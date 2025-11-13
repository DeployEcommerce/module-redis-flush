<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Service;

use Credis_Client;
use Credis_Client_Exception;
use DeployEcommerce\RedisFlush\Model\Data\FlushResult;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;
use Redis;
use RedisException;

/**
 * Service for flushing Redis cache
 *
 * Handles Redis flush operations with statistics capture,
 * event emission, and comprehensive error handling.
 */
class RedisCacheFlushService
{
    /**
     * @param RedisConnectionService $redisConnectionService
     * @param RedisStatisticsService $redisStatisticsService
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RedisConnectionService $redisConnectionService,
        private readonly RedisStatisticsService $redisStatisticsService,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Flush all Redis data
     *
     * Executes FLUSHALL command on Redis, capturing statistics before and after.
     * Emits events before and after flush for observers/plugins.
     *
     * @return FlushResult
     */
    public function flushAll(): FlushResult
    {
        $startTime = microtime(true);
        $executedAt = new \DateTimeImmutable();

        // Gather statistics before flush
        $statsBefore = $this->redisStatisticsService->getStatistics();
        $memoryBeforeMb = $statsBefore?->usedMemoryMb ?? 0.0;
        $keysBeforeFlush = $statsBefore?->getTotalKeys() ?? 0;

        // Get all Redis connections
        $connections = $this->redisConnectionService->getRedisConnections();

        if (empty($connections)) {
            return $this->createFailureResult(
                memoryBeforeMb: $memoryBeforeMb,
                executionTimeMs: (int)((microtime(true) - $startTime) * 1000),
                executedAt: $executedAt,
                errorMessage: 'No Redis connections available'
            );
        }

        // Emit before flush event
        $this->eventManager->dispatch('deploy_redis_flush_before', [
            'statistics' => $statsBefore,
            'connections' => $connections,
        ]);

        try {
            // Execute flush on all connections (though typically only one FLUSHALL is needed)
            $flushedDatabases = $this->executeFlushAll($connections);

            // Gather statistics after flush
            $statsAfter = $this->redisStatisticsService->getStatistics();
            $memoryAfterMb = $statsAfter?->usedMemoryMb ?? 0.0;

            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            $result = new FlushResult(
                success: true,
                memoryBeforeMb: $memoryBeforeMb,
                memoryAfterMb: $memoryAfterMb,
                executionTimeMs: $executionTimeMs,
                keysDeleted: $keysBeforeFlush,
                databasesFlushed: $flushedDatabases,
                executedAt: $executedAt
            );

            // Emit after flush event
            $this->eventManager->dispatch('deploy_redis_flush_after', [
                'result' => $result,
                'statistics_before' => $statsBefore,
                'statistics_after' => $statsAfter,
            ]);

            $this->logger->info(
                'Redis cache flushed successfully',
                ['result' => $result->toArray()]
            );

            return $result;
        } catch (RedisException | Credis_Client_Exception $e) {
            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            $this->logger->error(
                'Redis flush failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->createFailureResult(
                memoryBeforeMb: $memoryBeforeMb,
                executionTimeMs: $executionTimeMs,
                executedAt: $executedAt,
                errorMessage: sprintf('Redis error: %s', $e->getMessage())
            );
        } catch (\Exception $e) {
            $executionTimeMs = (int)((microtime(true) - $startTime) * 1000);

            $this->logger->error(
                'Unexpected error during Redis flush: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->createFailureResult(
                memoryBeforeMb: $memoryBeforeMb,
                executionTimeMs: $executionTimeMs,
                executedAt: $executedAt,
                errorMessage: sprintf('Unexpected error: %s', $e->getMessage())
            );
        }
    }

    /**
     * Execute FLUSHALL on all Redis connections
     *
     * @param array<Redis|Credis_Client> $connections
     * @return string[] Array of database identifiers that were flushed
     * @throws RedisException|Credis_Client_Exception
     */
    private function executeFlushAll(array $connections): array
    {
        $flushedDatabases = [];
        $flushed = false;

        foreach ($connections as $cacheId => $client) {
            if ($client instanceof Redis) {
                $client->flushAll();
                $flushed = true;
                $flushedDatabases[] = (string)$cacheId;
                break; // FLUSHALL affects all databases, so one call is enough
            }

            if ($client instanceof Credis_Client) {
                $client->flushAll();
                $flushed = true;
                $flushedDatabases[] = (string)$cacheId;
                break; // FLUSHALL affects all databases, so one call is enough
            }
        }

        if (!$flushed) {
            throw new \RuntimeException('Failed to execute FLUSHALL on any Redis connection');
        }

        return $flushedDatabases;
    }

    /**
     * Create a failure result
     *
     * @param float $memoryBeforeMb
     * @param int $executionTimeMs
     * @param \DateTimeImmutable $executedAt
     * @param string $errorMessage
     * @return FlushResult
     */
    private function createFailureResult(
        float $memoryBeforeMb,
        int $executionTimeMs,
        \DateTimeImmutable $executedAt,
        string $errorMessage
    ): FlushResult {
        return new FlushResult(
            success: false,
            memoryBeforeMb: $memoryBeforeMb,
            memoryAfterMb: $memoryBeforeMb, // No change if failed
            executionTimeMs: $executionTimeMs,
            keysDeleted: 0,
            databasesFlushed: [],
            executedAt: $executedAt,
            errorMessage: $errorMessage
        );
    }
}
