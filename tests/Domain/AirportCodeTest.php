<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AirportCodeTest extends TestCase
{
    #[Test]
    public function tryFromReturnsMatchingCaseForKnownCode(): void
    {
        self::assertSame(AirportCode::JFK, $this->tryFrom('JFK'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownCode(): void
    {
        self::assertNull($this->tryFrom('ZZZ'));
    }

    /**
     * Routed through a `string`-typed parameter (rather than calling
     * `AirportCode::tryFrom()` directly with a string literal) so PHPStan
     * doesn't narrow the argument to a literal type and flag the
     * assertions below as trivially true.
     */
    private function tryFrom(string $code): AirportCode|null
    {
        return AirportCode::tryFrom($code);
    }
}
