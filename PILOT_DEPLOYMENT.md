# Gäld single-instance deployment

This runbook describes how to operate the single-instance stack in
`compose.production.yml`: how images are tagged and built, how the stack is
started and stopped, and how updates are applied.

It deliberately names no host, address or version of any particular
installation. Which release an instance runs, how it is reached and how its
proxies are configured are properties of that installation, not of this
package — they belong in the operator's own notes, kept outside this
repository. This file went stale twice by recording them anyway.

## Image tags

Read `v3.8.6-ideall.14` as two independent numbers. `v3.8.6` was the upstream
release this deployment forked from, and `.14` is a local build counter that
increments once per image build. They are unrelated: `…-ideall.13` has nothing
to do with upstream release `v3.8.13`, and both existed at the same time.

Neither number is typed any more. `scripts/build-production.sh` derives both
from git — the base from the nearest tag, the counter from the commits since it
— writes `GAELD_IMAGE_TAG` into `.env.production` itself, and builds:

```bash
scripts/build-production.sh --print   # show the tag, build nothing
scripts/build-production.sh           # derive, record, build
```

So the base follows the next upstream merge on its own, and the counter follows
every commit. Expect the counter to jump when an installation switches from
hand-typed tags to derived ones — the derived counter counts every commit since
the base tag, not the builds someone remembered to number. A number nobody has
to remember beats a tidy one.

To see what an installation actually runs, ask it, rather than trusting a
number written down somewhere:

```bash
grep '^GAELD_IMAGE_TAG=' .env.production
docker compose --env-file .env.production -f compose.production.yml images
```

The script refuses to build from a dirty working tree — a tag names a commit,
and an uncommitted change is in no commit. Use `--allow-dirty` for a throwaway
build; it marks the tag `.dirty` so it cannot be mistaken for a releasable one.

Each image also carries where it came from, which a tag alone cannot prove:

```bash
docker image inspect gaeld/app:<tag> \
    --format '{{index .Config.Labels "org.opencontainers.image.revision"}}'
```

Images built before these labels were introduced report `unknown`; identifying
those means hashing their source tree against git.

## Before starting the stack

`GAELD_IMAGE_TAG` once read `v3.8.6-ideall.11` while the containers ran `.13`,
and since `.11` was still present locally, `up -d` would have rolled production
back two builds without a word. Compose has no opinion about which direction a
tag moves; this does:

```bash
scripts/check-production-tag.sh
```

It fails when the configured image is missing, and when it is older than the
one currently running. Run it before every `up -d`.

Deploy from a single checkout. A second one drifts, and the tag guard above
can only compare what it is pointed at. The compose project is named `gaeld`
in the compose file itself, so never pass `-p` — it would override that name
and detach the stack from its explicitly named volumes.

The host port stays bound to `127.0.0.1:8088` deliberately. Nginx additionally
joins an external `proxy` network so a reverse proxy can reach it without any
port leaving the loopback interface. PostgreSQL, Redis and PHP-FPM are never
exposed to the host network.

## Services

- `nginx`: local HTTP endpoint
- `app`: PHP-FPM application
- `horizon`: Redis queue processing, including OCR and scheduled queues
- `scheduler`: Laravel scheduled tasks and Horizon metric snapshots
- `postgres`: persistent PostgreSQL database
- `redis`: persistent cache, sessions, queues and Horizon state

The Redis queue reservation is set to 900 seconds so it remains longer than
the application's longest queued-job timeout (600 seconds).

### Compiled views are not state

`storage` is a named volume that outlives the image, and `opcache.validate_timestamps`
is off in production, so PHP never rechecks a file it has already compiled. Together
those two turned Laravel's compiled Blade views into a trap: a view compiled by an
earlier release survived the deploy under the same hashed filename, opcache loaded it
on the first request, and the new release's markup never appeared — no error, no log
line, and `view:clear` does not help, because the stale bytecode is in memory, not on
disk. It cost an afternoon on 12 September 2026, over a Blade conditional that had
deployed correctly all along.

The three app services therefore mount `storage/framework/views` as a per-container
tmpfs (32 MB, mode 1777 — the container runs as www-data and cannot chown a tmpfs).
It starts empty, the entrypoint's `view:cache` fills it from the image that is
actually running, and nothing survives into the next deploy. Uploads, the file cache
and the logs stay in `gaeld-storage` where they belong.

If the output of a deploy ever looks older than the code again, recreate the
containers — `restart` keeps the mounts but a changed compose definition needs
`up -d` — rather than clearing caches inside a running one.

Meilisearch is disabled and search uses the database.
Outbound mail is delivered over SMTP; host, port, encryption and sender come
from `MAIL_*` in `.env.production`. Queued mail leaves through Horizon, so a
broken SMTP setting surfaces as failed jobs rather than as a request error.

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

The stack itself publishes exactly one port: Nginx on `127.0.0.1:8088`. The
database, Redis and PHP-FPM have no host port at all. Everything else — a
tunnel, a reverse proxy, a VPN — sits in front of that one port and is the
installation's business, not this package's.

On the server, open `http://localhost:8088`. From a workstation, an SSH tunnel
needs no other route to exist:

```bash
ssh -L 8088:127.0.0.1:8088 USER@SERVER
```

Then open `http://localhost:8088` there.

The REST API under `/api/v1` is enabled and reachable over every route the
installation provides.

Whatever terminates TLS in front of Nginx, set `TRUSTED_PROXIES` to that hop's
address or network and no wider. `*` tells the application to believe any
`X-Forwarded-For` it receives, which lets a caller dictate its own client
address — rate limits, audit entries and session binding all follow that
address. If the port is reachable from outside the host at all, a wildcard is
a vulnerability, not a convenience.

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

## Backups

Local pre-change dumps protect against a bad migration. They do not protect
against loss of the server, because they live on it. Before an installation
carries records that cannot be reproduced, configure encrypted off-server
backups and complete a documented restore test — a backup nobody has restored
is a hypothesis.

`scripts/backup-sync.sh` handles the off-server copy; per-installation state
and open work belong in the operator's own notes, not here.
