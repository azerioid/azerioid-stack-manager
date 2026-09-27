# AZERIOID Stack Manager 1.6.0 — Repository Analysis (Read-Only)

**Analysis date:** 2026-09-26
**Repository HEAD:** `25a0d88` (docs: refresh README for v1.6 features and opaque row-actions menu)
**Live verification host:** `64.226.78.176` (Ubuntu 24.04.4 LTS, kernel 6.8.0-139, panel `v1.6.0` @ `689916c`)
**Mode:** Read-only. No files modified, no dependencies touched, no server state changed.

---

# 1. Part 0 confirmation + what I actually inspected

**Read in full, before any application code:**

| Doc | Coverage |
|---|---|
| `docs/DECISIONS.md` | All 34 entries (A1–A38 as present; **A7, A11–A14, A17, A18 do not exist as headers** — the log jumps, plus 4 unnumbered entries: *Product naming*, *A19 branding closure*, *Panel database*, *Bootstrap stack*) |
| `docs/SPEC.md` | 137 lines, full |
| `docs/port-ownership.md` | 76 lines, full |
| `docs/mail-server-design.md` | 419 lines, full (approved spec, §9 all resolved) |

**Architecture as verified in source, not docs:**

- **Control plane**: Laravel 12 + Livewire, 22 Livewire page components (`web/app/Livewire/`), 5 controllers, 5 middleware (TOTP 2FA, idle timeout, IP allowlist, setup gate, security headers), CSRF on every mutation (`web/bootstrap/app.php:56` — explicitly empty except-list).
- **Privileged broker**: `broker/broker.php` → `Kernel` with a **closed enumerated action map of 137 actions** (`broker/src/Kernel.php:78-224`), 76 action classes, `Validator` with 35 validators. Execution is `proc_open` with argv arrays and `bypass_shell => true` (`PosixRuntime.php:45-47`) — **no shell interpolation anywhere I found**. Secrets on stdin only. This core is genuinely well built.
- **Sudo boundary**: one line, one binary, no wildcards (`deploy/sudoers.d/azerioid-panel`).
- **Vhost state lives in the web-server config files**, as `# azerioid-managed engine=… type=… runtime=… docker_port=…` comments parsed by regex (`CaddyParser.php:239-288`). There is **no vhosts table** in SQLite. Confirmed live: 10 conf files in `/etc/caddy/conf.d/`, carrying `runtime=docker`, `runtime=octane`, `runtime=pm2` markers.
- **Panel DB**: SQLite, 13 migrations, 8 models. Queue = `database` driver, single systemd worker.
- **Runtimes verified live**: Octane (34000–34999), PM2 (36000–36999), Docker (37000–37999), ttyd (35000–35999) — all present and allocated exactly per `port-ownership.md`.
- **A38 Docker is real and correctly implemented.** Live: `docker.service`/`docker.socket` both `masked`; `rootlesskit … --net=slirp4netns --disable-host-loopback` running as `azerioid-supervised` (uid 993) with `Linger=yes`. This matches the ADR precisely. `DockerManager.php` is 1329 lines covering image/Dockerfile/compose modes, transactional enable, restore-meta, container shell, log streaming, image validate/search.
- **A36 Mail is real and matches the spec**: `postfix`, `dovecot`, `opendkim` all `active` live; 11 `Mail/*` broker classes; 19 `mail.*` actions including `mail.probe.outbound25`, `mail.relay.selftest`, `mail.smarthost.*`; `azerioid mail` CLI present. Spec §4.1 items 1–15 are traceable to code.
- **A21 TLS renewal exists** as documented (Caddy self-renewal + `Tls/Certbot.php`, `TlsRenew`, `AcmeStatusHint`, `CertProbe`, DNS-01 via `registry/dns-providers/`).

---

# 2. My own most important technical recommendations

These come **before** your 14 features, and are ordered by what I think matters most. I separate **verified** from **opinion** throughout.

## R1 — CRITICAL: on Debian/Ubuntu the panel's sudo identity collapses into the site-PHP identity

**Status: verified in code and on the live host. This is a genuine defect, not a locked decision.**

**Summary:** on apt installs the identity that holds the panel's broker sudo grant was the same identity that runs hosted-site PHP, so hosted-site code could reach the privileged broker. Verified in code and on the live host (since fixed: Part B in v1.6.1, Part A in v2.0.0).

> **Redacted (ADR A39 disclosure note).** The precise privilege chain, the live evidence for it, and the exploitation preconditions are withheld from this public record until operators have had a window to update past v2.0.0. They will be added to ADR A39 then, per its disclosure note. Run `azerioid panel identity status` (or `panel harden status` on v1.6.x–v1.10.x) to check a host.

**Root cause (precise):** `deploy/install.sh:147` runs `WEB_USER="$(detect_web_user)"` **before** `bootstrap_packages` installs Caddy. `deploy/lib/common.sh:81-89` prefers `caddy` only *if that user already exists*. On Debian/Ubuntu `www-data` pre-exists on a stock image → `www-data` wins. The corrective re-detection at `install.sh:178-184` fires only `if ! id -u "${WEB_USER}"` — which on apt hosts is never true, so **it never corrects**. Its own comment says it is there for "a bare EL image", which is accurate; the consequence is that **EL gets the intended isolation and apt does not.**

**Second, compounding factor:** `deploy/lib/fpm.sh:12-31` actively **strips `proc_open`/`proc_get_status` out of the shared FPM `php.ini`** so the panel pool can spawn `sudo`. That mutation un-hardens every pool on that PHP version. This is *forced* by PHP-FPM semantics, not carelessness: per-pool `disable_functions` **appends** to php.ini and cannot remove entries (the stock comment at `www.conf:477` states exactly this). So with a shared master there is no way to give the panel `proc_open` without giving it to sites.

**This contradicts two documented commitments:** SPEC.md §Security model claims "*public pools retain `proc_open` lockdown*" — not true on this host; and A1/SPEC.md §Panel runtime isolation specify pool user `caddy` — the live pool is `www-data`.

**Recommended fix, and I like it because it reuses what you already built:** **generalize the A3 dedicated panel FPM master from EL-only to all families.** `fpm.sh:33-40` and `deploy/systemd/azerioid-panel-php-fpm.service` already run a separate php-fpm master with `--fpm-config /etc/azerioid-panel/php-fpm.conf` on EL. That single change gives the panel its own php.ini scope (no global `proc_open` strip) **and** a clean place to pin a dedicated service user, fixing both halves at once. This is "extend existing infrastructure, don't add a parallel system" applied to the panel's own runtime. It needs a new ADR (call it A39) because it revises A3's stated EL-scoping and A1's `caddy` pool user.

## R2 — Per-vhost state has no home, and this gates most of your 14 features

**Status: verified architectural fact; the consequence is my opinion.**

Today per-vhost state lives in two places, neither suitable for growth:

- The managed comment line, already carrying 12+ keys (`engine`, `type`, `php`, `root`, `runtime`, `octane_port`, `octane_max_requests`, `pm2_port`, `pm2_instances`, `pm2_entry`, `docker_port`, `docker_internal_port`, `docker_mode`, `docker_image`), regex-parsed, flat, no nesting.
- Ad-hoc JSON sidecars invented per feature: `/var/lib/azerioid-panel/docker-meta/*.json`, `terminal-sessions.json`, `managed-components.json`, db-access sidecars, mail state.

Requests **3, 4, 6, 7, 8, 9, 12, 13** all need structured per-vhost state (container env/volumes, per-vhost cron entries, registry credentials, Node version, backup schedule + history, deployment history + status). Adding eight more keys to a regex-parsed comment line, or eight more sidecar formats, is where this codebase acquires the expensive rewrite. **I recommend deciding the state model before implementing any of those features** — it is cheap now and very expensive after three of them ship.

I am *not* proposing that SQLite become authoritative for serving. Config-as-truth is a real strength (survives panel DB loss, matches the discipline). The proposal is a **projection**: config files stay authoritative for *how traffic is served*; a `vhosts` table + typed child tables hold *panel-managed metadata*, reconciled from the config on read.

## R3 — Backup is the weakest production-readiness area, in three independent ways

**All verified.**

**(a) Crypto is unauthenticated with no KDF.** `ArchiveCrypto.php`: AES-256-CBC, `$key = hash('sha256', $passphrase, true)` — single unsalted SHA-256, no PBKDF2/Argon2, no MAC/AEAD. Unauthenticated CBC is malleable. Restore then feeds that plaintext to `mysql` and to `tar -xzf` **as root** (`BackupRestore.php:108`) with no `--no-same-owner`/`--no-same-permissions`. With backups sitting in a remote Spaces bucket, tampering there is a plausible path to root-owned/setuid content landing under `/data/www`.

A19 branding closure locks the `LACMP1`/`LCMP1` **wire format for existing archives** — so the ADR-respecting fix is a new `LACMP2` (salt + Argon2id/PBKDF2 + AES-256-GCM, magic as AAD) that still *reads* the old formats for restore. That is fully compatible with the locked decision.

**(b) PostgreSQL and MongoDB cannot be backed up at all.** `BackupRun::dumpDb()` hardcodes `/usr/bin/mysqldump` (`:129`); `BackupRestore::restoreDb()` hardcodes `/usr/bin/mysql` and `SHOW DATABASES LIKE`. Yet both engines are first-class managed components with per-database remote access (A23), and **`mongod` is `active` on the live host right now**. I consider this the single biggest product-readiness gap in the project — bigger than any of the 14 requests.

**(c) No streaming.** The entire dump/tarball is held as a PHP string, then `encrypt()` produces a second full copy, then it is written (`BackupRun.php:32-40`). Multi-GB sites will OOM — on this 961 MB host, quite early. Also: scheduled backups **require Spaces** (`RunScheduledBackup.php:36-38` returns `FAILURE` without it), so scheduled *local* backup is impossible even though manual local backup works.

## R4 — Self-update rolls back code but never schema

**Verified.** `PanelUpdater::apply()` rollback (`:236-262`) re-checks-out the old commit and re-runs `runMigrations()` — but `runMigrations()` is `php artisan migrate --force` only (`:734`). There is no `migrate:rollback` and **no pre-update snapshot of `panel.sqlite`**. If a migration succeeds and a later step fails, rollback leaves old code against a new schema. The panel DB is a single SQLite file; a copy before `migrate` is nearly free. The rest of the updater is genuinely good (tag channel, dirty-tree refusal, rollback point from `COMMIT` marker, operation log, deferred queue restart, A30-correct marker handling).

## R5 — Two operational reliability bugs in the job layer

**Verified.**

- **Stuck operations wedge the panel permanently.** `RunComponentOperationJob::handle()` refuses to start while any other row is `status = running` (`:32-36`), and `ComponentsPage.php:372-379` gates the UI the same way. There is **no reaper** — I grepped for one. A worker killed mid-install (deploy, OOM on a 961 MB box) leaves a row `running` forever and Components is dead until someone edits SQLite by hand.
- **The queued-behind operation is silently destroyed, not delayed.** The job sets `public int $tries = 1` (`:17`) and handles contention with `$this->release(15)` (`:34`). In Laravel, a released job is re-reserved with `attempts = 2`, which exceeds `tries = 1`, so the worker fails it as MaxAttemptsExceeded *before* `handle()` runs. The operation row is never updated, so the UI shows a permanently "pending" install. The systemd unit's `--tries=3` does not help — the job property overrides it.

## R6 — Audit log writes lose records under concurrency

**Verified.** `AuditLog::write()` does read-whole-file → concatenate → write-whole-file on **every broker call**. `PosixRuntime::writeFile()` takes `LOCK_EX` on the write but `readFile()` is unlocked and happens first, so the read-modify-write is not atomic: two concurrent callers (UI + CLI + queue worker) can each write `$existing . $line` and the second silently drops the first's record. Live file is 1.3 MB, so the O(n²) cost is currently bounded by daily rotation — but `copytruncate` in `deploy/logrotate/azerioid-panel` adds a second window where in-flight content is lost. Audit integrity is a security control; `fopen('a')` + `flock` + one `fwrite` is the fix.

## R7 — There is no CI

**Verified:** no `.github/` in the repo. 51 broker + 22 web test files and 6 `smoke-p*.sh` scripts exist, all invoked manually. Given the project's evident discipline is real but entirely human-driven (fleet regression across 5 OSes, by hand), a minimal GitHub Actions job running PHPUnit + `visudo -c` + registry-JSON-schema validation on every push would protect that discipline cheaply. I'd rank this above any new feature.

---

# 3. Preliminary gaps and uncertainties

## 3a. Genuine gaps (verified absent, no ADR excludes them)

| # | Finding | Evidence |
|---|---|---|
| G1 | **PostgreSQL / MongoDB backup + restore entirely absent** | `BackupRun.php:129`, `BackupRestore.php:47` — mysql-only |
| G2 | **Firewall status is ufw-only; firewalld hosts show nothing** | `Actions/FirewallStatus.php` probes only `/usr/sbin/ufw` + fail2ban, while `DbAccessFirewall`, `MailFirewall`, `SiteHttpFirewall` all handle both backends. On EL, the Security page reports no firewall while the broker is actively writing firewalld rules. |
| G3 | **No firewall rule management at all** | Only 3 actions exist: `firewall.status`, `firewall.unban`, `firewall.fail2ban.install`. No allow/deny, no port open/close, no enable/disable. Request #2 is genuinely near-greenfield. |
| G4 | **Cron is root-only, whole-file, raw text** | `CronManage.php` = `crontab -l` / `crontab <file>`. No per-vhost cron (`crontab -u az-vh-*`), no run-now, no last-run/exit-code capture, no enable/disable, no schedule builder. Lives on the Security page. |
| G5 | **No per-vhost Node version** | `registry/components/nodejs.json` offers one host-wide `node_major` (20/22/24) at install time. Changing it is a fleet-wide breaking change for every PM2 vhost simultaneously — no isolation. Contrast PHP: `php-8.1…8.4` are separate components with per-vhost `php=` selection. |
| G6 | **No Docker registry credentials** | No `docker login`, no credential store, no `config.json` handling anywhere in the broker. Hub search is the anonymous public API (`DockerManager.php:367`). |
| G7 | **Docker image mode has no env, no volumes, no restart policy** | `runCommand()` emits exactly `docker run --rm --name … -p 127.0.0.1:N:M <image>` (`:948-953`). Containers are ephemeral — state is lost on every restart, and there is no way to pass configuration or secrets. I consider this a sharper gap for request #3 than compose-stack orchestration. |
| G8 | **Compose port-override targets the wrong service** | `firstComposeService()` (`:980-1000`) is a hand-rolled line scanner returning the **first** service under `services:`, assuming 2-space indent. A compose file listing `db:` before `web:` gets the Caddy upstream port published on the database. No YAML parser is used. |
| G9 | **No default site on `:80`/`:443`** | Verified live: unknown Host on `:80` → **308 to HTTPS**, then the TLS handshake **fails** (`tlsv1 alert internal error`, alert 80, "no peer certificate available") because no block matches the SNI and there is no catch-all. A visitor gets an opaque connection failure. This is the A22 `:3169` problem, unsolved for site ports — and it gives request #14 real technical substance. |
| G10 | **Archive extract, copy, and in-place archive creation absent** | `VhostFileOp::OPS = ['list','read','write','mkdir','rename','move','delete']`. Extract is the documented v1 zip-slip exclusion (SPEC.md:129) — *that* part is a locked decision. **Copy** and **create-archive-in-place** are not excluded anywhere and are simply absent. |
| G11 | **`vhost.files.move` exists in the broker but has no UI** | Action registered at `Kernel.php:222`; only `rename` is wired in `VhostFilesPage.php`. Backend exists, UI missing. |
| G12 | **`/vhosts/{d}/files/zip` assembles archives inside panel PHP-FPM** | `VhostFilesController::zip()` reads each file through the broker, then builds the ZIP in panel memory and `sys_get_temp_dir()`. It respects the letter of SPEC.md:116 (no direct docroot access) but puts vhost file contents in the panel process. It is also flat-file only — selecting a directory cannot work, since `vhost.files.read` targets files. |
| G13 | **No retention on `audit_logs`, `component_operations`, `panel_update_operations`, `backup_jobs`** | Only `metric_samples` is pruned (7 days, `SampleMetrics.php:36`). SQLite grows unbounded. |
| G14 | **Elasticsearch: nothing exists** | Zero matches for elasticsearch/opensearch across the whole tree. |
| G15 | **SFTP: nothing exists** | Only an unrelated Laravel `filesystems.php` driver comment. |
| G16 | **No CI** | See R7. |

## 3b. Previously-scoped-out decisions — flagged, not rediscovered

| Locked decision | ADR | My read |
|---|---|---|
| RBAC / multi-account | **A15** (+ SPEC.md:71) | Correct v1 call. `users.role` is already nullable. Strongest future-phase candidate. **Worth revisiting — but after R1**, because R1 means the current single-admin boundary is weaker than it appears. |
| Full git CI/CD pipeline | prior planning (not in DECISIONS.md) | Request #12 will be presented explicitly as *reopening a declined direction*. Preliminary view: the "build pipeline" half remains inconsistent with the project's discipline; a narrow `git pull + hook` slice is a different, much smaller proposition. |
| Docker = per-vhost single-container | **A38** | Scope boundary is deliberate and well-argued. My finding is that **G7 (env/volumes) is a gap *inside* the locked scope**, not an expansion of it — worth separating from the compose-stack/registry/fleet question. |
| Auto SSL renewal | **A21** | Exists and works. Only genuine refinements will be proposed. |
| Archive extract / zip-slip | SPEC.md:129 | Locked exclusion. Copy and archive-*creation* are not covered by it. |
| Legacy `lacmp-panel` migration | **A2** | Removed intentionally. Not touching. |
| fail2ban history wiped each install | **A20** | Accepted tradeoff, explicitly "do not fix". Not touching. |
| Caddy local CA not in OS trust store | **A33** | Accepted. Not touching. |
| Webmail / spam UI / quotas | **A36** §4.2 | Locked out of mail v1. |

## 3c. Uncertainties — not guessed

1. **Is `WEB_USER=www-data` universal on apt hosts, or specific to this host's install history?** The code path says universal; I have one apt data point. Confirming needs a fresh install on a second apt host — i.e. **state change, so I am asking before doing it**.
2. **A34 (Laravel 13 / Livewire 4) status.** ADR says *In progress* on `upgrade/laravel-13-livewire-5`, "do not merge to main". The tree is Laravel 12. Is that branch alive, and does it land before or after this roadmap? It changes sequencing materially.
3. **`azerioid-panel-php-fpm` inactive on live** is *correct* per A3 (EL-only) — not a defect. Flagged so it isn't misread later.
4. Whether `/data/www/<site>.lacmp-pre-restore-*` trees (created by `BackupRestore.php:126`, never pruned) exist and consume disk on real hosts — `/data/www` not yet enumerated.

---

# 4. İlk sual qrupu (ən yüksək təsirli qərarlar)

Bu suallar arxitekturaya, təhlükəsizliyə və işlərin sıralanmasına birbaşa təsir edir. Cavablarınızı gözləyirəm — bunlar həll olunmadan tam roadmap yazmaq mənasızdır, çünki 14 tələbin çoxu bu qərarlardan asılıdır.

### Sual 1 — R1 (kritik təhlükəsizlik) necə və nə vaxt düzəldilməli?

Debian/Ubuntu-da panel `www-data` kimi işləyir və `www-data` root broker-ə parolsuz `sudo` hüququna sahibdir. Yəni hər hansı hostlanan PHP saytında kod icrası → host üzərində root. EL-də bu problem yoxdur.

| Variant | İzah | Risk |
|---|---|---|
| **A** | Ayrıca `azerioid-panel` sistem istifadəçisi + A3-ün dedicated FPM master-ini bütün OS-lərə genişləndirmək (yeni ADR A39) | Struktur həlli. Mövcud apt hostlarında pool user, sudoers, fayl sahibliyi dəyişir — miqrasiya lazımdır |
| **B** | Yalnız `install.sh`-da `detect_web_user()` sıralamasını düzəltmək (paketlərdən **sonra** çağırmaq) → `caddy` seçilir | Kiçik dəyişiklik, lakin `fpm.sh`-ın qlobal `proc_open` strip problemi **qalır** — sayt pool-ları hələ də zəifləmiş olur |
| **C** | Hər ikisi: B indi hotfix kimi (v1.6.1), A növbəti major-da | Tez qismən müdafiə + düzgün son həll |

**Mənim tövsiyəm: C.** Səbəb: B tək başına kifayət deyil (qlobal php.ini mutasiyası PHP-FPM semantikasına görə məcburidir — per-pool `disable_functions` yalnız *əlavə* edir, silə bilmir), ancaq B dərhal tətbiq oluna bilər və hücum zəncirinin ən asan hissəsini kəsir. A isə sizin öz A3 mexanizminizi təkrar istifadə edir, yeni sistem gətirmir.

**Sizdən lazım olan qərar:** (i) hansı variant; (ii) mövcud apt hostları **yerində düzəldilsin** (upgrade zamanı avtomatik, işləyən panelə toxunur) yoxsa **yalnız fresh install** + ayrıca `azerioid panel harden` əmri; (iii) bu iş hər şeydən əvvəl gəlirmi, yoxsa roadmap-ın 1-ci fazasının içində?

### Sual 2 — Per-vhost state modeli (bu, 8 tələbi bloklayır)

Hazırda vhost metadata ya regex ilə oxunan `# azerioid-managed` şərh sətrindədir (artıq 12+ açar), ya da hər feature-in özü üçün uydurduğu JSON sidecar-dadır. Tələb 3, 4, 6, 7, 8, 9, 12, 13 strukturlaşdırılmış per-vhost state tələb edir.

| Variant | İzah | Tradeoff |
|---|---|---|
| **A** | Şərh sətrini uzatmaq | Sıfır yeni infrastruktur; sətir idarəolunmaz olur, nested data mümkün deyil, regex kövrəkliyi artır |
| **B** | Per-vhost JSON sidecar (`docker-meta` nümunəsi ümumiləşdirilir) | Mövcud pattern; panel DB itsə də sağ qalır; sorğu/filtr/tarixçə çətindir |
| **C** | `panel.sqlite`-da real cədvəllər (`vhosts` + tipli uşaq cədvəllər), **config faylları xidmət üçün hələ də authoritative**, DB yalnız projeksiya/metadata | Sorğu, tarixçə, UI filtrləri asanlaşır; reconcile məntiqi yazılmalıdır; panel DB itkisi metadata itkisi deməkdir (backup ilə örtülür) |

**Mənim tövsiyəm: C**, bir şərtlə — config-as-truth pozulmur. Səbəb: tələb 13 (deployment status tracking) və 9 (backup history) mahiyyətcə *zaman seriyası* tələb edir; bunu fayl sidecar-larında saxlamaq həmin featurelər üçün ayrı-ayrı miniatür verilənlər bazası yazmaq deməkdir. C həm də R2-dəki "bahalı rewrite" riskini indi, ucuz olduğu halda bağlayır.

**Sizdən lazım olan qərar:** hansı variant; və əgər C — config faylları hər oxunuşda reconcile edilsin (həmişə düzgün, daha yavaş) yoxsa yalnız dəyişiklik zamanı (daha sürətli, drift riski)?

### Sual 3 — Backup: yeni format və engine örtüyü nə vaxt?

Üç ayrı problem var: (a) LACMP1 autentifikasiyasız CBC + salt-sız SHA-256 KDF; (b) PostgreSQL və MongoDB **heç cür** backup edilə bilmir (halbuki `mongod` canlı hostda **aktiv işləyir**); (c) bütün arxiv PHP yaddaşında saxlanır (streaming yoxdur).

**Sizdən lazım olan qərar:**

1. `LACMP2` (salt + Argon2id/PBKDF2 + AES-256-GCM, köhnə formatı **yalnız oxuma** üçün saxlayaraq) **indi** gəlsin, yoxsa sonra? A19 bu dəyişikliyə mane olmur — orada kilidlənən şey *mövcud arxivlərin* wire formatıdır, yeni versiya əlavə etmək qadağan deyil. **Tövsiyəm: indi**, çünki format dəyişikliyi nə qədər gec gəlsə, o qədər çox köhnə arxiv miras qalır.
2. PostgreSQL + MongoDB backup/restore **9-cu tələbin içində** mi, yoxsa ondan **əvvəl, müstəqil release-blocker** kimi mi? **Tövsiyəm: müstəqil blocker.** Səbəb: bu, yeni funksiya deyil — mövcud, sənədləşdirilmiş komponentlərdəki örtülməmiş boşluqdur.
3. Streaming (yaddaşda saxlamamaq) bu roadmap-a daxildirmi? 961 MB RAM-lı hostda bu praktik limitdir.

### Sual 4 — Elasticsearch: hansı forma və hansı host sinfi?

Kodda heç nə yoxdur — tam greenfield. Canlı test hostu: **961 MB RAM, 2 GB swap, 397 MB available**. Elasticsearch realistik olaraq minimum 2 GB RAM istəyir; A31 preflight isə RAM+swap cəmini ölçür və swap altında "yavaş ola bilər" xəbərdarlığı ilə **davam edir** — yəni ES belə hostda quraşdırıla bilər və sonra OOM-dan ölər.

**Sizdən lazım olan qərar:**

1. **Single-node, cluster, yoxsa hər ikisi?** Tövsiyəm: **yalnız single-node**, A16/A35/A37/A38-dəki "nazik dilim" intizamına uyğun olaraq. Cluster çoxserverli idarəetmə deməkdir və bu, layihədə hələ mövcud olmayan bir domendir (multi-server control plane).
2. **Elasticsearch yoxsa OpenSearch?** Bu, lisenziya qərarıdır (ES: SSPL/Elastic License; OpenSearch: Apache 2.0). MongoDB üçün siz SSPL-i qəbul edib registry-də qeyd etmisiniz (SPEC.md:100) — yəni presedent var, ancaq bu, açıq qərar tələb edir.
3. **Host-wide komponent, yoxsa per-vhost runtime?** Tövsiyəm: **host-wide managed komponent** (MariaDB/Redis pattern-i, loopback-only bind), per-vhost deyil — ES per-vhost olarsa, hər sayt 1–2 GB JVM heap deməkdir.
4. **ES üçün A31 preflight sərtləşdirilsinmi?** Yəni bu komponent üçün swap-ı saymayan, fiziki RAM əsaslı **hard block**? Tövsiyəm: **bəli** — bu, A31-ə ES-spesifik istisna əlavə etmək deməkdir və yeni ADR tələb edir.

---

## İcazə sorğusu

Sual 1-dəki 1-ci qeyri-müəyyənliyi (apt hostlarında `WEB_USER=www-data` universaldır, yoxsa bu hostun quraşdırma tarixçəsinə xasdır) təsdiqləmək üçün ikinci bir apt hostunda **təmiz quraşdırma** lazımdır — bu, server state-ini dəyişir. Read-only qaydasına görə **bunu etmirəm**. İcazə verirsinizsə, deyin; əks halda kod yolunun oxunuşuna əsaslanaraq davam edirəm (kod açıq şəkildə universal olduğunu göstərir).

## Növbəti addım

Bu 4 suala cavabınızdan sonra hazırlanacaq:

- **Part 3** — 14 tələbin hər biri üçün ayrı mövcud-vəziyyət / boşluq / UI / implementasiya spesifikasiyası analizi
- **Part 4** — iki bölməli roadmap (Section A: mənim tövsiyələrim, Section B: sizin 14 tələbiniz), fazalar, təsirlənən fayllar, asılılıqlar, təhlükəsizlik nəzərləri, acceptance criteria, testlər

Heç bir kod yazılmır və heç bir fayla toxunulmur.

---
---

# OPERATOR DECISIONS RECORDED (2026-09-26)

| Q | Decision |
|---|---|
| R1 | **Variant C** — B as immediate hotfix (v1.6.1), A in next major (ADR **A39**). Existing apt hosts fixed **in place during upgrade**. Handled as an **urgent security fix BEFORE the roadmap**, via a separate prompt. **Presented separately below, outside the roadmap.** |
| Q2 | **Variant C** — real tables in `panel.sqlite`; config files remain authoritative for serving. Reconcile **only on change** (performance); add a separate **`reconcile now`** command for full audit. |
| Q3 | **LACMP2 now.** PostgreSQL/Mongo backup = **independent blocker**, separate from request #9. **Streaming included.** |
| Q4 | **Single-node only**, **host-wide component**. ES-specific hard RAM preflight: **yes**, new ADR. License: **Elasticsearch (SSPL)** — chosen on the MongoDB precedent, to be recorded in the registry the same way. |
| Permission | Second apt host clean install authorised — **not needed**; resolved by deterministic proof of `detect_web_user()`. `doctl` unauthenticated in any case. |

**Uncertainty 3c.1 — RESOLVED.** `detect_web_user()` executed verbatim against a stock-apt-image stub (`caddy` absent, `www-data` present) returns `www-data`; the `install.sh:178` corrective re-detect does **not** fire because `id -u www-data` succeeds. `www-data` (uid 33) ships in Debian `base-passwd` on every Debian/Ubuntu image. **`WEB_USER=www-data` is latched on every apt install — universal, not host-specific.**

---
---

# P0 — URGENT SECURITY FIX (SEPARATE, OUTSIDE THE ROADMAP)

Per your instruction this is **not** a roadmap phase. It ships before roadmap work begins, under its own prompt. This section is the specification you need for that prompt.

## P0.a — Hotfix (v1.6.1, Variant B)

**Goal:** stop new apt installs from latching `www-data`, and correct hosts already latched.

**Change 1 — detection ordering.** `deploy/install.sh:147` currently runs `WEB_USER="$(detect_web_user)"` before `bootstrap_packages`. Move the authoritative detection to **after** `bootstrap_packages` (after the Caddy package creates the `caddy` user). Keep an early provisional value only for `--dry-run` display.

**Change 2 — make the corrective re-detect unconditional.** `deploy/install.sh:178-184` is guarded by `if ! id -u "${WEB_USER}"`, which never fires on apt. Replace with an unconditional re-detect after packages, unless `--web-user=` was passed explicitly by the operator.

**Change 3 — in-place remediation on upgrade (you chose in-place).** A migration step that, when it detects `web_user = www-data` in `/etc/azerioid-panel/broker.json` **and** the `caddy` user now exists:

1. Rewrite the panel FPM pool (`user`/`group`/`listen.owner`/`listen.group`) to the new user.
2. Rewrite `/etc/sudoers.d/azerioid-panel` for the new user — **validate with `visudo -c -f` before replacing**, and keep the old file until validation passes.
3. `chown -R` the panel tree, `storage/`, `bootstrap/cache/`, `/var/lib/azerioid-panel`, `/var/log/azerioid-panel` (pool user must keep write access — see `deploy/systemd/php-fpm-azerioid-panel.conf` `ReadWritePaths`).
4. Restore the `proc_open`/`proc_get_status` entries that `deploy/lib/fpm.sh:12-31` stripped from the shared `php.ini`, using the `${fpm_ini}.azerioid-panel.bak` that `fpm.sh:14` already creates.
5. Reload FPM + Caddy; verify the panel answers and a broker call succeeds **before** declaring success.

**Ordering hazard (must be respected):** if sudoers is rewritten before the pool user changes, the panel is instantly unable to reach the broker and the UI dies mid-upgrade. Sequence must be: create/confirm new user → chown → write **additive** sudoers (both old and new user) → switch pool → verify broker call → remove the old sudoers entry. That keeps a working path at every intermediate step.

**Rollback:** if the post-switch verification fails, restore the old pool file and the old sudoers, reload, and fail loudly. Never leave a host with neither user authorised.

## P0.b — Structural fix (next major, Variant A, ADR A39)

**Decision to record in A39:** the dedicated panel PHP-FPM master, currently EL-only per **A3**, becomes the **universal** panel runtime on all families; and the panel pool user becomes a **dedicated `azerioid-panel` system account** rather than `caddy` or `www-data`, revising **A1**.

**Why this and not more sudoers hardening:** per-pool `disable_functions` **appends** to `php.ini` and cannot remove entries (stock comment, `www.conf:477`). With a shared php-fpm master there is no way to grant the panel `proc_open` without granting it to every site pool. A separate master with its own `--fpm-config` is the only clean fix, and `deploy/systemd/azerioid-panel-php-fpm.service` + `fpm.sh:33-40` already implement it for EL. This extends proven infrastructure instead of adding a parallel system.

**Files affected:** `deploy/lib/fpm.sh`, `deploy/install.sh`, `deploy/lib/common.sh`, `deploy/systemd/azerioid-panel-php-fpm.service`, `deploy/systemd/php-fpm-azerioid-panel.conf`, `deploy/sudoers.d/azerioid-panel`, `deploy/lib/broker-setup.sh`, `deploy/lib/selinux.sh`, `deploy/uninstall.sh:241-243`, `docs/DECISIONS.md` (A39), `docs/SPEC.md` (§Security model, §Panel runtime isolation).

**Acceptance criteria:**

- [ ] Fresh install on Ubuntu 24.04, Debian 12, Alma 9, Rocky 9, CentOS Stream 9 → `/etc/sudoers.d/azerioid-panel` names a user that is **not** the site-PHP pool user.
- [ ] `grep '^disable_functions' <fpm php.ini>` on an apt host still lists `proc_open` and `proc_get_status` after install.
- [ ] Site pools show `proc_open` disabled via `php -i` through a site vhost.
- [ ] Upgrade from a latched `www-data` host completes, panel stays reachable throughout, and a broker call succeeds after.
- [ ] `visudo -c` passes at every intermediate step of the migration.
- [ ] `uninstall.sh --full` removes the new identity (A25 discipline).
- [ ] SELinux Enforcing unaffected on EL (A3 chain intact).

**Tests required:** new `deploy/test/smoke-p0-identity.sh` asserting pool user ≠ site pool user and `proc_open` still disabled for site pools; broker unit test that the sudoers template renders a non-`www-data` user; a migration test from a seeded `www-data` state.

**Decisions still needed from you before P0.b:** (i) exact account name — `azerioid-panel` vs reusing `caddy`; (ii) whether the panel keeps its own PHP version pin (A1's 8.4) independent of the distro php-fpm version; (iii) whether P0.b's migration is automatic on self-update or gated behind an explicit `azerioid panel harden` command.

---
---

# PART 3 — YOUR 14 REQUESTED FEATURES

Landing Page Manager and Cloudflare Deployment are **excluded** throughout, per your scope removal. Cloudflare appears only where an existing dependency already exists (`registry/dns-providers/cloudflare.json`, A21 DNS-01).

Legend: **[FI]** fully implemented · **[PI]** partial · **[UI-only]** · **[BE-only]** backend exists, UI missing · **[DOC]** documented not implemented · **[NF]** not found · **[?]** uncertain

---

## #1 — Elasticsearch Manager

**Current state: [NF].** Zero matches for `elasticsearch`/`opensearch` anywhere in the tree. Complete greenfield.

**What already exists that this reuses:** the whole managed-component machinery — `registry/components/*.json` schema, `ComponentInstaller`, `ComponentRepoInstaller` (GPG-pinned third-party repos per **A10**), `ComponentPreflight` (**A31**), `ComponentDetector`, `ManagedManifest`, `OperationLogger`, `ComponentsPage`, `azerioid component` CLI, `ServiceControl`/`ServiceStatus`, `PortOwnership`. A new search engine is a **registry entry plus a small number of broker actions**, not new infrastructure.

**Exact missing functionality**

1. `registry/components/elasticsearch.json` — packages, Elastic's official repo + GPG fingerprint, unit name, detect command, `min_ram_mb`, port 9200 loopback. **SSPL is recorded exactly as the MongoDB precedent does it: in the `description` string.** Verified: `registry/components/mongodb.json` reads `"MongoDB document database (mongodb-org; SSPL license)"` — there is **no dedicated `license` field** in `registry/schema.json`, so the description is the established place.
2. Repo installer branch in `ComponentRepoInstaller` (currently handles `nodejs`/NodeSource, Sury, Remi, Docker, Caddy).
3. **ES-specific hard RAM preflight** — you approved a new ADR. `ComponentPreflight` per **A31** counts `MemAvailable + SwapFree` and only *warns* under swap pressure. A JVM heap does not degrade gracefully under swap; it OOM-kills. This component needs an opt-out from A31's combined-headroom rule: **hard block on physical RAM below threshold**, swap not counted.
4. JVM heap sizing — `-Xms`/`-Xmx` written to `jvm.options`, defaulted from physical RAM (conventional: 50% of RAM, capped ~31 GB for compressed-oops).
5. Security baseline: bind `127.0.0.1:9200` only, `discovery.type: single-node`, security plugin enabled with a generated admin password (stored as a panel secret, never argv — **A23** CLI secret discipline).
6. Broker actions: `search.status` (cluster health, heap, index count), `search.indices` (list + size), `search.password.reset`. Health/diagnostics only.
7. `ComponentsPage` card + sidebar page; `azerioid search …` CLI for parity.
8. `docs/port-ownership.md` row for 9200; `conflicts` entry against the *other* engine.

**UI specification:** Components card (install/remove/status) following the Redis/MariaDB pattern. A `Search` sidebar page, interactive only when installed: health strip (cluster status green/yellow/red, heap used %, node count = 1, uptime), index table (name, docs, size, health), connection details (loopback URL, user, reveal-once password), reset-password action, recent log tail. **Not** in scope: index creation/mapping editor, query console, snapshot/restore UI, Kibana/Dashboards.

**Implementation specification:** host-wide managed component, loopback-only, single-node, no per-vhost binding — per your Q4 answer. Applications reach it at `127.0.0.1:9200` using panel-generated credentials.

**Questions this feature name does not answer — səncə hansı variant?**

1. ~~**Lisenziya**~~ — **QƏRAR VERİLDİ: Elasticsearch (SSPL)**, MongoDB presedentinə uyğun, registry `description` sahəsində qeyd olunacaq. Bu bənd bağlıdır. **Bir qeyd:** SSPL-i qəbul etmək MongoDB üçün olduğu kimi burada da operatorun öz istifadəsi kontekstindədir — panel komponenti *quraşdırır*, onu xidmət kimi yenidən satmır. Əgər gələcəkdə Stack Manager-in özü managed-hosting təklifinin bir hissəsi kimi satılarsa, SSPL §13-ü hüquqi baxımdan yenidən qiymətləndirmək lazım gələcək; bu, kod qərarı deyil, ona görə də burada yalnız qeyd olaraq saxlayıram.

2. **Heap default** — fiziki RAM-ın 50%-i standartdır, lakin 2 GB hostda bu 1 GB deməkdir və panel + MariaDB + Caddy üçün yer qalmır. Sərt minimum nə olsun: 2 GB, 4 GB?
3. **Security plugin** — açıq (parol + TLS-in-transit, quraşdırma mürəkkəbliyi) yoxsa bağlı (yalnız loopback-a etibar)? Tövsiyəm: **açıq**, çünki loopback-da eyni hostdaki hər vhost-un kodu ona çata bilər — R1-in dərsi məhz budur.

---

## #2 — Firewall Manager improvements

**Current state: [PI] — read-only, and broken on EL.** Confirmed unstarted as a manager; not in progress.

**What exists**

- Three broker actions only: `firewall.status`, `firewall.unban`, `firewall.fail2ban.install`.
- `Actions/FirewallStatus.php` returns raw `ufw status verbose` text plus fail2ban jails and banned IPs.
- `SecurityPage` renders it (`sub` string: *"SSH audit · UFW · fail2ban · root cron"*).
- Purpose-specific **writers** already exist and are good: `Network/SiteHttpFirewall` (80/443), `Database/DbAccessFirewall` (3306/5432/27017, **A23**), `Mail/MailFirewall` (25/465/587/993, **A36**). All three handle **ufw and firewalld**.

**Exact missing functionality**

1. **G2 — `FirewallStatus` is ufw-only.** It probes `/usr/sbin/ufw` and nothing else, while the writers all support firewalld. On EL the Security page shows no firewall **while the broker is actively writing firewalld rules**. This is a bug, not a missing feature — I put it in Section A.
2. No rule management at all: no add/delete allow/deny, no port open/close, no service-name rules, no source-CIDR rules, no enable/disable of the firewall itself, no default-policy control.
3. No unified view of **panel-owned vs operator-owned** rules. The writers tag their rules (`DbAccessFirewall::writeSidecar`) but nothing surfaces the distinction, so an operator cannot tell which rules the panel will reclaim.
4. No IPv6 handling surfaced.
5. No fail2ban jail management (enable/disable/configure jails, ban manually, adjust bantime) — only unban and install.
6. No `azerioid firewall …` CLI namespace at all.

**Security considerations (this is the highest-risk feature in your list):** a firewall rule editor on a remote-administered box is a **lockout weapon**. The panel is reached over SSH tunnel (`:3169`) or optional public IP/白-label `:443`. A rule that drops 22, 3169, or 443 locks the operator out permanently. Non-negotiable guards: (a) **80/443 always allowed** — already a documented invariant (`SPEC.md:63`, `port-ownership.md` rule 5); (b) SSH port and the panel port can never be denied, enforced in the broker not the UI; (c) any change that would close the operator's **current** source IP requires typed confirmation; (d) apply-then-verify with automatic revert on loss of reachability — the **A22** apply/rollback pattern is the precedent; (e) panel-owned tagged rules are not editable by hand in the UI, only via their owning feature.

**UI specification:** `Security → Firewall` gains: backend badge (ufw / firewalld / none, with an install CTA), default-policy display, a rules table split into **Panel-managed** (read-only, showing owning feature) and **Operator rules** (editable), add-rule form (port or service, protocol, source any/CIDR, action allow/deny, comment), delete with confirm, and an **enable/disable firewall** action behind typed confirm. A separate fail2ban section: jail list with status/bantime/findtime, per-jail enable/disable, banned-IP table with unban, manual ban.

**Implementation specification:** one new `Network/FirewallManager` abstraction with ufw and firewalld drivers (mirroring `Web/WebServerDriver` → `CaddyDriver`/`ApacheDriver`/`NginxDriver`, and `Database/DatabaseDriver` → three engines — the project already has this pattern twice). New actions `firewall.rules.list|add|delete`, `firewall.policy.set`, `firewall.enable|disable`, `firewall.jail.list|set|ban`. Existing writers refactored to call the shared manager so tagging is uniform.

**Questions:** (1) Should operator rules be **declarative** (panel owns the whole rule set, reconciles, overwrites hand edits) or **additive** (panel only adds/removes its own, leaves foreign rules)? Tövsiyəm: **additive** — declarative on a firewall is how you lose a box. (2) Should the reachability-verify-and-revert use a fixed timeout or require an explicit "confirm I'm still connected" click within N seconds? Tövsiyəm: **timed auto-revert**, because the click itself can be the thing that's blocked.

---

## #3 — Docker Manager improvements

**Current state: [FI] within **A38**'s scope, with two real defects inside that scope.** I will not describe this as absent or minimal — it is a substantial, adversarially-tested feature, verified running on the live host (rootful masked, rootless `dockerd` as `azerioid-supervised` uid 993, `Linger=yes`, slirp4netns, `--disable-host-loopback`).

**What exists:** `Vhost/DockerManager.php` (1329 lines), `Component/DockerRootlessSetup.php` (361), `Actions/VhostDocker.php`, 8 broker actions (`status`, `enable`, `disable`, `build`, `restart`, `logs`, `image.validate`, `image.search`), three modes (pull image / Dockerfile build / compose build), loopback-only port allocation 37000–37999, transactional enable with restore-meta, container shell (`VhostContainerShellPage`) and log streaming (`VhostContainerLogsPage`), Hub search, uninstall refusal while any vhost uses `runtime=docker`.

**Two defects inside the locked scope — these are bugs, not scope expansion:**

- **G7 — image mode has no env, no volumes, no restart policy.** `runCommand()` (`DockerManager.php:948-953`) emits exactly `docker run --rm --name … -p 127.0.0.1:N:M <image>`. Consequences: the container is **ephemeral** (`--rm`), so all state is lost on every restart/rebuild; there is **no way to pass configuration or secrets** to the container; no named volume or bind mount for persistence. A database or any stateful image is unusable in image mode. I consider this the sharpest gap in request #3 — sharper than compose-stack orchestration.
- **G8 — compose port override targets the wrong service.** `firstComposeService()` (`:980-1000`) is a hand-rolled line scanner that returns the **first** service key under `services:`, assuming exactly 2-space indentation. A compose file listing `db:` before `web:` gets the Caddy upstream port published on the **database**. No YAML parser is used anywhere in the broker.

**Genuinely out-of-scope-by-**A38** items, and my read on each:**

| Expansion | **A38** says | My recommendation |
|---|---|---|
| Cross-vhost container list / fleet UI | explicitly rejected ("not a Portainer-style container-fleet UI") | **Do not build.** No operator need is served that per-vhost status doesn't already cover, and it invites managing unrelated host containers — which A38 rejects by name. |
| Compose **stack** orchestration beyond one vhost | out of scope | **Do not build.** But **do** fix G8 so single-vhost multi-service compose is correct. |
| Volume management UI | out of scope | **Partially reopen** — not a general volume manager, but per-vhost **named volume or docroot bind mount** is required to make G7 fixable. Narrow, vhost-scoped. |
| Network management UI | out of scope | **Do not build.** Rootless has no overlay networks anyway (A38 notes this). |
| Registry credentials | out of scope | See #6 — worth reopening narrowly. |

**UI specification for the in-scope fixes:** the Docker section of the vhost edit panel gains an **Environment** editor (key/value rows, values write-once-reveal, stored as panel secrets not in the managed comment), a **Persistence** choice (none / named volume / bind a subdirectory of the docroot), and a **Restart policy** display. Compose mode gains an explicit **service selector** (parsed service list, operator picks which service receives the published port) instead of silently taking the first.

**Implementation specification:** env values are the first real secret-bearing per-vhost state — they must live in the Q2 Variant C tables, encrypted, never in the Caddy comment, never in argv, redacted from audit (`AuditLog::REDACT_KEYS` already covers `content`/`secret`/`token` patterns). Compose service selection requires a real YAML parser — `symfony/yaml` is almost certainly already in the Laravel dependency tree, but the **broker has no Composer autoloader**, so either vendor a minimal parser into `broker/src` or do the parse panel-side and pass the chosen service name to the broker as a validated string. **The latter is cleaner and I recommend it.**

**Questions:** (1) Volumes — named Docker volume (opaque, survives rebuild, invisible to File Manager) or a docroot subdirectory bind mount (visible/editable in File Manager, but the container writes as the supervised UID)? Tövsiyəm: **docroot bind mount**, because File Manager visibility is one of the panel's strengths and A38 already accepts supervised-UID writes on the host. (2) Should `--rm` be dropped so containers persist between restarts, or kept with volumes carrying state? Tövsiyəm: **keep `--rm` + volumes** — ephemeral containers with external state is the correct container model and avoids stale-container drift.

---

## #4 — Cronjob Manager improvements

**Current state: [PI] — root-only, whole-file, raw text.**

**What exists:** `Actions/CronManage.php` (51 lines). `cron.list` = `crontab -l` returning raw lines with the warning *"These entries run as root."* `cron.set` = validate every line with `Validator::cronLine()`, require typed `UPDATE-ROOT-CRON`, write a temp file, `crontab <file>` — **whole-file replace**. UI is a raw textarea on `SecurityPage` (`saveCron`, `:65`). `Validator::cronLine()` (`:512-525`) rejects NUL/newline/backtick/`$(` and requires 5 fields or an `@` shorthand.

**Exact missing functionality**

1. **No per-vhost cron.** Everything runs as **root**. Yet **A25** already gives every vhost a dedicated `az-vh-*` identity (verified live: 9 such accounts, group `azerioid-vhosts`, home = docroot, all password-locked `L`). Per-vhost cron via `crontab -u az-vh-…` is the obvious, ADR-consistent extension — and it removes the need to run site cron as root, which is the single biggest safety win here.
2. No structured entries — the panel has no concept of a *job*, only lines of text. No name, no description, no enable/disable toggle (operators comment lines out by hand).
3. No **run-now**.
4. No execution history: no last-run timestamp, no exit code, no output capture, no failure alerting. An operator cannot tell whether a cron job has been silently failing for a month.
5. No schedule builder / human-readable preview ("every day at 03:00"). Raw 5-field syntax only.
6. Whole-file replace is a **lost-update hazard**: two admins (or UI + CLI) editing concurrently — last writer wins silently.
7. No `azerioid cron …` CLI namespace.
8. Panel-owned entries are not distinguished from operator entries, so nothing protects `SchedulerInstall`'s own Laravel scheduler line from being clobbered by a textarea save.

**Security considerations:** root cron is arbitrary root code execution by definition — this is the most powerful surface in the panel after the broker itself, and today it is reachable by anyone who can reach the Security page. Per-vhost cron running as `az-vh-*` is a **privilege reduction** and should become the default; root cron should require a separate, louder confirmation. *(R1 interaction with this surface: redacted per the ADR A39 disclosure note.)*

**UI specification:** a dedicated `Cron` page (moved off Security). Job table: name, schedule (human-readable + raw), command, **run as** (root / vhost identity), enabled toggle, last run, last exit code, next run. Create/edit form with a schedule builder (preset cadences + advanced raw field, live "next 5 runs" preview), command field, run-as selector, working directory. Actions: run now (streams output), enable/disable, delete, view output history. Panel-owned rows shown read-only with their owning feature.

**Implementation specification:** jobs become rows in the Q2 Variant C tables (this is a clear case where the comment line cannot help — cron is not vhost-config, and history is a time series). The broker renders crontabs **from** that state rather than accepting raw text, which eliminates the lost-update problem and makes panel-owned entries structurally safe. Output capture: wrap each command so stdout/stderr and exit code land in a per-job log under `/var/log/azerioid-panel/cron/` with logrotate coverage, and the exit code is written back to the job row. Keep a raw-edit escape hatch behind the existing typed confirm for operators who want it.

**Questions:** (1) Should existing hand-written root crontab lines be **imported** into structured jobs on first use, or left alone and shown as read-only "unmanaged"? Tövsiyəm: **left alone, shown as unmanaged** — importing risks mangling something load-bearing. (2) Output retention: how long, and capped at what size per run? (3) Should a failing cron job raise a panel **alert** (the `AlertEvaluator` + Telegram path already exists)? Tövsiyəm: **yes** — this is most of the value of the feature.

---

## #5 — Automatic SSL renewal & refresh

**Current state: [FI] per **A21** — renewal genuinely exists. One real refinement is missing, and I have live evidence for it.**

**What exists:** `Tls/Certbot.php` (`issueHttp01`, `issueDns01`, `renewDryRun`, `ensureRenewalHook`), `Tls/VhostTlsIssuer`, `Tls/CertProbe` (reads origin cert via `127.0.0.1:443` + SNI), `Tls/TlsMode`, `Tls/AcmeStatusHint`, `Tls/DnsProviderRegistry`, `Actions/TlsRenew`, `Actions/TlsCerts`, `Actions/TlsDnsCredential`. Caddy renews its own HTTP-01 certs natively; `certbot.timer` + deploy-hook `azerioid-reload.sh` reloads Caddy for DNS-01 static certs. DNS tokens are stdin-only into root-only `0600` files. Verified live: `/etc/letsencrypt/renewal-hooks/deploy/azerioid-reload.sh` is installed and executable.

**Live verification — two honest observations:**

1. **`certbot.timer` is `inactive` / `not-found`** and `/etc/letsencrypt/live/` does not exist on this host. certbot is not installed here, so the **A21** DNS-01 renewal path has **no live evidence on this box**. The deploy hook is pre-installed, which is correct and forward-looking.
2. **No real-CA certificate has ever been issued on this host.** `/var/lib/caddy/.local/share/caddy/certificates/` contains **only** `local/` — there is no `acme-v02.api.letsencrypt.org-directory/`. Six vhosts hold `tls internal` self-signed certs. **Four vhosts** (three customer sites and a Docker test site) have **no `tls` directive** — i.e. they are configured for automatic HTTPS (HTTP-01) — and have **no certificate on disk at all**.

So four vhosts are in a silently-failed-issuance state right now.

**The one genuinely missing refinement — and it is precisely this:**

`AlertEvaluator` **does** have a TLS expiry rule (`rule_key => 'tls.expiry'`, threshold `tls_days`, default 14). But the branch reads:

```php
$days = $c['days_remaining'] ?? null;
if ($days === null) { continue; }   // AlertEvaluator.php:171-174
```

A vhost with **no certificate** has no `days_remaining`, so it is **silently skipped**. Expiry alerting works only for certs that exist. **There is no alert for "automatic TLS is configured and issuance has never succeeded"**, and no alert for "renewal is failing". That is exactly the state of four live vhosts, and the panel is silent about all four.

**Missing functionality, precisely scoped:** (1) a `tls.missing` / `tls.issuance_failed` alert rule for vhosts whose TLS mode is `auto`/`dns01` but which have no usable cert; (2) surfacing Caddy's own ACME failure reason — `AcmeStatusHint` exists, so wire it into the alert message; (3) a `certbot.timer` health check in `tls.renew.dry-run`'s result that alerts when DNS-01 certs exist but the timer is inactive (today it reports the state but nothing acts on it); (4) a manual **force-renew** action per vhost (today only `tls.renew.dry-run` exists — there is no non-dry renew action); (5) renewal history.

**UI specification:** the Vhosts TLS column gains an explicit **failed/missing** state (not just issuer + expiry). A TLS detail panel per vhost: mode, issuer, expiry, last issuance attempt + outcome, ACME hint on failure, force-renew button. Alerts page gains the new rule with its threshold.

**Do not treat as greenfield.** This is ~4 small additions to a working system.

**Questions:** (1) How long should a vhost be allowed to sit with `tls=auto` and no cert before it alerts — immediately, or after a grace period (DNS may legitimately not be pointed yet)? Tövsiyəm: **grace period, ~24h, operator-configurable**, because "created the vhost before pointing DNS" is the normal workflow. (2) Should force-renew be rate-limited to protect Let's Encrypt quotas? Tövsiyəm: **yes** — a retry button on an ACME-rate-limited domain makes things worse.

---

## #6 — Docker Hub / private registry integration

**Current state: [NF].** No `docker login`, no credential store, no `config.json` handling anywhere in the broker — I grepped for all three. `DockerManager::searchImages()` (`:367`) uses the **anonymous public** Hub API. `validateRemoteImage()` (`:308-351`) uses `docker manifest inspect` / `buildx imagetools inspect` with **no credentials**, so private images fail validation and `docker pull` fails at enable time with an auth error.

**Relationship to **A38**:** A38 explicitly lists "no general Docker Hub/private-registry credential management" as out of scope for v1. So this is **reopening a scoped-out decision** — and I think a *narrow* version is worth it, for a reason A38 itself implies: A38's whole premise is "the operator already has an image and wants it bound to a domain." Private images are an extremely common case of exactly that. The scoped-out thing was a *general credential manager*; a per-vhost pull secret is smaller.

**Exact missing functionality**

1. Credential storage: registry host, username, token/password — as encrypted panel secrets.
2. `docker login` executed as `azerioid-supervised` against its rootless daemon, writing `~/.docker/config.json` under that user — **or better**, `--password-stdin` at pull time without persisting credentials to disk.
3. Authenticated variants of `validateRemoteImage` and `prepareWorkload`'s `docker pull`.
4. Scoping model: are credentials global to the host, or per-vhost?
5. Rotation/removal, and cleanup on vhost delete and component uninstall.
6. `azerioid vhost docker registry …` CLI.

**Security considerations — this is the crux and it needs your decision:** `~/.docker/config.json` stores credentials **base64-encoded, not encrypted**. Every Docker-runtime vhost shares the single `azerioid-supervised` rootless daemon (A38's locked identity layout, with per-`az-vh-*` daemons explicitly deferred). Therefore **any credential persisted into that user's `config.json` is readable by every other Docker-runtime vhost's container-adjacent tooling that can reach that UID.** A38 already names this class of risk: *"an operator who supplies a malicious or careless compose file can still access/modify anything `azerioid-supervised` can reach (e.g. other Docker-runtime vhosts' own container data)."* Registry credentials landing there would extend that accepted blast radius to **third-party registry credentials** — a meaningfully worse consequence than container data.

**My recommendation:** do **not** persist credentials in `config.json`. Store them encrypted in the panel, and inject them **per operation** via `docker login --password-stdin` immediately followed by `docker logout`, or better, use `docker pull` with a short-lived credential passed on stdin. This keeps the secret out of the shared UID's filesystem entirely. It costs one extra step per pull and preserves A38's isolation reasoning instead of quietly widening it.

**UI specification:** in the vhost Docker section, an optional **Private registry** block: registry host (default `docker.io`), username, token (write-once reveal), test-connection action. A global Settings list of saved registries that vhosts can reference by name, so the same credential isn't re-entered per site — but the *injection* stays per-operation.

**Questions:** (1) Per-vhost credentials, host-global credentials, or named credentials referenced by vhosts? Tövsiyəm: **named global credentials, referenced per vhost** — matches how `registry/dns-providers` + stored DNS tokens already work under A21. (2) Do you need registries beyond Docker Hub (GHCR, ECR, GitLab, Harbor)? ECR needs token refresh via AWS SDK, which is a different and much larger problem — worth excluding explicitly. (3) Confirm you accept the no-persist design, or prefer the simpler `docker login` persistence with its documented blast radius.

---

## #7 — Per-vhost Node.js version manager

**Current state: [NF] for per-vhost; [FI] for one host-wide version.**

**What exists:** `registry/components/nodejs.json` with a single `install_options.node_major` (default `22`, choices `20|22|24`), installed from NodeSource via `ComponentRepoInstaller::installNodeSourceRepo()` (`:231`). `ComponentsPage:89,106` surfaces the choice at install time. **A16** (revised) keeps Node runtime-install-only at system level; **A37** adds PM2 as a per-vhost runtime with a shared global `npm install -g pm2`.

**Exact missing functionality and why it matters more than it looks**

There is **one** Node major for the whole host. Changing it means reinstalling the component, which **simultaneously changes the runtime under every PM2 vhost on the box** — with no isolation, no per-site pinning, and no staged rollout. With two Node sites on different major versions, the host simply cannot serve both. Once you have more than one Node vhost this stops being a convenience gap and becomes a **reliability requirement**.

The precedent is already in the repo: **PHP is done correctly.** `php-8.1`, `php-8.2`, `php-8.3`, `php-8.4` are four separate registry components, co-installed, with per-vhost selection carried in the managed comment (`php=8.4`) and validated by `Validator::phpVersion($version, $installed)`. Node should follow that shape.

**Two viable implementation approaches:**

| Approach | How | Trade-off |
|---|---|---|
| **A — mirror the PHP pattern** | Separate registry components `nodejs-20`, `nodejs-22`, `nodejs-24` from NodeSource, co-installed to versioned paths; per-vhost `node=22` in the managed comment; PM2 supervisor program invokes the chosen version's binary | Consistent with the project's own proven pattern; NodeSource packages **conflict with each other** on the same host (all provide `/usr/bin/node`) — needs `update-alternatives` or versioned install prefixes, which is real work |
| **B — a version manager (fnm / nvm / volta)** | Install one manager under `azerioid-supervised`, per-vhost `.node-version`, PM2 program resolves via the manager | Sidesteps package conflicts entirely; but introduces a **new external tool** and a second notion of "installed runtime" outside the registry — which cuts against the project's registry-driven discipline and against A16's "runtime install only, no npm global tooling by default" |

**My recommendation: A**, with versioned install prefixes rather than `update-alternatives`. Reason: it keeps every runtime inside the registry/component model that `ComponentDetector`, `ManagedManifest`, Components page, and `azerioid component` already understand, and it matches how the operator already reasons about PHP. B is faster to build but adds a parallel runtime-management system, which is exactly what A16/A35/A37/A38 keep refusing to do.

**Also missing regardless of approach:** PM2 itself is a **single shared global install** (A37). If two vhosts need different Node majors, `pm2-runtime` must run under the matching Node — so PM2 becomes per-version too, not one global.

**UI specification:** vhost edit gains a **Node version** selector listing installed majors (same control shape as the existing PHP version selector), with an inline CTA to install another major. Components page shows each Node major as its own card. Changing a version on a PM2 vhost warns that the worker restarts and requires a reload.

**Questions:** (1) Approach A or B? (2) How many majors co-installed — all three, or operator-chosen? (3) Does per-vhost `npm`/`node_modules` rebuild on version change become the panel's job, or the operator's? Tövsiyəm: **operator's**, with a clear warning — native modules compiled against the old major will break, and silently rebuilding someone's dependency tree is not a thing a control panel should do unasked.

---

## #8 — Advanced Vhost Manager backup

**Current state: [PI] — file-tree backup exists; a vhost *bundle* does not.**

**What exists:** `backup.files` (`BackupRun::tarSite()`, `:163`) tars **one directory under `/data/www` by name**, excluding hardcoded `vendor`, `node_modules`, `storage/logs`. `backup.caddy` tars web-server config. Restore (`BackupRestore::restoreFiles()`) has a genuinely good dry-run preview + move-aside + rollback.

**Why this is not a vhost backup:** a vhost is not just a directory. Restoring `backup.files` + `backup.caddy` does **not** restore: the vhost's databases (and which DBs belong to which vhost is not recorded anywhere), its TLS mode/certs, its `az-vh-*` identity and uid, its Supervisor programs (`octane-*`, `pm2-*`, `docker-*`), its runtime config (Octane port/max-requests, PM2 instances/entry, Docker mode/image/ports), its mail domain/mailboxes/aliases/DKIM keys, its cron entries, or its PHP version. `backup.caddy` is **host-wide**, not per-vhost, so restoring one vhost's config means restoring everyone's.

**Exact missing functionality**

1. A per-vhost bundle manifest enumerating: docroot tree, web-server config fragment, TLS material + mode, runtime config, Supervisor program definitions, associated databases + dumps, mail state, cron entries, PHP/Node version, `az-vh-*` identity metadata.
2. **Vhost↔database association** — does not exist today. `db.list` and `vhost.list` are unrelated. Without it, "back up this vhost" cannot know which databases to include. This is a prerequisite and it lands naturally in the Q2 Variant C tables.
3. Restore into a **different domain** (clone/staging), which requires rewriting the domain in config, creating a new identity, reallocating runtime ports, and rewriting DB names/grants.
4. Per-vhost schedule + retention (today: one global schedule, Spaces-only).
5. Operator-controlled excludes (today hardcoded).
6. Restore preview at bundle level, and partial restore (files only / DB only).
7. Pre-restore snapshots are left in `/data/www` as `<site>.lacmp-pre-restore-<stamp>` (`BackupRestore.php:126`) and **never pruned** — unbounded disk growth.

**Dependencies:** requires Section A's backup work (streaming, LACMP2, pg/mongo engines) and the Q2 state model. This is the most dependent of the 14 and should be late.

**Security considerations:** a vhost bundle contains DB dumps, DKIM private keys, TLS private keys, and (once #3 lands) container env secrets. That makes the archive far more sensitive than today's file tarball — which is precisely why LACMP2 (AEAD + KDF) must land first, not after. Restore must never run `tar` as root without `--no-same-owner --no-same-permissions`, and must refuse entries with setuid bits or device nodes.

**Questions:** (1) Should a bundle be **self-contained** (one encrypted archive) or a **manifest + component archives** (restore parts independently)? Tövsiyəm: **manifest + parts**, because partial restore is the common real need and a 20 GB monolith is unrestorable on a small host. (2) Should restore-to-different-domain be in scope, or is same-domain restore enough for v1? It roughly doubles the work. (3) Retention for pre-restore snapshots?

---

## #9 — Database Backup & Restore Manager improvements

**Current state: [PI] — MariaDB only, and that is the headline.**

**What exists:** `backup.db` → `mysqldump --single-transaction --quick --routines` (`BackupRun.php:129`), all-databases or one. `backup.restore.db` → `mysql` with `SHOW DATABASES LIKE` existence check and typed `OVERWRITE` confirm. `backup.list`, `backup.prune` (keep-N by filename sort). Local or DigitalOcean Spaces destination (`SpacesClient`, `spaces.test`). Encryption via `ArchiveCrypto`. `BackupJob` model records kind/name/status/size/duration/error. `BackupsPage` UI + `azerioid backup` CLI. `AlertEvaluator` has `backup.failed` and staleness rules.

**Per your Q3 answer, engine coverage + format + streaming are Section A blockers, not part of this request.** What remains genuinely in #9:

1. **Scheduling is Spaces-only.** `RunScheduledBackup.php:36-38` returns `FAILURE` when Spaces secrets are absent — so **scheduled local backup is impossible**, even though manual local backup works. Plain bug.
2. Scheduling is coarse: one hour-of-day, daily or weekly, one-per-day guard via `isToday()`. No hourly, no multiple windows, no per-database cadence.
3. Scheduled backups run **synchronously inside the scheduler tick** with a 900s broker timeout (`runOne`), rather than dispatching to the queue — so a slow dump blocks the scheduler and cannot be monitored as a job.
4. `backup.caddy` is hardcoded to run on every scheduled pass regardless of configuration.
5. **No restore verification** — nothing ever proves a backup is restorable. For a product whose value proposition includes disaster recovery, this is the most important missing thing in #9.
6. No integrity check on stored archives (sha256 is computed at creation but never re-verified).
7. Retention is keep-N by name sort only — no GFS/tiered policy, no per-target retention.
8. No point-in-time / incremental — full dumps only.
9. No per-database backup history view (the `BackupJob` table has it; the UI does not surface it well).
10. S3-compatible only via the Spaces client — no generic S3 endpoint testing beyond it, no local-plus-remote dual write.

**UI specification:** Backups page gains: destination selector that permits **local** for scheduled jobs; per-target schedule rows (target, cadence, window, retention, destination) instead of one global config; a history table per target with size/duration/status/verify-state; a **Verify** action that restores into a scratch database and reports success; integrity re-check action; restore wizard with dry-run preview (files restore already has preview — DB restore does not).

**Implementation specification:** move scheduled runs onto the queue as jobs (reuse the `RunComponentOperationJob` shape, with the R5 fixes applied). Restore verification for MariaDB/PostgreSQL = restore into a temp database, run a row-count/`pg_restore --list` sanity pass, drop. For Mongo, `mongorestore` into a temp DB. Retention policy as structured config, not a single `keep` integer.

**Overlap with Section A — stated explicitly so no work is duplicated:** Section A delivers the *engine drivers* (pg_dump/pg_restore, mongodump/mongorestore), the *LACMP2 format*, and *streaming*. Request #9 delivers the *UX and orchestration* on top: scheduling, retention policy, verification, history. No overlap in implementation.

**Questions:** (1) Is restore **verification** in scope for this round? Tövsiyəm: **yes** — it is the single highest-value item in #9 and cheap once pg/mongo drivers exist. (2) Retention model — keep-N, age-based, or GFS (daily×7 + weekly×4 + monthly×12)? Tövsiyəm: **age-based with per-target override**; GFS is a lot of logic for a single-server product. (3) Incremental/PITR — in or out? Tövsiyəm: **out**; binlog/WAL shipping is a genuinely large subsystem and full dumps fit this product's scale.

---

## #10 — File Manager improvements (backup, copy/paste, ZIP/TAR/GZ)

**Current state: [PI].**

**What exists:** broker ops `list`, `read`, `write`, `mkdir`, `rename`, `move`, `delete` (`VhostFileOp::OPS:17`), executed as the vhost's `az-vh-*` user by a setuid-dropping helper (`Files/vhost-file-op.php`). Containment is genuinely careful: `VhostPath::lexicalJoin` rejects NUL/absolute/climbing `..`, plus `realpath()` confinement of target-or-parent, both source and destination validated on rename/move, symlinks resolving outside rejected, no symlink creation, writes size-capped (`vhost_files_max_bytes`, 20 MiB default), bodies travel as `content_base64` on stdin and are redacted from audit. UI (`VhostFilesPage`, 481 lines): browse, sort, text edit, save, mkdir, single upload, delete (incl. multi), rename, download, and download-selected-as-ZIP.

**Exact missing functionality**

1. **Copy** — absent. Not excluded by any ADR; simply not implemented. (`move` exists.)
2. **G11 — `vhost.files.move` has no UI.** Registered at `Kernel.php:222`, wired in `FakeBroker`, but `VhostFilesPage` exposes only `rename`. Backend exists, UI missing — cheapest item in your whole list.
3. **Archive extract — this is the locked v1 exclusion** (`SPEC.md:129`, zip-slip). Reopening it is a deliberate decision, not a bug fix. If reopened, it needs: per-entry path validation through the same `VhostPath` containment, rejection of absolute/`..`/symlink/hardlink/device/setuid entries, an uncompressed-size and entry-count cap (zip-bomb), and extraction as the `az-vh-*` user. **My view: worth reopening, because it is the single most-requested file-manager capability and the containment primitives you need already exist and are well-tested.** The original exclusion was about not having those primitives; you now do.
4. **Archive creation in place** (make a `.zip`/`.tar.gz` inside the docroot) — absent, and **not** covered by the zip-slip exclusion. Creation has none of extract's risks.
5. **G12 — the existing ZIP path runs inside panel PHP-FPM.** `VhostFilesController::zip()` reads each file through the broker then assembles the archive in panel memory and `sys_get_temp_dir()`. It respects the *letter* of `SPEC.md:116` ("panel FPM must not read or write vhost document roots") since it never touches the docroot directly — but vhost file contents pass through and are written by the panel process. It is also **flat-file only**: `vhost.files.read` targets files, so selecting a directory cannot work. Limits: 50 files, 50 MB total, 20 MiB per file.
6. No chmod/chown, no file search, no multi-file/folder upload, no drag-and-drop, no duplicate, no "new empty file", no bulk move.
7. Text editor only — `editorText` flag exists but there is no syntax highlighting, and binary files cannot be viewed.
8. No per-vhost trash/undo; delete is immediate (with `confirmRecursive` for directories).
9. "Backup" in your request title — see #8; a File-Manager-initiated snapshot of a subtree would be the narrow version.

**Security considerations:** every new op must go through `VhostPath` + the setuid helper — **never** through panel FPM. Archive extract is the one genuinely dangerous addition and needs its own adversarial test suite (zip-slip via `..`, via absolute path, via symlink entry, via hardlink, nested archive, zip bomb, setuid entry). Archive creation should also cap total output size to prevent filling the disk.

**UI specification:** multi-select with a clipboard model (cut/copy → paste), a Move-to dialog with a directory tree, a **Compress** action (choose zip/tar.gz, names the output in the current directory), an **Extract** action on archive files (with a preview listing + destination choice + conflict policy), chmod dialog, recursive search box, multi-file upload with per-file progress.

**Questions:** (1) **Do you want to reopen the zip-slip extract exclusion?** This is a locked-decision reversal and needs an ADR amendment. (2) Should the download-as-ZIP path move into the broker (correct, and fixes directory support) or stay panel-side? Tövsiyəm: **move to the broker.** (3) chmod — full numeric mode, or a safe preset set (644/755/600/700)? Tövsiyəm: **presets**, because `777` on a docroot is a self-inflicted wound the panel shouldn't make easy.

---

## #11 — SFTP Manager

**Current state: [NF].** No SFTP anywhere (only an unrelated Laravel `filesystems.php` driver comment). Live: sshd has **no** panel-managed config — only `50-cloud-init.conf` and `60-cloudimg-settings.conf`, and the stock `Subsystem sftp /usr/lib/openssh/sftp-server` (the **external** subsystem, not `internal-sftp`).

**What exists that makes this tractable — the foundation is genuinely good.** **A25** already provides exactly the identity model SFTP needs, verified live: 9 `az-vh-*` accounts, group `azerioid-vhosts`, **home directory = the vhost docroot**, shell `/bin/bash`, and **all accounts password-locked (`passwd -S` → `L`)**. So no one can log in today, and enabling SFTP is an explicit, deliberate act rather than an accidental exposure.

**Exact missing functionality**

1. Panel-managed sshd configuration: a `Match Group azerioid-vhosts` block with `ChrootDirectory`, `ForceCommand internal-sftp`, `AllowTcpForwarding no`, `X11Forwarding no`, `PermitTunnel no`.
2. Switch `Subsystem sftp` to `internal-sftp` (chroot requires the in-process subsystem).
3. Per-vhost SFTP enable/disable.
4. Credential model: SSH public keys and/or passwords, set/rotate/revoke.
5. **Chroot ownership constraint** — `ChrootDirectory` requires the directory and every parent to be **root-owned and not group/world-writable**. Vhost docroots under `/data/www/<domain>` are owned by `az-vh-*` so the user can write. The standard resolution is chroot to `/data/www/<domain>` with a root-owned parent and the writable content in a subdirectory — which **changes the docroot layout**, or requires a bind-mount arrangement. This is the single hardest design problem in the feature and must be decided before implementation.
6. Connection limits, fail2ban jail for SFTP auth failures, `authorized_keys` management under root control (not writable by the vhost user, or the user can grant themselves persistence).
7. Firewall interaction — port 22 is already open for admin SSH; whether SFTP gets a separate port.
8. Audit of SFTP sessions and file operations (today, File Manager ops are audited; SFTP would bypass that entirely).
9. `azerioid vhost sftp …` CLI.

**Security considerations — this is the second-highest-risk item in your list, after the firewall.** Editing `sshd_config` on a remotely-administered host can lock you out of the box permanently, and unlike the firewall there is no auto-revert-on-unreachable trick that helps if sshd refuses to start. Mandatory: (a) write to a **drop-in** under `/etc/ssh/sshd_config.d/` and never edit the main file; (b) **`sshd -t` validation before reload, always**; (c) reload (`SIGHUP`), never `restart` — existing sessions survive a reload but not a failed restart; (d) refuse any change that would affect admin SSH access; (e) the `Match` block must be strictly scoped to `azerioid-vhosts`. Also note the **shell** question: these accounts have `/bin/bash` for the Terminal feature (A25 uses `runuser` + ttyd). `ForceCommand internal-sftp` in the Match block restricts SFTP logins without removing the shell that Terminal needs — but this means the same account is both a shell identity and an SFTP identity, so a password set for SFTP also becomes a password for SSH shell access unless the Match block also denies that. This must be handled explicitly.

**UI specification:** per-vhost **SFTP** section: enable/disable toggle, auth method (key only / password / both), public-key list (add/remove), password set/rotate (write-once reveal), connection details (host, port, username, chroot path), active-session list, recent auth failures. A host-level Settings block for the sshd drop-in state with a validation indicator.

**Questions — several must be answered before any implementation:** (1) **Chroot layout** — restructure docroots so `/data/www/<domain>` is root-owned with content in `<domain>/public` (a real change affecting existing hosts and every managed-comment `root=`), or skip chroot and rely on the `az-vh-*` uid plus `internal-sftp` without `ChrootDirectory` (much simpler, but the user can then browse the filesystem read-only as that uid)? Tövsiyəm: **skip chroot for v1**, document the limitation, because the uid already bounds *writes* and the docroot restructure would be a breaking change for every existing vhost. (2) Key-only, or passwords too? Tövsiyəm: **key-only default**, passwords opt-in per vhost with fail2ban. (3) Does enabling SFTP also grant SSH shell access, or must shell be explicitly denied in the Match block? Tövsiyəm: **deny shell** — SFTP and Terminal should be separately grantable.

---

## #12 — CI/CD Manager per vhost

**⚠ This is explicitly a REOPENING of a previously-declined direction.** Per your Part 0 briefing, a full git-based CI/CD / auto-build / auto-deploy pipeline was discussed and deliberately excluded, on the grounds that it would duplicate Caddy's front-router/TLS role and introduce an entirely new domain (build pipelines, webhooks) inconsistent with the project's "extend existing infrastructure, don't add parallel systems" discipline. I am not presenting this as a newly-found gap.

**Current state: [NF].** No git integration, no webhooks, no build/deploy actions anywhere in the broker.

**My honest assessment, split into two very different propositions:**

**Proposition A — "deployment pipeline" (the thing that was declined). I still recommend against it.** Build environments, per-language build steps, artifact storage, build caching, secret injection into builds, webhook receivers with signature verification, concurrent build isolation, build log streaming, queue capacity management. This is a product in its own right. Concretely: it needs a build executor (a new process-management domain, or Docker-as-build-runner which A38's rootless model constrains), inbound webhook endpoints (a new **public** attack surface on a panel whose entire security posture is "localhost-first, SSH tunnel by default" per `SPEC.md:60`), and provider OAuth token management. The original reasoning holds, and the R1 finding makes me *more* cautious about adding public endpoints, not less.

**Proposition B — "git deploy" (materially smaller, and defensible).** `git pull` + an optional post-deploy command, in an existing vhost docroot, triggered manually or on a schedule. No webhooks, no build matrix, no artifact store. Mechanically this is: a broker action that runs `git pull` as the `az-vh-*` user in the docroot, then runs one operator-supplied command (`composer install --no-dev`, `npm ci && npm run build`, `artisan migrate --force`), then the existing runtime reload that **already exists** for each runtime (`vhost.octane.reload`, `vhost.pm2.reload`, `vhost.docker.restart`). Deploy-key management reuses the SSH-key handling that #11 needs anyway. History and status reuse #13's tables.

**Why B is consistent with the discipline where A is not:** B adds *one broker action plus state*, and reuses the per-vhost identity (A25), Supervisor-based runtimes (A35/A37/A38), the existing reload actions, and the queue. It adds no new public endpoint, no new process manager, no build system. It is the same shape as "Octane is opt-in and reuses Supervisor + Caddy."

**If you pursue B, exact scope:** repository URL + branch, deploy key generation (panel-generated, public half shown for the operator to add to the provider), `git pull` as `az-vh-*`, one post-deploy command from an allowlisted set or free-form behind a typed confirm, automatic runtime reload, deploy history with status/duration/log, manual trigger + optional schedule, and a rollback via `git checkout <previous-sha>` + re-run.

**Security considerations for B:** the post-deploy command is **arbitrary code execution as the vhost user** — which is exactly what the vhost's own PHP already is, so it does not widen the blast radius *if and only if* it runs as `az-vh-*` and never as root or `azerioid-supervised`. Deploy keys must be read-only on the provider side and root-owned on disk. `git pull` into a live docroot is **not atomic** — a partial tree is served mid-pull. Atomic deploys need a release-directory + symlink swap, which changes the docroot model (and interacts with #11's chroot question).

**Questions — all blocking, and this is the feature where the name defines least:** (1) **Is Proposition B in scope at all, or does the original decline stand?** (2) Providers — GitHub/GitLab/Bitbucket, or provider-agnostic over SSH? Tövsiyəm: **provider-agnostic SSH**, which needs no OAuth and no provider APIs. (3) Triggers — manual only, manual + schedule, or webhooks? Tövsiyəm: **manual + schedule**; webhooks are the line where B turns into A. (4) Atomic release-directory deploys, or in-place `git pull`? Tövsiyəm: **in-place for v1**, documented, because release directories change the docroot contract. (5) Rollback expectation — code-only (`git checkout`), or code+DB? Code+DB is a different and much harder promise.

---

## #13 — Deployment progress & deployment status tracking

**Current state: [PI] — the pattern exists twice and is good; it just isn't generalised.**

**What exists, and it's the right model:** two subsystems already implement queued long-running operations with persisted progress:

- **Component operations** — `component_operations` table (status, started_at, finished_at, error, log, options), `RunComponentOperationJob`, broker-side `OperationLogger` writing to a per-operation log file, `component.operation.log` action to tail it, `ComponentsPage` polling for live progress.
- **Panel updates** — `panel_update_operations` table (+ tags migration), `RunPanelUpdateJob`, `PanelUpdater` with `OperationLogger`, `panel.update.operation.log`, `UpdatesPage`.

**Exact missing functionality**

1. **Not generalised.** Every other long operation is **synchronous and opaque**: `vhost.add`, `vhost.octane.enable`, `vhost.pm2.enable`, `vhost.docker.enable`, `vhost.docker.build` (900s timeout!), `backup.*`, `backup.restore.*`, `mail.domain.enable`, `db.dump`. A Docker build can take 15 minutes behind a blocking HTTP request with no progress, no log, no cancel, and no record that it happened.
2. No unified **Operations/Activity** view — component ops live on Components, panel updates on Updates, backups on Backups, and everything else nowhere.
3. **No cancel** for any operation.
4. **R5(a) — no stuck-operation reaper.** A worker killed mid-operation leaves `status = running` forever, and because `RunComponentOperationJob:32-36` and `ComponentsPage:372-379` both gate on that, **Components is permanently wedged** until someone edits SQLite.
5. **R5(b) — the queued-behind operation is destroyed, not delayed.** `tries = 1` + `release(15)` means the second operation is failed as MaxAttemptsExceeded before `handle()` runs; its row is never updated, so the UI shows a permanently "pending" install.
6. No concurrency model beyond "one at a time, globally" — two unrelated vhosts cannot deploy simultaneously.
7. No progress *percentage* or step model — only appended log lines.
8. No retention on any of the operation tables (**G13**).
9. No notification on completion/failure (`TelegramNotifier` exists and is unused for this).

**Overlap — stated explicitly:** items 4, 5 and 8 are **R5/G13 in Section A** (they are bugs). Items 1, 2, 3, 6, 7, 9 are the actual feature request. And #12(B) and #8 both *consume* this — deployment history and backup progress are two clients of the same generalised operation model. **Build this before #12 and #8, not after.**

**UI specification:** a global **Operations** page: table of all operations (type, subject, status, started, duration, initiator), filters, live-tailing detail drawer with log, cancel button where supported, retry. A persistent header indicator when any operation is running. Per-vhost operation history on the vhost detail view. Completion/failure notifications via the existing Telegram path.

**Implementation specification:** promote the existing pattern into one `operations` table with a polymorphic subject (vhost / component / database / backup / panel), one `RunOperationJob` wrapper, and the existing `OperationLogger` unchanged. Convert the long synchronous actions to dispatch through it. Concurrency becomes a **scoped lock** (per-vhost, per-component, per-database) rather than one global lock, using Laravel's atomic cache lock rather than the current racy read-then-write. Cancel = terminate the broker child and mark cancelled (only safe for operations with a defined interruption point — builds yes, migrations no).

**Questions:** (1) Which operations become async? Tövsiyəm: **anything that can exceed ~10s** — docker build/pull, component install, backup/restore, mail domain enable, octane/pm2/docker enable. (2) Concurrency scope — per-vhost, or per-resource-type? Tövsiyəm: **per-subject**, so two vhosts deploy independently but one vhost can't deploy twice. (3) Retention for operation records and their log files?

---

## #14 — Default page should display a marketing page

**Current state: [PI] — a per-vhost welcome page exists; a host-level default does not. And the name genuinely does not define the requirement, so this needs your decision before anything is built.**

**What exists:** `Vhost/VhostWelcomePage.php` — seeds `index.php` (php) or `index.html` (static) into a **brand-new, genuinely empty** docroot at `vhost.add` only, never on edit/reload, never overwriting (double-checked via `isGenuinelyEmpty()` + a `fileExists` guard). It is a dark-themed AZERIOID-branded card reading *"This site is set up and ready."* with domain, docroot, and live PHP version.

**Three possible readings, and they are completely different pieces of work:**

**(a) Improve the per-vhost placeholder.** Smallest. Replace/extend `VhostWelcomePage::render()` with a more marketing-flavoured template, perhaps operator-customisable. Files: one class. No security implications. Note it only ever applies to *empty* docroots, so it never affects a real site.

**(b) A host-level default/catch-all site for unmatched hostnames.** **This is the one with real technical substance, and I verified the gap is live.** Per `port-ownership.md` rule 3 and **A22**, a 421 catch-all exists for the panel port `:3169` — but there is **nothing equivalent for `:80`/`:443`**. Verified on the live host with an unknown Host header:

```
:80  → HTTP 308 redirect to https://
:443 → TLS handshake FAILS: tlsv1 alert internal error (alert 80),
        "no peer certificate available"
```

So any hostname pointed at the server's IP without a matching vhost produces a **redirect into an opaque TLS failure** — not a clean "no such site here" page. This is exactly the A22 problem class, unsolved for site ports. A branded default site (`tls internal`, or a 404/421 response) fixes it and gives you the "default page" the request asks for. It also has a mild security benefit: it makes explicit which hostnames the box serves, instead of relying on there being no first-matching site.

**(c) A public marketing website for the Stack Manager product itself.** I believe this is **out of scope** — it is adjacent to the Landing Page Manager you explicitly removed, and it is a website project rather than a panel feature. I will not propose it unless you tell me otherwise.

**My recommendation: (b), optionally plus (a).** (b) fixes a verified live defect and is a genuine product-quality improvement; (a) is cosmetic polish that can ride along cheaply.

**UI specification for (b):** a Settings block — "Default site for unknown domains": mode (branded default page / plain 404 / 421 Misdirected), optional custom HTML, and a preview. Applies to `:80`/`:443` as a less-specific catch-all, exactly mirroring the `:3169` pattern in `Web/PanelCaddy.php:73-76`.

**Implementation specification for (b):** a `https://:443` + `http://:80` catch-all site block in a panel-managed Caddy snippet with `tls internal`, served from a panel-owned directory (not under `/data/www`, so it cannot be edited by any vhost identity). Critical ordering constraint, same as A22: the catch-all must remain **less specific** than every named site, and must **not** be added on a tunnel-only install in a way that opens ports that were not already open — Caddy already owns `:80`/`:443` permanently per **A9**, so this is safe here in a way the `:3169` case was not.

**Questions — blocking:** (1) **Which of (a), (b), (c) do you actually mean?** (2) If (b): branded page, plain 404, or 421? Tövsiyəm: **branded page with a neutral "this domain is not configured on this server" message** — a 404 tells an attacker less, but a human who mis-pointed DNS learns nothing from it. (3) If (a): should the placeholder be operator-customisable (a template in Settings), or a fixed panel-branded page?

---
---

# PART 4 — PROPOSED ROADMAP

**This is a proposal for discussion, not an approved development plan. No implementation begins until you explicitly authorise a specific phase.**

**P0 (the R1 security fix) is deliberately NOT a phase here** — per your instruction it sits above and before this roadmap, specified separately above, under its own prompt.

**Sections A and B are kept strictly separate. Where a feature appears in both, the overlap is named and split — never implemented twice.**

---

## SECTION A — MY OWN RECOMMENDATIONS

### Phase A1 — Guardrails and operational correctness

**Rationale:** cheapest phase, highest leverage, and it protects every phase after it. Everything here is a verified bug or a missing safety net, not a feature.

**Features:** CI (**R7**), audit-log append fix (**R6**), job-layer fixes (**R5**), retention pruning (**G13**), firewalld status parity (**G2**).

**Implementation steps**

1. **CI.** New `.github/workflows/ci.yml`: PHPUnit for `broker/tests` (51 files) and `web/tests` (22), `visudo -c -f deploy/sudoers.d/azerioid-panel`, `bash -n` over `deploy/**/*.sh`, JSON-schema validation of `registry/components/*.json` against `registry/schema.json`, `php -l` sweep. Matrix on PHP 8.4.

   **Prerequisite — `registry/schema.json` is stale and must be fixed first, or the CI check gives false confidence.** Verified: the schema declares `properties` = `id, display_name, category, description, managed, system, min_os, ports, conflicts, distros, preflight`, but **`installable` is used by all 15 non-system components** and **`install_options` is used by `nodejs.json`** — neither is declared. `additionalProperties` is **not set**, so it defaults to permissive and everything passes vacuously. Step order must therefore be: (a) add `installable` and `install_options` to the schema, (b) set `additionalProperties: false`, (c) *then* wire the CI check. Otherwise the job validates nothing.
2. **Audit append.** Replace `AuditLog::write()`'s read-concatenate-write with an append: add `appendFile()` to the `Runtime` interface (`fopen('a')` + `flock(LOCK_EX)` + single `fwrite` + `fflush`), implement in `PosixRuntime` and `FakeRuntime`. Change logrotate from `copytruncate` to `create` + a post-rotate signal, so the rotation race closes too.
3. **Job reaper.** A scheduled command marking operations `running` past a timeout as `failed` with a clear reason, so `ComponentsPage` unwedges. Add `started_at`-based staleness.
4. **Job contention.** Replace `tries = 1` + `release()` with either `tries` high enough to survive releases plus a `retryUntil()`, or an atomic cache lock with proper backoff. Assert the queued-behind operation actually runs.
5. **Retention.** Prune `audit_logs`, `component_operations`, `panel_update_operations`, `backup_jobs` on the existing `SampleMetrics`-style schedule, with configurable windows.
6. **firewalld parity.** Extend `Actions/FirewallStatus.php` to detect and report firewalld (`firewall-cmd --state`, `--list-all`) alongside ufw, matching what `DbAccessFirewall`/`MailFirewall`/`SiteHttpFirewall` already do.

**Files affected:** new `.github/workflows/ci.yml`; `broker/src/AuditLog.php`, `broker/src/Runtime.php`, `broker/src/PosixRuntime.php`, `broker/src/FakeRuntime.php`, `deploy/logrotate/azerioid-panel`; `web/app/Jobs/RunComponentOperationJob.php`, `web/app/Jobs/RunPanelUpdateJob.php`, `web/routes/console.php`, new console command; `broker/src/Actions/FirewallStatus.php`; `web/app/Livewire/ComponentsPage.php` (stale-state messaging).

**Dependencies:** none. Can start immediately, in parallel with P0.

**Security considerations:** audit-log integrity is itself a security control — the append fix closes silent record loss. No new privileged surface. CI must never receive secrets; all tests must run against `FakeBroker`/`FakeRuntime` with no host mutation.

**Acceptance criteria**

- [ ] CI runs on every push and fails on a deliberately broken sudoers file, a broken registry JSON, and a failing unit test.
- [ ] Two concurrent broker calls produce **two** audit records (regression test for the lost-write race).
- [ ] A killed worker mid-install results in a `failed` operation within the timeout, and Components is usable again without touching SQLite.
- [ ] Two queued component operations both complete; neither is silently dropped.
- [ ] On an EL host, the Security page reports firewalld state correctly.
- [ ] Retention prunes on schedule and is configurable.

**Tests required:** unit test for concurrent-append audit integrity; unit test for reaper staleness; feature test for two queued operations both completing; unit test for firewalld detection via `FakeRuntime`; CI self-test with intentionally broken inputs.

**Decisions needed from you before A1:** (i) retention windows for audit logs and operation records; (ii) operation timeout before the reaper intervenes; (iii) is GitHub Actions acceptable, or do you want CI elsewhere?

---

### Phase A2 — Backup integrity and engine coverage (your declared blocker)

**Rationale:** per your Q3 answers — LACMP2 now, pg/mongo as an **independent blocker separate from request #9**, streaming included. This is the phase that makes disaster recovery real, and it must precede #8 and #9.

**Features:** LACMP2 format (**R3a**), PostgreSQL + MongoDB backup/restore (**R3b / G1**), streaming (**R3c**), restore hardening, scheduled-local fix.

**Implementation steps**

1. **LACMP2.** New format: magic `LACMP2` + version + salt + KDF params + nonce + AES-256-GCM ciphertext + tag, with the header as AAD. KDF: Argon2id if available (`sodium_crypto_pwhash`), else PBKDF2-SHA256 with a high iteration count; parameters stored in the header for forward compatibility. **Reading `LACMP1`/`LCMP1` is retained for restore** — which is exactly what A19's wire-format lock protects. New archives are always LACMP2.
2. **Streaming.** Replace the in-memory model: `BackupRun` currently builds the full plaintext string, then a full ciphertext copy, then writes. Move to a streaming pipeline — `proc_open` the dump/tar, chunk-encrypt through GCM, stream to local file or multipart-upload to Spaces. This changes `ArchiveCrypto`'s interface from string→string to stream→stream, and `SpacesClient` needs multipart upload.
3. **Engine drivers.** Introduce a `Backup/BackupEngine` abstraction with MariaDB (`mysqldump`/`mysql`), PostgreSQL (`pg_dump -Fc`/`pg_restore`), and MongoDB (`mongodump --archive`/`mongorestore`) drivers — mirroring the existing `Database/DatabaseDriver` trio, which already has exactly this shape. Credentials via `--defaults-extra-file` / `PGPASSFILE` / stdin — **never argv** (A23 discipline).
4. **Restore hardening.** `tar -xzf` gains `--no-same-owner --no-same-permissions --no-overwrite-dir`; reject archive entries with setuid/setgid bits, device nodes, absolute paths, or `..`; drop the redundant plaintext `restore.sql` write at `BackupRestore.php:60` (the SQL is already passed on stdin — writing it to disk is an unnecessary plaintext exposure).
5. **Scheduled-local fix.** `RunScheduledBackup` must accept a local destination instead of hard-failing without Spaces secrets.
6. **Pre-restore snapshot retention.** Prune `<site>.lacmp-pre-restore-*` trees under a configurable policy.

**Files affected:** `broker/src/ArchiveCrypto.php`, `broker/src/Actions/BackupRun.php`, `broker/src/Actions/BackupRestore.php`, `broker/src/Actions/BackupList.php`, `broker/src/Actions/BackupPrune.php`, `broker/src/SpacesClient.php`, new `broker/src/Backup/*`, `broker/src/Config.php`; `web/app/Console/Commands/RunScheduledBackup.php`, `web/app/Livewire/BackupsPage.php`, `web/app/Console/Commands/Azerioid/BackupCommand.php`; `docs/DECISIONS.md` (new ADR for the format), `docs/SPEC.md`.

**Dependencies:** A1 (CI, to protect a change of this blast radius). Independent of the state model.

**Security considerations:** this phase *is* a security fix. Today's unauthenticated CBC plus a root `tar -x` is a tamper-to-root path when archives live in remote storage. AEAD + entry filtering closes it. Passphrase handling stays stdin-only. The KDF change means old and new archives have different passphrase→key derivations — the header must carry enough to disambiguate, and restore must never silently fall back to the weak derivation for a LACMP2 archive.

**Acceptance criteria**

- [ ] New backups are LACMP2; `LACMP1` and `LCMP1` archives still restore.
- [ ] A single flipped byte in a LACMP2 archive causes restore to **fail with an authentication error**, not produce corrupt output.
- [ ] PostgreSQL and MongoDB databases back up and restore end-to-end on a host with those components installed.
- [ ] A backup larger than the PHP memory limit completes (streaming proven, not assumed).
- [ ] An archive containing a setuid entry and a `../escape` entry is rejected on restore.
- [ ] Scheduled backup to a **local** destination runs without Spaces configured.
- [ ] No secrets in argv (`ps` audit during a live backup) and none in `broker-audit.log`.

**Tests required:** round-trip unit tests per format version; tamper-detection test; KDF parameter round-trip; per-engine dump/restore integration tests; malicious-archive rejection suite; a large-file streaming test; `ps`/audit secret-leak assertions.

**Decisions needed from you before A2:** (i) Argon2id vs PBKDF2 as the primary KDF (Argon2id is stronger; `sodium` availability across your five OS targets needs confirming — if any target lacks it, PBKDF2 must be the floor); (ii) `pg_dump` custom format (`-Fc`, restorable selectively) vs plain SQL (portable, greppable) — tövsiyəm **`-Fc`**; (iii) pre-restore snapshot retention policy.

---

### Phase A3 — Update safety

**Features:** pre-update panel-DB snapshot (**R4**).

**Implementation steps:** in `PanelUpdater::apply()`, copy `/var/lib/azerioid-panel/panel.sqlite` to a timestamped snapshot **before** `runMigrations()`; on the rollback path, restore that snapshot instead of re-running forward migrations against old code; record the snapshot path in the operation log; prune old snapshots. Keep the existing rollback flow otherwise intact — it is good.

**Files affected:** `broker/src/Panel/PanelUpdater.php`, `broker/src/Config.php`, `docs/DECISIONS.md` (amend A30-era updater notes).

**Dependencies:** A1.

**Security considerations:** the snapshot contains the full panel database — encrypted secrets, session data, audit records. It must be `0600` root-owned under `/var/lib/azerioid-panel`, never under a web-served path, and covered by retention.

**Acceptance criteria**

- [ ] An update whose migration succeeds but whose later step fails rolls back to **both** the previous code and the previous schema.
- [ ] `migrate:status` after a rolled-back update matches the pre-update state.
- [ ] Snapshot is `0600`, root-owned, pruned on policy.
- [ ] A successful update leaves no stale snapshot beyond the retention window.

**Tests required:** simulated mid-update failure after a successful migration, asserting schema rollback; snapshot permission assertion.

**Decisions needed:** how many snapshots to retain.

---

### Phase A4 — Per-vhost state foundation (gates most of Section B)

**Rationale:** your Q2 answer — **Variant C**, tables in `panel.sqlite`, config files remain authoritative for serving, **reconcile only on change**, plus a **`reconcile now`** command. This phase is the gate for B-phases 2 through 6.

**Implementation steps**

1. **Schema.** `vhosts` (domain, engine, type, php_version, docroot, runtime, runtime config, tls_mode, identity, timestamps) plus typed children as later phases need them: `vhost_secrets` (encrypted; Docker env, registry refs), `vhost_cron_jobs`, `vhost_databases` (the missing association #8 needs), `operations` (generalising the two existing operation tables for #13).
2. **Reconciliation.** A `VhostReconciler` that parses the web-server config via the existing `CaddyParser`/`ApacheParser`/`NginxParser` and upserts the projection. Per your decision, invoked **on change only** — after `vhost.add|edit|del`, runtime enable/disable, and panel update.
3. **`reconcile now`.** Explicit full-audit command: `azerioid vhost reconcile [--dry-run]` plus a Settings button, reporting drift (config-only vhosts, DB-only orphans, mismatched fields) and repairing on request.
4. **Drift visibility.** Because reconcile is change-triggered, out-of-band edits (an operator hand-editing `/etc/caddy/conf.d/*.conf`) create drift. Surface a drift indicator whenever a config file's mtime is newer than its projection row, so drift is *visible* without a full scan on every page load.
5. **Read-path migration.** Pages that currently call `vhost.list` and re-parse on every render read the projection instead, keeping `vhost.list` as the authority for serving-critical decisions.

**Files affected:** new migrations; new `web/app/Models/Vhost.php` (+ children); new `web/app/Services/VhostReconciler.php`; `web/app/Livewire/VhostsPage.php`; `web/app/Console/Commands/Azerioid/VhostCommand.php`; broker `Vhost/VhostRegistration.php` and the three runtime managers (to emit reconcile triggers).

**Dependencies:** A1. Should follow P0 (identity changes touch file ownership).

**Security considerations:** `vhost_secrets` is the panel's first per-vhost secret store — it must use Laravel's encrypter with `APP_KEY`, never be logged, be added to `AuditLog::REDACT_KEYS`, and be included in the A3 snapshot/backup story. The projection must never become a *decision* input for privileged broker operations — the broker must keep validating against the real config, or a poisoned projection row becomes a privilege path.

**Acceptance criteria**

- [ ] Creating, editing, deleting a vhost keeps the projection in sync with zero manual steps.
- [ ] A hand-edited config file is detected as drift and shown in the UI.
- [ ] `azerioid vhost reconcile --dry-run` reports drift without changing anything; without `--dry-run` it repairs.
- [ ] Deleting `panel.sqlite` and reinstalling, then running `reconcile now`, reconstructs the full vhost projection from config files alone.
- [ ] Broker privileged actions still validate against config, not the projection (asserted by test).
- [ ] Secrets are encrypted at rest and absent from `broker-audit.log`.

**Tests required:** reconcile idempotence; drift detection; full rebuild-from-config test; a test asserting a poisoned projection row cannot influence a broker decision; secret redaction test.

**Decisions needed from you before A4:** (i) should the drift indicator use file mtime (cheap, false positives on touch) or a content hash (accurate, one read per vhost per check)? tövsiyəm **content hash, computed on the change-triggered reconcile and compared lazily**; (ii) does `reconcile now` auto-repair or always require confirmation? tövsiyəm **dry-run by default, repair on confirm**.

---

## SECTION B — YOUR 14 REQUESTED FEATURES

Ordered by dependency, not by your numbering. Every phase names which of your numbered requests it delivers.

### Phase B1 — Quick wins, no new foundations

**Your requests:** **#14** (default page, reading (b) + optionally (a)), **#5** (SSL renewal refinements), **#10 partial** (expose `vhost.files.move` — **G11**).

**Why grouped:** all three are small, independent of A4, and fix verified live defects.

**Implementation steps:** (1) `:80`/`:443` branded catch-all snippet mirroring `PanelCaddy`'s `:3169` 421 pattern, served from a panel-owned directory, with a Settings mode selector. (2) New `tls.missing` / `tls.issuance_failed` alert rule — remove the `$days === null → continue` blind spot at `AlertEvaluator.php:171-174`, wire `AcmeStatusHint` into the message, add a `certbot.timer`-inactive-with-DNS-01-certs check, add a rate-limited force-renew action and renewal history. (3) Wire the existing `vhost.files.move` action into `VhostFilesPage` with a destination picker.

**Files affected:** `broker/src/Web/PanelCaddy.php` or a new `Web/DefaultSite.php`, `broker/src/Vhost/VhostWelcomePage.php` (if (a)); `web/app/Services/Alerts/AlertEvaluator.php`, `broker/src/Actions/TlsRenew.php`, `broker/src/Tls/Certbot.php`, `web/app/Livewire/AlertsPage.php`, `web/app/Livewire/VhostsPage.php`; `web/app/Livewire/VhostFilesPage.php`, `web/resources/views/livewire/vhost-files.blade.php`; `docs/port-ownership.md`.

**Dependencies:** A1 for CI coverage. Nothing else.

**Security considerations:** the catch-all must stay strictly less specific than named sites (the A22 lesson) and must not open any port Caddy does not already own — safe here, since A9 gives Caddy `:80`/`:443` permanently. Force-renew must be rate-limited to protect ACME quotas. The default-site directory must not be writable by any vhost identity.

**Acceptance criteria**

- [ ] An unknown Host on `:80`/`:443` returns the configured default (page / 404 / 421) — **no TLS handshake failure**, verified by reproducing today's failing `openssl s_client` case.
- [ ] Every named vhost still serves correctly (catch-all specificity proven, not assumed).
- [ ] The four live vhosts currently holding `tls=auto` with no certificate raise an alert after the grace period.
- [ ] Force-renew works and is rate-limited.
- [ ] File move works from the UI, with containment tests still passing.

**Tests required:** Caddy config specificity test; live unmatched-host probe on all five OS targets; alert-rule unit test for the missing-cert case; move-operation containment tests (reuse the existing `VhostPath` suite).

**Decisions needed:** **#14 — which reading, (a)/(b)/(c)?** Default-site mode? TLS grace period length?

---

### Phase B2 — Firewall & Cron managers

**Your requests:** **#2** (Firewall Manager), **#4** (Cronjob Manager).

**Overlap declared:** **#2** overlaps Section A1's firewalld-parity bug fix (**G2**). A1 fixes *reporting*; B2 adds *management*. No duplication.

**Implementation steps:** new `Network/FirewallManager` with ufw + firewalld drivers, following the existing `WebServerDriver`/`DatabaseDriver` pattern; refactor `SiteHttpFirewall`/`DbAccessFirewall`/`MailFirewall` onto it for uniform tagging; new rule/policy/jail actions; apply-then-verify with timed auto-revert; hard-coded protection of 80/443, SSH, and the panel port. For cron: structured jobs in the A4 tables, per-vhost `crontab -u az-vh-*` as the default run-as, root cron behind a louder confirm, broker renders crontabs from state (eliminating the whole-file lost-update hazard), output+exit-code capture to `/var/log/azerioid-panel/cron/` with logrotate, run-now, enable/disable, failure alerting via the existing `AlertEvaluator`/Telegram path, panel-owned entries structurally protected.

**Files affected:** new `broker/src/Network/FirewallManager.php` + drivers, `broker/src/Actions/FirewallStatus.php`, new firewall actions, `broker/src/Network/SiteHttpFirewall.php`, `broker/src/Database/DbAccessFirewall.php`, `broker/src/Mail/MailFirewall.php`; `broker/src/Actions/CronManage.php`, new `broker/src/Cron/*`, `broker/src/Validator.php`; `web/app/Livewire/SecurityPage.php`, new `FirewallPage`/`CronPage`, new CLI commands, `deploy/logrotate/azerioid-panel`.

**Dependencies:** A1 (firewalld parity), A4 (cron job state). P0 should land first — until it does, `cron.set` is reachable from site PHP.

**Security considerations:** the two highest-risk features after SFTP. Firewall = lockout weapon; cron = arbitrary root execution. Moving site cron from root to `az-vh-*` is a **privilege reduction** and is the main security win of this phase. Firewall guards are non-negotiable and must live in the broker, not the UI.

**Acceptance criteria**

- [ ] Firewall rules add/delete/list on both ufw and firewalld across all five OS targets.
- [ ] A rule that would deny SSH, the panel port, or 80/443 is **refused by the broker**, not merely hidden in the UI.
- [ ] A change that loses reachability auto-reverts within the timeout.
- [ ] Panel-owned rules are visibly distinguished and not hand-editable.
- [ ] Cron jobs run as the vhost identity by default; root cron requires the louder confirm.
- [ ] Exit codes and output are captured; a failing job raises an alert.
- [ ] Concurrent cron edits from UI and CLI do not lose each other (the whole-file hazard is gone).
- [ ] Pre-existing unmanaged root crontab lines are preserved untouched.

**Tests required:** per-backend firewall driver unit tests; refusal tests for every protected port; auto-revert test; cron rendering idempotence; concurrent-edit test; `Validator::cronLine` fuzzing; unmanaged-line preservation test.

**Decisions needed:** firewall rules additive vs declarative (tövsiyəm **additive**); auto-revert timeout vs confirm-click; import or preserve-as-unmanaged for existing crontab lines (tövsiyəm **preserve**); cron output retention.

---

### Phase B3 — File Manager & SFTP

**Your requests:** **#10** (File Manager), **#11** (SFTP Manager).

**Implementation steps:** copy op; compress-in-place (zip/tar.gz); chmod with presets; recursive search; multi-file upload; move the download-as-ZIP path from panel PHP into the broker so directories work (**G12**); **extract only if you reopen the zip-slip exclusion** — and if so, behind a dedicated adversarial test suite. SFTP: panel-managed `sshd_config.d` drop-in with `Match Group azerioid-vhosts`, `internal-sftp`, `ForceCommand`, forwarding disabled; `sshd -t` validate before every reload; reload not restart; per-vhost enable/disable; root-owned `authorized_keys`; fail2ban jail; session/operation audit.

**Files affected:** `broker/src/Files/VhostFileOp.php`, `VhostFiles.php`, `VhostPath.php`, `vhost-file-op.php`, `broker/src/Actions/VhostFilesAction.php`, `broker/src/Kernel.php`; `web/app/Livewire/VhostFilesPage.php`, `web/app/Http/Controllers/VhostFilesController.php`; new `broker/src/Sftp/*`, new sshd drop-in template under `deploy/`, `broker/src/Vhost/VhostUser.php`, `deploy/fail2ban/`; `docs/SPEC.md` (§Per-vhost Terminal and File Manager), `docs/DECISIONS.md` (zip-slip amendment if reopened, plus an SFTP ADR).

**Dependencies:** P0 (identity), A1. SFTP needs the A25 identity model — already present.

**Security considerations:** the sshd edit is the single most lockout-prone change in the entire roadmap. Drop-in only, `sshd -t` always, reload never restart, refuse anything touching admin SSH. Archive extract is the other sharp edge and needs its own adversarial suite — zip-slip via `..`, absolute paths, symlink and hardlink entries, nested archives, zip bombs, setuid entries. Note the shell/SFTP interaction: `az-vh-*` accounts have `/bin/bash` for Terminal, so setting an SFTP password also creates an SSH shell credential unless the Match block explicitly denies it.

**Acceptance criteria**

- [ ] Copy, compress, chmod, search, multi-upload all work and remain inside the vhost root.
- [ ] ZIP download includes directories and no longer assembles inside panel FPM.
- [ ] If extract ships: every adversarial archive case is rejected, proven by test.
- [ ] SFTP login works for an enabled vhost, is confined to that vhost's tree, and **cannot** obtain a shell.
- [ ] A deliberately invalid sshd drop-in is rejected by `sshd -t` and never applied.
- [ ] Admin SSH access survives every SFTP operation, verified on all five OS targets.
- [ ] fail2ban jails SFTP auth failures.

**Tests required:** the adversarial archive suite; containment tests for every new op; sshd config validation test; a lockout-safety test asserting admin SSH survives; shell-denial test.

**Decisions needed:** **reopen zip-slip extract — yes/no?** Chroot layout vs no-chroot (tövsiyəm **no-chroot for v1**); key-only vs passwords (tövsiyəm **key-only default**); does SFTP grant shell (tövsiyəm **no**); chmod presets vs numeric.

---

### Phase B4 — Docker & Node runtime depth

**Your requests:** **#3** (Docker improvements), **#6** (registry credentials), **#7** (per-vhost Node version).

**Overlap declared:** **#6** is a precondition for private images in **#3** — build #6 first within this phase.

**Implementation steps:** fix **G8** (compose service selection — parse panel-side with a real YAML parser, pass the validated service name to the broker; the broker has no Composer autoloader, so do not vendor a parser into it); fix **G7** (env editor backed by `vhost_secrets`, persistence via docroot bind mount, explicit restart policy); registry credentials as named encrypted entries injected **per operation** via `--password-stdin` and never persisted into the shared `azerioid-supervised` `config.json`; per-vhost Node via separate `nodejs-20|22|24` registry components at versioned prefixes, `node=` in the managed comment, per-version PM2.

**Files affected:** `broker/src/Vhost/DockerManager.php`, `broker/src/Actions/VhostDocker.php`, `broker/src/Component/DockerRootlessSetup.php`, `registry/components/docker.json`; new `registry/components/nodejs-{20,22,24}.json`, `broker/src/Component/ComponentRepoInstaller.php`, `broker/src/Vhost/Pm2Manager.php`, `broker/src/CaddyParser.php`, `broker/src/Validator.php`; `web/app/Livewire/VhostsPage.php`, `ComponentsPage.php`; `docs/DECISIONS.md` (amend A38 for env/volumes/registry, amend A16/A37 for multi-version Node), `docs/port-ownership.md`.

**Dependencies:** A4 (secrets + per-vhost state). P0.

**Security considerations:** **A38's isolation reasoning must not be quietly widened.** All Docker-runtime vhosts share one rootless daemon as `azerioid-supervised` (per-`az-vh-*` daemons explicitly deferred). `~/.docker/config.json` stores credentials base64-encoded, not encrypted — so persisting them there extends A38's accepted blast radius from "other vhosts' container data" to "third-party registry credentials," which is materially worse. Per-operation injection avoids this. Container env values are secrets: encrypted at rest, redacted from audit, never in the managed comment. Node version changes must not silently rebuild an operator's `node_modules`.

**Acceptance criteria**

- [ ] A compose file with `db:` listed before `web:` publishes the port on the **operator-selected** service.
- [ ] Container env vars reach the container, are encrypted at rest, and are absent from `broker-audit.log` and from `ps`.
- [ ] A Docker-runtime vhost's data survives restart and rebuild.
- [ ] A private image pulls successfully, and no credential is left in `azerioid-supervised`'s `config.json` afterwards (asserted by test).
- [ ] Two vhosts run different Node majors simultaneously, each with its own PM2.
- [ ] Changing one vhost's Node version does not affect another's.
- [ ] A38's adversarial isolation tests still pass unchanged.

**Tests required:** compose service-selection unit tests including adversarial YAML; secret redaction and `ps` leak tests; credential-non-persistence test; multi-version Node coexistence test on all five OS targets; re-run of A38's existing adversarial suite.

**Decisions needed:** volumes — named volume vs docroot bind mount (tövsiyəm **bind mount**); keep `--rm` (tövsiyəm **yes, with volumes**); credential scope — named global referenced per vhost (tövsiyəm **yes**); registries beyond Hub (ECR needs AWS token refresh — recommend excluding); Node approach **A vs B** (tövsiyəm **A**); how many Node majors co-installed.

---

### Phase B5 — Operations tracking

**Your request:** **#13** (deployment progress & status tracking).

**Overlap declared:** items 4, 5 and 8 of my #13 analysis (stuck reaper, `tries`/`release` bug, retention) are **already delivered in A1** as bug fixes. B5 delivers the *generalisation*: unified operations model, global Operations page, cancel, scoped concurrency, step/progress model, completion notifications.

**Why before B6 and B7:** **#8** (vhost backup) and **#12** (git deploy) are both *clients* of this model. Building it first prevents each of them inventing its own progress tracking.

**Implementation steps:** one `operations` table with a polymorphic subject, superseding `component_operations` and `panel_update_operations` (with a migration that preserves history); one `RunOperationJob` wrapper reusing the existing broker-side `OperationLogger` unchanged; convert long synchronous actions (`vhost.docker.build`, `vhost.*.enable`, `backup.*`, `mail.domain.enable`, `component.install`) to dispatch through it; replace the global one-at-a-time lock with **per-subject atomic cache locks**; cancel via broker child termination for operations with a safe interruption point; Telegram notification on completion/failure.

**Files affected:** new migration + `web/app/Models/Operation.php`; new `web/app/Jobs/RunOperationJob.php` replacing the two existing jobs; `web/app/Livewire/ComponentsPage.php`, `UpdatesPage.php`, `VhostsPage.php`, `BackupsPage.php`, new `OperationsPage`; `broker/src/Component/OperationLogger.php` (unchanged if possible), the actions being converted; `web/app/Services/Alerts/TelegramNotifier.php`.

**Dependencies:** A1 (the bug fixes it builds on), A4 (state model).

**Security considerations:** operation logs can contain command output that includes secrets — `OperationLogger` must redact using the same key list as `AuditLog`. Cancel must not leave a half-applied privileged change; only operations with a defined rollback or interruption point may be cancellable (builds yes, migrations **no**). The Operations page exposes historical command output and must stay behind panel auth + 2FA.

**Acceptance criteria**

- [ ] A Docker build runs async with live log streaming and a cancel button.
- [ ] Two different vhosts run operations concurrently; the same vhost cannot.
- [ ] Existing component and panel-update history is preserved through the migration.
- [ ] Cancelling a build leaves the vhost in its prior serving state.
- [ ] Migrations are not cancellable (asserted).
- [ ] Operation logs contain no secrets.
- [ ] Completion and failure notifications fire.

**Tests required:** concurrency-scope tests; history-preserving migration test; cancel-safety test per operation type; log redaction test.

**Decisions needed:** which operations become async (tövsiyəm **anything over ~10s**); concurrency scope (tövsiyəm **per-subject**); operation + log retention.

---

### Phase B6 — Backup depth

**Your requests:** **#8** (advanced vhost backup), **#9** (DB backup/restore improvements).

**Overlap declared explicitly:** Section **A2** delivered the *engines* (pg/mongo), the *format* (LACMP2), *streaming*, and *restore hardening*. B6 delivers the *orchestration and UX* on top: vhost bundles, per-target scheduling, retention policy, restore **verification**, history. **No implementation overlap.**

**Implementation steps:** vhost↔database association (A4 table) so a bundle knows what to include; bundle manifest + component archives (not a monolith) covering docroot, config fragment, TLS material, runtime config, Supervisor programs, DB dumps, mail state, cron entries, versions, identity metadata; per-target schedules with structured retention; restore verification (restore into a scratch DB, sanity-check, drop); archive integrity re-check; bundle-level dry-run preview; partial restore; pre-restore snapshot pruning.

**Files affected:** new `broker/src/Backup/VhostBundle.php` and manifest handling, `broker/src/Actions/Backup*.php`, `broker/src/Config.php`; `web/app/Livewire/BackupsPage.php`, `web/app/Console/Commands/RunScheduledBackup.php`, `Azerioid/BackupCommand.php`, new migrations for schedules/targets.

**Dependencies:** **A2** (mandatory — bundles carry DKIM and TLS private keys and DB dumps, so AEAD must exist first), **A4** (associations), **B5** (progress for long restores).

**Security considerations:** a vhost bundle is the most sensitive artefact the product produces — DB dumps, DKIM private keys, TLS private keys, container env secrets. This is exactly why A2 precedes it. Restore must apply A2's hardened `tar` flags and entry filtering. Restore-to-different-domain (if in scope) must never reuse another vhost's identity or collide on runtime ports.

**Acceptance criteria**

- [ ] A vhost bundle captures and restores files, config, TLS, runtime, DBs, cron and mail for a representative PHP vhost, an Octane vhost, a PM2 vhost and a Docker vhost.
- [ ] Partial restore (files only / DB only) works.
- [ ] Restore verification proves a backup restorable without touching the live database.
- [ ] Per-target schedules and retention are honoured, including local destinations.
- [ ] Bundles are LACMP2 and tamper-evident.
- [ ] Pre-restore snapshots are pruned.

**Tests required:** per-runtime bundle round-trip; partial restore; verification correctness (including a deliberately corrupt archive); retention policy tests; secret-handling tests.

**Decisions needed:** self-contained bundle vs manifest+parts (tövsiyəm **manifest+parts**); restore-to-different-domain in or out; retention model (tövsiyəm **age-based with per-target override**); verification in scope (tövsiyəm **yes**); incremental/PITR (tövsiyəm **out**).

---

### Phase B7 — Elasticsearch (SSPL)

**Your request:** **#1**.

**Implementation steps:** `registry/components/elasticsearch.json`; GPG-pinned repo branch in `ComponentRepoInstaller` (A10 pattern); **ES-specific hard RAM preflight** as a documented exception to **A31** (you approved a new ADR: physical RAM only, swap **not** counted, hard block); JVM heap sizing written to `jvm.options`; `single-node` discovery; loopback bind; security plugin with a generated admin password stored as a panel secret; `search.status|indices|password.reset` actions; Components card + `Search` page + `azerioid search` CLI; port row in `docs/port-ownership.md`; `conflicts` against the other engine.

**Files affected:** new `registry/components/elasticsearch.json`; `broker/src/Component/ComponentRepoInstaller.php`, `ComponentPreflight.php`, new `broker/src/Search/*`, new actions, `broker/src/Kernel.php`; `web/app/Livewire/ComponentsPage.php`, new `SearchPage`, new CLI command; `docs/DECISIONS.md` (new ADR: component + A31 exception), `docs/port-ownership.md`.

**Dependencies:** A1. Independent of A4 — it is host-wide, not per-vhost, per your Q4 answer. Can run in parallel with other B phases.

**Security considerations:** loopback-only bind is necessary but **not sufficient** — every vhost on the host can reach `127.0.0.1:9200`, which is precisely the lesson of R1. Hence my recommendation to enable the security plugin with authentication rather than trusting loopback. The admin password must be a panel secret, never argv, reveal-once. Heap misconfiguration is an availability risk: on a 961 MB host like your test box, a careless heap setting will OOM-kill either the search engine or the panel.

**Acceptance criteria**

- [ ] Installs cleanly on Ubuntu 24.04, Debian 12, Alma 9, Rocky 9, CentOS Stream 9 (SELinux Enforcing).
- [ ] Install is **hard-blocked** on a host below the physical-RAM threshold, with swap explicitly not counted, and the message states what was measured (the A31 wording rule).
- [ ] Binds `127.0.0.1:9200` only; authentication is required.
- [ ] Heap is sized from physical RAM and recorded.
- [ ] Health, index list and password reset work in UI and CLI.
- [ ] Uninstall removes packages and units; data directory retained unless explicitly dropped (**A29** spirit).
- [ ] No secrets in argv or `broker-audit.log`.

**Tests required:** preflight hard-block unit test (including the swap-not-counted case); registry schema validation; per-OS install smoke; bind-address assertion; secret-handling assertions.

**Decisions needed:** ~~license/engine~~ **decided: Elasticsearch (SSPL)**; hard RAM minimum (2 GB / 4 GB); security plugin on/off (tövsiyəm **on**); heap default formula.

---

### Phase B8 — Git deploy (REOPENED DECISION — requires explicit authorisation)

**Your request:** **#12**.

**⚠ Do not treat this phase as proposed-and-accepted.** It reopens a direction you previously declined. I recommend **Proposition B (git deploy) only**, and recommend **against Proposition A (full CI/CD with build pipelines and webhooks)** — the original reasoning holds, and R1 makes me more reluctant to add public inbound endpoints to a localhost-first panel, not less.

**Implementation steps (B only):** repo URL + branch + panel-generated deploy key (public half shown for the operator to register); `git pull` executed as the vhost's `az-vh-*` user in the docroot; one post-deploy command (allowlisted presets, or free-form behind a typed confirm); automatic runtime reload via the **existing** `vhost.octane.reload` / `vhost.pm2.reload` / `vhost.docker.restart`; deploy history + status via **B5**; manual trigger plus optional schedule via **B2**'s cron; rollback via `git checkout <previous-sha>` + re-run.

**Files affected:** new `broker/src/Deploy/*` + actions, `broker/src/Kernel.php`; `web/app/Livewire/VhostsPage.php` (deploy section), new CLI command; `docs/DECISIONS.md` (a new ADR recording the reopening and the narrowed scope).

**Dependencies:** **B5** (operations/progress — mandatory), **B2** (scheduling), **B3** (SSH key handling), **A4** (state). This is the last phase for good reason.

**Security considerations:** the post-deploy command is arbitrary code execution as the vhost user — acceptable **only** because the vhost's own PHP already runs as that user, i.e. no widening. It must **never** run as root or as `azerioid-supervised`. Deploy keys: read-only at the provider, root-owned on disk, not readable by the vhost user. `git pull` into a live docroot is **not atomic** — a partial tree is served mid-pull; atomic release-directory deploys would fix it but change the docroot contract and collide with B3's chroot question. No webhook endpoint in this scope.

**Acceptance criteria**

- [ ] Deploy pulls and runs the post-deploy command as `az-vh-*`, never as root (asserted by test).
- [ ] The runtime reloads automatically for Octane, PM2 and Docker vhosts.
- [ ] Deploy history with status, duration and log is visible.
- [ ] Rollback restores the previous commit and re-runs.
- [ ] Deploy keys are root-owned and unreadable by the vhost user.
- [ ] No inbound webhook endpoint is created.

**Tests required:** privilege assertion tests; reload integration per runtime; rollback test; key permission test.

**Decisions needed — all blocking:** **is Proposition B in scope at all, or does the decline stand?** Provider-agnostic SSH vs provider APIs (tövsiyəm **SSH**); triggers manual+schedule vs webhooks (tövsiyəm **no webhooks**); in-place vs atomic release dirs (tövsiyəm **in-place for v1**); rollback code-only vs code+DB (tövsiyəm **code-only**).

---

## Dependency summary

```
P0 (urgent, separate)
   └─> A4, B2, B3, B4

A1 ──> everything (CI protects all later work)
A2 ──> B6
A3   (independent)
A4 ──> B2(cron), B4, B5, B6, B8

B1   (independent)
B5 ──> B6, B8
B2 ──> B8 (scheduling)
B3 ──> B8 (SSH keys)
B7   (independent — parallelisable)
```

**Recommended order:** P0 → A1 → A2 → A4 (A3 anywhere early) → B1 → B5 → B2 → B3 → B4 → B6 → B7 (parallel) → B8 (only if authorised).

## Features appearing in both sections — overlap resolution

| Your request | Section A does | Section B does |
|---|---|---|
| **#9** DB backup | A2: pg/mongo engines, LACMP2, streaming, restore hardening | B6: scheduling, retention policy, verification, history UX |
| **#2** Firewall | A1: firewalld **reporting** parity (bug) | B2: rule **management** |
| **#13** Deployment tracking | A1: stuck reaper, `tries`/`release` bug, retention (bugs) | B5: unified model, Operations page, cancel, scoped concurrency |
| **#8** Vhost backup | A2 + A4: format/streaming + vhost↔DB association | B6: bundle manifest, partial restore |
| **#10** File Manager | — | B1: expose `move`; B3: copy/compress/chmod/search/extract |


---
---

# PART 5 — QƏRAR TƏLƏB EDƏN SUALLAR

**Format:** hər sualın altında `**CAVAB:**` sətri var — birbaşa bu faylda yazın, mən oxuyub plana tətbiq edəcəyəm.

**Vacib:** sualları qəsdən **qruplara böldüm**. Hamısını birdən cavablamağa ehtiyac yoxdur — ümumilikdə 47 qərar var, lakin **indi yalnız Qrup 1 və Qrup 2 lazımdır** (6 sual). Qalanları müvafiq faza başlayanda soruşacağam; aşağıda yalnız *nəyin gəldiyini bilməniz üçün* indeks kimi sadaladım. Onları indi cavablamayın.

---

## QRUP 1 — BLOKLAYICI (indi cavab lazımdır)

Bu üçü cavablanmadan müvafiq fazaların spesifikasiyası yazıla bilməz.

### 1.1 — #14: "default page" hansı mənada?

**Nə üçün əhəmiyyətlidir:** üç tam fərqli iş həcmi var — biri bir sinif faylı, biri Caddy snippet + Settings, biri ayrıca veb-sayt layihəsi. Səhv oxunuş bütün B1 fazasını yanlış istiqamətə aparır.

| Variant | İş həcmi | Nə verir |
|---|---|---|
| **(a)** Mövcud per-vhost placeholder-i yeniləmək | Kiçik — `broker/src/Vhost/VhostWelcomePage.php` | Yeni boş vhost yaradılanda görünən səhifə daha yaxşı görünür. Yalnız **boş** docroot-a təsir edir, real saytı heç vaxt üzmür |
| **(b)** `:80`/`:443` üçün host səviyyəli catch-all | Orta — yeni Caddy snippet + Settings bloku | **Canlı hostda təsdiqlədiyim real nasazlığı düzəldir:** tanınmayan Host → `308` → `tlsv1 alert internal error` (alert 80, "no peer certificate available"). Yəni DNS-i bu serverə yönəldən, lakin vhost-u olmayan hər kəs anlaşılmaz TLS xətası alır. Bu, **A22**-nin `:3169` üçün həll etdiyi problemin sayt portlarında həll olunmamış variantıdır |
| **(c)** Stack Manager məhsulunun öz marketinq saytı | Böyük — ayrıca layihə | Məhsul tanıtımı |

**Mənim tövsiyəm: (b), üstəgəl ucuz olduğu üçün (a).**
Səbəb: (b) *təsdiqlənmiş* nasazlığı düzəldir və həm də təhlükəsizlik faydası var — hansı hostname-lərin bu boxda xidmət olunduğu açıq olur, "ilk uyğun sayt hər şeyə cavab verir" sürprizinə yer qalmır. (a) isə eyni fazada praktiki olaraq pulsuz gəlir.
**(c) barədə açıq fikrim:** bunu **scope-dan kənar** hesab edirəm — sizin sildiyiniz Landing Page Manager-ə qonşudur və panel feature-i deyil, veb-sayt layihəsidir. Əgər (c) istəyirsinizsə, bunu ayrıca müzakirə mövzusu kimi açmaq lazımdır, bu roadmap-ın içində yox.

**CAVAB:**

---

### 1.2 — #10: zip-slip extract istisnası yenidən açılsın?

**Nə üçün əhəmiyyətlidir:** `SPEC.md:129` arxiv extract-i v1-dən **qəsdən** çıxarıb (zip-slip riski). Bu, kilidlənmiş qərardır — açmaq ADR düzəlişi tələb edir, sadəcə feature əlavə etmək deyil.

**Mənim tövsiyəm: açmağa dəyər.** Səbəb: orijinal istisna *containment primitivləri olmadığı üçün* qoyulmuşdu. Artıq onlar var və sınaqlanmışdır — `VhostPath::lexicalJoin` (NUL/absolute/climbing `..` rədd edir), target-or-parent `realpath()` confinement, rename/move-da **hər iki** tərəfin validasiyası, symlink-i kənara çıxan hallarda rədd, symlink yaratmama, setuid-drop helper. Extract üçün lazım olan müdafiə bazası hazırdır.

**Şərtim var:** əgər açılırsa, öz adversarial test dəsti ilə açılmalıdır — `..` ilə escape, absolute path entry, symlink entry, **hardlink entry**, nested arxiv, zip bomb (uncompressed size + entry count cap), setuid/setgid entry, device node. Bunlar olmadan açılmamalıdır.

**Qeyd:** arxiv **yaratmaq** (in-place compress) istisnanın əhatəsində **deyil** — onda extract-in heç bir riski yoxdur. Onu hər halda edə bilərik, bu suala baxmayaraq.

**CAVAB:**

---

### 1.3 — #12: Proposition B (git deploy) scope-a daxildir, yoxsa əvvəlki rədd qərarı qalır?

**Nə üçün əhəmiyyətlidir:** bu, əvvəl **qəsdən rədd edilmiş** istiqamətin yenidən açılmasıdır. Mən onu iki tam fərqli təkliyə ayırdım:

**Proposition A — tam CI/CD (build pipeline, webhook, artifact store). TÖVSİYƏ ETMİRƏM.**
Səbəb: orijinal arqument qüvvədədir (build pipeline-lar ayrı domendir, "mövcud infrastrukturu genişlət, paralel sistem qurma" intizamına ziddir). Üstəlik **R1 məni daha ehtiyatlı edir, daha az yox**: webhook receiver — bütün təhlükəsizlik duruşu "localhost-first, default SSH tunnel" (`SPEC.md:60`) olan panelə **yeni public inbound attack surface** əlavə etmək deməkdir.

**Proposition B — git deploy. Müdafiə oluna bilər.**
`git pull` + bir post-deploy əmri, mövcud vhost docroot-unda, əl ilə və ya cədvəllə tetiklənir. Webhook yox, build matrix yox, artifact store yox. Mexaniki olaraq: `az-vh-*` kimi `git pull`, sonra operatorun verdiyi bir əmr, sonra **artıq mövcud olan** reload (`vhost.octane.reload` / `vhost.pm2.reload` / `vhost.docker.restart`).

**Niyə B intizama uyğundur, A yox:** B *bir broker action + state* əlavə edir və hər şeyi yenidən istifadə edir — per-vhost identity (**A25**), Supervisor-əsaslı runtime-lar (**A35/A37/A38**), mövcud reload action-ları, queue. Yeni public endpoint yox, yeni process manager yox, build sistemi yox. Bu, "Octane opt-in-dir və Supervisor + Caddy-ni yenidən istifadə edir" ilə **eyni formadır**.

**Bilməli olduğunuz bir texniki həqiqət:** `git pull` canlı docroot-a **atomik deyil** — pull ortasında yarımçıq ağac xidmət olunur. Atomik deploy üçün release-directory + symlink swap lazımdır, bu isə docroot modelini dəyişir və #11-in chroot sualı ilə toqquşur. Mən v1 üçün **in-place** tövsiyə edirəm, sənədləşdirilmiş şəkildə.

**CAVAB (B daxildir / yalnız A rədd / hər ikisi rədd):**

---

## QRUP 2 — P0 (təcili təhlükəsizlik fix-i) üçün lazım olan 3 qərar

Siz P0 üçün ayrıca prompt yazacağınızı dediniz. Həmin prompt-u yazmazdan əvvəl bu üçü qərarlaşdırılmalıdır, çünki hər biri miqrasiya davranışını dəyişir.

### 2.1 — Panel hesabının adı

| Variant | Tradeoff |
|---|---|
| **Yeni `azerioid-panel` sistem hesabı** | Ən təmiz ayırma — nə `www-data` (sayt PHP), nə `caddy` (web server) ilə paylaşılmır. **A1**-i dəyişir (orada pool user `caddy` yazılıb), yəni ADR A39-da qeyd olunmalıdır |
| **`caddy` istifadəçisini saxlamaq** | **A1**-ə uyğun qalır, EL-də artıq belədir. Lakin Caddy prosesi ilə eyni uid — Caddy compromise olunarsa broker-ə çatır. **A33** onsuz da Caddy-ə sudo verməməyi qərar verib, yəni bu ayırma qəsdəndir |

**Tövsiyəm: yeni `azerioid-panel` hesabı.** Səbəb: **A33** məhz "Caddy-ə daha geniş sudo vermə" prinsipini qoyur — panel pool-unu Caddy-nin uid-ində saxlamaq həmin prinsipin ruhuna ziddir. Ayrıca hesab hər iki tərəfi izolə edir.

**CAVAB:**

---

### 2.2 — Panel öz PHP versiyasını müstəqil saxlayır?

**Nə üçün əhəmiyyətlidir:** P0.b panelə **öz php-fpm master-ini** verir (EL-dəki `azerioid-panel-php-fpm` pattern-i bütün OS-lərə). Bu, texniki olaraq paneli distro php-fpm versiyasından **tam ayırır** — panel 8.4-də qala bilər, saytlar başqa versiyada.

**A1** panel PHP-ni 8.4-ə pin edir. Sual: bu pin artıq **həqiqətən müstəqil** olsun (panel öz binary-sini işlədir), yoxsa sadəcə distro paketinə bağlı qalsın?

**Tövsiyəm: müstəqil.** Səbəb: **A1**-in bütün məqsədi "panel runtime izolyasiyası"dır və indiyə qədər bu yalnız EL-də tam reallaşıb. Müstəqil master + müstəqil versiya pin-i **A1**-i ilk dəfə bütün OS-lərdə həqiqətə çevirir. Əlavə xərc: panel PHP upgrade-i artıq ayrı bir addımdır (distro yeniləməsi ilə gəlmir) — bu, əslində **üstünlükdür**, çünki distro PHP yeniləməsi paneli qəfil sındıra bilməz.

**CAVAB:**

---

### 2.3 — P0.b miqrasiyası avtomatik, yoxsa açıq əmrlə?

| Variant | Tradeoff |
|---|---|
| **Self-update zamanı avtomatik** | Hər host düzəlir, operator heç nə etmir. Lakin **işləyən panelin identity-sini upgrade ortasında dəyişir** — ən riskli an |
| **Açıq `azerioid panel harden` əmri** | Operator vaxtı seçir, terminal əlində, geri qaytarmağa hazır. Lakin **əksəriyyət heç vaxt işlətməyəcək** — yəni təhlükəsizlik fix-i yayılmayacaq |
| **Hibrid: avtomatik, lakin uğursuzluqda tam rollback + səsli xəbərdarlıq** | Yayılır və təhlükəsizdir, lakin miqrasiya kodu ən ciddi yazılmalıdır |

**Tövsiyəm: hibrid.** Səbəb: bu, **kritik** təhlükəsizlik fix-idir — yayılmayan fix fix deyil. Lakin P0.a-da təsvir etdiyim sıra (yeni user yarat → chown → **additive** sudoers, hər iki user → pool-u keç → broker call-u **yoxla** → köhnə sudoers sətrini sil) hər aralıq addımda işlək yol saxlayır. Uğursuzluqda köhnə pool + köhnə sudoers bərpa olunur və səsli fail verilir. Bu sıra hibridi təhlükəsiz edir.

Siz "mövcud apt hostları **yerində** düzəldilsin (upgrade zamanı)" dediniz — bu, avtomatik variantı göstərir. Hibrid həmin cavabınıza uyğundur, sadəcə rollback zəmanəti əlavə edir. Təsdiq edin.

**CAVAB:**

---

## SONRAKI QRUPLAR — İNDİ CAVABLAMAYIN

Yalnız indeks. Hər faza başlayanda həmin qrupu ayrıca soruşacağam, tövsiyələrimlə birlikdə. Burada saxlayıram ki, nəyin gəldiyini görəsiniz və əgər hər hansı biri barədə güclü fikriniz varsa, indi deyə biləsiniz.

| Qrup | Faza | Qərarlar |
|---|---|---|
| **3** | A1 | audit/operation record retention müddətləri · reaper-in müdaxilə timeout-u · GitHub Actions məqbuldur? |
| **4** | A2 | Argon2id yoxsa PBKDF2 (⚠ `sodium`-un 5 OS hədəfində mövcudluğu yoxlanmalı) · `pg_dump -Fc` yoxsa plain SQL · pre-restore snapshot retention |
| **5** | A3 + A4 | saxlanacaq `panel.sqlite` snapshot sayı · drift aşkarlanması mtime yoxsa content hash · `reconcile now` avtomatik təmir yoxsa təsdiqlə |
| **6** | B1 | default-site rejimi (branded / 404 / 421) · TLS grace period uzunluğu · placeholder operator tərəfindən dəyişdirilə bilsin? |
| **7** | B2 | firewall qaydaları additive yoxsa declarative · auto-revert timeout yoxsa təsdiq-klik · mövcud crontab sətirləri import yoxsa unmanaged saxlanılsın · cron output retention |
| **8** | B3 | SFTP chroot layout yoxsa chroot-suz · yalnız key yoxsa parol da · SFTP shell icazəsi verirmi · chmod preset yoxsa numerik |
| **9** | B4 | volume named yoxsa docroot bind mount · `--rm` saxlanılsın · credential scope · Hub-dan başqa registry-lər (⚠ ECR AWS token refresh tələb edir — çıxarmağı tövsiyə edirəm) · Node yanaşma **A** yoxsa **B** · neçə Node major |
| **10** | B5 | hansı əməliyyatlar async olsun · concurrency scope · operation + log retention |
| **11** | B6 | bundle self-contained yoxsa manifest+parts · başqa domenə restore daxildir? · retention modeli · verification scope-dadır? · incremental/PITR |
| **12** | B7 | sərt RAM minimumu (2 GB / 4 GB) · security plugin açıq/bağlı · heap default formulu |
| **13** | B8 | *(yalnız 1.3-ə "B daxildir" cavabı verilərsə)* provider-agnostic SSH yoxsa provider API · trigger-lər · in-place yoxsa atomik release · rollback yalnız kod yoxsa kod+DB |

---

## BAĞLANMIŞ QƏRARLAR (təkrar soruşulmayacaq)

| Mövzu | Qərar | Mənbə |
|---|---|---|
| R1 həlli | Variant C — B hotfix (v1.6.1), A növbəti major (ADR A39) | sizin cavab |
| R1 miqrasiyası | Mövcud apt hostları **yerində**, upgrade zamanı | sizin cavab |
| R1 sıralaması | Roadmap-dan **əvvəl**, ayrıca prompt | sizin cavab |
| Per-vhost state | Variant C — `panel.sqlite` cədvəlləri, config xidmət üçün authoritative | sizin cavab |
| Reconcile | **Yalnız dəyişiklik zamanı** + ayrıca `reconcile now` əmri | sizin cavab |
| Backup format | **LACMP2 indi** (LACMP1/LCMP1 yalnız oxuma üçün saxlanılır) | sizin cavab |
| Postgres/Mongo backup | **Müstəqil blocker**, 9-cu tələbdən ayrı | sizin cavab |
| Backup streaming | **Daxildir** | sizin cavab |
| Search engine forma | **Yalnız single-node**, host-wide komponent | sizin cavab |
| Search engine lisenziya | **Elasticsearch (SSPL)** — MongoDB presedenti, registry `description` sahəsində | sizin cavab |
| ES RAM preflight | **Sərt blok**, swap sayılmır, yeni ADR | sizin cavab |
| `WEB_USER=www-data` universallığı | **Təsdiqləndi** — determinist sübut, ikinci host lazım olmadı | mənim yoxlama |

---

## MƏNDƏN SİZƏ İKİ QEYD

**1. Bir hüquqi qeyd (kod qərarı deyil, ona görə yalnız qeyd).** Elasticsearch/SSPL seçiminiz MongoDB presedentinə uyğundur və mən onu tətbiq etdim. Qeyd: MongoDB-də olduğu kimi, burada da kontekst *operatorun öz istifadəsidir* — panel komponenti quraşdırır, onu xidmət kimi yenidən satmır. Əgər Stack Manager gələcəkdə **managed-hosting təklifinin bir hissəsi** kimi satılarsa, SSPL §13 hüquqi baxımdan yenidən qiymətləndirilməli olacaq. Bunu qərarınızı dəyişdirmək üçün demirəm — sadəcə sonra sürpriz olmasın.

**2. A2-də bir riskli fərziyyə var, onu indi yoxlamaq istəyirəm.** Argon2id-i `sodium_crypto_pwhash` üzərindən tövsiyə etdim, lakin `sodium` extension-ın **beş OS hədəfinin hamısında** (Ubuntu 24.04, Debian 12, Alma 9, Rocky 9, CentOS Stream 9) panel PHP 8.4-də mövcud olduğunu **yoxlamamışam**. Əgər hər hansı birində yoxdursa, PBKDF2 məcburi minimum olmalıdır — və bu, LACMP2 header formatını dəyişir (KDF parametrləri header-də saxlanılır). Bu, A2 başlamazdan əvvəl yoxlanmalı texniki məsələdir; icazə verirsinizsə canlı hostda `php -m` ilə baxıram (read-only), digər OS-lər üçün isə paket adlarını yoxlayıram.


---
---

# P0 IMPLEMENTATION REPORT — R1 identity collision fix (Part B)

**Date:** 2026-09-26 · **Authorised by:** operator prompt "URGENT — fix panel/site PHP identity collision (R1)"
**Scope shipped:** Part B (immediate mitigation) + in-place hardening command. **Part A (ADR A39 structural) NOT shipped.**
**Tests:** 556/556 pass on PHP 8.4 (project's pinned version per A1).

## 1. Root-cause fix — `detect_web_user()` ordering

`deploy/install.sh`
- Early `detect_web_user()` call retained but explicitly marked **provisional** (dry-run display / interactive prompts only), with a comment stating it must not drive pool/sudoers/ownership decisions.
- Authoritative detection moved to **after `bootstrap_packages`** and made **unconditional**. The old guard `if ! id -u "${WEB_USER}"` never fired on apt (because `www-data` always exists), which is why the bug never self-corrected.
- New `--web-user=` tracking via `EXPLICIT_WEB_USER` so an operator override still wins.
- New **fatal guard**: if the resolved user is also a site FPM pool user, the installer **refuses to proceed** rather than installing a root escalation.

`deploy/lib/common.sh`
- `detect_web_user()` logic unchanged (it was correct); added a call-order warning comment.
- New `site_pool_user_conflict()` — scans `/etc/php/*/fpm/pool.d/*.conf` and `/etc/php-fpm.d/*.conf`, skipping the panel's own pool, and reports whether a candidate user runs a site pool.

**Proof (function executed verbatim against a simulated stock apt image):**

```
STAGE=pre   detect_web_user=www-data  collides_with_site_pool=yes   <- old behaviour = the vulnerability
STAGE=post  detect_web_user=caddy     collides_with_site_pool=no    <- new behaviour = fixed
```

**Guard branches:**
```
WEB_USER=www-data, no override  -> FATAL, exit 1   (refuses to install the hole)
WEB_USER=caddy,    no override  -> proceed, exit 0
WEB_USER=www-data, --web-user=  -> proceed, exit 0 (explicit operator override honoured)
```

## 2. New in-place hardening command

- `broker/src/Panel/PanelHardener.php` (new) — idempotent, transactional migration with a rollback journal.
- `broker/src/Actions/PanelHarden.php` (new) — `panel.harden.status`, `panel.harden.apply`.
- `broker/src/Kernel.php` — 2 actions registered. `broker/src/Validator.php` — `HARDEN_PANEL_CONFIRM = 'HARDEN-PANEL'`.
- CLI: `azerioid panel harden status|apply` with `--confirm`, `--dry-run`, `--lockdown-site-pools`.
  `status` exits **1** when the host is vulnerable, so it works as a fleet/CI gate.
- `broker/tests/Unit/PanelHardenerTest.php` (new) — 11 tests / 28 assertions.

**Target identity: `caddy`** — deliberately, because it is what `detect_web_user()` already intends, what EL hosts already run, and what the fixed installer now produces. Hardened apt hosts therefore converge on the **same** state as fresh installs instead of forming a third variant. A fully dedicated `azerioid-panel` account is Part A / A39.

**Migration order (keeps a working broker path at every intermediate step):**
additive sudoers granting **both** identities → ownership migration → pool user → queue unit → broker.json / tmpfiles / scheduler cron → FPM reload (restart fallback if the socket is not re-owned) → **verify: unit active + socket owner + real broker call as the new identity** → only then drop the old grant → restart queue. Any failure reverts the entire journal and restarts services.

## 3. Live evidence on `64.226.78.176`

**BEFORE:**
```
User www-data may run the following commands on mail:
    (root) NOPASSWD: /usr/local/lib/azerioid-panel/broker
panel pool : user = www-data
site  pool : user = www-data
global fpm php.ini disable_functions =        (empty)
broker.json web_user = www-data
```

**AFTER:**
```
User www-data is not allowed to run sudo on mail.
User caddy may run the following commands on mail:
    (root) NOPASSWD: /usr/local/lib/azerioid-panel/broker
panel pool : user = caddy
site  pool : user = www-data          (unchanged)
visudo -c  : /etc/sudoers.d/azerioid-panel: parsed OK
azerioid panel harden status -> "OK: no sudoers grant is shared with a site PHP-FPM pool user." exit=0
```

**Negative test (non-destructive, did not invoke the broker as www-data):**
```
sudo -u www-data sudo -n -l /usr/local/lib/azerioid-panel/broker
  -> sudo: a password is required
```

**No indirect path remains:**
```
[A] www-data-writable files under PREFIX ......... 0
[B] world-writable files under PREFIX ............ 0
[C] broker=root:root 750  broker.php=root:root 640  sudoers=root:root 440
[E] www-data in caddy group = no ; caddy shell = /usr/sbin/nologin
```

## 4. Panel and sites both still fully functional

| Check | Result |
|---|---|
| `azerioid vhost list` / `db list` / `component list` / `status` | all exit 0 with real data (each is sudo→broker as `caddy`) |
| Privileged **write** via broker (`php.timeouts.ensure`) | `{"ok":true,...}` |
| Queue worker path | `User=caddy Group=caddy`; `PingJob ... RUNNING → DONE` |
| Panel HTTP (`/internal/auth-check`) | `401` — identical to baseline (Laravel executing, not 502) |
| 9 site vhosts, HTTPS status codes | **identical to pre-change baseline**, including pre-existing `000` (4 vhosts with no cert) and `502` (2 vhosts with downed upstreams) — those states pre-date this work |
| `php8.4-fpm`, `caddy`, `azerioid-panel-queue` | all `active` |
| Adminer (separate pool `azerioid-adminer-tool`, socket `www-data:www-data 0660`) | unaffected — `caddy` reaches it via its `www-data` group membership, unchanged |
| Idempotency: re-run `harden apply --confirm` | "Already hardened — panel runs as caddy." exit 0 |

## 5. Residual risk — explicitly NOT closed

1. **Site pools can still spawn processes.** On apt the panel pool shares one php-fpm master, hence one `php.ini`, with the site `www` pool, and `deploy/lib/fpm.sh` strips `proc_open`/`proc_get_status` from that shared `php.ini` so the panel can spawn `sudo`. Per-pool `disable_functions` can only **append** to the global list, never remove from it. Opt-in mitigation shipped: `--lockdown-site-pools` (behaviour-changing for hosted apps, so off by default). **This is no longer a root path** — the sudo grant is gone — but it is weaker than SPEC intends. Structural close = A39 Part A.
2. **`caddy` now holds the broker grant**, so a Caddy compromise reaches the broker. Far narrower than "any hosted site's PHP", but not the isolated identity Part A delivers.
3. `docs/SPEC.md` contained the claim *"public pools retain `proc_open` lockdown"*, which was **false in practice**. Corrected to describe reality plus the opt-in flag, rather than leaving a misleading guarantee.

## 6. Documentation

- `docs/DECISIONS.md`: new **ADR A39** — problem statement, Part B (landed), Part A (pending), and the residual risks above recorded so they are not silently lost.
- `docs/SPEC.md`: corrected the false FPM-lockdown claim.

## 7. NOT DONE — honest gap

**Test item 1 of the prompt ("fresh install on a clean Ubuntu host and a clean Debian host") was not performed.** I have no clean hosts, and `doctl` on this machine is unauthenticated (`access token is required`), so I could not provision any. What I did instead: executed the real `detect_web_user()` and the real guard logic against a simulated stock-apt layout, proving both branches deterministically (§1). That is strong evidence about the code path but it is **not** a real end-to-end install.

To close it: authenticate `doctl` (`! doctl auth init`) or give me a clean Ubuntu and a clean Debian host, and I will run genuine fresh installs and verify `user = caddy` in the panel pool with `www-data` untouched on the site pool.

Also not done (deliberately, out of P0 scope): nothing was committed to git.

## 8. VERDICT

**Is the passwordless-root-sudo-from-any-hosted-site-code path now closed?**

- **On the already-live `64.226.78.176`: YES.** `www-data` — the identity every hosted PHP site runs as — has no sudo entitlement at all (`sudo: a password is required`), holds no writable file anywhere under `PREFIX`, is not in the `caddy` group, and `caddy`'s shell is `nologin`. Panel, queue, CLI and all 9 site vhosts verified functional and byte-identical to baseline.
- **On fresh installs: YES by code path, not yet by live proof.** The ordering fix plus the new refuse-to-install guard make the vulnerable outcome unreachable, demonstrated deterministically — but a real clean-host install has not been run (§7).
- **Fully structurally isolated? NO, and not claimed.** Site pools retain process-spawning unless `--lockdown-site-pools` is used, and `caddy` rather than a dedicated account holds the grant. Both are A39 Part A.


---
---

# PART 5 — QRUP 1 & QRUP 2 CAVABLANDI (2026-09-26)

## Qeydə alınan qərarlar

| # | Qərar | Tətbiq olundu |
|---|---|---|
| **1.1** | #14 = **(b) + (a)**. (c) tam scope-dan kənar | Part 3 #14 və Part 4 B1 yeniləndi |
| **1.2** | #10 extract **açılır**, tam adversarial dəst **məcburi gate** | **ADR A40** yazıldı + `SPEC.md:129` düzəldildi |
| **1.3** | #12 **Proposition B daxil**, **Proposition A həmişəlik rədd** | **ADR A41** yazıldı (təkrar açılmasın deyə) |
| **2.1** | Yeni ayrıca **`azerioid-panel`** sistem hesabı; **A1 rəsmən rəvizə** | **ADR A39** Part A yeniləndi |
| **2.2** | Panel PHP versiyası **müstəqil** | **ADR A39** Part A, bənd 2 |
| **2.3** | Miqrasiya **hibrid** (avtomatik + tam rollback zəmanəti) | **ADR A39** Part A, bənd 3 |

### A41-də kilidlənən B parametrləri (sizin təsdiqinizlə)

| Parametr | Qərar |
|---|---|
| Provider | Provider-agnostic SSH, panel-generated deploy key. **OAuth yox, provider API yox** |
| Trigger | **Yalnız əl + cədvəl. Webhook yox** |
| Deploy | **v1 üçün in-place `git pull`**. Atomik release dizinləri yox |
| Rollback | **Yalnız kod** (`git checkout <sha>` + yenidən icra). Kod+DB yox |
| Identity | `az-vh-*` (A25) — **heç vaxt root, heç vaxt `azerioid-supervised`** |
| Reload | Mövcud `vhost.octane.reload` / `vhost.pm2.reload` / `vhost.docker.restart` |

**A41-də açıq qeyd olunan məhdudiyyət:** in-place `git pull` **atomik deyil** — pull ortasında yarımçıq ağac xidmət olunur. v1 üçün qəbul edilir, lakin UI-da sənədləşdirilməlidir, səssizcə keçilməməlidir.

---

## A2 BLOKERİ HƏLL OLUNDU — `sodium` / KDF araşdırması

İcazə verdiyiniz yoxlama tamamlandı. **Canlı hostda yalnız read-only `php -m` / `php -r` çağırışları; digər 4 hədəf üçün yalnız repo listing oxunuşu — heç bir serverə toxunulmadı.**

### Nəticə: 5 OS hədəfi

| Hədəf | PHP mənbəyi | `sodium` | Argon2id | Sübut |
|---|---|---|---|---|
| **Ubuntu 24.04** | Sury | ✅ **statik kompilyasiya** | ✅ | Canlı host: `php -m` → `sodium`; `extension_dir`-də `sodium.so` **yox**; `apt-cache policy php8.4-sodium` → **paket mövcud deyil**; `SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13` **available** |
| **Debian 12** | Sury (**eyni repo, eyni build**) | ✅ | ✅ | Sury hər iki distro üçün eyni paketi verir; ayrıca `php8.4-sodium` paketi mövcud deyil |
| **AlmaLinux 9** | Remi | ❌ **YOXDUR** | ❌ | `php-sodium` **ayrıca RPM**-dir: `php-sodium-8.4.25-1.module_php.8.4.el9.remi.x86_64.rpm`, və `deploy/lib/packages.sh:20-22` onu **quraşdırmır** |
| **Rocky 9** | Remi | ❌ **YOXDUR** | ❌ | eyni |
| **CentOS Stream 9** | Remi | ❌ **YOXDUR** | ❌ | eyni |

**Kritik tapıntı:** `deploy/lib/packages.sh:20-22` EL üçün bunları quraşdırır — `php-fpm php-cli php-process php-sqlite3 php-mysqlnd php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath` — **`php-sodium` siyahıda yoxdur**. `registry/components/php-8.4.json` EL bloku da onu sadalamır. Yəni **üç EL hədəfində `sodium_crypto_pwhash` (Argon2id) mövcud deyil.**

### Vacib ayrılma: AEAD `sodium`-dan asılı deyil

| Komponent | Asılılıq | 5 hədəfdə vəziyyət |
|---|---|---|
| **AES-256-GCM (AEAD)** | `openssl_encrypt` | ✅ **hər yerdə** — openssl həmişə mövcuddur. Canlı hostda `aes256gcm_is_available: yes` (hardware-accelerated) |
| **Argon2id (KDF)** | `sodium_crypto_pwhash` | ❌ EL-də yox |
| **PBKDF2-SHA256 (KDF)** | `hash_pbkdf2` | ✅ **hər yerdə** — PHP core |

Yəni LACMP2-nin **şifrələmə hissəsi heç bir yeni asılılıq tələb etmir**. Yalnız **KDF seçimi** `sodium`-dan asılıdır.

### Tövsiyəm (A2 başlamazdan əvvəl qərar lazımdır)

**Hər ikisini et, bu ardıcıllıqla:**

1. **`php-sodium`-u EL quraşdırma siyahısına əlavə et** — `deploy/lib/packages.sh` dnf bloku və `registry/components/php-8.4.json` EL `packages`. Ucuzdur: bir paket, **artıq konfiqurasiya olunmuş və A10-a uyğun GPG-pinned Remi repo-sundan** gəlir. Bundan sonra Argon2id bütün 5 hədəfdə mövcud olur.
2. **Buna baxmayaraq KDF id + parametrlərini LACMP2 header-ində saxla** və **PBKDF2-SHA256-nı zəmanətli minimum kimi qoru.** Səbəb: (a) yenilənməmiş mövcud EL hostları hələ də `sodium`-suz olacaq — orada yaradılmış arxivlər oxunabilən qalmalıdır; (b) header-də KDF-in qeyd olunması formatı gələcəyə hazır edir (sizin onsuz da istədiyiniz xüsusiyyət); (c) restore heç vaxt "zəif" derivasiyaya səssizcə geri düşməməlidir — header hansının istifadə olunduğunu birmənalı göstərir.

**Bu, Qrup 4-ün 1-ci sualını (Argon2id yoxsa PBKDF2) əvəz edir:** cavab "hər ikisi, header-də qeydlə, Argon2id üstünlüklü" olur — lakin bu, **sizin təsdiqinizi tələb edir**, çünki EL paket siyahısına əlavə etmək quraşdırma davranışını dəyişir.

**CAVAB (təsdiq / dəyişiklik):**

---

## Yenilənən feature qərarları

### #14 — (b) + (a) — Part 3/Part 4 dəqiqləşdirməsi

(b) üçün texniki əhatə: `:80`/`:443` üçün **daha az spesifik** catch-all site bloku, `tls internal` ilə, panel-in öz kataloqundan (heç bir vhost identity-si onu yaza bilməz). `Web/PanelCaddy.php:73-76`-dakı `:3169` 421 pattern-i eynilə təkrarlanır. **A9** Caddy-ə `:80`/`:443`-ü daimi verdiyi üçün bu, `:3169` halından fərqli olaraq heç bir yeni port açmır. Settings-də rejim seçicisi: branded səhifə / düz 404 / 421.

(a) üçün: `VhostWelcomePage::render()` yenilənir. Yalnız **boş** docroot-a təsir edir — real saytı heç vaxt üzmür.

**Hələ açıq (Qrup 6):** default-site rejimi hansı olsun (branded / 404 / 421)? Tövsiyəm: **branded, neytral mətnli** ("this domain is not configured on this server"). 404 hücumçuya daha az məlumat verir, lakin DNS-i səhv yönəldən insana heç nə öyrətmir.

### #10 — extract açıldı, gate məcburi

**A40** yazıldı. İki ayrı hissə:
- **Arxiv yaratmaq (in-place compress):** şərtsiz açıqdır — zip-slip istisnası onu heç vaxt əhatə etmirdi.
- **Arxiv açmaq (extract):** açıqdır, **lakin A40 cədvəlindəki 8 adversarial halın hamısı keçmədən "bitmiş" sayılmır.**

### #12 — B daxil, A həmişəlik rədd

**A41** yazıldı və gələcəkdə bu sualın təkrar açılmasının qarşısını almaq üçün A-nın rəddi rəsmiləşdirildi ("Do not reopen without a new ADR that explicitly supersedes this one").

---

## Qalan açıq qruplar

Qrup 1 və 2 bağlandı. Qrup 3–13 hələ açıqdır — hər faza başlayanda soruşacağam. **İstisna:** yuxarıdaki `php-sodium` təsdiqi A2-ni bloklayır, ona görə onu indi soruşdum.


---

## `php-sodium` TƏSDİQLƏNDİ VƏ TƏTBİQ OLUNDU (2026-09-26)

**CAVAB (operator):** təsdiqlənir — `php-sodium` EL siyahısına əlavə edilsin.

### Edilən dəyişikliklər (minimal, cərrahi)

| Fayl | Dəyişiklik |
|---|---|
| `deploy/lib/packages.sh` | dnf bootstrap sətrinə `php-sodium` əlavə olundu (`php-zip php-bcmath php-sodium …`) |
| `registry/components/php-8.4.json` | `distros.el.packages` → `php-sodium` əlavə olundu |
| `docs/DECISIONS.md` | **ADR A42** yazıldı — ölçülmüş tapıntılar, qərar, əhatə və nəticələr |

`git diff` təsdiqlədi: hər iki faylda **yalnız bir sətir** dəyişdi, JSON yenidən formatlanmadı. Bütün registry JSON-ları valid. **556/556 test keçir.**

### Əhatə qərarı (qeyd olunub, səssiz genişlənmə yoxdur)

**Yalnız panel runtime (PHP 8.4).** Sayt PHP versiyaları (`php-8.1/8.2/8.3`) **dəyişdirilmədi** — backup kriptoqrafiyası broker-də, panel runtime altında işləyir, ona görə onların belə bir tələbi yoxdur. Hostlanan tətbiqlərin rahatlığı üçün sayt versiyalarına sodium əlavə etmək **ayrıca sualdır**, burada qərar verilmədi. İstəyirsinizsə deyin.

### Vacib nəticə — KDF hələ də graceful degrade etməlidir

Paketi siyahıya əlavə etmək **onu geriyə dönük quraşdırmır.** Mövcud EL hostları yenidən quraşdırılana qədər `sodium`-suz qalacaq. Ona görə LACMP2 (A2 fazasında) **mütləq**:

1. **PBKDF2-SHA256**-nı zəmanətli minimum kimi saxlamalı (`hash_pbkdf2`, PHP core, hər yerdə var).
2. **KDF identifikatorunu və parametrlərini header-də** saxlamalı — restore birmənalı olsun və güclü derivasiya ilə yazılmış arxiv üçün heç vaxt səssizcə zəifə düşməsin.

Bu, A42-də rəsmən qeyd olundu ki, A2 başlayanda itməsin.

### Bonus tapıntı: AEAD-in `sodium`-dan asılılığı yoxdur

AES-256-GCM `openssl_encrypt` vasitəsilə 5 hədəfin hamısında əlçatandır (yoxlanılmış hostda hardware-accelerated). Yəni **LACMP2-nin şifrələmə yarısı heç bir yeni asılılıq tələb etmirdi** — yalnız KDF seçimi `sodium`-dan asılı idi. Bu da A42-də qeyd olundu.

