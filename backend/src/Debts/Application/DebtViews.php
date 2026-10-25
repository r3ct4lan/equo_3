<?php

declare(strict_types=1);

namespace App\Debts\Application;

use App\Connects\Application\Api\ConnectAccess;
use App\Debts\Domain\Debt;
use App\IdentityAccess\Application\Api\AccountAccess;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\Api\AutomaticShares;
use DateTimeImmutable;
use DateTimeZone;

final readonly class DebtViews
{
    public function __construct(private AccountAccess $accounts, private AutomaticShares $shares, private ConnectAccess $connects)
    {
    }

    /** @return array{id:string,name:string,isActive:bool} */
    public function user(string $id): array
    {
        $a = $this->accounts->find($id);
        if (null === $a) {
            throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
        }

        return ['id' => $a->id, 'name' => $a->name, 'isActive' => $a->isActive];
    }

    /** @return array<string,mixed> */
    public function detail(Debt $debt, string $viewerId, bool $includeParticipantConnects = true): array
    {
        if (!$debt->accessibleTo($viewerId)) {
            throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
        }
        $payer = $this->user($debt->payerId);
        $author = $this->user($debt->createdById);
        $canEdit = $viewerId === $debt->createdById && !$debt->isDeleted && $payer['isActive'] && $author['isActive'];
        $shares = $this->shares->forDebt($debt->id);
        $participants = [];
        foreach ($debt->currentParticipants() as $p) {
            $participants[] = ['debtParticipantId' => $p->id, 'user' => $this->user($p->userId), 'isPayer' => $p->userId === $debt->payerId, 'isAuthor' => $p->userId === $debt->createdById, 'shareAmount' => $debt->shareAmount(), 'transferId' => $shares[$p->id] ?? null, 'connectId' => $includeParticipantConnects ? $this->connects->findPairId($viewerId, $p->userId) : null];
        }

        return ['id' => $debt->id, 'title' => $debt->title, 'description' => $debt->description, 'totalAmount' => $debt->totalAmount->cents, 'payer' => $payer, 'createdBy' => $author, 'includePayerInSplit' => $debt->includePayerInSplit(), 'participantCount' => count($participants), 'shareAmount' => $debt->shareAmount(), 'participants' => $participants, 'isDeleted' => $debt->isDeleted, 'canEdit' => $canEdit, 'canDelete' => $canEdit, 'version' => $debt->version, 'createdAt' => self::date($debt->createdAt)];
    }

    public static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
