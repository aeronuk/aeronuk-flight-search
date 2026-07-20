<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

use function sprintf;

#[ORM\Embeddable]
final class Route
{
    public function __construct(
        #[ORM\Column(name: 'origin', type: 'string', length: 3, enumType: AirportCode::class)]
        public readonly AirportCode $origin,
        #[ORM\Column(name: 'destination', type: 'string', length: 3, enumType: AirportCode::class)]
        public readonly AirportCode $destination,
    ) {
        if ($origin === $destination) {
            throw new InvalidArgumentException(
                sprintf('origin and destination must differ, both were "%s".', $origin->value),
            );
        }
    }
}
