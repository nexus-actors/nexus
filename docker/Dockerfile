# ── Base stage ──
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

# ── Xdebug stage ──
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

# ── Swoole stage ──
FROM base AS swoole-build

RUN cd /tmp \
    && git clone --depth 1 --branch v6.2.0-rc1 https://github.com/swoole/swoole-src.git \
    && cd swoole-src \
    && phpize \
    && ./configure --enable-swoole \
    && make -j$(nproc) \
    && make install \
    && rm -rf /tmp/swoole-src

# ── Target: php-fiber (xdebug, no swoole) ──
FROM xdebug AS php-fiber

CMD ["tail", "-f", "/dev/null"]

# ── Target: php-swoole (swoole, no xdebug) ──
FROM base AS php-swoole

COPY --from=swoole-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole

CMD ["tail", "-f", "/dev/null"]

# ── Target: php-full (xdebug + swoole) ──
FROM xdebug AS php-full

COPY --from=swoole-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole

CMD ["tail", "-f", "/dev/null"]

# ── ZTS base (Thread Safe) ──
FROM php:8.5-zts AS zts-base

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

# ── Swoole with threads (ZTS) ──
FROM zts-base AS swoole-thread-build

RUN cd /tmp \
    && git clone --depth 1 --branch v6.2.0-rc1 https://github.com/swoole/swoole-src.git \
    && cd swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-thread \
    && make -j$(nproc) \
    && make install \
    && rm -rf /tmp/swoole-src

# ── Target: php-swoole-thread (ZTS + Swoole threads) ──
FROM zts-base AS php-swoole-thread

COPY --from=swoole-thread-build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
RUN docker-php-ext-enable swoole

CMD ["tail", "-f", "/dev/null"]
