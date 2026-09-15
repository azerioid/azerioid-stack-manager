<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Mail\MailMaps;
use PHPUnit\Framework\TestCase;

final class MailMapsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function state(): array
    {
        return [
            'domains' => [
                'raww.az' => ['enabled' => true],
                'paused.az' => ['enabled' => false],
            ],
            'mailboxes' => [
                'zaur@raww.az' => ['domain' => 'raww.az', 'local_part' => 'zaur'],
                'abuse@raww.az' => ['domain' => 'raww.az', 'local_part' => 'abuse'],
                'old@raww.az' => ['domain' => 'raww.az', 'local_part' => 'old', 'disabled' => true],
            ],
            'aliases' => [
                'postmaster@raww.az' => ['domain' => 'raww.az', 'destination' => 'zaur@raww.az'],
                'blank@raww.az' => ['domain' => 'raww.az', 'destination' => '   '],
            ],
        ];
    }

    public function test_only_enabled_domains_are_accepted(): void
    {
        $map = MailMaps::domainsMap($this->state());
        $this->assertStringContainsString("raww.az OK\n", $map);
        $this->assertStringNotContainsString('paused.az', $map);
    }

    public function test_mailbox_map_uses_trailing_slash_for_maildir(): void
    {
        $map = MailMaps::mailboxMap($this->state());
        $this->assertStringContainsString("zaur@raww.az raww.az/zaur/\n", $map);
        foreach (explode("\n", trim($map)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $this->assertStringEndsWith('/', $line, 'Postfix writes an mbox file when the path has no trailing slash.');
        }
    }

    public function test_disabled_mailbox_stops_delivery_but_keeps_its_maildir(): void
    {
        $state = $this->state();
        $this->assertStringNotContainsString('old@raww.az', MailMaps::mailboxMap($state));

        $users = MailMaps::dovecotUsers($state, ['old@raww.az' => '{SHA512-CRYPT}$6$abc'], '/var/vmail');
        $this->assertStringContainsString('old@raww.az::5000:5000::/var/vmail/raww.az/old::', $users);
    }

    public function test_alias_map_skips_empty_destinations(): void
    {
        $map = MailMaps::aliasMap($this->state());
        $this->assertStringContainsString('postmaster@raww.az zaur@raww.az', $map);
        $this->assertStringNotContainsString('blank@raww.az', $map);
    }

    public function test_dovecot_users_carry_hash_uid_gid_and_home(): void
    {
        $users = MailMaps::dovecotUsers(
            $this->state(),
            ['zaur@raww.az' => '{SHA512-CRYPT}$6$salt$hash'],
            '/var/vmail/'
        );
        $this->assertStringContainsString(
            'zaur@raww.az:{SHA512-CRYPT}$6$salt$hash:5000:5000::/var/vmail/raww.az/zaur::',
            $users
        );
    }

    public function test_maps_are_deterministic_and_carry_the_generated_header(): void
    {
        $first = MailMaps::mailboxMap($this->state());
        $shuffled = $this->state();
        $shuffled['mailboxes'] = array_reverse($shuffled['mailboxes'], true);

        $this->assertSame($first, MailMaps::mailboxMap($shuffled));
        $this->assertStringStartsWith('# AZERIOID Stack Manager', $first);
    }

    public function test_empty_state_renders_header_only(): void
    {
        $this->assertSame("# AZERIOID Stack Manager — broker-generated; edits are overwritten\n", MailMaps::domainsMap([]));
    }
}
