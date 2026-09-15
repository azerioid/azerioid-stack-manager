<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Mail;

use AzerioidPanel\Broker\Component\ComponentRegistry;
use AzerioidPanel\Broker\Component\OsRelease;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Registry-driven distro paths for the mail component.
 *
 * MariaDB taught us that hardcoding Debian layouts breaks EL (server_cnf, socket).
 * Every path here comes from registry/components/mail.json `distros.<key>.paths`,
 * with the first existing candidate winning and the first entry as the fallback.
 */
final class MailPaths
{
    /** @var array<string, list<string>> */
    private array $paths;

    /** @var array<string, string> */
    private array $units;

    /** @var list<string> */
    private array $foreignMtaPackages;

    /**
     * @param array<string, list<string>> $paths
     * @param array<string, string>       $units
     * @param list<string>                $foreignMtaPackages
     */
    private function __construct(
        private readonly Runtime $runtime,
        public readonly string $distroKey,
        array $paths,
        array $units,
        array $foreignMtaPackages,
    ) {
        $this->paths = $paths;
        $this->units = $units;
        $this->foreignMtaPackages = $foreignMtaPackages;
    }

    public static function for(Runtime $runtime, Config $config, ?OsRelease $os = null): self
    {
        $os ??= OsRelease::detect($runtime);
        $paths = [];
        $units = [];
        $foreign = [];
        try {
            $definition = (new ComponentRegistry($config->registryComponentsPath, $runtime))->get('mail');
            $block = $definition['distros'][$os->distroKey] ?? null;
            if (is_array($block)) {
                foreach (is_array($block['paths'] ?? null) ? $block['paths'] : [] as $key => $candidates) {
                    $list = array_values(array_filter(array_map('strval', (array) $candidates)));
                    if ($list !== []) {
                        $paths[(string) $key] = $list;
                    }
                }
                foreach (is_array($block['units'] ?? null) ? $block['units'] : [] as $key => $unit) {
                    $units[(string) $key] = (string) $unit;
                }
                $foreign = array_values(array_filter(array_map(
                    'strval',
                    is_array($block['foreign_mta_packages'] ?? null) ? $block['foreign_mta_packages'] : []
                )));
            }
        } catch (\Throwable) {
            // Registry unavailable (uninstalled tree / test fixture) — fall back to defaults below.
        }

        return new self($runtime, $os->distroKey, $paths + self::defaults(), $units + self::defaultUnits(), $foreign);
    }

    /** First candidate that exists on disk, else the first candidate. */
    public function path(string $key): string
    {
        $candidates = $this->paths[$key] ?? [];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && $this->runtime->fileExists($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }

    /** @return list<string> */
    public function candidates(string $key): array
    {
        return $this->paths[$key] ?? [];
    }

    public function unit(string $key): string
    {
        return $this->units[$key] ?? $key;
    }

    /** @return list<string> */
    public function foreignMtaPackages(): array
    {
        return $this->foreignMtaPackages !== [] ? $this->foreignMtaPackages : ForeignMta::FALLBACK_PACKAGES;
    }

    public function mainCf(): string
    {
        return $this->path('main_cf');
    }

    public function masterCf(): string
    {
        return $this->path('master_cf');
    }

    public function postfixDir(): string
    {
        return $this->path('postfix_dir');
    }

    public function dovecotConfD(): string
    {
        return $this->path('dovecot_confd');
    }

    public function opendkimConf(): string
    {
        return $this->path('opendkim_conf');
    }

    public function opendkimKeys(): string
    {
        return $this->path('opendkim_keys');
    }

    public function opendkimSocket(): string
    {
        return $this->path('opendkim_socket');
    }

    public function mailLog(): string
    {
        return $this->path('mail_log');
    }

    public function vmailRoot(): string
    {
        return $this->path('vmail_root');
    }

    /** @return array<string, list<string>> */
    private static function defaults(): array
    {
        return [
            'main_cf' => ['/etc/postfix/main.cf'],
            'master_cf' => ['/etc/postfix/master.cf'],
            'postfix_dir' => ['/etc/postfix'],
            'dovecot_conf' => ['/etc/dovecot/dovecot.conf'],
            'dovecot_confd' => ['/etc/dovecot/conf.d'],
            'opendkim_conf' => ['/etc/opendkim.conf'],
            'opendkim_keys' => ['/etc/opendkim/keys'],
            'opendkim_socket' => ['/var/spool/postfix/opendkim/opendkim.sock'],
            'mail_log' => ['/var/log/mail.log', '/var/log/maillog'],
            'vmail_root' => ['/var/vmail'],
            'tls_cert' => ['/etc/ssl/certs/ssl-cert-snakeoil.pem'],
            'tls_key' => ['/etc/ssl/private/ssl-cert-snakeoil.key'],
        ];
    }

    /** @return array<string, string> */
    private static function defaultUnits(): array
    {
        return ['postfix' => 'postfix', 'dovecot' => 'dovecot', 'opendkim' => 'opendkim'];
    }
}
