<?php
/**
 * Link checker: does each short link answer, and can its targets still be reached?
 *
 * Content of this site is checked directly (published, not deleted). Other addresses get
 * a HEAD request – with a small GET as fallback for servers that refuse HEAD. Requests go
 * through wp_safe_remote_*(), so local and private network addresses are never contacted.
 * Nothing but the request itself is sent.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies an HTTP answer.
 *
 * @param int  $code     HTTP status, 0 if there was none.
 * @param bool $is_error The request failed (DNS, timeout, TLS).
 * @return string ok, warning or broken.
 */
function classify_response( $code, $is_error = false ) {
	$code = (int) $code;
	if ( $is_error || 0 === $code ) {
		return 'broken';
	}
	if ( $code >= 200 && $code < 400 ) {
		return 'ok';
	}
	// Reachable but locked, rate-limited or refusing robots – worth a look, not necessarily broken.
	if ( in_array( $code, array( 401, 402, 403, 406, 429, 451, 503 ), true ) ) {
		return 'warning';
	}
	return 'broken';
}

/**
 * The worse of two results.
 *
 * @param string $a Result.
 * @param string $b Result.
 * @return string
 */
function worse( $a, $b ) {
	$rank = array(
		''        => 0,
		'ok'      => 1,
		'warning' => 2,
		'broken'  => 3,
	);
	return ( $rank[ $b ] ?? 0 ) > ( $rank[ $a ] ?? 0 ) ? $b : $a;
}

/**
 * Requests an address and returns status and HTTP code.
 *
 * @param string $url URL.
 * @return array{status:string,code:int,message:string}
 */
function check_url( $url ) {
	$args = array(
		'timeout'     => 10,
		'redirection' => 5,
		'user-agent'  => 'Clean Short Links link check (' . home_url( '/' ) . ')',
	);
	$res  = wp_safe_remote_head( $url, $args ); // Link check: the plugin's own feature, see readme.
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	if ( is_wp_error( $res ) || in_array( $code, array( 400, 403, 404, 405, 501 ), true ) ) {
		// Some servers do not answer HEAD properly – ask once more with a small GET.
		$res  = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 4096 ) ); // Link check: the plugin's own feature, see readme.
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}
	return array(
		'status'  => classify_response( $code, is_wp_error( $res ) ),
		'code'    => $code,
		'message' => is_wp_error( $res ) ? $res->get_error_message() : '',
	);
}

/**
 * Checks one target.
 *
 * @param string $target Target.
 * @return array{status:string,code:int,message:string}
 */
function check_target( $target ) {
	if ( 'home' === $target ) {
		return array(
			'status'  => 'ok',
			'code'    => 0,
			'message' => '',
		);
	}
	$link_id = target_link_id( $target );
	if ( $link_id ) {
		$link = link_by_id( $link_id );
		return array(
			'status'  => $link ? ( 'broken' === $link['check']['status'] ? 'warning' : 'ok' ) : 'broken',
			'code'    => 0,
			'message' => $link ? ( 'broken' === $link['check']['status'] ? __( 'The short link it leads to has a problem.', 'clean-short-links' ) : '' ) : __( 'The short link it leads to no longer exists.', 'clean-short-links' ),
		);
	}
	$post_id = target_post_id( $target );
	if ( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'trash' === $post->post_status ) {
			$message = __( 'The content was deleted.', 'clean-short-links' );
		} elseif ( 'publish' !== $post->post_status ) {
			$message = 'future' === $post->post_status ? '' : __( 'The content is not published.', 'clean-short-links' );
		} else {
			$message = '';
		}
		return array(
			'status'  => '' === $message ? 'ok' : 'broken',
			'code'    => 0,
			'message' => $message,
		);
	}
	return check_url( $target );
}

/**
 * Checks a short link: its own answer and every target it may lead to.
 *
 * @param array $link Main link.
 * @return string Overall result.
 */
function check_link( array $link ) {
	$items   = array();
	$overall = 'ok';
	$targets = array( 'main' => $link['target'] );
	foreach ( $link['rules'] as $i => $rule ) {
		/* translators: %d: number of the rule */
		$targets[ sprintf( __( 'Rule %d', 'clean-short-links' ), $i + 1 ) ] = $rule['target'];
	}
	if ( '' !== $link['fallback'] ) {
		$targets['fallback'] = $link['fallback'];
	}
	$seen = array();
	foreach ( $targets as $role => $target ) {
		if ( isset( $seen[ $target ] ) ) {
			continue;
		}
		$seen[ $target ] = true;
		$r               = check_target( $target );
		$items[]         = array(
			'role'    => $role,
			'target'  => $target,
			'status'  => $r['status'],
			'code'    => $r['code'],
			'message' => $r['message'],
		);
		$overall         = worse( $overall, $r['status'] );
	}

	// The short link itself: does the site answer the way it should right now?
	$expected = resolve( $link, new \DateTimeImmutable( 'now', wp_timezone() ), __NAMESPACE__ . '\\target_url', __NAMESPACE__ . '\\target_published' );
	$res      = wp_safe_remote_head( // Link check: the plugin's own feature, see readme.
		short_urls( $link )['link'],
		array(
			'timeout'     => 10,
			'redirection' => 0,
			'user-agent'  => 'Clean Short Links link check (' . home_url( '/' ) . ')',
		)
	);
	if ( ! is_wp_error( $res ) ) {
		$code = (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code === (int) $expected['status'] || ( 302 === (int) $expected['status'] && in_array( $code, array( 301, 302, 303, 307 ), true ) );
		/* translators: 1: HTTP status received, 2: HTTP status expected */
		$items[] = array(
			'role'    => 'self',
			'target'  => short_urls( $link )['link'],
			'status'  => $ok ? 'ok' : 'warning',
			'code'    => $code,
			'message' => $ok ? '' : sprintf( __( 'Answers with %1$d instead of %2$d – maybe a cache or another plugin is in the way.', 'clean-short-links' ), $code, $expected['status'] ),
		);
		$overall = worse( $overall, $ok ? 'ok' : 'warning' );
	}
	save_check( $link['id'], $overall, $items );
	return $overall;
}

/*
 * Once a day the links checked longest ago are checked – 25 per run, so large sites are
 * spread over several days and no single request takes long.
 */
add_action(
	'clean_short_links_check',
	function () {
		if ( empty( site_settings()['auto_check'] ) ) {
			return;
		}
		foreach ( links_to_check( 25 ) as $link ) {
			check_link( $link );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( (int) get_option( 'clean_short_links_db' ) === DB_VERSION && ! wp_next_scheduled( 'clean_short_links_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'clean_short_links_check' );
		}
	},
	20
);
