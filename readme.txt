=== WPS-Cache ===
Contributors: Jumaron
Tags: caching, performance, HTML, Redis, Varnish
Requires at least: 6.3
Tested up to: 6.7
Stable tag: 0.0.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Boost your WordPress speed with multi-layer caching: HTML, Redis, and Varnish for fast performance.

== Description ==
WPS-Cache enhances your WordPress site's speed by implementing multiple layers of caching:
- **HTML Cache:** Delivers pre-rendered static pages.
- **Redis Cache:** Accelerates database query performance.
- **Varnish Cache:** Handles HTTP-level caching for improved scalability.

== External Services ==
This plugin connects to external caching services to optimize performance:

- **Varnish Cache:**  
  The plugin sends HTTP requests (such as purge requests and connection checks) to a specified Varnish caching server. No personal or sensitive data is transmitted. For additional details, please review the [Varnish Cache documentation](https://varnish-cache.org/), its [Terms of Service](https://varnish-cache.org/TOS), and [Privacy Policy](https://varnish-cache.org/privacy).

== Installation ==
1. Upload the `WPS-Cache` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the "WPS Cache" settings page and configure your caching options.

== Frequently Asked Questions ==
= How do I configure external caching? =
Ensure that your Varnish server is properly configured and that the server details are correctly set in the plugin settings.

= Can I use this plugin on a live site? =
This plugin is currently experimental. We recommend testing in a staging environment first.

== Screenshots ==
1. Admin panel settings for cache management.
2. Front-end cache status indicator.

== Changelog ==
= 0.0.3 =
* Initial release with HTML, Redis, and Varnish caching support.
* Added real-time cache performance metrics.
* External services documentation added.

== Upgrade Notice ==
= 0.0.3 =
This is the first release. Ensure you test thoroughly on a staging environment before deploying to production.
