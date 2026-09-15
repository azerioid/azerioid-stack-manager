# Mail server component — implementation design (A36)

**Status:** **Approved spec** (operator decisions locked 2026-09-14) — ready for a future implementation task; **no code in this document’s scope**  
**Date:** 2026-09-14 (finalized same day)  
**Related ADR:** [A36 in DECISIONS.md](./DECISIONS.md#a36--mail-server-component-future)  
**Constraint:** No registry entry, broker action, or UI is shipped until a separate implementation task executes this approved spec.

---

## 1. Goal

Add an **opt-in, registry-driven mail server component** that lets an operator host mail for domains already managed as panel vhosts — with the same security-first, cross-OS, broker-owned configuration discipline as MariaDB / Adminer / Supervisor — without turning AZERIOID into a full mail-hosting product on day one.

v1 supports **two equally first-class outbound paths**: direct MX delivery when outbound TCP/25 is open, and **smarthost/relay** (SMTP AUTH to SES, SendGrid, Mailgun, or any SMTP relay) when the cloud provider blocks it. Install and the Mail UI **detect** outbound-25 reachability with a live probe and steer the operator accordingly (see §7.2 / §7.4).

---

## 2. Chosen MTA: Postfix (+ Dovecot + OpenDKIM)

### 2.1 Recommendation

| Role | Choice | Why |
|------|--------|-----|
| MTA | **Postfix** | Scriptable with `postconf -e` / `postconf -n`; validate with `postfix check`; reload/restart via systemd. Config path **`/etc/postfix/main.cf`** (+ `master.cf`) is the same on Ubuntu, Debian, and EL9. Package `postfix` is in **base** repos on all supported families (AppStream on EL). |
| Auth / delivery agent | **Dovecot** | Industry-standard SASL backend for Postfix submission; IMAPS for mailbox access. Packages mature on deb (`dovecot-core`, `dovecot-imapd`, `dovecot-lmtpd`) and EL (`dovecot`). |
| DKIM signing | **OpenDKIM** | Standard milter for outbound signing; `opendkim-genkey` for key + DNS TXT generation. On EL, **EPEL is an accepted dependency** of this component (and CRB if the distro requires it to pull OpenDKIM). |

**Exim is not chosen** for this component.

### 2.2 Postfix vs Exim (project constraints)

| Criterion | Postfix | Exim |
|-----------|----------|------|
| Idempotent broker edits | `postconf -e key=value` is designed for automation; Ansible/roles universally use it; preserves distro defaults instead of rewriting whole files | Debian/Ubuntu Exim is often **split-config** (`update-exim4.conf` / `exim4.conf.template`); programmatic edits are fragile and OS-specific |
| Config path stability | `/etc/postfix/main.cf` across Ubuntu / Debian / EL | Paths and “split vs monolithic” differ; EL vs Debian packaging diverge more |
| Distro package maturity | First-class on Ubuntu, Debian, Alma, Rocky, CentOS Stream | Default on many Debian installs; **conflicts** with Postfix via `mail-transport-agent` virtual package — installing Postfix removes Exim packages |
| Cross-OS mental model | One parameter language (`postconf`) | Powerful but harder to keep one broker code path |
| Ecosystem for our v1 stack | Postfix + Dovecot + OpenDKIM is the documented minimum secure stack in current ops guides | Viable, but fewer turnkey “SASL via Dovecot + milter” cookbooks that match our matrix |

**Install conflict (must be handled):** On Debian/Ubuntu, `apt install postfix` may **remove** `exim4*`. The component installer must:

1. Detect an existing MTA (`exim4`, foreign Postfix, Sendmail, etc.).
2. **Always** require typed confirmation (`REPLACE-MTA`) before removing foreign packages — **including Debian’s default Exim**. No auto-replace convenience exception.
3. Record adopt-vs-fresh in managed-component state.

### 2.3 Distro path / package sketch (confirmed patterns)

| Artifact | Ubuntu / Debian | EL (Alma / Rocky / CentOS Stream 9+) |
|----------|-----------------|--------------------------------------|
| `main.cf` | `/etc/postfix/main.cf` | `/etc/postfix/main.cf` |
| `master.cf` | `/etc/postfix/master.cf` | `/etc/postfix/master.cf` |
| Unit | `postfix.service` | `postfix.service` |
| Mail log | `/var/log/mail.log` (rsyslog) | `/var/log/maillog` |
| Default TLS material | often snakeoil under `/etc/ssl/…` | `/etc/pki/tls/certs|private/postfix.*` |
| Dovecot conf | `/etc/dovecot/dovecot.conf` + `conf.d/` | same layout under `/etc/dovecot/` |
| OpenDKIM | `/etc/opendkim.conf`, keys under `/etc/opendkim/keys/` | same after **EPEL** install |
| Client MTA package (optional) | `mailutils` | `s-nail` / `mailx` |

Broker code must use **DistroPaths / registry `paths`** for logs and TLS defaults — never hardcode Ubuntu paths (lesson from MariaDB `server_cnf` / socket divergence).

---

## 3. Minimum viable secure mail (current practice)

What “secure enough for v1” means in 2025–2026 ops practice — not a full anti-spam farm.

### 3.1 Transport layout

| Port | Service | Bind (v1 default) | Auth | Purpose |
|------|---------|-------------------|------|---------|
| **25** | SMTP | public (required for inbound MX) | **No AUTH** | Server-to-server receive only |
| **587** | submission | public | **SASL required** + STARTTLS mandatory | Clients / apps submit mail |
| **465** | submissions | public (optional but recommended) | SASL + implicit TLS | Clients that prefer SMTPS |
| **993** | IMAPS | public | TLS + mailbox auth | Retrieve mail |
| **143** | IMAP | **closed** in v1 | — | Avoid plaintext IMAP |

Firewall: when ufw/firewalld is active, component enable opens `smtp` / `smtps` / `imaps` (or explicit 25/465/587/993) the same way site HTTP opens 80/443 — never silently.

**Outbound vs inbound on :25:** Inbound listen on :25 is always part of v1 (MX receive). **Outbound** connect-to-remote-MX on :25 is often blocked by cloud providers (DigitalOcean documents default blocks on 25/465/587 for newer accounts; support-ticket lift varies). v1 therefore treats **direct outbound** and **smarthost relay** as peer configurations (§3.5, §4.1, §7.4).

### 3.2 Relay-abuse prevention (non-negotiable)

- **Never an open relay.** `mynetworks` = localhost only (plus optional operator-approved RFC1918 list behind typed confirm).
- Relay for real senders only via **`permit_sasl_authenticated`** on submission services.
- Do **not** set `smtpd_sasl_auth_enable=yes` globally in `main.cf` (that leaks AUTH onto port 25). Enable SASL only via `master.cf` overrides on 587/465.
- Rate limits: Postfix `smtpd_client_message_rate_limit` / `anvil` knobs; fail2ban jail for submission auth failures (reuse panel fail2ban patterns).
- Post-install **open-relay self-test** from an external probe or scripted “unauthenticated RCPT TO foreign domain must fail” check before UI marks the component healthy.

### 3.3 DNS authenticity (panel generates; operator publishes)

Like TLS/domain surfaces today, the panel **does not** own the DNS provider for mail by default — it shows copy-paste records:

| Record | Purpose |
|--------|---------|
| **MX** | Point domain → the operator-set **mail hostname** (panel setting, A22-style — not auto-derived) |
| **A/AAAA** | Mail hostname → server IP |
| **PTR / rDNS** | Operator must set at the VPS provider — panel can only **detect/warn** if reverse DNS ≠ mail hostname |
| **SPF** TXT | Direct path: include this host’s IP; smarthost path: include the provider’s published SPF mechanisms (UI must generate the right suggestion for the active outbound mode) |
| **DKIM** TXT | From OpenDKIM public key (`selector._domainkey`) when signing locally |
| **DMARC** TXT | `_dmarc` with `p=none; rua=mailto:…` initially; operator raises to quarantine/reject after alignment |

Google/Yahoo bulk-sender rules make SPF+DKIM+DMARC table-stakes for deliverability even for modest volume; v1 must make these visible and testable.

### 3.4 What a “simple admin UI” needs vs does not

**In scope for diagnostics / ops (v1):** queue depth / deferred count, recent log tail (redacted), “test outbound” to a typed address, DNS checklist with pass/fail probes where possible (MX/SPF/DKIM TXT lookups), open-relay check status, **outbound-25 probe status + smarthost CTA** (§7.4).

**Out of scope for UI (v1):** SpamAssassin/Rspamd rule tuning, quarantine management UI, mailing-list managers, full webmail, catch-all marketing automation, per-mailbox quota management UI.

### 3.5 Outbound delivery modes (both first-class in v1)

| Mode | When | Behavior |
|------|------|----------|
| **Direct** | Live outbound TCP/25 probe succeeds (connect + optional SMTP banner to a well-known MX, e.g. `gmail-smtp-in.l.google.com:25`) | Postfix delivers to recipient MX on :25 as usual; the panel’s local OpenDKIM signature is what receivers verify for DKIM alignment |
| **Smarthost / relay** | Probe fails **or** operator chooses relay anyway | `relayhost` + SMTP AUTH (SASL) to an external provider (SES, SendGrid, Mailgun, Brevo, generic SMTP). Credentials stored like other panel secrets (not argv, not audit bodies) |

Evidence informing this (2026-09-14 fleet probe): outbound :25 was **open** on Ubuntu droplet `64.226.78.176`, while [DigitalOcean’s docs](https://docs.digitalocean.com/support/why-is-smtp-blocked/) state 25/465/587 are **blocked by default** on Droplets — so openness on one tested host must not be generalized. Most real installs will need relay; the UI must treat that as the common case, not an edge case.

**Smarthost DKIM / DMARC (proven 2026-09-15 on `let.az` → Gmail via Brevo):** Many transactional relays modify the message after local signing (tracking pixels, List-Unsubscribe, footers, reformatting). When that happens, the panel’s own OpenDKIM body-hash typically fails at the destination (`dkim=neutral` / fail for the local selector) — **expected for a content-modifying relay, not a signing bug**. DMARC can still **pass** if the relay’s own domain-authenticated DKIM signature aligns with the `From:` domain (`d=<your domain>`), which reputable providers support once their domain-authentication / branding DNS is complete. DMARC requires SPF *or* DKIM to pass with alignment — not both, and not specifically the panel’s local signature. In smarthost mode, **relay domain authentication is what matters for deliverability**; keep publishing the panel’s DKIM TXT for direct mode and for relays that preserve the body, but do not treat a failed local signature after relay mutation as a defect. Direct mode continues to rely on local OpenDKIM end-to-end as originally designed.

---

## 4. Feature scope for v1

Mirror Terminal / File Manager / Supervisor / Node (A16): ship a thin, explicit slice; refuse feature creep in the same ADR language.

### 4.1 In scope (v1)

1. **Component install / remove / status** via registry + Components page (queued broker job), SELinux Enforcing–safe on EL; EPEL (and CRB if needed) for OpenDKIM on EL.
2. **Explicit mail hostname** panel setting (A22 white-label pattern) — required before enabling domains; never inferred from “first vhost” or bare `hostname -f`.
3. **Virtual mailbox domains** limited to domains that already exist as **panel-managed vhosts**, **vhost-scoped 1:1**. Deleting a vhost **refuses** while mailboxes (or mail-enabled state with data) exist unless `--drop-mail` / typed confirm.
4. **Mailbox create / reset password / disable / delete** (local virtual users, not shell accounts).
5. **Alias create / delete** (`alias@domain` → mailbox or external address with confirm if external).
6. **Authenticated submission** (587 + 465) and **IMAPS** (993) working end-to-end.
7. **Inbound receive** on 25 for enabled domains (MX must point here) — Maildir under a dedicated `vmail` UID. **Full send + receive ship together** (not receive-later).
8. **DKIM key generation per domain** + DNS record panel; Postfix milter wired to OpenDKIM.
9. **SPF / DMARC suggested records** displayed (and live DNS lookup health), mode-aware when smarthost is active.
10. **Outbound-25 live probe** + plain-language health messaging + **first-class smarthost configuration** (setup CTA when blocked) — core UX, not an advanced footnote (§7.4).
11. **Queue status**: active / deferred counts; flush deferred (with confirm); show last N log lines.
12. **CLI parity in v1:** `azerioid mail …` thin wrappers for every UI capability (including status probe and smarthost config).
13. **TLS for SMTP/IMAP:** prefer reusing existing Caddy/Let’s Encrypt material for a panel-managed hostname when available; otherwise dedicated certbot cert for the mail hostname.
14. **Hardcoded size defaults:** e.g. **25 MiB** max message size; **no** quota-management UI in v1.
15. **Port ownership** documented; panel Laravel mailer stays **decoupled** unless operator opts in via Settings to route alerts through local mail (§9.9).

### 4.2 Explicitly out of scope (v1)

| Out | Why / later |
|-----|-------------|
| Webmail (Roundcube, etc.) | Separate security surface + PHP app; like “no PM2” for Node |
| Spam-filter tuning UI (Rspamd/SpamAssassin rules, Bayes training) | Ops complexity; optional v2 component |
| Mailing lists (Mailman, Sympa) | Different product |
| Catch-all marketing / bulk send | Reputation risk; refuse high-volume features |
| Multi-tenant panel RBAC for mail | v1 remains single-admin (A15) |
| Automatic DNS mutation via Cloudflare/DO | Optional later reuse of `registry/dns-providers/`; v1 = display + copy |
| POP3 | Prefer IMAPS only |
| Sharing mailbox files via File Manager / Terminal | Mail store is not a vhost docroot |
| Auto-switching panel alert mail to local Postfix | Circular dependency risk; Settings opt-in only |
| Per-mailbox / per-domain quota UI | Hardcoded message-size default only |

### 4.3 Storage and identity model

- Dedicated system user/group **`vmail`** (no login shell); Maildir under e.g. `/var/vmail/<domain>/<localpart>/`.
- Postfix `virtual_*` maps and Dovecot userdb fed from **broker-generated map files** (`hash:` / `lmdb:` / passwd-file), regenerated atomically on change — **no MariaDB/PostgreSQL dependency** for mail maps.
- Mailbox passwords: hashed (Dovecot-compatible); generated once and shown like DB passwords; never accepted on argv (env / UI only).
- **Tenancy:** mailboxes and aliases are owned by the panel vhost domain. Vhost delete is blocked until mail is dropped with explicit confirm.

---

## 5. Security model

### 5.1 Fits existing patterns

| Pattern | Application |
|---------|-------------|
| Registry-driven install | `registry/components/mail.json` — packages, units, paths, EPEL hint per `ubuntu` / `debian` / `el` |
| Broker-only privileged ops | UI never writes `main.cf`; all changes via `mail.*` broker actions |
| Least privilege | `postfix` / `dovecot` / `opendkim` / `vmail` service users; panel FPM never reads private keys or smarthost secrets from world-readable paths |
| Confirm dangerous ops | **Always** `REPLACE-MTA`; open `mynetworks`; delete domain/vhost with mail; flush queue; clear smarthost |
| Fail2ban | Submission + IMAP auth jails alongside `azerioid-panel` / `sshd` |
| SELinux | Ship/allow known contexts; do not disable Enforcing; document `postfix_t` / `dovecot_t` / milter socket needs |
| Uninstall | Remove units/packages per policy; **retain** `/var/vmail` unless `--drop-mail` (mirror A29 spirit for operator data) |

### 5.2 Risks unique to mail (do not map 1:1 to existing components)

| Risk | Why unique | Mitigations in v1 design |
|------|------------|---------------------------|
| **Open relay** | Turns the VPS into a spam cannon within minutes | Defaults + self-test + typed confirms for any relay widening |
| **IP/domain reputation & blocklists** | Abuse or misconfig burns the host IP; recovery is slow | Rate limits; outbound volume warnings; PTR/SPF/DKIM/DMARC checklist before “healthy” |
| **Credential stuffing on 587/993** | Public auth ports | TLS-only auth; fail2ban; strong generated passwords |
| **Provider outbound SMTP blocks** | Common on DO and peers; direct MX delivery silently fails | Live outbound-25 probe; equal smarthost path; proactive UI/CLI messaging (§7.4) |
| **DKIM private key theft** | Forges mail as the domain | Root-only key files; never expose via File Manager; rotate action |
| **Smarthost credential leak** | Compromises third-party sending | Secret storage discipline; redact from logs/audit |
| **Backscatter / forged bounce** | Inbound policy mistakes | Reject unauthorized destinations; no catch-all by default |

Adminer’s risk was “SQL tool behind panel auth.” Mail’s risk is **internet-facing SMTP reputation** — closer to “opening Mongo globally” than to Adminer.

---

## 6. Registry entry sketch

Illustrative only — **do not add this file until the implementation task**.

```json
{
  "id": "mail",
  "display_name": "Mail (Postfix)",
  "category": "mail",
  "description": "Opt-in Postfix + Dovecot + OpenDKIM for panel-managed domains. Authenticated submission only; virtual mailboxes; DNS record guidance for SPF/DKIM/DMARC; direct or smarthost outbound.",
  "managed": true,
  "system": false,
  "installable": true,
  "min_os": { "ubuntu": "24.04", "debian": "12", "el": "9" },
  "ports": [
    { "port": 25,  "bind": "0.0.0.0", "owner": "postfix", "protocol": "tcp" },
    { "port": 587, "bind": "0.0.0.0", "owner": "postfix", "protocol": "tcp" },
    { "port": 465, "bind": "0.0.0.0", "owner": "postfix", "protocol": "tcp" },
    { "port": 993, "bind": "0.0.0.0", "owner": "dovecot", "protocol": "tcp" }
  ],
  "conflicts": ["exim4", "exim4-daemon-light", "sendmail"],
  "distros": {
    "ubuntu": {
      "packages": ["postfix", "dovecot-core", "dovecot-imapd", "dovecot-lmtpd", "opendkim", "opendkim-tools"],
      "unit_name": "postfix",
      "detect": { "packages": ["postfix"], "unit": "postfix", "command": "postconf -d mail_version" },
      "paths": {
        "main_cf": ["/etc/postfix/main.cf"],
        "master_cf": ["/etc/postfix/master.cf"],
        "dovecot_conf": ["/etc/dovecot/dovecot.conf"],
        "opendkim_conf": ["/etc/opendkim.conf"],
        "mail_log": ["/var/log/mail.log"],
        "vmail_root": ["/var/vmail"]
      }
    },
    "debian": { "...": "same package set and paths as ubuntu" },
    "el": {
      "packages": ["postfix", "dovecot", "opendkim", "opendkim-tools"],
      "repos_hint": ["epel"],
      "unit_name": "postfix",
      "detect": { "packages": ["postfix"], "unit": "postfix", "command": "postconf -d mail_version" },
      "paths": {
        "main_cf": ["/etc/postfix/main.cf"],
        "master_cf": ["/etc/postfix/master.cf"],
        "dovecot_conf": ["/etc/dovecot/dovecot.conf"],
        "opendkim_conf": ["/etc/opendkim.conf"],
        "mail_log": ["/var/log/maillog"],
        "vmail_root": ["/var/vmail"]
      }
    }
  },
  "preflight": {
    "min_disk_gb": 2,
    "min_ram_mb": 512,
    "notes": [
      "Requires an explicit operator-set mail hostname (panel setting)",
      "Outbound TCP/25 may be blocked — smarthost is a first-class v1 path",
      "Installing removes a foreign MTA only after typed REPLACE-MTA confirm",
      "EL installs OpenDKIM via EPEL (accepted dependency)"
    ]
  }
}
```

Also update `docs/port-ownership.md` (when implementing) with the four public ports and “mail is not loopback-default” exception (unlike MariaDB).

**Broker action sketch (names only):**

- `mail.status` (includes outbound-25 probe + delivery mode) / `mail.queue` / `mail.logs`
- `mail.hostname.set|show`
- `mail.domain.enable|disable` (domain ∈ panel vhosts; vhost-scoped)
- `mail.mailbox.add|list|passwd|disable|del`
- `mail.alias.add|list|del`
- `mail.dns.records` (SPF/DKIM/DMARC/MX text + live lookup; mode-aware)
- `mail.dkim.rotate`
- `mail.smarthost.set|clear|test` (first-class)
- `mail.probe.outbound25`
- `mail.test.send` (confirm)
- `mail.relay.selftest` (open-relay check)

**CLI (v1, full UI parity):** `azerioid mail status|hostname|domain|mailbox|alias|dns|smarthost|queue|test|…` — thin wrappers over the broker actions above. `azerioid mail status` **must** report the same direct-vs-blocked plain-language status as the UI health strip.

---

## 7. UI sketch — “Mail” sidebar page (v1)

Not wireframes — feature list at the same fidelity as prior component proposals.

### 7.1 Entry points

- **Components:** install/remove/status for `mail` (same card pattern as Redis/MariaDB/Adminer). Foreign MTA → typed `REPLACE-MTA` before proceed.
- **Settings (or Mail setup):** set **mail hostname** (required explicit value) before domain enable.
- **Sidebar “Mail”:** only interactive when the component is installed; otherwise deep-link to Components with install CTA.
- **Settings (panel alerts):** optional opt-in “send panel alerts via local mail component” — **off by default**; never auto-enabled on mail install.

### 7.2 Page sections

1. **Health strip** (see §7.4 — outbound delivery status is **mandatory**, not optional)  
   Postfix / Dovecot / OpenDKIM active? Open-relay self-test pass/fail? **Direct mail delivery available vs blocked** (live probe)? Smarthost configured? PTR match warning?

2. **Outbound / smarthost**  
   Visible configuration for relay host, port, username, password (write-once reveal), TLS mode. Primary CTA when probe is blocked (§7.4). Test connection action.

3. **Domains**  
   Table of panel vhost domains with mail enabled flag. Action: Enable mail for domain (runs outbound-25 probe **first**, then generates DKIM + shows DNS checklist). Disable (confirm if mailboxes exist). Vhost delete elsewhere must refuse while mail data exists unless drop confirm.

4. **DNS checklist (per domain)**  
   MX, A/AAAA, SPF, DKIM, DMARC — copy buttons + live lookup badges (green/red/unknown). SPF text adjusts for direct vs smarthost. Link to provider PTR instructions (generic).

5. **Mailboxes**  
   Filter by domain. Create (localpart + generated password once). Reset password. Disable. Delete (confirm). **No quota UI** (hardcoded max message size only).

6. **Aliases**  
   Simple list; create/delete.

7. **Queue**  
   Counts + deferred sample; “Flush deferred” with confirm; link/snippet of recent mail log (PII-aware truncation of message bodies).

8. **Test send**  
   Modal: from mailbox, to address, subject — exercises the **active** outbound path (direct or smarthost). Direct mode: local OpenDKIM authenticates the message. Smarthost mode: the relay’s domain-authenticated DKIM (after completing the provider’s DNS setup) is what receivers use for DMARC; the panel may still add a local signature, but it can legitimately fail body-hash verification if the relay modifies content (§3.5).

### 7.3 What the UI must say up front

- Mail is **opt-in** and **reputation-sensitive**.
- Operator **must** set the mail hostname, publish DNS, and set **PTR** at the VPS provider.
- Cloud providers often block outbound SMTP — the health strip will say so plainly and push smarthost setup.
- Reload/restart of Postfix after map changes is broker-owned (operator does not edit `main.cf`).
- No webmail in v1 — use any IMAP client (Apple Mail, Thunderbird, etc.).

### 7.4 Core UX: outbound port-25 / smarthost messaging (not optional)

This is **core v1 UX**, not a buried warning. Implementation must not downgrade it to a log line or “Advanced” footnote — fleet testing showed provider blocks are the common case operators will hit even when a particular test droplet happens to be open.

**Probe:** Same pattern as the diagnostic task — timed TCP connect (and preferably SMTP banner read) to a well-known external MX on port 25 (e.g. `gmail-smtp-in.l.google.com:25`). Broker exposes the result on `mail.status` / `mail.probe.outbound25`. Cache briefly if needed, but allow refresh; never invent “open” without a successful probe.

**Health strip copy (plain language):**

| Probe result | Status presentation | Operator action |
|--------------|---------------------|-----------------|
| Success | **“Direct mail delivery: available”** (green) | Optional: still allow configuring smarthost if preferred |
| Failure / timeout / filtered | **“Direct mail delivery: blocked by your provider — configure a relay to send mail”** (amber/red) | **Obvious CTA immediately beside the status** → “Configure relay…” opens smarthost setup (not buried in advanced settings) |

**Setup flow:** When enabling mail for a domain (and during initial component setup), run this probe **before** the operator is deep into DNS/DKIM steps. Surface the result immediately so they know up front whether a relay is required for outbound send. Inbound MX receive can still be configured either way; outbound without smarthost when blocked must not silently “succeed” in the UI.

**CLI:** `azerioid mail status` reports the same direct-vs-blocked wording (and whether a smarthost is configured), suitable for operators who never open the Mail page.

**Smarthost authentication messaging:** When a relay is configured, health-strip / test-send copy must **not** imply that “your” (local OpenDKIM) signature is what authenticates mail at the destination. Say that the **relay’s** domain authentication carries DMARC once the provider’s DKIM/SPF/branding records are published; note that local signatures may show `neutral`/fail after a content-modifying relay without indicating a panel defect (§3.5). Direct-mode copy may still speak to local DKIM.

---

## 8. Implementation phasing (after this approved spec)

Suggested engineering slices for the future implementation task:

| Slice | Deliverable |
|-------|-------------|
| M0 | ~~Open questions~~ — **done**; start from this document |
| M1 | Registry + install/uninstall + EPEL + SELinux/firewall + **always** `REPLACE-MTA` |
| M2 | Hardened Postfix/Dovecot baseline + open-relay self-test + **outbound-25 probe** + mail hostname setting + TLS material selection |
| M3 | Virtual domain/mailbox/alias maps (vhost-scoped) + IMAPS/submission + inbound Maildir |
| M4 | OpenDKIM + DNS records UI/CLI (mode-aware SPF) |
| M5 | **Smarthost first-class path** + health-strip/CLI messaging both states + real test send via external relay; copy distinguishes relay DKIM (deliverability) from local OpenDKIM (may fail after content-modifying relays) |
| M6 | Queue/logs + fleet proof (Ubuntu, Debian, Alma, Rocky, CentOS) |
| M7 | Docs (README + port-ownership) + PHPUnit/broker tests + Settings opt-in for panel alerts via local mail |

---

## 9. Resolved decisions (locked)

All former open questions are **decided**. No further operator input is required before an implementation task may begin.

1. **Mailbox tenancy:** **Vhost-scoped** (1:1 with a panel-managed domain). Deleting a vhost **refuses** while mailboxes exist unless `--drop-mail` / typed confirm.
2. **CLI namespace:** **`azerioid mail …` in v1**, full parity with the UI — not deferred.
3. **Inbound in v1:** **Full send + receive** (MX → Maildir) ships together.
4. **Smarthost / relay mode:** **First-class v1 path**, equal to direct MX delivery. Live outbound-25 probe selects messaging and setup CTA; both paths supported. Rationale: confirmed open on test droplet `64.226.78.176`, but DO (and peers) document default SMTP blocks on newer accounts — most real operators will need relay.
5. **EPEL for OpenDKIM on EL:** **Accepted** dependency (CRB if required by the distro).
6. **Mail hostname:** **Dedicated explicit panel setting** (A22-style), not derived from first domain or bare FQDN.
7. **TLS certificates:** Prefer **reuse** of existing Caddy/Let’s Encrypt material for a panel-managed hostname when available; else **dedicated certbot** cert for the mail hostname.
8. **Foreign MTA policy:** **Always** require typed `REPLACE-MTA` — **no** Debian-Exim auto-replace exception.
9. **Panel notifications via local Postfix:** **Decoupled by default.** Laravel mailer keeps the operator’s existing external SMTP unless they **explicitly opt in** via Settings. Never auto-switch (avoids circular dependency if mail is down).
10. **Quotas / max message size:** **Hardcoded** sensible defaults (e.g. **25 MiB** max message); **no** quota-management UI in v1.

---

## 10. Acceptance criteria for the future implementation task

The implementation task is done when:

- [ ] Component installs cleanly on Ubuntu, Debian, Alma, Rocky, CentOS Stream (SELinux Enforcing); EL OpenDKIM via EPEL.
- [ ] Foreign MTA removal requires typed `REPLACE-MTA` (including Debian default Exim).
- [ ] Unauthenticated external relay attempt fails; submission without auth fails; SASL+TLS submission succeeds.
- [ ] Mailbox + alias CRUD works (vhost-scoped); IMAPS login works; inbound + outbound round-trip for an enabled domain with correct DNS (direct path where :25 is open).
- [ ] Explicit mail hostname setting required; TLS prefers Caddy/LE material then certbot fallback.
- [ ] Panel shows accurate SPF/DKIM/DMARC/MX guidance. **Direct mode:** local OpenDKIM signature verifies end-to-end on an outbound test. **Smarthost mode:** relay domain authentication (provider DKIM aligned with `From:`) is the acceptance bar for deliverability/DMARC; a local OpenDKIM body-hash `neutral`/fail after a content-modifying relay is expected and not a failure (§3.5). UI/CLI copy must not claim local DKIM is what authenticates mail once smarthost is active.
- [ ] **Outbound-25 probe** correctly detects open and blocked states; UI health strip + `azerioid mail status` show the mandated plain-language copy; blocked state shows a prominent **Configure relay** CTA. Proven on at least one host with :25 open and one blocked (or simulated block) so both UI states are verified.
- [ ] **Smarthost/relay** works end-to-end (real test send via a real external relay provider) as a first-class path — not a fallback hack. Proven path may show DMARC pass via the relay’s aligned DKIM even when the panel’s local selector fails body-hash verification.
- [ ] Vhost delete refuses while mailboxes exist unless `--drop-mail` / typed confirm.
- [ ] Panel alert mail remains on external SMTP unless Settings opt-in.
- [ ] Max message size default applied; no quota UI.
- [ ] PHPUnit + broker unit coverage for map generation / policy refuses / probe messaging; no secrets in argv or audit bodies.
- [ ] `docs/port-ownership.md` + README updated; CLI help lists `azerioid mail …`.

---

## 11. Decision summary (ADR-facing)

| Topic | Decision |
|-------|----------|
| MTA | Postfix |
| Aux | Dovecot (SASL + IMAPS), OpenDKIM (signing; EPEL on EL) |
| Maps | Broker-generated files — no DB engine dependency |
| Tenancy | Vhost-scoped; delete refuse without `--drop-mail` |
| Auth model | Submission 587/465 only; never AUTH on 25 |
| Outbound | Direct **and** smarthost — both first-class; live :25 probe + mandatory UI/CLI messaging |
| DNS | Generate + verify guidance; operator publishes |
| Hostname / TLS | Explicit mail hostname setting; prefer Caddy/LE then certbot |
| Foreign MTA | Always `REPLACE-MTA` |
| Panel alerts | Decoupled; Settings opt-in only |
| CLI | `azerioid mail …` in v1 |
| v1 product | Mailboxes/aliases, full send+receive, queue, diagnostics, smarthost — **no** webmail / spam UI / lists / quota UI |
| Next step | Separate **implementation** task against this approved spec |
