# aeronuk-flight-search — Claude Code guide

Part of the AeroNuk platform. For system-wide architecture, ADRs, RabbitMQ
topology, and event contracts, see `aeronuk-ops/README.md` — this service
publishes and consumes no events, so there's little of that relevant here.

See `README.md` in this repo for endpoints, running instructions, and local
dev commands.

## Settled decisions — don't re-litigate

- **Root namespace is `AeroNuk\FlightSearch`**, not Symfony's default `App`
  (reconfigured in `composer.json`, `config/services.yaml`, `src/Kernel.php`,
  `public/index.php`, `bin/console`). Every new class goes under it.
- **Hand-rolled controllers + Doctrine ORM, not API Platform.** The two
  endpoints are simple, explicit searches, not generic resource CRUD.
- **Symfony 8.1 / PHP 8.4 / MySQL 8.4**, not the original AeroNuk briefing's
  "Symfony 7 + PHP 8.3" pin — superseded per explicit request to track latest
  stable versions. If asked to bump versions again, that's a deliberate,
  explicit decision each time, not a default to drift toward.
- **Repositories are plain DI services, not `ServiceEntityRepository`
  subclasses.** `FlightRepository`/`SeatRepository` take `EntityManagerInterface`
  via constructor injection; entities have no `repositoryClass`.
- **Entity/VO IDs are always passed in from the caller**, never generated
  inside a constructor — keeps construction deterministic and testable.
- **No getters on `Flight`/`Seat`/`Money`** — all properties are `public
  readonly`, and `symfony/serializer` reads them directly. `#[Ignore]` (to
  hide `Seat::$flight`) lives on the property, not a method.
- **`AirportCode` is a closed enum** (`JFK`/`LAX`/`ORD`/`SFO`/`LHR`/`NRT` — the
  airports used in the fixtures), not open-ended string validation. Adding a
  new airport means adding an enum case. This is what makes "search for an
  airport that doesn't exist" a real, enforced error (400) rather than a
  silently-empty result.
- **`Money` is a Doctrine embeddable VO** (`amount` + `currency`), replacing
  flat `price`/`currency` fields — the API now nests them:
  `"price": {"amount": "...", "currency": "..."}`. Two things had to line up
  for this to actually persist correctly (see `src/ValueObject/Money.php`,
  `src/Entity/Flight.php`, `config/packages/doctrine.yaml`):
  - `Flight::$price`'s `#[ORM\Embedded]` attribute needs `columnPrefix: false`.
    Doctrine's default behavior prefixes embedded columns with the property
    name (`price_amount`, `price_currency`); the actual `flight` table columns
    are flat `price`/`currency` (see the migration in `migrations/`), matching
    `Money`'s own `#[ORM\Column(name: 'price', ...)]` /
    `#[ORM\Column(name: 'currency', ...)]` attributes. Without
    `columnPrefix: false` those names collide and schema generation breaks.
  - Doctrine's attribute-mapping `dir` in `config/packages/doctrine.yaml` is
    `%kernel.project_dir%/src` (the whole `src/` tree), not narrowed to
    `src/Entity`. `Money` lives under `src/ValueObject/`, not `src/Entity/`,
    and still needs to be discovered as a mapped embeddable.
- **`Flight`'s constructor rejects `departureTime >= arrivalTime`.**
- **`Seat::$flight` uses `fetch: 'EAGER'`**, not Doctrine's default lazy
  proxy loading — proxies have known bugs with `readonly` properties: eager
  loading sidesteps that risk, and is cheap since a seat always has exactly
  one flight. This is one-directional only — it does **not** make "find
  seats for a flight" automatic, so `SeatRepository::findByFlight()` stays.
  Deliberately not replaced with an inverse `Flight::$seats` collection.
- **`GET /api/flights` requires `origin`, `destination`, AND `date`** — not
  an optional combinable filter set. Missing any one returns `400`.
- **`FlightRepository::get(string $id): Flight` always returns a `Flight`
  or throws `FlightNotFoundException`** — never `null`. There is no
  `find()` method; this avoids null-checks at call sites.
- **`.env` is committed, not gitignored** — this differs from the simpler
  convention the other four AeroNuk services use (`.env.example` committed,
  `.env` gitignored). This service follows Symfony's own convention instead:
  `.env` holds non-secret defaults; `.env.local`/`.env.*.local` (gitignored)
  hold real overrides. There's no real secret in `.env` here (dev-only DB
  creds already visible in plaintext in `docker-compose.yml` anyway). (Ignore
  the stale "This file is safe to commit — .env is gitignored" comment header
  in `.env.example` — leftover from an earlier draft of that file; `.gitignore`
  only excludes `.env.local`/`.env.*.local`, and `.env` itself is tracked.)
- **No pagination** on `GET /api/flights` — the dataset is small by design.
- **Seat numbers are zero-padded** (`'01A'`, not `'1A'`) in both
  `src/DataFixtures/FlightFixtures.php` and every test that creates a `Seat`
  (e.g. `tests/Repository/SeatRepositoryTest.php`). `SeatRepository::findByFlight()`
  orders results with a plain lexicographic `ORDER BY seatNumber ASC` — without
  zero-padding, `'12A'` sorts before `'1A'` as a string. Zero-padding the
  fixture/test data was the fix; the repository's plain string `ORDER BY`
  stays as-is. If new fixtures/tests add seats, pad single-digit row numbers
  the same way.
- **Fixtures auto-load only in `dev`/`test`, only when the `flight` table is
  empty** (`src/Command/LoadFixturesIfEmptyCommand.php`, wired into
  `frankenphp/docker-entrypoint.sh`). Never in `prod`. The command runs
  `doctrine:fixtures:load` as a nested command via
  `$application->find('doctrine:fixtures:load')->run($fixturesInput, $output)`.
  That nested run needs `$fixturesInput->setInteractive(false)` called
  explicitly on the `ArrayInput` — passing `['--no-interaction' => true]` into
  `ArrayInput`'s constructor is **not** enough on its own. `--no-interaction`
  is only specially parsed from raw argv by `Application::doRun()`; an
  `ArrayInput` built programmatically skips that parsing path entirely, so
  without the explicit `setInteractive(false)` call, `doctrine:fixtures:load`'s
  destructive-purge confirmation prompt still blocks (and silently resolves to
  "no" in non-tty contexts, so fixtures silently fail to load).
- **MySQL only grants the `aeronuk` user access to `flight_search` (the dev
  database) out of the box** — the official `mysql` image's
  `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD` env vars only provision
  grants for that one database on first init. `flight_search_test` needs its
  own grant. `mysql-init/01-create-test-db.sql` (`CREATE DATABASE IF NOT
  EXISTS flight_search_test; GRANT ALL PRIVILEGES ON flight_search_test.* TO
  'aeronuk'@'%'; FLUSH PRIVILEGES;`) is mounted read-only at
  `/docker-entrypoint-initdb.d/` in `docker-compose.yml`, which the MySQL
  image executes on container init. This only runs against a **fresh**
  volume — if `flight_search_mysql_data` already exists, the init scripts in
  `docker-entrypoint-initdb.d/` are skipped, since MySQL only runs them when
  initializing a brand-new data directory. Recreate the volume (`docker
  compose down -v` for this service, not the whole stack) if `flight_search_test`
  access is ever missing.
- **No OpenAPI/Swagger** generated yet.

Full rationale: `docs/superpowers/specs/2026-06-22-flight-search-implementation-design.md`.
