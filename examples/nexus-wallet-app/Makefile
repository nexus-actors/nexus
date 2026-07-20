.PHONY: help build install up down logs shell test perf clean

DC := docker compose

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | awk 'BEGIN{FS=":.*?## "}{printf "\033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image (PHP 8.5 ZTS + Swoole 6.2.1 thread-mode)
	$(DC) build

install: ## composer install inside the container
	$(DC) run --rm app composer install --no-interaction --no-progress

up: ## Start the Swoole-Threads HTTP server on :8080
	$(DC) up -d app
	@echo "Server: http://localhost:8080"
	@echo "Try:    curl -H 'Authorization: Bearer alice-token' http://localhost:8080/wallet/balance"

down: ## Stop the server
	$(DC) down

logs: ## Tail server logs
	$(DC) logs -f app

shell: ## Interactive shell inside the app container
	$(DC) exec app bash

test: ## Run the PHPUnit unit tests
	$(DC) run --rm app vendor/bin/phpunit

perf: ## Run the load tester against the running server (`make up` first)
	$(DC) --profile perf run --rm perf

clean: ## Tear everything down and remove built images
	$(DC) down --rmi local --volumes
