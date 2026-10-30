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

            $template = null === $state ? null : match (true) {
                'ACTIVATE_ACCOUNT' === $state->purpose->value && 'activate-account' === $outbox->templateKey() => ['Activate your Equo account', '/activate', 'Activate account'],
                'RESET_PASSWORD' === $state->purpose->value && 'reset-password' === $outbox->templateKey() => ['Reset your Equo password', '/password-reset', 'Choose a new password'],
                'CHANGE_EMAIL' === $state->purpose->value && 'change-email' === $outbox->templateKey() => ['Confirm your new Equo email', '/email-change', 'Confirm new email'],
                default => null,
            };
            if (null === $state || !$state->isDeliverableAt($now) || null === $template) {
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
                throw new RuntimeException('Email delivery payload has no action token.');
            }

            if ('ru' === ($payload['locale'] ?? 'en')) {
                $template = match ($outbox->templateKey()) {
                    'activate-account' => ['Активируйте аккаунт Equo', '/activate', 'Активировать аккаунт'],
                    'reset-password' => ['Сброс пароля Equo', '/password-reset', 'Выбрать новый пароль'],
                    'change-email' => ['Подтвердите новый адрес электронной почты Equo', '/email-change', 'Подтвердить адрес'],
                    default => $template,
                };
            }

            [$subject, $path, $label] = $template;
            $actionUrl = rtrim($this->frontendBaseUrl, '/').$path.'?token='.rawurlencode($token);
            $email = (new Email())
                ->from($this->fromAddress)
                ->to($outbox->recipientEmail())
                ->subject($subject)
                ->text($subject.': '.$actionUrl)
                ->html(sprintf(
                    '<p>%s:</p><p><a href="%s">%s</a></p>',
                    $subject,
                    htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $label,
                ));

            $this->mailerTransport->send($email);
            $outbox->markSent($now);
            $this->entityManager->flush();
        });
    }
}
