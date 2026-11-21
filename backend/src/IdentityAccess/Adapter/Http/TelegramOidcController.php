<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Http;

use App\IdentityAccess\Adapter\Security\TelegramOidcClient;
use App\IdentityAccess\Adapter\Security\TelegramOidcStateStore;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Credentials\CredentialManagement;
use App\IdentityAccess\Application\Login\LoginResult;
use App\IdentityAccess\Application\Login\SignInWithTelegram;
use App\Infrastructure\Application\Api\RequestLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TelegramOidcController
{
    private const string COOKIE = '__Secure-equo_telegram_oidc';
    private const string REACTIVATION_COOKIE = '__Secure-equo_telegram_reactivate';
    private const string PATH = '/api/v1/auth/telegram/oidc';
    private const array HEADERS = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    public function __construct(
        private TelegramOidcClient $provider,
        private TelegramOidcStateStore $attempts,
        private SignInWithTelegram $signIn,
        private CredentialManagement $credentials,
        private AuthCookieFactory $cookies,
        private RefreshRequestGuard $guard,
        private RequestLimiter $limiter,
    ) {
    }

    #[Route(self::PATH.'/start', name: 'api_v1_auth_telegram_oidc_start', methods: ['GET'])]
    public function start(Request $request): RedirectResponse
    {
        try {
            $retry = $this->limiter->consume(['telegram-oidc:ip:'.($request->getClientIp() ?? 'unknown') => 30]);
            if (null !== $retry) {
                return $this->failure('failed');
            }

            [$url, $cookie] = $this->issueAttempt();
        } catch (ApplicationFailure) {
            return $this->failure('failed');
        }

        $response = new RedirectResponse($url, Response::HTTP_SEE_OTHER, self::HEADERS);
        $response->headers->setCookie($cookie);

        return $response;
    }

    public function startCredentialChange(string $userId, string $sessionId, ?int $original, bool $deactivate = false): JsonResponse
    {
        [$url, $cookie] = $this->issueAttempt(['userId' => $userId, 'sessionId' => $sessionId, 'original' => $original, 'deactivate' => $deactivate]);
        $response = new JsonResponse(['url' => $url], headers: self::HEADERS);
        $response->headers->setCookie($cookie);

        return $response;
    }

    /** @param array{userId:string, sessionId:string, original:?int, deactivate:bool}|null $account
     * @return array{string, Cookie}
     */
    private function issueAttempt(?array $account = null): array
    {
        $state = TelegramOidcClient::randomUrlToken();
        $browser = TelegramOidcClient::randomUrlToken();
        $verifier = TelegramOidcClient::randomUrlToken();
        $url = $this->provider->authorizationUrl($state, $verifier);
        $this->attempts->put($state, $browser, $verifier, $account);

        return [$url, $this->stateCookie($browser, time() + TelegramOidcStateStore::TTL)];
    }

    public function clearAttempt(Response $response): void
    {
        $response->headers->setCookie($this->stateCookie('', 1));
    }

    #[Route(self::PATH.'/callback', name: 'api_v1_auth_telegram_oidc_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $query = $request->query->all();
        $state = $query['state'] ?? null;
        if (!is_string($state)) {
            return $this->failure('failed');
        }

        $account = null;
        $identity = null;
        try {
            $attempt = $this->attempts->consume($state, $request->cookies->getString(self::COOKIE));
            if (null === $attempt) {
                return $this->failure('failed');
            }
            $account = $attempt['account'];
            if (isset($query['error'])) {
                return $this->failure('access_denied' === $query['error'] ? 'cancelled' : 'failed', null !== $account);
            }
            $code = $query['code'] ?? null;
            if (!is_string($code) || '' === $code || strlen($code) > 2048) {
                return $this->failure('failed', null !== $account);
            }
            $identity = $this->provider->exchange($code, $attempt['verifier']);
            if (null !== $account) {
                if ($account['deactivate']) {
                    $this->credentials->proveTelegramDeactivation($account['userId'], $account['sessionId'], $identity->id);

                    return $this->redirect('/me?credentials=deactivation_ready');
                }

                $outcome = $this->credentials->applyTelegram($account['userId'], $account['sessionId'], $identity->id, $account['original']);

                return $this->redirect('/me?credentials='.$outcome);
            }
            $result = $this->signIn->handle($identity);
        } catch (ApplicationFailure $failure) {
            if (null === $account && null !== $identity && ApplicationFailureCode::AccountInactive === $failure->failureCode) {
                return $this->prepareReactivation($identity->id);
            }

            return $this->failure('failed', null !== $account);
        }

        return $this->signedIn($result);
    }

    #[Route(self::PATH.'/reactivate', name: 'api_v1_auth_telegram_oidc_reactivate', methods: ['POST'])]
    public function reactivate(Request $request): Response
    {
        $this->guard->validateOrigin($request);
        $id = $this->attempts->consumeReactivation($request->cookies->getString(self::REACTIVATION_COOKIE));
        if (null === $id) {
            return $this->failure('failed');
        }

        try {
            $response = $this->signedIn($this->signIn->reactivate($id));
        } catch (ApplicationFailure) {
            $response = $this->failure('failed');
        }
        $response->headers->setCookie($this->reactivationCookie('', 1));

        return $response;
    }

    private function prepareReactivation(int $telegramId): RedirectResponse
    {
        $token = TelegramOidcClient::randomUrlToken();
        try {
            $this->attempts->putReactivation($token, $telegramId);
        } catch (ApplicationFailure) {
            return $this->failure('failed');
        }
        $response = $this->failure('inactive');
        $response->headers->setCookie($this->reactivationCookie($token, time() + TelegramOidcStateStore::TTL));

        return $response;
    }

    private function signedIn(LoginResult $result): RedirectResponse
    {
        $response = $this->redirect('/');
        $this->cookies->addLoginCookies($response, $result);
        $response->headers->setCookie(Cookie::create('__Host-equo_telegram_signed_in', '1', time() + 60, '/', secure: true, httpOnly: false));

        return $response;
    }

    private function reactivationCookie(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(self::REACTIVATION_COOKIE, $value, $expiresAt, self::PATH.'/reactivate', secure: true, httpOnly: true);
    }

    private function failure(string $outcome, bool $account = false): RedirectResponse
    {
        return $this->redirect(($account ? '/me?credentials=' : '/login?telegram=').$outcome);
    }

    private function redirect(string $location): RedirectResponse
    {
        $response = new RedirectResponse($location, Response::HTTP_SEE_OTHER, self::HEADERS);
        $this->clearAttempt($response);

        return $response;
    }

    private function stateCookie(string $value, int $expiresAt): Cookie
    {
        return Cookie::create(self::COOKIE, $value, $expiresAt, self::PATH, secure: true, httpOnly: true);
    }
}
