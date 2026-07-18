<?php

declare(strict_types=1);

namespace AeroNuk\FlightSearch\Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;

trait DecodesJsonResponse
{
    /** @return array<mixed> */
    private function decodeJsonResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }
}
