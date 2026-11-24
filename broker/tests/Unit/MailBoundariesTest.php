<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Mail\MailMaps;
use AzerioidPanel\Broker\Mail\MailProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * A123: a SASL login sends only as the addresses it owns. A124: the DKIM signer sees
 * authenticated submissions only. A125: the signer listens on a unix socket.
 */
final class MailBoundariesTest extends TestCase
{
    private const SOCKET = '/var/spool/postfix/opendkim/opendkim.sock';

    public function test_sender_logins_map_mailboxes_to_themselves_and_aliases_to_local_owners(): void
    {
        $map = MailMaps::senderLoginMap([
            'mailboxes' => [
                'a@shop.test' => ['domain' => 'shop.test', 'local_part' => 'a'],
                'b@shop.test' => ['domain' => 'shop.test', 'local_part' => 'b'],
                'gone@shop.test' => ['domain' => 'shop.test', 'local_part' => 'gone', 'disabled' => true],
            ],
            'aliases' => [
                'sales@shop.test' => ['destination' => 'a@shop.test, b@shop.test, x@elsewhere.test'],
                'old@shop.test' => ['destination' => 'gone@shop.test'],
                'out@shop.test' => ['destination' => 'x@elsewhere.test'],
            ],
        ]);
        $this->assertStringContainsString("a@shop.test a@shop.test\n", $map);
        $this->assertStringContainsString("sales@shop.test a@shop.test,b@shop.test\n", $map);
        $this->assertStringNotContainsString('gone@', $map, 'a disabled mailbox owns nothing');
        $this->assertStringNotContainsString('old@', $map);
        $this->assertStringNotContainsString('out@', $map, 'an alias with no local owner cannot be used to send');
    }

    public function test_submission_services_check_the_sender_and_carry_the_only_milter(): void
    {
        $body = MailProvisioner::masterCfWithSubmission(
            "smtp      inet  n       -       y       -       -       smtpd\n"
            . "pickup    unix  n     -       y       60      1       pickup\n"
        );
        $this->assertSame(2, substr_count($body, '-o smtpd_sender_restrictions=reject_authenticated_sender_login_mismatch'));
        $this->assertSame(2, substr_count($body, '-o smtpd_milters=unix:opendkim/opendkim.sock'));
        $smtp = explode("\n", explode('pickup', $body, 2)[0]);
        $this->assertSame([], array_filter($smtp, static fn (string $l): bool => str_contains($l, 'milter') || str_contains($l, 'sender_login')), 'port 25 is untouched');
        $this->assertStringContainsString("submission inet n       -       y", $body);

        $el = MailProvisioner::masterCfWithSubmission('', 'unix:/run/opendkim/opendkim.sock', false);
        $this->assertStringContainsString("submission inet n       -       n", $el, 'EL: unchrooted, so /run is reachable');
        $this->assertStringContainsString('-o smtpd_milters=unix:/run/opendkim/opendkim.sock', $el);
    }

    public function test_converge_moves_an_old_host_onto_the_socket_and_submission_only_signing(): void
    {
        $rt = $this->oldHost();
        [$code, $json] = $this->converge($rt);
        $this->assertSame(0, $code, (string) ($json['error'] ?? ''));
        $this->assertTrue($json['data']['applied']);

        $dkim = $rt->files['/etc/opendkim.conf'];
        $this->assertStringContainsString('Socket                  local:' . self::SOCKET, $dkim);
        $this->assertStringContainsString('UMask                   007', $dkim);
        $this->assertStringNotContainsString('inet:', $dkim);
        $this->assertStringContainsString('reject_authenticated_sender_login_mismatch', $rt->files['/etc/postfix/master.cf']);
        $set = $this->postconfSets($rt);
        $this->assertContains('smtpd_milters=', $set);
        $this->assertContains('non_smtpd_milters=', $set);
        $this->assertContains('smtpd_sender_login_maps=hash:/etc/postfix/' . MailMaps::SENDER_LOGINS, $set);
        $this->assertArrayHasKey('/etc/postfix/' . MailMaps::SENDER_LOGINS, $rt->files);
        $this->assertNotEmpty(array_filter($rt->execLog, static fn (array $e): bool => $e['command'] === ['/usr/sbin/usermod', '-a', '-G', 'opendkim', 'postfix']));
    }

    public function test_converge_rolls_back_when_postfix_cannot_reach_the_signer(): void
    {
        $rt = $this->oldHost();
        $before = [$rt->files['/etc/opendkim.conf'], $rt->files['/etc/postfix/master.cf'], $rt->files['/etc/postfix/main.cf']];
        $rt->script(['/usr/sbin/runuser', '-u', 'postfix', '--', PHP_BINARY, '-r',
            'exit(@stream_socket_client("unix://" . $argv[1], $e, $s, 5) ? 0 : 1);', self::SOCKET], 1);

        [$code, $json] = $this->converge($rt);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('rolled back', (string) $json['error']);
        $this->assertSame($before, [$rt->files['/etc/opendkim.conf'], $rt->files['/etc/postfix/master.cf'], $rt->files['/etc/postfix/main.cf']]);
    }

    public function test_converge_is_a_no_op_without_mail_or_once_converged(): void
    {
        $rt = new FakeRuntime();
        [$code, $json] = $this->converge($rt);
        $this->assertSame(0, $code);
        $this->assertSame('mail is not installed', $json['data']['reason']);

        $rt = $this->oldHost();
        $this->converge($rt);
        $rt->script(['/usr/sbin/postconf', '-h', 'smtpd_milters'], 0, "\n");
        $rt->script(['/usr/sbin/postconf', '-h', 'non_smtpd_milters'], 0, "\n");
        $rt->script(['/usr/sbin/postconf', '-h', 'smtpd_sender_login_maps'], 0, 'hash:/etc/postfix/' . MailMaps::SENDER_LOGINS . "\n");
        [, $json] = $this->converge($rt);
        $this->assertSame('already converged', $json['data']['reason']);
    }

    private function oldHost(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/var/lib/azerioid-panel/managed-components.json'] = json_encode(['components' => ['mail' => ['installed_at' => 'x']]]);
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/etc/postfix/main.cf'] = "smtpd_milters = inet:127.0.0.1:8891\n";
        $rt->files['/etc/postfix/master.cf'] = "smtp      inet  n       -       y       -       -       smtpd\n";
        $rt->files['/etc/opendkim.conf'] = "UMask                   002\nSocket                  inet:8891@localhost\n";
        $rt->dirs['/etc/opendkim'] = true;
        $rt->dirs['/etc/opendkim/keys'] = true;
        $rt->dirs['/etc/postfix'] = true;
        $rt->script(['/usr/sbin/postconf', '-h', 'smtpd_milters'], 0, "inet:127.0.0.1:8891\n");

        return $rt;
    }

    /** @return list<string> */
    private function postconfSets(FakeRuntime $rt): array
    {
        $out = [];
        foreach ($rt->execLog as $e) {
            if (($e['command'][0] ?? '') === '/usr/sbin/postconf' && ($e['command'][1] ?? '') === '-e') {
                $out[] = (string) $e['command'][2];
            }
        }

        return $out;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function converge(FakeRuntime $rt): array
    {
        ob_start();
        $code = (new Kernel(new Config(), $rt))->run(['broker', 'mail.boundaries.converge'], []);
        $json = json_decode((string) ob_get_clean(), true);
        $this->assertIsArray($json);

        return [$code, $json];
    }
}
