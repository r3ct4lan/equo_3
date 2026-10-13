<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\EmailDelivery;

use App\Infrastructure\EmailDelivery\Monitoring\EmailOutboxAlertThresholds;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailOutboxAlertThresholdsTest extends TestCase
{
    public function testEmptyConfigurationIsUnconfigured(): void
    {
        foreach (['', '{}'] as $configuration) {
            $evaluation = (new EmailOutboxAlertThresholds($configuration))->evaluate(['pending_count' => 0]);

            self::assertSame(['status' => 'unconfigured', 'alerts' => []], $evaluation);
        }
    }

    public function testEvaluatesWarningAndCriticalThresholdsAtInclusiveBoundary(): void
    {
        $thresholds = new EmailOutboxAlertThresholds(<<<'JSON'
            {"pending_count":{"warning":5,"critical":10},"smtp_failure_count":{"warning":1,"critical":3}}
            JSON);

        $warning = $thresholds->evaluate(['pending_count' => 5, 'smtp_failure_count' => 0]);
        self::assertSame('warning', $warning['status']);
        self::assertSame([['metric' => 'pending_count', 'level' => 'warning', 'value' => 5, 'threshold' => 5]], $warning['alerts']);

        $critical = $thresholds->evaluate(['pending_count' => 10, 'smtp_failure_count' => 3]);
        self::assertSame('critical', $critical['status']);
        self::assertSame([
            ['metric' => 'pending_count', 'level' => 'critical', 'value' => 10, 'threshold' => 10],
            ['metric' => 'smtp_failure_count', 'level' => 'critical', 'value' => 3, 'threshold' => 3],
        ], $critical['alerts']);

        $healthy = $thresholds->evaluate(['pending_count' => 4, 'smtp_failure_count' => 0]);
        self::assertSame(['status' => 'ok', 'alerts' => []], $healthy);
    }

    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(string $configuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EmailOutboxAlertThresholds($configuration);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'malformed JSON' => ['{'];
        yield 'array root' => ['[]'];
        yield 'unknown metric' => ['{"unknown_count":{"warning":1,"critical":2}}'];
        yield 'missing level' => ['{"pending_count":{"warning":1}}'];
        yield 'negative threshold' => ['{"pending_count":{"warning":-1,"critical":2}}'];
        yield 'warning above critical' => ['{"pending_count":{"warning":3,"critical":2}}'];
        yield 'string threshold' => ['{"pending_count":{"warning":"1","critical":2}}'];
    }

    public function testRejectsMissingMetricAtEvaluationTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pending_count is unavailable');

        (new EmailOutboxAlertThresholds('{"pending_count":{"warning":1,"critical":2}}'))->evaluate([]);
    }
}
