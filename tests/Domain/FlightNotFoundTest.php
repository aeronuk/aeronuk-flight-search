<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FlightNotFoundTest extends TestCase
{
    #[Test]
    public function messageIncludesFlightId(): void
    {
        $exception = new FlightNotFound('flight-123');

        self::assertStringContainsString('flight-123', $exception->getMessage());
    }
}
