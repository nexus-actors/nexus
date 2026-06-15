# PHP 8.5 ZTS + Swoole 6.0 compiled with --enable-swoole-thread.
# Mirrors the Nexus monorepo's php-swoole image so the example
# binary-compatible with `make test-swoole` and the worker-pool-swoole
# runners.
FROM php:8.5-zts-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential pkg-config \
        libssl-dev libcurl4-openssl-dev libbrotli-dev libnghttp2-dev libpq-dev \
        zlib1g-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pcntl sockets opcache

# Swoole 6.0 with thread support — required for SwooleThreadServer / WorkerPoolApp.
RUN pecl channel-update pecl.php.net \
    && yes '' | pecl install swoole-6.0.2 \
    && docker-php-ext-enable swoole \
    && echo "swoole.enable_coroutine = On" >> /usr/local/etc/php/conf.d/swoole.ini \
    && echo "opcache.enable_cli = 1"        >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit_buffer_size = 128M" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.jit = tracing"          >> /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts || true

COPY . /app

EXPOSE 8080

CMD ["php", "public/server.php"]
