<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Database;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Validator;

final class DbAccessStore
{
    public const PATH = '/var/lib/azerioid-panel/db-access.json';

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /** @return array<string, array<string, array{mode:string,ips:list<string>,updated_at:?string}>> */
    public function all(): array
    {
        if (!$this->runtime->fileExists(self::PATH)) {
            return [];
        }
        try {
            $raw = $this->runtime->readFile(self::PATH);
        } catch (BrokerException) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @return array{mode:string,ips:list<string>,updated_at:?string}
     */
    public function get(string $engine, string $name): array
    {
        $all = $this->all();
        $row = $all[$engine][$name] ?? null;
        if (!is_array($row)) {
            return ['mode' => 'localhost', 'ips' => [], 'updated_at' => null];
        }
        $mode = (string) ($row['mode'] ?? 'localhost');
        if (!in_array($mode, Validator::ACCESS_MODES, true)) {
            $mode = 'localhost';
        }
        $ips = [];
        foreach ((array) ($row['ips'] ?? []) as $ip) {
            if (!is_string($ip)) {
                continue;
            }
            try {
                $ips[] = Validator::ipOrCidr($ip);
            } catch (BrokerException) {
            }
        }

        return [
            'mode' => $mode,
            'ips' => $ips,
            'updated_at' => isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
        ];
    }

    /**
     * @param  list<string>  $ips
     */
    public function put(string $engine, string $name, string $mode, array $ips): void
    {
        $all = $this->all();
        if (!isset($all[$engine]) || !is_array($all[$engine])) {
            $all[$engine] = [];
        }
        $all[$engine][$name] = [
            'mode' => $mode,
            'ips' => $ips,
            'updated_at' => $this->runtime->now(),
        ];
        $this->write($all);
    }

    public function forget(string $engine, string $name): void
    {
        $all = $this->all();
        if (!isset($all[$engine][$name])) {
            return;
        }
        unset($all[$engine][$name]);
        if ($all[$engine] === []) {
            unset($all[$engine]);
        }
        $this->write($all);
    }

    /**
     * @return list<array{mode:string,ips:list<string>}>
     */
    public function engineEntries(string $engine): array
    {
        $all = $this->all();
        $out = [];
        foreach (array_keys((array) ($all[$engine] ?? [])) as $name) {
            $out[] = $this->get($engine, (string) $name);
        }

        return $out;
    }

    /** @param array<string, mixed> $all */
    private function write(array $all): void
    {
        $dir = dirname(self::PATH);
        if (!$this->runtime->isDir($dir)) {
            $this->runtime->mkdir($dir, 0750);
        }
        $json = json_encode($all, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            throw new BrokerException('Failed to encode database access state.', 1);
        }
        $this->runtime->writeFile(self::PATH, $json . "\n", 0640);
    }
}
