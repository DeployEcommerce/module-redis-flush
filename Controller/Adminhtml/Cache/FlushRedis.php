<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Controller\Adminhtml\Cache;

use DeployEcommerce\RedisFlush\Service\RedisCacheFlushService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Admin controller for flushing Redis cache
 *
 * Handles Redis flush requests from admin with ACL and CSRF protection.
 */
class FlushRedis extends Action
{
    /**
     * Authorization level required for this action
     *
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'DeployEcommerce_RedisFlush::flush_redis';

    /**
     * @param Context $context
     * @param RedisCacheFlushService $redisCacheFlushService
     */
    public function __construct(
        Context $context,
        private readonly RedisCacheFlushService $redisCacheFlushService
    ) {
        parent::__construct($context);
    }

    /**
     * Execute Redis flush action
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('adminhtml/cache/index');

        // Validate form key for CSRF protection
        if (!$this->_formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage(
                __('Invalid form key. Please refresh the page and try again.')
            );
            return $resultRedirect;
        }

        try {
            // Execute flush operation
            $result = $this->redisCacheFlushService->flushAll();

            if ($result->success) {
                // Success message with statistics
                $this->messageManager->addSuccessMessage(
                    __(
                        'Redis cache flushed successfully. %1 keys deleted, %2 MB freed in %3s.',
                        number_format($result->keysDeleted),
                        number_format($result->getMemoryFreedMb(), 2),
                        $result->getExecutionTimeSeconds()
                    )
                );

                // Add detailed info message
                $this->messageManager->addNoticeMessage(
                    __(
                        'Memory before: %1 MB | Memory after: %2 MB | Freed: %3%',
                        number_format($result->memoryBeforeMb, 2),
                        number_format($result->memoryAfterMb, 2),
                        number_format($result->getMemoryFreedPercentage(), 1)
                    )
                );
            } else {
                // Failure message
                $this->messageManager->addErrorMessage(
                    __(
                        'Failed to flush Redis cache: %1',
                        $result->errorMessage ?? 'Unknown error'
                    )
                );
            }
        } catch (\Exception $e) {
            // Catch any unexpected exceptions
            $this->messageManager->addExceptionMessage(
                $e,
                __('An unexpected error occurred while flushing Redis cache: %1', $e->getMessage())
            );
        }

        return $resultRedirect;
    }
}
