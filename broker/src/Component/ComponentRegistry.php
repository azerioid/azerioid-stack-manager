<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Component;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

final class ComponentRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $componentsDir,
        private readonly Runtime $runtime,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $out = [];
        foreach ($this->indexed() as $component) {
            $out[] = $component;
        }
        usort($out, static function (array $a, array $b): int {
            if (($a['system'] ?? false) !== ($b['system'] ?? false)) {
                return ($b['system'] ?? false) <=> ($a['system'] ?? false);
            }
            $cat = ['web' => 0, 'runtime' => 1, 'database' => 2, 'tool' => 3, 'cache' => 4, 'other' => 5];
            $ca = $cat[(string) ($a['category'] ?? 'other')] ?? 9;
            $cb = $cat[(string) ($b['category'] ?? 'other')] ?? 9;
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            return strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        });
        return $out;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $indexed = $this->indexed();
        if (!isset($indexed[$id])) {
            throw new BrokerException('Unknown component id.', 2);
        }
        return $indexed[$id];
    }

    /** @return array<string, array<string, mixed>> */
    private function indexed(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        if (!$this->runtime->isDir($this->componentsDir)) {
            throw new BrokerException('Component registry directory is missing.', 1);
        }
        $indexed = [];
        foreach ($this->runtime->glob(rtrim($this->componentsDir, '/') . '/*.json') as $path) {
            $raw = $this->runtime->readFile($path);
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['id'])) {
                continue;
            }
            $id = (string) $data['id'];
            $indexed[$id] = $data;
        }
        if ($indexed === []) {
            throw new BrokerException('Component registry is empty.', 1);
        }
        $this->cache = $indexed;
        return $indexed;
    }
}
