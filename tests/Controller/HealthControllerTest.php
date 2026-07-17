<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Tests\Controller;

use AeroNuk\FlightSearch\Tests\DecodesJsonResponse;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    use DecodesJsonResponse;

    public function testHealthReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], $this->decodeJsonResponse($client));
    }
}
