# staff-portal-lab — intentionally vulnerable WordPress lab

> ⚠️ **For authorized security training / coursework use only.**
> This application is deliberately insecure. Never deploy it on a public network,
> never reuse its code, and never point it at production data.
> Use only in an isolated, local environment (personal VM, internal CTF range,
> or your own Docker host) as part of an authorized assignment.

A small WordPress-based **Staff Portal** CMS built with Docker Compose. Staff log
in to view internal announcements, notices, and downloadable resources;
administrators manage pages, publish announcements, and install portal template
packs through the dashboard.

The application ships with several deliberately introduced security flaws for you
to discover and exploit. Your objective is to work from an unauthenticated
starting point through to remote code execution on the server.

## Tech Stack

WordPress · PHP · MySQL · Apache · Docker Compose

## What this lab covers

Without spoiling *where* they live, the lab exercises four vulnerability classes:

1. Username / account enumeration
2. Sensitive file exposure via server misconfiguration
3. Broken access control (privilege escalation)
4. Unrestricted file upload leading to remote code execution

These are designed to **chain** — each finding feeds the next.

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

To reset the lab to a clean state at any time:

```bash
docker compose down -v
docker compose up -d --build
```

## Your Goal

Start with no credentials and see how far you can get. A complete solve chains
all four issues together, ending in code execution as the web-server user.

Try to get as far as you can on your own first. If you get stuck, or you're
grading/reviewing the lab, see **[SOLUTION.md](SOLUTION.md)** — it contains
progressive hints (reveal one nudge at a time) followed by full walkthroughs and
remediation notes for every stage.

## Repo Layout

```text
wordpress/
├── docker-compose.yml
├── seed/                             # seed data loaded during provisioning
├── scripts/
│   └── wp-setup.sh                   # wp-cli provisioning, runs once
└── wordpress/
    ├── Dockerfile
    ├── conf/                         # Apache configuration
    ├── mu-plugins/                   # must-use plugins (auto-loaded)
    └── plugins/
        └── staff-portal-manager/     # custom portal management plugin
```

## Notes

- The lab is fully self-provisioning; the `setup` container creates all accounts,
  content, and seed files, then exits.
- Everything runs locally and is disposable — `docker compose down -v` wipes all
  state so you can start fresh.
- Instructor / grading notes, credentials, and the intended solution path live in
  **[SOLUTION.md](SOLUTION.md)**, kept separate so they aren't visible at a glance.
