<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\DeactivateAccountRequest;
use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Application\AccountDeactivation\DeactivateAccount;
use App\IdentityAccess\Application\AccountDeactivation\DeactivateAccountCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DeactivateAccountController
{
    public function __construct(private DeactivateAccount $handler, private Security $security, private AuthCookieFactory $cookies)
    {
    }

    #[Route('/api/v1/account/deactivate', name: 'api_v1_account_deactivate', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json')] DeactivateAccountRequest $payload): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $this->handler->handle(new DeactivateAccountCommand($user->getUserIdentifier(), $payload->currentPassword ?? ''));
        } catch (ApplicationFailure $failure) {
            if (ApplicationFailureCode::AuthenticationRequired === $failure->failureCode) {
                throw new UnauthorizedHttpException('Bearer', previous: $failure);
            }
            throw $failure;
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        foreach ($this->cookies->clearSessionCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
