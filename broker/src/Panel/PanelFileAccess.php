<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Panel;

use AzerioidPanel\Broker\CaddyCli;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * What the web server may see of the panel once they are different users (A39 Part A).
 *
 * Before Part A the panel ran as Caddy's own uid, so Caddy could read the panel
 * tree simply by owning it. With a dedicated `azerioid-panel` account Caddy is a
 * stranger to those files, yet it still has to:
 *
 *  - stat and serve `web/public` (php_fastcgi try_files + static assets), and
 *  - import the terminal/Adminer route snippets under /var/lib/azerioid-panel.
 *
 * The grant is the Caddy *group* on exactly two directories, mode 0750, plus
 * world-read on the already-public `web/public` tree. Nothing is opened to other
 * local users: they cannot traverse `web/` or the state dir at all, and `.env`,
 * `storage/` and `panel.sqlite` stay owner/panel-group only.
 *
 * Self-update re-runs this after its `chown -R`, which would otherwise reset the
 * group and leave Caddy serving 403s until the next migration.
 */
final class PanelFileAccess
{
    public const STATE_DIR = '/var/lib/azerioid-panel';

    /** The group Caddy runs as, or null when it cannot be resolved. */
    public static function serverGroup(Runtime $runtime, Config $config): ?string
    {
        return CaddyCli::serviceUser($runtime) ?? ($config->webUser !== '' ? $config->webUser : null);
    }

    /**
     * Whether the split applies: only once the panel has its own identity.
     */
    public static function applies(Runtime $runtime, Config $config): bool
    {
        $group = self::serverGroup($runtime, $config);

        return $group !== null && $group !== $config->panelUser;
    }

    /**
     * @return list<string> what was done, for the caller's log
     */
    public static function apply(Runtime $runtime, Config $config): array
    {
        $group = self::serverGroup($runtime, $config);
        if ($group === null || $group === $config->panelUser) {
            return [];
        }
        $user = $config->panelUser;
        $web = rtrim($config->panelRoot, '/') . '/web';
        $notes = [];

        if ($runtime->isDir($web)) {
            $runtime->exec(['/bin/chown', $user . ':' . $group, $web], null, 30);
            $runtime->exec(['/bin/chmod', '0750', $web], null, 30);
            $notes[] = "{$web} {$user}:{$group} 0750";
        }
        if ($runtime->isDir($web . '/public')) {
            $runtime->exec(['/bin/chmod', '-R', 'o+rX', $web . '/public'], null, 60);
            $notes[] = "{$web}/public o+rX";
        }
        if ($runtime->isDir(self::STATE_DIR)) {
            $runtime->exec(['/bin/chown', $user . ':' . $group, self::STATE_DIR], null, 30);
            $runtime->exec(['/bin/chmod', '0750', self::STATE_DIR], null, 30);
            $notes[] = self::STATE_DIR . " {$user}:{$group} 0750";
        }

        return $notes;
    }
}
