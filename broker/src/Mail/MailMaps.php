<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Virtual domain / mailbox / alias maps for Postfix, plus the Dovecot passwd-file
 * userdb. A36 §4.3 rules these out of any SQL engine: mail must keep delivering
 * when MariaDB is down, so the maps are plain files the broker regenerates whole.
 *
 * Rendering is pure (static, state in → text out) so map generation is unit-testable
 * without a host; only write() touches disk and runs postmap.
 */
final class MailMaps
{
    public const VIRTUAL_DOMAINS = 'azerioid-virtual-domains';
    public const VIRTUAL_MAILBOXES = 'azerioid-virtual-mailboxes';
    public const VIRTUAL_ALIASES = 'azerioid-virtual-aliases';
    public const DOVECOT_USERS = 'azerioid-users';

    public const VMAIL_UID = 5000;
    public const VMAIL_GID = 5000;

    private const HEADER = "# AZERIOID Stack Manager — broker-generated; edits are overwritten\n";

    public function __construct(
        private readonly Config $config,
        private readonly Runtime $runtime,
        private readonly MailPaths $paths,
    ) {
    }

    /** @param array<string,mixed> $state */
    public static function domainsMap(array $state): string
    {
        $lines = [];
        foreach (self::sortedKeys($state['domains'] ?? []) as $domain) {
            if ((bool) (($state['domains'][$domain]['enabled'] ?? false))) {
                $lines[] = $domain . ' OK';
            }
        }

        return self::render($lines);
    }

    /**
     * Maildir paths are relative to virtual_mailbox_base and must end in "/"
     * so Postfix writes a Maildir rather than an mbox file.
     *
     * @param array<string,mixed> $state
     */
    public static function mailboxMap(array $state): string
    {
        $lines = [];
        foreach (self::sortedKeys($state['mailboxes'] ?? []) as $address) {
            $meta = $state['mailboxes'][$address];
            if ((bool) ($meta['disabled'] ?? false)) {
                continue;
            }
            $domain = (string) ($meta['domain'] ?? '');
            $localPart = (string) ($meta['local_part'] ?? '');
            if ($domain === '' || $localPart === '') {
                continue;
            }
            $lines[] = $address . ' ' . $domain . '/' . $localPart . '/';
        }

        return self::render($lines);
    }

    /** @param array<string,mixed> $state */
    public static function aliasMap(array $state): string
    {
        $lines = [];
        foreach (self::sortedKeys($state['aliases'] ?? []) as $address) {
            $destination = trim((string) ($state['aliases'][$address]['destination'] ?? ''));
            if ($destination !== '') {
                $lines[] = $address . ' ' . $destination;
            }
        }

        return self::render($lines);
    }

    /**
     * Dovecot passwd-file: user:hash:uid:gid:gecos:home:shell:extra
     * Disabled mailboxes keep their Maildir but get no usable password field,
     * so IMAP and submission both refuse them without deleting mail.
     *
     * @param array<string,mixed> $state
     * @param array<string,string> $hashes
     */
    public static function dovecotUsers(array $state, array $hashes, string $vmailRoot): string
    {
        $lines = [];
        foreach (self::sortedKeys($state['mailboxes'] ?? []) as $address) {
            $meta = $state['mailboxes'][$address];
            $domain = (string) ($meta['domain'] ?? '');
            $localPart = (string) ($meta['local_part'] ?? '');
            if ($domain === '' || $localPart === '') {
                continue;
            }
            $hash = (bool) ($meta['disabled'] ?? false) ? '' : ($hashes[$address] ?? '');
            $home = rtrim($vmailRoot, '/') . '/' . $domain . '/' . $localPart;
            $lines[] = sprintf(
                '%s:%s:%d:%d::%s::',
                $address,
                $hash,
                self::VMAIL_UID,
                self::VMAIL_GID,
                $home
            );
        }

        return self::render($lines);
    }

    /**
     * Regenerate every map from state and rebuild the Postfix hash databases.
     *
     * @param  array<string,mixed>   $state
     * @param  array<string,string>  $hashes
     * @return array{written:list<string>,postmap_failed:list<string>}
     */
    public function write(array $state, array $hashes): array
    {
        $postfixDir = rtrim($this->paths->postfixDir(), '/');
        $written = [];
        $failed = [];

        $files = [
            $postfixDir . '/' . self::VIRTUAL_DOMAINS => self::domainsMap($state),
            $postfixDir . '/' . self::VIRTUAL_MAILBOXES => self::mailboxMap($state),
            $postfixDir . '/' . self::VIRTUAL_ALIASES => self::aliasMap($state),
        ];
        foreach ($files as $path => $body) {
            $this->runtime->writeFile($path, $body, 0644);
            $written[] = $path;
            if (!$this->postmap($path)) {
                $failed[] = $path;
            }
        }

        $dovecotUsers = $this->dovecotUsersPath();
        $this->runtime->writeFile(
            $dovecotUsers,
            self::dovecotUsers($state, $hashes, $this->paths->vmailRoot()),
            0640
        );
        $this->runtime->exec(['/usr/bin/chown', 'root:dovecot', $dovecotUsers], null, 15);
        $written[] = $dovecotUsers;

        return ['written' => $written, 'postmap_failed' => $failed];
    }

    public function dovecotUsersPath(): string
    {
        return dirname(rtrim($this->paths->dovecotConfD(), '/')) . '/' . self::DOVECOT_USERS;
    }

    /** @return array{domains:string,mailboxes:string,aliases:string} lookup specs for main.cf */
    public function lookupSpecs(): array
    {
        $postfixDir = rtrim($this->paths->postfixDir(), '/');

        return [
            'domains' => 'hash:' . $postfixDir . '/' . self::VIRTUAL_DOMAINS,
            'mailboxes' => 'hash:' . $postfixDir . '/' . self::VIRTUAL_MAILBOXES,
            'aliases' => 'hash:' . $postfixDir . '/' . self::VIRTUAL_ALIASES,
        ];
    }

    private function postmap(string $path): bool
    {
        return $this->runtime->exec(['/usr/sbin/postmap', $path], null, 30)->ok();
    }

    /** @param list<string> $lines */
    private static function render(array $lines): string
    {
        return self::HEADER . ($lines === [] ? '' : implode("\n", $lines) . "\n");
    }

    /**
     * @param  mixed  $rows
     * @return list<string>
     */
    private static function sortedKeys(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $keys = array_map('strval', array_keys($rows));
        sort($keys);

        return $keys;
    }
}
