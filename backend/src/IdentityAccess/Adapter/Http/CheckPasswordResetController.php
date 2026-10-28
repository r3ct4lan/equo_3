<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\CheckPasswordResetRequest;
use App\IdentityAccess\Application\PasswordReset\ResetPassword;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CheckPasswordResetController
{
    public function __construct(private ResetPassword $handler)
    {
    }

    #[Route('/api/v1/auth/password-reset/check', name: 'api_v1_auth_password_reset_check', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload(acceptFormat: 'json')] CheckPasswordResetRequest $payload): Response
    {
        $this->handler->check($payload->token ?? '', $request->getClientIp() ?? 'unknown');

        return new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
    }
}
