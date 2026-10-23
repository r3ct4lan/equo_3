<?php

declare(strict_types=1);

namespace App\Invitations\Adapter\Http;

use App\IdentityAccess\Application\Api\Error\ApplicationFailure;
use App\IdentityAccess\Application\Api\Error\ApplicationFailureCode;
use App\Invitations\Application\InvitationService;
use App\Invitations\Application\PendingInvitationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class InvitationController
{
    public function __construct(private InvitationService $service, private PendingInvitationService $pending, private Security $security, private string $applicationOrigin)
    {
    }

    #[Route('/api/v1/connect-invitations', name: 'api_v1_invitation_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->emptyBody($request);
        $result = $this->service->create($this->userId());
        $result['url'] = rtrim($this->applicationOrigin, '/').'/connect-invitations/'.$result['token'];

        return $this->respond($result);
    }

    #[Route('/api/v1/connect-invitations/{token}', name: 'api_v1_invitation_preview', methods: ['GET'])]
    public function preview(string $token): JsonResponse
    {
        return $this->respond($this->service->preview($token));
    }

    #[Route('/api/v1/connect-invitations/{token}/accept', name: 'api_v1_invitation_accept', methods: ['POST'])]
    public function accept(string $token, Request $request): JsonResponse
    {
        $this->emptyBody($request);
        $result = $this->service->accept($token, $this->userId(), $request->getClientIp() ?? 'unknown');

        return $this->respond($result['view'] + ['invitationOutcome' => $result['outcome']], $result['created'] ? 201 : 200);
    }

    #[Route('/api/v1/connect-invitations/pending/accept', name: 'api_v1_pending_invitation_accept', methods: ['POST'], priority: 10)]
    public function acceptPending(Request $request): Response
    {
        $this->emptyBody($request);
        $result = $this->pending->accept($this->userId(), $request->getClientIp() ?? 'unknown');

        return null === $result
            ? new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'])
            : $this->respond($result['view'] + ['invitationOutcome' => $result['outcome']], $result['created'] ? 201 : 200);
    }

    private function userId(): string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $user->getUserIdentifier();
    }

    private function emptyBody(Request $request): void
    {
        if ('' !== $request->getContent()) {
            throw new ApplicationFailure(ApplicationFailureCode::InvalidRequest);
        }
    }

    /** @param array<string, mixed> $result */
    private function respond(array $result, int $status = 200): JsonResponse
    {
        return new JsonResponse($result, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
