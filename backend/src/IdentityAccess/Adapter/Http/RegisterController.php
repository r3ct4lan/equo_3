<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\RegisterRequest;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Api\RegisterResult;
use App\IdentityAccess\Application\Register\RegisterCommand;
use App\IdentityAccess\Application\Register\RegisterUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final readonly class RegisterController
{
    public function __construct(private RegisterUser $registerUser)
    {
    }

    #[Route(
        '/api/v1/auth/register',
        name: 'api_v1_auth_register',
        defaults: ['_api_status' => 201],
        methods: ['POST'],
    )]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        RegisterRequest $payload,
    ): RegisterResult {
        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if (null === $idempotencyKey || '' === trim($idempotencyKey)) {
            throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyRequired);
        }

        if (!Uuid::isValid($idempotencyKey)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return $this->registerUser->handle(new RegisterCommand(
            $payload->name ?? '',
            $payload->email ?? '',
            $payload->password ?? '',
            $idempotencyKey,
            $request->getClientIp() ?? 'unknown',
        ));
    }
}
