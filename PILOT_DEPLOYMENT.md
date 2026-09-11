# Gäld private Tailscale deployment

This runbook describes the private single-instance stack in
`compose.production.yml`. It is based on upstream release `v3.8.14` and
currently runs the project-specific images `gaeld/app:v3.8.6-ideall.14` and
`gaeld/web:v3.8.6-ideall.14`.

## Image tags

Read `v3.8.6-ideall.14` as two independent numbers. `v3.8.6` was the upstream
release this deployment forked from, and `.14` is a local build counter that
increments once per image build. They are unrelated: `…-ideall.13` has nothing
to do with upstream release `v3.8.13`, and both existed at the same time.

The base is now stale — the branch merged upstream up to `v3.8.14` on
11 September 2026, so the `v3.8.6` in the tag no longer describes the code.
Rename the next build to `v3.8.14-ideall.1` and carry the counter on from
there, or the same confusion returns.

Keep `GAELD_IMAGE_TAG` in `.env.production` in step with what is actually
running. It read `v3.8.6-ideall.11` while the containers ran `.13`, and since
`.11` was still present locally, a plain `up -d` would have rolled production
back two builds without a word. Check before starting the stack:

```bash
docker compose --env-file .env.production -f compose.production.yml images
grep GAELD_IMAGE_TAG .env.production
```

The deployment source is `/home/gmk/Gaeld`; there is no second checkout.
The compose project is named `gaeld` in the compose file itself, so never
pass `-p` — it would override that name and detach the stack from its
explicitly named volumes.

The host port remains intentionally bound to `127.0.0.1:8088`. Normal remote
access is provided by Tailscale Serve at
`https://gmk.tailc7653b.ts.net`, which is available only inside the tailnet.
Nginx also joins the existing `proxy` network for the internal
`gaeld.home.arpa` Traefik route. PostgreSQL, Redis and PHP-FPM are not exposed
to the host network.

## Services

- `nginx`: local HTTP endpoint
- `app`: PHP-FPM application
- `horizon`: Redis queue processing, including OCR and scheduled queues
- `scheduler`: Laravel scheduled tasks and Horizon metric snapshots
- `postgres`: persistent PostgreSQL database
- `redis`: persistent cache, sessions, queues and Horizon state

The Redis queue reservation is set to 900 seconds so it remains longer than
the application's longest queued-job timeout (600 seconds).

Meilisearch is disabled and search uses the database.
Outbound mail is delivered over SMTP through mail.cyon.ch on port 587 with
STARTTLS, sending as noreply@gaeld.ideall.ch (configured 8 September 2026).

## Configuration

Create the untracked runtime configuration:

```bash
cp .env.production.pilot.example .env.production
chmod 600 .env.production
```

Before starting anything, replace both `CHANGE_ME` passwords and populate
`APP_KEY` with a valid Laravel base64 key. Do not commit `.env.production`.

Every Compose command must use the runtime environment file for both Compose
interpolation and container configuration:

```bash
docker compose --env-file .env.production -f compose.production.yml config
```

To validate the package without creating a runtime configuration, point the
service-level environment file at the checked-in example explicitly:

```bash
GAELD_ENV_FILE=.env.production.pilot.example \
  docker compose --env-file .env.production.pilot.example \
  -f compose.production.yml config --quiet
```

## Build and start

Build and start commands change the local Docker runtime and must be run
deliberately:

```bash
docker compose --env-file .env.production -f compose.production.yml build
docker compose --env-file .env.production -f compose.production.yml up -d --wait
```

Install Gaeld interactively without demo data:

```bash
docker compose --env-file .env.production -f compose.production.yml exec app php artisan gaeld:install
```

Run the self-hosting diagnostic:

```bash
docker compose --env-file .env.production -f compose.production.yml exec app php artisan gaeld:doctor
```

## Status and logs

```bash
docker compose --env-file .env.production -f compose.production.yml ps
docker compose --env-file .env.production -f compose.production.yml logs --tail=200 app nginx horizon scheduler
```

Mail is sent through the queue, so delivery problems appear in the `horizon`
log and in `php artisan queue:failed`. Tinker is not installed in the
production image, so test delivery by triggering a password reset for a known
account, or by copying a short script into the container and running it with
`php`.

Setting `MAIL_MAILER=log` in `.env.production` switches delivery back to the
application log without removing the SMTP credentials.

## Access

From an authorized tailnet device, open:

```text
https://gmk.tailc7653b.ts.net
```

Tailscale Serve terminates HTTPS and proxies to `http://127.0.0.1:8088`.
This is the intended remote access path and is not a public Internet release.

On the server, open `http://localhost:8088`.

An SSH tunnel remains available as a fallback:

```bash
ssh -L 8088:127.0.0.1:8088 gmk@SERVER_IP
```

Then open `http://localhost:8088` on that workstation.

The REST API under `/api/v1` is enabled and uses the same private access path.
See `/home/gmk/Documents/gaeld-api-anleitung.md` for token handling and request
examples.

`TRUSTED_PROXIES=*` is currently functional because the application port is
not publicly exposed, but it is broader than necessary. Pin the relevant
Docker networks or proxy addresses and then restrict this setting to the
Tailscale/Docker gateway hop and, while the internal route is used, Traefik.

## Migrations and updates

Apply database migrations without prompting:

```bash
docker compose --env-file .env.production -f compose.production.yml exec app php artisan migrate --force
```

The source revision and image tag must be changed deliberately. Watchtower is
disabled on all Gaeld containers.

After building an explicitly selected update, run:

```bash
docker compose --env-file .env.production -f compose.production.yml exec app php artisan gaeld:update
docker compose --env-file .env.production -f compose.production.yml exec horizon php artisan horizon:terminate
```

Do not use `git pull` as an update policy and do not use moving or `latest`
tags for Gaeld images.

## Stop and removal

Stop the deployment without deleting its persistent data:

```bash
docker compose --env-file .env.production -f compose.production.yml stop
```

Removing volumes with `down --volumes` deletes the database and stored files.
That operation is intentionally not part of this runbook.

## Remaining operational work

No automated external backup is configured yet. Local pre-change backups with
checksums exist, but they do not protect against loss of the server. Before
relying on the instance for non-reproducible or business-critical records,
configure encrypted off-server backups and complete a documented restore test.

A public Internet domain is optional and intentionally deferred. If access
without Tailscale is required later, treat public DNS, TLS, reverse-proxy
hardening, monitoring and the go-live rollback path as a separate change.
