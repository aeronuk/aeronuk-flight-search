<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Controller;

use AeroNuk\FlightSearch\Exception\FlightNotFound;
use AeroNuk\FlightSearch\Repository\FlightRepository;
use AeroNuk\FlightSearch\Repository\SeatRepository;
use AeroNuk\FlightSearch\ValueObject\AirportCode;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

use function strtoupper;

class FlightController
{
    public function __construct(
        private FlightRepository $flightRepository,
        private SeatRepository $seatRepository,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/api/flights', name: 'flights_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $originParam = $request->query->get('origin');
        if ($originParam === null) {
            return new JsonResponse(['error' => 'origin is required'], 400);
        }

        $origin = AirportCode::tryFrom(strtoupper($originParam));
        if ($origin === null) {
            return new JsonResponse(['error' => 'origin must be a known 3-letter airport code'], 400);
        }

        $destinationParam = $request->query->get('destination');
        if ($destinationParam === null) {
            return new JsonResponse(['error' => 'destination is required'], 400);
        }

        $destination = AirportCode::tryFrom(strtoupper($destinationParam));
        if ($destination === null) {
            return new JsonResponse(['error' => 'destination must be a known 3-letter airport code'], 400);
        }

        $dateParam = $request->query->get('date');
        if ($dateParam === null) {
            return new JsonResponse(['error' => 'date is required'], 400);
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam);
        if ($date === false) {
            return new JsonResponse(['error' => 'date must be in YYYY-MM-DD format'], 400);
        }

        $flights = $this->flightRepository->search($origin, $destination, $date);

        return JsonResponse::fromJsonString($this->serializer->serialize($flights, 'json'));
    }

    #[Route('/api/flights/{id}/seats', name: 'flights_seats', methods: ['GET'])]
    public function seats(string $id): JsonResponse
    {
        try {
            $flight = $this->flightRepository->get($id);
        } catch (FlightNotFound) {
            return new JsonResponse(['error' => 'flight not found'], 404);
        }

        $seats = $this->seatRepository->findByFlight($flight);

        return JsonResponse::fromJsonString($this->serializer->serialize($seats, 'json'));
    }
}
