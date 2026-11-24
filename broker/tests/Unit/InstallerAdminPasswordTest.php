<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A112: the installer hands the new admin password to artisan through the environment,
 * never as a command-line argument (argv is readable by every local account in /proc).
 * Runs the real `_invoke_create_panel_admin` under bash with stub `runuser`/`sudo`/`env`
 * that record their argv and the environment they received.
 */
final class InstallerAdminPasswordTest extends TestCase
{
    public function test_the_admin_password_is_in_no_argv_but_reaches_artisan(): void
    {
        $dir = sys_get_temp_dir() . '/az-a112-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $log = $dir . '/calls.log';
        $stub = "#!/bin/bash\nprintf 'ARGV %s:' \"\$(basename \"\$0\")\" >> '{$log}'; printf ' [%s]' \"\$@\" >> '{$log}'; printf '\\nENV %s\\n' \"\${PANEL_INSTALL_ADMIN_PASSWORD:-}\" >> '{$log}'\n";
        foreach (['runuser', 'sudo'] as $name) {
            file_put_contents("{$dir}/{$name}", $stub);
            chmod("{$dir}/{$name}", 0755);
        }
        $secret = 'Corr3ct-Horse-Battery!';
        $lib = dirname(__DIR__, 3) . '/deploy/lib/post-install.sh';
        $script = "source '{$lib}'; PANEL_USER=azerioid-panel PREFIX=/opt/p ADMIN_EMAIL=a@example.com ADMIN_NAME=Admin;"
            . " _invoke_create_panel_admin /usr/bin/php8.4 '' '{$secret}'";
        exec('PATH=' . escapeshellarg($dir) . ':/usr/bin:/bin /bin/bash -c ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $calls = (string) @file_get_contents($log);
        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('ARGV runuser:', $calls, 'the admin is created through runuser');
        $this->assertStringContainsString("ENV {$secret}", $calls, 'the password reaches the child through its environment');
        foreach (explode("\n", $calls) as $line) {
            if (str_starts_with($line, 'ARGV')) {
                $this->assertStringNotContainsString($secret, $line, 'no argv carries the password');
            }
        }
    }

    public function test_the_manual_hint_reads_the_password_from_a_prompt(): void
    {
        $lib = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/lib/post-install.sh');
        $this->assertStringContainsString('read -rs -p', $lib);
        $this->assertDoesNotMatchRegularExpression("/PANEL_INSTALL_ADMIN_PASSWORD='your-password'/", $lib);
    }
}
