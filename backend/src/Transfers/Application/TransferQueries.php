<?php

declare(strict_types=1);

namespace App\Transfers\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Currency\Application\Api\ExchangeMarket;
use App\Debts\Application\Api\DebtAccess;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Port\CursorPort;
use App\Transfers\Application\Port\TransferRepositoryPort;
use App\Transfers\Domain\Transfer;
use DateTimeZone;

final readonly class TransferQueries
{
    public function __construct(private TransferRepositoryPort $repository, private ConnectAccess $connects, private AccountAccess $accounts, private DebtAccess $debts, private CursorPort $cursor, private ExchangeMarket $market)
    {
    }

    /** @return array<string,mixed> */
    public function detail(string $id, string $viewerId): array
    {
        $t = self::isUuid($id) ? $this->repository->find($id) : null;
        if (null === $t || !$this->connects->isSide($t->connectId, $viewerId)) {
            throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
        }

        return $this->view($t, $viewerId);
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array{nextCursor:?string,hasMore:bool}} */
    public function history(string $connectId, string $viewerId, int $limit = 20, ?string $cursor = null): array
    {
        if (!self::isUuid($connectId) || !$this->connects->isSide($connectId, $viewerId)) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $before = $this->cursor->decode($cursor);
        $rows = $this->repository->page($connectId, $limit + 1, $before['before'], $before['id']);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[count($rows) - 1] ?? null;

        return ['items' => array_map(fn (Transfer $t): array => $this->view($t, $viewerId), $rows), 'pagination' => ['nextCursor' => $hasMore && null !== $last ? $this->cursor->encode($last->createdAt, $last->id) : null, 'hasMore' => $hasMore]];
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array{nextCursor:?string,hasMore:bool}} */
    public function userHistory(string $viewerId, string $direction, int $limit = 20, ?string $cursor = null): array
    {
        if (!in_array($direction, ['incoming', 'outgoing'], true) || $limit < 1 || $limit > 100) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $before = $this->cursor->decode($cursor);
        $rows = $this->repository->pageForUser($viewerId, $direction, $limit + 1, $before['before'], $before['id']);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[count($rows) - 1] ?? null;

        return ['items' => array_map(fn (Transfer $t): array => $this->view($t, $viewerId), $rows), 'pagination' => ['nextCursor' => $hasMore && null !== $last ? $this->cursor->encode($last->createdAt, $last->id) : null, 'hasMore' => $hasMore]];
    }

    /** @return array{id:string,name:string,isActive:bool} */
    private function user(string $id): array
    {
        $a = $this->accounts->find($id);
        if (null === $a) {
            throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
        }

        return ['id' => $a->id, 'name' => $a->name, 'isActive' => $a->isActive];
    }

    /** @return array<string,mixed> */
    private function view(Transfer $t, string $viewerId): array
    {
        $sender = $this->user($t->senderId);
        $receiver = $this->user($t->receiverId);
        $canEdit = 'MANUAL' === $t->type && !$t->isDeleted && $t->createdById === $viewerId && $sender['isActive'] && $receiver['isActive'];

        $viewerCurrency = $this->accounts->find($viewerId)->currency ?? 'EUR';
        $share = null === $t->relatedTransferId ? null : $this->repository->find($t->relatedTransferId);
        $applied = $this->market->cardEquivalent($t->amount, $t->currency, $viewerCurrency, $t->manualRate, $t->rateCurrency, 'DEBT_SHARE' === $t->type);

        return ['viewerCurrency' => $viewerCurrency, 'equivalentAmount' => $applied, 'shareCurrency' => $share?->currency, 'shareRemainingAmount' => null === $share || $share->isDeleted ? null : $share->amount - $share->creditedAmount, 'availableBeforePayment' => null === $share || $share->isDeleted ? null : $this->repository->availableBefore($t->id, $share->id), 'settlementRate' => $t->settlementRate, 'id' => $t->id, 'connectId' => $t->connectId, 'sender' => $sender, 'receiver' => $receiver, 'currency' => $t->currency, 'manualRate' => $t->manualRate, 'rateCurrency' => $t->rateCurrency, 'creditedAmount' => $t->creditedAmount, 'excessAmount' => $t->excessAmount, 'remainingAmount' => 'DEBT_SHARE' === $t->type ? $t->amount - $t->creditedAmount : null, 'amount' => $t->amount, 'type' => $t->type, 'createdBy' => null === $t->createdById ? null : $this->user($t->createdById), 'debtReference' => null === $t->debtId ? null : $this->debts->reference($t->debtId, $viewerId), 'relatedTransferId' => $t->relatedTransferId, 'isDeleted' => $t->isDeleted, 'canEdit' => $canEdit, 'canDelete' => $canEdit, 'version' => $t->version, 'createdAt' => $t->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
    }

    private static function isUuid(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id);
    }
}
