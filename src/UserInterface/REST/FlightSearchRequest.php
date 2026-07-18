<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\UserInterface\REST;

use AeroNuk\FlightSearch\Domain\AirportCode;
use Symfony\Component\Validator\Constraints as Assert;

use function array_column;

final class FlightSearchRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'origin is required')]
        #[Assert\Choice(
            callback: [self::class, 'airportCodes'],
            message: 'origin must be a known 3-letter airport code',
        )]
        public string|null $origin = null,
        #[Assert\NotBlank(message: 'destination is required')]
        #[Assert\Choice(
            callback: [self::class, 'airportCodes'],
            message: 'destination must be a known 3-letter airport code',
        )]
        public string|null $destination = null,
        #[Assert\NotBlank(message: 'date is required')]
        #[Assert\Date(message: 'date must be in YYYY-MM-DD format')]
        public string|null $date = null,
    ) {
    }

    /** @return list<string> */
    public static function airportCodes(): array
    {
        return array_column(AirportCode::cases(), 'value');
    }
}
