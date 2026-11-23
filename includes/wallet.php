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

/**
 * Fills the values a card leaves empty from the short link. Deterministic: no translation, no user.
 *
 * @param array  $data     Stored card data.
 * @param array  $link     Link.
 * @param string $place    Location key, '' for the link itself.
 * @param array  $urls     short_urls() of the link.
 * @param int    $image_id Featured image of the target post, 0 if none.
 * @return array
 */
function wallet_live_data( array $data, array $link, string $place, array $urls, int $image_id ): array {
	$title = '' !== (string) ( $link['title'] ?? '' ) ? (string) $link['title'] : (string) ( $link['slug'] ?? '' );
	$link_url = (string) ( $urls['link'] ?? '' );
	if ( '' !== $place ) {
		$name     = (string) ( $urls['places'][ $place ]['name'] ?? $place );
		$title   .= ' – ' . $name;
		$link_url = (string) ( $urls['places'][ $place ]['link'] ?? $link_url );
	}
	$live = array(
		'title' => $title,
		'image' => $image_id,
		'qr'    => $link_url . '?w',
		'link'  => $link_url,
	);
	foreach ( $live as $key => $value ) {
		if ( empty( $data[ $key ] ) ) {
			$data[ $key ] = $value;
		}
	}
	return $data;
}

/**
 * Live data of a card of this plugin (filter clean_mobile_connect_wallet_card_data).
 *
 * @param mixed $data Stored card data.
 * @param mixed $card Card.
 * @return mixed
 */
function wallet_card_data_filter( $data, $card ) {
	if ( ! is_array( $data ) || ! is_array( $card ) || WALLET_OWNER !== ( $card['owner'] ?? '' ) ) {
		return $data;
	}
	$ref  = parse_wallet_ref( $card['owner_ref'] ?? '' );
	$link = $ref ? link_by_id( $ref['link_id'] ) : null;
	if ( ! $link ) {
		return $data;
	}
	$post  = target_post_id( $link['target'] );
	$image = $post ? (int) get_post_thumbnail_id( $post ) : 0;
	return wallet_live_data( $data, $link, $ref['place'], short_urls( $link ), $image );
}

/**
 * Owner entry for Clean Mobile Connect.
 *
 * @param mixed $owners Owners.
 * @return array
 */
function wallet_owners_filter( $owners ) {
	$owners = is_array( $owners ) ? $owners : array();
	$owners[ WALLET_OWNER ] = array(
		'label' => 'Clean Short Links',
		'file'  => plugin_basename( CLEAN_SHORT_LINKS_FILE ),
	);
	return $owners;
}

/**
 * Picker rows for Connect: the link and each of its locations that has no card yet.
 *
 * @param array    $link     Link.
 * @param array    $urls     short_urls() of the link.
 * @param callable $has_card Receives a reference, returns whether it has a card.
 * @return array[] Rows owner, ref, id, place, label, url.
 */
function wallet_choice_rows( array $link, array $urls, callable $has_card ): array {
	$id    = (int) ( $link['id'] ?? 0 );
	$title = '' !== (string) ( $link['title'] ?? '' ) ? (string) $link['title'] : (string) ( $link['slug'] ?? '' );
	$rows  = array();
	if ( ! $has_card( wallet_ref( $id ) ) ) {
		$rows[] = array(
			'owner' => WALLET_OWNER,
			'ref'   => wallet_ref( $id ),
			'id'    => $id,
			'place' => '',
			'label' => $title,
			'url'   => (string) ( $urls['link'] ?? '' ),
		);
	}
	foreach ( (array) ( $link['meta']['places'] ?? array() ) as $pl ) {
		$key = (string) ( $pl['key'] ?? '' );
		if ( '' === $key || $has_card( wallet_ref( $id, $key ) ) ) {
			continue;
		}
		$name   = (string) ( $urls['places'][ $key ]['name'] ?? ( $pl['name'] ?? $key ) );
		$rows[] = array(
			'owner' => WALLET_OWNER,
			'ref'   => wallet_ref( $id, $key ),
			'id'    => $id,
			'place' => $key,
			'label' => $title . ' – ' . $name,
			'url'   => (string) ( $urls['places'][ $key ]['link'] ?? ( $urls['link'] ?? '' ) ),
		);
	}
	return $rows;
}

/**
 * Short links offered in Connect's "Attach to a short link" box (filter clean_mobile_connect_link_choices).
 *
 * @param mixed $choices Rows so far.
 * @param mixed $search  Search text.
 * @return mixed
 */
function wallet_link_choices_filter( $choices, $search = '' ) {
	$choices = is_array( $choices ) ? $choices : array();
	if ( ! current_user_can( capability() ) || ! function_exists( 'clean_mobile_connect_wallet_card' ) ) {
		return $choices;
	}
	$has_card = static function ( $ref ) {
		return null !== \clean_mobile_connect_wallet_card( WALLET_OWNER, $ref );
	};
	$list = list_links(
		array(
			'search'   => (string) $search,
			'per_page' => 50,
		)
	);
	foreach ( $list['items'] as $link ) {
		foreach ( wallet_choice_rows( $link, short_urls( $link ), $has_card ) as $row ) {
			if ( count( $choices ) >= 100 ) {
				return $choices;
			}
			$choices[] = $row;
		}
	}
	return $choices;
}

/**
 * Whether a card may be attached to an owner object of this plugin (pure part).
 *
 * @param string     $owner Owner key.
 * @param string     $ref   Reference.
 * @param array|null $link  The link behind the reference, null if it does not exist on this site.
 * @param bool       $can   Whether the user may edit short links.
 * @return bool
 */
function wallet_attach_allowed( string $owner, string $ref, ?array $link, bool $can ): bool {
	if ( WALLET_OWNER !== $owner || ! $can || ! $link ) {
		return false;
	}
	$p = parse_wallet_ref( $ref );
	if ( ! $p || (int) $link['id'] !== $p['link_id'] || ! empty( $link['parent_id'] ) ) {
		return false;
	}
	if ( '' === $p['place'] ) {
		return true;
	}
	foreach ( (array) ( $link['meta']['places'] ?? array() ) as $pl ) {
		if ( ( $pl['key'] ?? '' ) === $p['place'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Connect asks whether a stand-alone card may be attached (filter clean_mobile_connect_wallet_can_attach).
 *
 * @param mixed $allowed Answer so far.
 * @param mixed $owner   Owner key.
 * @param mixed $ref     Reference.
 * @return mixed
 */
function wallet_can_attach_filter( $allowed, $owner = '', $ref = '' ) {
	if ( WALLET_OWNER !== $owner ) {
		return $allowed;
	}
	$p    = parse_wallet_ref( (string) $ref );
	$link = $p ? link_by_id( $p['link_id'] ) : null;
	return wallet_attach_allowed( (string) $owner, (string) $ref, $link, current_user_can( capability() ) ) ? true : $allowed;
}

/**
 * The link of a card of this plugin, null for other owners.
 *
 * @param mixed $card Card.
 * @return array|null
 */
function wallet_card_link( $card ) {
	if ( ! is_array( $card ) || WALLET_OWNER !== ( $card['owner'] ?? '' ) ) {
		return null;
	}
	$ref = parse_wallet_ref( $card['owner_ref'] ?? '' );
	return $ref ? link_by_id( $ref['link_id'] ) : null;
}

/**
 * Edit screen of the link behind a card.
 *
 * @param mixed $url  URL.
 * @param mixed $card Card.
 * @return mixed
 */
function wallet_edit_url_filter( $url, $card ) {
	$link = wallet_card_link( $card );
	return $link ? admin_page_url( 'edit', array( 'id' => $link['id'] ) ) . '#csl-sec-wallet' : $url;
}

/**
 * Readable name of the owner of a card.
 *
 * @param mixed $label Label.
 * @param mixed $card  Card.
 * @return mixed
 */
function wallet_label_filter( $label, $card ) {
	$link = wallet_card_link( $card );
	if ( ! $link ) {
		return $label;
	}
	/* translators: %s: title of the short link */
	return sprintf( __( 'Short link: %s', 'clean-short-links' ), '' !== $link['title'] ? $link['title'] : $link['slug'] );
}

/**
 * Who may download cards of this plugin.
 *
 * @param mixed $allowed Allowed.
 * @param mixed $card    Card.
 * @return mixed
 */
function wallet_can_download_filter( $allowed, $card ) {
	return WALLET_OWNER === ( is_array( $card ) ? ( $card['owner'] ?? '' ) : '' ) ? current_user_can( capability() ) : $allowed;
}

/**
 * What a registered card counts as in the statistics.
 *
 * @param array $card Card as passed by Connect.
 * @return array{link_id:int,source:int,ref:string}|null Null: not a card of this plugin.
 */
function wallet_click_args( array $card ) {
	if ( WALLET_OWNER !== ( $card['owner'] ?? '' ) ) {
		return null;
	}
	$p = parse_wallet_ref( (string) ( $card['owner_ref'] ?? '' ) );
	if ( ! $p ) {
		return null;
	}
	return array(
		'link_id' => $p['link_id'],
		'source'  => SOURCE_WALLET_ADD,
		'ref'     => '' !== $p['place'] ? '@p:' . $p['place'] : '',
	);
}

/**
 * A device registered a card: counted as a statistic only, click limits stay untouched.
 *
 * @param mixed $card Card.
 */
function wallet_registered_action( $card ) {
	$args = is_array( $card ) ? wallet_click_args( $card ) : null;
	if ( $args ) {
		record_click( $args['link_id'], $args['source'], DEVICE_MOBILE, $args['ref'] );
	}
}

/**
 * What a change of a link means for its cards.
 *
 * @param array|null $old Link before the change, null for a new link.
 * @param array      $new Link after the change.
 * @return array{touch:string[],end:string[]}
 */
function wallet_sync_plan( ?array $old, array $new ): array {
	$plan = array(
		'touch' => array(),
		'end'   => array(),
	);
	if ( null === $old ) {
		return $plan;
	}
	$id        = (int) ( $new['id'] ?? $old['id'] ?? 0 );
	$names_old = array();
	foreach ( (array) ( $old['meta']['places'] ?? array() ) as $pl ) {
		if ( ! empty( $pl['key'] ) ) {
			$names_old[ $pl['key'] ] = (string) ( $pl['name'] ?? '' );
		}
	}
	$names_new = array();
	foreach ( (array) ( $new['meta']['places'] ?? array() ) as $pl ) {
		if ( ! empty( $pl['key'] ) ) {
			$names_new[ $pl['key'] ] = (string) ( $pl['name'] ?? '' );
		}
	}
	$wide = ( $old['title'] ?? '' ) !== ( $new['title'] ?? '' )
		|| ( $old['target'] ?? '' ) !== ( $new['target'] ?? '' )
		|| ( $old['slug'] ?? '' ) !== ( $new['slug'] ?? '' )
		|| (array) ( $old['meta']['hosts'] ?? array() ) !== (array) ( $new['meta']['hosts'] ?? array() );
	if ( $wide ) {
		$plan['touch'][] = wallet_ref( $id );
	}
	foreach ( $names_new as $key => $name ) {
		if ( $wide || ( isset( $names_old[ $key ] ) && $names_old[ $key ] !== $name ) ) {
			$plan['touch'][] = wallet_ref( $id, $key );
		}
	}
	foreach ( array_keys( $names_old ) as $key ) {
		if ( ! isset( $names_new[ $key ] ) ) {
			$plan['end'][] = wallet_ref( $id, $key );
		}
	}
	return $plan;
}

/**
 * Tells Clean Mobile Connect that the live values of cards changed.
 *
 * @param string[] $refs Card references.
 */
function wallet_touch_refs( array $refs ): void {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_touch' ) ) {
		return;
	}
	foreach ( $refs as $ref ) {
		\clean_mobile_connect_wallet_touch( WALLET_OWNER, $ref );
	}
}

/**
 * Ends cards.
 *
 * @param string[] $refs Card references.
 */
function wallet_end_refs( array $refs ): void {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_end_card' ) ) {
		return;
	}
	foreach ( $refs as $ref ) {
		\clean_mobile_connect_wallet_end_card( WALLET_OWNER, $ref );
	}
}

/**
 * A link was saved: update or end its cards.
 *
 * @param int        $id  Link ID.
 * @param array|null $old Link before the change.
 */
function wallet_link_saved( $id, $old ) {
	if ( ! wallet_enabled() || ! is_array( $old ) ) {
		return;
	}
	$link = link_by_id( (int) $id );
	if ( ! $link || ! empty( $link['parent_id'] ) ) {
		return;
	}
	$plan = wallet_sync_plan( $old, $link );
	wallet_touch_refs( $plan['touch'] );
	wallet_end_refs( $plan['end'] );
}

/**
 * A link is deleted: end all its cards (invitations have none).
 *
 * @param array $link Link.
 */
function wallet_link_deleted( $link ) {
	if ( ! wallet_enabled() || ! is_array( $link ) || ! empty( $link['parent_id'] ) ) {
		return;
	}
	wallet_end_refs( wallet_refs_of( $link ) );
}

/**
 * The featured image of a post changed: touch the cards of its links.
 *
 * @param mixed $unused  Meta ID(s).
 * @param mixed $post_id Post ID.
 * @param mixed $key     Meta key.
 */
function wallet_thumbnail_changed( $unused, $post_id = 0, $key = '' ) {
	if ( '_thumbnail_id' !== $key || ! wallet_enabled() ) {
		return;
	}
	foreach ( links_for_post( (int) $post_id ) as $id ) {
		$link = link_by_id( $id );
		if ( $link ) {
			wallet_touch_refs( wallet_refs_of( $link ) );
		}
	}
}

/**
 * Touches every card of this site, 200 links at a time.
 *
 * @param int $page Page of the link list.
 */
function wallet_touch_all( $page = 1 ) {
	if ( ! wallet_enabled() ) {
		return;
	}
	$page = max( 1, (int) $page );
	$list = list_links(
		array(
			'per_page' => 200,
			'paged'    => $page,
		)
	);
	foreach ( $list['items'] as $link ) {
		wallet_touch_refs( wallet_refs_of( $link ) );
	}
	if ( $page * 200 < (int) $list['total'] ) {
		wp_schedule_single_event( time() + 5, 'clean_short_links_wallet_touch_all', array( $page + 1 ) );
	}
}

/**
 * Schedules a touch of all cards (addresses on the cards changed).
 *
 * @param bool $network On every site that serves short links instead of only this one.
 */
function schedule_wallet_touch( bool $network ): void {
	$one = static function () {
		if ( wallet_enabled() && ! wp_next_scheduled( 'clean_short_links_wallet_touch_all' ) ) {
			wp_schedule_single_event( time() + 5, 'clean_short_links_wallet_touch_all' );
		}
	};
	if ( ! $network || ! is_multisite() ) {
		$one();
		return;
	}
	foreach ( get_sites(
		array(
			'number'   => 1000,
			'deleted'  => 0,
			'archived' => 0,
			'fields'   => 'ids',
		)
	) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		if ( (int) get_option( 'clean_short_links_db' ) === DB_VERSION ) {
			$one();
		}
		restore_current_blog();
	}
}

add_action( 'clean_short_links_link_saved', __NAMESPACE__ . '\\wallet_link_saved', 10, 2 );
add_action( 'clean_short_links_link_deleted', __NAMESPACE__ . '\\wallet_link_deleted' );
add_action( 'added_post_meta', __NAMESPACE__ . '\\wallet_thumbnail_changed', 10, 4 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\wallet_thumbnail_changed', 10, 4 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\wallet_thumbnail_changed', 10, 4 );
add_action( 'clean_short_links_wallet_touch_all', __NAMESPACE__ . '\\wallet_touch_all' );

add_filter( 'clean_mobile_connect_wallet_card_data', __NAMESPACE__ . '\\wallet_card_data_filter', 10, 2 );
add_filter( 'clean_mobile_connect_link_choices', __NAMESPACE__ . '\\wallet_link_choices_filter', 10, 2 );
add_filter( 'clean_mobile_connect_wallet_can_attach', __NAMESPACE__ . '\\wallet_can_attach_filter', 10, 3 );
add_filter( 'clean_mobile_connect_wallet_owners', __NAMESPACE__ . '\\wallet_owners_filter' );
add_filter( 'clean_mobile_connect_wallet_owner_edit_url', __NAMESPACE__ . '\\wallet_edit_url_filter', 10, 2 );
add_filter( 'clean_mobile_connect_wallet_owner_label', __NAMESPACE__ . '\\wallet_label_filter', 10, 2 );
add_filter( 'clean_mobile_connect_wallet_owner_can_download', __NAMESPACE__ . '\\wallet_can_download_filter', 10, 2 );
add_action( 'clean_mobile_connect_wallet_registered', __NAMESPACE__ . '\\wallet_registered_action' );
