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
			return; // Unknown – WordPress shows its normal 404 page.
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
 * Redirects or prepares the dead-link page.
 *
 * @param array $found  Result of find_slug().
 * @param int   $source Where the visit came from.
 * @return int 0 when redirected (never returns then), otherwise the status of the dead link.
 */
function handle( array $found, $source ) {
	list( $link, $kind ) = $found;
	if ( 'retired' === $kind || ! $link ) {
		$GLOBALS['clean_short_links_status'] = 410;
		nocache_headers();
		return 410;
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
		$GLOBALS['clean_short_links_status'] = $result['status'];
		nocache_headers();
		return $result['status'];
	}
	count_visit( $link, $source );
	$url = $result['url'];
	if ( ! empty( site_settings()['pass_query'] ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameters are only passed on to the target.
		$url = add_query_arg( map_deep( wp_unslash( $_GET ), 'rawurlencode' ), $url ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	/**
	 * Filters the address a short link leads to right now.
	 *
	 * @param string $url    Target URL.
	 * @param array  $link   The link.
	 * @param int    $source 0 link, 1 QR code, 2 short form on the main address.
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
}
