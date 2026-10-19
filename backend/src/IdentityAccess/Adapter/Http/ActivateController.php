<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ActivateRequest;
use App\IdentityAccess\Application\Activate\ActivateAccount;
use App\IdentityAccess\Application\Activate\ActivateCommand;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ActivateController
{
    public function __construct(
        private ActivateAccount $activateAccount,
        private AuthCookieFactory $cookieFactory,
    ) {
    }

    #[Route('/api/v1/auth/activate', name: 'api_v1_auth_activate', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        ActivateRequest $payload,
    ): Response {
        $result = $this->activateAccount->handle(new ActivateCommand(
            $payload->token ?? '',
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
