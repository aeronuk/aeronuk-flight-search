<?php

namespace AeroNuk\FlightSearch\Tests\Command;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Tests\ResetsDatabase;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class LoadFixturesIfEmptyCommandTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resetDatabase($this->em);
    }

    private function flightCount(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(f.id) FROM ' . Flight::class . ' f')->getSingleScalarResult();
    }

    public function testLoadsFixturesWhenTableIsEmpty(): void
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:load-fixtures-if-empty'));
        $tester->execute([]);

        self::assertGreaterThan(0, $this->flightCount());
    }

    public function testSkipsLoadingWhenTableAlreadyHasData(): void
    {
        $flight = new Flight((string) Uuid::v7(), 'AN1', AirportCode::JFK, AirportCode::LAX, new \DateTimeImmutable('2026-07-01 08:00:00'), new \DateTimeImmutable('2026-07-01 11:00:00'), new Money('199.99', 'USD'));
        $this->em->persist($flight);
        $this->em->flush();

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:load-fixtures-if-empty'));
        $tester->execute([]);

        self::assertSame(1, $this->flightCount());
    }
}
