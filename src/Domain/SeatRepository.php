<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use Doctrine\ORM\EntityManagerInterface;

class SeatRepository
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
