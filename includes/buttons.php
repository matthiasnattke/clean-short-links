<?php
/**
 * Buttons that follow their short link: a button block whose link is a short link of this
 * network is only shown while the link leads to its target. It appears when the link starts
 * and disappears when it ends, is paused, has reached its limit or was retired.
 *
 * Decided when the page is rendered – a page cache keeps the state it saw.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * The short link behind an address, looked up on the site it belongs to.
 *
 * @param string $url Address of a button.
 * @return array{0:array,1:int}|null Found link and kind (as find_slug()) and the site ID, or null.
 */
function link_for_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['path'] ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
		return null;
	}
	$path = (string) $parts['path'];
	$host = strtolower( (string) ( $parts['host'] ?? '' ) );
	$own  = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$blog = get_current_blog_id();
	if ( '' !== $host && $host !== $own ) {
		$blog = 0;
		if ( is_multisite() ) {
			$site = get_site_by_path( $host, $path );
			$blog = $site && strtolower( $site->domain ) === $host && site_serves( $site->blog_id ) ? (int) $site->blog_id : 0;
		}
	}
	if ( $blog ) {
		$switch = get_current_blog_id() !== $blog;
		if ( $switch ) {
			switch_to_blog( $blog );
		}
		$found = null;
		if ( is_short_site() ) {
			list( , $q, $w ) = prefixes();
			$req             = parse_alias_path( $path, $q, $w );
			$found           = $req && SOURCE_WALLET_ADD !== $req[0] ? find_slug( $req[1] ) : null;
		} else {
			list( $p, $q, $w ) = prefixes();
			$req               = parse_path( $path, (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $p, $q, $w );
			if ( $req && SOURCE_ROOT === $req[0] ) {
				// example.com/slug: only a short link if there is no page at that address.
				$found = root_allowed() ? find_slug( $req[1] ) : null;
				if ( $found && ( ( $found[0] && empty( $found[0]['root'] ) ) || url_to_postid( home_url( $path ) ) ) ) {
					$found = null;
				}
			} elseif ( $req && SOURCE_WALLET_ADD !== $req[0] ) {
				$found = find_slug( $req[1] );
			}
		}
		if ( $switch ) {
			restore_current_blog();
		}
		if ( $found ) {
			return array( $found, $blog );
		}
	}
	// A further domain of the network (short domain, subdomain) that carries a route.
	if ( '' !== $host && is_multisite() ) {
		list( , $q, $w ) = prefixes();
		$req             = parse_alias_path( $path, $q, $w );
		$route           = $req && SOURCE_WALLET_ADD !== $req[0] ? find_route( $host, $req[1] ) : null;
		if ( $route && site_serves( $route['blog_id'] ) ) {
			switch_to_blog( $route['blog_id'] );
			$link = link_by_id( $route['link_id'] );
			restore_current_blog();
			return array( array( $link, $route['kind'] ), (int) $route['blog_id'] );
		}
	}
	return null;
}

/**
 * Should a button with this address show right now?
 *
 * @param string $url Address of the button.
 * @return bool|null True or false for a short link, null for any other address.
 */
function button_shows( $url ) {
	static $memo = array();
	if ( array_key_exists( $url, $memo ) ) {
		return $memo[ $url ];
	}
	$hit = link_for_url( $url );
	if ( ! $hit ) {
		$memo[ $url ] = null;
		return null;
	}
	list( $found, $blog ) = $hit;
	list( $link, $kind )  = $found;
	$switch               = get_current_blog_id() !== $blog;
	if ( $switch ) {
		switch_to_blog( $blog );
	}
	$shows = false;
	if ( $link && 'retired' !== $kind && ! empty( $link['parent_id'] ) ) {
		$parent = link_by_id( $link['parent_id'] );
		$link   = $parent ? merge_parent( $link, $parent ) : null;
	}
	if ( $link && 'retired' !== $kind ) {
		$shows = leads_now(
			resolve(
				$link,
				new \DateTimeImmutable( 'now', wp_timezone() ),
				__NAMESPACE__ . '\\target_url',
				__NAMESPACE__ . '\\target_published'
			)
		);
	}
	if ( $switch ) {
		restore_current_blog();
	}
	/**
	 * Filters whether a button to a short link shows.
	 *
	 * @param bool   $shows Whether the link leads to its target right now.
	 * @param string $url   Address of the button.
	 * @param array  $link  The short link (empty for a retired address).
	 */
	$memo[ $url ] = (bool) apply_filters( 'clean_short_links_button_shows', $shows, $url, $link ? $link : array() );
	return $memo[ $url ];
}

add_filter(
	'render_block_core/button',
	function ( $content ) {
		if ( is_admin() || empty( site_settings()['hide_dead_buttons'] ) ) {
			return $content;
		}
		$href = first_href( $content );
		return '' !== $href && false === button_shows( $href ) ? '' : $content;
	}
);

// A row of buttons whose buttons are all hidden leaves no empty gap behind.
add_filter(
	'render_block_core/buttons',
	function ( $content ) {
		if ( is_admin() || empty( site_settings()['hide_dead_buttons'] ) ) {
			return $content;
		}
		return false === stripos( $content, '<a ' ) && false === stripos( $content, '<button' ) ? '' : $content;
	}
);
