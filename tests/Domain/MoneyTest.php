<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function validConstructionSucceeds(): void
    {
        $money = new Money('299.99', 'USD');

        self::assertSame('299.99', $money->amount);
        self::assertSame('USD', $money->currency);
    }

    #[Test]
    public function malformedAmountThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money('299.9', 'USD');
    }

    #[Test]
    public function malformedCurrencyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money('299.99', 'usd');
    }
}
