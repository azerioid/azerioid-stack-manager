<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Which account a Supervisor program runs as (ADR A56).
 *
 * A program bound to a site — its Octane worker, its PM2 app, or an operator's program for it —
 * runs as that site's identity (az-vh-*), so it reaches exactly what the site's Terminal and PHP
 * reach. The operator has no choice here (operator decision). azerioid-supervised is left for:
 *
 *  - programs bound to no site;
 *  - Docker programs, until each Docker site has a daemon of its own (A56 part 2);
 *  - a site whose identity is still on the pre-A49 shared group;
 *  - a site the migration put back because its program stopped working as the site (see
 *    ProgramIdentityMigrator), until an operator retries.
 */
final class ProgramIdentity
{
    /** Root-only record of sites put back on azerioid-supervised, with the reason. */
    public const SHARED_FILE = '/var/lib/azerioid-panel/program-identity.json';

    public static function userFor(Runtime $runtime, ?string $domain, string $program = ''): string
    {
        if ($domain === null || $domain === '' || $runtime->getuid() !== 0
            || str_starts_with($program, DockerManager::PROGRAM_PREFIX)
            || self::sharedReason($runtime, $domain) !== null) {
            return SupervisedUser::USERNAME;
        }
        $user = VhostUser::username($domain);
        if (!VhostUser::userExists($runtime, $user)
            || VhostUser::docrootGroup($runtime, $domain) === VhostUser::LEGACY_GROUP) {
            return SupervisedUser::USERNAME;
        }

        return $user;
    }

    public static function isSiteIdentity(string $user): bool
    {
        return str_starts_with($user, VhostUser::PREFIX);
    }

    public static function sharedReason(Runtime $runtime, string $domain): ?string
    {
        $reason = self::load($runtime)[$domain] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /** @return array<string, string> domain => reason */
    public static function shared(Runtime $runtime): array
    {
        return array_filter(self::load($runtime), 'is_string');
    }

    public static function putBack(Runtime $runtime, string $domain, string $reason): void
    {
        $all = self::load($runtime);
        $all[$domain] = mb_substr($reason, 0, 300);
        self::save($runtime, $all);
    }

    public static function clear(Runtime $runtime, string $domain): void
    {
        $all = self::load($runtime);
        if (array_key_exists($domain, $all)) {
            unset($all[$domain]);
            self::save($runtime, $all);
        }
    }

    /** @return array<string, mixed> */
    private static function load(Runtime $runtime): array
    {
        if (!$runtime->fileExists(self::SHARED_FILE)) {
            return [];
        }
        $d = json_decode($runtime->readFile(self::SHARED_FILE), true);

        return is_array($d) ? $d : [];
    }

    /** @param array<string, mixed> $all */
    private static function save(Runtime $runtime, array $all): void
    {
        $runtime->mkdir(dirname(self::SHARED_FILE), 0750);
        $runtime->writeFile(self::SHARED_FILE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }
}
