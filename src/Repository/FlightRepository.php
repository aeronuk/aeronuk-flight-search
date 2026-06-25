<?php

namespace AeroNuk\FlightSearch\Repository;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Exception\FlightNotFoundException;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use Doctrine\ORM\EntityManagerInterface;

class FlightRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(string $id): Flight
    {
        $flight = $this->em->find(Flight::class, $id);

        if ($flight === null) {
            throw new FlightNotFoundException($id);
        }

        return $flight;
    }

    /**
     * @return Flight[]
     */
    public function search(AirportCode $origin, AirportCode $destination, \DateTimeImmutable $date): array
    {
        return $this->em->createQueryBuilder()
            ->select('f')
            ->from(Flight::class, 'f')
            ->andWhere('f.origin = :origin')
            ->andWhere('f.destination = :destination')
            ->andWhere('f.departureTime BETWEEN :start AND :end')
            ->setParameter('origin', $origin)
            ->setParameter('destination', $destination)
            ->setParameter('start', $date->setTime(0, 0, 0))
            ->setParameter('end', $date->setTime(23, 59, 59))
            ->orderBy('f.departureTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
