# staff-portal-lab — intentionally vulnerable WordPress lab

> ⚠️ **For authorized security training / coursework use only.**
> This application is deliberately insecure. Never deploy it on a public network,
> never reuse its code, and never point it at production data.
> Use only in an isolated, local environment (personal VM, internal CTF range,
> or your own Docker host) as part of an authorized assignment.

A small WordPress-based Staff Portal CMS built with Docker Compose, containing
four chained vulnerabilities commonly found in real-world WordPress deployments.

## Tech Stack

WordPress · PHP · MySQL · Apache · Docker Compose

## Vulnerabilities

| # | Vulnerability | Location |
|---|---|---|
| 1 | Username enumeration via `xmlrpc.php` | mu-plugin: `staffportal-xmlrpc-enum` |
| 2 | Directory listing exposing a backup archive with leaked credentials | Apache config |
| 3 | Privilege escalation via missing capability check | Plugin: `staff-portal-manager` |
| 4 | Insecure plugin upload → RCE | Plugin: `staff-portal-manager` |

## Core Features (intentionally broken)

- **Enumerate** valid staff usernames unauthenticated via a custom XML-RPC method
- **Browse** an exposed directory index to retrieve a backup archive containing plaintext credentials
- **Escalate** from a low-privilege staff account to admin-level content actions via a missing `current_user_can()` check
- **Execute** arbitrary PHP on the server by uploading a malicious plugin zip through an unvalidated, unauthenticated endpoint

## Quick Start

```bash
git clone https://github.com/alden195/wordpress
cd wordpress
docker compose up -d --build
```

Wait ~1–2 minutes for the one-shot `setup` container to provision WordPress:

```bash
docker compose logs -f setup
```

Then browse to: **http://localhost:8080**

Admin credentials *(for grading / resetting — not part of the intended attack path)*:
`admin / AdminP@ssw0rd!`

To reset everything:

```bash
docker compose down -v
docker compose up -d --build
```

## Intended Exploitation Chain

1. **Enumerate usernames via XML-RPC.**
   The mu-plugin registers `staffportal.checkUser` on `/xmlrpc.php`. It returns a
   success string for valid usernames and a distinct `404` fault for invalid ones —
   an unauthenticated attacker can wordlist through this to identify staff accounts.

2. **Find leaked credentials via directory listing.**
   `wp-content/uploads/backups/` has directory indexing enabled. Browsing to it
   reveals `portal_backup_2024-03.zip`, which contains a valid low-privilege login:
   `jsmith / Summer2024!`

3. **Exploit broken access control.**
   `POST /wp-admin/admin-ajax.php` with `action=staffportal_publish_announcement`
   lets any logged-in user publish content normally restricted to admins/editors —
   the handler only checks `is_user_logged_in()`, not `current_user_can()`.

4. **Achieve RCE via insecure plugin upload.**
   `action=staffportal_import_template` accepts an arbitrary `.zip`, extracts it
   into `wp-content/plugins/`, and auto-activates the uploaded plugin — all with
   only a login check, no nonce, no capability check, no content validation.
   A malicious plugin containing a PHP web shell executes as the web server user.
   The extracted PHP is also directly reachable at
   `http://localhost:8080/wp-content/plugins/<pack>/<shell>.php`.

## Repo Layout

```
wordpress/
├── docker-compose.yml
├── seed/
│   └── portal_backup_2024-03.zip     # leaked backup (vuln #2)
├── scripts/
│   └── wp-setup.sh                   # wp-cli provisioning, runs once
└── wordpress/
    ├── Dockerfile
    ├── conf/
    │   └── directory-listing.conf    # enables Options +Indexes (vuln #2)
    ├── mu-plugins/
    │   └── staffportal-xmlrpc-enum.php   # vuln #1
    └── plugins/
        └── staff-portal-manager/
            └── staff-portal-manager.php  # vulns #3 and #4
```

## Notes for Write-up / Grading

- The plugin source has inline comments marking each bug (`Bug 1` / `Bug 2`) for root-cause reporting.
- Stock WordPress XML-RPC does **not** leak usernames — the mu-plugin is what makes vuln #1 deterministic.
- Credentials in `seed/portal_backup_2024-03.zip` and the `jsmith` WordPress account are kept in sync by `wp-setup.sh`.
- For a harder variant, swap `subscriber` for a custom `Staff` role — the plugin bugs fire regardless of role, only requiring the user to be logged in.
