<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Supervisor;

use AzerioidPanel\Broker\Runtime;
use AzerioidPanel\Broker\Vhost\DockerManager;
use AzerioidPanel\Broker\Vhost\SiteDocker;
use AzerioidPanel\Broker\Vhost\VhostUser;

/**
 * Which account a Supervisor program runs as (ADR A56).
 *
 * A program bound to a site — its Octane worker, its PM2 app, or an operator's program for it —
 * runs as that site's identity (az-vh-*), so it reaches exactly what the site's Terminal and PHP
 * reach. The operator has no choice here (operator decision). azerioid-supervised is left for:
 *
 *  - programs bound to no site;
 *  - a Docker program whose site does not have a daemon of its own yet (A56 part 2);
 *  - a site whose identity is still on the pre-A49 shared group;
 *  - a site the migration put back because its program stopped working as the site (see
 *    ProgramIdentityMigrator), until an operator retries.
 */
final class ProgramIdentity
{
    /** Root-only record of sites put back on azerioid-supervised, with the reason. */
    public const SHARED_FILE = '/var/lib/azerioid-panel/program-identity.json';

    /**
     * Present once azerioid-supervised has left the site groups (A56 part 3): new sites no
     * longer add it, and only a put-back site lets it back into its own group.
     */
    public const DETACHED_MARKER = '/var/lib/azerioid-panel/supervised-detached';

    /**
     * Version of the removal the marker records. 2 (v2.7.2): also the legacy group, and the
     * running processes restarted — a process keeps the groups it started with (seen on the
     * Ubuntu host: the idle shared daemon still held four site groups after v2.7.0's removal).
     */
    public const DETACH_VERSION = '2';

    public static function detached(Runtime $runtime): bool
    {
        return $runtime->fileExists(self::DETACHED_MARKER)
            && strtok($runtime->readFile(self::DETACHED_MARKER), "\n") === self::DETACH_VERSION;
    }

    public static function userFor(Runtime $runtime, ?string $domain, string $program = ''): string
    {
        if ($domain === null || $domain === '' || $runtime->getuid() !== 0
            || self::sharedReason($runtime, $domain) !== null
            || (str_starts_with($program, DockerManager::PROGRAM_PREFIX) && !SiteDocker::ready($runtime, $domain))) {
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
        if (self::detached($runtime) && $runtime->getuid() === 0) {
            // The shared account runs this site's program again: it needs the site's files.
            $runtime->exec(['/usr/bin/gpasswd', '-a', SupervisedUser::USERNAME, VhostUser::docrootGroup($runtime, $domain)], null, 30);
        }
    }

    public static function clear(Runtime $runtime, string $domain): void
    {
        $all = self::load($runtime);
        if (array_key_exists($domain, $all)) {
            unset($all[$domain]);
            self::save($runtime, $all);
            if (self::detached($runtime) && $runtime->getuid() === 0) {
                $runtime->exec(['/usr/bin/gpasswd', '-d', SupervisedUser::USERNAME, VhostUser::docrootGroup($runtime, $domain)], null, 30);
            }
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
