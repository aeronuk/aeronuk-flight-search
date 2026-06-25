<?php

namespace AeroNuk\FlightSearch\Repository;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Entity\Seat;
use Doctrine\ORM\EntityManagerInterface;

class SeatRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return Seat[]
     */
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
