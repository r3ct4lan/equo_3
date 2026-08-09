<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\ApiJsonResponder;
use App\Infrastructure\Http\BearerAuthenticationEntryPoint;
use App\Infrastructure\Http\RequestIdSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;

final class BearerAuthenticationEntryPointTest extends TestCase
{
    public function testReturnsExactAuthenticationRequiredEnvelope(): void
    {
        $request = Request::create('/api/v1/me');
        $request->attributes->set(RequestIdSubscriber::ATTRIBUTE, 'request-123');
        $response = (new BearerAuthenticationEntryPoint(new ApiJsonResponder(new Serializer([new DateTimeNormalizer()], [new JsonEncoder()]))))->start($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame([
            'error' => [
                'code' => 'AUTHENTICATION_REQUIRED',
                'message' => 'Authentication is required.',
                'requestId' => 'request-123',
            ],
        ], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('raw auth failure', (string) $response->getContent());
    }
}
