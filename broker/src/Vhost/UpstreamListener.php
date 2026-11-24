<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\Runtime;

/**
 * A126: who is listening on a site's upstream port. Octane, PM2 and Docker upstreams are
 * loopback TCP ports, and any local account can bind a port while the site's own worker is
 * down; Caddy would then send that site's visitors to it. So a port counts as the site's only
 * when every listener on it is the site's identity (or root — shared Docker's proxy).
 */
final class UpstreamListener
{
    /** @return list<int>|null uids of the listeners (ss omits uid 0), null without ss */
    public static function owners(Runtime $runtime, int $port): ?array
    {
        $ss = self::ss($runtime);
        if ($ss === null) {
            return null;
        }
        $r = $runtime->exec([$ss, '-H', '-tlne', 'sport', '=', ':' . $port], null, 5);
        if (!$r->ok()) {
            return [];
        }
        $uids = [];
        foreach (explode("\n", $r->stdout) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $uids[] = preg_match('/\buid:(\d+)\b/', $line, $m) === 1 ? (int) $m[1] : 0;
        }

        return $uids;
    }

    /** The first listener on $port that is neither the site's identity nor root; null if none. */
    public static function foreignOwner(Runtime $runtime, int $port, string $domain): ?int
    {
        $uids = self::owners($runtime, $port) ?? [];
        if ($uids === []) {
            return null;
        }
        $site = self::siteUid($runtime, $domain);
        foreach ($uids as $uid) {
            if ($uid !== 0 && $uid !== $site) {
                return $uid;
            }
        }

        return null;
    }

    /** Listening, and only by the site (or root). Without ss there is nothing to check: true. */
    public static function ownedBySite(Runtime $runtime, int $port, string $domain): bool
    {
        $uids = self::owners($runtime, $port);
        if ($uids === null) {
            return true;
        }
        if ($uids === []) {
            return false;
        }

        return self::foreignOwner($runtime, $port, $domain) === null;
    }

    public static function ss(Runtime $runtime): ?string
    {
        foreach (['/usr/sbin/ss', '/usr/bin/ss', '/bin/ss'] as $ss) {
            if ($runtime->fileExists($ss)) {
                return $ss;
            }
        }

        return null;
    }

    /** -1 when the identity cannot be resolved: then no non-root listener counts as the site's. */
    private static function siteUid(Runtime $runtime, string $domain): int
    {
        $id = $runtime->exec(['/usr/bin/id', '-u', VhostUser::username($domain)], null, 10);

        return $id->ok() && preg_match('/^\d+$/', trim($id->stdout)) === 1 ? (int) trim($id->stdout) : -1;
    }
}
