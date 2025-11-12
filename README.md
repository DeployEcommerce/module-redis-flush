# DeployEcommerce RedisFlush Extension

A Magento 2 extension that adds a "Flush Redis" button to the Cache Management page in the admin panel, allowing
administrators to execute a Redis `flushall` command with a single click.

## Description

This extension provides a convenient way to flush all Redis cache data directly from the Magento admin interface. It
adds a button to the Cache Management page that triggers a complete Redis flush operation.

## Requirements

- Magento 2.x
- Redis configured as cache backend
- Cm_Cache_Backend_Redis library

## Installation

```bash
composer require deployecommerce/module-redis-flush
php bin/magento module:enable DeployEcommerce_RedisFlush
php bin/magento setup:upgrade
php bin/magento cache:flush
```

## Usage

1. Log in to the Magento Admin Panel

2. Navigate to:
   ```
   System > Tools > Cache Management
   ```

3. You will see a "Flush Redis" button in the page actions toolbar

4. Click the "Flush Redis" button to execute a complete Redis flush

5. A success or error message will be displayed after the operation completes

## Permissions

The extension uses Magento's built-in cache management permissions. Only users with the following permission can flush
Redis:

- `Magento_Backend::cache` (Cache Management)

To grant access, go to:

```
System > Permissions > User Roles
```

## Warning

**Use with caution!** The `flushall` command will clear ALL data from Redis, including:

- Magento cache
- Session data (if stored in Redis)
- Full page cache
- Any other data stored in the Redis instance

This may cause temporary performance degradation as caches are rebuilt and could log out active users if sessions are
stored in Redis.

## Support

No official support is provided for this extension. Bug reports and feature requests are welcome
via [GitHub Issues](https://github.com/DeployEcommerce/module-redis-flush/issues).

## License

This project is licensed under the MIT License - see the [LICENSE.md](LICENSE.md) file for details.
