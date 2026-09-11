#!/usr/bin/env bash
#
# Build the production images with a tag derived from git.
#
# The image tag used to be typed into .env.production by hand, which encodes two
# separate facts — the upstream release this deployment forked from, and a local
# build counter. Both drifted: the tag still said v3.8.6 long after the branch
# had merged upstream through v3.8.14, and .env.production said .11 while the
# containers ran .13. Since .11 was still present locally, a plain `up -d` would
# have rolled production back two builds without a word.
#
# Everything the tag needs is already in git, so nothing here is counted by hand:
#
#   base    = the nearest tag             → moves at the next upstream merge
#   counter = commits since that tag      → moves with every commit
#
# This script builds images. It never starts or migrates anything: the runbook
# wants a production rollout to stay a deliberate act.
#
# Usage:
#   scripts/build-production.sh              build, refusing a dirty tree
#   scripts/build-production.sh --print      print the tag it would use, build nothing
#   scripts/build-production.sh --allow-dirty  build anyway, marking the tag .dirty

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

ENV_FILE="${GAELD_ENV_FILE:-.env.production}"
COMPOSE_FILE="compose.production.yml"

print_only=false
allow_dirty=false
for argument in "$@"; do
    case "$argument" in
        --print) print_only=true ;;
        --allow-dirty) allow_dirty=true ;;
        *) echo "Unknown option: $argument" >&2; exit 2 ;;
    esac
done

# ── Derive the tag ──────────────────────────────────────────
if ! base="$(git describe --tags --abbrev=0 2>/dev/null)"; then
    echo "✖ No tag reachable from HEAD; cannot derive an image tag." >&2
    echo "  Fetch the upstream tags first: git fetch upstream --tags" >&2
    exit 1
fi

counter="$(git rev-list --count "${base}..HEAD")"
revision="$(git rev-parse --short HEAD)"
build_date="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
tag="${base}-ideall.${counter}"

dirty=false
if [ -n "$(git status --porcelain)" ]; then
    dirty=true
    tag="${tag}.dirty"
fi

previous="$(sed -n 's/^GAELD_IMAGE_TAG=//p' "$ENV_FILE" 2>/dev/null | head -1)"

echo "  base      ${base}   (nearest tag)"
echo "  counter   ${counter}   (commits since ${base})"
echo "  revision  ${revision}"
echo "  tag       ${tag}"
[ -n "$previous" ] && echo "  previous  ${previous}"

if [ "$print_only" = true ]; then
    [ "$dirty" = true ] && echo "  (working tree is dirty)"
    exit 0
fi

if [ "$dirty" = true ] && [ "$allow_dirty" = false ]; then
    echo "" >&2
    echo "✖ The working tree has uncommitted changes." >&2
    echo "  The tag names a commit, so it would not describe what goes into the image." >&2
    echo "  Commit first, or rebuild with --allow-dirty to tag it ${tag}." >&2
    exit 1
fi

# ── Record it where compose reads it ────────────────────────
if [ ! -f "$ENV_FILE" ]; then
    echo "✖ ${ENV_FILE} does not exist." >&2
    exit 1
fi

if grep -q '^GAELD_IMAGE_TAG=' "$ENV_FILE"; then
    sed -i "s|^GAELD_IMAGE_TAG=.*|GAELD_IMAGE_TAG=${tag}|" "$ENV_FILE"
else
    printf 'GAELD_IMAGE_TAG=%s\n' "$tag" >> "$ENV_FILE"
fi
chmod 600 "$ENV_FILE"

# ── Build ───────────────────────────────────────────────────
echo ""
echo "▸ Building gaeld/app:${tag} and gaeld/web:${tag}"

GIT_REVISION="$revision" BUILD_DATE="$build_date" \
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" build

echo ""
echo "✔ Built ${tag} from ${revision}"
echo ""
echo "  Verify, then roll out deliberately:"
echo "    scripts/check-production-tag.sh"
echo "    docker compose --env-file ${ENV_FILE} -f ${COMPOSE_FILE} up -d --wait"
