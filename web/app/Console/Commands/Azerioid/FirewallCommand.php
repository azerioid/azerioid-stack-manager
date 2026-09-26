<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

/**
 * `azerioid firewall …` (B2 / request #2).
 *
 * A firewall is something operators reach for when something is already wrong, and
 * the panel may be the thing they cannot reach. So every rule operation is available
 * here, with the same broker guards — including the revert window, which is more
 * useful from the CLI than from the browser: the confirmation is the proof the
 * operator's SSH session survived the change.
 */
class FirewallCommand extends Command
{
    use CallsBroker;

    protected $signature = 'azerioid:firewall
        {action : list|allow|deny|delete|confirm|revert}
        {port? : port, optionally with protocol (8443 or 8443/udp)}
        {--from= : source IP or CIDR (default: anywhere)}
        {--note= : short label recorded with the rule}
        {--revert-after=120 : seconds before an unconfirmed change is put back}
        {--no-revert : apply without a revert window (needs --confirm)}
        {--confirm= : I-HAVE-CONSOLE-ACCESS, when declining the revert window}
        {--json : JSON output}';

    protected $description = 'List and change host firewall rules (ufw or firewalld)';

    public function handle(): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        try {
            return match ($action) {
                'list' => $this->list(),
                'allow', 'deny' => $this->addRule($action),
                'delete', 'remove' => $this->deleteRule(),
                'confirm' => $this->window('firewall.confirm', 'Change confirmed; it will not be reverted.'),
                'revert' => $this->window('firewall.revert', 'Previous rules restored.'),
                default => $this->usage(),
            };
        } catch (\Throwable $e) {
            return $this->failBroker($e);
        }
    }

    private function usage(): int
    {
        $this->error('Usage: azerioid firewall list|allow|deny|delete|confirm|revert [port] [--from=IP] [--note=label]');

        return self::INVALID;
    }

    private function list(): int
    {
        $data = $this->brokerData('firewall.rules', [], [], 30, false);
        if ($this->wantsJson()) {
            return $this->emitData($data);
        }

        $this->line('backend: ' . (string) ($data['backend'] ?? 'none'));
        $protected = $data['protected_ports'] ?? [];
        $this->line('protected: ssh ' . implode(',', (array) ($protected['ssh'] ?? []))
            . ' · panel ' . (string) ($protected['panel'] ?? '') . ' · web '
            . implode(',', (array) ($protected['web'] ?? [])));
        if (($data['revert']['armed'] ?? false) === true) {
            $this->warn('A change is waiting: ' . (string) ($data['revert']['description'] ?? '')
                . ' — confirm it before ' . (string) ($data['revert']['deadline'] ?? '')
                . ' or it will be reverted.');
        }
        foreach ((array) ($data['rules'] ?? []) as $rule) {
            $this->line(sprintf(
                '%-6s %-10s %-18s %s',
                (string) ($rule['action'] ?? ''),
                ((string) ($rule['port'] ?? '')) . '/' . (string) ($rule['protocol'] ?? ''),
                (string) ($rule['source'] ?? 'anywhere'),
                ($rule['managed'] ?? false) ? (string) ($rule['comment'] ?? '') : 'yours'
            ));
        }

        return self::SUCCESS;
    }

    private function addRule(string $action): int
    {
        [$port, $protocol] = $this->port();
        if ($port === null) {
            return $this->usage();
        }

        $res = $this->brokerCall('firewall.rule.add', [], [
            'action' => $action,
            'port' => $port,
            'protocol' => $protocol,
            'source' => (string) ($this->option('from') ?? ''),
            'note' => (string) ($this->option('note') ?? ''),
        ] + $this->windowInput(), 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }

        $this->info('Rule added: ' . (string) ($res->data['added']['describe'] ?? ''));
        $this->reportWindow($res->data);

        return self::SUCCESS;
    }

    private function deleteRule(): int
    {
        [$port, $protocol] = $this->port();
        if ($port === null) {
            return $this->usage();
        }

        $res = $this->brokerCall('firewall.rule.delete', [], [
            // Deleting needs to name the rule exactly, including whether it was an
            // allow or a deny, which `delete 8443` alone cannot express. An allow is
            // assumed because that is what almost every rule is.
            'action' => strtolower((string) ($this->option('note') ?? '')) === 'deny' ? 'deny' : 'allow',
            'port' => $port,
            'protocol' => $protocol,
            'source' => (string) ($this->option('from') ?? ''),
        ] + $this->windowInput(), 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }

        $this->info('Rule removed: ' . (string) ($res->data['deleted']['describe'] ?? ''));
        $this->reportWindow($res->data);

        return self::SUCCESS;
    }

    private function window(string $action, string $message): int
    {
        $res = $this->brokerCall($action, [], [], 60);
        if (! $res->ok) {
            $this->throwBrokerFailure($res);
        }
        $this->info($message);

        return self::SUCCESS;
    }

    /** @return array{0:?string,1:string} */
    private function port(): array
    {
        $raw = trim((string) ($this->argument('port') ?? ''));
        if ($raw === '') {
            return [null, 'tcp'];
        }
        [$port, $protocol] = array_pad(explode('/', $raw, 2), 2, 'tcp');

        return [$port, $protocol === '' ? 'tcp' : $protocol];
    }

    /** @return array<string,mixed> */
    private function windowInput(): array
    {
        if ((bool) $this->option('no-revert')) {
            return ['revert' => false, 'confirm' => (string) ($this->option('confirm') ?? '')];
        }

        return [
            'revert_after' => (int) $this->option('revert-after'),
            'confirm' => (string) ($this->option('confirm') ?? ''),
        ];
    }

    /** @param mixed $data */
    private function reportWindow(mixed $data): void
    {
        if (! is_array($data) || ($data['revert']['armed'] ?? false) !== true) {
            $this->line('No automatic revert was armed.');

            return;
        }
        $this->warn('Still connected? Run `azerioid firewall confirm` within '
            . (string) ($data['revert']['seconds'] ?? '') . ' seconds, '
            . 'or the previous rules are restored automatically.');
    }
}
