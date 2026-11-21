<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Http\Request\TelegramLaunchRequest;
use App\IdentityAccess\Adapter\Security\TelegramMiniAppVerifier;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Login\SignInWithTelegram;
use App\IdentityAccess\Domain\User\VerifiedTelegramIdentity;
use App\Infrastructure\Application\Api\RequestLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TelegramMiniAppController
{
    public function __construct(
        private TelegramMiniAppVerifier $verifier,
        private SignInWithTelegram $signIn,
        private RefreshRequestGuard $guard,
        private AuthCookieFactory $cookies,
        private RequestLimiter $limiter,
    ) {
    }

    #[Route('/api/v1/auth/telegram/mini-app', name: 'api_v1_auth_telegram_mini_app', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        TelegramLaunchRequest $payload,
    ): JsonResponse {
        return $this->start($this->verifiedIdentity($request, $payload));
    }

    #[Route('/api/v1/auth/telegram/mini-app/reactivate', name: 'api_v1_auth_telegram_mini_app_reactivate', methods: ['POST'])]
    public function reactivate(
        Request $request,
        #[MapRequestPayload(acceptFormat: 'json')]
        TelegramLaunchRequest $payload,
    ): JsonResponse {
        return $this->start($this->verifiedIdentity($request, $payload), reactivate: true);
    }

    private function verifiedIdentity(Request $request, TelegramLaunchRequest $payload): VerifiedTelegramIdentity
    {
        $this->guard->validateOrigin($request);
        $retry = $this->limiter->consume(['telegram-login:ip:'.($request->getClientIp() ?? 'unknown') => 30]);
        if (null !== $retry) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, retryAfter: $retry);
        }

        return $this->verifier->verify($payload->initData ?? '');
    }

    private function start(VerifiedTelegramIdentity $identity, bool $reactivate = false): JsonResponse
    {
        $result = $reactivate ? $this->signIn->reactivate($identity->id) : $this->signIn->handle($identity);
        $body = $result->toResponseBody();
        if (null !== $identity->startParam) {
            $body['startParam'] = $identity->startParam;
        }
        $response = new JsonResponse($body, headers: ['Cache-Control' => 'no-store']);
        $this->cookies->addLoginCookies($response, $result);

        return $response;
    }
}
