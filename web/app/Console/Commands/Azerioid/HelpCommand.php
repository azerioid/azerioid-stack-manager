<?php

namespace App\Console\Commands\Azerioid;

use Illuminate\Console\Command;

class HelpCommand extends Command
{
    protected $signature = 'azerioid:help';

    protected $description = 'Show azerioid CLI usage';

    public function handle(): int
    {
        $this->line(<<<'TXT'
AZERIOID Stack Manager CLI — thin wrapper over the panel broker.

Usage:
  azerioid status [--json]
  azerioid version [--json]

  azerioid vhost list   [--engine=caddy|apache|nginx] [--json]
  azerioid vhost add    --domain=<d> --type=php|static|proxy [--php=<v>] [--root=<path>] [--upstream=<host:port>]
                        [--engine=caddy|apache|nginx] [--tls=off|auto|internal|dns01] [--dns-provider=cloudflare|digitalocean] [--wildcard] [--staging]
  azerioid vhost edit   --domain=<d> [--php=<v>] [--root=<path>] [--engine=caddy|apache|nginx]
                        [--tls=off|auto|internal|dns01] [--dns-provider=…] [--wildcard] [--staging]
  azerioid vhost del    --domain=<d>
  azerioid vhost files  list|read|write|delete|mkdir|rename --domain=<d> [--path=<rel>] [--dest=<rel>] [--recursive] [--json]

  azerioid db list      [--engine=mariadb|postgresql|mongodb] [--json]
  azerioid db add       --engine=<e> --name=<db> [--user=<u>]
  azerioid db del       --engine=<e> --name=<db>
  azerioid db edit      --engine=<e> --name=<db> --reset-password
  azerioid db access show --engine=<e> --name=<db> [--json]
  azerioid db access set  --engine=<e> --name=<db> --mode=localhost|specific|global [--ip=<ip>[,<ip>...]] [--confirm]

  azerioid component list [--json]
  azerioid component install <id> [--version=<v>] [--option=key=value]...
  azerioid component remove  <id>

  azerioid service <unit> start|stop|restart|status [--json]

  azerioid panel domain show [--json]
  azerioid panel domain set  --domain=<d> [--tls=auto|internal|dns01] [--dns-provider=…] [--staging]
  azerioid panel domain clear

  azerioid process list [--json]
  azerioid process create --command=<cmd> (--vhost=<domain>|--freeform) [--name=<n>] [--directory=<path>]
  azerioid process start|stop|restart|del <name>
  azerioid process logs <name> [--follow] [--lines=100]

  azerioid audit tail [--follow] [--lines=50] [--json]

Secrets:
  DB passwords are generated and printed once — never accepted via argv.
  DNS-01 API tokens: set AZERIOID_DNS_API_TOKEN (or DNS_API_TOKEN) in the environment — never argv.
  Bare --tls means --tls=auto. Aliases: dns→dns01, self→internal, on→auto. Use --staging for Let's Encrypt staging (rate-limit safe).
  vhost files write reads new content from stdin (not argv). Upload/download is UI-only.
TXT);

        return self::SUCCESS;
    }
}
