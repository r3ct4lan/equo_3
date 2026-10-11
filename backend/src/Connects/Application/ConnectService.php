<?php

declare(strict_types=1);

namespace App\Connects\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Connects\Application\Port\ConnectRepositoryPort;
use App\Connects\Application\Port\ConnectRuntimePort;
use App\Connects\Domain\CanonicalPair;
use App\Connects\Domain\Connect;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\BalanceQuery;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class ConnectService implements ConnectAccess
{
    public function __construct(private ConnectRepositoryPort $repository, private ConnectRuntimePort $runtime, private AccountAccess $accounts, private BalanceQuery $balances)
    {
    }

    public function isSide(string $connectId, string $viewerId): bool
    {
        $c = $this->repository->find($connectId);

        return null !== $c && in_array($viewerId, [$c->pair->first, $c->pair->second], true);
    }

    public function exists(string $firstUserId, string $secondUserId): bool
    {
        return $firstUserId !== $secondUserId && $this->repository->exists(new CanonicalPair($firstUserId, $secondUserId));
    }

    public function ensurePair(string $firstUserId, string $secondUserId): string
    {
        return $this->repository->getOrCreate(new Connect($this->runtime->newId(), new CanonicalPair($firstUserId, $secondUserId), $this->runtime->now()))['connect']->id;
    }

    public function isPair(string $connectId, string $firstUserId, string $secondUserId): bool
    {
        $c = $this->repository->find($connectId);
        $pair = new CanonicalPair($firstUserId, $secondUserId);

        return null !== $c && $c->pair->first === $pair->first && $c->pair->second === $pair->second;
    }

    public function getOrCreate(string $firstUserId, string $secondUserId, string $viewerId): array
    {
        $result = $this->repository->getOrCreate(new Connect($this->runtime->newId(), new CanonicalPair($firstUserId, $secondUserId), $this->runtime->now()));

        return ['view' => $this->view($result['connect'], $viewerId), 'created' => $result['created']];
    }

    /** @return array<string, mixed> */
    public function detail(string $id, string $viewerId): array
    {
        if (!self::isUuid($id) || null === ($connect = $this->repository->find($id))) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        return $this->view($connect, $viewerId);
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array{nextCursor: ?string, hasMore: bool}} */
    public function page(string $viewerId, int $limit = 20, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $before = null;
        $id = null;
        if (null !== $cursor) {
            try {
                if (strlen($cursor) > 256 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) {
                    throw new JsonException();
                }
                $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
                if (false === $decoded) {
                    throw new JsonException();
                }
                $data = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_string($data['createdAt'] ?? null) || !is_string($data['id'] ?? null) || !self::isUuid($data['id'])) {
                    throw new JsonException();
                }
                $before = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $data['createdAt'], new DateTimeZone('UTC'));
                if (false === $before || $before->format('Y-m-d\TH:i:s\Z') !== $data['createdAt']) {
                    throw new JsonException();
                }
                $id = $data['id'];
            } catch (JsonException) {
                throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
            }
        }
        $rows = $this->repository->page($viewerId, $limit + 1, $before, $id);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows[count($rows) - 1] ?? null;
        $next = $hasMore && null !== $last ? rtrim(strtr(base64_encode(json_encode(['createdAt' => self::date($last->createdAt), 'id' => $last->id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : null;

        return ['items' => array_map(fn (Connect $c): array => $this->view($c, $viewerId), $rows), 'pagination' => ['nextCursor' => $next, 'hasMore' => $hasMore]];
    }

    /** @return array<string, mixed> */
    private function view(Connect $connect, string $viewerId): array
    {
        if (!in_array($viewerId, [$connect->pair->first, $connect->pair->second], true)) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        $otherId = $viewerId === $connect->pair->first ? $connect->pair->second : $connect->pair->first;
        $other = $this->accounts->find($otherId);
        $viewer = $this->accounts->find($viewerId);
        if (null === $other || null === $viewer) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        $view = ['id' => $connect->id, 'otherUser' => ['id' => $other->id, 'name' => $other->name, 'isActive' => $other->isActive], 'balanceAmount' => $this->balances->balance($connect->id, $viewerId), 'canCreateDebt' => $viewer->isActive && $other->isActive, 'canCreateTransfer' => $viewer->isActive && $other->isActive, 'createdAt' => self::date($connect->createdAt)];
        if (!$other->isActive) {
            $view['restriction'] = ['code' => 'OTHER_USER_INACTIVE', 'message' => 'New financial operations are unavailable until the user reactivates the account.'];
        }

        return $view;
    }

    public static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function isUuid(string $id): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }
}
