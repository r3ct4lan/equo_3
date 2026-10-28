<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Application\Port\DebtPeopleSearchPort;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use JsonException;

final readonly class DebtPeopleSearch
{
    public function __construct(private DebtPeopleSearchPort $search, private DebtRepositoryPort $debts)
    {
    }

    /** @return array{items:list<array{id:string,name:string,isActive:bool,email:?string}>,pagination:array{nextCursor:?string,hasMore:bool}} */
    public function page(string $viewer, string $query, ?string $debtId, ?string $cursor): array
    {
        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') > 100 || 1 !== preg_match('//u', $query) || str_contains($query, "\0")) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        if (null !== $debtId) {
            $debt = CreateDebtCommand::isUuid($debtId) ? $this->debts->find($debtId) : null;
            if (null === $debt || $debt->isDeleted || $debt->createdById !== $viewer) {
                throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
            }
        }
        $binding = hash('sha256', $viewer."\0".$query."\0".($debtId ?? ''));
        $name = null;
        $id = null;
        if (null !== $cursor) {
            try {
                if (strlen($cursor) > 1024 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                    throw new JsonException();
                }
                $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
                $data = json_decode(false === $decoded ? '' : $decoded, true, 4, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['id'] ?? null) || !CreateDebtCommand::isUuid($data['id']) || !is_string($data['binding'] ?? null) || !hash_equals($binding, $data['binding'])) {
                    throw new JsonException();
                }
                $name = $data['name'];
                $id = $data['id'];
            } catch (JsonException) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
            }
        }
        $rows = $this->search->search($viewer, $query, $debtId, $name, $id);
        $hasMore = count($rows) > 5;
        $rows = array_slice($rows, 0, 5);
        $last = $rows[count($rows) - 1] ?? null;
        $next = $hasMore && null !== $last ? rtrim(strtr(base64_encode(json_encode(['name' => $last['sortName'], 'id' => $last['id'], 'binding' => $binding], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;
        $items = array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'isActive' => $row['isActive'], 'email' => $row['email']], $rows);

        return ['items' => $items, 'pagination' => ['nextCursor' => $next, 'hasMore' => $hasMore]];
    }
}
