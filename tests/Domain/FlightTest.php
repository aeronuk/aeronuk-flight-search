<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FlightTest extends TestCase
{
    private function makeFlight(
        string $id = 'flight-1',
        string $flightNumber = 'AN101',
        Route|null $route = null,
        DateTimeImmutable|null $departureTime = null,
        DateTimeImmutable|null $arrivalTime = null,
        Money|null $price = null,
    ): Flight {
        return new Flight(
            $id,
            $flightNumber,
            $route ?? new Route(AirportCode::JFK, AirportCode::LAX),
            $departureTime ?? new DateTimeImmutable('2026-07-01 08:00:00'),
            $arrivalTime ?? new DateTimeImmutable('2026-07-01 11:30:00'),
            $price ?? new Money('299.99', 'USD'),
        );
    }

    #[Test]
    public function validConstructionExposesGivenProperties(): void
    {
        $departure = new DateTimeImmutable('2026-07-01 08:00:00');
        $arrival   = new DateTimeImmutable('2026-07-01 11:30:00');
        $price     = new Money('299.99', 'USD');
        $route     = new Route(AirportCode::JFK, AirportCode::LAX);

        $flight = $this->makeFlight(
            id: 'flight-1',
            flightNumber: 'AN101',
            route: $route,
            departureTime: $departure,
            arrivalTime: $arrival,
            price: $price,
        );

        self::assertSame('flight-1', $flight->id);
        self::assertSame('AN101', $flight->flightNumber);
        self::assertSame($route, $flight->route);
        self::assertSame($departure, $flight->departureTime);
        self::assertSame($arrival, $flight->arrivalTime);
        self::assertSame($price, $flight->price);
    }

    #[Test]
    public function departureTimeAtOrAfterArrivalTimeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeFlight(
            departureTime: new DateTimeImmutable('2026-07-01 12:00:00'),
            arrivalTime: new DateTimeImmutable('2026-07-01 12:00:00'),
        );
    }
}
