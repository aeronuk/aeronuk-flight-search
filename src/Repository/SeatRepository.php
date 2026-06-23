<?php

namespace AeroNuk\FlightSearch\Repository;

use Doctrine\ORM\EntityManagerInterface;

class SeatRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }
}
