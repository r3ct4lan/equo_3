<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ActivateRequest;
use App\IdentityAccess\Application\Activate\ActivateAccount;
use App\IdentityAccess\Application\Activate\ActivateCommand;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ActivateController
{
    public function __construct(private ActivateAccount $activateAccount)
    {
    }

    #[Route('/api/v1/auth/activate', name: 'api_v1_auth_activate', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        ActivateRequest $payload,
    ): Response {
        $this->activateAccount->handle(new ActivateCommand(
            $payload->token ?? '',
            $request->getClientIp() ?? 'unknown',
        ));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
