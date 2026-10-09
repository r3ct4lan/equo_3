<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Application\Logout\LogoutCurrentSession;
use App\IdentityAccess\Application\Logout\LogoutCurrentSessionCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogoutController
{
    public function __construct(
        private LogoutCurrentSession $logout,
        private RefreshRequestGuard $guard,
        private AuthCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/logout', name: 'api_v1_auth_logout', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $credentials = $this->guard->validateForLogout($request);
        if (null !== $credentials) {
            $this->logout->handle(new LogoutCurrentSessionCommand($credentials->refreshToken, $credentials->csrfToken));
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        foreach ($this->cookies->clearSessionCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
