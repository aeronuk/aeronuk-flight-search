<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

interface SeatRepository
{
    /** @return Seat[] */
    public function findByFlight(Flight $flight): array;
}
