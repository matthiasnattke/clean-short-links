<?php
/**
 * Front end: turns a short address into a redirect and counts the visit.
 *
 * - example.com/s/slug and example.com/q/slug are handled right after WordPress has parsed
 *   the request – before any template, maintenance mode or canonical redirect.
 * - The short form example.com/slug is only looked at when WordPress found nothing there
 *   (a 404), so existing content always wins.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * The request path, without query string.
 *
 * @return string
 */
function request_path() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return (string) wp_parse_url( $uri, PHP_URL_PATH );
}

/**
 * Parses the current request.
 *
 * @return array{0:int,1:string}|null
 */
function current_request() {
	list( $p, $q ) = prefixes();
	return parse_path( request_path(), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $p, $q );
}

/**
 * Status set for the 404/410 page that follows a dead short link.
 *
 * @var int
 */
$GLOBALS['clean_short_links_status'] = 0;

add_action(
	'parse_request',
	function ( $wp ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$req = current_request();
		if ( ! $req || SOURCE_ROOT === $req[0] ) {
			return;
		}
		$found = find_slug( $req[1] );
		if ( ! $found ) {
			// Unknown short address: the default target of the site, if set – otherwise WordPress' 404 page.
			$default = default_target_url( 'unknown' );
			if ( $default ) {
				go_to( $default, null, $req[0], false );
			}
			return;
		}
		$result = handle( $found, $req[0] );
		if ( $result ) {
			// Dead link: let WordPress render its 404 page with our status.
			$wp->query_vars = array( 'error' => '404' );
		}
	},
	1
);

add_action(
	'template_redirect',
	function () {
		if ( ! is_404() || ! root_allowed() || $GLOBALS['clean_short_links_status'] ) {
			return;
		}
		$req = current_request();
		if ( ! $req || SOURCE_ROOT !== $req[0] ) {
			return;
		}
		$found = find_slug( $req[1] );
		if ( ! $found || ( $found[0] && empty( $found[0]['root'] ) ) || 'retired' === $found[1] ) {
			return;
		}
		handle( $found, SOURCE_ROOT );
	},
	0
);

/*
 * A link that has run out answers "410 Gone", one that has not started yet "404".
 */
add_filter(
	'status_header',
	function ( $header, $code, $description, $protocol ) {
		$status = $GLOBALS['clean_short_links_status'];
		if ( 404 === (int) $code && 410 === $status ) {
			return $protocol . ' 410 Gone';
		}
		return $header;
	},
	10,
	4
);

/**
 * The site's default target as URL, if it applies to this case.
 *
 * @param string $case unknown (no such short link) or inactive (not started, expired, used up, paused, retired).
 * @return string|null
 */
function default_target_url( $case ) {
	$s = site_settings();
	if ( empty( $s[ 'default_' . $case ] ) ) {
		return null;
	}
	if ( 'home' === $s['default_mode'] ) {
		return home_url( '/' );
	}
	if ( 'custom' === $s['default_mode'] && '' !== $s['default_url'] ) {
		return target_url( $s['default_url'] );
	}
	return null;
}

/**
 * Redirects or prepares the dead-link page.
 *
 * Invitations follow their main link: target, rules, time window and fallback come from
 * there, their own click limit and expiry still apply.
 *
 * @param array $found  Result of find_slug().
 * @param int   $source Where the visit came from.
 * @return int 0 when redirected (never returns then), otherwise the status of the dead link.
 */
function handle( array $found, $source ) {
	list( $link, $kind ) = $found;
	if ( $link && ! empty( $link['parent_id'] ) ) {
		$parent = link_by_id( $link['parent_id'] );
		$link   = $parent ? merge_parent( $link, $parent ) : null;
	}
	if ( 'retired' === $kind || ! $link ) {
		return dead_end( 410, $link, $source );
	}
	if ( 'qr' === $kind ) {
		$source = SOURCE_QR;
	}
	$result = resolve(
		$link,
		new \DateTimeImmutable( 'now', wp_timezone() ),
		__NAMESPACE__ . '\\target_url',
		__NAMESPACE__ . '\\target_published'
	);
	if ( 302 !== $result['status'] ) {
		return dead_end( $result['status'], $link, $source );
	}
	// The link check found this target unreachable: the link's own fallback, otherwise the
	// default target of the site, otherwise the 404 page.
	if ( ! empty( site_settings()['skip_broken'] ) && target_broken( $link, $result['target'] ) ) {
		$fallback = $link['fallback'] ?? '';
		$url      = '' !== $fallback && $fallback !== $result['target'] && ! target_broken( $link, $fallback ) ? target_url( $fallback ) : null;
		if ( ! $url ) {
			return dead_end( 404, $link, $source );
		}
		$result = array(
			'status' => 302,
			'url'    => $url,
			'state'  => 'unreachable',
			'target' => $fallback,
		);
	}
	// Only real uses count – not the way to the fallback page of an expired link.
	$used = 'default' === $result['state'] || 0 === strpos( $result['state'], 'rule-' );
	go_to( $result['url'], $link, $source, $used );
	return 0;
}

/**
 * A link that does not lead anywhere right now: the site's default target, or 404/410.
 *
 * @param int        $status 404 or 410.
 * @param array|null $link   Link.
 * @param int        $source Source.
 * @return int Status (only when not redirected).
 */
function dead_end( $status, $link, $source ) {
	$default = default_target_url( 'inactive' );
	if ( $default ) {
		go_to( $default, $link, $source, false );
	}
	$GLOBALS['clean_short_links_status'] = $status;
	nocache_headers();
	return $status;
}

/**
 * Sends the visitor on. Never returns.
 *
 * @param string     $url    Target URL.
 * @param array|null $link   Link.
 * @param int        $source Source.
 * @param bool       $count  Count the visit.
 */
function go_to( $url, $link, $source, $count ) {
	if ( $count && $link ) {
		count_visit( $link, $source );
	}
	if ( ! empty( site_settings()['pass_query'] ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameters are only passed on to the target.
		$url = add_query_arg( map_deep( wp_unslash( $_GET ), 'rawurlencode' ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	/**
	 * Filters the address a short link leads to right now.
	 *
	 * @param string     $url    Target URL.
	 * @param array|null $link   The link, null for an unknown short address.
	 * @param int        $source 0 link, 1 QR code, 2 short form on the main address.
	 */
	$url = apply_filters( 'clean_short_links_redirect', $url, $link, $source );
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	// Targets may be on other hosts by design, so this is not wp_safe_redirect().
	wp_redirect( $url, 302, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}

/**
 * Counts a visit – without IP address, cookie or user ID.
 *
 * @param array $link   Link.
 * @param int   $source Source.
 */
function count_visit( array $link, $source ) {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
	$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( 'GET' !== $method || is_bot( $ua ) ) {
		return;
	}
	if ( empty( site_settings()['count_editors'] ) && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return;
	}
	$ref = isset( $_SERVER['HTTP_REFERER'] ) ? referrer_host( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) ) : '';
	record_click( $link['id'], $source, device_class( $ua ), $ref );
	add_click( $link['id'] );
}
