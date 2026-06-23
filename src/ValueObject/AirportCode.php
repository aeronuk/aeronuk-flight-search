<?php

namespace AeroNuk\FlightSearch\ValueObject;

enum AirportCode: string
{
    case JFK = 'JFK';
    case LAX = 'LAX';
    case ORD = 'ORD';
    case SFO = 'SFO';
    case LHR = 'LHR';
    case NRT = 'NRT';
}
