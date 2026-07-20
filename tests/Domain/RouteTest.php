<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    #[Test]
    public function validConstructionSucceeds(): void
    {
        $route = new Route(AirportCode::JFK, AirportCode::LAX);

        self::assertSame(AirportCode::JFK, $route->origin);
        self::assertSame(AirportCode::LAX, $route->destination);
    }

    #[Test]
    public function sameOriginAndDestinationThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route(AirportCode::JFK, AirportCode::JFK);
    }
}
