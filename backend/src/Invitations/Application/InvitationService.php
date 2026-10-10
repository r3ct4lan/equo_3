<?php

declare(strict_types=1);

namespace App\Invitations\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Invitations\Application\Port\InvitationRateLimitPort;
use App\Invitations\Application\Port\InvitationRepositoryPort;
use App\Invitations\Application\Port\InvitationRuntimePort;
use App\Invitations\Application\Port\InvitationTokenPort;
use App\Invitations\Domain\ConnectInvitation;
use DateTimeImmutable;
use DateTimeZone;

final readonly class InvitationService
{
    public function __construct(private InvitationRepositoryPort $repository, private InvitationRuntimePort $runtime, private InvitationTokenPort $tokens, private InvitationRateLimitPort $limits, private AccountAccess $accounts, private ConnectAccess $connects)
    {
    }

    /** @return array{token: string, expiresAt: string, created: bool} */
    public function create(string $authorId): array
    {
        return $this->runtime->run(function () use ($authorId): array {
            $author = $this->accounts->find($authorId, true);
            if (null === $author || !$author->isActive) {
                throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
            }
            $now = $this->runtime->now();
            $i = $this->repository->available($authorId, $now);
            $created = null === $i;
            if (null === $i) {
                $id = $this->runtime->newId();
                $token = $this->tokens->issue($id);
                $i = new ConnectInvitation($id, $authorId, $this->tokens->digest($token), $now, $now->modify('+7 days'));
                $this->repository->save($i);
            } else {
                $token = $this->tokens->reproduce($i->id, $i->tokenHash);
            }

            return ['token' => $token, 'expiresAt' => self::date($i->expiresAt), 'created' => $created];
        });
    }

    /** @return array{inviter: array{id: string, name: string}, expiresAt: string, canAccept: bool} */
    public function preview(string $token): array
    {
        $i = $this->validated($token);
        if (null !== $i->usedAt) {
            throw new ApplicationFailure(ApplicationFailureCode::TokenUsed);
        }
        if (!$i->isAvailable($this->runtime->now())) {
            throw new ApplicationFailure(ApplicationFailureCode::TokenExpired);
        }
        $author = $this->accounts->find($i->createdById);
        if (null === $author) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        return ['inviter' => ['id' => $author->id, 'name' => $author->name], 'expiresAt' => self::date($i->expiresAt), 'canAccept' => $author->isActive];
    }

    /** @return array{view: array<string, mixed>, created: bool} */
    public function accept(string $token, string $acceptorId, string $ip): array
    {
        $id = $this->tokens->verify($token);
        $retry = $this->limits->retryAfter($ip, $id);
        if (null !== $retry) {
            throw new ApplicationFailure(ApplicationFailureCode::RateLimitExceeded, $retry);
        }
        $snapshot = $this->validated($token);

        return $this->runtime->run(function () use ($snapshot, $token, $acceptorId): array {
            $ids = array_unique([$snapshot->createdById, $acceptorId]);
            sort($ids, SORT_STRING);
            $active = true;
            foreach ($ids as $id) {
                $account = $this->accounts->find($id, true);
                $active = $active && null !== $account && $account->isActive;
            }
            $i = $this->validated($token, true);
            if (null !== $i->usedAt && $i->acceptedById !== $acceptorId) {
                throw new ApplicationFailure(ApplicationFailureCode::TokenUsed);
            }
            if ($i->createdById === $acceptorId) {
                throw new ApplicationFailure(ApplicationFailureCode::ValidationError);
            }
            if (!$active) {
                throw new ApplicationFailure(ApplicationFailureCode::InactiveUser);
            }
            if (null !== $i->usedAt) {
                return $this->connects->getOrCreate($i->createdById, $acceptorId, $acceptorId) + [];
            }
            $now = $this->runtime->now();
            if (!$i->isAvailable($now)) {
                throw new ApplicationFailure(ApplicationFailureCode::TokenExpired);
            }
            $result = $this->connects->getOrCreate($i->createdById, $acceptorId, $acceptorId);
            $i->accept($acceptorId, $now);
            $this->repository->save($i);

            return $result;
        });
    }

    private function validated(string $token, bool $forUpdate = false): ConnectInvitation
    {
        $id = $this->tokens->verify($token);
        $i = null === $id ? null : $this->repository->find($id, $forUpdate);
        if (null === $i || !hash_equals($i->tokenHash, $this->tokens->digest($token))) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }

        return $i;
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
