<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class TelegramDeploymentConfiguration
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire(env: 'EQUO_APPLICATION_ORIGIN')]
        private string $origin,
        #[Autowire(env: 'EQUO_TELEGRAM_BOT_TOKEN')]
        #[SensitiveParameter]
        private string $botToken,
        #[Autowire(env: 'EQUO_TELEGRAM_CLIENT_ID')]
        private string $clientId,
        #[Autowire(env: 'EQUO_TELEGRAM_CLIENT_SECRET')]
        #[SensitiveParameter]
        private string $clientSecret,
        #[Autowire(env: 'NUXT_PUBLIC_TELEGRAM_BOT_USERNAME')]
        private string $botUsername,
        #[Autowire(env: 'bool:NUXT_PUBLIC_TELEGRAM_LOGIN_ENABLED')]
        private bool $enabled,
    ) {
    }

    /** @return list<string> */
    public function problems(): array
    {
        if (!$this->enabled && '' === $this->botToken && '' === $this->clientId && '' === $this->clientSecret && '' === $this->botUsername) {
            return [];
        }
        $fixture = 'e2e' === $this->environment;
        $errors = [];
        if (!$this->enabled) {
            $errors[] = 'Enable Telegram login in the web configuration.';
        }
        if (!self::isHttpsOrigin($this->origin)) {
            $errors[] = 'Telegram requires an HTTPS application origin with no path or query.';
        }
        if ($fixture ? 'equo-e2e-mini-app-key' !== $this->botToken : 1 !== preg_match('/\A[1-9][0-9]{0,15}:[A-Za-z0-9_-]+\z/D', $this->botToken)) {
            $errors[] = 'The Telegram bot token is incompatible with this environment.';
        }
        if (1 !== preg_match('/\A[1-9][0-9]{0,15}\z/D', $this->clientId)) {
            $errors[] = 'Configure a numeric Telegram OIDC client ID.';
        }
        if ($fixture ? 'equo-e2e-oidc-only' !== $this->clientSecret : '' === $this->clientSecret || str_starts_with($this->clientSecret, 'equo-e2e-')) {
            $errors[] = 'The Telegram OIDC client secret is incompatible with this environment.';
        }
        if (1 !== preg_match('/\A[A-Za-z0-9_]{5,32}\z/D', $this->botUsername)) {
            $errors[] = 'Configure the Telegram bot username for Mini App invitation links.';
        }

        return $errors;
    }

    public static function isHttpsOrigin(string $origin): bool
    {
        $url = parse_url($origin);

        return is_array($url) && 'https' === ($url['scheme'] ?? null)
            && isset($url['host']) && '' !== $url['host']
            && [] === array_diff(array_keys($url), ['scheme', 'host', 'port']);
    }
}
