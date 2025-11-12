<?php
/**
 * Copyright © Deploy Ecommerce All rights reserved.
 *
 */
declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Block\Adminhtml\Cache;

use Magento\Backend\Block\Widget\Button;

class FlushButton extends Button
{
    /**
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();

        $this->setData([
            'id' => 'flush_redis',
            'label' => __('Flush Redis'),
            'class' => 'primary',
            'onclick' => "setLocation('" . $this->getUrl('redisflush/cache/flushredis') . "')"
        ]);
    }
}
