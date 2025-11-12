<?php

/**
 * Copyright © Deploy Ecommerce All rights reserved.
 *
 */
declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Controller\Adminhtml\Cache;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\Controller\Result\Redirect;

class FlushRedis extends Action
{
    /**
     * Authorization level of a basic admin session
     */
    const ADMIN_RESOURCE = 'Magento_Backend::cache';

    /**
     * @var Pool
     */
    protected $cacheFrontendPool;

    /**
     * @param Context $context
     * @param Pool $cacheFrontendPool
     */
    public function __construct(
        Context $context,
        Pool $cacheFrontendPool
    ) {
        parent::__construct($context);
        $this->cacheFrontendPool = $cacheFrontendPool;
    }

    /**
     * Flush all Redis data
     *
     * @return Redirect
     */
    public function execute()
    {
        try {
            $flushed = false;

            /** @var \Magento\Framework\Cache\FrontendInterface $cacheFrontend */
            foreach ($this->cacheFrontendPool as $cacheFrontend) {
                $backend = $cacheFrontend->getBackend();

                // Use reflection to access the protected _redis property
                $reflection = new \ReflectionClass($backend);
                $redisProperty = $reflection->getProperty('_redis');
                $redisProperty->setAccessible(true);
                $redis = $redisProperty->getValue($backend);

                if ($redis) {
                    $redis->flushAll();
                    $flushed = true;
                    break;
                }
            }

            if ($flushed) {
                $this->messageManager->addSuccessMessage(
                    __('Redis cache has been flushed successfully.')
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __('Redis backend not found. Please ensure Redis is configured as your cache backend.')
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('An error occurred while flushing Redis: %1', $e->getMessage())
            );
        }

        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('adminhtml/cache/index');
    }
}
