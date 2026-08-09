<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MeController
{
    public function __construct(private Security $security)
    {
    }

    #[Route('/api/v1/me', name: 'api_v1_me', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof AuthenticatedUser) {
            return new JsonResponse([], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($user->profile()->toArray());
    }
}
