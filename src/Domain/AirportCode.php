<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Domain;

enum AirportCode: string
{
    case JFK = 'JFK';
    case LAX = 'LAX';
    case ORD = 'ORD';
    case SFO = 'SFO';
    case LHR = 'LHR';
    case NRT = 'NRT';
}
