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
test: ## Reset the test database and run PHPUnit (Unit first, then Functional)
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:drop --force --env=test || true
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:create --env=test
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:migrations:migrate --no-interaction --env=test
	# Unit tests (everything not tagged #[Group('functional')]) run first and
	# fail fast before the slower, DB/HTTP-backed Functional group starts.
	# --do-not-fail-on-empty-test-suite: kept as a guard in case the Unit
	# suite is ever empty again (see CLAUDE.md) — an empty Unit run isn't a
	# failure, unlike an empty Functional run below, which would be.
	docker compose exec -T aeronuk-flight-search php bin/phpunit --exclude-group functional --do-not-fail-on-empty-test-suite
	docker compose exec -T aeronuk-flight-search php bin/phpunit --group functional

.PHONY: coverage
coverage: ## Reset the test database and run PHPUnit with coverage (Unit first, then Functional)
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:drop --force --env=test || true
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:database:create --env=test
	docker compose exec -T aeronuk-flight-search php bin/console doctrine:migrations:migrate --no-interaction --env=test
	# Same Unit-then-Functional split as `test` above, but each pass also
	# writes its own Clover report so both are uploaded to Codecov in CI
	# (see .github/workflows/ci.yml and CLAUDE.md's CI section).
	docker compose exec -T aeronuk-flight-search php bin/phpunit --exclude-group functional --do-not-fail-on-empty-test-suite --coverage-clover var/coverage/unit.clover.xml
	docker compose exec -T aeronuk-flight-search php bin/phpunit --group functional --coverage-clover var/coverage/functional.clover.xml

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
