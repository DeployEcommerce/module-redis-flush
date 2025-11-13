<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Block\Adminhtml\Cache;

use DeployEcommerce\RedisFlush\Service\RedisStatisticsService;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Redis statistics display block for admin Cache Management page
 *
 * Displays real-time Redis memory usage, keyspace info, and connection status.
 */
class RedisStatistics extends Template
{
    /**
     * Template file
     *
     * @var string
     */
    protected $_template = 'DeployEcommerce_RedisFlush::cache/redis_statistics.phtml';

    /**
     * @param Context $context
     * @param RedisStatisticsService $redisStatisticsService
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly RedisStatisticsService $redisStatisticsService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Get Redis statistics
     *
     * @return \DeployEcommerce\RedisFlush\Model\Data\RedisStatistics|null
     */
    public function getStatistics(): ?\DeployEcommerce\RedisFlush\Model\Data\RedisStatistics
    {
        return $this->redisStatisticsService->getStatistics();
    }

    /**
     * Check if Redis is available
     *
     * @return bool
     */
    public function isRedisAvailable(): bool
    {
        return $this->getStatistics() !== null;
    }

    /**
     * Format number with thousand separators
     *
     * @param int $number
     * @return string
     */
    public function formatNumber(int $number): string
    {
        return number_format($number);
    }

    /**
     * Format bytes to human-readable size
     *
     * @param float $mb
     * @return string
     */
    public function formatMemory(float $mb): string
    {
        if ($mb >= 1024) {
            return sprintf('%.2f GB', $mb / 1024);
        }
        return sprintf('%.2f MB', $mb);
    }

    /**
     * Get memory usage bar width percentage
     *
     * @param float|null $percentage
     * @return float
     */
    public function getMemoryBarWidth(?float $percentage): float
    {
        if ($percentage === null) {
            return 0.0;
        }
        return min(100.0, max(0.0, $percentage));
    }

    /**
     * Get memory usage bar color class based on usage percentage
     *
     * @param float|null $percentage
     * @return string
     */
    public function getMemoryBarColorClass(?float $percentage): string
    {
        if ($percentage === null) {
            return 'memory-bar-unknown';
        }

        if ($percentage >= 90) {
            return 'memory-bar-critical';
        }

        if ($percentage >= 75) {
            return 'memory-bar-warning';
        }

        return 'memory-bar-normal';
    }

    /**
     * Get refresh URL for statistics
     *
     * @return string
     */
    public function getRefreshUrl(): string
    {
        return $this->getUrl('adminhtml/cache/index');
    }
}
