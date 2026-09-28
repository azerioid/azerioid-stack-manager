<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Search;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Component\OperationLogger;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Elasticsearch as a host-wide, single-node, loopback-only search engine (B7 / request #1,
 * ADR A54). Installed from Elastic's own repository (key fingerprint pinned); licence SSPL,
 * recorded the way MongoDB's is (registry description).
 *
 *  - binds 127.0.0.1:9200 only (HTTP) — the transport port stays on loopback too;
 *  - security on, with a generated password for the `elastic` user. Loopback alone is not a
 *    boundary here: every site on the host can reach 127.0.0.1 (the lesson of R1), so the
 *    password is what keeps one site out of another's indices;
 *  - HTTP TLS off: the traffic never leaves the host, and apps then need no CA file;
 *  - heap fixed at half the physical RAM, capped at 31 GB (compressed object pointers).
 *
 * The password lives in a root-only file and reaches curl on stdin (`-K -`), never argv.
 */
final class ElasticsearchSetup
{
    public const CONFIG = '/etc/elasticsearch/elasticsearch.yml';
    public const HEAP = '/etc/elasticsearch/jvm.options.d/azerioid-heap.options';
    public const CREDENTIALS = '/etc/azerioid-panel/elasticsearch.json';
    public const UNIT = 'elasticsearch';
    public const URL = 'http://127.0.0.1:9200';

    private const BEGIN = '# --- azerioid-panel (ADR A54) — managed, do not edit ---';
    private const END = '# --- end azerioid-panel ---';

    /** Top-level settings the panel owns; any other occurrence is removed so none is duplicated. */
    private const OWNED = [
        'network.host', 'http.host', 'http.port', 'transport.host', 'discovery.type',
        'cluster.initial_master_nodes', 'xpack.security.enabled', 'xpack.security.http.ssl.enabled',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
    ) {
    }

    public function configure(OperationLogger $log): void
    {
        $this->runtime->writeFile(self::CONFIG, self::rewriteConfig($this->runtime->readFile(self::CONFIG)), 0660);
        $this->runtime->exec(['/usr/bin/chown', 'root:elasticsearch', self::CONFIG], null, 10);
        $heap = self::heapMb($this->physicalMb());
        $this->runtime->mkdir(dirname(self::HEAP), 0750);
        $this->runtime->writeFile(self::HEAP, "# azerioid-panel (ADR A54): half the physical RAM, at most 31 GB\n-Xms{$heap}m\n-Xmx{$heap}m\n", 0644);
        $log->info("Elasticsearch: loopback only, single node, security on, heap {$heap} MB.");

        $this->runtime->exec(['/usr/bin/systemctl', 'daemon-reload'], null, 60);
        $start = $this->runtime->exec(['/usr/bin/systemctl', 'enable', '--now', self::UNIT], null, 300);
        if (!$start->ok()) {
            throw new BrokerException('Elasticsearch did not start: ' . trim($start->stderr)
                . ' See: journalctl -u elasticsearch', 1);
        }
        if (!$this->waitForHttp()) {
            throw new BrokerException('Elasticsearch started but did not answer on ' . self::URL . ' within three minutes.', 1);
        }
        $this->resetPassword();
        $log->info('Elasticsearch is up; the elastic user\'s password was set and stored for the panel.');
    }

    /**
     * Line by line: a regex that crosses lines has already edited the wrong one once (CLAUDE.md).
     * Drops every top-level line for a setting the panel owns, the `xpack.security.http.ssl:` block
     * (its indented children with it), and any earlier managed block; appends the managed block.
     */
    public static function rewriteConfig(string $yaml): string
    {
        $out = [];
        $skipBlock = false;
        $inManaged = false;
        foreach (explode("\n", str_replace("\r\n", "\n", $yaml)) as $line) {
            if ($line === self::BEGIN) {
                $inManaged = true;

                continue;
            }
            if ($inManaged) {
                if ($line === self::END) {
                    $inManaged = false;
                }

                continue;
            }
            if ($skipBlock) {
                if ($line !== '' && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                    continue;
                }
                $skipBlock = false;
            }
            if (preg_match('/^xpack\.security\.http\.ssl:\s*(#.*)?$/', $line) === 1) {
                $skipBlock = true;

                continue;
            }
            if (preg_match('/^([A-Za-z0-9_.]+)\s*:/', $line, $m) === 1 && in_array($m[1], self::OWNED, true)) {
                continue;
            }
            $out[] = $line;
        }
        while ($out !== [] && trim((string) end($out)) === '') {
            array_pop($out);
        }

        return implode("\n", $out) . "\n\n" . implode("\n", [
            self::BEGIN,
            'network.host: 127.0.0.1',
            'http.host: 127.0.0.1',
            'transport.host: 127.0.0.1',
            'http.port: 9200',
            'discovery.type: single-node',
            'xpack.security.enabled: true',
            'xpack.security.http.ssl.enabled: false',
            self::END,
        ]) . "\n";
    }

    /** Half the physical RAM, at most 31 GB, at least 512 MB. */
    public static function heapMb(int $physicalMb): int
    {
        return max(512, min(31744, intdiv($physicalMb, 2)));
    }

    /** @return array{user:string, password:string} a new password, returned once to the caller */
    public function resetPassword(): array
    {
        $r = $this->runtime->exec([
            '/usr/share/elasticsearch/bin/elasticsearch-reset-password', '-u', 'elastic', '-b', '-s', '--url', self::URL,
        ], null, 120);
        $password = trim($r->stdout);
        if (!$r->ok() || $password === '' || preg_match('/\s/', $password) === 1) {
            throw new BrokerException('Could not set the elastic user\'s password: ' . trim($r->stderr), 1);
        }
        $this->runtime->mkdir(dirname(self::CREDENTIALS), 0750);
        $this->runtime->writeFile(self::CREDENTIALS, json_encode(['user' => 'elastic', 'password' => $password]) . "\n", 0600);
        $this->runtime->chmod(self::CREDENTIALS, 0600);

        return ['user' => 'elastic', 'password' => $password];
    }

    /**
     * GET a path with the stored credentials.
     *
     * @return array<mixed>
     */
    public function get(string $path): array
    {
        $cred = $this->credentials();
        $cfg = 'user = "elastic:' . str_replace(['\\', '"'], ['\\\\', '\\"'], $cred['password']) . "\"\n";
        $r = $this->runtime->exec(['/usr/bin/curl', '-sS', '--max-time', '20', '-K', '-', self::URL . $path], $cfg, 30);
        $decoded = json_decode($r->stdout, true);
        if (!$r->ok() || !is_array($decoded)) {
            throw new BrokerException('Elasticsearch did not answer ' . $path . ': ' . trim($r->stderr . ' ' . substr($r->stdout, 0, 200)), 1);
        }
        if (isset($decoded['error'])) {
            throw new BrokerException('Elasticsearch: ' . (is_array($decoded['error']) ? ($decoded['error']['reason'] ?? 'error') : (string) $decoded['error']), 1);
        }

        return $decoded;
    }

    /** @return array{user:string, password:string} */
    private function credentials(): array
    {
        if (!$this->runtime->fileExists(self::CREDENTIALS)) {
            throw new BrokerException('No stored Elasticsearch credentials; reset the password first.', 3);
        }
        $d = json_decode($this->runtime->readFile(self::CREDENTIALS), true);
        if (!is_array($d) || !is_string($d['password'] ?? null)) {
            throw new BrokerException('The stored Elasticsearch credentials are unreadable; reset the password.', 1);
        }

        return ['user' => 'elastic', 'password' => $d['password']];
    }

    private function waitForHttp(): bool
    {
        for ($i = 0; $i < 90; $i++) {
            $r = $this->runtime->exec(['/usr/bin/curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '3', self::URL], null, 10);
            // 401: up, and asking for credentials — which is what we want.
            if (in_array(trim($r->stdout), ['200', '401'], true)) {
                return true;
            }
            $this->runtime->exec(['/bin/sleep', '2'], null, 5);
        }

        return false;
    }

    private function physicalMb(): int
    {
        return self::memTotalMb($this->runtime->readFile('/proc/meminfo'));
    }

    public static function memTotalMb(string $meminfo): int
    {
        return preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $m) === 1 ? intdiv((int) $m[1], 1024) : 0;
    }
}
