<?php
/**
 * Removes all data of Clean Short Links: tables, options and scheduled events of every site.
 *
 * @package CleanShortLinks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the data of the current site.
 */
function clean_short_links_uninstall_site() {
	global $wpdb;
	foreach ( array( 'cleansl_links', 'cleansl_slugs', 'cleansl_clicks' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Removing the plugin's own tables.
	}
	delete_option( 'clean_short_links_db' );
	delete_option( 'clean_short_links_site' );
	delete_option( 'clean_short_links_network' );
	wp_clear_scheduled_hook( 'clean_short_links_prune' );
	wp_clear_scheduled_hook( 'clean_short_links_check' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $clean_short_links_site ) {
		switch_to_blog( (int) $clean_short_links_site );
		clean_short_links_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'clean_short_links_network' );
} else {
	clean_short_links_uninstall_site();
}
