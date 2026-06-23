# aeronuk-flight-search: real implementation design

## Context

`aeronuk-flight-search` currently exists only as a throwaway Docker stub (plain
PHP built-in server, no framework) created to prove the full `aeronuk-ops`
stack boots together. This design replaces that stub with a real Symfony API
service, per the AeroNuk briefing's stack choice (PHP + Symfony + MySQL) and
ADR-004 (database-per-service, Doctrine for this service).

The original briefing pinned "PHP 8.3 + Symfony 7", but the user explicitly
asked to use the latest stable versions instead. As of this design (June
2026): Symfony's latest stable is **8.1** (requires PHP 8.4.0+, ruling out
8.3), and MySQL's current LTS is **8.4** (8.0 reached EOL April 2026). This
design uses Symfony 8.1, PHP 8.4, MySQL 8.4 — superseding the briefing's
version pin, not its architectural intent.

This is the first of five services to be implemented for real (suggested
build order in `aeronuk-ops/CLAUDE.md`: flight-search → booking → payment →
notification → client), so it's also the template the other services will
likely follow for "how do we turn a stub into a real service."

## Versions & tooling

- Install Symfony CLI via Homebrew (`brew install symfony-cli/cli/symfony`) —
  the officially documented primary path (symfony.com/doc/current/setup.html)
  for `symfony new`.
- `symfony new aeronuk-flight-search` with no `--version` flag, which installs
  the latest stable release (8.1) — skeleton flavor (no Twig/forms/security;
  this is a pure JSON API, not a traditional web app).
- PHP 8.4 throughout (composer platform requirement and Docker base image).
- MySQL 8.4 LTS as the database image (`mysql:8.4`), replacing the stub's
  `mysql:8`.

## Docker setup

Replace the current hand-written `Dockerfile`/stub entrypoint with the
official `dunglas/symfony-docker` template (the "Complete Docker Environment"
that symfony.com/doc/current/setup/docker.html points to), pinned to PHP 8.4
(`dunglas/frankenphp:1-php8.4` instead of their current default `1-php8.5`,
since the user asked for 8.3 or 8.4 specifically).

The official `frankenphp/docker-entrypoint.sh` already, by default:
1. Runs `composer install` if `vendor/` is empty.
2. Waits (up to 60s) for the database to become reachable.
3. Runs `doctrine:migrations:migrate --no-interaction --all-or-nothing` if a
   `migrations/` directory with files exists.

This design adds one extra guarded step to that script, inserted after the
migrations step: if `APP_ENV` is `dev` or `test` **and** the `flight` table
has zero rows, run `bin/console doctrine:fixtures:load --no-interaction`.
Never runs in `prod`. This keeps "`docker compose up` gives you a fully
working, seeded API" without seeding non-dev environments.

`docker-compose.yml` keeps the existing service names/ports
(`aeronuk-flight-search:8000`, MySQL service `flight-search-db`) and the
shared `aeronuk-network`, so nothing about the wiring in `aeronuk-ops`
changes — only the image build changes.

## Data model

Two Doctrine entities, mapped via attributes:

- **`Flight`**: `id` (UUID, primary key), `flightNumber` (string),
  `origin` (string, airport code), `destination` (string, airport code),
  `departureTime` (datetime immutable), `arrivalTime` (datetime immutable),
  `price` (decimal), `currency` (string, e.g. `USD`).
- **`Seat`**: `id` (UUID, primary key), `flight` (ManyToOne → `Flight`),
  `seatNumber` (string, e.g. `12A`), `class` (string enum:
  economy/business/first), `available` (bool).

Generated via `make:entity`, with the resulting schema captured in a Doctrine
Migrations file (`migrations/VersionXXXX.php`), committed to the repo per
ADR-004.

## API endpoints

- **`GET /api/flights`** — query params `origin`, `destination`, `date`, all
  optional and independently combinable (omitting all returns every flight).
  `origin`/`destination` match the airport code exactly (case-insensitive);
  `date` (format `YYYY-MM-DD`) matches flights whose `departureTime` falls on
  that calendar date, not an exact timestamp match. Returns a flat JSON array
  of flights (no pagination — the seed dataset is small; can be added later
  if it's ever needed). Serialized via `symfony/serializer`.
- **`GET /api/flights/{id}/seats`** — flat JSON array of that flight's seats.
  Returns `404` with `{"error": "..."}` if no flight matches `{id}` (matching
  the error shape the current stub already uses, for continuity).
- **`GET /health`** — unchanged contract, `{"status": "ok"}`, now backed by a
  real Symfony controller instead of the PHP stub script.

Both `/api/flights*` controllers use hand-rolled query logic against Doctrine
repositories (custom finder methods on `FlightRepository`/`SeatRepository`),
not API Platform — the two endpoints are simple, explicit searches rather
than generic resource CRUD, so the extra framework/config weight of API
Platform isn't justified here.

## Fixtures

`doctrine/doctrine-fixtures-bundle`, one fixture class (`FlightFixtures`)
loading roughly 5–6 realistic flights, each with several seats across
economy/business/first. Auto-loaded by the entrypoint guard described above
in dev/test, and runnable manually anywhere via
`bin/console doctrine:fixtures:load`.

## Testing

PHPUnit + Symfony's `WebTestCase`, run against a dedicated `flight_search_test`
database (separate from the dev DB, configured via `.env.test`). Coverage:
- `/api/flights` with no filters, with each filter individually, and combined.
- `/api/flights/{id}/seats` happy path and 404 for a missing flight.
- `/health`.

## Documentation

- **`README.md`** (service-level, replaces nothing — first real one for this
  repo): endpoints with example requests/responses, env vars, how to run
  migrations/fixtures/tests both via Docker and locally.
- **`CLAUDE.md`** (service-level): short. Points back to
  `aeronuk-ops/README.md` for system-wide architecture/ADRs/event contracts
  (this service publishes/consumes no events, so there's little of that to
  restate here). States this service's own settled decisions so they aren't
  re-litigated later: hand-rolled controllers + Doctrine (not API Platform),
  Symfony 8.1 / PHP 8.4 / MySQL 8.4 (superseding the original briefing's
  7/8.3 pin, per explicit user request), fixtures auto-load in dev/test only.

## Out of scope (explicitly deferred)

- Pagination on the search endpoint.
- OpenAPI/Swagger generation (nelmio/api-doc-bundle).
- Anything related to booking, payment, or notification — this is a pure
  read service per the briefing; it publishes and consumes no events.
