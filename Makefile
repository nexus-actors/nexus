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

update: ## Composer install
	$(DC) composer update

test: ## Run all tests
	$(DC) vendor/bin/phpunit

test-unit: ## Unit tests only
	$(DC) vendor/bin/phpunit --testsuite=unit

test-fiber: ## Fiber integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-fiber

test-swoole: ## Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-swoole

test-worker-pool-swoole: ## Worker pool Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-worker-pool-swoole

test-serialization: ## Serialization integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-serialization

test-cluster: ## Cluster integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-cluster

test-persistence: ## Persistence unit + integration tests
	$(DC) vendor/bin/phpunit --testsuite=unit-persistence,unit-persistence-dbal,unit-persistence-doctrine,integration-persistence

test-http: ## HTTP integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-http

test-http-swoole: ## HTTP Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole

perf-http-swoole: ## HTTP Swoole performance benchmarks (worker mode)
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole

perf-http-swoole-threads: ## HTTP Swoole performance benchmarks (thread mode)
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole-threads

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

profile-hotpath: ## Profile hotpath breakdown with SPX (then run make spx-ui)
	docker compose exec php-swoole bash -c 'SPX_ENABLED=1 SPX_REPORT=full php tests/Performance/hotpath_breakdown.php'

spx-ui: ## Serve SPX web UI to browse saved flame charts (http://localhost:8889?SPX_KEY=nexus&SPX_UI_URI=/)
	docker compose exec php-swoole php -S 0.0.0.0:8889 docker/spx-ui.php

.PHONY: help build up down shell install test test-unit test-fiber test-swoole test-worker-pool-swoole test-serialization test-cluster test-persistence test-http test-http-swoole psalm phpcs phpcbf mutation cs cs-fix profile-hotpath spx-ui
