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
  azerioid panel update check [--json]
  azerioid panel update apply --confirm

  azerioid process list [--json]
  azerioid process create --command=<cmd> (--vhost=<domain>|--freeform) [--name=<n>] [--directory=<path>]
  azerioid process start|stop|restart|del <name>
  azerioid process logs <name> [--follow] [--lines=100]

  azerioid updates check [--json]
  azerioid updates apply [--security] --confirm

  azerioid backup create [--local|--spaces] [--db=<name|all>] [--keep=N]
  azerioid backup list   [--local|--spaces] [--json]
  azerioid backup restore --file=<path|key> --target=<db> --confirm [--overwrite] [--local|--spaces]

  azerioid totp status  [--email=] [--json]
  azerioid totp disable --email=<admin>
  azerioid totp reset   --email=<admin>
  azerioid totp confirm --email=<admin>

  azerioid audit tail [--follow] [--lines=50] [--json]

Secrets:
  DB passwords are generated and printed once — never accepted via argv.
  DNS-01 API tokens: set AZERIOID_DNS_API_TOKEN (or DNS_API_TOKEN) in the environment — never argv.
  Backup passphrase: saved in the Backups UI, or AZERIOID_BACKUP_PASSPHRASE — never argv.
  TOTP re-auth: AZERIOID_ADMIN_PASSWORD and AZERIOID_TOTP_CODE — never argv.
  Bare --tls means --tls=auto. Aliases: dns→dns01, self→internal, on→auto. Use --staging for Let's Encrypt staging (rate-limit safe).
  vhost files write reads new content from stdin (not argv). Upload/download is UI-only.
  updates apply / backup restore / panel update apply require --confirm. Log rotation is system logrotate (no CLI).
  Panel self-update tracks origin/main only (no stable channel yet); dirty source trees are refused; failures roll back.
TXT);

        return self::SUCCESS;
    }
}
