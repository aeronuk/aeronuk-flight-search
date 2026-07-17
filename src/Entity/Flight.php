<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Entity;

use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'flight')]
class Flight
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36, unique: true)]
        public readonly string $id,
        #[ORM\Column(length: 20)]
        public readonly string $flightNumber,
        #[ORM\Column(type: 'string', length: 3, enumType: AirportCode::class)]
        public readonly AirportCode $origin,
        #[ORM\Column(type: 'string', length: 3, enumType: AirportCode::class)]
        public readonly AirportCode $destination,
        #[ORM\Column]
        public readonly DateTimeImmutable $departureTime,
        #[ORM\Column]
        public readonly DateTimeImmutable $arrivalTime,
        #[ORM\Embedded(class: Money::class, columnPrefix: false)]
        public readonly Money $price,
    ) {
        if ($departureTime >= $arrivalTime) {
            throw new InvalidArgumentException('departureTime must be before arrivalTime.');
        }
    }
}
