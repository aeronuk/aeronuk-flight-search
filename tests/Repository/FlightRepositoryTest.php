<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Tests\Repository;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Repository\FlightRepository;
use AeroNuk\FlightSearch\Tests\ResetsDatabase;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class FlightRepositoryTest extends KernelTestCase
{
    use ResetsDatabase;

    private EntityManagerInterface $em;
    private FlightRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(FlightRepository::class);
        $this->resetDatabase($this->em);
    }

    private function persistFlight(
        string $number,
        AirportCode $origin,
        AirportCode $destination,
        string $departure,
    ): Flight {
        $flight = new Flight(
            (string) Uuid::v7(),
            $number,
            $origin,
            $destination,
            new DateTimeImmutable($departure),
            new DateTimeImmutable($departure . ' +2 hours'),
            new Money('199.99', 'USD'),
        );
        $this->em->persist($flight);
        $this->em->flush();

        return $flight;
    }

    #[Test]
    public function searchReturnsFlightsMatchingOriginDestinationAndDate(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');
        $this->persistFlight('AN2', AirportCode::JFK, AirportCode::SFO, '2026-07-01 12:00:00');
        $this->persistFlight('AN3', AirportCode::JFK, AirportCode::LAX, '2026-07-02 08:00:00');

        $results = $this->repository->search(AirportCode::JFK, AirportCode::LAX, new DateTimeImmutable('2026-07-01'));

        self::assertCount(1, $results);
        self::assertSame('AN1', $results[0]->flightNumber);
    }

    #[Test]
    public function searchReturnsEmptyArrayWhenNoFlightMatches(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');

        $results = $this->repository->search(AirportCode::ORD, AirportCode::SFO, new DateTimeImmutable('2026-07-01'));

        self::assertCount(0, $results);
    }
}
