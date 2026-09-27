# AZERIOID Stack Manager

Server control panel. Three parts:

- `web/` — Laravel 12 + Livewire panel. Runs unprivileged as the panel account.
- `broker/` — root-privileged broker (`broker/broker` → `broker.php` → `src/Kernel.php`). The panel reaches it
  only through `sudo`; every action is enumerated in `Kernel::ACTIONS` and re-validates its input.
- `deploy/` — bash installer (`install.sh`, `lib/*.sh`), uninstaller, `verify-release.sh`.

Decisions live in `docs/DECISIONS.md` (ADRs A1…A48); the target design is `docs/SPEC.md`. Read the relevant ADR
before changing behaviour it covers, and record new decisions there in the same format.

## Tests

Run from `web/`, with **PHP 8.4** — the `php` on PATH here is 8.3 and fails with hundreds of misleading
ParseErrors:

```sh
cd web && ~/Library/Application\ Support/Herd/bin/php84 vendor/bin/phpunit --no-progress
```

One suite covers both the panel (`web/tests`) and the broker (`broker/tests/Unit`). Broker tests use
`FakeRuntime`, which never touches the machine; panel tests use `FakeBroker` (`web/app/Services/Broker`), so a new
broker action also needs a FakeBroker arm.

Shell changes: `bash -n` every touched script (CI does, for all of `deploy/`).

## The broker copy in web/lib

The panel autoloads a **copy** of the broker from `web/lib/azerioid-broker/` (gitignored, created by
`deploy/lib/broker-setup.sh`), and that copy wins over `broker/src` in tests. A stale copy makes new broker code
look missing (undefined constants, old classes). A project hook in `.claude/settings.json` re-syncs it after every
edit under `broker/src/`; if you change broker files some other way, run:

```sh
rsync -a --delete broker/src/ web/lib/azerioid-broker/
```

## Identities (ADR A39)

- `web_user` (broker.json) / `WEB_USER` (installer): the web server and site PHP identity, usually `caddy`.
- `panel_user` / `PANEL_USER`: what the panel itself runs as — `azerioid-panel` once migrated, the only holder of
  the broker sudo grant. Code that acts *for the panel* (sudoers, panel file ownership, scheduler cron, handing a
  file to the panel) uses the panel user, never the web user.

## Privileged code

Anything in `broker/src/Panel/` or `deploy/` changes sudoers, ownership, systemd units or php.ini on real hosts.
The pattern there: journal every mutation, verify for real (a broker call, an HTTP request) before removing the
old path, and roll everything back on failure. Validate sudoers with `visudo -c -f` before replacing the live file
(`PanelSudoers::install`). Rewrite config files line by line — a regex whose `\s` crosses newlines has already
edited the wrong line once.

## Hosts and releases

The fleet test host is `root@64.226.78.176`. Releases: bump `VERSION`, tag `vX.Y.Z`, publish with `gh release`,
then `deploy/verify-release.sh --apply root@64.226.78.176`. Anything that touches a real host is confirmed with
the user first.

## Git

Work on a branch, not `main`. Commits and PRs carry **no** Claude/Co-Authored-By attribution — the user is the
sole author.
