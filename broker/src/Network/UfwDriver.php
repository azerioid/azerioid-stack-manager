<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Network;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * ufw backend (B2 / request #2).
 *
 * Rules are deleted by **specification**, never by the index ufw prints. The index
 * shifts the moment anything else changes, so `ufw delete 3` deletes whatever
 * happens to be third when it runs — a race between the panel listing rules and the
 * operator confirming a deletion, with a firewall rule as the prize.
 *
 * Panel ownership is recorded in ufw's own comment field, so a reinstalled panel
 * still recognises its rules and an operator's hand-written rule is never mistaken
 * for one.
 */
final class UfwDriver implements FirewallDriver
{
    public const BIN = '/usr/sbin/ufw';

    /** ufw keeps its rule state in these; there is no export command. */
    private const STATE_FILES = ['/etc/ufw/user.rules', '/etc/ufw/user6.rules'];

    public function __construct(private readonly Runtime $runtime)
    {
    }

    public function name(): string
    {
        return 'ufw';
    }

    public function available(): bool
    {
        return $this->runtime->fileExists(self::BIN);
    }

    public function active(): bool
    {
        if (!$this->available()) {
            return false;
        }
        $st = $this->runtime->exec([self::BIN, 'status'], null, 15);

        return str_contains(strtolower($st->stdout . ' ' . $st->stderr), 'status: active');
    }

    /** @return list<FirewallRule> */
    public function rules(): array
    {
        $out = $this->runtime->exec([self::BIN, 'status', 'numbered'], null, 15);
        $rules = [];
        foreach (explode("\n", $out->stdout) as $line) {
            $rule = $this->parseLine($line);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * ufw's own output format, which is stable but not machine-oriented:
     *
     *   [ 3] 3306/tcp     ALLOW IN    10.0.0.5     # azerioid-db-mariadb
     *   [ 4] 22/tcp (v6)  ALLOW IN    Anywhere (v6)
     *
     * The v6 duplicate of an identical v4 rule is skipped: ufw writes both for one
     * `ufw allow`, and reporting two rules where the operator made one is confusing
     * and makes deletion look incomplete.
     */
    private function parseLine(string $line): ?FirewallRule
    {
        if (!preg_match('/^\[\s*(\d+)\]\s+(.+)$/', trim($line), $m)) {
            return null;
        }
        $handle = $m[1];
        $body = $m[2];

        $comment = '';
        if (str_contains($body, '#')) {
            [$body, $comment] = explode('#', $body, 2);
            $comment = trim($comment);
        }

        if (!preg_match('/^(\S+)(?:\s+\(v6\))?\s+(ALLOW|DENY|REJECT|LIMIT)\s+(?:IN|OUT|FWD)\s+(.+)$/', trim($body), $p)) {
            return null;
        }
        // Only the intersection the panel models; anything else stays visible to the
        // operator through `ufw status` but is not something the panel will rewrite.
        if (str_contains($body, '(v6)')) {
            return null;
        }
        if (!preg_match('/^(\d+)\/(tcp|udp)$/', $p[1], $to)) {
            return null;
        }

        $action = $p[2] === 'DENY' || $p[2] === 'REJECT' ? FirewallRule::DENY : FirewallRule::ALLOW;
        $from = trim($p[3]);
        $source = str_starts_with($from, 'Anywhere') ? null : $from;
        if ($source !== null && filter_var(explode('/', $source)[0], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return FirewallRule::observed($action, (int) $to[1], $to[2], $source, $comment, $handle);
    }

    public function add(FirewallRule $rule): void
    {
        $this->run(array_merge([self::BIN], $this->spec($rule), ['comment', $rule->comment]));
    }

    public function delete(FirewallRule $rule): void
    {
        $this->run(array_merge([self::BIN, 'delete'], $this->spec($rule)));
    }

    /**
     * ufw accepts the same specification for adding and deleting, which is exactly
     * why deletion is safe here.
     *
     * @return list<string>
     */
    private function spec(FirewallRule $rule): array
    {
        if ($rule->source === null) {
            return [$rule->action, $rule->port . '/' . $rule->protocol];
        }

        return [
            $rule->action, 'from', $rule->source,
            'to', 'any', 'port', (string) $rule->port, 'proto', $rule->protocol,
        ];
    }

    public function snapshot(): string
    {
        $state = [];
        foreach (self::STATE_FILES as $path) {
            $state[$path] = $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : null;
        }

        return (string) json_encode($state);
    }

    public function restore(string $snapshot): void
    {
        $state = json_decode($snapshot, true);
        if (!is_array($state)) {
            throw new BrokerException('Firewall snapshot is unreadable; refusing to restore.', 1);
        }
        foreach (self::STATE_FILES as $path) {
            $body = $state[$path] ?? null;
            if (is_string($body)) {
                // 0640 root:root, matching how ufw ships them.
                $this->runtime->writeFile($path, $body, 0640);
            }
        }
        $this->runtime->exec([self::BIN, 'reload'], null, 30);
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $result = $this->runtime->exec($command, null, 20);
        if (!$result->ok()) {
            $detail = trim($result->stderr) !== '' ? trim($result->stderr) : trim($result->stdout);
            throw new BrokerException('ufw refused the rule: ' . ($detail === '' ? 'unknown error' : $detail), 1);
        }
    }
}
