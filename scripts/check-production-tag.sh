#!/usr/bin/env bash
#
# Preflight for `docker compose ... up -d`: does GAELD_IMAGE_TAG describe what
# you actually mean to run?
#
# On 2026-09-11 .env.production read v3.8.6-ideall.11 while the containers ran
# .13, and .11 was still present locally — so 'up -d' would have started an image
# from two builds earlier and said nothing about it. Compose has no opinion about
# whether a tag moves forward or backward; this script does.
#
# Exit status: 0 when the configured tag is present and is not older than what is
# running, 1 otherwise.

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${GAELD_ENV_FILE:-.env.production}"
COMPOSE_FILE="compose.production.yml"

# Compose prefers the shell environment over --env-file when interpolating, so
# resolve the tag the same way it will: environment first, file second.
configured="${GAELD_IMAGE_TAG:-$(sed -n 's/^GAELD_IMAGE_TAG=//p' "$ENV_FILE" 2>/dev/null | head -1)}"
if [ -z "$configured" ]; then
    echo "✖ GAELD_IMAGE_TAG is not set in ${ENV_FILE}." >&2
    echo "  Compose would fall back to its default tag, which is a stale git hash." >&2
    exit 1
fi

echo "  configured  ${configured}   (${ENV_FILE})"

status=0

# ── Do the images exist at all? ─────────────────────────────
for image in "gaeld/app:${configured}" "gaeld/web:${configured}"; do
    if ! docker image inspect "$image" > /dev/null 2>&1; then
        echo "✖ ${image} is not present locally — 'up -d' would fail to start." >&2
        echo "  Build it first: scripts/build-production.sh" >&2
        status=1
    fi
done
[ "$status" -eq 0 ] || exit "$status"

# ── What do the labels say it holds? ────────────────────────
revision="$(docker image inspect "gaeld/app:${configured}" \
    --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' 2>/dev/null || echo '')"
echo "  revision    ${revision:-unknown}   (baked into the image)"

if [ -n "$revision" ] && [ "$revision" != "unknown" ]; then
    if git cat-file -e "${revision}^{commit}" 2>/dev/null; then
        echo "              $(git log -1 --format='%s' "$revision" | cut -c1-64)"
    else
        echo "  ⚠ that revision is not in this repository"
    fi
fi

# ── Is it a step forward from what runs now? ────────────────
# Buffer the listing rather than piping into a command that exits early: with
# `set -o pipefail`, an early `awk ... {exit}` sends SIGPIPE upstream and takes
# the whole script down with it.
#
# A failure here must not read as "nothing is running" — that would hand out an
# all-clear precisely when the check could not be made.
if ! images_listing="$(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" images 2>/dev/null)"; then
    echo "✖ Could not list the running containers, so nothing was compared." >&2
    echo "  Check that ${ENV_FILE} is complete and the Docker daemon is reachable." >&2
    exit 1
fi

running="$(printf '%s\n' "$images_listing" | awk '$2 == "gaeld/app" {print $3}' | sed -n '1p')"

if [ -z "$running" ]; then
    echo "  running     nothing — the stack is not up"
    exit 0
fi

echo "  running     ${running}"

if [ "$running" = "$configured" ]; then
    echo ""
    echo "✔ Already running ${configured}; 'up -d' would change nothing."
    exit 0
fi

configured_at="$(docker image inspect "gaeld/app:${configured}" --format '{{.Created}}')"
running_at="$(docker image inspect "gaeld/app:${running}" --format '{{.Created}}' 2>/dev/null || echo '')"

echo ""
if [ -n "$running_at" ] && [ "$configured_at" \< "$running_at" ]; then
    echo "✖ ROLLBACK: ${configured} was built before ${running} was." >&2
    echo "  built ${configured_at}  vs  running ${running_at}" >&2
    echo "  'up -d' would move production backwards. Say so out loud before you do it." >&2
    exit 1
fi

echo "✔ 'up -d' would move production from ${running} to ${configured}."
