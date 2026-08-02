<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Messaging;

use App\IdentityAccess\Application\Api\TokenDeliveryQuery;
use App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord;
use App\Infrastructure\EmailDelivery\Security\PayloadCipher;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final readonly class SendUserActionEmailHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenDeliveryQuery $tokenDelivery,
        private PayloadCipher $payloadCipher,
        private TransportInterface $mailerTransport,
        private string $frontendBaseUrl,
        private string $fromAddress,
    ) {
    }

    public function __invoke(SendUserActionEmail $message): void
    {
        $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($message): void {
            $outbox = $this->entityManager->find(
                EmailDeliveryOutboxRecord::class,
                $message->deliveryId,
                LockMode::PESSIMISTIC_WRITE,
            );

            if (!$outbox instanceof EmailDeliveryOutboxRecord || $outbox->isTerminal()) {
                return;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $state = $this->tokenDelivery->state($outbox->userActionTokenId(), $now);

            if (null === $state || !$state->isDeliverableAt($now)) {
                $outbox->markFailed($now, 'Action token is no longer deliverable.');
                $this->entityManager->flush();

                return;
            }

            $ciphertext = $outbox->encryptedPayload();
            if (null === $ciphertext) {
                throw new RuntimeException('Email delivery payload is missing.');
            }

            $payload = $this->payloadCipher->decrypt($ciphertext);
            $token = $payload['token'] ?? null;
            if (!is_string($token) || '' === $token) {
                throw new RuntimeException('Email delivery payload has no activation token.');
            }

            $activationUrl = rtrim($this->frontendBaseUrl, '/').'/activate?token='.rawurlencode($token);
            $email = (new Email())
                ->from($this->fromAddress)
                ->to($outbox->recipientEmail())
                ->subject('Activate your Equo account')
                ->text("Activate your Equo account: {$activationUrl}")
                ->html(sprintf(
                    '<p>Activate your Equo account:</p><p><a href="%s">Activate account</a></p>',
                    htmlspecialchars($activationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ));

            $this->mailerTransport->send($email);
            $outbox->markSent($now);
            $this->entityManager->flush();
        });
    }
}
