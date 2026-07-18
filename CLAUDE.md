# aeronuk-flight-search — Claude Code guide

Part of AeroNuk. For system-wide architecture, ADRs, RabbitMQ topology, and
event contracts, see `aeronuk-ops/README.md`. This service publishes and
consumes no events.

## Commit policy

**Never add `Co-Authored-By` trailers** to any commit in this project.

## Status

**Fully implemented.** All endpoints done, fixtures auto-load, tests pass.

## Stack

- PHP 8.4
- Symfony 8.1 (FrankenPHP runtime in Docker)
- Doctrine ORM 3
- MySQL 8.4
- Root namespace: `AeroNuk\FlightSearch` (not Symfony's default `App`)

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/flights?origin=LHR&destination=JFK&date=2025-01-15` | Search flights |
| `GET` | `/api/flights/{id}/seats` | Seats for a flight |
| `GET` | `/health` | Docker healthcheck |

`origin`, `destination`, and `date` are all **required** on `GET /api/flights`.
Missing any one returns `400`. Invalid airport code (not in the enum) → `400`.
Unknown flight ID → `404`.

## Source layout

`src/` is organized by architectural layer, not by Symfony class type:
`Domain` (entities, repositories, value objects, domain exceptions),
`UserInterface/REST` (everything serving the HTTP API), and `Infra`
(Doctrine tooling — migrations, fixtures). `Kernel.php` is the one
exception, staying unmoved at the `src/` root since it's Symfony's
framework bootstrap class, not part of the three-layer split.

```
src/
  Domain/                             # flat — no Entity/Repository/ValueObject/Exception subfolders
    Flight.php                        # public readonly properties, no getters
    Seat.php                          # public readonly, $flight eager-fetched
    FlightRepository.php              # DI service, not ServiceEntityRepository
    SeatRepository.php                # DI service, findByFlight() returns Seat[]
    AirportCode.php                   # closed enum: LHR JFK LAX ORD SFO NRT
    Money.php                         # Doctrine embeddable: amount + currency
    FlightNotFound.php                # no "Exception" suffix; see naming convention below
  UserInterface/
    REST/
      FlightController.php            # GET /api/flights, GET /api/flights/{id}/seats
      HealthController.php            # GET /health
      FlightSearchRequest.php         # #[MapQueryString] DTO for GET /api/flights, Symfony Validator constraints
      ValidationExceptionListener.php # converts #[MapQueryString] validation failures to {"error": ...}
  Infra/
    DataFixtures/
      FlightFixtures.php              # seed data; only loads in dev/test
    Migrations/
      Version*.php                    # was the top-level migrations/ directory
  Kernel.php
```

## Settled decisions — do not re-litigate

- **Root namespace `AeroNuk\FlightSearch`** — reconfigured in `composer.json`,
  `config/services.yaml`, `src/Kernel.php`, `public/index.php`, `bin/console`.
  Every new class goes under this namespace.

- **`src/` is organized by architectural layer (`Domain` /
  `UserInterface/REST` / `Infra`), not by Symfony class type.** See "Source
  layout" above. `Domain` classes (entities, repositories, value objects,
  the domain exception) sit **flat** directly under `src/Domain/` — no
  `Entity`/`Repository`/`ValueObject`/`Exception` subfolders — all sharing
  one `AeroNuk\FlightSearch\Domain` namespace; `tests/Domain/` mirrors that
  flatness. `config/packages/doctrine_migrations.yaml`'s `migrations_paths`
  points at `src/Infra/Migrations` (previously the top-level `migrations/`
  directory); `frankenphp/docker-entrypoint.sh`'s migrations-present check
  was updated to match. Auto-generated migrations are deliberately excluded
  from `phpstan.neon.dist`/`phpcs.xml.dist` — they were never linted or
  analyzed when they lived outside `src/`, and moving them in-tree isn't
  meant to newly subject generated code to `level: max` / the Doctrine
  ruleset. `config/services.yaml`'s `when@test` block can no longer make
  "just the repositories" public via a directory resource (there's no
  `Repository/` subfolder left to scope to) — it declares
  `AeroNuk\FlightSearch\Domain\FlightRepository`/`SeatRepository` public
  explicitly instead, one service at a time.

- **Hand-rolled controllers + Doctrine ORM, not API Platform.** The two
  endpoints are simple explicit searches, not generic resource CRUD.

- **Symfony 8.1 / PHP 8.4 / MySQL 8.4** — supersedes the briefing's
  "Symfony 7 / PHP 8.3" pin. Track latest stable. Each version bump is a
  deliberate decision, not a default drift.

- **Repositories are plain DI services, not `ServiceEntityRepository`
  subclasses.** `FlightRepository` and `SeatRepository` take
  `EntityManagerInterface` via constructor injection. Entities have no
  `repositoryClass` attribute.

- **Entity/VO IDs are always passed in from the caller** — never generated
  inside a constructor. Keeps construction deterministic and testable.

- **No getters on `Flight`/`Seat`/`Money`.** All properties are
  `public readonly`. `symfony/serializer` reads them directly.
  `#[Ignore]` (to hide `Seat::$flight` from serialization) lives on the
  property, not a getter method.

- **`AirportCode` is a closed enum** (`LHR`/`JFK`/`LAX`/`ORD`/`SFO`/`NRT`).
  Adding an airport = adding an enum case. An unknown code is a real 400 error,
  not a silent empty result.

- **`Money` is a Doctrine embeddable VO** (`amount` + `currency`). Two
  critical config details:
  - `Flight::$price` must have `#[ORM\Embedded(columnPrefix: false)]`.
    Without it Doctrine prefixes columns as `price_amount`/`price_currency`
    but the migration uses flat `price`/`currency` — mismatch → broken schema.
  - The Doctrine mapping `dir` in `config/packages/doctrine.yaml` must be
    `%kernel.project_dir%/src` (the whole `src/` tree), not just `src/Domain`,
    so `Money` in `src/Domain/` is discovered as a mapped embeddable.

- **`Flight`'s constructor rejects `departureTime >= arrivalTime`.**

- **`Seat::$flight` uses `fetch: 'EAGER'`**, not lazy proxy. Doctrine has
  known bugs with `readonly` properties and lazy proxies; eager loading
  sidesteps that risk and is cheap (one flight per seat). There is
  deliberately no inverse `Flight::$seats` collection.

- **`GET /api/flights` requires all three params** (origin, destination, date)
  — not an optional combinable filter set. Missing any → 400.

- **`GET /api/flights` validates via Symfony Validator on a request DTO, not
  hand-written `if`/`return` branches.** `FlightController::search()` takes
  `#[MapQueryString(validationFailedStatusCode: 400)] FlightSearchRequest
  $request` — Symfony denormalizes the query string into
  `UserInterface\REST\FlightSearchRequest` and runs its `Assert\NotBlank` /
  `Assert\Choice` / `Assert\Date` constraints before the controller body
  runs at all. `FlightSearchRequest`'s `origin`/`destination`/`date`
  properties are plain `string|null` (constraints, not PHP types, enforce
  "required"); the controller converts them to `AirportCode` and
  `DateTimeImmutable` after validation passes. On validation failure,
  Symfony throws an `HttpException` wrapping a `ValidationFailedException`
  — `UserInterface\REST\ValidationExceptionListener` catches that specific case
  (previous instance check, not a blanket exception handler) and rewrites
  it to this API's `{"error": "..."}` JSON shape instead of Symfony's
  default (HTML in debug mode, `title`/`detail` JSON in prod), so the `400`
  response contract stays exactly what it was under manual validation.

- **`FlightRepository::get(string $id): Flight` always returns a `Flight` or
  throws `FlightNotFound`** — never `null`. No `find()` method;
  avoids null-checks at call sites.

- **Exception classes don't carry an `Exception` suffix** (`FlightNotFound`,
  not `FlightNotFoundException`). They already live in the `Exception`
  namespace, so the suffix is redundant — this is enforced by
  `doctrine/coding-standard`'s `SlevomatCodingStandard.Classes.SuperfluousExceptionNaming`
  sniff (see CI section below), not just a style preference. Follow this for
  every new exception class.

- **`.env` is committed, not gitignored.** This follows Symfony convention:
  `.env` holds non-secret defaults; `.env.local`/`.env.*.local` (gitignored)
  hold real overrides. The other AeroNuk services use `.env.example` +
  gitignored `.env` — this service differs intentionally.

- **No pagination** on `GET /api/flights` — dataset is small by design.

- **Seat numbers are zero-padded** (`'01A'`, not `'1A'`) in fixtures and
  tests. `SeatRepository::findByFlight()` uses plain lexicographic
  `ORDER BY seatNumber ASC` — without zero-padding, `'12A'` sorts before
  `'1A'`. Keep the zero-padding; don't change the repository sort.

- **Fixtures reload unconditionally in `dev`/`test` on every container
  start** — `frankenphp/docker-entrypoint.sh` runs
  `doctrine:fixtures:load --no-interaction` directly (Doctrine's own
  command, gated on `$APP_ENV` in the shell script) after migrations, every
  time. Deliberately not conditioned on "table is empty" — that was a
  previous design (`LoadFixturesIfEmptyCommand`) and was removed as an
  unnecessary layer of conditional logic. Fixture data isn't precious;
  resetting it on every restart is simpler and more predictable than trying
  to preserve it.

- **MySQL test database setup.** The official `mysql` Docker image only grants
  the `aeronuk` user access to the one database named in `MYSQL_DATABASE`
  (`flight_search`). The test database (`flight_search_test`) needs its own
  grant. `mysql-init/01-create-test-db.sql` creates it and grants access. This
  init script only runs on a **fresh volume** — if `flight_search_mysql_data`
  already exists, the script is skipped. Run `docker compose down -v` (for
  this service only) if the test DB is ever missing.

- **No OpenAPI/Swagger** generated.

## Running locally

```bash
# From this repo's directory:
docker compose up --build
# equivalently: make up

# Service available at:
curl http://localhost:8000/health
curl "http://localhost:8000/api/flights?origin=LHR&destination=JFK&date=2025-06-15"
```

## Tests

```bash
make test
```

Tests use the `flight_search_test` database (separate from dev). Nothing
creates that schema automatically on a fresh volume — `docker-entrypoint.sh`
only migrates the **dev** database on container start (hardcoded to `.env`,
under `APP_ENV=dev`), and `tests/bootstrap.php` doesn't touch the database at
all. `make test` (see `Makefile`) explicitly drops, recreates, and migrates
`flight_search_test` via `--env=test` before running `bin/phpunit`, mirroring
the pattern from a sibling project. Running `php bin/phpunit` directly
(skipping `make test`) will fail with "table doesn't exist" on a fresh
volume — this bit us once already; don't reach for `docker compose exec ...
php bin/phpunit` as a shortcut.

**Test namespaces mirror the namespace of the class under test — there is
no separate `Tests\` prefix.** `composer.json`'s `autoload-dev` maps the
same `AeroNuk\FlightSearch\` PSR-4 prefix that `autoload` uses for `src/`
to `tests/` as well (Composer supports multiple base directories per
prefix, merged only for dev installs — production `--no-dev` autoloading
never sees `tests/`). Concretely: `AeroNuk\FlightSearch\Domain\FlightRepository`
is tested by `AeroNuk\FlightSearch\Domain\FlightRepositoryTest` in
`tests/Domain/FlightRepositoryTest.php`, `AeroNuk\FlightSearch\UserInterface\REST\FlightController`
by `AeroNuk\FlightSearch\UserInterface\REST\FlightControllerTest` in
`tests/UserInterface/REST/FlightControllerTest.php`, and so on — a test
class's namespace is *identical* to the production class it tests,
distinguished only by living under `tests/` instead of `src/`.
`tests/Tests/DecodesJsonResponse.php` and `tests/Tests/ResetsDatabase.php`
are the deliberate exception: shared test helper traits with no single
production class to mirror, so they keep the existing shared
`AeroNuk\FlightSearch\Tests` namespace — and, because that namespace has
one more segment than the mirrored classes do, they live one directory
deeper too (`tests/Tests/`, not `tests/`), so the PSR-4 base-directory
mapping above still resolves them correctly.

**Tests are Unit by default; anything that touches the database, makes an
HTTP request, or boots the Kernel/container is tagged
`#[PHPUnit\Framework\Attributes\Group('functional')]`** on the class (or
method, for a mixed class). The namespace-mirroring rule above rules out a
folder-based `tests/Unit` vs `tests/Functional` split, so the grouping is
attribute-based instead. `make test` runs PHPUnit twice in sequence: first
excluding the `functional` group (Unit — fast, no I/O), then including only
it (Functional — slower, needs the live test database/HTTP stack) — so a
Unit failure fails the build before any Functional test even starts. The 4
Functional test classes (`FlightRepositoryTest`, `SeatRepositoryTest` — both
`KernelTestCase`, hitting the database; `FlightControllerTest`,
`HealthControllerTest` — both `WebTestCase`, making HTTP requests) are
tagged `#[Group('functional')]`. The Domain/`UserInterface/REST` Unit tests
(`MoneyTest`, `FlightTest`, `AirportCodeTest`, `FlightNotFoundTest`,
`SeatTest`, `FlightSearchRequestTest`, `ValidationExceptionListenerTest`)
are plain `PHPUnit\Framework\TestCase`, untagged. The first `phpunit`
invocation still passes `--do-not-fail-on-empty-test-suite` as a guard in
case the Unit suite is ever empty again (an empty Unit run isn't meant to
be a failure — an empty Functional run, which doesn't get that flag, would
be).

**Use PHPUnit data providers instead of copy-pasted near-identical test
methods.** When several test cases exercise the same code path and differ
only in input/expected values (e.g. several different invalid
`GET /api/flights` query strings that should each 400), write one test
method with a [`#[DataProvider('...')]`](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html#data-providers)
attribute (from `PHPUnit\Framework\Attributes\DataProvider`) backed by a
`public static function ...Provider(): iterable` that yields
`'case description' => [...args]`. See
`FlightControllerTest::searchWithInvalidQueryReturns400()` /
`invalidSearchQueryProvider()` for the pattern. Don't add another
`searchWith*Returns400()`-style method for a new invalid-input case —
add a case to the existing provider instead.

**Test methods are marked with `#[PHPUnit\Framework\Attributes\Test]`, not
named with the legacy `test`-prefix convention.** Name the method for the
behavior it verifies (`searchReturnsMatchingFlights`, not
`testSearchReturnsMatchingFlights`), and add `use
PHPUnit\Framework\Attributes\Test;` plus `#[Test]` above it — stacked above
`#[DataProvider(...)]` where both apply, as in
`FlightControllerTest::searchWithInvalidQueryReturns400()`. This makes test
discovery explicit via the attribute rather than implicit via method
naming.

## CI

`.github/workflows/ci.yml`, triggered on push to `main` and on every pull
request. One workflow file with five jobs (not split per-concern across
files) because `needs:` dependencies only work within a single workflow —
`phpunit`'s `needs: build` (see below) would break across separate files.

- **`build`** — builds the `frankenphp_dev` target and pushes it to GHCR
  tagged with the commit SHA. This is an internal/ephemeral artifact for
  job-to-job reuse, not a published release image (registry pushes are
  intentionally out of scope for now).
- **`phpunit`** — `needs: build`, pulls that image, and runs via
  `.github/actions/bootstrap-stack` (a local composite action: log in to
  GHCR, pull, `docker compose up -d`, poll `/health` until ready). It's the
  only job that talks to a real database (the test schema), so it's the
  only one that pays for the image build + full compose stack. It runs
  `make coverage` (not `make test` — see below) and uploads the resulting
  Clover reports to Codecov via `codecov/codecov-action`.
- **`composer-lint`**, **`coding-standards`**, **`static-analysis`** — run
  on bare PHP instead: `actions/checkout` → `shivammathur/setup-php`
  (installs PHP 8.4 directly on the runner, no Docker/image involved) →
  `ramsey/composer-install` → `make <target>`. None of the three touches a
  database (they lint `composer.json`, run `phpcs`, and run `phpstan`
  respectively), so they don't declare `needs: build` and start immediately
  in parallel with it instead of waiting on it — the critical path of a CI
  run is roughly `max(build+phpunit, slowest bare-PHP job)` rather than
  `build` gating everything. Each job installs only the PHP extensions its
  tool actually needs, cross-checked against the Dockerfile's
  `install-php-extensions` list (`apcu`, `bcmath`, `intl`, `opcache`,
  `pdo_mysql`, `zip`) by actually running each job's checks with that exact
  extension set on a bare (non-Docker) PHP install, rather than assuming:
  - `composer-lint` installs `bcmath` and `intl` — `composer-require-checker`
    (config: `composer-require-checker.json`) pulls in
    `php-standard-library` transitively, which genuinely requires both at
    runtime.
  - `coding-standards` and `static-analysis` need neither. But `composer
    install` platform-checks the *entire* locked `composer.json` (including
    `require-dev`) on every run, regardless of which job invokes it or what
    it's actually going to use — so without `bcmath`/`intl` present, plain
    `composer install` fails outright for these two jobs even though phpcs
    and phpstan themselves never touch either extension. Their `composer
    install` step passes `--ignore-platform-req=ext-bcmath
    --ignore-platform-req=ext-intl` to work around that.
  - None of the three needs `pdo_mysql`, `apcu`, `opcache`, or `zip`.
    Notably, `static-analysis`'s `phpstan-bootstrap.php` boots the real
    Symfony `Kernel` and resolves the Doctrine `EntityManager` — but
    Doctrine's `Connection` is lazy and never actually connects just to
    resolve entity metadata, so `phpstan analyse` passes on a runner with no
    `pdo_mysql` extension at all and no reachable `flight-search-db` host
    (verified directly, not assumed: same command, same `DATABASE_URL` from
    `.env`, against a container with only `ctype`/`iconv` installed).

The Dockerfile builds a **bare runtime image** — no `COPY . /app`, no
`RUN composer install`. Application code only enters via the `.:/app` bind
mount in `docker-compose.yml`, and `frankenphp/docker-entrypoint.sh` runs
`composer install` (and migrations/fixtures) at container start. So the
GHCR image alone isn't runnable — `phpunit` still needs the checked-out repo
mounted in. `docker-compose.yml`'s `aeronuk-flight-search` service has an
`image: ${APP_IMAGE:-aeronuk-flight-search:local}` key specifically so CI can
point it at the pulled GHCR tag while local dev (`APP_IMAGE` unset) keeps
building from the `Dockerfile` exactly as before.

`phpunit` spins up the **full** compose stack (app + MySQL), not a bare
`docker run`, so it reuses the exact same entrypoint bootstrap logic already
relied on for local dev/tests, rather than reimplementing
composer-install/migration steps.

The actual tool invocations live in the root `Makefile` (`make cs`, `make
stan`, `make composer-lint`, `make test`, `make coverage`), not inline in
`ci.yml`. This keeps the exact same command available for local use (`make
test` is the documented way to run tests — see below) and in CI, so the two
never drift. `cs`, `stan`, and `composer-lint` run their tool through a
`DOCKER_EXEC` Makefile variable that defaults to `docker compose exec -T
aeronuk-flight-search` (local dev, and matching prior CI behavior); the
three bare-PHP CI jobs override it to empty (`make stan DOCKER_EXEC=`) so
the same target runs the tool directly against the runner's own PHP instead.
`test` and `coverage` don't take this variable — they're Docker/compose-only,
since `phpunit` is the one job that needs the live database.

### Coverage (Codecov)

`make coverage` mirrors `make test`'s Unit-then-Functional `phpunit`
invocations (see "Tests" above) but adds `--coverage-clover` to each pass,
writing `var/coverage/unit.clover.xml` and `var/coverage/functional.clover.xml`
(both under the gitignored `var/`, matching `phpunit.dist.xml`'s existing
`<source>` block that scopes coverage to `src/`, not `tests/`). `make test`
itself is deliberately left coverage-free, since collecting coverage adds
overhead that isn't worth paying on every local test run.

Collecting coverage requires a coverage driver, which isn't installed by
default. **`pcov`** is installed via `RUN install-php-extensions pcov` in
the `frankenphp_dev` stage of the `Dockerfile` only — *not* added to
`frankenphp_base`'s shared extension list — because a coverage driver has no
business in a hypothetical future production image built straight from
`frankenphp_base`. `pcov` (not Xdebug) is used because it's purpose-built
for fast coverage collection with much lower overhead, and step debugging
isn't needed in CI.

In CI, the `phpunit` job runs `make coverage` in place of `make test` (a
strict superset — same tests, plus coverage output). Because
`docker-compose.yml` mounts an anonymous volume over `/app/var`
(`- /app/var`, layered on top of the `.:/app` bind mount specifically to
keep container-only state like `var/cache` out of the checkout), the Clover
files land inside the container but never appear on the runner's own
filesystem — a `docker compose cp aeronuk-flight-search:/app/var/coverage/.
./var/coverage/` step copies them out before the upload step can see them.
Both files are then uploaded to Codecov via `codecov/codecov-action`,
authenticated with `${{ secrets.CODECOV_TOKEN }}`. `codecov.yml` at the repo
root sets the project coverage target to 80%.

Two prerequisites for this to actually work are manual, repo-admin actions
outside the scope of any PR, and can't be completed by editing workflow
files alone:

1. **Codecov repo activation + `CODECOV_TOKEN` secret.** This repo needs to
   be activated at codecov.io (sign in with GitHub, add the repo) and a
   `CODECOV_TOKEN` added as a GitHub Actions repository secret — Codecov's
   tokenless upload path is unreliable (rate-limited, deprecated for most
   cases) so this is a real prerequisite, not optional polish. Until it's
   done, the "Upload coverage to Codecov" step in the `phpunit` job is
   expected to fail; that's not a bug in the workflow.
2. **Branch protection.** Codecov's coverage check only *reports* a commit
   status by default. Making it actually block merges to `main` requires
   adding it as a required status check in this repo's branch protection
   settings, once it's reporting successfully.

Tooling:
- **`doctrine/coding-standard`** (PHP_CodeSniffer, not PHP-CS-Fixer — the
  official Doctrine ruleset only ships for phpcs) via `phpcs.xml.dist`.
- **PHPStan at `level: max`** via `phpstan.neon.dist`, with two extra pieces
  wired in to make max level usable rather than full of noise:
  - `symfony.containerXmlPath` points at the dev container XML
    (`var/cache/dev/AeroNuk_FlightSearch_KernelDevDebugContainer.xml`),
    generated automatically by `composer install`'s `cache:clear`
    auto-script — this is what lets `self::getContainer()->get(X::class)`
    resolve to `X` instead of generic `object` in tests.
  - `doctrine.objectManagerLoader` points at `phpstan-bootstrap.php` (boots
    the real `Kernel` and returns the `EntityManager`), which is what lets
    PHPStan infer exact DQL result types (e.g. `FlightRepository::search()`
    returning `Flight[]` instead of `mixed`) instead of just seeing
    `AbstractQuery::getResult()`'s native `mixed` signature.
  - `phpstan/phpstan-phpunit` is required so `self::assertIsString()` /
    `assertIsArray()` / `assertInstanceOf()` narrow types for PHPStan the
    same way `is_string()` etc. would — used deliberately in tests instead
    of `assert()` or `@var` overrides to satisfy PHPStan's own guidance
    against silencing errors.
- **`composer-require-checker`** (config: `composer-require-checker.json`)
  checks for undeclared/implicit dependencies. Its whitelist covers symbols
  genuinely provided transitively by `symfony/framework-bundle` /
  `doctrine/orm` / `doctrine/doctrine-migrations-bundle` that Symfony Flex
  itself never adds as direct requires (`HttpFoundation`, `HttpKernel`,
  `DependencyInjection` attributes, `Routing` attributes,
  `Doctrine\Persistence\ObjectManager`, `Doctrine\DBAL\Schema\Schema`,
  `Doctrine\Migrations\AbstractMigration`). One real gap it did catch:
  `doctrine/doctrine-fixtures-bundle` was in `require-dev` even though
  `src/Infra/DataFixtures/FlightFixtures.php` sits under the main (non-dev)
  PSR-4 autoload root — it's now in `require`. Moving `migrations/` under
  `src/Infra/Migrations` (also on that autoload root) newly brought
  `Doctrine\DBAL\Schema\Schema` and `Doctrine\Migrations\AbstractMigration`
  into scope for the same reason — added to the whitelist rather than
  `require`, since unlike the fixtures bundle these really are only used by
  auto-generated migration code, not application code.
- **`ergebnis/composer-normalize`** enforces consistent `composer.json`
  key ordering/formatting (`composer normalize --dry-run` in CI).
