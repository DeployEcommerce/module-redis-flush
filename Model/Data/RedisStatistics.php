<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Model\Data;

/**
 * Value object representing Redis statistics
 *
 * Immutable data structure containing Redis server statistics
 * including memory usage, keyspace information, and connection details.
 */
class RedisStatistics
{
    /**
     * @param float $usedMemoryMb Memory used by Redis in megabytes
     * @param float $usedMemoryPeakMb Peak memory usage in megabytes
     * @param float|null $maxMemoryMb Maximum memory limit in MB (null if unlimited)
     * @param int $connectedClients Number of connected clients
     * @param int $uptimeInSeconds Server uptime in seconds
     * @param array<int, KeyspaceInfo> $keyspaceInfo Keyspace information per database
     * @param string $redisVersion Redis server version
     * @param bool $isConnected Connection status
     * @param \DateTimeImmutable $collectedAt Timestamp when stats were collected
     */
    public function __construct(
        public readonly float $usedMemoryMb,
        public readonly float $usedMemoryPeakMb,
        public readonly ?float $maxMemoryMb,
        public readonly int $connectedClients,
        public readonly int $uptimeInSeconds,
        public readonly array $keyspaceInfo,
        public readonly string $redisVersion,
        public readonly bool $isConnected,
        public readonly \DateTimeImmutable $collectedAt
    ) {
    }

    /**
     * Get memory usage as percentage of max memory
     *
     * @return float|null Percentage (0-100) or null if no max memory set
     */
    public function getMemoryUsagePercentage(): ?float
    {
        if ($this->maxMemoryMb === null || $this->maxMemoryMb === 0.0) {
            return null;
        }

        return ($this->usedMemoryMb / $this->maxMemoryMb) * 100;
    }

    /**
     * Get total keys across all databases
     *
     * @return int
     */
    public function getTotalKeys(): int
    {
        return array_reduce(
            $this->keyspaceInfo,
            fn(int $carry, KeyspaceInfo $info): int => $carry + $info->keys,
            0
        );
    }

    /**
     * Get total keys with expiry across all databases
     *
     * @return int
     */
    public function getTotalExpires(): int
    {
        return array_reduce(
            $this->keyspaceInfo,
            fn(int $carry, KeyspaceInfo $info): int => $carry + $info->expires,
            0
        );
    }

    /**
     * Get uptime as human-readable string
     *
     * @return string
     */
    public function getUptimeFormatted(): string
    {
        $days = floor($this->uptimeInSeconds / 86400);
        $hours = floor(($this->uptimeInSeconds % 86400) / 3600);
        $minutes = floor(($this->uptimeInSeconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = sprintf('%d day%s', $days, $days !== 1 ? 's' : '');
        }
        if ($hours > 0) {
            $parts[] = sprintf('%d hour%s', $hours, $hours !== 1 ? 's' : '');
        }
        if ($minutes > 0 || empty($parts)) {
            $parts[] = sprintf('%d minute%s', $minutes, $minutes !== 1 ? 's' : '');
        }

        return implode(', ', $parts);
    }

    /**
     * Convert to array representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'used_memory_mb' => $this->usedMemoryMb,
            'used_memory_peak_mb' => $this->usedMemoryPeakMb,
            'max_memory_mb' => $this->maxMemoryMb,
            'memory_usage_percentage' => $this->getMemoryUsagePercentage(),
            'connected_clients' => $this->connectedClients,
            'uptime_seconds' => $this->uptimeInSeconds,
            'uptime_formatted' => $this->getUptimeFormatted(),
            'keyspace_info' => array_map(
                fn(KeyspaceInfo $info): array => $info->toArray(),
                $this->keyspaceInfo
            ),
            'total_keys' => $this->getTotalKeys(),
            'total_expires' => $this->getTotalExpires(),
            'redis_version' => $this->redisVersion,
            'is_connected' => $this->isConnected,
            'collected_at' => $this->collectedAt->format('Y-m-d H:i:s'),
        ];
    }
}
