<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\Runtime;

/**
 * Broker-owned mail state. The broker must answer `mail.status` on a host where
 * the Laravel app is down, so hostname/domains/mailboxes live here rather than in
 * the panel SQLite database.
 *
 * Two files, two trust levels:
 *   state.json          0640  metadata only — never a password or a hash
 *   passdb.json         0600  Dovecot password hashes (root-only)
 * The smarthost password is not in either: it lives in Postfix's own
 * sasl_passwd (0600) so exactly one copy of that secret exists on disk.
 */
final class MailState
{
    public const DIR = '/var/lib/azerioid-panel/mail';
    public const STATE_PATH = self::DIR . '/state.json';
    public const PASSDB_PATH = self::DIR . '/passdb.json';

    public function __construct(private readonly Runtime $runtime)
    {
    }

    /**
     * @return array{
     *   hostname:string,
     *   domains:array<string,array<string,mixed>>,
     *   mailboxes:array<string,array<string,mixed>>,
     *   aliases:array<string,array<string,mixed>>,
     *   smarthost:?array<string,mixed>,
     *   alerts_via_local_mail:bool
     * }
     */
    public function load(): array
    {
        $raw = [];
        if ($this->runtime->fileExists(self::STATE_PATH)) {
            $decoded = json_decode($this->runtime->readFile(self::STATE_PATH), true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        return [
            'hostname' => trim((string) ($raw['hostname'] ?? '')),
            'domains' => $this->section($raw, 'domains'),
            'mailboxes' => $this->section($raw, 'mailboxes'),
            'aliases' => $this->section($raw, 'aliases'),
            'smarthost' => is_array($raw['smarthost'] ?? null) ? $raw['smarthost'] : null,
            'alerts_via_local_mail' => (bool) ($raw['alerts_via_local_mail'] ?? false),
        ];
    }

    /** @param array<string,mixed> $state */
    public function save(array $state): void
    {
        $this->ensureDir();
        $payload = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new BrokerException('Could not serialize mail state.', 1);
        }
        $this->runtime->writeFile(self::STATE_PATH, $payload . "\n", 0640);
    }

    /**
     * Read-modify-write under one call site so no caller forgets to persist.
     *
     * @param  callable(array<string,mixed>):array<string,mixed>  $mutator
     * @return array<string,mixed>
     */
    public function mutate(callable $mutator): array
    {
        $state = $mutator($this->load());
        $this->save($state);

        return $state;
    }

    /** @return array<string,string> address => Dovecot password hash */
    public function passdb(): array
    {
        if (!$this->runtime->fileExists(self::PASSDB_PATH)) {
            return [];
        }
        $decoded = json_decode($this->runtime->readFile(self::PASSDB_PATH), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $address => $hash) {
            if (is_string($address) && is_string($hash) && $hash !== '') {
                $out[$address] = $hash;
            }
        }

        return $out;
    }

    /** @param array<string,string> $hashes */
    public function savePassdb(array $hashes): void
    {
        $this->ensureDir();
        $payload = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new BrokerException('Could not serialize mailbox credentials.', 1);
        }
        $this->runtime->writeFile(self::PASSDB_PATH, $payload . "\n", 0600);
    }

    public function hostname(): string
    {
        return $this->load()['hostname'];
    }

    /** @return list<string> */
    public function enabledDomains(): array
    {
        $out = [];
        foreach ($this->load()['domains'] as $domain => $meta) {
            if ((bool) ($meta['enabled'] ?? false)) {
                $out[] = (string) $domain;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> mailbox addresses belonging to a domain (including disabled ones) */
    public function mailboxesForDomain(string $domain): array
    {
        $out = [];
        foreach ($this->load()['mailboxes'] as $address => $meta) {
            if ((string) ($meta['domain'] ?? '') === $domain) {
                $out[] = (string) $address;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> alias addresses belonging to a domain */
    public function aliasesForDomain(string $domain): array
    {
        $out = [];
        foreach ($this->load()['aliases'] as $address => $meta) {
            if ((string) ($meta['domain'] ?? '') === $domain) {
                $out[] = (string) $address;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Does this domain hold mail data that a vhost delete would destroy?
     *
     * @return array{mail_enabled:bool,mailboxes:list<string>,aliases:list<string>,has_data:bool}
     */
    public function domainMailFootprint(string $domain): array
    {
        $state = $this->load();
        $enabled = (bool) (($state['domains'][$domain]['enabled'] ?? false));
        $mailboxes = $this->mailboxesForDomain($domain);
        $aliases = $this->aliasesForDomain($domain);

        return [
            'mail_enabled' => $enabled,
            'mailboxes' => $mailboxes,
            'aliases' => $aliases,
            'has_data' => $enabled || $mailboxes !== [] || $aliases !== [],
        ];
    }

    private function ensureDir(): void
    {
        if (!$this->runtime->isDir(self::DIR)) {
            $this->runtime->mkdir(self::DIR, 0750);
        }
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,array<string,mixed>>
     */
    private function section(array $raw, string $key): array
    {
        $rows = is_array($raw[$key] ?? null) ? $raw[$key] : [];
        $out = [];
        foreach ($rows as $id => $meta) {
            if (is_array($meta)) {
                $out[(string) $id] = $meta;
            }
        }

        return $out;
    }
}
