---
title: ZTS PHP Setup
related:
  - operations/deployment/docker
  - scaling/choosing-thread-count
  - runtimes/swoole
---

# ZTS PHP Setup

The Swoole worker-pool and thread-mode HTTP server require ZTS (Zend Thread Safety) PHP. Standard Homebrew, Debian, and RHEL packages ship NTS (non-thread-safe) PHP. This page documents three ways to get a ZTS PHP 8.5 environment.

## Why ZTS is required

`Swoole\Thread` and `WorkerPoolBootstrap` use Swoole's thread mode (`--enable-swoole-thread`), which requires PHP to be compiled with thread safety enabled. NTS PHP will crash at runtime with a fatal error when Swoole attempts to create threads. This is a Swoole constraint, not a Nexus one.

Nexus's Fiber runtime and all unit tests run on NTS PHP. Only the worker pool and thread-mode HTTP server require ZTS.

## Option 1 — Docker (recommended)

Use the `php-swoole` target from `docker/Dockerfile`. It is based on the official `php:8.5.7-zts` image and installs Swoole 6.2.1 compiled with `--enable-swoole-thread`:

```dockerfile title="docker/Dockerfile (php-swoole target)"
FROM php:8.5.7-zts AS base-zts

# ── Swoole build stage (ZTS required for --enable-swoole-thread) ──
FROM base-zts AS swoole-build

RUN cd /tmp \
    && git clone --depth 1 --branch v6.2.1 https://github.com/swoole/swoole-src.git \
    && cd swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-thread \
    && make -j$(nproc) \
    && make install \
    && rm -rf /tmp/swoole-src

# ── Target: php-swoole (ZTS + swoole thread, no xdebug) ──
FROM base-zts AS php-swoole
COPY --from=swoole-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole
```

Build and run:

```bash title="terminal"
docker compose build php-swoole
docker compose run --rm php-swoole php -m | grep swoole
# swoole
```

This is the same image used by CI for Swoole integration tests and worker-pool tests.

## Option 2 — GitHub Actions with shivammathur/setup-php

Use the `shivammathur/setup-php` action with the `--ts` flag to install a ZTS PHP build:

```yaml title=".github/workflows/swoole-tests.yml"
jobs:
  swoole:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup ZTS PHP 8.5
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"
          extensions: swoole
          ini-values: "swoole.use_shortname=Off"
          coverage: none
        env:
          phpts: ts   # <-- enables ZTS build

      - name: Verify thread support
        run: php -r "var_dump(SWOOLE_THREAD);"
```

The `phpts: ts` environment variable instructs `setup-php` to download the thread-safe variant. Without it, the default is NTS and Swoole will not expose `Swoole\Thread`.

## Option 3 — Build from source (ASDF / phpenv)

For local development without Docker, build PHP from source with the maintainer ZTS flag. Using ASDF with the `asdf-php` plugin:

```bash title="terminal"
# Install asdf-php plugin
asdf plugin add php https://github.com/asdf-community/asdf-php.git

# Build ZTS PHP 8.5.7
PHP_CONFIGURE_OPTIONS="--enable-maintainer-zts" \
  asdf install php 8.5.7

asdf local php 8.5.7

# Verify
php -r "echo PHP_ZTS ? 'ZTS' : 'NTS';"
# ZTS
```

Then build and install Swoole manually:

```bash title="terminal"
pecl install swoole
# Accept "enable swoole-thread support? yes"
```

Or build from source to pin the version:

```bash title="terminal"
git clone --depth 1 --branch v6.2.1 https://github.com/swoole/swoole-src.git
cd swoole-src
phpize
./configure --enable-swoole --enable-swoole-thread
make -j$(nproc) && sudo make install
echo "extension=swoole.so" >> "$(php -r 'echo php_ini_loaded_file();')"
```

## Verifying the installation

```bash title="terminal"
php -r "
  echo 'PHP ZTS: ' . (PHP_ZTS ? 'yes' : 'no') . PHP_EOL;
  echo 'Swoole: ' . phpversion('swoole') . PHP_EOL;
  echo 'Thread support: ' . (defined('SWOOLE_THREAD') ? 'yes' : 'no') . PHP_EOL;
"
```

Expected output:

```
PHP ZTS: yes
Swoole: 6.2.1
Thread support: yes
```

If `SWOOLE_THREAD` is not defined, Swoole was compiled without `--enable-swoole-thread`. Rebuild with that flag.

## Caveats

- Xdebug is compatible with ZTS PHP. The `php-full` Docker target combines ZTS + Swoole + Xdebug. However, Xdebug disables JIT, so do not use `php-full` for production performance testing.
- The Fiber runtime does not require ZTS. Unit tests and non-Swoole integration tests run on the `php-fiber` target (NTS PHP + Xdebug) and do not require this setup.
- `opcache.jit` does not work under Xdebug regardless of ZTS/NTS. Only the `php-swoole` target (no Xdebug) exercises JIT in CI.

## See also

- [Docker deployment](./deployment/docker.md)
- [Choosing thread count](../scaling/choosing-thread-count.md)
- [Swoole runtime](../runtimes/swoole.md)
