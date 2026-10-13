<?php

declare(strict_types=1);

namespace App\Infrastructure\EmailDelivery\Monitoring;

use InvalidArgumentException;
use JsonException;

final readonly class EmailOutboxAlertThresholds
{
    /**
     * @var array<string, array{warning: int, critical: int}>
     */
    private array $thresholds;

    public function __construct(string $configuredThresholdsJson)
    {
        if ('' === trim($configuredThresholdsJson)) {
            $this->thresholds = [];

            return;
        }

        try {
            $decodedObject = json_decode($configuredThresholdsJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Email outbox alert thresholds must be valid JSON.');
        }

        if (!$decodedObject instanceof \stdClass) {
            throw new InvalidArgumentException('Email outbox alert thresholds must be a JSON object.');
        }

        $thresholds = [];
        foreach (get_object_vars($decodedObject) as $metric => $levelsObject) {
            if (!in_array($metric, EmailOutboxMonitor::METRIC_NAMES, true)) {
                throw new InvalidArgumentException(sprintf('Unsupported email outbox metric: %s.', $metric));
            }

            if (!$levelsObject instanceof \stdClass) {
                throw new InvalidArgumentException(sprintf(
                    'Thresholds for %s must define warning and critical values.',
                    $metric,
                ));
            }

            $levels = get_object_vars($levelsObject);
            if (2 !== count($levels) || !array_key_exists('warning', $levels) || !array_key_exists('critical', $levels)) {
                throw new InvalidArgumentException(sprintf(
                    'Thresholds for %s must define warning and critical values.',
                    $metric,
                ));
            }

            $warning = $levels['warning'];
            $critical = $levels['critical'];
            if (!is_int($warning) || !is_int($critical) || $warning < 0 || $critical < 0 || $warning > $critical) {
                throw new InvalidArgumentException(sprintf(
                    'Thresholds for %s must be non-negative integers with warning <= critical.',
                    $metric,
                ));
            }

            $thresholds[$metric] = ['warning' => $warning, 'critical' => $critical];
        }

        $this->thresholds = $thresholds;
    }

    /**
     * @param array<string, int> $metrics
     *
     * @return array{status: 'unconfigured'|'ok'|'warning'|'critical', alerts: list<array{metric: string, level: 'warning'|'critical', value: int, threshold: int}>}
     */
    public function evaluate(array $metrics): array
    {
        if ([] === $this->thresholds) {
            return ['status' => 'unconfigured', 'alerts' => []];
        }

        $alerts = [];
        $overallStatus = 'ok';
        foreach ($this->thresholds as $metric => $levels) {
            if (!array_key_exists($metric, $metrics)) {
                throw new InvalidArgumentException(sprintf('Email outbox metric %s is unavailable.', $metric));
            }

            $value = $metrics[$metric];
            if ($value >= $levels['critical']) {
                $alerts[] = [
                    'metric' => $metric,
                    'level' => 'critical',
                    'value' => $value,
                    'threshold' => $levels['critical'],
                ];
                $overallStatus = 'critical';
            } elseif ($value >= $levels['warning']) {
                $alerts[] = [
                    'metric' => $metric,
                    'level' => 'warning',
                    'value' => $value,
                    'threshold' => $levels['warning'],
                ];
                if ('critical' !== $overallStatus) {
                    $overallStatus = 'warning';
                }
            }
        }

        return ['status' => $overallStatus, 'alerts' => $alerts];
    }
}
