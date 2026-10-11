<?php

declare(strict_types=1);

namespace App\Debts\Adapter\Http;

use App\Debts\Adapter\Http\Request\CreateDebtRequest;
use App\Debts\Application\CreateDebt;
use App\Debts\Application\CreateDebtCommand;
use App\Debts\Application\DebtQueries;
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
    public function __construct(private CreateDebt $createDebt, private DebtQueries $queries, private Security $security)
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

    #[Route('/api/v1/debts', name: 'api_v1_debt_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $params = $request->query->all();
        $limit = $params['limit'] ?? '20';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($limit) || 1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (null !== $cursor && !is_string($cursor)) || [] !== array_diff(array_keys($params), ['limit', 'cursor'])) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return new JsonResponse($this->queries->page($this->userId(), (int) $limit, $cursor), 200, ['Cache-Control' => 'no-store']);
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
