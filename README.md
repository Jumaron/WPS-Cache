# WPS-Cache (Experimental)

**WPS-Cache** is a comprehensive caching plugin for WordPress designed to improve the performance and scalability of your website. It supports multiple caching mechanisms including HTML caching, Redis object caching, and Varnish HTTP caching.

**Disclaimer:** This plugin is currently in an **experimental** stage of development. Use with caution and expect potential bugs or breaking changes. It is recommended to test thoroughly on a staging environment before deploying to production.

## Features

- **HTML Cache:** Cache static HTML pages for faster delivery.
- **Redis Cache:** Cache database queries using Redis for improved performance.
- **Varnish Cache:** HTTP cache acceleration using Varnish for reduced server load.
- **CSS Minification:** Minify and combine CSS files to reduce page load times.
- **Cache Management:** Clear and manage caches directly from the WordPress admin panel.
- **Analytics:** Collect and display cache performance metrics to monitor efficiency.
- **Import/Export:** Easily import and export plugin settings for backup or migration.

## Installation

1. Upload the `WPS-Cache` plugin to your WordPress plugins directory (`/wp-content/plugins/`).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin settings in the 'WPS Cache' menu in the WordPress admin panel.

## Usage

### Enabling Caches

1. Navigate to the 'WPS Cache' settings page.
2. Enable the desired cache types (HTML, Redis, Varnish, CSS Minification).
3. Configure the settings for each cache type according to your server environment and needs.

### Clearing Caches

1. Navigate to the 'Tools' tab in the 'WPS Cache' settings page.
2. Click the 'Clear All Caches' button to clear all active caches.

### Import/Export Settings

1. Navigate to the 'Tools' tab in the 'WPS Cache' settings page.
2. Use the 'Import/Export Settings' section to import or export plugin settings as needed.

## Development

### File Structure

- **wps-cache/**
  - **includes/**: The Object Cache.
  - **src/**: Main plugin classes and logic.
    - **Admin/**: Admin panel management and settings.
    - **Cache/**: Cache drivers and cache management.
  - **assets/**: CSS and JavaScript assets.
  - **wps-cache.php**: Main plugin file.

### Key Classes

- **`WPSCache\Plugin`:** Main plugin class, responsible for initialization and overall plugin management.
- **`WPSCache\Cache\CacheManager`:** Manages cache drivers (HTML, Redis, Varnish) and their operations.
- **`WPSCache\Admin\AdminPanelManager`:** Handles the admin panel, settings pages, and user interface.
- **`WPSCache\Admin\Tools\CacheTools`:** Provides cache management tools like clearing and monitoring.

## Contributing

We welcome contributions! To contribute to WPS-Cache:

1. Fork the repository.
2. Create a new branch: `git checkout -b feature/your-new-feature`
3. Make your changes and test thoroughly.
4. Commit your changes: `git commit -am 'Add: Your detailed commit message'`
5. Push to the branch: `git push origin feature/your-new-feature`
6. Create a new Pull Request, clearly describing your changes and the problem they solve.

## Acknowledgements

- [WordPress](https://wordpress.org/)
- [Redis](https://redis.io/)
- [Varnish](https://varnish-cache.org/)
