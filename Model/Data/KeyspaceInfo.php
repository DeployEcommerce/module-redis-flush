<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Model\Data;

/**
 * Value object representing Redis keyspace information for a single database
 *
 * Immutable data structure containing statistics about keys in a Redis database.
 */
class KeyspaceInfo
{
    /**
     * @param int $database Database number (0-15 typically)
     * @param int $keys Total number of keys
     * @param int $expires Number of keys with expiry set
     * @param int $avgTtl Average time-to-live in seconds (0 if no TTL info)
     */
    public function __construct(
        public readonly int $database,
        public readonly int $keys,
        public readonly int $expires,
        public readonly int $avgTtl
    ) {
    }

    /**
     * Get percentage of keys with expiry
     *
     * @return float Percentage (0-100)
     */
    public function getExpiryPercentage(): float
    {
        if ($this->keys === 0) {
            return 0.0;
        }

        return ($this->expires / $this->keys) * 100;
    }

    /**
     * Get average TTL as human-readable string
     *
     * @return string
     */
    public function getAvgTtlFormatted(): string
    {
        if ($this->avgTtl === 0) {
            return 'N/A';
        }

        if ($this->avgTtl < 60) {
            return sprintf('%ds', $this->avgTtl);
        }

        if ($this->avgTtl < 3600) {
            return sprintf('%dm', (int)floor($this->avgTtl / 60));
        }

        if ($this->avgTtl < 86400) {
            return sprintf('%dh', (int)floor($this->avgTtl / 3600));
        }

        return sprintf('%dd', (int)floor($this->avgTtl / 86400));
    }

    /**
     * Convert to array representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'keys' => $this->keys,
            'expires' => $this->expires,
            'expiry_percentage' => $this->getExpiryPercentage(),
            'avg_ttl' => $this->avgTtl,
            'avg_ttl_formatted' => $this->getAvgTtlFormatted(),
        ];
    }
}
