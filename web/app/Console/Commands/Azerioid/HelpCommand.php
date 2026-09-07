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

  azerioid vhost list   [--stack=caddy|apache|nginx] [--json]
  azerioid vhost add    --domain=<d> --type=php|static|proxy [--php=<v>] [--root=<path>] [--upstream=<host:port>] [--tls]
  azerioid vhost edit   --domain=<d> [--php=<v>] [--root=<path>] [--tls=on|off]
  azerioid vhost del    --domain=<d>

  azerioid db list      [--engine=mariadb|postgresql|mongodb] [--json]
  azerioid db add       --engine=<e> --name=<db> [--user=<u>]
  azerioid db del       --engine=<e> --name=<db>
  azerioid db edit      --engine=<e> --name=<db> --reset-password

  azerioid component list [--json]
  azerioid component install <id> [--version=<v>] [--option=key=value]...
  azerioid component remove  <id>

  azerioid service <name> start|stop|restart|status

  azerioid process list [--json]
  azerioid process create --command=<cmd> (--vhost=<domain>|--freeform) [--name=<n>] [--directory=<path>]
  azerioid process start|stop|restart|del <name>
  azerioid process logs <name> [--follow] [--lines=100]

  azerioid audit tail [--follow] [--lines=50] [--json]

Secrets: generated DB passwords are printed once at creation/reset and never accepted via argv.
TXT);

        return self::SUCCESS;
    }
}
