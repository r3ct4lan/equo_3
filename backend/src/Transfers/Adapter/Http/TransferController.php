<?php

declare(strict_types=1);

namespace App\Transfers\Adapter\Http;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Transfers\Application\TransferQueries;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransferController
{
    public function __construct(private TransferQueries $queries, private Security $security)
    {
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
}
