<?php
/**
 * Click statistics: storing, querying, pruning and a small SVG chart.
 *
 * Visits are added up per day – no IP addresses, cookies, user IDs or full referrer URLs
 * are ever stored, only the host of the referring page.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables of this plugin.

/**
 * Adds one visit.
 *
 * @param int    $link_id  Link ID.
 * @param int    $source   Source.
 * @param int    $device   Device class.
 * @param string $referrer Referring host.
 */
function record_click( $link_id, $source, $device, $referrer ) {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO %i (link_id, day, source, device, referrer, hits) VALUES (%d, %s, %d, %d, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
			tables()['clicks'],
			$link_id,
			wp_date( 'Y-m-d' ),
			$source,
			$device,
			$referrer
		)
	);
}

/**
 * First day of a period of N days, ending today.
 *
 * @param int $days Days.
 * @return string Y-m-d.
 */
function since( $days ) {
	return wp_date( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
}

/**
 * Visits per link in a period.
 *
 * @param int[] $ids  Link IDs, empty for all.
 * @param int   $days Days, 0 for all time.
 * @return array<int,int> Link ID => visits.
 */
function totals( array $ids = array(), $days = 30 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT link_id, SUM(hits) AS hits FROM %i WHERE day >= %s GROUP BY link_id', tables()['clicks'], $days ? since( $days ) : '1000-01-01' ) );
	$ids  = array_flip( array_filter( array_map( 'intval', $ids ) ) );
	$out  = array();
	foreach ( $rows as $r ) {
		if ( ! $ids || isset( $ids[ (int) $r->link_id ] ) ) {
			$out[ (int) $r->link_id ] = (int) $r->hits;
		}
	}
	return $out;
}

/**
 * Most visited links of a period.
 *
 * @param int $days  Days.
 * @param int $limit How many.
 * @return array<int,int> Link ID => visits.
 */
function top_links( $days = 30, $limit = 5 ) {
	$all = totals( array(), $days );
	arsort( $all );
	return array_slice( $all, 0, $limit, true );
}

/**
 * All visits of the site in a period.
 *
 * @param int $days Days.
 * @return int
 */
function site_total( $days = 30 ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM %i WHERE day >= %s', tables()['clicks'], since( $days ) ) );
}

/**
 * Visits per day for a chart, with empty days filled in.
 *
 * @param int $link_id Link ID, 0 for all links.
 * @param int $days    Days.
 * @return array<string,int> Y-m-d => visits.
 */
function daily( $link_id, $days = 30 ) {
	global $wpdb;
	$t    = tables();
	$rows = $link_id
		? $wpdb->get_results( $wpdb->prepare( 'SELECT day, SUM(hits) AS hits FROM %i WHERE link_id = %d AND day >= %s GROUP BY day', $t['clicks'], $link_id, since( $days ) ) )
		: $wpdb->get_results( $wpdb->prepare( 'SELECT day, SUM(hits) AS hits FROM %i WHERE day >= %s GROUP BY day', $t['clicks'], since( $days ) ) );
	$out  = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$out[ wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
	}
	foreach ( $rows as $r ) {
		if ( isset( $out[ $r->day ] ) ) {
			$out[ $r->day ] = (int) $r->hits;
		}
	}
	return $out;
}

/**
 * Visits of a link split by a column.
 *
 * @param int    $link_id Link ID.
 * @param string $column  source, device or referrer.
 * @param int    $days    Days, 0 for all time.
 * @return array<string,int>
 */
function breakdown( $link_id, $column, $days = 30 ) {
	global $wpdb;
	if ( ! in_array( $column, array( 'source', 'device', 'referrer' ), true ) ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS k, SUM(hits) AS hits FROM %i WHERE link_id = %d AND day >= %s GROUP BY k ORDER BY hits DESC LIMIT 10', $column, tables()['clicks'], $link_id, $days ? since( $days ) : '1970-01-01' ) );
	$out  = array();
	foreach ( $rows as $r ) {
		$out[ (string) $r->k ] = (int) $r->hits;
	}
	return $out;
}

/**
 * CSV of a link's visits per day, source and device.
 *
 * @param int $link_id Link ID.
 * @return string
 */
function export_csv( $link_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT day, source, device, referrer, hits FROM %i WHERE link_id = %d ORDER BY day, source, device', tables()['clicks'], $link_id ) );
	$src  = array( 'link', 'qr', 'short-form', 'link-in-bio', 'nfc' );
	$dev  = array( 'desktop', 'mobile', 'tablet' );
	$out  = "day,source,device,referrer,visits\n";
	foreach ( $rows as $r ) {
		$out .= sprintf( "%s,%s,%s,%s,%d\n", $r->day, $src[ (int) $r->source ] ?? '', $dev[ (int) $r->device ] ?? '', str_replace( ',', '', $r->referrer ), $r->hits );
	}
	return $out;
}

/*
 * Old statistics are removed once a day, as set in the network settings.
 */
add_action(
	'clean_short_links_prune',
	function () {
		global $wpdb;
		$days = (int) network_settings()['retention_days'];
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', tables()['clicks'], since( $days ) ) );
		}
	}
);

/**
 * A bar chart of visits per day as inline SVG.
 *
 * @param array<string,int> $series Y-m-d => visits.
 * @return string SVG markup (escaped).
 */
function chart_svg( array $series ) {
	$w     = 640;
	$h     = 140;
	$n     = max( 1, count( $series ) );
	$max   = max( 1, max( $series ? $series : array( 0 ) ) );
	$bw    = $w / $n;
	$bars  = '';
	$i     = 0;
	$label = '';
	foreach ( $series as $day => $hits ) {
		$bh = $hits ? max( 2, round( ( $h - 20 ) * $hits / $max ) ) : 0;
		/* translators: 1: date, 2: number of visits */
		$title = sprintf( _n( '%1$s: %2$d visit', '%1$s: %2$d visits', $hits, 'clean-short-links' ), wp_date( get_option( 'date_format' ), strtotime( $day . ' 12:00' ) ), $hits );
		$bars .= sprintf(
			'<rect x="%.1f" y="%d" width="%.1f" height="%d" rx="2" class="csl-bar"><title>%s</title></rect>',
			$i * $bw + 1,
			$h - 16 - $bh,
			max( 1, $bw - 2 ),
			$bh,
			esc_html( $title )
		);
		if ( 0 === $i || $i === $n - 1 ) {
			$label .= sprintf( '<text x="%.1f" y="%d" text-anchor="%s" class="csl-axis">%s</text>', 0 === $i ? 0 : $w, $h - 2, 0 === $i ? 'start' : 'end', esc_html( wp_date( 'j.n.', strtotime( $day . ' 12:00' ) ) ) );
		}
		++$i;
	}
	$label .= sprintf( '<text x="%d" y="10" text-anchor="end" class="csl-axis">%s</text>', $w, esc_html( number_format_i18n( $max ) ) );
	return sprintf( '<svg class="csl-chart" viewBox="0 0 %d %d" role="img" aria-label="%s">%s%s</svg>', $w, $h, esc_attr__( 'Visits per day', 'clean-short-links' ), $bars, $label );
}
