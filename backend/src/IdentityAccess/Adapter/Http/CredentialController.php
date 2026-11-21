<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\EmailChangeConfirmationRequest;
use App\IdentityAccess\Adapter\Http\Request\LinkEmailRequest;
use App\IdentityAccess\Adapter\Http\Request\TelegramCredentialRequest;
use App\IdentityAccess\Adapter\Security\AuthenticatedUser;
use App\IdentityAccess\Adapter\Security\TelegramMiniAppVerifier;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Credentials\CredentialManagement;
use App\Infrastructure\Application\Api\RequestLimiter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/account/credentials')]
final readonly class CredentialController
{
    public function __construct(
        private CredentialManagement $credentials,
        private Security $security,
        private TelegramMiniAppVerifier $miniApp,
        private TelegramOidcController $oidc,
        private RequestLimiter $limiter,
    ) {
    }

    #[Route('', name: 'api_v1_account_credentials', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        [$user, $session] = $this->actor();

        return $this->json($this->credentials->settings($user, $session));
    }

    #[Route('/telegram/start', name: 'api_v1_account_telegram_start', methods: ['POST'])]
    public function startTelegram(#[MapRequestPayload(acceptFormat: 'json')] TelegramCredentialRequest $request): JsonResponse
    {
        [$user, $session] = $this->actor();
        $this->limit($user);
        $original = $this->credentials->authorizeTelegramChange($user, $session, $request->currentPassword ?? '');

        return $this->oidc->startCredentialChange($user, $session, $original);
    }

    #[Route('/telegram/proof', name: 'api_v1_account_telegram_proof', methods: ['POST'])]
    public function applyTelegram(#[MapRequestPayload(acceptFormat: 'json')] TelegramCredentialRequest $request): JsonResponse
    {
        [$user, $session] = $this->actor();
        $this->limit($user);
        $identity = $this->miniApp->verify($request->initData ?? '');
        $original = $this->credentials->authorizeTelegramChange($user, $session, $request->currentPassword ?? '');

        return $this->json(['status' => $this->credentials->applyTelegram($user, $session, $identity->id, $original)]);
    }

    #[Route('/email/start', name: 'api_v1_account_email_link_start', methods: ['POST'])]
    public function startEmail(#[MapRequestPayload(acceptFormat: 'json')] LinkEmailRequest $request): JsonResponse
    {
        [$user, $session] = $this->actor();
        $this->limit($user);
        $this->credentials->requestEmail($user, $session, $request->email ?? '', $request->password ?? '');

        return $this->json(['status' => 'email_confirmation_scheduled'], Response::HTTP_ACCEPTED);
    }

    #[Route('/email/confirm', name: 'api_v1_account_email_link_confirm', methods: ['POST'])]
    public function confirmEmail(#[MapRequestPayload(acceptFormat: 'json')] EmailChangeConfirmationRequest $request): JsonResponse
    {
        [$user, $session] = $this->actor();
        $this->limit($user);

        return $this->json(['status' => $this->credentials->confirmEmail($user, $session, $request->token ?? '')]);
    }

    #[Route('/cancel', name: 'api_v1_account_credentials_cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        [$user, $session] = $this->actor();
        $this->credentials->cancel($user, $session);
        $response = new Response(status: Response::HTTP_NO_CONTENT, headers: ['Cache-Control' => 'no-store']);
        $this->oidc->clearAttempt($response);

        return $response;
    }

    /** @return array{string, string} */
    private function actor(): array
    {
        $viewer = $this->security->getUser();
        if (!$viewer instanceof AuthenticatedUser || null === $viewer->sessionId) {
            throw new ApplicationFailure(ApplicationFailureCode::AuthenticationRequired);
        }

        return [$viewer->getUserIdentifier(), $viewer->sessionId];
    }

    private function limit(string $userId): void
    {
        $retryAfter = $this->limiter->consume(['credentials:user:'.$userId => 15]);
        if (null !== $retryAfter) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retryAfter);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
