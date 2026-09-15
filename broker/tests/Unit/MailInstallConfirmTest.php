<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Kernel;
use AzerioidPanel\Broker\Mail\ForeignMta;
use AzerioidPanel\Broker\Mail\MailProvisioner;
use PHPUnit\Framework\TestCase;

/**
 * A36 §9.8: a foreign MTA is only ever replaced after a typed REPLACE-MTA, and a
 * host with no MTA at all must never be told it has one.
 */
final class MailInstallConfirmTest extends TestCase
{
    private string $registryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryPath = dirname(__DIR__, 3) . '/registry/components';
    }

    private function ubuntuRuntime(): FakeRuntime
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=ubuntu\nVERSION_ID=\"24.04\"\n";
        $rt->files['/proc/meminfo'] = "MemAvailable: 2097152 kB\n";
        $rt->dirs[rtrim($this->registryPath, '/')] = true;
        foreach (glob($this->registryPath . '/*.json') ?: [] as $path) {
            $rt->files[$path] = (string) file_get_contents($path);
        }
        $rt->script(['/bin/df', '-B1', '-P', '/var'], 0, "Filesystem 1B-blocks Used Available Capacity Mounted on\n/dev/sda1 10000000000 1000000000 9000000000 10% /var\n");
        $rt->files['/etc/postfix/master.cf'] = "smtp      inet  n       -       y       -       -       smtpd\n";
        $rt->dirs['/etc/postfix'] = true;
        $rt->dirs['/etc/dovecot'] = true;
        $rt->dirs['/etc/dovecot/conf.d'] = true;
        $rt->script(['/bin/hostname', '-f'], 0, "mail.raww.az\n");

        return $rt;
    }

    private function installedPackage(FakeRuntime $rt, string $package): void
    {
        $rt->script(['/usr/bin/dpkg-query', '-W', '-f=${Status}', $package], 0, 'install ok installed');
    }

    private function kernel(FakeRuntime $rt): Kernel
    {
        $cfg = new Config();
        $cfg->registryComponentsPath = $this->registryPath;
        $cfg->stagingDir = sys_get_temp_dir() . '/azerioid-mail-install-' . getmypid();
        $cfg->managedComponentsPath = $cfg->stagingDir . '/managed-components.json';
        @mkdir($cfg->stagingDir . '/operations', 0750, true);
        $rt->dirs[$cfg->stagingDir] = true;
        $rt->dirs[$cfg->stagingDir . '/operations'] = true;

        return new Kernel($cfg, $rt);
    }

    /** @return array{0:int,1:array} */
    private function capture(Kernel $kernel, array $argv, array $stdin = []): array
    {
        ob_start();
        $code = $kernel->run($argv, $stdin);
        $out = ob_get_clean();

        return [$code, json_decode(trim((string) $out), true)];
    }

    public function test_clean_host_is_not_reported_as_having_a_foreign_mta(): void
    {
        $rt = $this->ubuntuRuntime();
        $detected = (new ForeignMta(new Config(), $rt, OsRelease::detect($rt)))->detect();

        $this->assertFalse($detected['present'], 'A host with no MTA must not trigger the REPLACE-MTA gate.');
        $this->assertSame([], $detected['packages']);
        $this->assertSame([], $detected['reasons']);
    }

    public function test_exim_is_detected_and_listed_for_removal(): void
    {
        $rt = $this->ubuntuRuntime();
        $this->installedPackage($rt, 'exim4');
        $this->installedPackage($rt, 'exim4-base');

        $detector = new ForeignMta(new Config(), $rt, OsRelease::detect($rt));
        $detected = $detector->detect();

        $this->assertTrue($detected['present']);
        $this->assertContains('exim4', $detected['packages']);
        $this->assertContains('exim4', $detector->packagesToRemove());
        $this->assertNotContains('postfix', $detector->packagesToRemove(), 'Postfix is upgraded in place, never removed.');
    }

    public function test_a_running_foreign_mail_unit_alone_is_enough_to_gate_install(): void
    {
        $rt = $this->ubuntuRuntime();
        $rt->script(['/usr/bin/systemctl', 'is-active', 'sendmail'], 0, "active\n");

        $detected = (new ForeignMta(new Config(), $rt, OsRelease::detect($rt)))->detect();

        $this->assertTrue($detected['present']);
        $this->assertSame(['sendmail'], $detected['units']);
    }

    public function test_install_refuses_without_the_typed_confirmation(): void
    {
        $rt = $this->ubuntuRuntime();
        $this->installedPackage($rt, 'exim4');

        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'component.install', 'mail'],
            ['operation_id' => 'op-mail-1']
        );

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('REPLACE-MTA', (string) $json['error']);
        $cmds = array_map(static fn (array $row): string => implode(' ', $row['command']), $rt->execLog);
        foreach ($cmds as $cmd) {
            $this->assertStringNotContainsString('remove', $cmd, 'Nothing may be uninstalled before the operator confirms.');
        }
    }

    public function test_wrong_confirmation_text_is_rejected(): void
    {
        $rt = $this->ubuntuRuntime();
        $this->installedPackage($rt, 'exim4');

        [$code] = $this->capture(
            $this->kernel($rt),
            ['broker', 'component.install', 'mail'],
            ['operation_id' => 'op-mail-2', 'options' => ['confirm' => 'replace-mta']]
        );

        $this->assertNotSame(0, $code);
    }

    public function test_confirmed_install_removes_the_foreign_mta_before_installing_postfix(): void
    {
        $rt = $this->ubuntuRuntime();
        $this->installedPackage($rt, 'exim4');
        $this->installedPackage($rt, 'exim4-base');

        [$code, $json] = $this->capture(
            $this->kernel($rt),
            ['broker', 'component.install', 'mail'],
            ['operation_id' => 'op-mail-3', 'options' => ['confirm' => 'REPLACE-MTA']]
        );
        $this->assertSame(0, $code, (string) json_encode($json));

        $cmds = array_map(static fn (array $row): string => implode(' ', $row['command']), $rt->execLog);
        $removeAt = null;
        $installAt = null;
        foreach ($cmds as $i => $cmd) {
            if ($removeAt === null && str_contains($cmd, 'apt-get') && str_contains($cmd, 'remove') && str_contains($cmd, 'exim4')) {
                $removeAt = $i;
            }
            if ($installAt === null && str_contains($cmd, 'apt-get') && str_contains($cmd, 'install') && str_contains($cmd, 'postfix')) {
                $installAt = $i;
            }
        }
        $this->assertNotNull($removeAt, 'Confirmed install must remove the foreign MTA.');
        $this->assertNotNull($installAt);
        $this->assertLessThan($installAt, $removeAt, 'Exim must go before Postfix arrives, or the package manager fights itself.');
    }

    public function test_confirmation_is_not_echoed_back_into_the_managed_manifest(): void
    {
        $rt = $this->ubuntuRuntime();
        $this->installedPackage($rt, 'exim4');
        $cfgPath = sys_get_temp_dir() . '/azerioid-mail-install-' . getmypid() . '/managed-components.json';

        [$code] = $this->capture(
            $this->kernel($rt),
            ['broker', 'component.install', 'mail'],
            ['operation_id' => 'op-mail-4', 'options' => ['confirm' => 'REPLACE-MTA']]
        );
        $this->assertSame(0, $code);

        $manifest = $rt->files[$cfgPath] ?? '';
        $this->assertStringNotContainsString('REPLACE-MTA', $manifest);
    }

    public function test_port_25_never_advertises_auth_after_hardening(): void
    {
        $body = MailProvisioner::masterCfWithSubmission(
            "smtp      inet  n       -       y       -       -       smtpd\n"
            . "submission inet n     -       y       -       -       smtpd\n"
            . "  -o smtpd_sasl_auth_enable=yes\n"
            . "pickup    unix  n     -       y       60      1       pickup\n"
        );

        $lines = explode("\n", $body);
        $inSmtp = false;
        foreach ($lines as $line) {
            if (preg_match('/^smtp\s+inet\s/', $line) === 1) {
                $inSmtp = true;
                continue;
            }
            if ($inSmtp) {
                if ($line !== '' && preg_match('/^\s/', $line) !== 1) {
                    break;
                }
                $this->assertStringNotContainsString('smtpd_sasl_auth_enable=yes', $line);
            }
        }
        $this->assertStringContainsString('smtpd_client_restrictions=permit_sasl_authenticated,reject', $body);
        $this->assertSame(1, substr_count($body, "\nsubmission inet"), 'The stock submission block must be replaced, not duplicated.');
        $this->assertStringContainsString('pickup    unix', $body, 'Unrelated services must survive rewriting.');
    }
}
