<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SensitiveDataHttpTest extends WebTestCase
{
    public function testPersistenceSecretsAreIgnoredByHttpSerializer(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/_test/http/sensitive-records');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('passwordHash', $content);
        self::assertStringNotContainsString('password-hash-must-not-leak', $content);
        self::assertStringNotContainsString('tokenHash', $content);
        self::assertStringNotContainsString('token-hash-must-not-leak', $content);
        self::assertStringNotContainsString('payload', $content);
        self::assertStringNotContainsString('payload-must-not-leak', $content);
        self::assertStringNotContainsString('encryptedPayload', $content);
        self::assertStringNotContainsString('encrypted-payload-must-not-leak', $content);
    }
}
