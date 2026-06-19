# PHP 8.5 ZTS + Swoole 6.2 compiled with --enable-swoole-thread.
# Mirrors the Nexus monorepo's php-swoole image so the example is
# binary-compatible with `make test-swoole` and the worker-pool-swoole
# runners.
FROM php:8.5-zts

RUN apt-get update && apt-get install -y --no-install-recommends \
        autoconf build-essential pkg-config \
        libssl-dev libcurl4-openssl-dev libbrotli-dev libnghttp2-dev libpq-dev \
        libsqlite3-dev \
        zlib1g-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

# Swoole 6.2 with thread support — required for SwooleThreadServer / WorkerPoolApp.
RUN git clone --depth 1 --branch v6.2.1 https://github.com/swoole/swoole-src.git /tmp/swoole-src \
    && cd /tmp/swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-thread \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /tmp/swoole-src \
    && docker-php-ext-enable swoole \
    && echo "swoole.enable_coroutine = On" >> /usr/local/etc/php/conf.d/swoole.ini

# Database extensions: pdo_pgsql for Postgres (production), pdo_sqlite for tests.
RUN docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY . /app

EXPOSE 8080

CMD ["php", "public/server.php"]
