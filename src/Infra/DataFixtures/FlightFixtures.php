<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Infra\DataFixtures;

use AeroNuk\FlightSearch\Domain\AirportCode;
use AeroNuk\FlightSearch\Domain\Flight;
use AeroNuk\FlightSearch\Domain\Money;
use AeroNuk\FlightSearch\Domain\Route;
use AeroNuk\FlightSearch\Domain\Seat;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

use function number_format;
use function sprintf;

/**
 * Generates one recurring daily flight for every directed AirportCode
 * pair (30 routes, origin !== destination), from today through
 * self::MONTHS_AHEAD months out (~300 occurrences per route, ~9,000
 * flights total) — so almost any origin/destination/date search a
 * developer tries against the local stack returns a result.
 */
class FlightFixtures extends Fixture
{
    /** Daily flights recur through this many months out from today. */
    private const int MONTHS_AHEAD = 10;

    private const array SEAT_MAP = [
        '01A' => 'business',
        '12A' => 'economy',
        '12B' => 'economy',
        '12C' => 'economy',
    ];

    /**
     * Coarse region per airport, used only to derive a plausible flight
     * duration below — not a domain concept.
     */
    private const array REGIONS = [
        'JFK' => 'NA',
        'LAX' => 'NA',
        'ORD' => 'NA',
        'SFO' => 'NA',
        'LHR' => 'EU',
        'NRT' => 'AS',
    ];

    /**
     * Flight duration in whole hours, keyed by "{originRegion}-{destinationRegion}".
     * Covers every combination reachable with one airport in EU (LHR) and
     * one in AS (NRT), so the lookup below always finds a match.
     */
    private const array REGION_DURATION_HOURS = [
        'NA-NA' => 3,
        'NA-EU' => 8,
        'EU-NA' => 8,
        'NA-AS' => 12,
        'AS-NA' => 12,
        'EU-AS' => 11,
        'AS-EU' => 11,
    ];

    public function load(ObjectManager $manager): void
    {
        $today   = new DateTimeImmutable('today');
        $horizon = $today->modify(sprintf('+%d months', self::MONTHS_AHEAD));

        $routeNumber = 0;
        foreach (self::routes() as [$origin, $destination]) {
            $routeNumber++;

            $this->loadRoute($manager, $routeNumber, $origin, $destination, $today, $horizon);
        }

        $manager->flush();
    }

    private function loadRoute(
        ObjectManager $manager,
        int $routeNumber,
        AirportCode $origin,
        AirportCode $destination,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
    ): void {
        $flightNumberPrefix                = sprintf('AN%02d', $routeNumber);
        [$departureHour, $departureMinute] = self::departureTimeOfDay($routeNumber);
        $durationHours                     = self::durationHours($origin, $destination);
        $amount                            = self::amount($routeNumber, $durationHours);

        $occurrence    = 1;
        $departureDate = $today;

        while ($departureDate <= $horizon) {
            $departure = $departureDate->setTime($departureHour, $departureMinute);
            $arrival   = $departure->add(new DateInterval(sprintf('PT%dH', $durationHours)));

            $flight = new Flight(
                (string) Uuid::v7(),
                sprintf('%s%03d', $flightNumberPrefix, $occurrence),
                new Route($origin, $destination),
                $departure,
                $arrival,
                new Money($amount, 'USD'),
            );
            $manager->persist($flight);

            foreach (self::SEAT_MAP as $seatNumber => $class) {
                $manager->persist(new Seat((string) Uuid::v7(), $flight, $seatNumber, $class));
            }

            $departureDate = $departureDate->modify('+1 day');
            $occurrence++;
        }
    }

    /** @return list<array{0: AirportCode, 1: AirportCode}> every directed AirportCode pair, origin !== destination */
    private static function routes(): array
    {
        $routes = [];
        foreach (AirportCode::cases() as $origin) {
            foreach (AirportCode::cases() as $destination) {
                if ($origin === $destination) {
                    continue;
                }

                $routes[] = [$origin, $destination];
            }
        }

        return $routes;
    }

    /**
     * A per-route, deterministic time of day so flights aren't all
     * clustered at midnight — varies across routes purely for realism.
     *
     * @return array{0: int, 1: int} hour (24h), minute
     */
    private static function departureTimeOfDay(int $routeNumber): array
    {
        return [6 + ($routeNumber % 14), ($routeNumber % 4) * 15];
    }

    private static function durationHours(AirportCode $origin, AirportCode $destination): int
    {
        $regionPair = self::REGIONS[$origin->value] . '-' . self::REGIONS[$destination->value];

        // Every combination reachable with one airport in EU (LHR) and one in
        // AS (NRT) is covered above; this fallback only exists to satisfy
        // static analysis, which can't infer that origin and destination are
        // always in different regions when they share one (LHR/NRT).
        return self::REGION_DURATION_HOURS[$regionPair] ?? 6;
    }

    /** @return numeric-string a plausible fare, formatted like "299.99" */
    private static function amount(int $routeNumber, int $durationHours): string
    {
        $base = 79.99 + ($durationHours * 45) + (($routeNumber % 5) * 10);

        return number_format($base, 2, '.', '');
    }
}
