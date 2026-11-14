<?php
/**
 * Clean Short Links – short links on subdomains without a site of their own.
 *
 * WordPress decides very early which site of a network answers a host – before any plugin
 * is loaded. Unknown subdomains are sent to the sign-up page. This small file lets the
 * subdomains listed in Network admin → Short links be answered by the main site instead;
 * there the plugin only serves short links and sends everything else to the main site.
 *
 * Setup: copy this file to wp-content/sunrise.php and add to wp-config.php, above the line
 * "That's all, stop editing!":
 *
 *     define( 'SUNRISE', true );
 *
 * If there is a sunrise.php already, add this line to it instead:
 *
 *     require WP_CONTENT_DIR . '/plugins/clean-short-links/sunrise.php';
 *
 * @package CleanShortLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'CLEAN_SHORT_LINKS_SUNRISE' ) ) {
	return;
}

define( 'CLEAN_SHORT_LINKS_SUNRISE', true );

add_filter(
	'pre_get_site_by_path',
	function ( $site, $domain ) {
		if ( null !== $site ) {
			return $site;
		}
		global $wpdb;
		// One small, cached query: the list is written by the network settings of the plugin.
		$data = wp_cache_get( 'alias_hosts', 'clean_short_links' );
		if ( false === $data ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Runs before the network is loaded, get_site_option() is not available yet.
			$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s LIMIT 1', $wpdb->sitemeta, 'clean_short_links_alias_hosts' ) );
			$data = $raw ? maybe_unserialize( $raw ) : array();
			$data = is_array( $data ) ? $data : array();
			wp_cache_set( 'alias_hosts', $data, 'clean_short_links', 300 );
		}
		$domain = strtolower( (string) $domain );
		if ( empty( $data['hosts'] ) || ! in_array( $domain, (array) $data['hosts'], true ) ) {
			return $site;
		}
		$main = get_site( (int) ( $data['blog'] ?? 1 ) );
		if ( $main ) {
			define( 'CLEAN_SHORT_LINKS_ALIAS', $domain );
		}
		return $main ? $main : $site;
	},
	10,
	2
);
