<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tool;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Web\PanelCaddy;

/**
 * Panel-gated Adminer install: pinned artifact, isolated FPM pool, Caddy forward_auth route.
 */
final class AdminerTool
{
    public const TOOL_DIR = '/var/lib/azerioid-panel/tools/adminer';
    public const ARTIFACT_PATH = self::TOOL_DIR . '/adminer.php';
    public const FPM_POOL = 'azerioid-adminer-tool';
    public const FPM_SOCKET = '/run/php/azerioid-adminer-tool.sock';
    public const CADDY_ROUTE_PATH = '/var/lib/azerioid-panel/caddy-adminer-routes.conf';
    public const CADDY_PATH_PREFIX = '/tools/adminer';

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public function install(array $definition, OperationLogger $log): void
    {
        $artifact = $this->artifact($definition);
        $this->assertPanelPhpReady($log);

        $snap = $this->snapshot();
        try {
            AdminerUser::ensure($this->runtime, $this->config->phpGroup);
            $this->ensureToolPathAccess();
            $this->downloadArtifact($artifact, $log);
            $this->writeFpmPool($log);
            $this->reloadPanelPhpFpm($log);
            // Stub must exist before the panel snippet imports it (Caddy fails closed on missing imports).
            $this->writeCaddyRoutes(false);
            $this->ensurePanelSnippetImports();
            $this->writeCaddyRoutes(true);
            CaddyApply::run($this->runtime, $this->config, 'auto', [$this->config->panelPort]);
            $log->info('Adminer tool installed (panel session gate + isolated FPM).');
        } catch (\Throwable $e) {
            $this->restore($snap);
            throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
        }
    }

    public function uninstall(OperationLogger $log): void
    {
        $snap = $this->snapshot();
        try {
            $this->writeCaddyRoutes(false);
            CaddyApply::run($this->runtime, $this->config, 'auto', [$this->config->panelPort]);
            $poolFile = $this->fpmPoolFile();
            if ($this->runtime->fileExists($poolFile)) {
                $this->runtime->deleteFile($poolFile);
            }
            $this->reloadPanelPhpFpm($log);
            if ($this->runtime->fileExists(self::ARTIFACT_PATH)) {
                $this->runtime->deleteFile(self::ARTIFACT_PATH);
            }
            $log->info('Adminer tool removed.');
        } catch (\Throwable $e) {
            $this->restore($snap);
            throw $e instanceof BrokerException ? $e : new BrokerException($e->getMessage(), 1);
        }
    }

    /** @param array<string, mixed> $definition */
    private function artifact(array $definition): array
    {
        $artifact = $definition['artifact'] ?? null;
        if (!is_array($artifact)) {
            throw new BrokerException('Adminer registry entry is missing artifact metadata.', 2);
        }
        foreach (['version', 'filename', 'source_url', 'sha256'] as $key) {
            if (trim((string) ($artifact[$key] ?? '')) === '') {
                throw new BrokerException('Adminer artifact metadata is incomplete.', 2);
            }
        }

        return $artifact;
    }

    private function assertPanelPhpReady(OperationLogger $log): void
    {
        $sock = $this->config->panelFpmSocket;
        if (!$this->runtime->fileExists($sock)) {
            throw new BrokerException('Panel PHP-FPM is not available; install the panel before Adminer.', 2);
        }
        $curl = $this->runtime->fileExists('/usr/bin/curl') ? '/usr/bin/curl' : null;
        if ($curl === null) {
            throw new BrokerException('curl is required to download the pinned Adminer release.', 2);
        }
        $log->info('Panel PHP runtime present; will use PHP ' . $this->config->panelPhpVersion . ' FPM master for Adminer pool.');
    }

    private function ensureToolPathAccess(): void
    {
        $toolsDir = dirname(self::TOOL_DIR);
        $group = $this->config->phpGroup;
        $this->runtime->mkdir($toolsDir, 0750);
        // mkdir() is create-only on some runtimes — force mode/group so the FPM user can traverse.
        $this->runtime->exec(['/usr/bin/chmod', '0750', $toolsDir], null, 15);
        if ($group !== '') {
            $this->runtime->exec(['/usr/bin/chgrp', $group, $toolsDir], null, 15);
        }
        $this->runtime->mkdir(self::TOOL_DIR, 0750);
        $this->runtime->exec(['/usr/bin/chmod', '0750', self::TOOL_DIR], null, 15);
        $this->runtime->exec(['/usr/bin/chown', AdminerUser::USERNAME . ':' . AdminerUser::USERNAME, self::TOOL_DIR], null, 15);
    }

    /** @param array<string, mixed> $artifact */
    private function downloadArtifact(array $artifact, OperationLogger $log): void
    {
        $url = (string) $artifact['source_url'];
        $expected = strtolower(trim((string) $artifact['sha256']));
        $filename = (string) $artifact['filename'];
        $version = (string) $artifact['version'];
        $staging = rtrim($this->config->stagingDir, '/') . '/adminer-' . $version . '.download';
        $curl = '/usr/bin/curl';

        $this->runtime->mkdir(self::TOOL_DIR, 0750);
        $log->info("Downloading Adminer {$version} from official release (checksum verified).");
        $fetch = $this->runtime->exec([$curl, '-fsSL', '--max-time', '120', '-o', $staging, $url], null, 130);
        if (!$fetch->ok()) {
            throw new BrokerException('Adminer download failed.', 1);
        }

        $hashCmd = $this->runtime->fileExists('/usr/bin/sha256sum')
            ? ['/usr/bin/sha256sum', $staging]
            : ['/usr/bin/shasum', '-a', '256', $staging];
        $hashRes = $this->runtime->exec($hashCmd, null, 30);
        if (!$hashRes->ok()) {
            throw new BrokerException('Could not verify Adminer checksum.', 1);
        }
        $got = strtolower(trim(explode(' ', trim($hashRes->stdout))[0] ?? ''));
        if ($got !== $expected) {
            $this->runtime->deleteFile($staging);
            throw new BrokerException('Adminer checksum mismatch; refusing to install.', 1);
        }

        $dest = rtrim(self::TOOL_DIR, '/') . '/' . $filename;
        if ($this->runtime->fileExists($dest)) {
            $this->runtime->deleteFile($dest);
        }
        $this->runtime->rename($staging, $dest);
        $this->runtime->exec(['/usr/bin/chown', AdminerUser::USERNAME . ':' . AdminerUser::USERNAME, $dest], null, 15);
        $this->runtime->exec(['/usr/bin/chmod', '0640', $dest], null, 15);
        $log->info('Installed ' . $dest);
    }

    private function writeFpmPool(OperationLogger $log): void
    {
        $openBase = self::TOOL_DIR . ':/tmp:/dev/urandom';
        $user = AdminerUser::USERNAME;
        $pool = $this->poolName();
        $sock = self::FPM_SOCKET;
        $toolDir = self::TOOL_DIR;
        $phpUser = $this->config->phpUser;
        $phpGroup = $this->config->phpGroup;
        $body = <<<POOL
; AZERIOID Stack Manager — Adminer tool pool (broker-managed; do not edit)
[{$pool}]
user = {$user}
group = {$phpGroup}
listen = {$sock}
listen.owner = {$phpUser}
listen.group = {$phpGroup}
listen.mode = 0660
pm = ondemand
pm.max_children = 4
pm.process_idle_timeout = 30s
chdir = {$toolDir}
php_admin_value[open_basedir] = {$openBase}
php_admin_value[disable_functions] = passthru,exec,shell_exec,system,chroot,chgrp,chown,ini_alter,ini_restore,proc_open,popen,pcntl_exec,pcntl_fork,dl,show_source
php_admin_flag[allow_url_fopen] = off
php_admin_flag[allow_url_include] = off

POOL;
        $poolFile = $this->fpmPoolFile();
        $this->runtime->mkdir(dirname($poolFile), 0750);
        $this->runtime->writeFile($poolFile, $body, 0644);
        $log->info('Wrote FPM pool ' . $poolFile);
    }

    private function poolName(): string
    {
        return self::FPM_POOL;
    }

    private function fpmPoolFile(): string
    {
        $os = OsRelease::detect($this->runtime);
        if ($os->distroKey === 'el') {
            return '/etc/azerioid-panel/php-fpm.d/' . self::FPM_POOL . '.conf';
        }
        $ver = $this->config->panelPhpVersion;

        return '/etc/php/' . $ver . '/fpm/pool.d/' . self::FPM_POOL . '.conf';
    }

    private function reloadPanelPhpFpm(OperationLogger $log): void
    {
        $unit = $this->config->panelFpmUnit !== ''
            ? $this->config->panelFpmUnit
            : $this->config->phpFpmService($this->config->panelPhpVersion, $this->runtime);
        $res = $this->runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 30);
        if (!$res->ok()) {
            $log->warn('systemctl reload ' . $unit . ' failed; trying restart.');
            $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
        }
    }

    private function writeCaddyRoutes(bool $enabled): void
    {
        $path = $this->config->adminerCaddyRoutesPath;
        $this->runtime->mkdir(dirname($path), 0750);
        if (!$enabled) {
            $this->runtime->writeFile($path, "# AZERIOID Stack Manager — Adminer not installed\n", 0644);

            return;
        }

        $port = $this->config->panelPort > 0 ? $this->config->panelPort : 3169;
        $auth = '127.0.0.1:' . $port;
        $sock = self::FPM_SOCKET;
        if (!str_starts_with($sock, 'unix')) {
            $sock = 'unix/' . $sock;
        }
        $root = self::TOOL_DIR;
        $prefix = self::CADDY_PATH_PREFIX;
        // handle_path .../* does not match the bare prefix (no trailing slash).
        // Browsers and the sidebar often hit /tools/adminer — redirect into the gated path.
        $body = <<<CADDY
# AZERIOID Stack Manager — Adminer route (broker-managed; do not edit)
redir {$prefix} {$prefix}/ 308
handle_path {$prefix}/* {
    forward_auth {$auth} {
        uri /internal/auth-check
        header_up Host 127.0.0.1
    }
    root * {$root}
    rewrite * /adminer.php?{query}
    php_fastcgi {$sock} {
        dial_timeout 10s
        read_timeout 120s
    }
}

CADDY;
        $this->runtime->writeFile($path, $body, 0644);
    }

    private function ensurePanelSnippetImports(): void
    {
        $snippet = rtrim($this->config->vhostDir, '/') . '/' . PanelCaddy::SNIPPET;
        if (!$this->runtime->fileExists($snippet)) {
            return;
        }
        $import = 'import /var/lib/azerioid-panel/caddy-adminer-routes.conf';
        $body = $this->runtime->readFile($snippet);
        if (str_contains($body, $import)) {
            return;
        }
        $anchor = 'import /var/lib/azerioid-panel/caddy-terminal-routes.conf';
        if (!str_contains($body, $anchor)) {
            return;
        }
        $body = str_replace($anchor, $anchor . "\n    " . $import, $body);
        $this->runtime->writeFile($snippet, $body, 0644);
    }

    /** @return array{caddy:?string,fpm:?string,artifact:?string} */
    private function snapshot(): array
    {
        $path = $this->config->adminerCaddyRoutesPath;
        $caddy = $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : null;
        $poolFile = $this->fpmPoolFile();
        $fpm = $this->runtime->fileExists($poolFile) ? $this->runtime->readFile($poolFile) : null;
        $artifact = $this->runtime->fileExists(self::ARTIFACT_PATH) ? $this->runtime->readFile(self::ARTIFACT_PATH) : null;

        return ['caddy' => $caddy, 'fpm' => $fpm, 'artifact' => $artifact];
    }

    /** @param array{caddy:?string,fpm:?string,artifact:?string} $snap */
    private function restore(array $snap): void
    {
        $path = $this->config->adminerCaddyRoutesPath;
        if ($snap['caddy'] === null) {
            // Keep a stub if the panel snippet already imports this path — deleting the
            // file leaves Caddy unable to adapt the config (missing import).
            $this->writeCaddyRoutes(false);
        } else {
            $this->runtime->writeFile($path, $snap['caddy'], 0644);
        }
        $poolFile = $this->fpmPoolFile();
        if ($snap['fpm'] === null) {
            if ($this->runtime->fileExists($poolFile)) {
                $this->runtime->deleteFile($poolFile);
            }
        } else {
            $this->runtime->writeFile($poolFile, $snap['fpm'], 0644);
        }
        if ($snap['artifact'] === null) {
            if ($this->runtime->fileExists(self::ARTIFACT_PATH)) {
                $this->runtime->deleteFile(self::ARTIFACT_PATH);
            }
        } else {
            $this->runtime->writeFile(self::ARTIFACT_PATH, $snap['artifact'], 0640);
        }
    }
}
