<?php

declare(strict_types=1);

namespace App\Tests\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Adapter\Security\TelegramDeploymentConfiguration;
use PHPUnit\Framework\TestCase;

final class TelegramDeploymentConfigurationTest extends TestCase
{
    public function testDisabledTelegramNeedsNoCredentialsOrHttpsOrigin(): void
    {
        $settings = new TelegramDeploymentConfiguration('dev', 'http://localhost', '', '', '', '', false);
        self::assertSame([], $settings->problems());
    }

    public function testEnabledTelegramRequiresAllCredentialsAndAnHttpsOriginWithoutAPath(): void
    {
        $incomplete = new TelegramDeploymentConfiguration('prod', 'https://example.test/path', '', '123456789', '', '', true);
        self::assertCount(4, $incomplete->problems());

        $valid = new TelegramDeploymentConfiguration('prod', 'https://example.test', '123456789:synthetic-example-token', '123456789', 'synthetic-example-password', 'EquoDevBot', true);
        self::assertSame([], $valid->problems());
    }

    public function testFixtureCredentialsAreRestrictedToTheE2eEnvironment(): void
    {
        $bot = 'equo-e2e-mini-app-key';
        $secret = 'equo-e2e-oidc-only';
        $e2e = new TelegramDeploymentConfiguration('e2e', 'https://nginx', $bot, '123456789', $secret, 'EquoE2eBot', true);
        self::assertSame([], $e2e->problems());

        $deployed = new TelegramDeploymentConfiguration('prod', 'https://example.test', $bot, '123456789', $secret, 'EquoE2eBot', true);
        self::assertNotEmpty($deployed->problems());

        $liveInFixture = new TelegramDeploymentConfiguration('e2e', 'https://nginx', '123456789:synthetic-token', '123456789', 'synthetic-secret', 'EquoE2eBot', true);
        self::assertNotEmpty($liveInFixture->problems());
    }
}
