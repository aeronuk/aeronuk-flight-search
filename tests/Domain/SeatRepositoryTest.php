<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use AeroNuk\FlightSearch\Tests\ResetsDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class SeatRepositoryTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;
    private SeatRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(SeatRepository::class);
        $this->resetDatabase($this->em);
    }

    #[Test]
    public function findByFlightReturnsOnlyThatFlightsSeatsAndEagerLoadsTheFlight(): void
    {
        $flightA = new Flight(
            (string) Uuid::v7(),
            'AN1',
            AirportCode::JFK,
            AirportCode::LAX,
            new DateTimeImmutable('2026-07-01 08:00:00'),
            new DateTimeImmutable('2026-07-01 11:00:00'),
            new Money('199.99', 'USD'),
        );
        $flightB = new Flight(
            (string) Uuid::v7(),
            'AN2',
            AirportCode::ORD,
            AirportCode::SFO,
            new DateTimeImmutable('2026-07-02 08:00:00'),
            new DateTimeImmutable('2026-07-02 10:00:00'),
            new Money('149.99', 'USD'),
        );
        $this->em->persist($flightA);
        $this->em->persist($flightB);

        $this->em->persist(new Seat((string) Uuid::v7(), $flightA, '01A', 'business'));
        $this->em->persist(new Seat((string) Uuid::v7(), $flightA, '12A', 'economy'));
        $this->em->persist(new Seat((string) Uuid::v7(), $flightB, '01A', 'business'));
        $this->em->flush();
        $flightAId = $flightA->id;
        $this->em->clear();

        $reloadedFlightA = $this->em->find(Flight::class, $flightAId);
        self::assertInstanceOf(Flight::class, $reloadedFlightA);

        $results = $this->repository->findByFlight($reloadedFlightA);

        self::assertCount(2, $results);
        self::assertSame('01A', $results[0]->seatNumber);
        self::assertSame('12A', $results[1]->seatNumber);
        self::assertSame('AN1', $results[0]->flight->flightNumber);
    }
}
