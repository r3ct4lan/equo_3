<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ResetPasswordRequest;
use App\IdentityAccess\Application\PasswordReset\ResetPassword;
use App\IdentityAccess\Application\PasswordReset\ResetPasswordCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ResetPasswordController
{
    public function __construct(private ResetPassword $handler, private AuthCookieFactory $cookies)
    {
    }

    #[Route('/api/v1/auth/password-reset', name: 'api_v1_auth_password_reset', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload(acceptFormat: 'json')] ResetPasswordRequest $payload): Response
    {
        $this->handler->handle(new ResetPasswordCommand($payload->token ?? '', $payload->newPassword ?? '', $request->getClientIp() ?? 'unknown'));
        $response = new Response(status: Response::HTTP_NO_CONTENT);
        foreach ($this->cookies->clearSessionCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
