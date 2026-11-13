<?php
/**
 * Working together with Clean Maintenance: one rule per site, shared by both plugins
 * (option "clean_maintenance_short_links"), decides how short links behave during
 * maintenance and whether short links that do not work show the maintenance page.
 * Without Clean Maintenance nothing here has any effect.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Is Clean Maintenance active with the functions this needs?
 *
 * @return bool
 */
function maintenance_available() {
	return function_exists( 'clean_maintenance_show_page' ) && function_exists( 'clean_maintenance_blocks_visitor' );
}

/**
 * Cleans the shared rules – the same values Clean Maintenance uses.
 *
 * @param mixed $in Raw value.
 * @return array{during:string,dead:string}
 */
function clean_maintenance_rules( $in ) {
	$in = is_array( $in ) ? $in : array();
	return array(
		'during' => in_array( $in['during'] ?? '', array( 'redirect', 'open', 'pause' ), true ) ? $in['during'] : 'redirect',
		'dead'   => in_array( $in['dead'] ?? '', array( 'never', 'temporary', 'always' ), true ) ? $in['dead'] : 'never',
	);
}

/**
 * The shared rules of the current site.
 *
 * @return array{during:string,dead:string}
 */
function maintenance_rules() {
	return clean_maintenance_rules( get_option( 'clean_maintenance_short_links', array() ) );
}

/**
 * During maintenance with the rule "pause": short links show the maintenance page too.
 * Never returns when it applies.
 */
function maybe_pause_for_maintenance() {
	if ( maintenance_available() && 'pause' === maintenance_rules()['during'] && \clean_maintenance_blocks_visitor() ) {
		\clean_maintenance_show_page( 503 );
	}
}

/**
 * For a short link that does not work: the maintenance page instead of the error page, if
 * the rule says so. Never returns when it applies.
 *
 * @param int $status 404, 410 or 503.
 */
function maybe_maintenance_for_dead_link( $status ) {
	if ( ! maintenance_available() ) {
		return;
	}
	$dead = maintenance_rules()['dead'];
	if ( 'always' === $dead || ( 'temporary' === $dead && 503 === (int) $status ) ) {
		\clean_maintenance_show_page( (int) $status );
	}
}

/**
 * Is the current page the target of an active short link?
 *
 * @return bool
 */
function is_short_link_target() {
	global $wpdb;
	if ( is_front_page() || is_home() ) {
		$target = 'home';
		$id     = (int) get_option( 'page_on_front' );
	} elseif ( is_singular() ) {
		$id     = (int) get_queried_object_id();
		$target = 'post:' . $id;
	} else {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table of this plugin.
	return (bool) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM %i WHERE parent_id = 0 AND status = 'active' AND ( ( post_id > 0 AND post_id = %d ) OR target = %s OR rules LIKE %s ) LIMIT 1",
			tables()['links'],
			$id,
			$target,
			'%' . $wpdb->esc_like( '"' . $target . '"' ) . '%'
		)
	);
}

/*
 * With the rule "let targets through", pages that active short links lead to are an
 * exception of the maintenance mode.
 */
add_filter(
	'clean_maintenance_excluded',
	function ( $excluded ) {
		if ( $excluded || 'open' !== maintenance_rules()['during'] ) {
			return $excluded;
		}
		return is_short_link_target();
	}
);
