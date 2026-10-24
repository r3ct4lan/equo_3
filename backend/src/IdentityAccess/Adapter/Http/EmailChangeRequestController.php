<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\EmailChangeRequest;
use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Application\Api\EmailChangeRequestResult;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\EmailChange\EmailChangeRequestCommand;
use App\IdentityAccess\Application\EmailChange\RequestEmailChange;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EmailChangeRequestController
{
    public function __construct(private RequestEmailChange $handler, private Security $security)
    {
    }

    #[Route('/api/v1/account/email-change-requests', name: 'api_v1_account_email_change_requests', defaults: ['_api_status' => 202], methods: ['POST'])]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json')] EmailChangeRequest $payload): EmailChangeRequestResult
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }
        try {
            $this->handler->handle(new EmailChangeRequestCommand($user->getUserIdentifier(), $payload->currentPassword ?? '', $payload->newEmail ?? ''));
        } catch (ApplicationFailure $failure) {
            if (ApplicationFailureCode::AuthenticationRequired === $failure->failureCode) {
                throw new UnauthorizedHttpException('Bearer', previous: $failure);
            }
            throw $failure;
        }

        return new EmailChangeRequestResult();
    }
}
