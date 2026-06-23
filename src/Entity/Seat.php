<?php

namespace AeroNuk\FlightSearch\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ORM\Entity]
#[ORM\Table(name: 'seat')]
class Seat
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36, unique: true)]
        public readonly string $id,

        #[ORM\ManyToOne(targetEntity: Flight::class, fetch: 'EAGER')]
        #[ORM\JoinColumn(nullable: false)]
        #[Ignore]
        public readonly Flight $flight,

        #[ORM\Column(length: 4)]
        public readonly string $seatNumber,

        #[ORM\Column(length: 10)]
        public readonly string $class,

        #[ORM\Column]
        public readonly bool $available = true,
    ) {
    }
}
