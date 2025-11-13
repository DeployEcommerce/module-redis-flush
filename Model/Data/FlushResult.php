<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Model\Data;

/**
 * Value object representing the result of a Redis flush operation
 *
 * Immutable data structure containing the results and statistics
 * of a Redis cache flush operation.
 */
class FlushResult
{
    /**
     * @param bool $success Whether the flush operation succeeded
     * @param float $memoryBeforeMb Memory usage before flush in MB
     * @param float $memoryAfterMb Memory usage after flush in MB
     * @param int $executionTimeMs Execution time in milliseconds
     * @param int $keysDeleted Estimated number of keys deleted
     * @param string[] $databasesFlushed List of database numbers flushed
     * @param \DateTimeImmutable $executedAt Timestamp of execution
     * @param string|null $errorMessage Error message if operation failed
     */
    public function __construct(
        public readonly bool $success,
        public readonly float $memoryBeforeMb,
        public readonly float $memoryAfterMb,
        public readonly int $executionTimeMs,
        public readonly int $keysDeleted,
        public readonly array $databasesFlushed,
        public readonly \DateTimeImmutable $executedAt,
        public readonly ?string $errorMessage = null
    ) {
    }

    /**
     * Get memory freed in megabytes
     *
     * @return float
     */
    public function getMemoryFreedMb(): float
    {
        return max(0.0, $this->memoryBeforeMb - $this->memoryAfterMb);
    }

    /**
     * Get memory freed as percentage of original memory
     *
     * @return float Percentage (0-100)
     */
    public function getMemoryFreedPercentage(): float
    {
        if ($this->memoryBeforeMb === 0.0) {
            return 0.0;
        }

        return ($this->getMemoryFreedMb() / $this->memoryBeforeMb) * 100;
    }

    /**
     * Get execution time in seconds
     *
     * @return float
     */
    public function getExecutionTimeSeconds(): float
    {
        return round($this->executionTimeMs / 1000, 3);
    }

    /**
     * Convert to array representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'memory_before_mb' => $this->memoryBeforeMb,
            'memory_after_mb' => $this->memoryAfterMb,
            'memory_freed_mb' => $this->getMemoryFreedMb(),
            'memory_freed_percentage' => $this->getMemoryFreedPercentage(),
            'execution_time_ms' => $this->executionTimeMs,
            'execution_time_seconds' => $this->getExecutionTimeSeconds(),
            'keys_deleted' => $this->keysDeleted,
            'databases_flushed' => $this->databasesFlushed,
            'executed_at' => $this->executedAt->format('Y-m-d H:i:s'),
            'error_message' => $this->errorMessage,
        ];
    }

    /**
     * Get a human-readable summary of the flush operation
     *
     * @return string
     */
    public function getSummary(): string
    {
        if (!$this->success) {
            return sprintf(
                'Flush failed: %s',
                $this->errorMessage ?? 'Unknown error'
            );
        }

        return sprintf(
            'Flushed %s keys, freed %.2f MB (%.1f%%) in %.3f seconds',
            number_format($this->keysDeleted),
            $this->getMemoryFreedMb(),
            $this->getMemoryFreedPercentage(),
            $this->getExecutionTimeSeconds()
        );
    }
}
