# Security policy

AZERIOID Stack Manager runs a root-privileged broker on the hosts it manages, so security reports are
taken seriously and handled before anything else.

## Reporting a vulnerability

Please **do not** open a public issue, pull request or discussion for a security problem.

Report it privately through GitHub: on this repository, open the **Security** tab and choose
**Report a vulnerability** ([direct link](https://github.com/azerioid/azerioid-stack-manager/security/advisories/new)).
That creates a private advisory that only the maintainer can see.

Useful to include:

- the version (`cat /usr/local/lib/azerioid-panel/VERSION`, or `azerioid panel update check`) and the OS;
- what a lower-privileged party (a hosted site, its PHP code, a local account, a network client) can do
  that it should not, and the steps to reproduce it;
- whether you have tested it on a host you own.

You should get an acknowledgement within a few days. Fixes are released as a new patch version with an
entry in [`docs/DECISIONS.md`](docs/DECISIONS.md); the release notes say when updating is recommended.
Precise exploitation details may be held back for a short window after a fix ships, so operators can
update first, and are then published.

## Supported versions

Only the **latest release** receives security fixes. The panel updates itself in place
(`azerioid panel update check`, then `azerioid panel update apply --confirm`), so staying current is the
supported path; older lines are not patched.

| Version | Supported |
|---------|-----------|
| Latest `v2.x` release | Yes |
| Anything older | No — update first |

## Scope

In scope: the panel (`web/`), the broker (`broker/`), the installer and uninstaller (`deploy/`,
`stack-manager.sh`) and the component definitions in `registry/`.

Out of scope: vulnerabilities in the upstream software the panel installs (Caddy, PHP, databases, Docker,
…) — report those upstream — and findings that need root on the host already.
