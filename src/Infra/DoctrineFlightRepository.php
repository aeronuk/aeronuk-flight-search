<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Infra;

use AeroNuk\FlightSearch\Domain\Flight;
use AeroNuk\FlightSearch\Domain\FlightNotFound;
use AeroNuk\FlightSearch\Domain\FlightRepository;
use AeroNuk\FlightSearch\Domain\Route;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

class DoctrineFlightRepository implements FlightRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(string $id): Flight
    {
        $flight = $this->em->find(Flight::class, $id);

        if ($flight === null) {
            throw new FlightNotFound($id);
        }

        return $flight;
    }

    /** @return Flight[] */
    public function search(Route $route, DateTimeImmutable $date): array
    {
        return $this->em->createQueryBuilder()
            ->select('f')
            ->from(Flight::class, 'f')
            ->andWhere('f.route.origin = :origin')
            ->andWhere('f.route.destination = :destination')
            ->andWhere('f.departureTime BETWEEN :start AND :end')
            ->setParameter('origin', $route->origin)
            ->setParameter('destination', $route->destination)
            ->setParameter('start', $date->setTime(0, 0, 0))
            ->setParameter('end', $date->setTime(23, 59, 59))
            ->orderBy('f.departureTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
