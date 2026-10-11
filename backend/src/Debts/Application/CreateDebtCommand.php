<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Domain\Money;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class CreateDebtCommand
{
    public string $title;
    public string $payerId;
    /** @var list<string> */ public array $participantIds;
    public string $idempotencyKey;

    /** @param list<string> $participantIds */
    public function __construct(string $title, public ?string $description, public int $totalAmount, string $payerId, array $participantIds, public bool $includePayerInSplit, string $idempotencyKey)
    {
        if ('' === trim($title) || $totalAmount < 1 || $totalAmount > Money::MAX_CENTS || !self::isUuid($payerId) || !self::isUuid($idempotencyKey) || [] === $participantIds) {
            throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
        }
        foreach ($participantIds as $id) {
            if (!self::isUuid($id)) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
        }
        $ids = array_map(strtolower(...), $participantIds);
        $payerId = strtolower($payerId);
        if (count(array_unique($ids)) !== count($ids) || in_array($payerId, $ids, true)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidParticipant);
        }
        sort($ids, SORT_STRING);
        $this->title = trim($title);
        $this->payerId = $payerId;
        $this->participantIds = $ids;
        $this->idempotencyKey = strtolower($idempotencyKey);
    }

    public static function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $value);
    }

    public function canonical(): string
    {
        return json_encode(['title' => $this->title, 'description' => $this->description, 'totalAmount' => $this->totalAmount, 'payerId' => $this->payerId, 'participantIds' => $this->participantIds, 'includePayerInSplit' => $this->includePayerInSplit], JSON_THROW_ON_ERROR);
    }
}
