<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Debts\Application\Api\DebtAccess;
use App\Debts\Application\Port\CursorPort;
use App\Debts\Application\Port\DebtRepositoryPort;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;

final readonly class DebtQueries implements DebtAccess
{
    public function __construct(private DebtRepositoryPort $repository, private DebtViews $views, private CursorPort $cursor)
    {
    }

    /** @return array<string,mixed> */
    public function detail(string $id, string $viewerId): array
    {
        $debt = CreateDebtCommand::isUuid($id) ? $this->repository->find($id) : null;
        if (null === $debt || !$debt->accessibleTo($viewerId)) {
            throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
        }

        return $this->views->detail($debt, $viewerId);
    }

    public function reference(string $debtId, string $viewerId): array
    {
        $d = $this->repository->find($debtId);
        if (null === $d || !$d->accessibleTo($viewerId)) {
            return ['id' => $debtId, 'isAccessible' => false];
        }

        return ['id' => $debtId, 'isAccessible' => true, 'title' => $d->title, 'isDeleted' => $d->isDeleted];
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array{nextCursor:?string,hasMore:bool}} */
    public function page(string $viewerId, int $limit = 20, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $before = $this->cursor->decode($cursor);
        $rows = $this->repository->page($viewerId, $limit + 1, $before['before'], $before['id']);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[count($rows) - 1] ?? null;
        $items = [];
        foreach ($rows as $d) {
            $v = $this->views->detail($d, $viewerId, false);
            $items[] = ['id' => $v['id'], 'title' => $v['title'], 'totalAmount' => $v['totalAmount'], 'payer' => $v['payer'], 'participantCount' => $v['participantCount'], 'shareAmount' => $v['shareAmount'], 'canEdit' => $v['canEdit'], 'version' => $v['version'], 'createdAt' => $v['createdAt']];
        }

        return ['items' => $items, 'pagination' => ['nextCursor' => $hasMore && null !== $last ? $this->cursor->encode($last->createdAt, $last->id) : null, 'hasMore' => $hasMore]];
    }
}
