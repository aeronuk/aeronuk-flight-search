<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\UserInterface\REST;

use AeroNuk\FlightSearch\Tests\DecodesJsonResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('functional')]
class HealthControllerTest extends WebTestCase
{
    use DecodesJsonResponse;

    #[Test]
    public function healthReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok'], $this->decodeJsonResponse($client));
    }
}
