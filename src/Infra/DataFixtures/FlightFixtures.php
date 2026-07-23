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
 * Generates a realistic multi-departure daily flight schedule for every
 * directed AirportCode pair (30 routes, origin !== destination): five
 * scheduled departures per route per day — 05:00, 09:30, 13:45, 18:15,
 * and a final departure that's either a conventional 23:50 evening flight
 * or, for a minority of routes, a 01:00 red-eye landing on the following
 * calendar day — recurring daily from today through self::MONTHS_AHEAD
 * months out (~60 days, ~300 occurrences per route, ~9,000 flights total)
 * — so almost any origin/destination/date search a developer tries
 * against the local stack returns several results, the way a real search
 * would.
 */
class FlightFixtures extends Fixture
{
    /** The daily departure schedule recurs through this many months out from today. */
    private const int MONTHS_AHEAD = 2;

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

            // Flushing (and clearing the identity map) once per route, rather
            // than once at the very end, keeps Doctrine's unit-of-work change
            // tracking bounded at ~300 flights/~1,200 seats per batch instead
            // of accumulating all ~9,000 flights/~36,000 seats in memory at
            // once — without this, fixture loading at this volume exhausts
            // PHP's default memory_limit.
            $manager->flush();
            $manager->clear();
        }
    }

    private function loadRoute(
        ObjectManager $manager,
        int $routeNumber,
        AirportCode $origin,
        AirportCode $destination,
        DateTimeImmutable $today,
        DateTimeImmutable $horizon,
    ): void {
        $flightNumberPrefix = sprintf('AN%02d', $routeNumber);
        $schedule           = self::dailySchedule($routeNumber);
        $durationHours      = self::durationHours($origin, $destination);
        $amount             = self::amount($routeNumber, $durationHours);

        $occurrence    = 1;
        $departureDate = $today;

        while ($departureDate <= $horizon) {
            foreach ($schedule as [$hour, $minute, $dayOffset]) {
                $departureDay = $dayOffset > 0
                    ? $departureDate->modify(sprintf('+%d day', $dayOffset))
                    : $departureDate;
                $departure    = $departureDay->setTime($hour, $minute);
                $arrival      = $departure->add(new DateInterval(sprintf('PT%dH', $durationHours)));

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

                $occurrence++;
            }

            $departureDate = $departureDate->modify('+1 day');
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
     * A route's daily departure schedule: four fixed times spread across
     * the morning through evening (05:00, 09:30, 13:45, 18:15), plus a
     * final departure that's either a conventional late-evening flight
     * (23:50) or, for routes flagged by isRedEyeRoute(), a red-eye landing
     * on the following calendar day (01:00) — so most routes' last flight
     * of the day is conventional, and only a minority run past midnight,
     * matching how red-eyes work at real airports.
     *
     * @return list<array{0: int, 1: int, 2: int}> [hour (24h), minute,
     *         day offset] for each scheduled departure, in chronological
     *         order
     */
    private static function dailySchedule(int $routeNumber): array
    {
        $schedule = [
            [5, 0, 0],
            [9, 30, 0],
            [13, 45, 0],
            [18, 15, 0],
        ];

        $schedule[] = self::isRedEyeRoute($routeNumber) ? [1, 0, 1] : [23, 50, 0];

        return $schedule;
    }

    /**
     * A deterministic minority of routes (every fifth) run their last
     * flight of the day as a red-eye departing at 01:00 the following
     * calendar day, rather than the conventional 23:50 — reflecting that
     * red-eyes are the exception, not the norm, at real airports.
     */
    private static function isRedEyeRoute(int $routeNumber): bool
    {
        return $routeNumber % 5 === 0;
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
