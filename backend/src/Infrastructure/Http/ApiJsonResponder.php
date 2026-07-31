<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class ApiJsonResponder
{
    private const array SERIALIZATION_CONTEXT = [
        DateTimeNormalizer::FORMAT_KEY => 'Y-m-d\TH:i:s\Z',
        DateTimeNormalizer::TIMEZONE_KEY => 'UTC',
    ];

    public function __construct(private SerializerInterface $serializer)
    {
    }

    /** @param array<string, string> $headers */
    public function respond(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($data, 'json', self::SERIALIZATION_CONTEXT),
            $status,
            $headers,
            true,
        );
    }
}
