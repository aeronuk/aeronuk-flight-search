<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeatTest extends TestCase
{
    private function makeFlight(): Flight
    {
        return new Flight(
            'flight-1',
            'AN101',
            new Route(AirportCode::JFK, AirportCode::LAX),
            new DateTimeImmutable('2026-07-01 08:00:00'),
            new DateTimeImmutable('2026-07-01 11:30:00'),
            new Money('299.99', 'USD'),
        );
    }

    #[Test]
    public function constructorAssignsAllGivenProperties(): void
    {
        $flight = $this->makeFlight();

        $seat = new Seat('seat-1', $flight, '12A', 'economy', false);

        self::assertSame('seat-1', $seat->id);
        self::assertSame($flight, $seat->flight);
        self::assertSame('12A', $seat->seatNumber);
        self::assertSame('economy', $seat->class);
        self::assertFalse($seat->available);
    }

    #[Test]
    public function availableDefaultsToTrue(): void
    {
        $seat = new Seat('seat-1', $this->makeFlight(), '12A', 'economy');

        self::assertTrue($seat->available);
    }
}
