# Prefix used to run each tool. Defaults to executing inside the local dev
# stack's app container (used by local dev and by the `phpunit` CI job,
# which is the only job that needs a live MySQL connection). CI's bare-PHP
# jobs (composer-lint, coding-standards, static-analysis — see CLAUDE.md's
# CI section) override this to empty so the same targets run directly
# against a `setup-php`-provisioned runner instead, with no Docker involved.
DOCKER_EXEC ?= docker compose exec -T aeronuk-flight-search

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: up
up: ## Build and start the local dev stack
	docker compose up --build

.PHONY: test
test: ## Reset the test database and run PHPUnit
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:drop --force --env=test || true
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:create --env=test
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:migrations:migrate --no-interaction --env=test
	docker compose exec -T aeronuk-flight-search php bin/phpunit

.PHONY: cs
cs: ## Check coding standard (phpcs, Doctrine ruleset)
	$(DOCKER_EXEC) vendor/bin/phpcs

.PHONY: cs-fix
cs-fix: ## Auto-fix coding standard violations
	$(DOCKER_EXEC) vendor/bin/phpcbf

.PHONY: stan
stan: ## Run static analysis (phpstan, max level)
	$(DOCKER_EXEC) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: composer-lint
composer-lint: ## Validate composer.json and check for undeclared/unnormalized dependencies
	$(DOCKER_EXEC) composer validate --strict
	$(DOCKER_EXEC) composer normalize --dry-run
	$(DOCKER_EXEC) vendor/bin/composer-require-checker check --config-file=composer-require-checker.json
