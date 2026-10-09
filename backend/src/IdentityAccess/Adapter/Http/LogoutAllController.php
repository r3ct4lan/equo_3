<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Application\Logout\LogoutAllSessions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogoutAllController
{
    public function __construct(
        private LogoutAllSessions $logout,
        private Security $security,
        private AuthCookieFactory $cookies,
    ) {
    }

    #[Route('/api/v1/auth/logout-all', name: 'api_v1_auth_logout_all', methods: ['POST'])]
    public function __invoke(): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $this->logout->handle($user->getUserIdentifier());
        } catch (\App\IdentityAccess\Application\Api\Error\ApplicationFailure $failure) {
            throw new UnauthorizedHttpException('Bearer', previous: $failure);
        }

        $response = new Response(status: Response::HTTP_NO_CONTENT);
        foreach ($this->cookies->clearSessionCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
