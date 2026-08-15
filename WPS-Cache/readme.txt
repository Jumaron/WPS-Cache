=== WPS-Cache ===
Contributors: Jumaron
Tags: caching, performance, HTML, Redis, Varnish
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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

- **Cloudflare (optional):**
  When explicitly enabled with an API token and zone ID, the plugin sends cache-purge requests and can synchronize one identified full-page Cache Rule. Requests go to `api.cloudflare.com`; review Cloudflare's terms and privacy policy before enabling it.

- **Google Fonts and approved external assets (optional):**
  Localization downloads only Google Fonts styles/files and exact CSS/JavaScript URLs entered by an administrator. Cached copies are served from this WordPress installation.

- **Gravatar (optional):**
  Local Gravatar caching downloads avatar images from official Gravatar hosts and stores them for seven days.

Real-user performance monitoring, image statistics, and uptime history stay inside the WordPress database. The RUM beacon samples anonymous paths and timing values; it does not intentionally store IP addresses, cookies, user IDs, or full query strings.

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
= 0.2.0 =
* Added canonical query/device cache policies, per-URL purge, sitemap preload batches, stale rebuild locking, REST caching, and Nginx/Cloudflare integrations.
* Added HTML/resource delivery, responsive media, local image processing with backup/restore and WebP/AVIF, Script Manager rules, monitoring, WP-CLI, and multisite controls.
* Reorganized the admin application into capability-focused navigation with diagnostics, presets, safe preview mode, import/export, and rollback.

= 0.1.1 =
* Stopped writing Apache/LiteSpeed directives to `.htaccess` on activation.
* Added ownership-scoped cleanup for server rules created by older releases.
* Kept page-cache delivery portable through the existing PHP drop-in.

= 0.1.0 =
* Rebuilt the plugin around modular cache, optimization, lifecycle, and infrastructure services.
* Added independent frontend optimization, automated tests, static analysis, and deterministic release builds.
* Fixed cache lifetime propagation and early WooCommerce/session bypass rules.

= 0.0.4 =
* Full rework.

= 0.0.3 =
* Initial release with HTML, Redis, and Varnish caching support.
* Added real-time cache performance metrics.
* External services documentation added.

== Upgrade Notice ==
= 0.1.1 =
Prevents shared-host HTTP 500 errors caused by restricted `.htaccess` directives.
= 0.1.0 =
Architecture rebuild. Test on staging and clear all cache layers after upgrading.
= 0.0.4 =
Clear Cache on update!
= 0.0.3 =
This is the first release. Ensure you test thoroughly on a staging environment before deploying to production.
