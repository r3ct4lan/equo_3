<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Login\LoginResult;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthCookieFactory
{
    public function addLoginCookies(Response $response, LoginResult $result): void
    {
        $result->withCookieSecrets(function (string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt) use ($response): void {
            foreach ($this->loginCookies($refreshToken, $csrfToken, $expiresAt) as $cookie) {
                $response->headers->setCookie($cookie);
            }
        });
    }

    /** @return list<Cookie> */
    public function loginCookies(string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt): array
    {
        return $this->sessionCookies($refreshToken, $csrfToken, $expiresAt);
    }

    /** @return list<Cookie> */
    public function refreshCookies(string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt): array
    {
        return $this->sessionCookies($refreshToken, $csrfToken, $expiresAt);
    }

    /** @return list<Cookie> */
    public function clearSessionCookies(): array
    {
        return $this->sessionCookies('', '', new DateTimeImmutable('@1'));
    }

    /** @return list<Cookie> */
    private function sessionCookies(string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt): array
    {
        return [
            Cookie::create('equo_refresh')
                ->withValue($refreshToken)
                ->withExpires($expiresAt)
                ->withPath('/api/v1/auth')
                ->withSecure(true)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
            Cookie::create('__Host-equo_csrf')
                ->withValue($csrfToken)
                ->withExpires($expiresAt)
                ->withPath('/')
                ->withSecure(true)
                ->withHttpOnly(false)
                ->withSameSite(Cookie::SAMESITE_LAX),
        ];
    }
}
