<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Runtime;

/**
 * Mail is the one component whose ports are deliberately public (A36 §3.1) —
 * inbound MX on 25, submission on 587/465, IMAPS on 993. Enabling it with ufw
 * or firewalld active must open those explicitly, exactly like SiteHttpFirewall
 * keeps 80/443 open. Silently relying on a default-allow policy is how a host
 * ends up "installed and healthy" while every message times out.
 *
 * Plaintext IMAP on 143 is never opened.
 */
final class MailFirewall
{
    /** @var array<int,string> port => rule comment suffix */
    public const PORTS = [
        25 => 'smtp',
        465 => 'smtps',
        587 => 'submission',
        993 => 'imaps',
    ];

    private const COMMENT_PREFIX = 'azerioid-mail-';

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /** @return array{backend:string,applied:bool,ports:list<int>,detail:string} */
    public function open(): array
    {
        $ports = array_keys(self::PORTS);
        if ($this->ufwActive()) {
            foreach (self::PORTS as $port => $label) {
                $this->runtime->exec(
                    ['/usr/sbin/ufw', 'allow', $port . '/tcp', 'comment', self::COMMENT_PREFIX . $label],
                    null,
                    15
                );
            }

            return [
                'backend' => 'ufw',
                'applied' => true,
                'ports' => $ports,
                'detail' => 'allowed ' . implode(', ', array_map(static fn (int $p): string => $p . '/tcp', $ports)),
            ];
        }
        if ($this->firewalldActive()) {
            foreach (self::PORTS as $port => $label) {
                $this->runtime->exec(
                    ['/usr/bin/firewall-cmd', '--permanent', '--add-port=' . $port . '/tcp'],
                    null,
                    15
                );
            }
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);

            return [
                'backend' => 'firewalld',
                'applied' => true,
                'ports' => $ports,
                'detail' => 'allowed ' . implode(', ', array_map(static fn (int $p): string => $p . '/tcp', $ports)),
            ];
        }

        return [
            'backend' => 'none',
            'applied' => false,
            'ports' => $ports,
            'detail' => 'No active ufw/firewalld; mail ports are governed by the provider firewall only',
        ];
    }

    /** @return array{backend:string,applied:bool,detail:string} */
    public function close(): array
    {
        if ($this->ufwActive()) {
            foreach (array_keys(self::PORTS) as $port) {
                $this->runtime->exec(['/usr/sbin/ufw', '--force', 'delete', 'allow', $port . '/tcp'], null, 15);
            }

            return ['backend' => 'ufw', 'applied' => true, 'detail' => 'mail port rules removed'];
        }
        if ($this->firewalldActive()) {
            foreach (array_keys(self::PORTS) as $port) {
                $this->runtime->exec(
                    ['/usr/bin/firewall-cmd', '--permanent', '--remove-port=' . $port . '/tcp'],
                    null,
                    15
                );
            }
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);

            return ['backend' => 'firewalld', 'applied' => true, 'detail' => 'mail port rules removed'];
        }

        return ['backend' => 'none', 'applied' => false, 'detail' => 'No active ufw/firewalld'];
    }

    /** @return array{backend:string,open_ports:list<int>,missing_ports:list<int>} */
    public function status(): array
    {
        $wanted = array_keys(self::PORTS);
        if ($this->ufwActive()) {
            $st = $this->runtime->exec(['/usr/sbin/ufw', 'status'], null, 15);

            return $this->classify('ufw', $wanted, $st->stdout);
        }
        if ($this->firewalldActive()) {
            $st = $this->runtime->exec(['/usr/bin/firewall-cmd', '--list-ports'], null, 15);

            return $this->classify('firewalld', $wanted, $st->stdout);
        }

        return ['backend' => 'none', 'open_ports' => $wanted, 'missing_ports' => []];
    }

    /**
     * @param  list<int>  $wanted
     * @return array{backend:string,open_ports:list<int>,missing_ports:list<int>}
     */
    private function classify(string $backend, array $wanted, string $output): array
    {
        $open = [];
        $missing = [];
        foreach ($wanted as $port) {
            if (preg_match('/\b' . $port . '\/tcp\b/', $output) === 1) {
                $open[] = $port;
            } else {
                $missing[] = $port;
            }
        }

        return ['backend' => $backend, 'open_ports' => $open, 'missing_ports' => $missing];
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
