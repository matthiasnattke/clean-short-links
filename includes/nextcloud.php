<?php
/**
 * Nextcloud: a short link for every public share link.
 *
 * Optional and off until three constants are set in wp-config.php:
 *
 *     define( 'CLEAN_SHORT_LINKS_NEXTCLOUD_URL',  'https://cloud.example.com' );
 *     define( 'CLEAN_SHORT_LINKS_NEXTCLOUD_USER', 'name' );
 *     define( 'CLEAN_SHORT_LINKS_NEXTCLOUD_PASS', 'app-password' ); // an app password, never the real one
 *
 * Every five minutes (and on "Sync now") the public link shares of that user are read through
 * Nextcloud's sharing API. A new share gets a short link on the first short domain of the network
 * (its own link there, so it reads `short.de/contract`; without a short domain on the main site),
 * named after the file, ending with the share. The short address is written into the share's label, so it shows in Nextcloud.
 * A link whose share is gone or whose end has passed is deleted; for 60 days its addresses lead to the
 * Nextcloud instance and are not handed out, then they are free again.
 * These links carry `meta.nextcloud` (the share ID) and show a "Nextcloud" badge.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/** Hook of the sync. */
const NEXTCLOUD_HOOK = 'clean_short_links_nextcloud';

/** State option (network): map share ID => link ID, last run, last error. */
const NEXTCLOUD_OPTION = 'clean_short_links_nextcloud';

/** Addresses of deleted Nextcloud links (network option): "host/slug" => time. They lead to the Nextcloud instance. */
const NEXTCLOUD_GONE_OPTION = 'clean_short_links_nextcloud_gone';

/** Days an address of a deleted Nextcloud link leads to the Nextcloud instance before it is free again. */
const NEXTCLOUD_GONE_DAYS = 60;

/** Most remembered addresses of deleted Nextcloud links (oldest are dropped). */
const NEXTCLOUD_GONE_MAX = 2000;

/**
 * Remembers the addresses of a Nextcloud link before it is deleted.
 *
 * @param array    $link  Link.
 * @param string[] $hosts Hosts it is offered on (besides the main site).
 */
function nextcloud_remember_gone( array $link, array $hosts ) {
	$gone = (array) get_site_option( NEXTCLOUD_GONE_OPTION, array() );
	$now  = time();
	foreach ( array_merge( array( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ), $hosts ) as $host ) {
		$gone[ strtolower( $host ) . '/' . $link['slug'] ] = $now;
	}
	if ( count( $gone ) > NEXTCLOUD_GONE_MAX ) {
		asort( $gone );
		$gone = array_slice( $gone, -NEXTCLOUD_GONE_MAX, null, true );
	}
	update_site_option( NEXTCLOUD_GONE_OPTION, $gone );
}

/**
 * Frees the addresses of Nextcloud links deleted more than NEXTCLOUD_GONE_DAYS ago: they no longer lead
 * to Nextcloud and can be handed out again (the retired entries on the main site are removed).
 *
 * @param array $gone "host/slug" => time of deletion.
 * @param int   $now  Unix time.
 * @return array{keep:array,free:string[]} Remaining entries and the freed short addresses of the main site.
 */
function nextcloud_expire_gone( array $gone, $now ) {
	$main = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$free = array();
	foreach ( $gone as $key => $at ) {
		if ( (int) $at > $now - NEXTCLOUD_GONE_DAYS * DAY_IN_SECONDS ) {
			continue;
		}
		unset( $gone[ $key ] );
		list( $host, $slug ) = array_pad( explode( '/', (string) $key, 2 ), 2, '' );
		if ( $host === $main && '' !== $slug ) {
			$free[] = $slug;
		}
	}
	return array(
		'keep' => $gone,
		'free' => $free,
	);
}

/**
 * Where a deleted Nextcloud address leads: the Nextcloud instance, or '' when the address is not one.
 *
 * @param string $host Host.
 * @param string $slug Short address.
 * @return string
 */
function nextcloud_gone_target( $host, $slug ) {
	$c = nextcloud_config();
	if ( ! $c || '' === (string) $slug ) {
		return '';
	}
	$gone = (array) get_site_option( NEXTCLOUD_GONE_OPTION, array() );
	return isset( $gone[ strtolower( (string) $host ) . '/' . $slug ] ) ? $c['url'] . '/' : '';
}

/**
 * Redirects to the Nextcloud instance when the requested address belonged to a deleted Nextcloud link.
 *
 * @param string $host Host.
 * @param string $slug Short address.
 */
function nextcloud_gone_redirect( $host, $slug ) {
	$to = nextcloud_gone_target( $host, $slug );
	if ( '' !== $to ) {
		nocache_headers();
		wp_redirect( $to, 302, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The Nextcloud instance set in wp-config.php.
		exit;
	}
}

/**
 * The connection from wp-config.php, or null when not configured.
 *
 * @return array{url:string,user:string,pass:string}|null
 */
function nextcloud_config() {
	if ( ! defined( 'CLEAN_SHORT_LINKS_NEXTCLOUD_URL' ) || ! defined( 'CLEAN_SHORT_LINKS_NEXTCLOUD_USER' ) || ! defined( 'CLEAN_SHORT_LINKS_NEXTCLOUD_PASS' ) ) {
		return null;
	}
	$url = rtrim( (string) CLEAN_SHORT_LINKS_NEXTCLOUD_URL, '/' );
	if ( 1 !== preg_match( '#^https://[^\s/]+(/[^\s]*)?$#', $url ) || '' === (string) CLEAN_SHORT_LINKS_NEXTCLOUD_USER || '' === (string) CLEAN_SHORT_LINKS_NEXTCLOUD_PASS ) {
		return null;
	}
	return array(
		'url'  => $url,
		'user' => (string) CLEAN_SHORT_LINKS_NEXTCLOUD_USER,
		'pass' => (string) CLEAN_SHORT_LINKS_NEXTCLOUD_PASS,
	);
}

/**
 * Saved state of the sync.
 *
 * @return array{map:array,at:int,error:string,count:int,blog:int}
 */
function nextcloud_state() {
	$s = is_multisite() ? get_site_option( NEXTCLOUD_OPTION, array() ) : get_option( NEXTCLOUD_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'map'   => is_array( $s['map'] ?? null ) ? array_map( 'intval', $s['map'] ) : array(),
		'at'    => (int) ( $s['at'] ?? 0 ),
		'error' => (string) ( $s['error'] ?? '' ),
		'count' => (int) ( $s['count'] ?? 0 ),
		'blog'  => (int) ( $s['blog'] ?? 0 ),
	);
}

/**
 * Stores the state of the sync.
 *
 * @param array $state State.
 */
function nextcloud_save_state( array $state ) {
	if ( is_multisite() ) {
		update_site_option( NEXTCLOUD_OPTION, $state );
	} else {
		update_option( NEXTCLOUD_OPTION, $state, false );
	}
}

/**
 * The public link shares out of an OCS answer: share ID => url, file name, label, end (Unix time, 0 = none).
 *
 * @param string $body JSON body of GET …/files_sharing/api/v1/shares.
 * @param int    $tz   Offset of the site's time zone in seconds (an expiry date ends at the end of that day).
 * @return array<string,array{url:string,name:string,label:string,ends:int}>|null Null when the answer is not usable.
 */
function nextcloud_parse_shares( $body, $tz = 0 ) {
	$json = json_decode( (string) $body, true );
	$data = $json['ocs']['data'] ?? null;
	if ( ! is_array( $data ) ) {
		return null;
	}
	$out = array();
	foreach ( $data as $s ) {
		if ( ! is_array( $s ) || 3 !== (int) ( $s['share_type'] ?? -1 ) ) {
			continue; // Only public links.
		}
		$id  = (string) ( $s['id'] ?? '' );
		$url = (string) ( $s['url'] ?? '' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) || 1 !== preg_match( '#^https://\S+$#', $url ) ) {
			continue;
		}
		$name = basename( (string) ( $s['file_target'] ?? $s['path'] ?? '' ) );
		$ends = 0;
		if ( is_string( $s['expiration'] ?? null ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $s['expiration'], $m ) ) {
			$ends = (int) gmdate( 'U', (int) strtotime( $m[1] . ' 23:59:59 UTC' ) ) - (int) $tz;
		}
		$out[ $id ] = array(
			'url'   => $url,
			'name'  => '' !== $name ? $name : $id,
			'label' => trim( (string) ( $s['label'] ?? '' ) ),
			'ends'  => $ends,
		);
	}
	return $out;
}

/**
 * The plan of one sync: which shares get a new link, which links are updated, which are deleted.
 *
 * @param array<string,array> $shares Shares from nextcloud_parse_shares().
 * @param array<string,int>   $map    Share ID => link ID from the last run.
 * @param callable            $link   Receives a link ID, returns the link (or null when it is gone).
 * @param int                 $now    Unix time.
 * @return array{create:string[],update:array<string,int>,delete:array<string,int>}
 */
function nextcloud_plan( array $shares, array $map, callable $link, $now ) {
	$plan = array(
		'create' => array(),
		'update' => array(),
		'delete' => array(),
	);
	foreach ( $map as $share => $id ) {
		$l = $link( (int) $id );
		if ( ! $l ) {
			continue; // Deleted by hand: forget it, do not create it again.
		}
		$ends = isset( $shares[ $share ] ) ? (int) $shares[ $share ]['ends'] : 0;
		$gone = ! isset( $shares[ $share ] ) || ( $ends > 0 && $ends <= $now ) || ( ! empty( $l['ends_at'] ) && (int) $l['ends_at'] <= $now );
		if ( $gone ) {
			$plan['delete'][ (string) $share ] = (int) $id;
		} else {
			$plan['update'][ (string) $share ] = (int) $id;
		}
	}
	foreach ( $shares as $share => $s ) {
		if ( ! array_key_exists( (string) $share, $map ) && ( 0 === (int) $s['ends'] || (int) $s['ends'] > $now ) ) {
			$plan['create'][] = (string) $share;
		}
	}
	return $plan;
}

/**
 * A request to the sharing API.
 *
 * @param array  $c      Connection.
 * @param string $method GET or PUT.
 * @param string $path   Path below …/files_sharing/api/v1.
 * @param array  $body   Form fields for PUT.
 * @return array|\WP_Error
 */
function nextcloud_request( array $c, $method, $path, array $body = array() ) {
	return wp_safe_remote_request( // Nextcloud sync: the plugin's own optional feature, set up in wp-config.php.
		$c['url'] . '/ocs/v2.php/apps/files_sharing/api/v1' . $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'format=json',
		array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization'  => 'Basic ' . base64_encode( $c['user'] . ':' . $c['pass'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
				'OCS-APIRequest' => 'true',
				'Accept'         => 'application/json',
			),
			'body'    => $body ? $body : null,
		)
	);
}

/**
 * The site that owns the Nextcloud links: the first short domain of the network, else the main site.
 *
 * @return int
 */
function nextcloud_blog_id() {
	if ( ! is_multisite() ) {
		return get_current_blog_id();
	}
	foreach ( array_map( 'intval', (array) network_settings()['short_sites'] ) as $id ) {
		$site = get_site( $id );
		if ( $site && '/' === $site->path && ! is_main_site( $id ) && ! $site->deleted && ! $site->archived ) {
			return $id;
		}
	}
	return get_main_site_id();
}

/**
 * Runs the sync on the owning site. Returns the number of links created, updated and deleted, or an error.
 *
 * @return array{created:int,updated:int,deleted:int}|\WP_Error
 */
function nextcloud_sync() {
	$c = nextcloud_config();
	if ( ! $c ) {
		return new \WP_Error( 'config', __( 'Nextcloud is not set up in wp-config.php.', 'clean-short-links' ) );
	}
	$blog  = nextcloud_blog_id();
	$state = nextcloud_state();
	// The owning site changed (e.g. a short domain was marked): the old links go, the sync makes them anew there.
	if ( is_multisite() && $state['map'] && (int) $state['blog'] !== $blog ) {
		switch_to_blog( $state['blog'] ? (int) $state['blog'] : get_main_site_id() );
		foreach ( $state['map'] as $id ) {
			delete_link( (int) $id, false );
		}
		restore_current_blog();
		$state['map'] = array();
	}
	$state['blog'] = $blog;
	nextcloud_save_state( $state );
	$switched = is_multisite() && get_current_blog_id() !== $blog;
	if ( $switched ) {
		switch_to_blog( $blog );
	}
	try {
		return nextcloud_sync_here( $c );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * The sync, on the main site.
 *
 * @param array $c Connection.
 * @return array|\WP_Error
 */
function nextcloud_sync_here( array $c ) {
	$state = nextcloud_state();
	$res   = nextcloud_request( $c, 'GET', '/shares' );
	$code  = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	$tz    = (int) wp_timezone()->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );
	$list  = 200 === $code ? nextcloud_parse_shares( wp_remote_retrieve_body( $res ), $tz ) : null;
	if ( null === $list ) {
		$state['at']    = time();
		$state['error'] = is_wp_error( $res ) ? $res->get_error_message() : ( 401 === $code ? __( 'Nextcloud refused the sign-in (app password?).', 'clean-short-links' ) : 'HTTP ' . $code );
		nextcloud_save_state( $state );
		return new \WP_Error( 'nextcloud', $state['error'] );
	}

	$now   = time();
	$plan  = nextcloud_plan( $list, $state['map'], __NAMESPACE__ . '\\link_by_id', $now );
	// On a short domain the links are its own; on the main site they are offered on the first short domain.
	$hosts = is_short_site() ? array() : array_slice( short_hosts(), 0, 1 );
	$done  = array(
		'created' => 0,
		'updated' => 0,
		'deleted' => 0,
	);

	foreach ( $plan['delete'] as $share => $id ) {
		$gone = link_by_id( $id );
		if ( $gone ) {
			nextcloud_remember_gone( $gone, (array) $gone['meta']['hosts'] );
		}
		delete_link( $id, true );
		unset( $state['map'][ $share ] );
		++$done['deleted'];
	}
	foreach ( array_keys( $state['map'] ) as $share ) {
		if ( ! isset( $plan['update'][ $share ] ) ) {
			unset( $state['map'][ $share ] ); // Deleted by hand.
		}
	}
	foreach ( $plan['update'] as $share => $id ) {
		$l    = link_by_id( $id );
		$s    = $list[ $share ];
		$ends = (int) $s['ends'];
		if ( $l && ( $l['target'] !== $s['url'] || (int) $l['ends_at'] !== $ends ) ) {
			$data            = $l;
			$data['id']      = $id;
			$data['target']  = $s['url'];
			$data['ends_at'] = $ends;
			if ( ! is_wp_error( save_link( $data ) ) ) {
				++$done['updated'];
			}
		}
	}
	foreach ( $plan['create'] as $share ) {
		$s     = $list[ $share ];
		$title = '' !== $s['label'] ? $s['label'] : (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $s['name'] );
		$slug  = unique_numbered( title_slug( $title ) );
		if ( $hosts && hosts_taken( $slug, $hosts ) ) {
			$alt  = host_alternatives( $slug, $hosts );
			$slug = $alt ? $alt[0] : unique_random_slug();
		}
		$id = save_link(
			array(
				'title'   => $title,
				'target'  => $s['url'],
				'slug'    => $slug,
				'kind'    => 'title',
				'root'    => 0,
				'ends_at' => (int) $s['ends'],
				'meta'    => array(
					'hosts'     => $hosts,
					'nextcloud' => $share,
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			continue;
		}
		$state['map'][ $share ] = (int) $id;
		++$done['created'];
		if ( '' === $s['label'] ) {
			$urls  = short_urls( link_by_id( $id ) );
			$short = $hosts && isset( $urls['hosts'][ $hosts[0] ] ) ? $urls['hosts'][ $hosts[0] ] : $urls['link'];
			nextcloud_request( $c, 'PUT', '/shares/' . rawurlencode( $share ), array( 'label' => preg_replace( '#^https://#', '', $short ) ) );
		}
	}

	// After NEXTCLOUD_GONE_DAYS the addresses of deleted links are free again.
	$expired = nextcloud_expire_gone( (array) get_site_option( NEXTCLOUD_GONE_OPTION, array() ), $now );
	if ( $expired['free'] || count( $expired['keep'] ) !== count( (array) get_site_option( NEXTCLOUD_GONE_OPTION, array() ) ) ) {
		global $wpdb;
		foreach ( $expired['free'] as $slug ) {
			$wpdb->delete( tables()['slugs'], array( 'slug' => $slug, 'kind' => 'retired' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Short-address table of this plugin.
		}
		update_site_option( NEXTCLOUD_GONE_OPTION, $expired['keep'] );
		forget_links();
	}

	$state['at']    = $now;
	$state['error'] = '';
	$state['count'] = count( $state['map'] );
	nextcloud_save_state( $state );
	return $done;
}

/*
 * Every five minutes, on the main site only and only while it is set up.
 */
add_filter(
	'cron_schedules',
	function ( $s ) {
		$s['clean_short_links_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Clean Short Links: every 5 minutes',
		);
		return $s;
	}
);

add_action(
	'init',
	function () {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		$on   = null !== nextcloud_config();
		$next = wp_next_scheduled( NEXTCLOUD_HOOK );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'clean_short_links_5min', NEXTCLOUD_HOOK );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( NEXTCLOUD_HOOK );
		}
	}
);

add_action( NEXTCLOUD_HOOK, __NAMESPACE__ . '\\nextcloud_sync' );
