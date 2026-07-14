FROM php:8.5-cli-alpine

RUN docker-php-ext-install pcntl

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY . .

CMD ["php", "bootstrap.php"]
