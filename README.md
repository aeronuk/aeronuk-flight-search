# aeronuk-flight-search

Flight search service for the AeroNuk platform. Symfony 8.1 + PHP 8.4 + MySQL 8.4, served via FrankenPHP, root namespace `AeroNuk\FlightSearch`. Part of the `aeronuk-ops` Docker Compose stack — see that repo's `README.md` for the full system architecture.

## Endpoints

### `GET /api/flights`

Query params, **all three required** — this is a fixed route-on-a-day search, not a flexible filter:

- `origin` — 3-letter airport code (one of `JFK`, `LAX`, `ORD`, `SFO`, `LHR`, `NRT`), case-insensitive
- `destination` — same closed set as `origin`
- `date` — `YYYY-MM-DD`, matches flights departing on that calendar date

Returns `400` with `{"error": "..."}` if any of the three is missing, if `date` is malformed, or if `origin`/`destination` is outside the known airport set.

```bash
curl "http://localhost:8000/api/flights?origin=JFK&destination=LAX&date=2026-07-01"
```

```json
[
  {
    "id": "019efed1-fd60-779d-9456-bd9660f12255",
    "flightNumber": "AN101",
    "origin": "JFK",
    "destination": "LAX",
    "departureTime": "2026-07-01T08:00:00+00:00",
    "arrivalTime": "2026-07-01T11:30:00+00:00",
    "price": { "amount": "299.99", "currency": "USD" }
  }
]
```

### `GET /api/flights/{id}/seats`

```bash
curl "http://localhost:8000/api/flights/<flight-id>/seats"
```

```json
[
  { "id": "...", "seatNumber": "01A", "class": "business", "available": true },
  { "id": "...", "seatNumber": "12A", "class": "economy", "available": true },
  { "id": "...", "seatNumber": "12B", "class": "economy", "available": true },
  { "id": "...", "seatNumber": "12C", "class": "economy", "available": true }
]
```

Note `seatNumber` is zero-padded (`01A`, not `1A`) — see `CLAUDE.md` for why.

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
| `APP_ENV` | Symfony environment | `docker-compose.yml` (`environment:` key) and `.env.dev`/`.env.test` |
