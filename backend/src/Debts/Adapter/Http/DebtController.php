<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http;

use App\Debts\Adapter\Http\Request\CreateDebtRequest;
use App\Debts\Adapter\Http\Request\UpdateDebtRequest;
use App\Debts\Application\CreateDebt;
use App\Debts\Application\CreateDebtCommand;
use App\Debts\Application\DebtQueries;
use App\Debts\Application\DeleteDebt;
use App\Debts\Application\UpdateDebt;
use App\Debts\Application\UpdateDebtCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DebtController
{
    public function __construct(private CreateDebt $createDebt, private DebtQueries $queries, private Security $security, private UpdateDebt $updateDebt, private DeleteDebt $deleteDebt)
    {
    }

    #[Route('/api/v1/debts', name: 'api_v1_debt_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload(acceptFormat: 'json')] CreateDebtRequest $payload): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key || '' === trim($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyRequired);
        }
        if (!CreateDebtCommand::isUuid($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $result = $this->createDebt->handle(new CreateDebtCommand($payload->title ?? '', $payload->description, is_int($payload->totalAmount) ? $payload->totalAmount : 0, $payload->payerId ?? '', $payload->participantIds ?? [], is_bool($payload->includePayerInSplit) ? $payload->includePayerInSplit : false, $key), $this->userId());
        $body = $result['body'];

        return new JsonResponse($body, $result['status'], ['Cache-Control' => 'no-store', 'Location' => '/api/v1/debts/'.$body['id'], 'ETag' => '"'.$body['version'].'"']);
    }

    #[Route('/api/v1/debts/{debtId}', name: 'api_v1_debt_update', methods: ['PUT'])]
    public function update(string $debtId, Request $request, #[MapRequestPayload(acceptFormat: 'json')] UpdateDebtRequest $payload): JsonResponse
    {
        $etag = $request->headers->get('If-Match');
        if (null === $etag || '' === trim($etag)) {
            throw new ApplicationFailure(ApplicationFailureCode::PreconditionRequired);
        }
        if (1 !== preg_match('/^"([1-9][0-9]{0,9})"$/D', $etag, $match) || (int) $match[1] > 2147483647) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        if (!CreateDebtCommand::isUuid($debtId)) {
            throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
        }
        $body = $this->updateDebt->handle(new UpdateDebtCommand($debtId, $payload->title ?? '', $payload->description, is_int($payload->totalAmount) ? $payload->totalAmount : 0, $payload->participantIds ?? [], is_bool($payload->includePayerInSplit) ? $payload->includePayerInSplit : false, (int) $match[1]), $this->userId());

        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'ETag' => '"'.$body['version'].'"']);
    }

    #[Route('/api/v1/debts/{debtId}', name: 'api_v1_debt_delete', methods: ['DELETE'])]
    public function delete(string $debtId, Request $request): JsonResponse
    {
        $etag = $request->headers->get('If-Match');
        if (null === $etag || '' === trim($etag)) {
            throw new ApplicationFailure(ApplicationFailureCode::PreconditionRequired);
        }
        if (1 !== preg_match('/^"([1-9][0-9]{0,9})"$/D', $etag, $match) || (int) $match[1] > 2147483647) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        if (!CreateDebtCommand::isUuid($debtId)) {
            throw new ApplicationFailure(ApplicationFailureCode::DebtNotAvailable);
        }
        $this->deleteDebt->handle($debtId, (int) $match[1], $this->userId());

        return new JsonResponse(null, 204, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/debts', name: 'api_v1_debt_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $params = $request->query->all();
        $limit = $params['limit'] ?? '20';
        $cursor = $params['cursor'] ?? null;
        $status = $params['status'] ?? null;
        if (!is_string($limit) || 1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (null !== $cursor && !is_string($cursor)) || (null !== $status && 'active' !== $status) || [] !== array_diff(array_keys($params), ['limit', 'cursor', 'status'])) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return new JsonResponse($this->queries->page($this->userId(), (int) $limit, $cursor, 'active' === $status), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/debts/{debtId}', name: 'api_v1_debt_detail', methods: ['GET'])]
    public function detail(string $debtId): JsonResponse
    {
        $body = $this->queries->detail($debtId, $this->userId());

        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'ETag' => '"'.$body['version'].'"']);
    }

    private function userId(): string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $user->getUserIdentifier();
    }
}
