<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\UserInterface\REST;

use AeroNuk\FlightSearch\Domain\AirportCode;
use AeroNuk\FlightSearch\Domain\FlightNotFound;
use AeroNuk\FlightSearch\Domain\FlightRepository;
use AeroNuk\FlightSearch\Domain\SeatRepository;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

use function assert;

class FlightController
{
    public function __construct(
        private FlightRepository $flightRepository,
        private SeatRepository $seatRepository,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/api/flights', name: 'flights_search', methods: ['GET'])]
    public function search(
        #[MapQueryString(validationFailedStatusCode: 400)]
        FlightSearchRequest $request,
    ): JsonResponse {
        assert($request->origin !== null);
        assert($request->destination !== null);
        assert($request->date !== null);

        $flights = $this->flightRepository->search(
            AirportCode::from($request->origin),
            AirportCode::from($request->destination),
            new DateTimeImmutable($request->date),
        );

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
