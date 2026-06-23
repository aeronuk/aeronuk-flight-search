<?php

namespace AeroNuk\FlightSearch\Exception;

final class FlightNotFoundException extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(sprintf('Flight "%s" not found.', $id));
    }
}
