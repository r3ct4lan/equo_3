<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\LoginRequest;
use App\IdentityAccess\Application\Login\LoginCommand;
use App\IdentityAccess\Application\Login\LoginUser;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    public function __construct(
        private LoginUser $loginUser,
        private AuthCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        LoginRequest $payload,
    ): Response {
        $result = $this->loginUser->handle(new LoginCommand(
            $payload->email ?? '',
            $payload->password ?? '',
            $request->getClientIp() ?? 'unknown',
        ));

        $response = new JsonResponse($result->toResponseBody());

        $result->withCookieSecrets(function (string $refreshToken, string $csrfToken, DateTimeImmutable $expiresAt) use ($response): void {
            foreach ($this->cookieFactory->loginCookies($refreshToken, $csrfToken, $expiresAt) as $cookie) {
                $response->headers->setCookie($cookie);
            }
        });

        return $response;
    }
}
