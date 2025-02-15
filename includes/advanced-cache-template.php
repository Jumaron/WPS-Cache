<?php
/**
 * WPS Cache - Advanced Cache Drop-in
 * 
 * This file is automatically deployed to wp-content/advanced-cache.php
 * It handles serving cached files and manages cache bypassing logic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access not allowed.' );
}

class WPSAdvancedCache {
	private const CACHE_BYPASS_CONDITIONS = [
		'WP_CLI',
		'DOING_CRON',
		'DOING_AJAX',
		'REST_REQUEST',
		'XMLRPC_REQUEST',
		'WP_ADMIN',
	];

	private const CONTENT_TYPES = [
		'css'  => 'text/css; charset=UTF-8',
		'js'   => 'application/javascript; charset=UTF-8',
		'html' => 'text/html; charset=UTF-8'
	];

	private const DEFAULT_CACHE_LIFETIME = 3600;
	private const YEAR_IN_SECONDS        = 31536000;

	private string $request_uri;
	private array $settings;
	private int $cache_lifetime;

	public function __construct() {
		// Sanitize the REQUEST_URI before using it.
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$this->settings       = $this->getSettings();
		$this->cache_lifetime = $this->settings['cache_lifetime'] ?? self::DEFAULT_CACHE_LIFETIME;
	}

	/**
	 * Main execution method
	 */
	public function execute(): void {
		if ( $this->shouldBypassCache() ) {
			$this->setHeader( 'BYPASS' );
			return;
		}

		// Check for static asset caching (CSS/JS)
		if ( $this->handleStaticAsset() ) {
			return;
		}

		// Handle HTML caching
		$this->handleHtmlCache();
	}

	/**
	 * Checks if cache should be bypassed.
	 *
	 * For GET requests, if a 'preview' parameter is set, the associated nonce is verified.
	 * For POST data, a nonce check is required as well.
	 * Other query parameters (excluding known nonce keys) also force a cache bypass.
	 *
	 * @return bool
	 */
	private function shouldBypassCache(): bool {
		// Retrieve and sanitize server variables using filter_input.
        $request_method = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'GET';
        $requested_with = filter_input(INPUT_SERVER, 'HTTP_X_REQUESTED_WITH', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $requested_with = $requested_with ? strtolower(trim($requested_with)) : '';


		// Process preview GET variable with nonce verification.
		$preview = '';
		if ( isset( $_GET['preview'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] )
				? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
				: '';
			// If the nonce is missing or invalid, bypass cache.
			if ( ! wp_verify_nonce( $nonce, 'wps_cache_preview' ) ) {
				return true;
			}
			$preview = sanitize_text_field( wp_unslash( $_GET['preview'] ) );
		}

		// Process POST data with nonce verification.
		$post_data = [];
		if ( ! empty( $_POST ) ) {
			$nonce = isset( $_POST['_wpnonce'] )
				? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
				: '';
			// If the nonce is missing or invalid, bypass cache.
			if ( ! wp_verify_nonce( $nonce, 'wps_cache_post' ) ) {
				return true;
			}
			$post_data = array_map( 'sanitize_text_field', wp_unslash( $_POST ) );
		}

		// Process GET parameters excluding known nonce fields.
		$query_params = [];
		if ( ! empty( $_GET ) ) {
			$excluded_keys = [ 'preview', '_wpnonce' ];
			$other_params  = array_diff_key( $_GET, array_flip( $excluded_keys ) );
			if ( ! empty( $other_params ) ) {
				$query_params = array_map( 'sanitize_text_field', wp_unslash( $other_params ) );
			}
		}

		return (
			! empty( $preview ) ||
			! empty( $post_data ) ||
			is_admin() ||
			$request_method !== 'GET' ||
			! empty( $query_params ) ||
			( $requested_with === 'xmlhttprequest' )
		);
	}

	/**
	 * Handles static asset caching (CSS/JS)
	 */
	private function handleStaticAsset(): bool {
		if ( ! preg_match( '/\.(?:css|js)\?.*ver=(\d+)$/', $this->request_uri, $matches ) ) {
			return false;
		}

		// Use wp_parse_url() instead of parse_url() for consistent output.
		$file_path = wp_parse_url( $this->request_uri, PHP_URL_PATH );
		if ( ! $file_path ) {
			return false;
		}

		$extension  = pathinfo( $file_path, PATHINFO_EXTENSION );
		$file_key   = md5( $file_path );
		$cache_file = WP_CONTENT_DIR . "/cache/wps-cache/$extension/" . $file_key . ".$extension";

		if ( file_exists( $cache_file ) && $this->isCacheValid( $cache_file ) ) {
			return $this->serveCachedFile( $cache_file, self::CONTENT_TYPES[ $extension ] );
		}

		return false;
	}

	/**
	 * Handles HTML page caching
	 */
	private function handleHtmlCache(): void {
		$cache_key  = md5( $this->request_uri );
		$cache_file = WP_CONTENT_DIR . '/cache/wps-cache/html/' . $cache_key . '.html';

		if ( file_exists( $cache_file ) && $this->isCacheValid( $cache_file ) ) {
			$this->serveCachedFile( $cache_file, self::CONTENT_TYPES['html'] );
			return;
		}

		$this->setHeader( 'MISS' );
	}

	/**
	 * Serves a cached file with appropriate headers
	 */
	private function serveCachedFile( string $file, string $content_type ): bool {
		$content = @file_get_contents( $file );
		if ( $content === false ) {
			return false;
		}

		$cache_time = filemtime( $file );
		$etag       = '"' . md5( $content ) . '"';

		// Sanitize the HTTP_IF_NONE_MATCH header.
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) )
			: '';
		if ( $if_none_match === $etag ) {
			header( 'HTTP/1.1 304 Not Modified' );
			exit;
		}

		// Set cache headers.
		header( 'Content-Type: ' . $content_type );
		header( 'Cache-Control: public, max-age=' . self::YEAR_IN_SECONDS );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $cache_time ) . ' GMT' );

		$this->setHeader( 'HIT' );

		// Escape the cached content for safe output while preserving allowed HTML.
		echo wp_kses_post( $content );
		exit;
	}

	/**
	 * Checks if cache file is still valid
	 */
	private function isCacheValid( string $file ): bool {
		return ( time() - filemtime( $file ) ) < $this->cache_lifetime;
	}

	/**
	 * Sets WPS Cache header
	 */
	private function setHeader( string $status ): void {
		header( 'X-WPS-Cache: ' . $status );
	}

	/**
	 * Gets cache settings from WordPress options
	 */
	private function getSettings(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$settings = get_option( 'wpsc_settings' );
		return is_array( $settings ) ? $settings : [];
	}
}

// Execute caching logic.
try {
	$cache = new WPSAdvancedCache();
	$cache->execute();
} catch ( Throwable $e ) {
	// Debug logging is disabled in production.
	header( 'X-WPS-Cache: ERROR' );
}
