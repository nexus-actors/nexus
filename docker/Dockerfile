# ── Base stage (non-ZTS, for Fiber + Xdebug) ──
FROM php:8.5-cli AS base

WORKDIR /app

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libssl-dev \
    libicu-dev \
    && docker-php-ext-install bcmath intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ── Base ZTS stage (thread-safe, for Swoole Thread) ──
FROM php:8.5-zts AS base-zts

WORKDIR /app

RUN apt-get update && apt-get install -y \
    unzip \
    git \
    curl \
    libssl-dev \
    libicu-dev \
    && docker-php-ext-install bcmath intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ── Xdebug stage (non-ZTS, for Fiber) ──
FROM base AS xdebug

ARG XDEBUG_MODE=debug
ARG XDEBUG_START_WITH_REQUEST=trigger
ARG XDEBUG_CLIENT_HOST=host.docker.internal
ARG XDEBUG_CLIENT_PORT=9003

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN echo "xdebug.mode=${XDEBUG_MODE}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=${XDEBUG_START_WITH_REQUEST}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=${XDEBUG_CLIENT_HOST}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=${XDEBUG_CLIENT_PORT}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log_level=0" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# ── Xdebug ZTS stage (thread-safe, for Full = Swoole + Xdebug) ──
FROM base-zts AS xdebug-zts

ARG XDEBUG_MODE=debug
ARG XDEBUG_START_WITH_REQUEST=trigger
ARG XDEBUG_CLIENT_HOST=host.docker.internal
ARG XDEBUG_CLIENT_PORT=9003

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN echo "xdebug.mode=${XDEBUG_MODE}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=${XDEBUG_START_WITH_REQUEST}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=${XDEBUG_CLIENT_HOST}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=${XDEBUG_CLIENT_PORT}" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log_level=0" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# ── Swoole build stage (ZTS required for --enable-swoole-thread) ──
FROM base-zts AS swoole-build

RUN cd /tmp \
    && git clone --depth 1 --branch v6.2.0-rc1 https://github.com/swoole/swoole-src.git \
    && cd swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-thread \
    && make -j$(nproc) \
    && make install \
    && rm -rf /tmp/swoole-src

# ── Target: php-fiber (non-ZTS + xdebug, no swoole) ──
FROM xdebug AS php-fiber

CMD ["tail", "-f", "/dev/null"]

# ── Target: php-swoole (ZTS + swoole thread, no xdebug) ──
FROM base-zts AS php-swoole

COPY --from=swoole-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole

# OPcache + JIT — see docker/opcache.ini for the rationale per-setting
# and website/docs/http/performance.md for the full tuning guide. The
# zzz- prefix on the prod override matters: PHP loads conf.d/*.ini in
# alphabetical order and later files override earlier ones. ASCII sorts
# '-' (0x2D) BEFORE '.' (0x2E), so a name like `zz-opcache-prod.ini`
# would actually load BEFORE `zz-opcache.ini` and get clobbered by it.
# `zzz-` sorts strictly after `zz-`, ensuring the prod override wins.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/zzz-opcache-prod.ini /usr/local/etc/php/conf.d/zzz-opcache-prod.ini

CMD ["tail", "-f", "/dev/null"]

# ── Target: php-full (ZTS + xdebug + swoole thread) ──
FROM xdebug-zts AS php-full

COPY --from=swoole-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole

# DEV image: base OPcache only — NO -prod.ini override. Devs editing
# volume-mounted code expect changes to take effect; validate_timestamps=1
# (the default in opcache.ini) re-stats every 2 seconds. Xdebug also
# disables JIT at load, so the jit_buffer_size in opcache.ini is unused
# here — acceptable cost for a dev image.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

CMD ["tail", "-f", "/dev/null"]
