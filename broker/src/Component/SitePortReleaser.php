<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\CaddyCli;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Database\BrokerConfigWriter;
use AzerioidPanel\Broker\Runtime;

/**
 * Release :80/:443 from the panel Caddy instance so Nginx/Apache can own site ports.
 * Panel vhosts in conf.d (e.g. azerioid-panel on 3169) are preserved.
 */
final class SitePortReleaser
{
    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    /** @return array<string, mixed> */
    public function release(): array
    {
        $caddyfile = $this->config->caddyfile;
        if (!$this->runtime->fileExists($caddyfile)) {
            throw new BrokerException('Caddyfile not found.', 1);
        }

        $panelPort = $this->panelPort();
        $before80 = $this->caddyListensOn(80);
        $before443 = $this->caddyListensOn(443);

        $backup = rtrim($this->config->stagingDir, '/') . '/caddyfile.pre-release-' . gmdate('Ymd\THis\Z') . '.bak';
        $this->runtime->mkdir(dirname($backup), 0750);
        $original = $this->runtime->readFile($caddyfile);
        $this->runtime->writeFile($backup, $original, 0640);

        $parked = $this->parkSiteVhosts();

        $released = $this->renderPanelOnlyCaddyfile($this->config->caddyConfD);
        $this->runtime->writeFile($caddyfile, $released, 0644);

        $validate = CaddyCli::validate($this->runtime, $this->config, $caddyfile);
        if (!$validate->ok()) {
            $this->restoreParkedVhosts($parked);
            $this->runtime->writeFile($caddyfile, $original, 0644);
            $detail = trim($validate->stderr . "\n" . $validate->stdout);
            throw new BrokerException(
                'Caddy rejected the updated config; restored previous Caddyfile. '
                . ($detail !== '' ? $detail : 'validation failed'),
                1
            );
        }

        $configPath = getenv('AZERIOID_PANEL_CONFIG') ?: getenv('LACMP_PANEL_CONFIG') ?: '/etc/azerioid-panel/broker.json';
        BrokerConfigWriter::merge($this->runtime, $configPath, [
            'site_web_server' => 'panel-only',
        ]);

        try {
            CaddyApply::run($this->runtime, $this->config, 'auto');
        } catch (BrokerException $e) {
            $this->restoreParkedVhosts($parked);
            $this->runtime->writeFile($caddyfile, $original, 0644);
            BrokerConfigWriter::merge($this->runtime, $configPath, [
                'site_web_server' => $this->config->siteWebServer,
            ]);
            throw new BrokerException(
                'Caddy could not reload after releasing site ports; restored previous Caddyfile. ' . $e->getMessage(),
                1
            );
        }

        if (!$this->panelReachable($panelPort)) {
            $this->restoreParkedVhosts($parked);
            $this->runtime->writeFile($caddyfile, $original, 0644);
            BrokerConfigWriter::merge($this->runtime, $configPath, [
                'site_web_server' => $this->config->siteWebServer,
            ]);
            try {
                CaddyApply::run($this->runtime, $this->config, 'auto');
            } catch (BrokerException) {
            }
            throw new BrokerException("Panel is not reachable on 127.0.0.1:{$panelPort} after releasing site ports.", 1);
        }

        return [
            'released' => true,
            'panel_port' => $panelPort,
            'backup' => $backup,
            'caddy_listened_on_80_before' => $before80,
            'caddy_listened_on_443_before' => $before443,
            'caddy_listens_on_80_after' => $this->caddyListensOn(80),
            'caddy_listens_on_443_after' => $this->caddyListensOn(443),
            'site_web_server' => 'panel-only',
            'parked_site_vhosts' => $parked,
            'note' => 'Panel Caddy serves only azerioid-panel.conf (panel port). Site snippets were parked under staging. Re-install Caddy for sites from Components to reclaim :80/:443.',
        ];
    }

    /** @return list<string> */
    private function parkSiteVhosts(): array
    {
        $confD = rtrim($this->config->caddyConfD, '/');
        $parkDir = rtrim($this->config->stagingDir, '/')
            . '/released-caddy-vhosts-' . gmdate('Ymd\THis\Z');
        $this->runtime->mkdir($parkDir, 0750);
        $parked = [];
        foreach ($this->runtime->glob($confD . '/*.conf') as $path) {
            if (str_ends_with($path, '/azerioid-panel.conf')) {
                continue;
            }
            $dest = $parkDir . '/' . basename($path);
            $this->runtime->rename($path, $dest);
            $parked[] = $dest;
        }

        return $parked;
    }

    /** @param list<string> $parked */
    private function restoreParkedVhosts(array $parked): void
    {
        $confD = rtrim($this->config->caddyConfD, '/');
        foreach ($parked as $path) {
            if (!$this->runtime->fileExists($path)) {
                continue;
            }
            $this->runtime->rename($path, $confD . '/' . basename($path));
        }
    }

    private function renderPanelOnlyCaddyfile(string $confD): string
    {
        $import = rtrim($confD, '/') . '/azerioid-panel.conf';

        // auto_https disable_redirects MUST stay in this panel-only Caddyfile only.
        // Never put it in the global multi-vhost Caddyfile — that would suppress HTTP→HTTPS
        // redirects (and confuse operators) for user sites that still need ACME HTTP-01 on :80.
        return <<<EOF
# AZERIOID Stack Manager — panel Caddy only (site ports released for Nginx/Apache)
{
    admin off
    auto_https disable_redirects
}
import {$import}

EOF;
    }

    private function panelPort(): int
    {
        $access = '/etc/azerioid-panel/access.env';
        if ($this->runtime->fileExists($access)) {
            foreach (explode("\n", $this->runtime->readFile($access)) as $line) {
                if (str_starts_with($line, 'PANEL_PORT=')) {
                    $port = (int) trim(substr($line, strlen('PANEL_PORT=')));
                    if ($port > 0) {
                        return $port;
                    }
                }
            }
        }

        return 3169;
    }

    private function panelReachable(int $port): bool
    {
        $result = $this->runtime->exec(
            ['/usr/bin/curl', '-fsSI', "http://127.0.0.1:{$port}"],
            null,
            15
        );

        return $result->ok();
    }

    private function caddyListensOn(int $port): bool
    {
        $result = $this->runtime->exec(['/usr/bin/ss', '-ltnp', 'sport', '=', (string) $port]);
        if (!$result->ok()) {
            return false;
        }

        return str_contains(strtolower($result->stdout), 'caddy');
    }
}
