<?php

declare(strict_types=1);

namespace App\Connects\Adapter\Http;

use App\Connects\Adapter\Http\Request\CreateConnectRequest;
use App\Connects\Application\ConnectService;
use App\Connects\Application\CreateConnect;
use App\Connects\Application\CreateConnectCommand;
use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ConnectController
{
    public function __construct(private ConnectService $service, private Security $security, private CreateConnect $createConnect)
    {
    }

    #[Route('/api/v1/connects', name: 'api_v1_connect_create', methods: ['POST'])]
    public function create(Request $request, #[MapRequestPayload(acceptFormat: 'json')] CreateConnectRequest $payload): JsonResponse
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key || '' === trim($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::IdempotencyKeyRequired);
        }
        if (!CreateConnectCommand::isUuid($key)) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
        $result = $this->createConnect->handle(new CreateConnectCommand($payload->otherUserId ?? '', $payload->sharedDebtId ?? '', $key), $this->userId());
        $body = $result['body'];

        return new JsonResponse($body, $result['status'], ['Cache-Control' => 'no-store', 'Location' => '/api/v1/connects/'.$body['id']]);
    }

    #[Route('/api/v1/connects', name: 'api_v1_connect_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $params = $request->query->all();
        $limit = $params['limit'] ?? '20';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($limit) || 1 !== preg_match('/^[1-9][0-9]{0,2}$/D', $limit) || (null !== $cursor && !is_string($cursor)) || [] !== array_diff(array_keys($params), ['limit', 'cursor'])) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }

        return new JsonResponse($this->service->page($this->userId(), (int) $limit, $cursor), 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/v1/connects/{connectId}', name: 'api_v1_connect_detail', methods: ['GET'])]
    public function detail(string $connectId): JsonResponse
    {
        return new JsonResponse($this->service->detail($connectId, $this->userId()), 200, ['Cache-Control' => 'no-store']);
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
