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

```
src/
  Controller/
    FlightController.php             # GET /api/flights, GET /api/flights/{id}/seats
    HealthController.php             # GET /health
  DataFixtures/
    FlightFixtures.php               # seed data; only loads in dev/test
  Entity/
    Flight.php                       # public readonly properties, no getters
    Seat.php                         # public readonly, $flight eager-fetched
  EventListener/
    ValidationExceptionListener.php  # converts #[MapQueryString] validation failures to {"error": ...}
  Exception/
    FlightNotFound.php               # no "Exception" suffix; see naming convention below
  Repository/
    FlightRepository.php             # DI service, not ServiceEntityRepository
    SeatRepository.php               # DI service, findByFlight() returns Seat[]
  Request/
    FlightSearchRequest.php          # #[MapQueryString] DTO for GET /api/flights, Symfony Validator constraints
  ValueObject/
    AirportCode.php                  # closed enum: LHR JFK LAX ORD SFO NRT
    Money.php                        # Doctrine embeddable: amount + currency
  Kernel.php
```

## Settled decisions — do not re-litigate

- **Root namespace `AeroNuk\FlightSearch`** — reconfigured in `composer.json`,
  `config/services.yaml`, `src/Kernel.php`, `public/index.php`, `bin/console`.
  Every new class goes under this namespace.

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
    `%kernel.project_dir%/src` (the whole `src/` tree), not just `src/Entity`,
    so `Money` in `src/ValueObject/` is discovered as a mapped embeddable.

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
  `Request\FlightSearchRequest` and runs its `Assert\NotBlank` /
  `Assert\Choice` / `Assert\Date` constraints before the controller body
  runs at all. `FlightSearchRequest`'s `origin`/`destination`/`date`
  properties are plain `string|null` (constraints, not PHP types, enforce
  "required"); the controller converts them to `AirportCode` and
  `DateTimeImmutable` after validation passes. On validation failure,
  Symfony throws an `HttpException` wrapping a `ValidationFailedException`
  — `EventListener\ValidationExceptionListener` catches that specific case
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

**Use PHPUnit data providers instead of copy-pasted near-identical test
methods.** When several test cases exercise the same code path and differ
only in input/expected values (e.g. several different invalid
`GET /api/flights` query strings that should each 400), write one test
method with a [`#[DataProvider('...')]`](https://docs.phpunit.de/en/12.5/writing-tests-for-phpunit.html#data-providers)
attribute (from `PHPUnit\Framework\Attributes\DataProvider`) backed by a
`public static function ...Provider(): iterable` that yields
`'case description' => [...args]`. See
`FlightControllerTest::testSearchWithInvalidQueryReturns400()` /
`invalidSearchQueryProvider()` for the pattern. Don't add another
`testSearchWith*Returns400()`-style method for a new invalid-input case —
add a case to the existing provider instead.

## CI

`.github/workflows/ci.yml`, triggered on push to `main` and on every pull
request. One workflow file with five jobs (not split per-concern across
files) because `needs:` dependencies only work within a single workflow —
splitting would have broken the `build`-once-reuse-everywhere design below.

- **`build`** — builds the `frankenphp_dev` target and pushes it to GHCR
  tagged with the commit SHA. This is an internal/ephemeral artifact for
  job-to-job reuse, not a published release image (registry pushes are
  intentionally out of scope for now).
- **`composer-lint`**, **`coding-standards`**, **`static-analysis`**,
  **`phpunit`** — each `needs: build`, pulls that image, and runs via
  `.github/actions/bootstrap-stack` (a local composite action: log in to
  GHCR, pull, `docker compose up -d`, poll `/health` until ready).

The Dockerfile builds a **bare runtime image** — no `COPY . /app`, no
`RUN composer install`. Application code only enters via the `.:/app` bind
mount in `docker-compose.yml`, and `frankenphp/docker-entrypoint.sh` runs
`composer install` (and migrations/fixtures) at container start. So the
GHCR image alone isn't runnable — every job still needs the checked-out repo
mounted in. `docker-compose.yml`'s `aeronuk-flight-search` service has an
`image: ${APP_IMAGE:-aeronuk-flight-search:local}` key specifically so CI can
point it at the pulled GHCR tag while local dev (`APP_IMAGE` unset) keeps
building from the `Dockerfile` exactly as before.

Each job (including the lint-only ones) spins up the **full** compose stack
(app + MySQL), not a bare `docker run`, so that all four jobs reuse the
exact same entrypoint bootstrap logic already relied on for local dev/tests,
rather than reimplementing composer-install/migration steps per job.

The actual tool invocations live in the root `Makefile` (`make cs`, `make
stan`, `make composer-lint`, `make test`), not inline in `ci.yml` — the
workflow just calls `make <target>` after bootstrapping the stack. This
keeps the exact same command available for local use (`make test` is the
documented way to run tests — see below) and in CI, so the two never drift.

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
  `doctrine/orm` that Symfony Flex itself never adds as direct requires
  (`HttpFoundation`, `HttpKernel`, `DependencyInjection` attributes,
  `Routing` attributes, `Doctrine\Persistence\ObjectManager`). One real gap
  it did catch: `doctrine/doctrine-fixtures-bundle` was in `require-dev`
  even though `src/DataFixtures/FlightFixtures.php` sits under the main
  (non-dev) PSR-4 autoload root — it's now in `require`.
- **`ergebnis/composer-normalize`** enforces consistent `composer.json`
  key ordering/formatting (`composer normalize --dry-run` in CI).
