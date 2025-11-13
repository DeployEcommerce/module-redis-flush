<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Service;

use Credis_Client;
use DeployEcommerce\RedisFlush\Model\Data\KeyspaceInfo;
use DeployEcommerce\RedisFlush\Model\Data\RedisStatistics;
use Psr\Log\LoggerInterface;
use Redis;

/**
 * Service for gathering Redis statistics
 *
 * Collects memory usage, keyspace information, and connection details
 * from Redis server via INFO command.
 */
class RedisStatisticsService
{
    /**
     * @param RedisConnectionService $redisConnectionService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RedisConnectionService $redisConnectionService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get Redis statistics
     *
     * @return RedisStatistics|null Returns null if Redis is not available
     */
    public function getStatistics(): ?RedisStatistics
    {
        $redisClient = $this->redisConnectionService->getPrimaryRedisConnection();

        if ($redisClient === null) {
            $this->logger->warning('Redis connection not available for statistics gathering');
            return null;
        }

        try {
            $info = $this->getRedisInfo($redisClient);

            return new RedisStatistics(
                usedMemoryMb: $this->bytesToMegabytes((int)($info['used_memory'] ?? 0)),
                usedMemoryPeakMb: $this->bytesToMegabytes((int)($info['used_memory_peak'] ?? 0)),
                maxMemoryMb: $this->getMaxMemoryMb($info),
                connectedClients: (int)($info['connected_clients'] ?? 0),
                uptimeInSeconds: (int)($info['uptime_in_seconds'] ?? 0),
                keyspaceInfo: $this->parseKeyspaceInfo($info),
                redisVersion: (string)($info['redis_version'] ?? 'unknown'),
                isConnected: true,
                collectedAt: new \DateTimeImmutable()
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to gather Redis statistics: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return null;
        }
    }

    /**
     * Get Redis INFO command result
     *
     * @param Redis|Credis_Client $client
     * @return array<string, mixed>
     * @throws \Exception
     */
    private function getRedisInfo(Redis|Credis_Client $client): array
    {
        if ($client instanceof Redis) {
            $info = $client->info();
            if (!is_array($info)) {
                throw new \RuntimeException('Redis INFO command returned invalid data');
            }
            return $info;
        }

        if ($client instanceof Credis_Client) {
            $info = $client->info();
            if (!is_array($info)) {
                throw new \RuntimeException('Credis INFO command returned invalid data');
            }
            return $info;
        }

        throw new \InvalidArgumentException('Unsupported Redis client type: ' . get_class($client));
    }

    /**
     * Parse keyspace information from Redis INFO
     *
     * Parses entries like "db0:keys=1234,expires=523,avg_ttl=3600"
     *
     * @param array<string, mixed> $info
     * @return array<int, KeyspaceInfo>
     */
    private function parseKeyspaceInfo(array $info): array
    {
        $keyspaceInfo = [];

        foreach ($info as $key => $value) {
            if (!str_starts_with($key, 'db')) {
                continue;
            }

            // Extract database number from key (e.g., "db0" -> 0)
            $dbNumber = (int)substr($key, 2);

            // Parse value string: "keys=1234,expires=523,avg_ttl=3600"
            $stats = $this->parseKeyspaceStats((string)$value);

            $keyspaceInfo[$dbNumber] = new KeyspaceInfo(
                database: $dbNumber,
                keys: $stats['keys'],
                expires: $stats['expires'],
                avgTtl: $stats['avg_ttl']
            );
        }

        ksort($keyspaceInfo);

        return $keyspaceInfo;
    }

    /**
     * Parse keyspace statistics string
     *
     * @param string $statsString Format: "keys=1234,expires=523,avg_ttl=3600"
     * @return array{keys: int, expires: int, avg_ttl: int}
     */
    private function parseKeyspaceStats(string $statsString): array
    {
        $stats = [
            'keys' => 0,
            'expires' => 0,
            'avg_ttl' => 0,
        ];

        $parts = explode(',', $statsString);

        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $key = trim($key);
                $value = trim($value);

                if (array_key_exists($key, $stats)) {
                    $stats[$key] = (int)$value;
                }
            }
        }

        return $stats;
    }

    /**
     * Get max memory in megabytes
     *
     * @param array<string, mixed> $info
     * @return float|null Returns null if no max memory is set (unlimited)
     */
    private function getMaxMemoryMb(array $info): ?float
    {
        $maxMemory = (int)($info['maxmemory'] ?? 0);

        if ($maxMemory === 0) {
            return null; // Unlimited
        }

        return $this->bytesToMegabytes($maxMemory);
    }

    /**
     * Convert bytes to megabytes
     *
     * @param int $bytes
     * @return float
     */
    private function bytesToMegabytes(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }
}
