# Gaeld local pilot deployment

This runbook describes the local-only pilot stack in `compose.production.yml`.
It is pinned to Gaeld commit `7ca55186f59fe50e6f8eafc35146f552070b702d`.

The stack is intentionally reachable only on `127.0.0.1:8088`. It does not
use the server's existing Traefik network and does not expose PostgreSQL or
Redis to the host network.

## Services

- `nginx`: local HTTP endpoint
- `app`: PHP-FPM application
- `horizon`: Redis queue processing, including OCR and scheduled queues
- `scheduler`: Laravel scheduled tasks and Horizon metric snapshots
- `postgres`: persistent PostgreSQL database
- `redis`: persistent cache, sessions, queues and Horizon state

The Redis queue reservation is set to 900 seconds so it remains longer than
the application's longest queued-job timeout (600 seconds).

Meilisearch and SMTP delivery are disabled for the initial pilot. Search uses
the database and outbound mail is written to the application log.

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

These commands belong to Phase 3 or later and must not be run during Phase 2:

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

Application mail can be inspected in the `app` and `horizon` logs while
`MAIL_MAILER=log` is active.

## Local access

On the server, open `http://localhost:8088`.

From another workstation, create an SSH tunnel:

```bash
ssh -L 8088:127.0.0.1:8088 gmk@SERVER_IP
```

Then open `http://localhost:8088` on that workstation.

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

Stop the pilot without deleting its persistent data:

```bash
docker compose --env-file .env.production -f compose.production.yml stop
```

Removing volumes with `down --volumes` deletes the database and stored files.
That operation is intentionally not part of this runbook.

## Pilot limitation

No external backup is configured yet. Until backup and restore have been
implemented and tested, only test or otherwise reproducible data may be kept
in this installation and it must not be treated as a public production system.
