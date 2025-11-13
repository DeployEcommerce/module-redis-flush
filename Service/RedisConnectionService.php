<?php
/**
 * Copyright © Deploy Ecommerce. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace DeployEcommerce\RedisFlush\Service;

use Cm_Cache_Backend_Redis;
use Credis_Client;
use Magento\Framework\App\Cache\Frontend\Pool as CacheFrontendPool;
use Magento\Framework\Cache\Backend\Redis as MagentoRedis;
use Magento\Framework\Cache\Backend\RemoteSynchronizedCache;
use Magento\Framework\Cache\FrontendInterface;
use Psr\Log\LoggerInterface;
use Redis;
use ReflectionClass;
use ReflectionException;

/**
 * Service for managing Redis connections
 *
 * Handles connection pool management and extraction of Redis clients
 * from various Magento cache backend implementations.
 */
class RedisConnectionService
{
    /**
     * Cache for extracted Redis clients
     *
     * @var array<string, Redis|Credis_Client|null>
     */
    private array $redisClients = [];

    /**
     * @param CacheFrontendPool $cacheFrontendPool
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheFrontendPool $cacheFrontendPool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get all Redis client connections from configured cache backends
     *
     * @return array<Redis|Credis_Client>
     */
    public function getRedisConnections(): array
    {
        $connections = [];

        /** @var FrontendInterface $cacheFrontend */
        foreach ($this->cacheFrontendPool as $cacheId => $cacheFrontend) {
            $client = $this->extractRedisClient($cacheFrontend, (string)$cacheId);

            if ($client !== null) {
                $connections[$cacheId] = $client;
            }
        }

        return $connections;
    }

    /**
     * Get a single Redis connection (typically the first available)
     *
     * @return Redis|Credis_Client|null
     */
    public function getPrimaryRedisConnection(): Redis|Credis_Client|null
    {
        $connections = $this->getRedisConnections();

        return !empty($connections) ? reset($connections) : null;
    }

    /**
     * Check if Redis is configured and available
     *
     * @return bool
     */
    public function isRedisAvailable(): bool
    {
        return !empty($this->getRedisConnections());
    }

    /**
     * Extract Redis client from cache frontend
     *
     * @param FrontendInterface $cacheFrontend
     * @param string $cacheId
     * @return Redis|Credis_Client|null
     */
    private function extractRedisClient(FrontendInterface $cacheFrontend, string $cacheId): Redis|Credis_Client|null
    {
        // Check cache first
        if (isset($this->redisClients[$cacheId])) {
            return $this->redisClients[$cacheId];
        }

        try {
            $backend = $cacheFrontend->getBackend();
            $actualBackend = $this->resolveActualBackend($backend);

            if ($actualBackend === null) {
                $this->redisClients[$cacheId] = null;
                return null;
            }

            $redisClient = $this->extractRedisClientFromBackend($actualBackend);
            $this->redisClients[$cacheId] = $redisClient;

            return $redisClient;
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Failed to extract Redis client for cache "%s": %s',
                    $cacheId,
                    $e->getMessage()
                )
            );
            $this->redisClients[$cacheId] = null;
            return null;
        }
    }

    /**
     * Resolve the actual Redis backend from wrapped backends
     *
     * Handles RemoteSynchronizedCache which wraps the actual Redis backend
     *
     * @param object $backend
     * @return object|null
     */
    private function resolveActualBackend(object $backend): ?object
    {
        // Handle RemoteSynchronizedCache - extract remote backend
        if ($backend instanceof RemoteSynchronizedCache) {
            try {
                $reflection = new ReflectionClass($backend);
                // The property is named 'remote', not 'remoteBackend'
                if ($reflection->hasProperty('remote')) {
                    $remoteBackendProperty = $reflection->getProperty('remote');
                    $remoteBackendProperty->setAccessible(true);
                    $backend = $remoteBackendProperty->getValue($backend);
                }
            } catch (ReflectionException $e) {
                $this->logger->warning(
                    'Failed to extract remote backend from RemoteSynchronizedCache: ' . $e->getMessage()
                );
                return null;
            }
        }

        // Check if backend is a recognized Redis backend
        if ($this->isRedisBackend($backend)) {
            return $backend;
        }

        return null;
    }

    /**
     * Check if backend is a Redis backend
     *
     * @param object $backend
     * @return bool
     */
    private function isRedisBackend(object $backend): bool
    {
        return $backend instanceof Cm_Cache_Backend_Redis
            || $backend instanceof MagentoRedis
            || stripos(get_class($backend), 'redis') !== false;
    }

    /**
     * Extract Redis client from backend using reflection
     *
     * @param object $backend
     * @return Redis|Credis_Client|null
     */
    private function extractRedisClientFromBackend(object $backend): Redis|Credis_Client|null
    {
        try {
            $reflection = new ReflectionClass($backend);

            // Try common property names for Redis client
            foreach (['_redis', 'redis', '_client', 'client'] as $propertyName) {
                if ($reflection->hasProperty($propertyName)) {
                    $property = $reflection->getProperty($propertyName);
                    $property->setAccessible(true);
                    $client = $property->getValue($backend);

                    if ($client instanceof Redis || $client instanceof Credis_Client) {
                        return $client;
                    }
                }
            }

            $this->logger->warning(
                sprintf(
                    'Redis backend "%s" does not have a recognized Redis client property',
                    get_class($backend)
                )
            );
        } catch (ReflectionException $e) {
            $this->logger->error(
                sprintf(
                    'Reflection error while extracting Redis client from "%s": %s',
                    get_class($backend),
                    $e->getMessage()
                )
            );
        }

        return null;
    }

    /**
     * Clear the Redis client cache
     *
     * Forces re-extraction of Redis clients on next request
     *
     * @return void
     */
    public function clearClientCache(): void
    {
        $this->redisClients = [];
    }
}
