<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ChangePasswordRequest;
use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\PasswordChange\ChangePassword;
use App\IdentityAccess\Application\PasswordChange\ChangePasswordCommand;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ChangePasswordController
{
    public function __construct(private ChangePassword $handler, private Security $security, private AuthCookieFactory $cookies)
    {
    }

    #[Route('/api/v1/account/password-change', name: 'api_v1_account_password_change', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json')] ChangePasswordRequest $payload): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }
        try {
            $this->handler->handle(new ChangePasswordCommand($user->getUserIdentifier(), $payload->currentPassword ?? '', $payload->newPassword ?? ''));
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
