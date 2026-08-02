<?php

declare(strict_types=1);

namespace App\IdentityAccess\Adapter\Persistence\Doctrine;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\IdentityAccess\Application\Port\TransactionPort;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class DoctrineTransaction implements TransactionPort
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $operation): mixed
    {
        try {
            return $this->entityManager->wrapInTransaction(
                static fn (EntityManagerInterface $entityManager): mixed => $operation(),
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'uniq_app_user_email')) {
                throw new ApplicationFailure(ApplicationFailureCode::EmailAlreadyExists);
            }

            throw $exception;
        } catch (Throwable $exception) {
            throw $exception;
        }
    }
}
