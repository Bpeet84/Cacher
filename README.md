# Cacher - Advanced WordPress Cache System

Cacher is a powerful and flexible caching solution for WordPress that supports full page caching, object caching, and database query caching with multiple storage backends.

## Description

Cacher provides a comprehensive caching system for WordPress sites with support for:

- Full page caching
- Database query caching
- Multiple cache storage backends (File, APCu, Redis)
- Automatic cache invalidation
- Cache preloading
- Detailed cache statistics

### Features

- **Multiple Storage Backends:**
  - File-based caching
  - APCu in-memory caching
  - Redis caching
  - Automatic driver selection

- **Smart Caching:**
  - Intelligent cache key generation
  - Automatic cache invalidation
  - Cache preloading system
  - Compression support
  - Hybrid caching strategies

- **Advanced Controls:**
  - Exclude URLs from caching
  - Exclude logged-in users
  - Exclude specific database queries
  - Custom TTL settings per cache type
  - Detailed cache statistics

- **Developer Friendly:**
  - Clean, well-documented code
  - WordPress coding standards compliant
  - Extensible architecture
  - Comprehensive error handling
  - Debug logging support

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Write permissions for cache directory
- Optional: APCu extension for memory caching
- Optional: Redis server for Redis caching

## Installation

1. Upload the `cacher` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin settings under 'Settings > Cacher'

## Configuration

### Basic Settings

1. Enable/disable caching
2. Choose cache storage backend (File/APCu/Redis)
3. Set cache TTL (Time To Live)
4. Configure exclusion rules

### Advanced Settings

1. Redis connection settings (if using Redis)
2. Cache preload settings
3. Compression settings
4. Debug logging options

## Frequently Asked Questions

### How do I clear the cache?

You can clear the cache through the admin interface under Settings > Cacher, or by using the provided functions programmatically.

### Which cache backend should I choose?

- **File**: Good for most sites, no special requirements
- **APCu**: Better performance, requires APCu PHP extension
- **Redis**: Best performance, requires Redis server

### Can I exclude certain pages from caching?

Yes, you can exclude pages using:
- URL patterns
- WordPress hooks
- User roles
- Custom conditions

## Support

For support and bug reports, please use the [GitHub issues page](https://github.com/Bpeet84/Cacher/issues).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Peter Bakonyi](https://hostfix.hu/plugins/cacher)

## Changelog

### 1.0.0
- Initial release
- Full page caching
- Object caching
- Database query caching
- Multiple storage backends
- Cache preloading
- Statistics and monitoring 