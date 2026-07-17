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
	docker compose exec -T aeronuk-flight-search vendor/bin/phpcs

.PHONY: cs-fix
cs-fix: ## Auto-fix coding standard violations
	docker compose exec -T aeronuk-flight-search vendor/bin/phpcbf

.PHONY: stan
stan: ## Run static analysis (phpstan, max level)
	docker compose exec -T aeronuk-flight-search vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: composer-lint
composer-lint: ## Validate composer.json and check for undeclared/unnormalized dependencies
	docker compose exec -T aeronuk-flight-search composer validate --strict
	docker compose exec -T aeronuk-flight-search composer normalize --dry-run
	docker compose exec -T aeronuk-flight-search vendor/bin/composer-require-checker check --config-file=composer-require-checker.json
