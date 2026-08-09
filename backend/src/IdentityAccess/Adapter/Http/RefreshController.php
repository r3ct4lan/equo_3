<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Refresh\RefreshCommand;
use App\IdentityAccess\Application\Refresh\RefreshSession;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RefreshController
{
    public function __construct(
        private RefreshRequestGuard $guard,
        private RefreshSession $refreshSession,
        private AuthCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/api/v1/auth/refresh', name: 'api_v1_auth_refresh', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $credentials = $this->guard->validate($request);
        $result = $this->refreshSession->handle(new RefreshCommand(
            $credentials->refreshToken,
            $credentials->csrfToken,
        ));
        $response = new JsonResponse($result->toResponseBody());

        $result->withCookieSecrets(function (string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt) use ($response): void {
            foreach ($this->cookieFactory->refreshCookies($refreshToken, $csrfToken, $expiresAt) as $cookie) {
                $response->headers->setCookie($cookie);
            }
        });

        return $response;
    }
}
