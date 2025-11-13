<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Block\Adminhtml\Cache;

use DeployEcommerce\RedisFlush\Service\RedisStatisticsService;
use Magento\Backend\Block\Widget\Button;
use Magento\Backend\Block\Widget\Context;

/**
 * Enhanced Redis flush button with statistics-aware confirmation
 *
 * Displays flush button with detailed confirmation dialog showing
 * current Redis statistics and warnings about impact.
 */
class FlushButton extends Button
{
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
     * Configure flush button
     *
     * @return void
     */
    protected function _construct(): void
    {
        parent::_construct();

        $statistics = $this->redisStatisticsService->getStatistics();

        if ($statistics === null) {
            // Redis not available - show disabled button
            $this->setData([
                'id' => 'flush_redis_cache',
                'label' => __('Flush Redis Cache'),
                'class' => 'action-secondary disabled',
                'title' => __('Redis is not configured or not available'),
                'disabled' => true,
            ]);
            return;
        }

        // Build confirmation message with current statistics
        $confirmMessage = $this->buildConfirmationMessage($statistics);

        // Add form key to URL for CSRF protection
        $url = $this->getUrl('rediscache/cache/flushredis', [
            'form_key' => $this->getFormKey()
        ]);

        $this->setData([
            'id' => 'flush_redis_cache',
            'label' => __('Flush Redis Cache'),
            'class' => 'action-secondary',
            'onclick' => sprintf("return confirm('%s') && (window.location.href='%s');", $confirmMessage, $url),
        ]);
    }

    /**
     * Build detailed confirmation message with statistics
     *
     * @param \DeployEcommerce\RedisFlush\Model\Data\RedisStatistics $statistics
     * @return string
     */
    private function buildConfirmationMessage(\DeployEcommerce\RedisFlush\Model\Data\RedisStatistics $statistics): string
    {
        $memoryInfo = sprintf('%.2f MB', $statistics->usedMemoryMb);
        if ($statistics->maxMemoryMb !== null) {
            $percentage = $statistics->getMemoryUsagePercentage();
            $memoryInfo .= sprintf(' (%.1f%%)', $percentage);
        }

        $keysInfo = number_format($statistics->getTotalKeys());

        $message = __('This action will cause temporary performance degradation and remove '.$keysInfo.' keys and '.$memoryInfo.' of data. Are you sure you want to continue?')->render();

        return addslashes($message);
    }
}
