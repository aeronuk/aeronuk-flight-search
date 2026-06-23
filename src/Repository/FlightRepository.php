<?php

namespace AeroNuk\FlightSearch\Repository;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Exception\FlightNotFoundException;
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
}
