<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\ChangeCurrencyRequest;
use App\IdentityAccess\Adapter\Http\Request\ChangeLocaleRequest;
use App\IdentityAccess\Adapter\Http\Request\RenameCurrentUserRequest;
use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\CurrentUser\ChangeCurrency;
use App\IdentityAccess\Application\CurrentUser\ChangeLocale;
use App\IdentityAccess\Application\CurrentUser\RenameCurrentUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MeController
{
    public function __construct(private Security $security, private RenameCurrentUser $rename, private ChangeLocale $changeLocale, private ChangeCurrency $changeCurrency)
    {
    }

    #[Route('/api/v1/me/currency', name: 'api_v1_me_currency', methods: ['PATCH'])]
    public function currency(#[MapRequestPayload(acceptFormat: 'json')] ChangeCurrencyRequest $payload): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return new JsonResponse($this->changeCurrency->handle($user->getUserIdentifier(), $payload->currency ?? '')->toArray());
    }

    #[Route('/api/v1/me/locale', name: 'api_v1_me_locale', methods: ['PATCH'])]
    public function locale(#[MapRequestPayload(acceptFormat: 'json')] ChangeLocaleRequest $payload): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return new JsonResponse($this->changeLocale->handle($user->getUserIdentifier(), $payload->locale ?? '')->toArray());
    }

    #[Route('/api/v1/me', name: 'api_v1_me', methods: ['GET'])]
    public function get(): Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof AuthenticatedUser) {
            return new JsonResponse([], Response::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($user->profile()->toArray());
    }

    #[Route('/api/v1/me', name: 'api_v1_me_update', methods: ['PATCH'])]
    public function patch(#[MapRequestPayload(acceptFormat: 'json')] RenameCurrentUserRequest $payload): Response
    {
        $user = $this->security->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $profile = $this->rename->handle($user->getUserIdentifier(), $payload->name ?? '');
        } catch (ApplicationFailure $failure) {
            if (ApplicationFailureCode::AuthenticationRequired === $failure->failureCode) {
                throw new UnauthorizedHttpException('Bearer', previous: $failure);
            }
            throw $failure;
        }

        return new JsonResponse($profile->toArray());
    }
}
