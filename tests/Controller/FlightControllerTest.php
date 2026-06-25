<?php

namespace AeroNuk\FlightSearch\Tests\Controller;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Entity\Seat;
use AeroNuk\FlightSearch\Tests\ResetsDatabase;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class FlightControllerTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetDatabase($this->em);
    }

    private function persistFlight(string $number, AirportCode $origin, AirportCode $destination, string $departure): Flight
    {
        $flight = new Flight(
            (string) Uuid::v7(),
            $number,
            $origin,
            $destination,
            new \DateTimeImmutable($departure),
            new \DateTimeImmutable($departure . ' +2 hours'),
            new Money('199.99', 'USD'),
        );
        $this->em->persist($flight);
        $this->em->flush();

        return $flight;
    }

    public function testSearchReturnsMatchingFlights(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');
        $this->persistFlight('AN2', AirportCode::ORD, AirportCode::SFO, '2026-07-02 08:00:00');

        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX&date=2026-07-01');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('AN1', $data[0]['flightNumber']);
    }

    public function testFlightPriceSerializesAsNestedMoneyObject(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');

        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX&date=2026-07-01');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['amount' => '199.99', 'currency' => 'USD'], $data[0]['price']);
    }

    public function testSearchRequiresOrigin(): void
    {
        $this->client->request('GET', '/api/flights?destination=LAX&date=2026-07-01');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSearchRequiresDestination(): void
    {
        $this->client->request('GET', '/api/flights?origin=JFK&date=2026-07-01');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSearchRequiresDate(): void
    {
        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSearchWithInvalidDateReturns400(): void
    {
        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX&date=not-a-date');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSearchWithUnknownAirportCodeReturns400(): void
    {
        $this->client->request('GET', '/api/flights?origin=ZZZ&destination=LAX&date=2026-07-01');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSeatsHappyPath(): void
    {
        $flight = $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');
        $this->em->persist(new Seat((string) Uuid::v7(), $flight, '12A', 'economy'));
        $this->em->flush();

        $this->client->request('GET', '/api/flights/' . $flight->id . '/seats');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('12A', $data[0]['seatNumber']);
        self::assertArrayNotHasKey('flight', $data[0]);
    }

    public function testSeatsReturns404ForMissingFlight(): void
    {
        $this->client->request('GET', '/api/flights/00000000-0000-0000-0000-000000000000/seats');

        self::assertResponseStatusCodeSame(404);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }
}
