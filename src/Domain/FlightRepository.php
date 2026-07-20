<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

use DateTimeImmutable;

interface FlightRepository
{
    public function get(string $id): Flight;

    /** @return Flight[] */
    public function search(Route $route, DateTimeImmutable $date): array;
}
