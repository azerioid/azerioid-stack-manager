<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\Runtime;

/**
 * Public sites listen on 80/443. Enabling ufw (default deny) for panel or
 * database access must never leave those ports closed — that is ERR_CONNECTION_TIMED_OUT
 * for every vhost, with PHP-FPM still healthy on-host.
 */
final class SiteHttpFirewall
{
    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * @return array{backend:string,applied:bool,detail:string}
     */
    public function ensure(): array
    {
        if ($this->ufwActive()) {
            $this->runtime->exec(['/usr/sbin/ufw', 'allow', '80/tcp', 'comment', 'azerioid-http'], null, 15);
            $this->runtime->exec(['/usr/sbin/ufw', 'allow', '443/tcp', 'comment', 'azerioid-https'], null, 15);

            return ['backend' => 'ufw', 'applied' => true, 'detail' => '80/tcp and 443/tcp allowed'];
        }
        if ($this->firewalldActive()) {
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--permanent', '--add-service=http'], null, 15);
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--permanent', '--add-service=https'], null, 15);
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);

            return ['backend' => 'firewalld', 'applied' => true, 'detail' => 'http and https services allowed'];
        }

        return ['backend' => 'none', 'applied' => false, 'detail' => 'No active ufw/firewalld'];
    }

    /**
     * Never open Apache/Nginx loopback backend ports on the WAN.
     * Loopback (lo) is accepted earlier in ufw/firewalld, so local reverse_proxy still works.
     *
     * @return array{backend:string,applied:bool,detail:string}
     */
    public function denyBackendPorts(int $apachePort, int $nginxPort): array
    {
        $ports = array_values(array_unique([$apachePort, $nginxPort]));
        if ($this->ufwActive()) {
            foreach ($ports as $port) {
                $this->runtime->exec(
                    ['/usr/sbin/ufw', 'deny', $port . '/tcp', 'comment', 'azerioid-backend-internal'],
                    null,
                    15
                );
            }

            return ['backend' => 'ufw', 'applied' => true, 'detail' => 'denied ' . implode(',', $ports)];
        }
        if ($this->firewalldActive()) {
            foreach ($ports as $port) {
                $this->runtime->exec(
                    [
                        '/usr/bin/firewall-cmd',
                        '--permanent',
                        '--add-rich-rule=rule family=ipv4 port port=' . $port . ' protocol=tcp drop',
                    ],
                    null,
                    15
                );
            }
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);

            return ['backend' => 'firewalld', 'applied' => true, 'detail' => 'dropped ' . implode(',', $ports)];
        }

        return ['backend' => 'none', 'applied' => false, 'detail' => 'Bind is loopback-only; no host firewall to update'];
    }

    private function ufwActive(): bool
    {
        if (!$this->runtime->fileExists('/usr/sbin/ufw')) {
            return false;
        }
        $st = $this->runtime->exec(['/usr/sbin/ufw', 'status'], null, 15);

        return $st->ok() && (bool) preg_match('/^Status:\s*active/mi', $st->stdout);
    }

    private function firewalldActive(): bool
    {
        if (!$this->runtime->fileExists('/usr/bin/firewall-cmd')) {
            return false;
        }
        $st = $this->runtime->exec(['/usr/bin/firewall-cmd', '--state'], null, 15);

        return $st->ok() && str_contains(strtolower($st->stdout), 'running');
    }
}
