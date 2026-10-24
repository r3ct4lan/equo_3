<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Http;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Adapter\Http\Request\CreateManualTransferRequest;
use App\Transfers\Adapter\Http\Request\UpdateManualTransferRequest;
use App\Transfers\Application\CreateManualTransfer;
use App\Transfers\Application\CreateManualTransferCommand;
use App\Transfers\Application\DeleteManualTransfer;
use App\Transfers\Application\TransferQueries;
use App\Transfers\Application\UpdateManualTransfer;
use App\Transfers\Application\UpdateManualTransferCommand;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransferController
{
    public function __construct(private TransferQueries $queries, private Security $security, private CreateManualTransfer $createManualTransfer, private UpdateManualTransfer $updateManualTransfer, private DeleteManualTransfer $deleteManualTransfer)
    {
    }

    #[Route('/api/v1/connects/{connectId}/transfers', name: 'api_v1_manual_transfer_create', methods: ['POST'])]
    public function create(string $connectId, Request $request, #[MapRequestPayload(acceptFormat: 'json')] CreateManualTransferRequest $payload): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key || '' === trim($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyRequired);
        }
        if (!CreateManualTransferCommand::isUuid($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        if (!CreateManualTransferCommand::isUuid($connectId)) {
            throw new ApplicationFailure(ApplicationFailureCode::ResourceNotFound);
        }
        $result = $this->createManualTransfer->handle(new CreateManualTransferCommand($connectId, $payload->senderId ?? '', $payload->receiverId ?? '', is_int($payload->amount) ? $payload->amount : 0, $key), $this->userId());
        $body = $result['body'];

        return new JsonResponse($body, $result['status'], ['Cache-Control' => 'no-store', 'Location' => '/api/v1/transfers/'.$body['id'], 'ETag' => '"'.$body['version'].'"']);
    }

    #[Route('/api/v1/transfers/{transferId}', name: 'api_v1_manual_transfer_update', methods: ['PUT'])]
    public function update(string $transferId, Request $request, #[MapRequestPayload(acceptFormat: 'json')] UpdateManualTransferRequest $payload): JsonResponse
    {
        $expectedVersion = $this->expectedVersion($request);
        if (!CreateManualTransferCommand::isUuid($transferId)) {
            throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
        }
        $body = $this->updateManualTransfer->handle(new UpdateManualTransferCommand($transferId, $payload->senderId ?? '', $payload->receiverId ?? '', is_int($payload->amount) ? $payload->amount : 0, $expectedVersion), $this->userId());

        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'ETag' => '"'.$body['version'].'"']);
    }

    #[Route('/api/v1/transfers/{transferId}', name: 'api_v1_manual_transfer_delete', methods: ['DELETE'])]
    public function delete(string $transferId, Request $request): JsonResponse
    {
        $expectedVersion = $this->expectedVersion($request);
        if (!CreateManualTransferCommand::isUuid($transferId)) {
            throw new ApplicationFailure(ApplicationFailureCode::TransferNotAvailable);
        }
        $this->deleteManualTransfer->handle($transferId, $expectedVersion, $this->userId());

        return new JsonResponse(null, 204, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/transfers/{transferId}', name: 'api_v1_transfer_detail', methods: ['GET'])]
    public function detail(string $transferId): JsonResponse
    {
        $body = $this->queries->detail($transferId, $this->userId());

        return new JsonResponse($body, 200, ['Cache-Control' => 'no-store', 'ETag' => '"'.$body['version'].'"']);
    }

    #[Route('/api/v1/connects/{connectId}/transfers', name: 'api_v1_connect_transfers', methods: ['GET'])]
    public function history(string $connectId, Request $request): JsonResponse
    {
        $params = $request->query->all();
        $limit = $params['limit'] ?? '20';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($limit) || 1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (null !== $cursor && !is_string($cursor)) || [] !== array_diff(array_keys($params), ['limit', 'cursor'])) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return new JsonResponse($this->queries->history($connectId, $this->userId(), (int) $limit, $cursor), 200, ['Cache-Control' => 'no-store']);
    }

    private function userId(): string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $user->getUserIdentifier();
    }

    private function expectedVersion(Request $request): int
    {
        $etag = $request->headers->get('If-Match');
        if (null === $etag || '' === trim($etag)) {
            throw new ApplicationFailure(ApplicationFailureCode::PreconditionRequired);
        }
        if (1 !== preg_match('/^"([1-9][0-9]{0,9})"$/D', $etag, $match) || (int) $match[1] > 2147483647) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return (int) $match[1];
    }
}
