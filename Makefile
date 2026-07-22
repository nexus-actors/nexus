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

test: ## Run the suites safe on the php container only — NOT comprehensive (full matrix: make test-all)
	$(DC) vendor/bin/phpunit --testsuite=unit,integration-doctrine-entity-behavior,integration-fiber,integration-http,integration-step,integration-serialization,integration-messenger,integration-persistence,psalm

test-all: ## Run the FULL correctness matrix across the php AND php-swoole containers (everything except perf benchmarks)
	$(MAKE) test
	$(MAKE) test-swoole
	$(MAKE) test-worker-pool-swoole
	$(MAKE) test-cluster
	$(MAKE) test-http-swoole
	$(MAKE) test-doctrine

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

test-messenger: ## Messenger integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-messenger

test-cluster: ## Cluster TCP integration tests (Swoole, real sockets)
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-cluster

test-cluster-debug: ## Cluster TCP integration tests under Xdebug (php-swoole-debug; set a breakpoint + IDE listener on :9003)
	docker compose exec -e XDEBUG_TRIGGER=1 php-swoole-debug vendor/bin/phpunit --testsuite=integration-cluster

test-swoole-debug: ## Swoole integration tests under Xdebug (php-swoole-debug; set a breakpoint + IDE listener on :9003)
	docker compose exec -e XDEBUG_TRIGGER=1 php-swoole-debug vendor/bin/phpunit --testsuite=integration-swoole

test-cluster-loopback: ## Cluster TCP loopback integration tests (plain php container, no ext-swoole required)
	$(DC) vendor/bin/phpunit --testsuite=integration-cluster-loopback

test-doctrine: ## Doctrine DBAL pool + ORM pool + EntityBehavior integration tests
	docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=integration-doctrine-fiber
	docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=integration-doctrine-orm-fiber
	docker compose exec -T php-fiber vendor/bin/phpunit --testsuite=integration-doctrine-entity-behavior
	docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-doctrine-swoole
	docker compose exec -T php-swoole vendor/bin/phpunit --testsuite=integration-doctrine-orm-swoole

test-persistence: ## Persistence unit + integration tests
	$(DC) vendor/bin/phpunit --testsuite=unit-persistence,unit-persistence-dbal,unit-persistence-doctrine,integration-persistence

test-http: ## HTTP integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-http

test-http-swoole: ## HTTP Swoole integration tests
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=integration-http-swoole

test-observability: ## Observability package unit tests
	docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit packages/nexus-observability-otel/tests/Unit packages/nexus-observability-http/tests/Unit packages/nexus-observability-persistence/tests/Unit packages/nexus-observability-worker-pool/tests/Unit packages/nexus-observability-doctrine/tests/Unit packages/nexus-observability-logger/tests/Unit packages/nexus-observability-actor/tests/Unit
	docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-observability-swoole/tests/Unit

perf-http-swoole: ## HTTP Swoole performance benchmarks (worker mode)
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole

perf-http-swoole-threads: ## HTTP Swoole performance benchmarks (thread mode)
	docker compose exec php-swoole vendor/bin/phpunit --testsuite=performance-http-swoole-threads

psalm: ## Run Psalm analysis
	$(DC) vendor/bin/psalm --find-unused-psalm-suppress

deps-check: ## Verify every package's composer.json declares all used dependencies
	$(DC) php bin/check-package-deps.php

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

docs-verify: ## Verify ```php snippets in website/docs/ via bin/verify-doc-snippets
	@docker compose exec -T php bin/verify-doc-snippets --self-test
	@docker compose exec -T php bin/verify-doc-snippets

docs-api: ## Build the api.nexusactors.com phpDocumentor reference (nexus-actors/phpDocumentor fork)
	@./bin/build-api-docs.sh

docs-api-serve: ## Serve API docs locally on http://127.0.0.1:$(PORT) (default PORT=8081; set API_BASE_URL=http://127.0.0.1:$(PORT) in .env.local)
	@echo "Serving API docs at http://127.0.0.1:$(PORT) (Ctrl-C to stop)"
	@cd build/api-nexus && python3 -m http.server $(PORT) --bind 127.0.0.1

PORT ?= 8081

.PHONY: help build up down shell install test test-all test-unit test-fiber test-swoole test-worker-pool-swoole test-serialization test-messenger test-cluster test-cluster-debug test-swoole-debug test-cluster-loopback test-doctrine test-persistence test-http test-http-swoole test-observability psalm deps-check phpcs phpcbf mutation cs cs-fix profile-hotpath spx-ui docs-verify docs-api docs-api-serve
