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
  azerioid vhost reconcile [--repair] [--dry-run] [--json]   # audit the panel vhost projection against the config files
  azerioid vhost isolation status|apply [--confirm] [--dry-run] [--json]   # A49: every vhost identity on a group of its own
  azerioid vhost add    --domain=<d> --type=php|static|proxy [--php=<v>] [--root=<path>] [--upstream=<host:port>]
                        [--engine=caddy|apache|nginx] [--tls=off|auto|internal|dns01] [--dns-provider=cloudflare|digitalocean] [--wildcard] [--staging]
  azerioid vhost edit   --domain=<d> [--php=<v>] [--root=<path>] [--engine=caddy|apache|nginx]
                        [--tls=off|auto|internal|dns01] [--dns-provider=…] [--wildcard] [--staging]
  azerioid vhost del    --domain=<d>
  azerioid vhost files  list|read|write|delete|mkdir|rename --domain=<d> [--path=<rel>] [--dest=<rel>] [--recursive] [--json]
  azerioid vhost octane enable  --domain=<d> [--max-requests=500] [--port=34000-34999]
  azerioid vhost octane disable|reload|status --domain=<d> [--json]
  azerioid vhost pm2 enable  --domain=<d> [--instances=1] [--entry=server.js] [--port=36000-36999]
  azerioid vhost pm2 disable|reload|status|scale --domain=<d> [--instances=N] [--json]
  azerioid vhost pm2 node --domain=<d> --node=system|20|22|24   # A51; enable also takes --node=
  azerioid vhost docker enable  --domain=<d> --internal-port=N [--mode=image|compose|dockerfile] [--image=…] [--service=web]
                               [--restart=always|on-failure|never] [--volume=data:/data[:ro]]… [--registry=<name>] [--env-file=path]
  azerioid vhost docker disable|build|restart|logs|status --domain=<d>
  azerioid vhost docker services|settings|env [--reveal]|env-set --env-file=path --domain=<d>   # A50
  azerioid docker registry list|add <name> --host=ghcr.io --username=<u>|del <name>   # password: AZERIOID_REGISTRY_PASSWORD or prompt
  azerioid deploy set <domain> --repository=git@host:o/r.git [--branch=main] [--preset=none|composer|laravel|npm|custom --command=… --confirm=RUN-AS-SITE] [--schedule=off|hourly|daily@3]
  azerioid deploy config|run|rollback|key|remove <domain>       # A53: fetch with a root-only key, check out and run as the site
  azerioid deploy list

  azerioid db list      [--engine=mariadb|postgresql|mongodb] [--json]
  azerioid db add       --engine=<e> --name=<db> [--user=<u>]
  azerioid db del       --engine=<e> --name=<db>
  azerioid db edit      --engine=<e> --name=<db> --reset-password
  azerioid db access show --engine=<e> --name=<db> [--json]
  azerioid db access set  --engine=<e> --name=<db> --mode=localhost|specific|global [--ip=<ip>[,<ip>...]] [--confirm]

  azerioid mail status [--json]
  azerioid mail hostname show|set --hostname=<fqdn>
  azerioid mail domain list|enable|disable --domain=<d> [--drop-mail --confirm=DROP-MAIL]
  azerioid mail mailbox list [--domain=<d>] [--json]
  azerioid mail mailbox add|passwd|disable|enable --address=<user@domain>
  azerioid mail mailbox del --address=<user@domain> [--drop-mail --confirm=DROP-MAIL]
  azerioid mail alias list|add|del --address=<alias@domain> [--destination=<addr>] [--confirm-external]
  azerioid mail dns --domain=<d> [--json]
  azerioid mail dkim rotate --domain=<d>
  azerioid mail smarthost show|test
  azerioid mail smarthost set --host=<relay> [--port=587] --username=<u> [--tls=starttls|wrapper]
  azerioid mail smarthost clear --confirm=CLEAR-RELAY
  azerioid mail probe | selftest [--json]
  azerioid mail queue [--flush --confirm=FLUSH-QUEUE] [--json]
  azerioid mail logs [--lines=100] [--json]
  azerioid mail test send --from=<mailbox> --to=<addr> [--subject=<s>]

  azerioid component list [--json]
  azerioid component install <id> [--version=<v>] [--option=key=value]...
  azerioid component remove  <id>

  azerioid service <unit> start|stop|restart|status [--json]

  azerioid panel domain show [--json]
  azerioid panel domain set  --domain=<d> [--tls=auto|internal|dns01] [--dns-provider=…] [--staging]
  azerioid panel domain clear
  azerioid panel update check [--json]
  azerioid panel update apply [--v=<tag>] --confirm
  azerioid panel harden status [--json]
  azerioid panel harden apply --confirm [--lockdown-site-pools] [--dry-run]
  azerioid panel identity status [--json]              # A39: dedicated azerioid-panel account
  azerioid panel identity apply --confirm [--dry-run]  # runs automatically after self-update
  azerioid panel default-site show [--json]
  azerioid panel default-site set --mode=page|404|421   # answer hostnames no vhost claims
  azerioid panel default-site clear

  azerioid process list [--json]
  azerioid process create --command=<cmd> (--vhost=<domain>|--freeform) [--name=<n>] [--directory=<path>]
  azerioid process start|stop|restart|del <name>
  azerioid process logs <name> [--follow] [--lines=100]

  azerioid updates check [--json]
  azerioid updates apply [--security] --confirm

  azerioid backup create [--local|--spaces] [--db=<name|all>] [--keep=N] [--kdf=pbkdf2|argon2id]
  azerioid backup bundle <domain> [--local|--spaces]            # A52: one site — files, config, its databases
  azerioid backup bundles [--local|--spaces]                    # list bundles
  azerioid backup bundle-restore <domain> --bundle=<stamp> [--parts=files,config,db-…] [--apply --confirm] [--db-confirm=OVERWRITE]
  azerioid backup bundle-dbs <domain> [--set=mariadb:shop]…     # which databases belong to the site
  azerioid backup verify --file=<key> [--deep]                  # --deep: restore into a scratch database and drop it
  azerioid backup list   [--local|--spaces] [--json]
  azerioid backup restore --file=<path|key> --target=<db> --confirm [--overwrite] [--local|--spaces]
  azerioid backup verify  --file=<path|key> [--local|--spaces] [--json]

  azerioid sftp status [--json]
  azerioid sftp configure | unconfigure
  azerioid sftp enable|disable <domain>
  azerioid sftp keys <domain> [--json]
  azerioid sftp key-add <domain> --key="ssh-ed25519 …"   (or pipe the .pub on stdin)
  azerioid sftp key-del <domain> --fingerprint=SHA256:…

  azerioid cron list [--owner=<domain|root>] [--json]
  azerioid cron add --owner=<domain|root> --schedule='*/5 * * * *' --command='...' [--note=] [--confirm=RUN-AS-ROOT]
  azerioid cron enable|disable|del|run|log <job-id> [--lines=200] [--json]

  azerioid firewall list [--json]
  azerioid firewall allow|deny <port>[/proto] [--from=<ip|cidr>] [--note=<label>] [--revert-after=120]
  azerioid firewall delete <port>[/proto] [--from=<ip|cidr>]
  azerioid firewall confirm | revert

  azerioid totp status  [--email=] [--json]
  azerioid totp disable --email=<admin>
  azerioid totp reset   --email=<admin>
  azerioid totp confirm --email=<admin>

  azerioid audit tail [--follow] [--lines=50] [--json]

SFTP:
  File transfer only, keys only, no shell — the account's shell exists for the panel's Terminal,
  which the panel brokers. Keys live in /etc/ssh/azerioid-authorized-keys/<identity>, owned by
  root, so a site cannot install its own key and keep access after a hole is closed.
  sshd is configured through a drop-in, validated with `sshd -t`, and reloaded — never restarted.
  Your own SSH access is untouched: the Match block cannot match root.

Cron:
  A job belongs to a vhost and runs as that vhost's own identity (az-vh-*), not as root.
  A root job is possible but needs --confirm=RUN-AS-ROOT every time, including on enable.
  Output and exit codes are kept per job under /var/log/azerioid-panel/cron for 14 days.
  Root crontab lines the panel did not write are preserved and shown separately.

Firewall:
  A rule change is applied inside a revert window: if you do not run `azerioid firewall confirm`
  before it closes, the previous rules are restored automatically. The confirmation is the proof
  your own session survived the change. Use --no-revert --confirm=I-HAVE-CONSOLE-ACCESS to skip it.
  SSH (read from sshd_config), the panel port and 80/443 cannot be denied — the broker refuses.

Secrets:
  DB and mailbox passwords are generated and printed once — never accepted via argv.
  Mail relay password: set AZERIOID_RELAY_PASSWORD in the environment, or run interactively — never argv.
  DNS-01 API tokens: set AZERIOID_DNS_API_TOKEN (or DNS_API_TOKEN) in the environment — never argv.
  Backup passphrase: saved in the Backups UI, or AZERIOID_BACKUP_PASSPHRASE — never argv.
  TOTP re-auth: AZERIOID_ADMIN_PASSWORD and AZERIOID_TOTP_CODE — never argv.
  Bare --tls means --tls=auto. Aliases: dns→dns01, self→internal, on→auto. Use --staging for Let's Encrypt staging (rate-limit safe).
  vhost files write reads new content from stdin (not argv). Upload/download is UI-only.
  vhost octane is opt-in per vhost and Laravel-only: it runs FrankenPHP under Supervisor on 127.0.0.1:34000-34999
  with Caddy reverse-proxying to it. New PHP vhosts stay on PHP-FPM, and the panel's own runtime never uses Octane.
  Long-lived workers keep constructors and static state between requests — see https://laravel.com/docs/octane.
  vhost pm2 is opt-in per vhost and Node-only (proxy/static with a detected entrypoint): pm2-runtime under Supervisor
  on 127.0.0.1:36000-36999 with Caddy reverse-proxying. Cluster workers share one port; reload is a rolling restart
  of fresh Node processes — apps must bind to process.env.PORT; in-memory state is not shared across workers unless
  you use sticky sessions or an external store — see https://pm2.keymetrics.io/docs/usage/cluster-mode/.
  Mail is opt-in and reputation-sensitive: installing over a foreign MTA (including Debian's Exim) needs a typed
  REPLACE-MTA confirmation, and `mail status` reports whether outbound port 25 is open or blocked by your provider.
  updates apply / backup restore / panel update apply require --confirm. Log rotation is system logrotate (no CLI).
  Panel self-update targets semver git tags (latest by real semver, or --v=<tag>); dirty source trees are refused; failures roll back.
TXT);

        return self::SUCCESS;
    }
}
