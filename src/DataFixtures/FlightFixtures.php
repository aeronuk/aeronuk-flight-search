<?php

namespace AeroNuk\FlightSearch\DataFixtures;

use AeroNuk\FlightSearch\Entity\Flight;
use AeroNuk\FlightSearch\Entity\Seat;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use AeroNuk\FlightSearch\ValueObject\Money;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

class FlightFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $flights = [
            ['AN101', AirportCode::JFK, AirportCode::LAX, '2026-07-01 08:00:00', '2026-07-01 11:30:00', '299.99', 'USD'],
            ['AN102', AirportCode::LAX, AirportCode::JFK, '2026-07-01 13:00:00', '2026-07-01 21:15:00', '319.99', 'USD'],
            ['AN201', AirportCode::ORD, AirportCode::SFO, '2026-07-02 09:15:00', '2026-07-02 11:45:00', '249.50', 'USD'],
            ['AN305', AirportCode::JFK, AirportCode::LHR, '2026-07-03 19:00:00', '2026-07-04 07:00:00', '649.00', 'USD'],
            ['AN410', AirportCode::SFO, AirportCode::NRT, '2026-07-05 00:30:00', '2026-07-06 05:00:00', '899.00', 'USD'],
        ];

        foreach ($flights as [$number, $origin, $destination, $departure, $arrival, $amount, $currency]) {
            $flight = new Flight(
                (string) Uuid::v7(),
                $number,
                $origin,
                $destination,
                new \DateTimeImmutable($departure),
                new \DateTimeImmutable($arrival),
                new Money($amount, $currency),
            );
            $manager->persist($flight);

            foreach (['01A' => 'business', '12A' => 'economy', '12B' => 'economy', '12C' => 'economy'] as $seatNumber => $class) {
                $manager->persist(new Seat((string) Uuid::v7(), $flight, $seatNumber, $class));
            }
        }

        $manager->flush();
    }
}
