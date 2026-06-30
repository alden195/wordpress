# Staff Portal — Intentionally Vulnerable WordPress Lab

> ⚠️ **For authorized security training / coursework use only.**
> This application is deliberately broken. Never deploy it on a public
> network, never reuse its code, and never point it at production data.
> By using this repo you agree it is for isolated, local/lab use
> (e.g. a personal VM, an internal CTF range, or your own Docker host)
> as part of an authorized assignment.

## What this is

A small WordPress-based "Staff Portal" CMS, built with Docker Compose,
containing four chained vulnerabilities commonly seen in real-world
WordPress deployments:

| # | Vulnerability | Where |
|---|---|---|
| 1 | Username enumeration via `xmlrpc.php` | mu-plugin: `staffportal-xmlrpc-enum` |
| 2 | Directory listing exposing a backup archive with leaked creds | Apache config |
| 3 | Privilege escalation via missing capability check | Custom plugin: `staff-portal-manager` |
| 4 | Insecure/unrestricted plugin upload → RCE | Custom plugin: `staff-portal-manager` |

## Quick start

```bash
git clone <this-repo>
cd staff-portal-lab
docker compose up -d --build
```

Wait ~1–2 minutes for the one-shot `setup` container to provision
WordPress (watch with `docker compose logs -f setup`). Then browse to:

```
http://localhost:8080
```

Admin creds (for grading / resetting the lab, not part of the intended
attack path): `admin / AdminP@ssw0rd!`

To reset everything:
```bash
docker compose down -v
docker compose up -d --build
```

## Intended exploitation chain

1. **Enumerate usernames via XML-RPC.**
   The mu-plugin registers a custom XML-RPC method,
   `staffportal.checkUser`, on `/xmlrpc.php`. It returns a success
   string (`KNOWN_STAFF_ACCOUNT: <user>`) for valid usernames and a
   distinct `404` fault for invalid ones. An unauthenticated attacker
   loops a wordlist through this method (single calls or a
   `system.multicall` batch) and observes the response difference to
   build a list of valid staff accounts (`jsmith`, `akumar`, `mlee`,
   ...).

   > Why a custom method? Stock WordPress XML-RPC does **not** leak
   > this — `wp.getUsersBlogs` and friends return the same generic
   > "Incorrect username or password" fault for both bad usernames and
   > bad passwords. The mu-plugin is what makes the enumeration
   > deterministic and genuinely XML-RPC-based.

2. **Find leaked credentials via directory listing.**
   `wp-content/uploads/backups/` has directory indexing enabled.
   Browsing to `http://localhost:8080/wp-content/uploads/backups/`
   reveals `portal_backup_2024-03.zip`, which contains
   `credentials.txt` with a valid low-privilege staff login:
   `jsmith / Summer2024!`.

3. **Log in as `jsmith`** (a `subscriber`-level account) at
   `/wp-login.php` or via cookie auth, then exploit the **broken
   access control** in the bundled plugin:
   - `POST /wp-admin/admin-ajax.php` with
     `action=staffportal_publish_announcement` lets *any logged-in
     user* publish content normally restricted to admins/editors,
     because the handler only checks `is_user_logged_in()`.

4. **Escalate to RCE via insecure plugin upload.**
   The same plugin exposes
   `action=staffportal_import_template` on `admin-ajax.php`, which
   accepts an arbitrary `.zip` upload and extracts it straight into
   `wp-content/plugins/` — again with only a login check, no
   capability check, no nonce, no content validation. It then
   **auto-activates** any plugin the upload introduced, so a
   subscriber-level user (who has no `activate_plugins` capability and
   couldn't use the normal Plugins screen) still gets their plugin
   running. An attacker zips up a minimal plugin containing a PHP web
   shell, uploads it as `jsmith`, and code executes as the web server
   user. Even without activation, the extracted PHP is directly
   reachable at
   `http://localhost:8080/wp-content/plugins/<pack>/<shell>.php`.

This mirrors real CVEs in the WordPress plugin ecosystem (missing
`current_user_can()` checks on `admin-ajax.php` actions, and
unrestricted plugin/theme upload functionality), similar in spirit to
labs like [envizon](https://github.com/evait-security/envizon).

## Repo layout

```
staff-portal-lab/
├── docker-compose.yml
├── seed/
│   └── portal_backup_2024-03.zip   # leaked backup (planted vuln #2)
├── scripts/
│   └── wp-setup.sh                 # wp-cli provisioning, runs once
└── wordpress/
    ├── Dockerfile                  # enables Options +Indexes, installs wp-cli
    ├── conf/
    │   └── directory-listing.conf
    ├── mu-plugins/
    │   └── staffportal-xmlrpc-enum.php   # vuln #1
    └── plugins/
        └── staff-portal-manager/
            └── staff-portal-manager.php   # vulns #3 and #4
```

## Notes for write-up / grading

- The plugin source has inline comments marking exactly where each bug
  lives (`Bug 1` / `Bug 2`), useful if you need to point to root cause
  in your report.
- `XMLRPC_ENABLED` is set in `wp-config.php` for flavour, but note it
  is **not** a constant WordPress core actually reads — `xmlrpc.php` is
  enabled by default regardless. What makes vuln #1 deterministic is
  the `staffportal-xmlrpc-enum` mu-plugin, which adds a custom method
  whose response distinguishes valid from invalid usernames.
- Credentials in `seed/portal_backup_2024-03.zip` and the `jsmith`
  WordPress account are kept in sync by `wp-setup.sh` so the planted
  "leak" is always a valid, working login.
- If you want a harder variant, swap `subscriber` for a custom "Staff"
  role with a couple of extra capabilities — the plugin bugs don't
  care which role triggers them, only that the user is logged in.
