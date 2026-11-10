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
use Magento\Framework\Cache\Backend\SymfonyL2Cache;
use Magento\Framework\Cache\Frontend\Adapter\Symfony\BackendWrapper;
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
     * Handles the Zend-based backends used up to Magento 2.4.8 (RemoteSynchronizedCache wrapping
     * a Cm/Magento Redis backend) and the Symfony-based cache introduced in 2.4.9, where
     * getBackend() returns a BackendWrapper around a tag adapter that holds the Redis client,
     * optionally behind a SymfonyL2Cache whose remote side is itself a cache frontend.
     *
     * @param object $backend
     * @return object|null
     */
    private function resolveActualBackend(object $backend): ?object
    {
        // RemoteSynchronizedCache (<= 2.4.8) and SymfonyL2Cache (2.4.9+) both keep the
        // shared cache in a 'remote' property; on 2.4.9+ it is a frontend, not a backend
        if ($backend instanceof RemoteSynchronizedCache || $backend instanceof SymfonyL2Cache) {
            $remote = $this->getPropertyValue($backend, 'remote');
            if ($remote instanceof FrontendInterface) {
                $remote = $remote->getBackend();
            }

            return is_object($remote) ? $this->resolveActualBackend($remote) : null;
        }

        // Symfony cache (2.4.9+): the tag adapter (e.g. RedisTagAdapter) holds the Redis client
        if ($backend instanceof BackendWrapper) {
            $adapter = $this->getPropertyValue($backend, 'adapter');

            return is_object($adapter) && $this->isRedisBackend($adapter) ? $adapter : null;
        }

        // Check if backend is a recognized Redis backend
        if ($this->isRedisBackend($backend)) {
            return $backend;
        }

        return null;
    }

    /**
     * Read a (possibly private) property from a wrapper object
     *
     * @param object $object
     * @param string $propertyName
     * @return mixed
     */
    private function getPropertyValue(object $object, string $propertyName): mixed
    {
        try {
            $reflection = new ReflectionClass($object);
            if ($reflection->hasProperty($propertyName)) {
                return $reflection->getProperty($propertyName)->getValue($object);
            }
        } catch (ReflectionException $e) {
            $this->logger->warning(
                sprintf(
                    'Failed to read property "%s" from "%s": %s',
                    $propertyName,
                    get_class($object),
                    $e->getMessage()
                )
            );
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
