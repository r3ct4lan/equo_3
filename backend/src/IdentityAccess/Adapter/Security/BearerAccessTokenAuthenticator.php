<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Security;

use App\IdentityAccess\Application\CurrentUser\GetCurrentUser;
use App\IdentityAccess\Application\Port\AccessTokenVerifierPort;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class BearerAccessTokenAuthenticator extends AbstractAuthenticator
{
    private const string PROTECTED_PATH = '/api/v1/me';

    public function __construct(
        private AccessTokenVerifierPort $accessTokenVerifier,
        private GetCurrentUser $getCurrentUser,
        private AuthenticationEntryPointInterface $entryPoint,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::PROTECTED_PATH === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $accessToken = $this->accessToken($request);
        $verified = $this->accessTokenVerifier->verify($accessToken);

        if (null === $verified) {
            throw $this->authenticationFailed();
        }

        $profile = $this->getCurrentUser->handle($verified->userId);

        if (null === $profile || $profile->id !== $verified->userId) {
            throw $this->authenticationFailed();
        }

        return new SelfValidatingPassport(new UserBadge(
            $verified->userId,
            static fn (string $userIdentifier): AuthenticatedUser => new AuthenticatedUser($profile),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->entryPoint->start($request, $exception);
    }

    private function accessToken(Request $request): string
    {
        $headers = $request->headers->all('authorization');

        if (1 !== count($headers)) {
            throw $this->authenticationFailed();
        }

        $header = $headers[0];
        if (!is_string($header) || 1 !== preg_match('/\ABearer ([^\s,]+)\z/D', $header, $matches)) {
            throw $this->authenticationFailed();
        }

        return $matches[1];
    }

    private function authenticationFailed(): CustomUserMessageAuthenticationException
    {
        return new CustomUserMessageAuthenticationException('Authentication is required.');
    }
}
