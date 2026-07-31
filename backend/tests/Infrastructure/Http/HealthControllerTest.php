<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointReturnsOk(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');
        self::assertResponseHasHeader('X-Request-Id');
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","service":"equo-api"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
