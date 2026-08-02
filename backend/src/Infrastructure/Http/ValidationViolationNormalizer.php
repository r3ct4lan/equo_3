<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ValidationViolationNormalizer
{
    /** @return list<array{field: string, code: string, message: string}> */
    public function normalize(ValidationFailedException $exception): array
    {
        $violations = [];

        foreach ($exception->getViolations() as $violation) {
            $violations[] = [
                'field' => '' !== $violation->getPropertyPath() ? $violation->getPropertyPath() : 'request',
                'code' => $this->publicCode($violation),
                'message' => $this->publicMessage($violation),
            ];
        }

        usort($violations, static fn (array $left, array $right): int => [
            $left['field'],
            $left['code'],
            $left['message'],
        ] <=> [
            $right['field'],
            $right['code'],
            $right['message'],
        ]);

        return $violations;
    }

    private function publicCode(ConstraintViolationInterface $violation): string
    {
        $payload = $violation->getConstraint()?->payload;

        if (is_array($payload) && isset($payload['code']) && is_string($payload['code'])) {
            return $payload['code'];
        }

        if (str_contains($violation->getMessageTemplate(), 'type')) {
            return 'INVALID_TYPE';
        }

        return 'VALIDATION_ERROR';
    }

    private function publicMessage(ConstraintViolationInterface $violation): string
    {
        if ('INVALID_TYPE' === $this->publicCode($violation)) {
            return 'Value has invalid type.';
        }

        return (string) $violation->getMessage();
    }
}
