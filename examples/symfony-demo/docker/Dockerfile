# Stage 1: ZTS base with PHP extensions
FROM php:8.5-zts AS base-zts

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install bcmath intl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Stage 2: Swoole build (compiles with --enable-swoole-thread for ZTS thread pool)
FROM base-zts AS swoole-build

RUN apt-get update && apt-get install -y git \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch v6.2.0 https://github.com/swoole/swoole-src.git /tmp/swoole \
    && cd /tmp/swoole \
    && phpize \
    && ./configure --enable-swoole-thread --enable-openssl \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable swoole

# Stage 3: Production worker image (Swoole, no Xdebug)
FROM base-zts AS php-swoole

COPY --from=swoole-build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=swoole-build /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini /usr/local/etc/php/conf.d/

RUN docker-php-ext-enable swoole

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Stage 4: Development image (Swoole + Xdebug)
FROM php-swoole AS php-full

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

ENV XDEBUG_MODE=develop,debug
