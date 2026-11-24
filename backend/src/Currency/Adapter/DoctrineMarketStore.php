<?php

declare(strict_types=1);

namespace App\Currency\Adapter;

use App\Currency\Application\Api\MarketUnavailable;
use App\Currency\Application\MarketSource;
use App\Currency\Application\MarketStore;
use App\Currency\Domain\FeedPolicy;
use App\Currency\Domain\MarketFeed;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class DoctrineMarketStore implements MarketStore
{
    public function __construct(private Connection $db, private MarketSource $source)
    {
    }

    public static function independentConnection(Connection $application): Connection
    {
        // A slow upstream or a rolled-back payment must not hold or undo the cache lock.
        return DriverManager::getConnection($application->getParams(), $application->getConfiguration());
    }

    public function currencies(): array
    {
        /* @var array<string,int> */
        return $this->get(MarketFeed::Catalog);
    }

    public function rates(): array
    {
        /* @var array<string,array{value:string,date:string}> */
        return $this->get(MarketFeed::Rates);
    }

    /** @return array<string,mixed> */
    private function get(MarketFeed $feed): array
    {
        [$data, $policy] = $this->read($feed);
        if ($policy->expiresAt > time()) {
            return $data;
        }
        if ($policy->retryAt > time()) {
            return $this->fallback($feed, $data) ?? throw new MarketUnavailable();
        }

        $result = $this->db->transactional(function () use ($feed): ?array {
            $this->db->executeStatement("INSERT INTO currency_market (id, currencies, rates, refreshed_at) VALUES (1, '{}', '{}', CURRENT_TIMESTAMP) ON CONFLICT DO NOTHING");
            [$data, $policy] = $this->read($feed, true);
            if ($policy->expiresAt > time()) {
                return $data;
            }
            if ($policy->retryAt <= time()) {
                try {
                    $update = $this->source->fetch($feed, $policy);
                    $current = $update->data ?? $data;
                    $this->save($feed, $update->storeable ? $update->policy : new FeedPolicy(), $update->storeable ? $update->data : []);

                    return $current;
                } catch (MarketUnavailable) {
                    $this->save($feed, new FeedPolicy(0, $policy->lifetime, $policy->etag, $policy->lastModified, time() + 30));
                }
            }

            return $this->fallback($feed, $data);
        });

        return $result ?? throw new MarketUnavailable();
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function fallback(MarketFeed $feed, array $data): ?array
    {
        return MarketFeed::Catalog === $feed && [] !== $data ? $data : null;
    }

    /** @return array{array<string,mixed>,FeedPolicy} */
    private function read(MarketFeed $feed, bool $lock = false): array
    {
        $row = $this->db->fetchAssociative("SELECT {$feed->value} AS data, cache_policy -> ?::text AS policy FROM currency_market WHERE id = 1".($lock ? ' FOR UPDATE' : ''), [$feed->value]);
        if (false === $row) {
            return [[], new FeedPolicy()];
        }
        $data = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
        $policy = json_decode($row['policy'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);

        return [$data, new FeedPolicy(...$policy)];
    }

    /** @param array<string,mixed>|null $data */
    private function save(MarketFeed $feed, FeedPolicy $policy, ?array $data = null): void
    {
        $this->db->executeStatement("UPDATE currency_market SET {$feed->value} = COALESCE(?::jsonb, {$feed->value}), cache_policy = cache_policy || jsonb_build_object(?::text, ?::jsonb), refreshed_at = CASE WHEN ? = 0 THEN clock_timestamp() ELSE refreshed_at END WHERE id = 1", [
            null === $data ? null : json_encode((object) $data, JSON_THROW_ON_ERROR),
            $feed->value,
            json_encode($policy, JSON_THROW_ON_ERROR),
            $policy->retryAt,
        ]);
    }
}
