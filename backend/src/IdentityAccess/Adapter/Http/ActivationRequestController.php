<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ActivationRequest;
use App\IdentityAccess\Application\ActivationRequest\ActivationRequestCommand;
use App\IdentityAccess\Application\ActivationRequest\RequestActivation;
use App\IdentityAccess\Application\Api\ActivationRequestResult;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ActivationRequestController
{
    public function __construct(private RequestActivation $requestActivation)
    {
    }

    #[Route(
        '/api/v1/auth/activation-requests',
        name: 'api_v1_auth_activation_requests',
        defaults: ['_api_status' => 202],
        methods: ['POST'],
    )]
    public function __invoke(
        #[MapRequestPayload(acceptFormat: 'json')]
        ActivationRequest $payload,
    ): ActivationRequestResult {
        return $this->requestActivation->handle(new ActivationRequestCommand(
            $payload->email ?? '',
            $payload->password ?? '',
        ));
    }
}
