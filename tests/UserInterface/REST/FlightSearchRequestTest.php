<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\UserInterface\REST;

use AeroNuk\FlightSearch\Domain\AirportCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

class FlightSearchRequestTest extends TestCase
{
    #[Test]
    public function airportCodesReturnsAllAirportCodeValues(): void
    {
        self::assertSame(
            array_column(AirportCode::cases(), 'value'),
            FlightSearchRequest::airportCodes(),
        );
    }
}
