<?php
/**
 * Removes all data of Clean Short Links: tables, options and scheduled events of every site.
 *
 * @package CleanShortLinks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Ends the Wallet cards of this site's links and locations through Clean Mobile Connect.
 *
 * Only on uninstall: deactivating the plugin leaves cards untouched. Without Connect there is nothing to end.
 */
function clean_short_links_uninstall_cards() {
	global $wpdb;
	if ( ! function_exists( 'clean_mobile_connect_wallet_end_card' ) ) {
		return;
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, meta FROM %i WHERE parent_id = 0', $wpdb->prefix . 'cleansl_links' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table, being removed.
	foreach ( (array) $rows as $row ) {
		$id = (int) $row->id;
		\clean_mobile_connect_wallet_end_card( 'csl', (string) $id );
		$meta = json_decode( (string) $row->meta, true );
		foreach ( (array) ( is_array( $meta ) ? ( $meta['places'] ?? array() ) : array() ) as $place ) {
			if ( ! empty( $place['key'] ) ) {
				\clean_mobile_connect_wallet_end_card( 'csl', $id . ':' . $place['key'] );
			}
		}
	}
}

/**
 * Removes the data of the current site.
 */
function clean_short_links_uninstall_site() {
	global $wpdb;
	clean_short_links_uninstall_cards();
	foreach ( array( 'cleansl_links', 'cleansl_slugs', 'cleansl_clicks' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Removing the plugin's own tables.
	}
	delete_option( 'clean_short_links_db' );
	delete_post_meta_by_key( '_clean_short_links_permalink' );
	delete_option( 'clean_short_links_site' );
	delete_option( 'clean_short_links_network' );
	wp_clear_scheduled_hook( 'clean_short_links_prune' );
	wp_unschedule_hook( 'clean_short_links_wallet_touch_all' ); // Whatever the page argument.
	wp_clear_scheduled_hook( 'clean_short_links_check' );
	wp_clear_scheduled_hook( 'clean_short_links_calendars' );
	if ( ! function_exists( 'clean_maintenance_settings' ) ) {
		delete_option( 'clean_maintenance_short_links' ); // Shared with Clean Maintenance – kept while that is installed.
	}
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
	delete_site_option( 'clean_short_links_alias_hosts' );
	delete_site_option( 'clean_short_links_routes_db' );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->base_prefix . 'cleansl_routes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Removing the plugin's own table.
} else {
	clean_short_links_uninstall_site();
}
