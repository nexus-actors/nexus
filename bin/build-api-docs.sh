#!/usr/bin/env bash
set -euo pipefail

# Build the api.nexusactors.com phpDocumentor site against the full Nexus monorepo.
# Wraps a workaround invocation around 2 known phpDocumentor 3.9-dev bugs:
# 1. Extension finder TypeError when project's vendor requires PHP > container's
# 2. ProvideTemplateOverridePathMiddleware URI-scheme bug on default config loading
#
# These workarounds let phpdoc bypass config-file path detection by using
# CLI flags + running from a fixtures dir (no phpdoc.dist.xml auto-detected).

NEXUS_WT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_WT="${PLUGIN_WT:-$NEXUS_WT/../phpdoc-templates-plugin}"

if [ ! -d "$PLUGIN_WT" ]; then
    echo "ERROR: plugin worktree not found at $PLUGIN_WT" >&2
    echo "Set PLUGIN_WT env var to the phpdoc-templates-plugin checkout." >&2
    exit 1
fi

docker run --rm \
    -v "$PLUGIN_WT":/app \
    -v "$NEXUS_WT/packages":/nexus-packages:ro \
    -v "$NEXUS_WT":/nexus \
    phpdoc-templates-plugin:dev \
    bash -c '
set -euo pipefail

# 1. Merge all 22 package src dirs into one flat dir
MERGED=/tmp/phpdoc-nexus-merged
mkdir -p $MERGED
for pkg in nexus-core nexus-runtime nexus-runtime-fiber nexus-runtime-swoole \
           nexus-runtime-step nexus-app nexus-serialization nexus-logger \
           nexus-cluster nexus-worker-pool nexus-worker-pool-swoole \
           nexus-persistence nexus-persistence-dbal nexus-persistence-doctrine \
           nexus-http nexus-http-ws nexus-http-auth nexus-http-toolkit \
           nexus-http-server-swoole nexus-http-server-swoole-threads \
           nexus-doctrine-dbal nexus-doctrine-orm; do
    cp -r /nexus-packages/$pkg/src/. $MERGED/ 2>/dev/null || true
done

# 2. Symlink .phpdoc/build → nexus target so api-classes.json lands there
FIXTURES=/app/tests/Integration/fixtures
if [ -d $FIXTURES/.phpdoc/build ]; then
    mv $FIXTURES/.phpdoc/build $FIXTURES/.phpdoc/build.bak
fi
ln -sf /nexus/build/api-nexus $FIXTURES/.phpdoc/build
cd $FIXTURES

# 3. Run phpdoc with CLI flags (bypasses config file + URI bugs)
/app/vendor/bin/phpdoc \
    --directory=$MERGED \
    --target=/nexus/build/api-nexus \
    --cache-folder=/nexus/build/.phpdoc-cache \
    --extensions-dir=/app \
    --visibility=public --visibility=protected \
    --ignore-tags=internal \
    --force --no-ansi

# 4. Restore
rm $FIXTURES/.phpdoc/build
if [ -f $FIXTURES/.phpdoc/build.bak ]; then
    mv $FIXTURES/.phpdoc/build.bak $FIXTURES/.phpdoc/build
fi
'

echo "API docs built at $NEXUS_WT/build/api-nexus/"
echo "  Open index.html in a browser, or check api-classes.json for the catalog."
