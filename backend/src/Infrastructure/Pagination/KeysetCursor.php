<?php

declare(strict_types=1);

namespace App\Infrastructure\Pagination;

use App\Debts\Application\Port\CursorPort as DebtCursor;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Port\CursorPort as TransferCursor;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class KeysetCursor implements DebtCursor, TransferCursor
{
    public function decode(?string $cursor): array
    {
        if (null === $cursor) {
            return ['before' => null, 'id' => null];
        }
        try {
            if (strlen($cursor) > 256 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                throw new JsonException();
            }
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            if (false === $raw) {
                throw new JsonException();
            }
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_string($data['createdAt'] ?? null) || !is_string($data['id'] ?? null) || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $data['id'])) {
                throw new JsonException();
            }
            $before = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['createdAt'], new DateTimeZone('UTC'));
            if (false === $before || $before->format('Y-m-d\TH:i:s\Z') !== $data['createdAt']) {
                throw new JsonException();
            }

            return ['before' => $before, 'id' => $data['id']];
        } catch (JsonException) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
    }

    public function encode(DateTimeImmutable $createdAt, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['createdAt' => $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
