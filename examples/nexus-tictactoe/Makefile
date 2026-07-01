.PHONY: help build install up down logs shell test clean

DC := docker compose

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' Makefile | awk 'BEGIN{FS=":.*?## "}{printf "\033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build the Docker image (PHP 8.5 ZTS + Swoole 6.0 thread-mode)
	$(DC) build

install: ## composer install inside the container
	$(DC) run --rm app composer install --no-interaction --no-progress

up: ## Start the Swoole-Threads HTTP server on :9080
	$(DC) up -d app
	@echo "Server: http://localhost:9080"
	@echo "Open two browser tabs (or share the URL) to play multiplayer."

down: ## Stop the server + Postgres
	$(DC) down

logs: ## Tail server logs
	$(DC) logs -f app

shell: ## Interactive shell inside the app container
	$(DC) exec app bash

test: ## Run the PHPUnit unit tests
	$(DC) run --rm app vendor/bin/phpunit

clean: ## Tear everything down and remove built images
	$(DC) down --rmi local --volumes
