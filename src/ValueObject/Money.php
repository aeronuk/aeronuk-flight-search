<?php

namespace AeroNuk\FlightSearch\ValueObject;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class Money
{
    public function __construct(
        #[ORM\Column(name: 'price', type: 'decimal', precision: 10, scale: 2)]
        public readonly string $amount,
        #[ORM\Column(name: 'currency', type: 'string', length: 3)]
        public readonly string $currency,
    ) {
        if (!preg_match('/^\d+\.\d{2}$/', $amount)) {
            throw new \InvalidArgumentException(sprintf('Invalid money amount "%s", expected format like "299.99".', $amount));
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException(sprintf('Invalid currency code "%s", expected a 3-letter ISO code.', $currency));
        }
    }
}
