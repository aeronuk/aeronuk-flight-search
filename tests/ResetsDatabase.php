<?php

namespace AeroNuk\FlightSearch\Tests;

use Doctrine\ORM\EntityManagerInterface;

trait ResetsDatabase
{
    private function resetDatabase(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM seat');
        $connection->executeStatement('DELETE FROM flight');
    }
}
