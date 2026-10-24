<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\EmailChangeConfirmationRequest;
use App\IdentityAccess\Application\EmailChange\ConfirmEmailChange;
use App\IdentityAccess\Application\EmailChange\EmailChangeConfirmationCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EmailChangeConfirmationController
{
    public function __construct(private ConfirmEmailChange $handler)
    {
    }

    #[Route('/api/v1/auth/email-change-confirmations', name: 'api_v1_auth_email_change_confirmations', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload(acceptFormat: 'json')] EmailChangeConfirmationRequest $payload): Response
    {
        $this->handler->handle(new EmailChangeConfirmationCommand($payload->token ?? '', $request->getClientIp() ?? 'unknown'));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
