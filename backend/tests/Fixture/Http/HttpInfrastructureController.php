<?php

declare(strict_types=1);

namespace App\Tests\Fixture\Http;

use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class HttpInfrastructureController
{
    public function payload(
        #[MapRequestPayload(acceptFormat: 'json')]
        FixtureRequest $payload,
    ): array {
        return [
            'id' => $payload->id,
            'name' => $payload->name,
        ];
    }

    public function response(): FixtureResponse
    {
        return new FixtureResponse(
            '550e8400-e29b-41d4-a716-446655440000',
            new DateTimeImmutable('2026-07-31T14:15:16+02:00'),
            FixtureStatus::Ready,
            new FixtureNestedResponse('nested'),
        );
    }

    public function error(): never
    {
        throw new RuntimeException('SQLSTATE password=secret at /var/www/backend/src/Internal.php:42');
    }
}
