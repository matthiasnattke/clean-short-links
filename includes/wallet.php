<?php
/**
 * Wallet cards for short links (with Clean Mobile Connect): card references, the
 * decision what the wallet address of a link shows (pure), and the answer to /w/ requests.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/** The owner name under which this plugin registers cards with Clean Mobile Connect. */
const WALLET_OWNER = 'csl';

/**
 * The card reference of a link or one of its locations.
 *
 * @param int    $link_id Link ID.
 * @param string $place   Location key, '' for the link itself.
 * @return string E.g. "12" or "12:home".
 */
function wallet_ref( $link_id, $place = '' ) {
	return (string) (int) $link_id . ( '' !== $place ? ':' . $place : '' );
}

/**
 * Reads a card reference.
 *
 * @param string $ref Reference.
 * @return array{link_id:int,place:string}|null
 */
function parse_wallet_ref( $ref ) {
	if ( ! preg_match( '/^([1-9][0-9]*)(?::([a-z0-9]+(?:-[a-z0-9]+)*))?\z/', (string) $ref, $m ) ) {
		return null;
	}
	return array(
		'link_id' => (int) $m[1],
		'place'   => $m[2] ?? '',
	);
}

/**
 * All card references of a link: its own plus one per location.
 *
 * @param array $link Link.
 * @return string[]
 */
function wallet_refs_of( array $link ) {
	$id   = (int) ( $link['id'] ?? 0 );
	$refs = array( wallet_ref( $id ) );
	foreach ( (array) ( $link['meta']['places'] ?? array() ) as $pl ) {
		if ( ! empty( $pl['key'] ) ) {
			$refs[] = wallet_ref( $id, $pl['key'] );
		}
	}
	return $refs;
}

/**
 * What a wallet address points at.
 *
 * @param array|null $found Result of find_slug(): array( link|null, kind ).
 * @param string     $slug  The requested short address.
 * @return array{link:array|null,place:string,status:int}|null Null: no cards (invitations). Status 404 means the address is unknown: the caller follows the unknown short address path; 410 means retired.
 */
function wallet_target( $found, $slug ) {
	if ( ! $found ) {
		return array(
			'link'   => null,
			'place'  => '',
			'status' => 404,
		);
	}
	list( $link, $kind ) = $found;
	if ( ! $link || 'retired' === $kind ) {
		return array(
			'link'   => null,
			'place'  => '',
			'status' => 410,
		);
	}
	if ( ! empty( $link['parent_id'] ) ) {
		return null;
	}
	$place  = '';
	$prefix = (string) ( $link['slug'] ?? '' ) . '-';
	if ( 'place' === $kind && 0 === strpos( $slug, $prefix ) ) {
		$place = substr( $slug, strlen( $prefix ) );
	}
	return array(
		'link'   => $link,
		'place'  => $place,
		'status' => 200,
	);
}

/**
 * What the wallet address shows: the card page, or a dead end like the short link itself.
 *
 * @param bool $has_card                Is there a card for this reference?
 * @param int  $resolve_status          Status the link would answer (302 when it works).
 * @param bool $broken_without_fallback Is the target broken and without a fallback?
 * @return string no_card, dead or page.
 */
function wallet_decision( $has_card, $resolve_status, $broken_without_fallback ) {
	if ( ! $has_card ) {
		return 'no_card';
	}
	if ( $resolve_status >= 400 || $broken_without_fallback ) {
		return 'dead';
	}
	return 'page';
}

/**
 * Does the link lead only to its fallback right now (not started, expired, used up, no
 * rule fits)? Then the wallet address takes that way too and shows no card.
 *
 * @param array $result Result of resolve().
 * @return bool
 */
function wallet_fallback_only( array $result ) {
	$state = (string) ( $result['state'] ?? '' );
	return 302 === (int) ( $result['status'] ?? 0 ) && 'default' !== $state && 0 !== strpos( $state, 'rule-' );
}

/**
 * Scheme and host of the request, for addresses on the host the visitor asked for.
 *
 * @param string $host     The Host header.
 * @param bool   $ssl      Is the request over HTTPS?
 * @param string $fallback Origin used when the header is not a plain host (with optional port).
 * @return string E.g. "https://example.com".
 */
function wallet_origin( $host, $ssl, $fallback ) {
	$host = strtolower( (string) $host );
	if ( ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?\z/', $host ) ) {
		return $fallback;
	}
	return ( $ssl ? 'https' : 'http' ) . '://' . $host;
}

/**
 * The addresses the guest page needs, on the requested host.
 *
 * @param string $origin      Scheme and host (wallet_origin()).
 * @param string $path        Request path: …/<wallet prefix>/<slug>.
 * @param string $slug        The normalized short address.
 * @param string $link_prefix Link prefix of the short address, '' where short links have none.
 * @return array{page:string,pass:string,back:string} The /w/ address, its ?pkpass download and the short address.
 */
function wallet_urls( $origin, $path, $slug, $link_prefix ) {
	$parts  = array_values( array_filter( explode( '/', strtolower( rawurldecode( (string) $path ) ) ), 'strlen' ) );
	$wallet = $parts[ count( $parts ) - 2 ] ?? 'w';
	$base   = $origin . '/' . implode( '/', array_slice( $parts, 0, max( 0, count( $parts ) - 2 ) ) );
	$base   = rtrim( $base, '/' ) . '/';
	$page   = $base . $wallet . '/' . $slug;
	return array(
		'page' => $page,
		'pass' => $page . '?pkpass',
		'back' => $base . ( '' !== $link_prefix ? $link_prefix . '/' : '' ) . $slug,
	);
}

/**
 * Answers a wallet address (/w/<slug>): the guest page of the card, its .pkpass download
 * (?pkpass), or the link's own dead end. Never counts a visit and never claims a click.
 *
 * @param array  $found Result of find_slug() (or of a route): array( link|null, kind ).
 * @param string $slug  The requested short address.
 * @param bool   $fast  On the fast way: a dead end without a default target is left to the normal way.
 * @return int 0 when answered (never returns then), otherwise the dead-end status, -1 on the fast way.
 */
function handle_wallet( array $found, string $slug, bool $fast ): int {
	$target = wallet_target( $found, $slug );
	if ( null === $target || 200 !== $target['status'] ) {
		// Invitations have no cards; retired addresses are gone.
		$status = null === $target ? 404 : $target['status'];
		return (int) dead_end( $status, null, SOURCE_WALLET_ADD, $fast );
	}
	$link = $target['link'];
	$card = wallet_enabled() && function_exists( 'clean_mobile_connect_wallet_card' )
		? \clean_mobile_connect_wallet_card( WALLET_OWNER, wallet_ref( $link['id'], $target['place'] ) )
		: null;
	if ( ! $card ) {
		return (int) dead_end( 404, null, SOURCE_WALLET_ADD, $fast );
	}

	$result = resolve(
		$link,
		new \DateTimeImmutable( 'now', wp_timezone() ),
		__NAMESPACE__ . '\\target_url',
		__NAMESPACE__ . '\\target_published'
	);
	if ( wallet_fallback_only( $result ) ) {
		// The link's own way to its fallback page, as from /s/ (not counted).
		go_to( final_url( $result['url'], $link, SOURCE_WALLET_ADD ), $link, SOURCE_WALLET_ADD, false, '', 302, true );
	}
	$status = (int) $result['status'];
	$broken = false;
	if ( 302 === $status && ! empty( site_settings()['skip_broken'] ) && target_broken( $link, $result['target'] ) ) {
		// As in handle(): the link's own fallback still leads somewhere, otherwise 503 or 410.
		$fallback = $link['fallback'] ?? '';
		$url      = '' !== $fallback && $fallback !== $result['target'] && ! target_broken( $link, $fallback ) ? target_url( $fallback ) : null;
		if ( ! $url ) {
			$broken = true;
			$status = 'gone' === unreachable( $link, time(), site_settings()['gone_days'] ) ? 410 : 503;
		}
	}

	$decision = wallet_decision( true, $status, $broken );
	if ( 'page' !== $decision ) {
		return (int) dead_end( $status, $link, SOURCE_WALLET_ADD, $fast );
	}

	$alias = defined( 'CLEAN_SHORT_LINKS_ALIAS' ) && current_host() === CLEAN_SHORT_LINKS_ALIAS;
	$home  = wp_parse_url( home_url( '/' ) );
	$host  = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$urls  = wallet_urls(
		wallet_origin( $host, is_ssl(), ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) ),
		request_path(),
		$slug,
		$alias ? '' : prefixes()[0]
	);
	if ( isset( $_GET['pkpass'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only asks for the card download.
		\clean_mobile_connect_wallet_send_pass( $card );
	} else {
		\clean_mobile_connect_wallet_guest_page(
			$card,
			array(
				'pass_url' => $urls['pass'],
				'page_url' => $urls['page'],
				'back_url' => $urls['back'],
			)
		);
	}
	exit;
}

/**
 * Answers a found short address: wallet addresses go to handle_wallet(), all others to handle().
 *
 * @param array  $found  Result of find_slug() (or of a route).
 * @param int    $source Source.
 * @param string $slug   The requested short address.
 * @param bool   $fast   On the fast way.
 * @return int See handle().
 */
function handle_found( array $found, $source, $slug, $fast = false ) {
	if ( SOURCE_WALLET_ADD === $source ) {
		return handle_wallet( $found, $slug, $fast );
	}
	return handle( $found, $source, $slug, $fast );
}
