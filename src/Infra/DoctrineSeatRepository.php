<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Infra;

use AeroNuk\FlightSearch\Domain\Flight;
use AeroNuk\FlightSearch\Domain\Seat;
use AeroNuk\FlightSearch\Domain\SeatRepository;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineSeatRepository implements SeatRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return Seat[] */
    public function findByFlight(Flight $flight): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(Seat::class, 's')
            ->andWhere('s.flight = :flight')
            ->setParameter('flight', $flight)
            ->orderBy('s.seatNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
