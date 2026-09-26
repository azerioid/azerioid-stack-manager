<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Web;

use AzerioidPanel\Broker\BrokerException;
use AzerioidPanel\Broker\CaddyApply;
use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\Runtime;

/**
 * Default site for hostnames that match no vhost (B1 / request #14).
 *
 * A22 established that a lone site on a listener is Caddy's default for *any*
 * Host on that port, and fixed it for the panel port with a less-specific
 * catch-all returning 421. The same hole was never closed on `:80`/`:443`.
 *
 * Measured on a fleet host before this change: an unknown Host on `:80` got a
 * `308` to HTTPS, and the HTTPS connection then failed the TLS handshake outright
 * (`tlsv1 alert internal error`, "no peer certificate available") because no site
 * block matched the SNI and there was no catch-all. A visitor who pointed DNS at
 * the server without a matching vhost saw a broken connection rather than an
 * answer.
 *
 * Caddy resolves site blocks by specificity, not file order, so a hostless
 * `https://:443` block is always beaten by any named site. Named vhosts are
 * unaffected — which the tests pin, because getting that wrong would route every
 * site here.
 *
 * Content is served from a panel-owned directory, deliberately not under
 * `/data/www`, so no vhost identity (A25) can edit what unmatched visitors see.
 */
final class DefaultSite
{
    public const SNIPPET = 'azerioid-default-site.conf';

    public const ROOT = '/var/lib/azerioid-panel/default-site';

    public const MODE_PAGE = 'page';

    public const MODE_404 = '404';

    public const MODE_421 = '421';

    public const MODES = [self::MODE_PAGE, self::MODE_404, self::MODE_421];

    public const DEFAULT_MODE = self::MODE_PAGE;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Config $config,
    ) {
    }

    /** @return array{enabled:bool, mode:string, snippet:string, root:string} */
    public function status(): array
    {
        $path = $this->snippetPath();
        if (!$this->runtime->fileExists($path)) {
            return [
                'enabled' => false,
                'mode' => self::DEFAULT_MODE,
                'snippet' => $path,
                'root' => self::ROOT,
            ];
        }
        $body = $this->runtime->readFile($path);

        return [
            'enabled' => true,
            'mode' => $this->modeOf($body),
            'snippet' => $path,
            'root' => self::ROOT,
        ];
    }

    private function modeOf(string $body): string
    {
        if (str_contains($body, 'respond "Misdirected request." 421')) {
            return self::MODE_421;
        }
        if (str_contains($body, 'respond 404')) {
            return self::MODE_404;
        }

        return self::MODE_PAGE;
    }

    /**
     * @return array{enabled:bool, mode:string, snippet:string, root:string, applied:array<string,mixed>}
     */
    public function apply(string $mode, ?string $customHtml = null): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new BrokerException(
                'Default site mode must be one of: ' . implode(', ', self::MODES) . '.',
                2
            );
        }

        $path = $this->snippetPath();
        $previous = $this->runtime->fileExists($path) ? $this->runtime->readFile($path) : null;

        if ($mode === self::MODE_PAGE) {
            $this->writeDocument($customHtml);
        }
        $this->runtime->writeFile($path, $this->render($mode), 0644);

        try {
            $applied = CaddyApply::run($this->runtime, $this->config, 'auto');
        } catch (\Throwable $e) {
            // Never leave a rejected snippet behind: Caddy would refuse to reload
            // and every site on the host would keep serving the previous config
            // until someone noticed.
            if ($previous === null) {
                $this->runtime->deleteFile($path);
            } else {
                $this->runtime->writeFile($path, $previous, 0644);
            }
            try {
                CaddyApply::run($this->runtime, $this->config, 'auto');
            } catch (\Throwable) {
                // surfaced by the original error
            }
            throw new BrokerException('Default site rejected by Caddy: ' . $e->getMessage(), 1);
        }

        return $this->status() + ['applied' => $applied];
    }

    /** @return array{enabled:bool, mode:string, snippet:string, root:string, applied:array<string,mixed>|null} */
    public function disable(): array
    {
        $path = $this->snippetPath();
        if (!$this->runtime->fileExists($path)) {
            return $this->status() + ['applied' => null];
        }
        $previous = $this->runtime->readFile($path);
        $this->runtime->deleteFile($path);
        try {
            $applied = CaddyApply::run($this->runtime, $this->config, 'auto');
        } catch (\Throwable $e) {
            $this->runtime->writeFile($path, $previous, 0644);
            throw new BrokerException('Could not remove the default site: ' . $e->getMessage(), 1);
        }

        return $this->status() + ['applied' => $applied];
    }

    private function snippetPath(): string
    {
        return rtrim($this->config->caddyConfD, '/') . '/' . self::SNIPPET;
    }

    private function render(string $mode): string
    {
        $body = match ($mode) {
            self::MODE_421 => "\trespond \"Misdirected request.\" 421\n",
            self::MODE_404 => "\trespond 404\n",
            default => "\troot * " . self::ROOT . "\n\tfile_server\n\theader Cache-Control \"no-store\"\n"
                . "\trespond /health 204\n",
        };

        // `tls internal` mirrors the proven :3169 catch-all (A22): without a
        // certificate for an arbitrary SNI the handshake fails and the visitor sees
        // nothing at all, which is the behaviour being fixed.
        return <<<CADDY
# AZERIOID Stack Manager — default site for unmatched hostnames (broker-managed).
#
# Caddy resolves site blocks by specificity, so these hostless blocks are beaten by
# every named vhost. They only answer names no vhost claims. Do not edit: rewritten
# by `azerioid panel default-site`.
http://:80 {
{$body}}

https://:443 {
	tls internal
{$body}}

CADDY;
    }

    private function writeDocument(?string $customHtml): void
    {
        $this->runtime->mkdir(self::ROOT, 0755);
        $html = $customHtml !== null && trim($customHtml) !== ''
            ? $customHtml
            : self::defaultHtml();
        $this->runtime->writeFile(self::ROOT . '/index.html', $html, 0644);
    }

    /**
     * Neutral wording on purpose. A bare 404 tells an attacker less, but tells the
     * operator who just mis-pointed DNS nothing at all — and that is who actually
     * lands here. It names no software, version or hostname.
     */
    public static function defaultHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Domain not configured</title>
  <style>
    :root { color-scheme: dark light; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: grid; place-items: center;
      font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
      background: #0b0d10; color: #e4e4e7;
    }
    main {
      width: min(32rem, calc(100% - 2rem));
      padding: 2rem 1.75rem;
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 0.75rem;
      background: rgba(15, 17, 21, 0.85);
    }
    h1 { font-size: 1.2rem; font-weight: 600; margin: 0 0 0.75rem; color: #fafafa; }
    p { margin: 0.5rem 0; line-height: 1.6; color: #a1a1aa; font-size: 0.95rem; }
  </style>
</head>
<body>
  <main>
    <h1>This domain is not configured on this server.</h1>
    <p>The request reached the server, but no site is set up for the hostname you used.</p>
    <p>If you own this domain, check that its DNS points here and that a matching site exists.</p>
  </main>
</body>
</html>
HTML;
    }
}
