<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Vhost;

use AzerioidPanel\Broker\Runtime;

/**
 * One-time welcome landing page for brand-new, empty docroots.
 *
 * Only called from vhost add (not edit/reload). Never overwrites existing content.
 */
final class VhostWelcomePage
{
    /**
     * Write index.php (php) or index.html (static) when $root has no entries at all.
     *
     * @return ?string Absolute path written, or null when skipped
     */
    public static function seedIfEmpty(Runtime $runtime, string $domain, string $root, string $type): ?string
    {
        if ($type !== 'php' && $type !== 'static') {
            return null;
        }
        if (!$runtime->isDir($root)) {
            return null;
        }
        if (!self::isGenuinelyEmpty($runtime, $root)) {
            return null;
        }

        $filename = $type === 'php' ? 'index.php' : 'index.html';
        $path = rtrim($root, '/') . '/' . $filename;
        // Conservative: never clobber even if listDir raced / missed an entry.
        if ($runtime->fileExists($path)) {
            return null;
        }

        $runtime->writeFile($path, self::render($domain, $root, $type), 0660);

        return $path;
    }

    public static function isGenuinelyEmpty(Runtime $runtime, string $root): bool
    {
        if (!$runtime->isDir($root)) {
            return false;
        }
        $entries = $runtime->listDir($root);
        // listDir already excludes . and ..; any remaining name means "not empty".
        return $entries === [];
    }

    public static function render(string $domain, string $root, string $type): string
    {
        $safeDomain = htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeRoot = htmlspecialchars($root, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $phpLine = '';
        if ($type === 'php') {
            $phpLine = <<<'PHP'
    <p class="meta">PHP <?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?> is responding.</p>
PHP;
        }

        // Shared markup: for index.php the PHP line is live; for index.html it is omitted.
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeDomain} · AZERIOID Stack Manager</title>
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: center;
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      background: radial-gradient(1200px 600px at 20% -10%, #2a2418 0%, transparent 55%),
                  radial-gradient(900px 500px at 100% 0%, #1a2330 0%, transparent 50%),
                  #0b0d10;
      color: #e4e4e7;
    }
    main {
      width: min(36rem, calc(100% - 2rem));
      padding: 2rem 1.75rem;
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 0.75rem;
      background: rgba(15, 17, 21, 0.85);
    }
    .brand {
      font-size: 0.75rem; letter-spacing: 0.14em; text-transform: uppercase;
      color: #c9a227; margin: 0 0 1rem;
    }
    h1 { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.5rem; color: #fafafa; }
    p { margin: 0.5rem 0; line-height: 1.55; color: #a1a1aa; }
    code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.9em; color: #e8d48b;
      background: rgba(201, 162, 39, 0.08); padding: 0.1em 0.35em; border-radius: 0.25rem;
    }
    .meta { margin-top: 1.25rem; font-size: 0.9rem; color: #71717a; }
  </style>
</head>
<body>
  <main>
    <p class="brand">AZERIOID Stack Manager</p>
    <h1>This site is set up and ready.</h1>
    <p><code>{$safeDomain}</code> is live. Upload your site files to replace this page.</p>
    <p>Document root: <code>{$safeRoot}</code></p>
{$phpLine}  </main>
</body>
</html>
HTML;
    }
}
