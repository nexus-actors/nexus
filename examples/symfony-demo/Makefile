.PHONY: build install up down shell test psalm cs cs-fix migrate

build:
	docker compose build

install:
	docker compose exec app composer install

up:
	docker compose up -d

down:
	docker compose down

shell:
	docker compose exec app bash

test:
	docker compose exec app vendor/bin/phpunit

psalm:
	docker compose exec app vendor/bin/psalm

cs:
	docker compose exec app vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	docker compose exec app vendor/bin/php-cs-fixer fix

migrate:
	docker compose exec app vendor/bin/doctrine orm:schema-tool:create --force
