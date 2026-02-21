.DEFAULT_GOAL := help
DC := docker compose exec php

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*## "}; {printf "\033[36m%-25s\033[0m %s\n", $$1, $$2}'

build: ## Build Docker images
	docker compose build

up: ## Start containers
	docker compose up -d

down: ## Stop containers
	docker compose down

shell: ## Shell into PHP container
	docker compose exec php bash

install: ## Composer install
	$(DC) composer install

test: ## Run all tests
	$(DC) vendor/bin/phpunit

test-unit: ## Unit tests only
	$(DC) vendor/bin/phpunit --testsuite=unit

test-fiber: ## Fiber integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-fiber

test-swoole: ## Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole

test-serialization: ## Serialization integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-serialization

test-cluster: ## Cluster integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-cluster

test-thread-cluster: ## Thread cluster integration tests
	docker compose exec php-swoole-thread vendor/bin/phpunit --testsuite=integration-thread-cluster

test-persistence: ## Persistence unit + integration tests
	$(DC) vendor/bin/phpunit --testsuite=unit-persistence,unit-persistence-dbal,unit-persistence-doctrine,integration-persistence

psalm: ## Run Psalm analysis
	$(DC) vendor/bin/psalm

phpcs: ## Run PHPCS check
	$(DC) vendor/bin/phpcs

phpcbf: ## Fix PHPCS violations
	$(DC) vendor/bin/phpcbf

mutation: ## Mutation testing
	$(DC) vendor/bin/infection --min-msi=80 --min-covered-msi=90

cs: ## Code style check
	$(DC) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: ## Fix code style
	$(DC) vendor/bin/php-cs-fixer fix

.PHONY: help build up down shell install test test-unit test-fiber test-swoole test-serialization test-cluster test-thread-cluster test-persistence psalm phpcs phpcbf mutation cs cs-fix
