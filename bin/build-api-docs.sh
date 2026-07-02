#!/usr/bin/env bash
set -euo pipefail

# Build the api.nexusactors.com phpDocumentor reference — same pipeline as
# .github/workflows/pages-api.yml: phpDocumentor from the nexus-actors fork
# (whose default template carries the Nexus branding), run via Docker.
#
# The fork is cloned into build/.phpdocumentor (gitignored) and its vendor/
# persists there, so repeat builds skip the composer download.

NEXUS_WT="$(cd "$(dirname "$0")/.." && pwd)"
FORK_DIR="${PHPDOC_FORK_DIR:-$NEXUS_WT/build/.phpdocumentor}"
FORK_REPO="https://github.com/nexus-actors/phpDocumentor.git"

if [ ! -d "$FORK_DIR/.git" ]; then
    git clone --depth 1 "$FORK_REPO" "$FORK_DIR"
else
    git -C "$FORK_DIR" fetch --depth 1 origin master
    git -C "$FORK_DIR" reset --hard origin/master
fi

docker run --rm \
    -v "$FORK_DIR":/phpdoc \
    -v "$NEXUS_WT":/data \
    -w /data \
    composer:2 \
    sh -c 'composer install --no-dev --no-interaction --no-progress --quiet --working-dir=/phpdoc \
        && php /phpdoc/bin/phpdoc --config=phpdoc.dist.xml --target=build/api-nexus --force'

echo "API docs built at $NEXUS_WT/build/api-nexus/"
