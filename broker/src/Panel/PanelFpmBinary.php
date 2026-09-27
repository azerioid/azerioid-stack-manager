<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Systemd;

/**
 * Keeps the EL panel master's private php-fpm copy in step with the distro binary (KI-3).
 *
 * On EL the panel master cannot run the distro php-fpm (httpd_exec_t → httpd_t, A3), so
 * deploy/lib/fpm.sh installs a bin_t copy under PREFIX/sbin. That copy was made once and
 * never again, so a distro PHP security update reached the site pools but not the panel.
 * Self-update now calls refresh(): when the copy differs from the distro binary it is
 * replaced, relabelled, config-tested and the panel master restarted. apt hosts run the
 * distro binary directly (A39-A1) and are left alone.
 */
final class PanelFpmBinary
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * @return list<string> what happened, for the caller's log
     */
    public function refresh(): array
    {
        $exec = $this->unitExecStart();
        $copy = $exec[0] ?? null;
        if ($copy === null) {
            return [];
        }
        $source = $this->distroBinary();
        if ($source === null) {
            return ['panel php-fpm copy ' . $copy . ' kept: no distro php-fpm found to compare it with'];
        }
        if ($this->runtime->exec(['/usr/bin/cmp', '-s', $source, $copy], null, 30)->ok()) {
            return ['panel php-fpm copy is current with ' . $source];
        }

        $staged = $copy . '.new';
        $previous = $copy . '.previous';
        $this->must(['/bin/cp', '-f', $source, $staged], 'copy ' . $source);
        $this->runtime->chmod($staged, 0755);
        // The fcontext rule covers PREFIX/sbin(/.*)?, so the staged file gets bin_t too.
        $this->runtime->exec(['/sbin/restorecon', $staged], null, 30);
        $this->must(['/bin/cp', '-f', '-p', $copy, $previous], 'keep the previous copy');
        $this->must(['/bin/mv', '-f', $staged, $copy], 'install the new copy');

        // Test with the unit's own arguments: a host that has not run the A39
        // migration yet has no /etc/azerioid-panel/php.ini to pass.
        $args = array_values(array_filter(array_slice($exec, 1), static fn (string $a): bool => $a !== '--nodaemonize'));
        $test = $this->runtime->exec(array_merge([$copy, '-t'], $args), null, 30);
        if (!$test->ok()) {
            $this->runtime->exec(['/bin/mv', '-f', $previous, $copy], null, 30);
            throw new BrokerException(
                'Refreshed panel php-fpm failed its config test and was put back: '
                . trim($test->stderr . ' ' . $test->stdout),
                1
            );
        }

        try {
            Systemd::control($this->runtime, 'restart', PanelIdentityMigrator::UNIT);
        } catch (\Throwable $e) {
            $this->runtime->exec(['/bin/mv', '-f', $previous, $copy], null, 30);
            Systemd::control($this->runtime, 'restart', PanelIdentityMigrator::UNIT);
            throw new BrokerException('Panel php-fpm did not restart on the refreshed binary; the previous one is back: ' . $e->getMessage(), 1);
        }
        $this->runtime->deleteFile($previous);

        return ['panel php-fpm copy refreshed from ' . $source . ' and ' . PanelIdentityMigrator::UNIT . ' restarted'];
    }

    /**
     * The panel unit's ExecStart split into words, when its binary is the PREFIX/sbin
     * copy; empty when it runs anything else (apt runs the distro binary directly).
     *
     * @return list<string>
     */
    private function unitExecStart(): array
    {
        if (!$this->runtime->fileExists(PanelIdentityMigrator::UNIT_FILE)) {
            return [];
        }
        if (preg_match('/^ExecStart=(.+)$/m', $this->runtime->readFile(PanelIdentityMigrator::UNIT_FILE), $m) !== 1) {
            return [];
        }
        $words = preg_split('/\s+/', trim($m[1])) ?: [];
        $sbin = rtrim($this->config->panelRoot, '/') . '/sbin/';
        if (($words[0] ?? '') === '' || !str_starts_with($words[0], $sbin) || !$this->runtime->fileExists($words[0])) {
            return [];
        }

        return $words;
    }

    /** The distro php-fpm the installer copied from (fpm_bin() in deploy/lib/common.sh), plus Remi's SCL path. */
    private function distroBinary(): ?string
    {
        $ver = $this->config->panelPhpVersion;
        foreach ([
            '/usr/sbin/php-fpm' . $ver,
            '/usr/sbin/php-fpm',
            '/opt/remi/php' . str_replace('.', '', $ver) . '/root/usr/sbin/php-fpm',
        ] as $candidate) {
            if ($this->runtime->fileExists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function must(array $command, string $what): void
    {
        $r = $this->runtime->exec($command, null, 60);
        if (!$r->ok()) {
            throw new BrokerException('Panel php-fpm refresh could not ' . $what . ': ' . trim($r->stderr), 1);
        }
    }
}
