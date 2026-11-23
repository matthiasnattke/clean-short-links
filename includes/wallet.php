<?php
/**
 * Wallet cards for short links (with Clean Mobile Connect): card references and the
 * decision what the wallet address of a link shows. This part is pure.
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
