<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Tests\Controller;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Entity\Seat;
use AeroNuk\FlightSearch\Tests\DecodesJsonResponse;
use AeroNuk\FlightSearch\Tests\ResetsDatabase;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class FlightControllerTest extends WebTestCase
{
    use DecodesJsonResponse;
    use ResetsDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
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

    public function testSearchReturnsMatchingFlights(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');
        $this->persistFlight('AN2', AirportCode::ORD, AirportCode::SFO, '2026-07-02 08:00:00');

        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX&date=2026-07-01');

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse($this->client);
        self::assertCount(1, $data);
        self::assertIsArray($data[0]);
        self::assertSame('AN1', $data[0]['flightNumber']);
    }

    public function testFlightPriceSerializesAsNestedMoneyObject(): void
    {
        $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');

        $this->client->request('GET', '/api/flights?origin=JFK&destination=LAX&date=2026-07-01');

        $data = $this->decodeJsonResponse($this->client);
        self::assertIsArray($data[0]);
        self::assertSame(['amount' => '199.99', 'currency' => 'USD'], $data[0]['price']);
    }

    #[DataProvider('invalidSearchQueryProvider')]
    public function testSearchWithInvalidQueryReturns400(string $queryString): void
    {
        $this->client->request('GET', '/api/flights?' . $queryString);

        self::assertResponseStatusCodeSame(400);
        $data = $this->decodeJsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSearchQueryProvider(): iterable
    {
        yield 'missing origin' => ['destination=LAX&date=2026-07-01'];
        yield 'missing destination' => ['origin=JFK&date=2026-07-01'];
        yield 'missing date' => ['origin=JFK&destination=LAX'];
        yield 'blank origin' => ['origin=&destination=LAX&date=2026-07-01'];
        yield 'blank destination' => ['origin=JFK&destination=&date=2026-07-01'];
        yield 'blank date' => ['origin=JFK&destination=LAX&date='];
        yield 'invalid date format' => ['origin=JFK&destination=LAX&date=not-a-date'];
        yield 'unknown origin airport code' => ['origin=ZZZ&destination=LAX&date=2026-07-01'];
        yield 'unknown destination airport code' => ['origin=JFK&destination=ZZZ&date=2026-07-01'];
    }

    public function testSeatsHappyPath(): void
    {
        $flight = $this->persistFlight('AN1', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00');
        $this->em->persist(new Seat((string) Uuid::v7(), $flight, '12A', 'economy'));
        $this->em->flush();

        $this->client->request('GET', '/api/flights/' . $flight->id . '/seats');

        self::assertResponseIsSuccessful();
        $data = $this->decodeJsonResponse($this->client);
        self::assertCount(1, $data);
        self::assertIsArray($data[0]);
        self::assertSame('12A', $data[0]['seatNumber']);
        self::assertArrayNotHasKey('flight', $data[0]);
    }

    public function testSeatsReturns404ForMissingFlight(): void
    {
        $this->client->request('GET', '/api/flights/00000000-0000-0000-0000-000000000000/seats');

        self::assertResponseStatusCodeSame(404);
        $data = $this->decodeJsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }
}
