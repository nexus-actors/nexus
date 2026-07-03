.PHONY: help build install up down logs shell test psalm deptrac cs cs-fix phpcs ci clean

DC := docker compose

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | awk 'BEGIN{FS=":.*?## "}{printf "\033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image (PHP 8.5 ZTS + Swoole 6.2)
	$(DC) build

install: ## composer install inside the container
	$(DC) run --rm app composer install --no-interaction --no-progress

up: ## Start the server on :9090 (+ Postgres)
	$(DC) up -d app
	@echo "Server: http://localhost:9090"

down: ## Stop server + Postgres
	$(DC) down

logs: ## Tail server logs
	$(DC) logs -f app

shell: ## Interactive shell inside the app container
	$(DC) run --rm app bash

test: ## Run the PHPUnit suite
	$(DC) run --rm app vendor/bin/phpunit

psalm: ## Psalm static analysis (level 1 + nexus-psalm plugin)
	$(DC) run --rm app vendor/bin/psalm --no-cache

deptrac: ## Deptrac layer + context boundary check
	$(DC) run --rm app php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress

cs: ## PHP-CS-Fixer dry run
	$(DC) run --rm app vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## PHP-CS-Fixer auto-fix
	$(DC) run --rm app vendor/bin/php-cs-fixer fix

phpcs: ## PHPCS (Slevomat) standards check
	$(DC) run --rm app vendor/bin/phpcs

ci: test psalm deptrac cs phpcs ## Full local quality battery

clean: ## Tear everything down and remove built images
	$(DC) down --rmi local --volumes
