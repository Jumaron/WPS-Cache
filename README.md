# WPS-Cache

[![WordPress Compatible](https://img.shields.io/badge/WordPress-Compatible-0073aa.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2%20or%20later-blue.svg)](http://www.gnu.org/licenses/gpl-2.0.html)
[![Experimental](https://img.shields.io/badge/Status-Experimental-orange.svg)]()

**Boost your WordPress speed with multi-layer caching** using HTML, Redis, and Varnish for lightning-fast performance.

> ⚠️ **Experimental Status:** This plugin is under active development. Please test thoroughly in a staging environment before production use.

## ✨ Features

- 🔄 **HTML Cache** - Lightning-fast static page delivery
- 📦 **Redis Cache** - Turbocharged database query performance
- 🚄 **Varnish Cache** - HTTP acceleration that reduces server load
- 📊 **Real-time Analytics** - Monitor cache performance metrics
- 🎨 **CSS Optimization** - Automatic minification
- 🔧 **Easy Management** - Intuitive WordPress admin integration
- 💾 **Import/Export** - Simple configuration backup and migration

## External Services

This plugin connects to external services to enhance caching performance:

- **Varnish Cache:**  
  The plugin sends HTTP requests (including purge requests and connection checks) to a configured Varnish caching server. No personally identifiable data is transmitted during these communications. For more details, please review the [Varnish Cache documentation](https://varnish-cache.org/), its [Terms of Service](https://varnish-cache.org/TOS), and [Privacy Policy](https://varnish-cache.org/privacy).

_Note: If you configure your setup to use a remote Varnish server, ensure that you trust the server and have reviewed its policies._

## 🚀 Quick Start

1. Upload `WPS-Cache` to `/wp-content/plugins/` or directly via the WordPress Admin Interface (Add Plugin → Upload Plugin)
2. Activate via the WordPress Plugins menu
3. Configure in the "WPS Cache" settings

## 💡 Usage

### Cache Management

- Access "WPS Cache" in the admin panel
- Toggle individual cache types
- Clear specific or all caches
- Import/export settings

## 🔧 Development

### Structure

```
wps-cache/
├── includes/           # Object Cache
├── src/
│   ├── Admin/         # Admin Interface
│   └── Cache/         # Cache Drivers
├── assets/            # Static Assets
└── wps-cache.php      # Main Plugin File
```

### Core Classes

- `WPSCache\Plugin` - Core initialization
- `WPSCache\Cache\CacheManager` - Cache operations
- `WPSCache\Admin\AdminPanelManager` - UI/UX handling
- `WPSCache\Admin\Tools\CacheTools` - Management utilities

## 🤝 Contributing

We love your input! Check out our (Coming Soon) [Contributing Guidelines](CONTRIBUTING.md).

1. Fork it
2. Create your feature branch (`git checkout -b feature/amazingness`)
3. Commit your changes (`git commit -am 'Add: Amazing Feature'`)
4. Push to the branch (`git push origin feature/amazingness`)
5. Open a Pull Request

## 📚 Documentation

Detailed documentation available at (Coming Soon) [docs.wps-cache.com](https://docs.wps-cache.com)

## 🙏 Acknowledgements

Built with love and support from:

- [WordPress](https://wordpress.org/) – The world's favorite CMS
- [Redis](https://redis.io/) – Lightning-fast data store
- [Varnish](https://varnish-cache.org/) – Web acceleration magic

## 📝 License

GPLv2 or later © Jumaron  
For more details, please see the [GNU General Public License](http://www.gnu.org/licenses/gpl-2.0.html).

---

<p align="center">Made with ❤️ for the WordPress community</p>
