# aeronuk-flight-search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the throwaway PHP stub in `aeronuk-flight-search` with a real Symfony API service backed by MySQL, implementing `GET /api/flights` (search) and `GET /api/flights/{id}/seats`, per `docs/superpowers/specs/2026-06-22-flight-search-implementation-design.md`.

**Architecture:** Symfony skeleton app, hand-rolled controllers querying Doctrine ORM repositories, JSON responses via `symfony/serializer`. Runs in Docker via the official `dunglas/symfony-docker` FrankenPHP template (pinned to PHP 8.4), with its entrypoint script extended to auto-load fixtures in dev/test only.

**Tech Stack:** Symfony (latest stable, 8.1 as of this writing), PHP 8.4, MySQL 8.4, Doctrine ORM + Migrations + Fixtures, PHPUnit + Symfony test-pack, FrankenPHP.

## Global Constraints

- Latest stable Symfony (8.1 as of this writing) — use no `--version` flag so tooling always resolves latest stable, don't hardcode a version number that will go stale.
- PHP 8.4 exactly (not 8.3 — Symfony 8.1 requires PHP 8.4.0+; not 8.5, the official template's current default, because the user asked for 8.3 or 8.4 specifically).
- MySQL 8.4 (LTS) — image `mysql:8.4`.
- Existing Docker wiring must not change: service/container name `aeronuk-flight-search`, port `8000`, DB service `flight-search-db`, network `aeronuk-network` — `aeronuk-ops/nginx/nginx.conf` and the root `docker-compose.yml` already hardcode these names.
- Hand-rolled controllers + Doctrine ORM repositories — no API Platform.
- No pagination on `GET /api/flights`.
- Error response shape: `{"error": "..."}`, matching the stub's existing convention.
- `GET /health` contract unchanged: `{"status": "ok"}`.
- Fixtures auto-load only when `APP_ENV` is `dev` or `test` AND the `flight` table is empty. Never in `prod`.
- No OpenAPI/Swagger generation this pass.
- All composer/console commands run via `docker compose exec aeronuk-flight-search ...` once the container exists (Task 2 onward), so everything runs against the exact PHP 8.4 / extension set the app will actually run on — not whatever PHP version is on the host.

---

### Task 1: Scaffold the Symfony skeleton project

**Files:**
- Create: entire Symfony skeleton tree (`composer.json`, `composer.lock`, `symfony.lock`, `config/`, `src/`, `bin/`, `public/`, `.env`, `.gitignore`, etc.)
- Delete: `public/index.php`, `Dockerfile` (throwaway stub artifacts, superseded by the real skeleton and Task 2's Docker setup)
- Keep as-is for now: `docker-compose.yml`, `.env.example`, `docs/` (touched in later tasks)

**Interfaces:**
- Produces: a working `bin/console` and `composer.json` that Task 2 (Docker) and Task 3 (Doctrine packages) build on.

- [ ] **Step 1: Install the Symfony CLI**

```bash
brew install symfony-cli/tap/symfony-cli
symfony check:requirements
```

Expected: requirements check completes and reports the locally installed PHP (8.4.x) as compatible.

- [ ] **Step 2: Remove the throwaway stub's app files**

```bash
cd /Users/yourwebmaker/Code/aeronuk/aeronuk-flight-search
rm -rf public Dockerfile
```

- [ ] **Step 3: Scaffold the skeleton into a temp dir, then merge into the existing repo**

The existing directory already has `.git/`, `docs/`, `docker-compose.yml`, `.env.example`, `.gitignore` — `symfony new` requires an empty target, so scaffold elsewhere and merge:

```bash
symfony new /tmp/aeronuk-flight-search-scaffold
rsync -a --exclude='.git' /tmp/aeronuk-flight-search-scaffold/ /Users/yourwebmaker/Code/aeronuk/aeronuk-flight-search/
rm -rf /tmp/aeronuk-flight-search-scaffold
```

- [ ] **Step 4: Confirm `.gitignore` keeps `.env` committed, per Symfony convention**

Open the now-merged `.gitignore` and confirm it ignores `.env.local`, `.env.*.local`, `.env.local.php` but **not** plain `.env` (Symfony commits `.env` with non-secret defaults; this differs from the simpler "`.env` is gitignored" convention the other four AeroNuk stub services use — that's intentional and gets called out in this service's `CLAUDE.md` in Task 10).

- [ ] **Step 5: Verify the skeleton boots**

```bash
cd /Users/yourwebmaker/Code/aeronuk/aeronuk-flight-search
composer validate
php bin/console about
```

Expected: `composer validate` reports no errors; `about` prints the Symfony version (8.1.x) and PHP version.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Scaffold Symfony skeleton project, replacing the throwaway stub"
```

---

### Task 2: Docker setup (FrankenPHP, PHP 8.4, MySQL 8.4)

**Files:**
- Create: `frankenphp/docker-entrypoint.sh`, `frankenphp/Caddyfile`, `frankenphp/conf.d/10-app.ini`, `.dockerignore`
- Create: `Dockerfile` (trimmed dev-only version of the official template)
- Modify: `docker-compose.yml` (replace stub's plain-PHP service with the FrankenPHP build, bump MySQL to 8.4)

**Interfaces:**
- Produces: a running `aeronuk-flight-search` container on port 8000, with `flight-search-db` (MySQL 8.4) reachable from it at host `flight-search-db:3306`. Later tasks run all `composer`/`bin/console`/`bin/phpunit` commands via `docker compose exec aeronuk-flight-search ...` against this container.

- [ ] **Step 1: Create `frankenphp/conf.d/10-app.ini`**

```ini
expose_php = 0
date.timezone = UTC
apc.enable_cli = 1
session.use_strict_mode = 1
zend.detect_unicode = 0

; https://symfony.com/doc/current/performance.html
realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 32531
opcache.memory_consumption = 256
opcache.enable_file_override = 1
```

- [ ] **Step 2: Create `frankenphp/Caddyfile`**

A trimmed version of the official template's Caddyfile — no Mercure, no Vulcain, no TLS/ACME (this service sits behind `aeronuk-ops`'s nginx on plain HTTP, port 8000 internally):

```caddyfile
{
	skip_install_trust
	frankenphp
}

:8000 {
	root /app/public
	encode zstd br gzip

	@phpRoute {
		not file {path}
	}
	rewrite @phpRoute index.php

	@frontController path index.php
	php @frontController {
		worker {
			file ./public/index.php
			{$FRANKENPHP_WORKER_CONFIG}
		}
	}

	file_server {
		hide *.php
	}
}
```

- [ ] **Step 3: Create `frankenphp/docker-entrypoint.sh`**

The official `dunglas/symfony-docker` entrypoint, used as-is (it already auto-runs `composer install` and Doctrine migrations — see the design spec). Task 9 adds one extra line to this same file for fixture auto-loading; this step establishes the baseline:

```sh
#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	# Display information about the current project
	# Or about an error in project initialization
	php bin/console -V

	if grep -q ^DATABASE_URL= .env; then
		echo 'Waiting for database to be ready...'
		ATTEMPTS_LEFT_TO_REACH_DATABASE=60
		until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# If the Doctrine command exits with 255, an unrecoverable error occurred
				ATTEMPTS_LEFT_TO_REACH_DATABASE=0
				break
			fi
			sleep 1
			ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
			echo "Still waiting for database to be ready... Or maybe the database is not reachable. $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
		done

		if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
			echo 'The database is not up or not reachable:'
			echo "$DATABASE_ERROR"
			exit 1
		else
			echo 'The database is now ready and reachable'
		fi

		if [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
```

- [ ] **Step 4: Create `Dockerfile`**

Trimmed to a single `frankenphp_dev` target — this project never deploys beyond local Docker Compose (per the AeroNuk briefing), so the official template's separate prod-builder/prod-final stages are dropped:

```dockerfile
#syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.4 AS frankenphp_upstream

FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		zip \
		pdo_mysql
	rm -rf /var/lib/apt/lists/*
EOF

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV FRANKENPHP_WORKER_CONFIG=watch

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]
```

- [ ] **Step 5: Create `.dockerignore`**

```
**/*.log
**/*.md
**/*.php~
**/*.dist.php
**/*.dist
**/*.cache
**/._*
**/.dockerignore
**/.DS_Store
**/.git/
**/.gitattributes
**/.gitignore
**/.gitmodules
**/compose.*.yaml
**/compose.*.yml
**/docker-compose.*.yaml
**/docker-compose.*.yml
**/docker-compose.yaml
**/docker-compose.yml
**/Dockerfile
**/Thumbs.db
docs/
public/bundles/
tests/
var/
vendor/
.env.*.local
.env.local
.env.local.php
.env.test
```

- [ ] **Step 6: Replace `docker-compose.yml`**

Keeps the same service/container names, port, network, and MySQL service name the stub already used — only the build and image change:

```yaml
services:

  aeronuk-flight-search:
    build:
      context: .
      target: frankenphp_dev
    container_name: aeronuk-flight-search
    restart: unless-stopped
    ports:
      - "8000:8000"
    volumes:
      - .:/app
      - /app/var
    environment:
      APP_ENV: dev
    depends_on:
      - flight-search-db
    networks:
      - aeronuk-network

  flight-search-db:
    image: mysql:8.4
    container_name: aeronuk-flight-search-db
    environment:
      MYSQL_DATABASE: flight_search
      MYSQL_USER: aeronuk
      MYSQL_PASSWORD: aeronuk
      MYSQL_ROOT_PASSWORD: aeronuk
    volumes:
      - flight_search_mysql_data:/var/lib/mysql
    networks:
      - aeronuk-network

volumes:
  flight_search_mysql_data:

networks:
  aeronuk-network:
    name: aeronuk-network
    driver: bridge
```

- [ ] **Step 7: Build and boot, verify**

```bash
cd /Users/yourwebmaker/Code/aeronuk/aeronuk-flight-search
docker compose up --build -d
sleep 5
docker ps --filter "name=aeronuk-flight-search" --format "table {{.Names}}\t{{.Status}}"
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/
```

Expected: both containers show `Up`; the curl prints `404` (Symfony's "no route" response — there are no routes yet, which is correct at this point, and proves the app booted rather than crashing).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add FrankenPHP/PHP 8.4 Docker setup with MySQL 8.4"
```

---

### Task 3: Doctrine ORM, Migrations, serializer, uid packages

**Files:**
- Modify: `composer.json`, `composer.lock`, `.env`
- Create: `.env.test`, `config/packages/doctrine.yaml` (generated by Flex recipe, then reviewed)

**Interfaces:**
- Produces: a working `DATABASE_URL` for both `dev` (db `flight_search`) and `test` (db `flight_search_test`) environments, and the `doctrine:*` console commands Task 4 needs.

- [ ] **Step 1: Require the packages**

```bash
docker compose exec aeronuk-flight-search composer require doctrine/doctrine-bundle doctrine/orm doctrine/doctrine-migrations-bundle symfony/serializer-pack symfony/uid
docker compose exec aeronuk-flight-search composer require --dev symfony/maker-bundle doctrine/doctrine-fixtures-bundle symfony/test-pack
docker compose restart aeronuk-flight-search
```

- [ ] **Step 2: Point `.env`'s `DATABASE_URL` at the real MySQL service**

The Flex recipe appends a commented-out Postgres example to `.env`. Replace it with:

```
DATABASE_URL="mysql://aeronuk:aeronuk@flight-search-db:3306/flight_search?serverVersion=8.4&charset=utf8mb4"
```

- [ ] **Step 3: Create `.env.test`**

Committed (per Symfony convention — see Task 1 Step 4), points at a separate test database on the same MySQL service:

```
DATABASE_URL="mysql://aeronuk:aeronuk@flight-search-db:3306/flight_search_test?serverVersion=8.4&charset=utf8mb4"
```

- [ ] **Step 4: Create the test database**

```bash
docker compose exec aeronuk-flight-search php bin/console doctrine:database:create --env=test --if-not-exists
```

Expected: `Created database "flight_search_test"`.

- [ ] **Step 5: Verify Doctrine can connect in both envs**

```bash
docker compose exec aeronuk-flight-search php bin/console dbal:run-sql "SELECT 1"
docker compose exec aeronuk-flight-search php bin/console dbal:run-sql "SELECT 1" --env=test
```

Expected: both print a successful one-row result, no connection errors.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Add Doctrine ORM/Migrations, serializer, and uid packages"
```

---

### Task 4: Flight and Seat entities + migration

**Files:**
- Create: `src/Entity/Flight.php`, `src/Entity/Seat.php`
- Create: `src/Repository/FlightRepository.php`, `src/Repository/SeatRepository.php` (empty repository classes for now — query methods come in Task 5)
- Create: `migrations/VersionXXXXXXXXXXXXXX.php` (generated, exact filename depends on timestamp)

**Interfaces:**
- Produces: `Flight` (getters: `getId(): string`, `getFlightNumber(): string`, `getOrigin(): string`, `getDestination(): string`, `getDepartureTime(): \DateTimeImmutable`, `getArrivalTime(): \DateTimeImmutable`, `getPrice(): string`, `getCurrency(): string`) and `Seat` (getters: `getId(): string`, `getFlight(): Flight` [marked `#[Ignore]` for serialization], `getSeatNumber(): string`, `getClass(): string`, `isAvailable(): bool`). Task 5's repositories and Task 6's controllers consume both.

- [ ] **Step 1: Create `src/Repository/FlightRepository.php`**

```php
<?php

namespace App\Repository;

use App\Entity\Flight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Flight::class);
    }
}
```

- [ ] **Step 2: Create `src/Repository/SeatRepository.php`**

```php
<?php

namespace App\Repository;

use App\Entity\Seat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seat::class);
    }
}
```

- [ ] **Step 3: Create `src/Entity/Flight.php`**

```php
<?php

namespace App\Entity;

use App\Repository\FlightRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FlightRepository::class)]
#[ORM\Table(name: 'flight')]
class Flight
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\Column(length: 20)]
    private string $flightNumber;

    #[ORM\Column(length: 3)]
    private string $origin;

    #[ORM\Column(length: 3)]
    private string $destination;

    #[ORM\Column]
    private \DateTimeImmutable $departureTime;

    #[ORM\Column]
    private \DateTimeImmutable $arrivalTime;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(length: 3)]
    private string $currency;

    public function __construct(
        string $flightNumber,
        string $origin,
        string $destination,
        \DateTimeImmutable $departureTime,
        \DateTimeImmutable $arrivalTime,
        string $price,
        string $currency,
    ) {
        $this->id = (string) Uuid::v7();
        $this->flightNumber = $flightNumber;
        $this->origin = strtoupper($origin);
        $this->destination = strtoupper($destination);
        $this->departureTime = $departureTime;
        $this->arrivalTime = $arrivalTime;
        $this->price = $price;
        $this->currency = $currency;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFlightNumber(): string
    {
        return $this->flightNumber;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function getDepartureTime(): \DateTimeImmutable
    {
        return $this->departureTime;
    }

    public function getArrivalTime(): \DateTimeImmutable
    {
        return $this->arrivalTime;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
```

- [ ] **Step 4: Create `src/Entity/Seat.php`**

```php
<?php

namespace App\Entity;

use App\Repository\SeatRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SeatRepository::class)]
#[ORM\Table(name: 'seat')]
class Seat
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Flight::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Flight $flight;

    #[ORM\Column(length: 4)]
    private string $seatNumber;

    #[ORM\Column(length: 10)]
    private string $class;

    #[ORM\Column]
    private bool $available;

    public function __construct(Flight $flight, string $seatNumber, string $class, bool $available = true)
    {
        $this->id = (string) Uuid::v7();
        $this->flight = $flight;
        $this->seatNumber = $seatNumber;
        $this->class = $class;
        $this->available = $available;
    }

    public function getId(): string
    {
        return $this->id;
    }

    #[Ignore]
    public function getFlight(): Flight
    {
        return $this->flight;
    }

    public function getSeatNumber(): string
    {
        return $this->seatNumber;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
```

- [ ] **Step 5: Generate and review the migration**

```bash
docker compose exec aeronuk-flight-search php bin/console make:migration --no-interaction
```

Expected: a new file under `migrations/`. Open it and confirm it creates both `flight` and `seat` tables with the columns above, plus a foreign key from `seat.flight_id` to `flight.id`.

- [ ] **Step 6: Run the migration in both dev and test**

```bash
docker compose exec aeronuk-flight-search php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec aeronuk-flight-search php bin/console doctrine:migrations:migrate --no-interaction --env=test
```

- [ ] **Step 7: Verify the schema matches the mapping**

```bash
docker compose exec aeronuk-flight-search php bin/console doctrine:schema:validate
```

Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add Flight and Seat entities with migration"
```

---

### Task 5: Repository search methods + tests

**Files:**
- Modify: `src/Repository/FlightRepository.php` (add `search()`)
- Modify: `src/Repository/SeatRepository.php` (add `findByFlight()`)
- Create: `tests/ResetsDatabase.php`
- Create: `tests/Repository/FlightRepositoryTest.php`
- Create: `tests/Repository/SeatRepositoryTest.php`

**Interfaces:**
- Consumes: `Flight`, `Seat` from Task 4.
- Produces: `FlightRepository::search(?string $origin, ?string $destination, ?\DateTimeImmutable $date): Flight[]` and `SeatRepository::findByFlight(Flight $flight): Seat[]` — Task 6's controllers call both directly.

- [ ] **Step 1: Create the shared test helper `tests/ResetsDatabase.php`**

```php
<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

trait ResetsDatabase
{
    private function resetDatabase(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM seat');
        $connection->executeStatement('DELETE FROM flight');
    }
}
```

- [ ] **Step 2: Write the failing test `tests/Repository/FlightRepositoryTest.php`**

```php
<?php

namespace App\Tests\Repository;

use App\Entity\Flight;
use App\Repository\FlightRepository;
use App\Tests\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class FlightRepositoryTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;
    private FlightRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(FlightRepository::class);
        $this->resetDatabase($this->em);
    }

    private function persistFlight(string $number, string $origin, string $destination, string $departure): Flight
    {
        $flight = new Flight(
            $number,
            $origin,
            $destination,
            new \DateTimeImmutable($departure),
            new \DateTimeImmutable($departure . ' +2 hours'),
            '199.99',
            'USD',
        );
        $this->em->persist($flight);
        $this->em->flush();

        return $flight;
    }

    public function testSearchWithNoFiltersReturnsAllFlights(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $results = $this->repository->search(null, null, null);

        self::assertCount(2, $results);
    }

    public function testSearchFiltersByOriginCaseInsensitively(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $results = $this->repository->search('jfk', null, null);

        self::assertCount(1, $results);
        self::assertSame('AN1', $results[0]->getFlightNumber());
    }

    public function testSearchFiltersByDestination(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $results = $this->repository->search(null, 'sfo', null);

        self::assertCount(1, $results);
        self::assertSame('AN2', $results[0]->getFlightNumber());
    }

    public function testSearchFiltersByDate(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $results = $this->repository->search(null, null, new \DateTimeImmutable('2026-07-02'));

        self::assertCount(1, $results);
        self::assertSame('AN2', $results[0]->getFlightNumber());
    }

    public function testSearchCombinesFilters(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'JFK', 'SFO', '2026-07-01 12:00:00');

        $results = $this->repository->search('JFK', 'SFO', new \DateTimeImmutable('2026-07-01'));

        self::assertCount(1, $results);
        self::assertSame('AN2', $results[0]->getFlightNumber());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Repository/FlightRepositoryTest.php
```

Expected: FAIL — `Call to undefined method App\Repository\FlightRepository::search()`.

- [ ] **Step 4: Implement `FlightRepository::search()`**

Replace the contents of `src/Repository/FlightRepository.php` with:

```php
<?php

namespace App\Repository;

use App\Entity\Flight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Flight::class);
    }

    /**
     * @return Flight[]
     */
    public function search(?string $origin, ?string $destination, ?\DateTimeImmutable $date): array
    {
        $qb = $this->createQueryBuilder('f')->orderBy('f.departureTime', 'ASC');

        if ($origin !== null) {
            $qb->andWhere('f.origin = :origin')->setParameter('origin', strtoupper($origin));
        }

        if ($destination !== null) {
            $qb->andWhere('f.destination = :destination')->setParameter('destination', strtoupper($destination));
        }

        if ($date !== null) {
            $qb->andWhere('f.departureTime BETWEEN :start AND :end')
                ->setParameter('start', $date->setTime(0, 0, 0))
                ->setParameter('end', $date->setTime(23, 59, 59));
        }

        return $qb->getQuery()->getResult();
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Repository/FlightRepositoryTest.php
```

Expected: all 5 tests pass.

- [ ] **Step 6: Write the failing test `tests/Repository/SeatRepositoryTest.php`**

```php
<?php

namespace App\Tests\Repository;

use App\Entity\Flight;
use App\Entity\Seat;
use App\Repository\SeatRepository;
use App\Tests\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SeatRepositoryTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;
    private SeatRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(SeatRepository::class);
        $this->resetDatabase($this->em);
    }

    public function testFindByFlightReturnsOnlyThatFlightsSeats(): void
    {
        $flightA = new Flight('AN1', 'JFK', 'LAX', new \DateTimeImmutable('2026-07-01 08:00:00'), new \DateTimeImmutable('2026-07-01 11:00:00'), '199.99', 'USD');
        $flightB = new Flight('AN2', 'ORD', 'SFO', new \DateTimeImmutable('2026-07-02 08:00:00'), new \DateTimeImmutable('2026-07-02 10:00:00'), '149.99', 'USD');
        $this->em->persist($flightA);
        $this->em->persist($flightB);

        $this->em->persist(new Seat($flightA, '1A', 'business'));
        $this->em->persist(new Seat($flightA, '12A', 'economy'));
        $this->em->persist(new Seat($flightB, '1A', 'business'));
        $this->em->flush();

        $results = $this->repository->findByFlight($flightA);

        self::assertCount(2, $results);
        self::assertSame('1A', $results[0]->getSeatNumber());
        self::assertSame('12A', $results[1]->getSeatNumber());
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Repository/SeatRepositoryTest.php
```

Expected: FAIL — `Call to undefined method App\Repository\SeatRepository::findByFlight()`.

- [ ] **Step 8: Implement `SeatRepository::findByFlight()`**

Replace the contents of `src/Repository/SeatRepository.php` with:

```php
<?php

namespace App\Repository;

use App\Entity\Flight;
use App\Entity\Seat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seat::class);
    }

    /**
     * @return Seat[]
     */
    public function findByFlight(Flight $flight): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.flight = :flight')
            ->setParameter('flight', $flight)
            ->orderBy('s.seatNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
```

- [ ] **Step 9: Run the test to verify it passes**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Repository/SeatRepositoryTest.php
```

Expected: test passes.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "Add FlightRepository::search() and SeatRepository::findByFlight() with tests"
```

---

### Task 6: GET /api/flights and GET /api/flights/{id}/seats

**Files:**
- Create: `src/Controller/FlightController.php`
- Create: `tests/Controller/FlightControllerTest.php`

**Interfaces:**
- Consumes: `FlightRepository::search()`, `SeatRepository::findByFlight()` from Task 5; `SerializerInterface` from `symfony/serializer-pack` (Task 3).
- Produces: routes `flights_search` (`GET /api/flights`) and `flights_seats` (`GET /api/flights/{id}/seats`).

- [ ] **Step 1: Write the failing test `tests/Controller/FlightControllerTest.php`**

```php
<?php

namespace App\Tests\Controller;

use App\Entity\Flight;
use App\Entity\Seat;
use App\Tests\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FlightControllerTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetDatabase($this->em);
    }

    private function persistFlight(string $number, string $origin, string $destination, string $departure): Flight
    {
        $flight = new Flight(
            $number,
            $origin,
            $destination,
            new \DateTimeImmutable($departure),
            new \DateTimeImmutable($departure . ' +2 hours'),
            '199.99',
            'USD',
        );
        $this->em->persist($flight);
        $this->em->flush();

        return $flight;
    }

    public function testSearchWithNoFiltersReturnsAllFlights(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $this->client->request('GET', '/api/flights');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
    }

    public function testSearchByOriginAndDestination(): void
    {
        $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->persistFlight('AN2', 'ORD', 'SFO', '2026-07-02 08:00:00');

        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('AN1', $data[0]['flightNumber']);
    }

    public function testSearchWithInvalidDateReturns400(): void
    {
        $this->client->request('GET', '/api/flights?date=not-a-date');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSeatsHappyPath(): void
    {
        $flight = $this->persistFlight('AN1', 'JFK', 'LAX', '2026-07-01 08:00:00');
        $this->em->persist(new Seat($flight, '12A', 'economy'));
        $this->em->flush();

        $this->client->request('GET', '/api/flights/' . $flight->getId() . '/seats');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('12A', $data[0]['seatNumber']);
        self::assertArrayNotHasKey('flight', $data[0]);
    }

    public function testSeatsReturns404ForMissingFlight(): void
    {
        $this->client->request('GET', '/api/flights/00000000-0000-0000-0000-000000000000/seats');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Controller/FlightControllerTest.php
```

Expected: FAIL — 404 for `/api/flights` (no route defined yet).

- [ ] **Step 3: Implement `src/Controller/FlightController.php`**

```php
<?php

namespace App\Controller;

use App\Repository\FlightRepository;
use App\Repository\SeatRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class FlightController
{
    public function __construct(
        private FlightRepository $flightRepository,
        private SeatRepository $seatRepository,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/api/flights', name: 'flights_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $origin = $request->query->get('origin');
        $destination = $request->query->get('destination');
        $dateParam = $request->query->get('date');

        $date = null;
        if ($dateParam !== null) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateParam);
            if ($date === false) {
                return new JsonResponse(['error' => 'date must be in YYYY-MM-DD format'], 400);
            }
        }

        $flights = $this->flightRepository->search($origin, $destination, $date ?: null);

        return JsonResponse::fromJsonString($this->serializer->serialize($flights, 'json'));
    }

    #[Route('/api/flights/{id}/seats', name: 'flights_seats', methods: ['GET'])]
    public function seats(string $id): JsonResponse
    {
        $flight = $this->flightRepository->find($id);

        if ($flight === null) {
            return new JsonResponse(['error' => 'flight not found'], 404);
        }

        $seats = $this->seatRepository->findByFlight($flight);

        return JsonResponse::fromJsonString($this->serializer->serialize($seats, 'json'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Controller/FlightControllerTest.php
```

Expected: all 5 tests pass. (`testSeatsHappyPath`'s `assertArrayNotHasKey('flight', ...)` confirms the `#[Ignore]` attribute on `Seat::getFlight()` from Task 4 is working.)

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add GET /api/flights and GET /api/flights/{id}/seats"
```

---

### Task 7: GET /health

**Files:**
- Create: `src/Controller/HealthController.php`
- Create: `tests/Controller/HealthControllerTest.php`

**Interfaces:**
- Produces: route `health` (`GET /health`), unchanged contract from the stub.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    public function testHealthReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], json_decode($client->getResponse()->getContent(), true));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Controller/HealthControllerTest.php
```

Expected: FAIL with a 404 (no `/health` route yet).

- [ ] **Step 3: Implement `src/Controller/HealthController.php`**

```php
<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Controller/HealthControllerTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "Add GET /health"
```

---

### Task 8: Fixtures + dev/test auto-load on container start

**Files:**
- Create: `src/DataFixtures/FlightFixtures.php`
- Create: `src/Command/LoadFixturesIfEmptyCommand.php`
- Create: `tests/Command/LoadFixturesIfEmptyCommandTest.php`
- Modify: `frankenphp/docker-entrypoint.sh` (one line added after the migrations step)

**Interfaces:**
- Produces: console command `app:load-fixtures-if-empty` (no arguments) — only loads fixtures when `kernel.environment` is `dev` or `test` AND the `flight` table is empty.

- [ ] **Step 1: Create `src/DataFixtures/FlightFixtures.php`**

```php
<?php

namespace App\DataFixtures;

use App\Entity\Flight;
use App\Entity\Seat;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class FlightFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $flights = [
            ['AN101', 'JFK', 'LAX', '2026-07-01 08:00:00', '2026-07-01 11:30:00', '299.99', 'USD'],
            ['AN102', 'LAX', 'JFK', '2026-07-01 13:00:00', '2026-07-01 21:15:00', '319.99', 'USD'],
            ['AN201', 'ORD', 'SFO', '2026-07-02 09:15:00', '2026-07-02 11:45:00', '249.50', 'USD'],
            ['AN305', 'JFK', 'LHR', '2026-07-03 19:00:00', '2026-07-04 07:00:00', '649.00', 'USD'],
            ['AN410', 'SFO', 'NRT', '2026-07-05 00:30:00', '2026-07-06 05:00:00', '899.00', 'USD'],
        ];

        foreach ($flights as [$number, $origin, $destination, $departure, $arrival, $price, $currency]) {
            $flight = new Flight(
                $number,
                $origin,
                $destination,
                new \DateTimeImmutable($departure),
                new \DateTimeImmutable($arrival),
                $price,
                $currency,
            );
            $manager->persist($flight);

            foreach (['1A' => 'business', '12A' => 'economy', '12B' => 'economy', '12C' => 'economy'] as $seatNumber => $class) {
                $manager->persist(new Seat($flight, $seatNumber, $class));
            }
        }

        $manager->flush();
    }
}
```

- [ ] **Step 2: Write the failing test `tests/Command/LoadFixturesIfEmptyCommandTest.php`**

```php
<?php

namespace App\Tests\Command;

use App\Entity\Flight;
use App\Tests\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class LoadFixturesIfEmptyCommandTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resetDatabase($this->em);
    }

    private function flightCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(f.id) FROM ' . Flight::class . ' f')->getSingleScalarResult();
    }

    public function testLoadsFixturesWhenTableIsEmpty(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:load-fixtures-if-empty'));
        $tester->execute([]);

        self::assertGreaterThan(0, $this->flightCount());
    }

    public function testSkipsLoadingWhenTableAlreadyHasData(): void
    {
        $flight = new Flight('AN1', 'JFK', 'LAX', new \DateTimeImmutable('2026-07-01 08:00:00'), new \DateTimeImmutable('2026-07-01 11:00:00'), '199.99', 'USD');
        $this->em->persist($flight);
        $this->em->flush();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:load-fixtures-if-empty'));
        $tester->execute([]);

        self::assertSame(1, $this->flightCount());
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Command/LoadFixturesIfEmptyCommandTest.php
```

Expected: FAIL — command `app:load-fixtures-if-empty` does not exist.

- [ ] **Step 4: Implement `src/Command/LoadFixturesIfEmptyCommand.php`**

```php
<?php

namespace App\Command;

use App\Entity\Flight;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:load-fixtures-if-empty', description: 'Loads fixtures only in dev/test, and only if the flight table is empty')]
class LoadFixturesIfEmptyCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($this->environment, ['dev', 'test'], true)) {
            $output->writeln(sprintf('Skipping fixtures: environment is "%s", not dev/test.', $this->environment));

            return Command::SUCCESS;
        }

        $count = (int) $this->em->createQuery('SELECT COUNT(f.id) FROM ' . Flight::class . ' f')->getSingleScalarResult();

        if ($count > 0) {
            $output->writeln('Skipping fixtures: flight table already has data.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Loading fixtures (environment: %s)...', $this->environment));

        $application = $this->getApplication();
        if ($application === null) {
            return Command::FAILURE;
        }

        return $application->find('doctrine:fixtures:load')->run(new ArrayInput(['--no-interaction' => true]), $output);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
docker compose exec aeronuk-flight-search php bin/phpunit tests/Command/LoadFixturesIfEmptyCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Wire the command into the entrypoint**

In `frankenphp/docker-entrypoint.sh`, find this block (added in Task 2):

```sh
		if [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
```

Replace it with:

```sh
		if [ "$(find ./migrations -iname '*.php' -print -quit)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
			php bin/console app:load-fixtures-if-empty
		fi
```

- [ ] **Step 7: Verify end-to-end via a real container restart**

```bash
docker compose exec aeronuk-flight-search php bin/console dbal:run-sql "DELETE FROM seat"
docker compose exec aeronuk-flight-search php bin/console dbal:run-sql "DELETE FROM flight"
docker compose restart aeronuk-flight-search
sleep 5
docker compose logs aeronuk-flight-search --tail 20
curl -s http://localhost:8000/api/flights | head -c 200
```

Expected: the logs show `Loading fixtures (environment: dev)...`, and the curl output is a JSON array of 5 flights.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Add fixtures with dev/test-only auto-load on container start"
```

---

### Task 9: Service-level README.md and CLAUDE.md

**Files:**
- Create: `README.md`
- Create: `CLAUDE.md`

**Interfaces:**
- None — documentation only.

- [ ] **Step 1: Write `README.md`**

```markdown
# aeronuk-flight-search

Flight search service for the AeroNuk platform. Symfony 8.1 + PHP 8.4 + MySQL 8.4, served via FrankenPHP. Part of the `aeronuk-ops` Docker Compose stack — see that repo's `README.md` for the full system architecture.

## Endpoints

### `GET /api/flights`

Query params, all optional and combinable:

- `origin` — 3-letter airport code, case-insensitive exact match
- `destination` — 3-letter airport code, case-insensitive exact match
- `date` — `YYYY-MM-DD`, matches flights departing on that calendar date

```bash
curl "http://localhost:8000/api/flights?origin=JFK&destination=LAX&date=2026-07-01"
```

```json
[
  {
    "id": "...",
    "flightNumber": "AN101",
    "origin": "JFK",
    "destination": "LAX",
    "departureTime": "2026-07-01T08:00:00+00:00",
    "arrivalTime": "2026-07-01T11:30:00+00:00",
    "price": "299.99",
    "currency": "USD"
  }
]
```

### `GET /api/flights/{id}/seats`

```bash
curl "http://localhost:8000/api/flights/<flight-id>/seats"
```

```json
[
  { "id": "...", "seatNumber": "1A", "class": "business", "available": true },
  { "id": "...", "seatNumber": "12A", "class": "economy", "available": true }
]
```

Returns `404` with `{"error": "flight not found"}` if `{id}` doesn't match a flight.

### `GET /health`

```json
{ "status": "ok" }
```

## Running

From `aeronuk-ops`: `docker compose up --build` boots this service along with the rest of the system.

Standalone:

```bash
docker compose up --build
```

Migrations and (in `dev`/`test` only) fixtures run automatically on container start — see `CLAUDE.md` for details.

## Local development

```bash
# Run a console command
docker compose exec aeronuk-flight-search php bin/console <command>

# Run the test suite (uses the flight_search_test database)
docker compose exec aeronuk-flight-search php bin/phpunit

# Re-seed fixtures manually
docker compose exec aeronuk-flight-search php bin/console doctrine:fixtures:load

# Generate a new migration after changing an entity
docker compose exec aeronuk-flight-search php bin/console make:migration
```

## Environment variables

| Variable | Purpose | Set in |
|----------|---------|--------|
| `DATABASE_URL` | MySQL connection string | `.env` (dev), `.env.test` (test) |
| `APP_ENV` | Symfony environment | `docker-compose.yml` |
```

- [ ] **Step 2: Write `CLAUDE.md`**

```markdown
# aeronuk-flight-search — Claude Code guide

Part of the AeroNuk platform. For system-wide architecture, ADRs, RabbitMQ
topology, and event contracts, see `aeronuk-ops/README.md` — this service
publishes and consumes no events, so there's little of that relevant here.

See `README.md` in this repo for endpoints, running instructions, and local
dev commands.

## Settled decisions — don't re-litigate

- **Hand-rolled controllers + Doctrine ORM, not API Platform.** The two
  endpoints are simple, explicit searches, not generic resource CRUD.
- **Symfony 8.1 / PHP 8.4 / MySQL 8.4**, not the original AeroNuk briefing's
  "Symfony 7 + PHP 8.3" pin — superseded per explicit request to track latest
  stable versions. If asked to bump versions again, that's a deliberate,
  explicit decision each time, not a default to drift toward.
- **`.env` is committed, not gitignored** — this differs from the simpler
  convention the other four AeroNuk services use (`.env.example` committed,
  `.env` gitignored). This service follows Symfony's own convention instead:
  `.env` holds non-secret defaults; `.env.local`/`.env.*.local` (gitignored)
  hold real overrides. There's no real secret in `.env` here (dev-only DB
  creds already visible in plaintext in `docker-compose.yml` anyway).
- **No pagination** on `GET /api/flights` — the dataset is small by design.
- **Fixtures auto-load only in `dev`/`test`, only when the `flight` table is
  empty** (`src/Command/LoadFixturesIfEmptyCommand.php`, wired into
  `frankenphp/docker-entrypoint.sh`). Never in `prod`.
- **No OpenAPI/Swagger** generated yet.

Full rationale: `docs/superpowers/specs/2026-06-22-flight-search-implementation-design.md`.
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "Add service-level README.md and CLAUDE.md"
```

---

## Self-Review

**Spec coverage:** Symfony 8.1/PHP 8.4/MySQL 8.4 (Tasks 1–3); Docker via dunglas/symfony-docker pinned to 8.4 (Task 2); entrypoint auto-migrations (Task 2, inherited from official script) + guarded fixture auto-load (Task 8); Flight/Seat entities + migration (Task 4); hand-rolled controllers + Doctrine (Tasks 5–6); search filters incl. case-insensitivity and date-as-calendar-day (Task 5); 404 error shape (Task 6); `/health` unchanged contract (Task 7); fixtures (Task 8); functional tests throughout (Tasks 5–8); README.md + CLAUDE.md (Task 9). No spec section is without a task.

**Placeholder scan:** no TBD/TODO; every step has complete, runnable code or exact commands with expected output.

**Type consistency:** `Flight::getId()`/`Seat::getId()` return `string` everywhere they're used (repositories, controllers, tests). `FlightRepository::search()` signature (`?string, ?string, ?\DateTimeImmutable`) matches its Task 6 controller call site. `SeatRepository::findByFlight(Flight $flight)` matches the controller's `seats()` usage.
