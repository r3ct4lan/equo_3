<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Uid\Ulid;

final readonly class BearerAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private ApiJsonResponder $responder)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);

        if (!is_string($requestId)) {
            $requestId = (string) new Ulid();
            $request->attributes->set(RequestIdSubscriber::ATTRIBUTE, $requestId);
        }

        return $this->responder->respond(
            [
                'error' => [
                    'code' => 'AUTHENTICATION_REQUIRED',
                    'message' => 'Authentication is required.',
                    'requestId' => $requestId,
                ],
            ],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
