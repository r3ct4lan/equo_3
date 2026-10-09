<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\PasswordResetRequest;
use App\IdentityAccess\Application\Api\PasswordResetRequestResult;
use App\IdentityAccess\Application\PasswordReset\PasswordResetRequestCommand;
use App\IdentityAccess\Application\PasswordReset\RequestPasswordReset;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PasswordResetRequestController
{
    public function __construct(private RequestPasswordReset $handler)
    {
    }

    #[Route('/api/v1/auth/password-reset-requests', name: 'api_v1_auth_password_reset_requests', defaults: ['_api_status' => 202], methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload(acceptFormat: 'json')] PasswordResetRequest $payload): PasswordResetRequestResult
    {
        $this->handler->handle(new PasswordResetRequestCommand($payload->email ?? '', $request->getClientIp() ?? 'unknown'));

        return new PasswordResetRequestResult();
    }
}
