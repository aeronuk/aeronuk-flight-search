<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Exception;

use RuntimeException;

use function sprintf;

final class FlightNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Flight "%s" not found.', $id));
    }
}
