<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Network\SiteHttpFirewall;
use AzerioidPanel\Broker\Runtime;

/**
 * Bind Apache/Nginx to a single loopback port and keep those ports off the WAN.
 * Caddy owns :80/:443; backends never listen there.
 */
final class BackendEngineBind
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(string $engine): array
    {
        $engine = VhostEngine::normalize($engine);
        if (!VhostEngine::isBackend($engine)) {
            throw new BrokerException('Only apache and nginx have internal backend binds.', 2);
        }
        if ($engine === VhostEngine::APACHE) {
            $this->labelHttpPort($this->config->apacheBackendPort);
            $detail = $this->ensureApache();
        } else {
            $this->labelHttpPort($this->config->nginxBackendPort);
            $detail = $this->ensureNginx();
        }
        $firewall = (new SiteHttpFirewall($this->runtime))->denyBackendPorts(
            $this->config->apacheBackendPort,
            $this->config->nginxBackendPort,
        );

        return [
            'engine' => $engine,
            'bind' => VhostEngine::backendAddress($this->config, $engine),
            'firewall' => $firewall,
        ] + $detail;
    }

    public function installed(string $engine): bool
    {
        $engine = VhostEngine::normalize($engine);
        if ($engine === VhostEngine::CADDY) {
            return true;
        }
        // Leftover /etc/apache2 or /etc/nginx after a package purge is not an
        // install. Match component.status: the engine binary must exist.
        if ($engine === VhostEngine::APACHE) {
            return $this->runtime->fileExists('/usr/sbin/apache2ctl')
                || $this->runtime->fileExists('/usr/sbin/apachectl')
                || $this->runtime->fileExists('/usr/sbin/httpd');
        }

        return $this->runtime->fileExists('/usr/sbin/nginx');
    }

    /** @return array<string,mixed> */
    private function ensureApache(): array
    {
        $addr = VhostEngine::backendAddress($this->config, VhostEngine::APACHE);
        $listenLine = 'Listen ' . $addr;
        $rewritten = [];
        foreach (['/etc/apache2/ports.conf', '/etc/httpd/conf/httpd.conf'] as $path) {
            if (!$this->runtime->fileExists($path)) {
                continue;
            }
            $this->runtime->writeFile($path, $this->rewriteApacheListen($this->runtime->readFile($path), $listenLine), 0644);
            $rewritten[] = $path;
        }

        // Ubuntu includes Listen from ports.conf; do not also Listen in conf-enabled
        // (AH00526: Cannot define multiple Listeners on the same IP:port).
        if ($this->runtime->isDir('/etc/httpd/conf.d')) {
            $this->runtime->writeFile(
                '/etc/httpd/conf.d/00-azerioid-remoteip.conf',
                $this->apacheForwardedClientConf($addr),
                0644
            );
        }
        if ($this->runtime->isDir('/etc/apache2/conf-available')) {
            $snippet = '/etc/apache2/conf-available/azerioid-backend.conf';
            $this->runtime->writeFile($snippet, $this->apacheForwardedClientConf($addr), 0644);
            if (!$this->runtime->isDir('/etc/apache2/conf-enabled')) {
                $this->runtime->mkdir('/etc/apache2/conf-enabled', 0755);
            }
            $enabled = '/etc/apache2/conf-enabled/azerioid-backend.conf';
            if (!$this->runtime->fileExists($enabled) && $this->runtime->fileExists('/usr/sbin/a2enconf')) {
                $this->runtime->exec(['/usr/sbin/a2enconf', 'azerioid-backend'], null, 15);
            } elseif (!$this->runtime->fileExists($enabled)) {
                $this->runtime->writeFile($enabled, $this->runtime->readFile($snippet), 0644);
            }
        }

        foreach (['proxy', 'proxy_fcgi', 'rewrite', 'headers', 'setenvif', 'remoteip'] as $mod) {
            if ($this->runtime->fileExists('/usr/sbin/a2enmod')) {
                $this->runtime->exec(['/usr/sbin/a2enmod', $mod], null, 15);
            }
        }
        foreach (['000-default', 'default-ssl'] as $site) {
            if ($this->runtime->fileExists('/usr/sbin/a2dissite')) {
                $this->runtime->exec(['/usr/sbin/a2dissite', $site], null, 15);
            }
        }

        $unit = $this->runtime->fileExists('/usr/sbin/httpd') && !$this->runtime->isDir('/etc/apache2')
            ? 'httpd'
            : 'apache2';
        $this->reloadUnit($unit);

        return ['listen' => $addr, 'rewritten' => $rewritten, 'unit' => $unit];
    }

    /** @return array<string,mixed> */
    private function ensureNginx(): array
    {
        $addr = VhostEngine::backendAddress($this->config, VhostEngine::NGINX);
        [$host, $port] = explode(':', $addr, 2);
        if ($this->runtime->isDir('/etc/nginx/conf.d') || $this->runtime->isDir('/etc/nginx')) {
            if (!$this->runtime->isDir('/etc/nginx/conf.d')) {
                $this->runtime->mkdir('/etc/nginx/conf.d', 0755);
            }
            $this->runtime->writeFile(
                '/etc/nginx/conf.d/00-azerioid-backend.conf',
                $this->nginxForwardedClientConf($host, $port),
                0644
            );
        }
        foreach ([
            '/etc/nginx/sites-enabled/default',
            '/etc/nginx/sites-enabled/default.conf',
        ] as $default) {
            if ($this->runtime->fileExists($default)) {
                $this->runtime->deleteFile($default);
            }
        }
        // EL ships a listen 80/443 server inside nginx.conf (not sites-enabled).
        if ($this->runtime->fileExists('/etc/nginx/nginx.conf')) {
            $this->runtime->writeFile(
                '/etc/nginx/nginx.conf',
                $this->rewriteNginxStockListen($this->runtime->readFile('/etc/nginx/nginx.conf'), $host, $port),
                0644
            );
        }
        $this->reloadUnit('nginx');

        return ['listen' => $addr, 'unit' => 'nginx'];
    }

    private function rewriteNginxStockListen(string $body, string $host, string $port): string
    {
        $loopback = "listen {$host}:{$port};";
        $body = preg_replace('/^(\s*)listen\s+\[::\]:80\s*;/m', '$1# AZERIOID-disabled listen [::]:80;', $body) ?? $body;
        $body = preg_replace('/^(\s*)listen\s+80(\s+default_server)?\s*;/m', '$1' . $loopback, $body) ?? $body;
        $body = preg_replace(
            '/^(\s*)listen\s+\[::\]:443[^\n]*;/m',
            '$1# AZERIOID-disabled listen [::]:443;',
            $body
        ) ?? $body;
        $body = preg_replace(
            '/^(\s*)listen\s+443[^\n]*;/m',
            '$1# AZERIOID-disabled listen 443;',
            $body
        ) ?? $body;

        return $body;
    }

    private function rewriteApacheListen(string $body, string $listenLine): string
    {
        $body = preg_replace('/^# AZERIOID backend bind.*\nListen[^\n]*\n/m', '', $body) ?? $body;
        $body = preg_replace('/^[ \t]*Listen\s+/m', '# AZERIOID-disabled Listen ', $body) ?? $body;
        if (!str_contains($body, $listenLine)) {
            $body = "# AZERIOID backend bind (Caddy owns :80/:443)\n{$listenLine}\n" . $body;
        }

        return $body;
    }

    private function apacheForwardedClientConf(string $listenAddr): string
    {
        return <<<APACHE
# AZERIOID — Apache is an internal backend only. Caddy owns :80/:443.
# Listen is set in ports.conf to {$listenAddr}; do not Listen here.
# Trust X-Forwarded-For / proto only from Caddy on loopback.
<IfModule remoteip_module>
    RemoteIPHeader X-Forwarded-For
    RemoteIPInternalProxy 127.0.0.1
    RemoteIPInternalProxy ::1
</IfModule>
<IfModule setenvif_module>
    SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
</IfModule>

APACHE;
    }

    private function nginxForwardedClientConf(string $host, string $port): string
    {
        return <<<NGINX
# AZERIOID — Nginx is an internal backend only. Caddy owns :80/:443.
# Trust client IP / scheme only from Caddy on loopback.
set_real_ip_from 127.0.0.1;
set_real_ip_from ::1;
real_ip_header X-Forwarded-For;
real_ip_recursive on;

map \$http_x_forwarded_proto \$azerioid_https {
    default off;
    https on;
}
map \$http_x_forwarded_proto \$azerioid_scheme {
    default http;
    https https;
}

server {
    listen {$host}:{$port} default_server;
    server_name _;
    return 444;
}

NGINX;
    }

    /**
     * EL policy assigns 8081 to transproxy_port_t and 8082 to us_cli_port_t.
     * httpd_t cannot bind those; http_port_t is the correct type for loopback backends.
     */
    private function labelHttpPort(int $port): void
    {
        $semanage = $this->selinuxBin('semanage');
        $getenforce = $this->selinuxBin('getenforce');
        if ($semanage === null || $getenforce === null) {
            return;
        }
        $mode = $this->runtime->exec([$getenforce], null, 5);
        if (!str_contains($mode->stdout, 'Enforcing')) {
            return;
        }
        $portStr = (string) $port;
        $add = $this->runtime->exec(
            [$semanage, 'port', '-a', '-t', 'http_port_t', '-p', 'tcp', $portStr],
            null,
            15
        );
        if ($add->ok()) {
            return;
        }
        $this->runtime->exec(
            [$semanage, 'port', '-m', '-t', 'http_port_t', '-p', 'tcp', $portStr],
            null,
            15
        );
    }

    private function selinuxBin(string $name): ?string
    {
        foreach (['/usr/sbin/' . $name, '/usr/bin/' . $name] as $path) {
            if ($this->runtime->fileExists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function reloadUnit(string $unit): void
    {
        $reload = $this->runtime->exec(['/usr/bin/systemctl', 'reload', $unit], null, 60);
        if ($reload->ok()) {
            return;
        }
        $restart = $this->runtime->exec(['/usr/bin/systemctl', 'restart', $unit], null, 60);
        if (!$restart->ok()) {
            $detail = trim($restart->stderr . "\n" . $restart->stdout);
            throw new BrokerException(
                "Could not reload {$unit} after binding the internal port"
                . ($detail !== '' ? ': ' . $detail : '.'),
                1
            );
        }
    }
}
