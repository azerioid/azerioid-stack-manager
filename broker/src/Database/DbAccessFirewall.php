<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\Network\SiteHttpFirewall;
use AzerioidPanel\Broker\Runtime;

/**
 * Rewrites tagged firewall rules for one engine port from the aggregate
 * access state. ufw is preferred; firewalld is used when ufw is absent.
 *
 * Specific-IP databases produce one allow-from rule per CIDR (union across
 * the engine). Global produces an any-source allow on that port. Localhost
 * (no remote databases) deletes the tagged rules.
 */
final class DbAccessFirewall
{
    public const SIDECAR = '/var/lib/azerioid-panel/db-access-firewall.json';

    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function isActive(): bool
    {
        return $this->ufwActive() || $this->firewalldActive();
    }

    /**
     * @param  array{network:string,ips:list<string>,bind_public:bool}  $aggregate
     * @return array{backend:string,applied:bool,detail:string}
     */
    public function sync(string $engine, int $port, array $aggregate): array
    {
        $comment = 'azerioid-db-' . $engine;
        $desired = $this->desiredRules($port, $aggregate, $comment);
        $previous = $this->sidecarEngine($engine);

        if ($this->ufwActive()) {
            $this->deleteUfw($previous);
            $this->applyUfw($desired);
            $this->writeSidecar($engine, 'ufw', $desired);
            (new SiteHttpFirewall($this->runtime))->ensure();

            return ['backend' => 'ufw', 'applied' => true, 'detail' => $this->summary($desired)];
        }
        if ($this->firewalldActive()) {
            $this->deleteFirewalld($previous);
            $this->applyFirewalld($desired);
            $this->writeSidecar($engine, 'firewalld', $desired);
            (new SiteHttpFirewall($this->runtime))->ensure();

            return ['backend' => 'firewalld', 'applied' => true, 'detail' => $this->summary($desired)];
        }

        return [
            'backend' => 'none',
            'applied' => false,
            'detail' => 'No active ufw/firewalld; engine-level restrictions still applied where supported.',
        ];
    }

    /**
     * @param  array{network:string,ips:list<string>,bind_public:bool}  $aggregate
     * @return list<array{kind:string,port:int,src:?string,comment:string}>
     */
    private function desiredRules(int $port, array $aggregate, string $comment): array
    {
        if ($aggregate['network'] === 'localhost') {
            return [];
        }
        if ($aggregate['network'] === 'global') {
            return [['kind' => 'any', 'port' => $port, 'src' => null, 'comment' => $comment]];
        }
        $rules = [];
        foreach ($aggregate['ips'] as $src) {
            $rules[] = ['kind' => 'from', 'port' => $port, 'src' => $src, 'comment' => $comment];
        }

        return $rules;
    }

    private function ufwActive(): bool
    {
        if (!$this->runtime->fileExists('/usr/sbin/ufw')) {
            return false;
        }
        $st = $this->runtime->exec(['/usr/sbin/ufw', 'status'], null, 15);
        if (!$st->ok()) {
            return false;
        }

        return (bool) preg_match('/^Status:\s*active/mi', $st->stdout);
    }

    private function firewalldActive(): bool
    {
        if (!$this->runtime->fileExists('/usr/bin/firewall-cmd')) {
            return false;
        }
        $st = $this->runtime->exec(['/usr/bin/firewall-cmd', '--state'], null, 15);

        return $st->ok() && str_contains(strtolower($st->stdout), 'running');
    }

    /** @param  list<array{kind:string,port:int,src:?string,comment:string}>  $rules */
    private function applyUfw(array $rules): void
    {
        foreach ($rules as $rule) {
            $cmd = $rule['kind'] === 'any'
                ? ['/usr/sbin/ufw', 'allow', $rule['port'] . '/tcp', 'comment', $rule['comment']]
                : ['/usr/sbin/ufw', 'allow', 'from', (string) $rule['src'], 'to', 'any', 'port', (string) $rule['port'], 'proto', 'tcp', 'comment', $rule['comment']];
            $this->runtime->exec($cmd, null, 15);
        }
    }

    /** @param  array{backend?:string,rules?:list<array{kind:string,port:int,src:?string,comment:string}>}  $previous */
    private function deleteUfw(array $previous): void
    {
        foreach ((array) ($previous['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $port = (int) ($rule['port'] ?? 0);
            if ($port < 1) {
                continue;
            }
            $cmd = (($rule['kind'] ?? '') === 'any')
                ? ['/usr/sbin/ufw', '--force', 'delete', 'allow', $port . '/tcp']
                : ['/usr/sbin/ufw', '--force', 'delete', 'allow', 'from', (string) ($rule['src'] ?? ''), 'to', 'any', 'port', (string) $port, 'proto', 'tcp'];
            $this->runtime->exec($cmd, null, 15);
        }
    }

    /** @param  list<array{kind:string,port:int,src:?string,comment:string}>  $rules */
    private function applyFirewalld(array $rules): void
    {
        foreach ($rules as $rule) {
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--permanent', ...$this->firewalldArg($rule)], null, 15);
        }
        if ($rules !== []) {
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);
        }
    }

    /** @param  array{backend?:string,rules?:list<array{kind:string,port:int,src:?string,comment:string}>}  $previous */
    private function deleteFirewalld(array $previous): void
    {
        $had = false;
        foreach ((array) ($previous['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--permanent', ...$this->firewalldRemoveArg($rule)], null, 15);
            $had = true;
        }
        if ($had) {
            $this->runtime->exec(['/usr/bin/firewall-cmd', '--reload'], null, 15);
        }
    }

    /**
     * @param  array{kind?:string,port?:int,src?:?string}  $rule
     * @return list<string>
     */
    private function firewalldArg(array $rule): array
    {
        $port = (int) ($rule['port'] ?? 0);
        if (($rule['kind'] ?? '') === 'any') {
            return ['--add-port=' . $port . '/tcp'];
        }

        return ['--add-rich-rule=' . $this->richRule($rule)];
    }

    /**
     * @param  array{kind?:string,port?:int,src?:?string}  $rule
     * @return list<string>
     */
    private function firewalldRemoveArg(array $rule): array
    {
        $port = (int) ($rule['port'] ?? 0);
        if (($rule['kind'] ?? '') === 'any') {
            return ['--remove-port=' . $port . '/tcp'];
        }

        return ['--remove-rich-rule=' . $this->richRule($rule)];
    }

    /** @param  array{kind?:string,port?:int,src?:?string}  $rule */
    private function richRule(array $rule): string
    {
        $src = (string) ($rule['src'] ?? '127.0.0.1');
        $port = (int) ($rule['port'] ?? 0);

        return 'rule family="ipv4" source address="' . $src . '" port port="' . $port . '" protocol="tcp" accept';
    }

    /** @return array{backend?:string,rules?:list<array{kind:string,port:int,src:?string,comment:string}>} */
    private function sidecarEngine(string $engine): array
    {
        $all = $this->readSidecar();
        $row = $all[$engine] ?? [];

        return is_array($row) ? $row : [];
    }

    /** @param  list<array{kind:string,port:int,src:?string,comment:string}>  $rules */
    private function writeSidecar(string $engine, string $backend, array $rules): void
    {
        $all = $this->readSidecar();
        $all[$engine] = ['backend' => $backend, 'rules' => $rules];
        $dir = dirname(self::SIDECAR);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }
        $json = json_encode($all, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($json)) {
            $this->runtime->writeFile(self::SIDECAR, $json . "\n", 0640);
        }
    }

    /** @return array<string, mixed> */
    private function readSidecar(): array
    {
        if (!$this->runtime->fileExists(self::SIDECAR)) {
            return [];
        }
        try {
            $decoded = json_decode($this->runtime->readFile(self::SIDECAR), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  list<array{kind:string,port:int,src:?string,comment:string}>  $rules */
    private function summary(array $rules): string
    {
        if ($rules === []) {
            return 'tagged rules removed (localhost only)';
        }
        if (($rules[0]['kind'] ?? '') === 'any') {
            return 'port ' . $rules[0]['port'] . '/tcp open to any IPv4';
        }
        $srcs = [];
        foreach ($rules as $rule) {
            $srcs[] = (string) ($rule['src'] ?? '');
        }

        return 'port ' . $rules[0]['port'] . '/tcp allowed from ' . implode(', ', $srcs);
    }
}
