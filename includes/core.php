<?php
/**
 * Settings and the pure logic: short addresses, time windows, rules, resolving a link.
 *
 * Nothing in here touches the database directly, so it can be tested without WordPress.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/** Characters for random short addresses – without 0/o, 1/l/i, which are easily confused. */
const RANDOM_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

/** Where a visit came from. */
const SOURCE_LINK = 0;
const SOURCE_QR   = 1;
const SOURCE_ROOT = 2;
const SOURCE_BIO  = 3;
const SOURCE_NFC  = 4;

/** Device class of a visit. */
const DEVICE_DESKTOP = 0;
const DEVICE_MOBILE  = 1;
const DEVICE_TABLET  = 2;

// Operating systems a variant can ask for.
const OS_KEYS = array( 'ios', 'android', 'windows', 'macos', 'linux' );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'clean-short-links', false, dirname( plugin_basename( CLEAN_SHORT_LINKS_FILE ) ) . '/languages' );
	}
);

/* ---------------------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------------------ */

/**
 * Network-wide settings (on a single site they live in the site's options).
 *
 * @return array
 */
function network_defaults() {
	return array(
		'prefix'         => 's',
		'qr_prefix'      => 'q',
		'site_prefix'    => 0,
		'allow_root'     => 1,
		'retention_days' => 730,
		'reserved'       => '',
		'short_sites'    => array(),
		'alias_hosts'    => array(),
		'signup_home'    => 0,
		'referrers'      => 1,
	);
}

/**
 * @return array
 */
function network_settings() {
	$saved = is_multisite() ? get_site_option( 'clean_short_links_network', array() ) : get_option( 'clean_short_links_network', array() );
	return array_merge( network_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * Subdomains without a site of their own that serve short links – only when the sunrise
 * file of the plugin is active (see sunrise.php).
 *
 * @return string[]
 */
function alias_hosts() {
	return defined( 'CLEAN_SHORT_LINKS_SUNRISE' ) && is_multisite() ? (array) network_settings()['alias_hosts'] : array();
}

/**
 * Cleans a list of host names (one per line or an array): lower case, valid, unique, at most 20.
 *
 * @param string|array $in       Raw value.
 * @param string[]     $excluded Hosts that may not be used (domains of real sites).
 * @return string[]
 */
function clean_alias_hosts( $in, array $excluded = array() ) {
	$out = array();
	foreach ( is_array( $in ) ? $in : preg_split( '/[\s,]+/', (string) $in ) as $h ) {
		$h = strtolower( trim( preg_replace( '#^https?://#i', '', trim( (string) $h ) ), '/ ' ) );
		if ( preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h ) && ! in_array( $h, $excluded, true ) && ! in_array( $h, $out, true ) ) {
			$out[] = $h;
		}
	}
	return array_slice( $out, 0, 20 );
}

/**
 * The short address of a request on a subdomain without a site: /slug, /<prefix>/slug or
 * /<qr prefix>/slug.
 *
 * @param string $path      Request path.
 * @param string $qr_prefix QR prefix.
 * @return array{0:int,1:string}|null Source and short address.
 */
function parse_alias_path( $path, $qr_prefix ) {
	$parts = array_values( array_filter( explode( '/', strtolower( rawurldecode( (string) $path ) ) ), 'strlen' ) );
	if ( 1 === count( $parts ) && is_valid_slug( $parts[0] ) ) {
		return array( SOURCE_LINK, $parts[0] );
	}
	if ( 2 === count( $parts ) && is_valid_slug( $parts[1] ) && preg_match( '/^[a-z0-9-]{1,20}$/', $parts[0] ) ) {
		return array( $parts[0] === $qr_prefix ? SOURCE_QR : SOURCE_LINK, $parts[1] );
	}
	return null;
}

/**
 * Settings of the current site.
 *
 * @return array
 */
function site_defaults() {
	return array(
		'prefix'        => '',
		'qr_prefix'     => '',
		'auto_types'    => array(),
		'post_kind'     => 'title',
		'other_kind'    => 'random',
		'root_default'  => 1,
		'count_editors' => 0,
		'pass_query'    => 1,
		'default_mode'  => '',
		'default_url'   => '',
		'default_unknown'  => 1,
		'default_inactive' => 1,
		'auto_check'    => 1,
		'skip_broken'   => 1,
		'gone_days'     => 30,
		'utm_default'   => 0,
		'bio_enabled'   => 0,
		'bio_slug'      => 'links',
	);
}

/**
 * @return array
 */
function site_settings() {
	$saved = get_option( 'clean_short_links_site', array() );
	return array_merge( site_defaults(), is_array( $saved ) ? $saved : array() );
}

/**
 * The two path prefixes in effect on this site.
 *
 * @return array{0:string,1:string} Link prefix and QR prefix.
 */
function prefixes() {
	$n = network_settings();
	$s = site_settings();
	$p = $n['prefix'];
	$q = $n['qr_prefix'];
	if ( ! empty( $n['site_prefix'] ) || ! is_multisite() ) {
		$p = '' !== $s['prefix'] ? $s['prefix'] : $p;
		$q = '' !== $s['qr_prefix'] ? $s['qr_prefix'] : $q;
	}
	return array( $p, $q );
}

/**
 * Is the short form on the main address (example.com/slug) allowed on this site?
 *
 * @return bool
 */
function root_allowed() {
	return ! empty( network_settings()['allow_root'] );
}

/**
 * Cleans a path prefix: lowercase letters, digits and dashes, no slashes.
 *
 * @param string $value Raw value.
 * @return string
 */
function clean_prefix( $value ) {
	$value = strtolower( trim( (string) $value, " /\t\n\r" ) );
	return preg_match( '/^[a-z0-9][a-z0-9-]{0,19}$/', $value ) ? $value : '';
}

/* ---------------------------------------------------------------------------
 * Short addresses
 * ------------------------------------------------------------------------ */

/**
 * Words that can never be a short address on the main address.
 *
 * @return string[]
 */
function reserved_words() {
	$words = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-signup', 'wp-activate', 'wp-cron', 'admin', 'login', 'logout', 'dashboard', 'feed', 'rss', 'comments', 'page', 'author', 'category', 'tag', 'search', 'xmlrpc', 'robots', 'sitemap', 'favicon', 'embed', 'trackback', 'files' );
	foreach ( preg_split( '/[\s,]+/', (string) network_settings()['reserved'] ) as $w ) {
		$w = normalize_slug( $w );
		if ( '' !== $w ) {
			$words[] = $w;
		}
	}
	list( $p, $q ) = prefixes();
	$words[]       = $p;
	$words[]       = $q;
	return array_values( array_unique( $words ) );
}

/**
 * Normalizes a short address the way it is stored and looked up: lowercase, umlauts
 * transliterated, spaces and underscores to dashes, everything else removed.
 *
 * @param string $value Raw value.
 * @return string
 */
function normalize_slug( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = strtr(
		$value,
		array(
			'ä' => 'ae',
			'ö' => 'oe',
			'ü' => 'ue',
			'Ä' => 'ae',
			'Ö' => 'oe',
			'Ü' => 'ue',
			'ß' => 'ss',
			'é' => 'e',
			'è' => 'e',
			'á' => 'a',
			'à' => 'a',
			'ç' => 'c',
			'ñ' => 'n',
		)
	);
	$value = preg_replace( '/[\s_.\/]+/', '-', $value );
	$value = preg_replace( '/[^a-z0-9-]/', '', $value );
	$value = preg_replace( '/-{2,}/', '-', $value );
	return substr( trim( $value, '-' ), 0, 64 );
}

/**
 * Is this a well-formed short address?
 *
 * @param string $slug Normalized short address.
 * @return bool
 */
function is_valid_slug( $slug ) {
	return 1 === preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', (string) $slug );
}

/**
 * A random short address.
 *
 * @param int $length Number of characters.
 * @return string
 */
function random_slug( $length = 5 ) {
	$out = '';
	$max = strlen( RANDOM_ALPHABET ) - 1;
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= RANDOM_ALPHABET[ random_int( 0, $max ) ];
	}
	return $out;
}

/**
 * Word lists for memorable short addresses – plain ASCII so they fit the compact QR mode.
 *
 * @param string $locale Locale, e.g. de_DE.
 * @return array{0:string[],1:string[]} Adjectives and nouns.
 */
function word_lists( $locale ) {
	if ( 0 === strpos( (string) $locale, 'de' ) ) {
		return array(
			array( 'blaue', 'rote', 'gelbe', 'gruene', 'bunte', 'stille', 'helle', 'warme', 'frische', 'kleine', 'grosse', 'flinke', 'kluge', 'leise', 'lustige', 'sanfte', 'wilde', 'weite', 'runde', 'neue', 'alte', 'freie', 'goldene', 'klare' ),
			array( 'couch', 'wolke', 'sonne', 'welle', 'tasse', 'kerze', 'lampe', 'feder', 'insel', 'bruecke', 'muehle', 'linde', 'quelle', 'wiese', 'kiste', 'fahne', 'glocke', 'trommel', 'laterne', 'eule', 'katze', 'ente', 'biene', 'rakete' ),
		);
	}
	return array(
		array( 'blue', 'red', 'green', 'golden', 'quiet', 'bright', 'warm', 'fresh', 'little', 'big', 'swift', 'clever', 'gentle', 'happy', 'wild', 'wide', 'round', 'new', 'old', 'free', 'clear', 'sunny', 'brave', 'calm' ),
		array( 'couch', 'cloud', 'sun', 'wave', 'cup', 'candle', 'lamp', 'feather', 'island', 'bridge', 'mill', 'river', 'meadow', 'box', 'flag', 'bell', 'drum', 'lantern', 'owl', 'cat', 'duck', 'bee', 'rocket', 'kite' ),
	);
}

/**
 * A memorable short address like "blaue-couch".
 *
 * @param string $locale Locale.
 * @return string
 */
function memorable_slug( $locale ) {
	list( $adj, $noun ) = word_lists( $locale );
	return $adj[ random_int( 0, count( $adj ) - 1 ) ] . '-' . $noun[ random_int( 0, count( $noun ) - 1 ) ];
}

/**
 * A short address derived from a title: the first words, at most 30 characters.
 *
 * @param string $title Title.
 * @return string
 */
function title_slug( $title ) {
	$slug  = normalize_slug( $title );
	$words = array_values( array_filter( explode( '-', $slug ), 'strlen' ) );
	$out   = '';
	foreach ( $words as $w ) {
		$next = '' === $out ? $w : $out . '-' . $w;
		if ( strlen( $next ) > 30 && '' !== $out ) {
			break;
		}
		$out = $next;
		if ( substr_count( $out, '-' ) >= 3 ) {
			break;
		}
	}
	return substr( $out, 0, 30 );
}

/**
 * Splits a request path into the kind of short link it addresses.
 *
 * @param string $path      Request path without query string, e.g. "/s/blaue-couch/".
 * @param string $home_path Path of the site's home URL, e.g. "/" or "/blog/".
 * @param string $prefix    Link prefix.
 * @param string $qr_prefix QR prefix.
 * @return array{0:int,1:string}|null Source and short address, or null.
 */
function parse_path( $path, $home_path, $prefix, $qr_prefix ) {
	$path      = strtolower( rawurldecode( (string) $path ) );
	$home_path = '/' . trim( strtolower( (string) $home_path ), '/' ) . '/';
	$home_path = '//' === $home_path ? '/' : $home_path;
	if ( 0 !== strpos( $path . '/', $home_path ) ) {
		return null;
	}
	$rest  = trim( substr( $path, strlen( $home_path ) ), '/' );
	$parts = '' === $rest ? array() : explode( '/', $rest );
	if ( 2 === count( $parts ) ) {
		$slug = normalize_slug( $parts[1] );
		if ( ! is_valid_slug( $slug ) ) {
			return null;
		}
		if ( $parts[0] === $prefix ) {
			return array( SOURCE_LINK, $slug );
		}
		if ( $parts[0] === $qr_prefix ) {
			return array( SOURCE_QR, $slug );
		}
	}
	if ( 1 === count( $parts ) && is_valid_slug( $parts[0] ) ) {
		return array( SOURCE_ROOT, $parts[0] );
	}
	return null;
}

/* ---------------------------------------------------------------------------
 * Targets, time windows and rules
 * ------------------------------------------------------------------------ */

/**
 * Cleans a target: "post:123" for content of this site, "link:45" for another short link,
 * "home" for the home page, otherwise an http(s) URL.
 *
 * @param string $value Raw value.
 * @return string Empty if invalid.
 */
function clean_target( $value ) {
	$value = trim( (string) $value );
	if ( 'home' === $value ) {
		return 'home';
	}
	if ( preg_match( '/^(post|link):(\d+)$/', $value, $m ) ) {
		return (int) $m[2] > 0 ? $m[1] . ':' . (int) $m[2] : '';
	}
	if ( preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $value ) ) {
		return esc_url_raw( $value );
	}
	return '';
}

/**
 * Post ID of a target, 0 for URLs.
 *
 * @param string $target Target.
 * @return int
 */
function target_post_id( $target ) {
	return preg_match( '/^post:(\d+)$/', (string) $target, $m ) ? (int) $m[1] : 0;
}

/**
 * Short link ID of a target that points to another short link, otherwise 0.
 *
 * @param string $target Target.
 * @return int
 */
function target_link_id( $target ) {
	return preg_match( '/^link:(\d+)$/', (string) $target, $m ) ? (int) $m[1] : 0;
}

/**
 * The link an invitation follows: target, rules, time window and fallback come from the
 * main link; the invitation keeps its own status, click limit, counter and – if set –
 * its own expiry.
 *
 * @param array $invite Invitation link.
 * @param array $parent Main link.
 * @return array Effective link.
 */
function merge_parent( array $invite, array $parent ) {
	$link = $parent;
	foreach ( array( 'id', 'slug', 'qr_slug', 'title', 'parent_id', 'max_clicks', 'clicks', 'root' ) as $key ) {
		$link[ $key ] = $invite[ $key ] ?? null;
	}
	$link['status'] = 'active' === ( $invite['status'] ?? 'active' ) && 'active' === ( $parent['status'] ?? 'active' ) ? 'active' : 'paused';
	if ( ! empty( $invite['ends_at'] ) && ( empty( $parent['ends_at'] ) || (int) $invite['ends_at'] < (int) $parent['ends_at'] ) ) {
		$link['ends_at'] = $invite['ends_at'];
	}
	return $link;
}

/**
 * Did the last link check find this target unreachable?
 *
 * @param array  $link   Link with its check results.
 * @param string $target Target.
 * @return bool
 */
function target_broken( array $link, $target ) {
	foreach ( (array) ( $link['check']['items'] ?? array() ) as $item ) {
		if ( ( $item['target'] ?? '' ) === $target && 'self' !== ( $item['role'] ?? '' ) ) {
			return 'broken' === ( $item['status'] ?? '' );
		}
	}
	return false;
}

/**
 * How unreachable is a link's target? Temporarily at first, permanently once it has been
 * unreachable without a break for the given number of days.
 *
 * @param array $link Link with its check results.
 * @param int   $now  Unix time.
 * @param int   $days Days after which "temporarily" becomes "permanently".
 * @return string '' (reachable or unknown), temporary or gone.
 */
function unreachable( array $link, $now, $days = 30 ) {
	$since = (int) ( $link['check']['since'] ?? 0 );
	if ( ! $since ) {
		return '';
	}
	return $now - $since >= max( 1, (int) $days ) * DAY_IN_SECONDS ? 'gone' : 'temporary';
}

/**
 * Channels a short link can get an address of its own for. The key becomes part of the
 * address (blue-couch-ig) and the utm_source.
 *
 * @return array<string,string> Key => name.
 */
function channels() {
	$list = array(
		'ig'    => 'Instagram',
		'fb'    => 'Facebook',
		'wa'    => 'WhatsApp',
		'tg'    => 'Telegram',
		'masto' => 'Mastodon',
		'li'    => 'LinkedIn',
		'yt'    => 'YouTube',
		'tt'    => 'TikTok',
		'nl'    => __( 'Newsletter', 'clean-short-links' ),
		'mail'  => __( 'E-mail', 'clean-short-links' ),
		'print' => __( 'Print', 'clean-short-links' ),
	);
	/**
	 * Filters the channels short links can get addresses of their own for.
	 *
	 * @param array $list Key (letters and digits) => name.
	 */
	return apply_filters( 'clean_short_links_channels', $list );
}

/**
 * Cleans the extra settings of a link.
 *
 * @param array $meta Raw values.
 * @return array
 */
function clean_meta( $meta ) {
	$meta     = is_array( $meta ) ? $meta : array();
	$social   = is_array( $meta['social'] ?? null ) ? $meta['social'] : array();
	$channels = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $meta['channels'] ?? array() ) ), array_keys( channels() ) ) );
	return array(
		'social'    => array(
			'title'       => substr( sanitize_text_field( $social['title'] ?? '' ), 0, 120 ),
			'description' => substr( sanitize_text_field( $social['description'] ?? '' ), 0, 300 ),
			'image'       => absint( $social['image'] ?? 0 ),
		),
		'channels'  => $channels,
		'utm'       => empty( $meta['utm'] ) ? 0 : 1,
		'permanent' => empty( $meta['permanent'] ) ? 0 : 1,
		'bio'       => empty( $meta['bio'] ) ? 0 : 1,
		'bio_order' => (int) ( $meta['bio_order'] ?? 0 ),
		// Calendar: address, target for events without a link, last sync.
		'ical_url'     => preg_match( '#^(https?|webcals?)://\S+$#i', trim( (string) ( $meta['ical_url'] ?? '' ) ) ) ? esc_url_raw( preg_replace( '#^webcals?://#i', 'https://', trim( (string) $meta['ical_url'] ) ) ) : '',
		'ical_default' => clean_target( $meta['ical_default'] ?? '' ),
		'ical_at'      => (int) ( $meta['ical_at'] ?? 0 ),
		'ical_error'   => substr( sanitize_text_field( $meta['ical_error'] ?? '' ), 0, 200 ),
		'ical_count'   => (int) ( $meta['ical_count'] ?? 0 ),
		// Other targets by device, operating system or screen width, and further domains.
		'variants'     => clean_variants( $meta['variants'] ?? array() ),
		'hosts'        => clean_hosts( $meta['hosts'] ?? array() ),
		// Locations with addresses of their own ("<short address>-<key>").
		'places'       => clean_places( $meta['places'] ?? array() ),
	);
}

/**
 * Cleans the locations of a link: a name and a key that becomes part of the address. The
 * key is kept when the name changes, so printed codes keep working.
 *
 * @param mixed $in Raw list.
 * @return array<int,array{key:string,name:string}>
 */
function clean_places( $in ) {
	$out  = array();
	$used = array_keys( channels() );
	foreach ( is_array( $in ) ? $in : array() as $row ) {
		if ( ! is_array( $row ) || count( $out ) >= 30 ) {
			continue;
		}
		$name = substr( sanitize_text_field( $row['name'] ?? '' ), 0, 60 );
		$key  = normalize_slug( (string) ( $row['key'] ?? '' ) );
		if ( '' === $key || ! is_valid_slug( $key ) ) {
			$key = trim( substr( normalize_slug( $name ), 0, 24 ), '-' );
		}
		if ( '' === $name && '' === trim( (string) ( $row['key'] ?? '' ) ) ) {
			continue;
		}
		if ( '' === $key || ! is_valid_slug( $key ) ) {
			continue;
		}
		$base = $key;
		for ( $i = 2; in_array( $key, $used, true ); $i++ ) {
			$key = substr( $base, 0, 21 ) . '-' . $i;
		}
		$used[] = $key;
		$out[]  = array(
			'key'  => $key,
			'name' => '' !== $name ? $name : $key,
		);
	}
	return $out;
}

/**
 * The kind of short address a new link gets by default: posts and pages from their title,
 * everything else random or memorable, as set for the site.
 *
 * @param string $target Target.
 * @param array  $s      Site settings.
 * @return string random, memorable or title.
 */
function default_kind( $target, array $s ) {
	if ( target_post_id( $target ) ) {
		return in_array( $s['post_kind'] ?? '', array( 'random', 'memorable', 'title' ), true ) ? $s['post_kind'] : 'title';
	}
	return 'memorable' === ( $s['other_kind'] ?? '' ) ? 'memorable' : 'random';
}

/**
 * Applies the changes from the block editor's sidebar to a link. Only the fields the
 * sidebar shows are taken over, everything else stays as it is.
 *
 * @param array $old Link as stored.
 * @param array $in  Changes: slug, kind, keep_old, root, paused, start_mode, starts_at,
 *                   ends_at (local "Y-m-d\TH:i"), max_clicks, permanent, utm, bio, channels.
 * @return array Data for save_link().
 */
function editor_changes( array $old, array $in ) {
	$data = array(
		'id'         => $old['id'],
		'title'      => $old['title'],
		'target'     => $old['target'],
		'slug'       => $old['slug'],
		'qr_slug'    => $old['qr_slug'],
		'kind'       => $old['kind'],
		'root'       => ! empty( $old['root'] ),
		'start_mode' => $old['start_mode'],
		'starts_at'  => (int) $old['starts_at'],
		'ends_at'    => (int) $old['ends_at'],
		'fallback'   => $old['fallback'],
		'rules'      => $old['rules'],
		'qr'         => $old['qr'],
		'status'     => $old['status'],
		'max_clicks' => (int) $old['max_clicks'],
		'meta'       => $old['meta'],
		'old_slug'   => 'alias',
	);
	if ( isset( $in['slug'] ) ) {
		$slug         = normalize_slug( (string) $in['slug'] );
		$kind         = sanitize_key( (string) ( $in['kind'] ?? '' ) );
		$data['slug'] = $slug;
		if ( $slug !== $old['slug'] ) {
			$data['kind'] = in_array( $kind, array( 'random', 'memorable', 'title' ), true ) ? $kind : 'custom';
		}
		$data['old_slug'] = isset( $in['keep_old'] ) && ! $in['keep_old'] ? 'retire' : 'alias';
	}
	if ( isset( $in['root'] ) ) {
		$data['root'] = (bool) $in['root'];
	}
	if ( isset( $in['paused'] ) ) {
		$data['status'] = $in['paused'] ? 'paused' : 'active';
	}
	if ( isset( $in['start_mode'] ) ) {
		$mode               = in_array( $in['start_mode'], array( 'publish', 'date' ), true ) ? $in['start_mode'] : '';
		$starts             = 'date' === $mode ? local_to_timestamp( (string) ( $in['starts_at'] ?? '' ) ) : null;
		$data['start_mode'] = 'date' === $mode && ! $starts ? '' : $mode;
		$data['starts_at']  = $starts ? $starts : 0;
		if ( 'publish' === $mode && ! target_post_id( $old['target'] ) ) {
			$data['start_mode'] = '';
		}
	}
	if ( isset( $in['ends_at'] ) ) {
		$ends            = local_to_timestamp( (string) $in['ends_at'] );
		$data['ends_at'] = $ends ? $ends : 0;
	}
	if ( isset( $in['max_clicks'] ) ) {
		$data['max_clicks'] = absint( $in['max_clicks'] );
	}
	foreach ( array( 'permanent', 'utm', 'bio' ) as $key ) {
		if ( isset( $in[ $key ] ) ) {
			$data['meta'][ $key ] = $in[ $key ] ? 1 : 0;
		}
	}
	if ( isset( $in['channels'] ) ) {
		$data['meta']['channels'] = (array) $in['channels'];
	}
	$data['meta'] = clean_meta( $data['meta'] );
	return $data;
}

/**
 * May this link answer with a permanent redirect (301)? Only if nothing about it can
 * change by itself: no start date, end, rules, limit or invitations – browsers and search
 * engines remember a 301. Starting with the publication is allowed: before it the link does
 * not redirect at all, after it the target stays the same.
 *
 * @param array $link Link.
 * @return bool
 */
function permanent_allowed( array $link ) {
	return ! empty( $link['meta']['permanent'] )
		&& empty( $link['parent_id'] )
		&& empty( $link['rules'] )
		// Starting with the publication is fine: once published, the target stays the same.
		&& empty( $link['starts_at'] ) && in_array( $link['start_mode'] ?? '', array( '', 'publish' ), true )
		&& empty( $link['ends_at'] )
		&& empty( $link['max_clicks'] )
		&& empty( $link['meta']['variants'] );
}

/**
 * Appends UTM parameters, without overwriting ones the URL already has.
 *
 * @param string $url    URL.
 * @param array  $params utm_source, utm_medium, utm_campaign.
 * @return string
 */
function add_utm( $url, array $params ) {
	$query = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$add = array();
	foreach ( $params as $key => $value ) {
		if ( '' !== (string) $value && ! isset( $query[ $key ] ) ) {
			$add[ $key ] = rawurlencode( (string) $value );
		}
	}
	if ( ! $add ) {
		return $url;
	}
	$hash = '';
	$pos  = strpos( $url, '#' );
	if ( false !== $pos ) {
		$hash = substr( $url, $pos );
		$url  = substr( $url, 0, $pos );
	}
	$pairs = array();
	foreach ( $add as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs ) . $hash;
}

/**
 * The UTM parameters of a visit – derived from the short link itself, nothing to maintain.
 *
 * @param array  $link    Link.
 * @param int    $source  Source.
 * @param string $channel Channel key or ''.
 * @return array
 */
function utm_params( array $link, $source, $channel = '' ) {
	$place = 0 === strpos( $channel, 'p:' ) ? substr( $channel, 2 ) : '';
	if ( '' !== $channel && '' === $place ) {
		$medium = in_array( $channel, array( 'nl', 'mail' ), true ) ? 'email' : ( 'print' === $channel ? 'print' : 'social' );
		$src    = $channel;
	} else {
		$names  = array(
			SOURCE_QR  => array( 'qr', 'qr' ),
			SOURCE_BIO => array( 'linkinbio', 'social' ),
			SOURCE_NFC => array( 'nfc', 'nfc' ),
		);
		$src    = $names[ $source ][0] ?? 'shortlink';
		$medium = $names[ $source ][1] ?? 'shortlink';
	}
	return array(
		'utm_source'   => $src,
		'utm_medium'   => $medium,
		'utm_campaign' => $link['slug'] ?? '',
	) + ( '' !== $place ? array( 'utm_content' => $place ) : array() );
}

/**
 * Has a link used up its click limit?
 *
 * @param array $link Link.
 * @return bool
 */
function limit_reached( array $link ) {
	return ! empty( $link['max_clicks'] ) && (int) ( $link['clicks'] ?? 0 ) >= (int) $link['max_clicks'];
}

/**
 * Cleans a local date-time from a form ("2026-12-24T18:00" or "2026-12-24 18:00").
 *
 * @param string $value Raw value.
 * @return string "Y-m-d H:i" or "".
 */
function clean_local_datetime( $value ) {
	$value = str_replace( 'T', ' ', trim( (string) $value ) );
	if ( preg_match( '/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2}))?/', $value, $m ) ) {
		return $m[1] . ' ' . ( $m[2] ?? '00:00' );
	}
	return '';
}

/**
 * Cleans a time of day.
 *
 * @param string $value Raw value.
 * @return string "H:i" or "".
 */
function clean_time( $value ) {
	return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', trim( (string) $value ) ) ? trim( (string) $value ) : '';
}

/**
 * Cleans the list of rules from the form.
 *
 * @param array $rules Raw rules.
 * @return array
 */
function clean_rules( $rules ) {
	$out = array();
	foreach ( (array) $rules as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$target = clean_target( $r['target'] ?? '' );
		if ( '' === $target ) {
			continue;
		}
		$days = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $r['days'] ?? array() ) ), fn( $d ) => $d >= 1 && $d <= 7 ) ) );
		sort( $days );
		$out[] = array(
			'label'     => sanitize_text_field( $r['label'] ?? '' ),
			'from'      => clean_local_datetime( $r['from'] ?? '' ),
			'to'        => clean_local_datetime( $r['to'] ?? '' ),
			'days'      => count( $days ) === 7 ? array() : $days,
			'time_from' => clean_time( $r['time_from'] ?? '' ),
			'time_to'   => clean_time( $r['time_to'] ?? '' ),
			'target'    => $target,
		) + ( 'ical' === ( $r['source'] ?? '' ) ? array( 'source' => 'ical' ) : array() );
	}
	return $out;
}

/**
 * Does a rule apply at this moment?
 *
 * Dates and times are local times of the site. "to" is exclusive. A time window whose
 * end lies before its start runs over midnight (e.g. 22:00–02:00).
 *
 * @param array              $rule Rule.
 * @param \DateTimeImmutable $now  Now, in the site's time zone.
 * @return bool
 */
function rule_matches( array $rule, \DateTimeImmutable $now ) {
	$stamp = $now->format( 'Y-m-d H:i' );
	if ( ! empty( $rule['from'] ) && $stamp < $rule['from'] ) {
		return false;
	}
	if ( ! empty( $rule['to'] ) && $stamp >= $rule['to'] ) {
		return false;
	}
	if ( ! empty( $rule['days'] ) && ! in_array( (int) $now->format( 'N' ), array_map( 'intval', $rule['days'] ), true ) ) {
		return false;
	}
	$t    = $now->format( 'H:i' );
	$from = $rule['time_from'] ?? '';
	$to   = $rule['time_to'] ?? '';
	if ( '' !== $from && '' !== $to ) {
		return $from <= $to ? ( $t >= $from && $t < $to ) : ( $t >= $from || $t < $to );
	}
	if ( '' !== $from ) {
		return $t >= $from;
	}
	if ( '' !== $to ) {
		return $t < $to;
	}
	return true;
}

/**
 * Where does a link lead right now?
 *
 * @param array              $link     Link (see Store\row_to_link()).
 * @param \DateTimeImmutable $now      Now, in the site's time zone.
 * @param callable           $url_of   Turns a target into a URL, null if it is not available (e.g. a draft).
 * @param callable           $published Returns the publication time of a target (Unix time) or null.
 * @return array{status:int,url:string,state:string,target:string} status 302, 404 or 410.
 */
function resolve( array $link, \DateTimeImmutable $now, callable $url_of, callable $published ) {
	$fallback = function ( $status, $state ) use ( $link, $url_of ) {
		$url = '' !== ( $link['fallback'] ?? '' ) ? $url_of( $link['fallback'] ) : null;
		return $url ? array(
			'status' => 302,
			'url'    => $url,
			'state'  => $state,
			'target' => $link['fallback'],
		) : array(
			'status' => $status,
			'url'    => '',
			'state'  => $state,
			'target' => '',
		);
	};

	if ( 'active' !== ( $link['status'] ?? 'active' ) ) {
		return array(
			'status' => 404,
			'url'    => '',
			'state'  => 'paused',
			'target' => '',
		);
	}
	$ts    = $now->getTimestamp();
	$start = null;
	if ( 'publish' === ( $link['start_mode'] ?? '' ) ) {
		$start = $published( $link['target'] );
		if ( null === $start ) {
			return $fallback( 404, 'waiting' );
		}
	} elseif ( ! empty( $link['starts_at'] ) ) {
		$start = (int) $link['starts_at'];
	}
	if ( null !== $start && $ts < $start ) {
		return $fallback( 404, 'waiting' );
	}
	if ( ! empty( $link['ends_at'] ) && $ts >= (int) $link['ends_at'] ) {
		return $fallback( 410, 'expired' );
	}
	if ( limit_reached( $link ) ) {
		return $fallback( 410, 'exhausted' );
	}

	$target = $link['target'];
	$state  = 'default';
	foreach ( (array) ( $link['rules'] ?? array() ) as $i => $rule ) {
		if ( rule_matches( $rule, $now ) ) {
			$target = $rule['target'];
			$state  = 'rule-' . ( $i + 1 );
			break;
		}
	}
	$url = $url_of( $target );
	if ( ! $url ) {
		return $fallback( 404, 'unavailable' );
	}
	return array(
		'status' => 302,
		'url'    => $url,
		'state'  => $state,
		'target' => $target,
	);
}

/**
 * State of a link for lists: active, waiting, expired, exhausted, unreachable, gone, paused.
 *
 * @param array $link      Link.
 * @param int   $now       Unix time.
 * @param int   $gone_days Days after which an unreachable target counts as gone.
 * @return string
 */
function link_state( array $link, $now, $gone_days = 30 ) {
	if ( 'active' !== ( $link['status'] ?? 'active' ) ) {
		return 'paused';
	}
	$u = unreachable( $link, $now, $gone_days );
	if ( '' !== $u ) {
		return 'gone' === $u ? 'gone' : 'unreachable';
	}
	if ( ! empty( $link['starts_at'] ) && $now < (int) $link['starts_at'] ) {
		return 'waiting';
	}
	// Starting with the publication: waiting while the content is a draft or scheduled.
	if ( 'publish' === ( $link['start_mode'] ?? '' ) && function_exists( __NAMESPACE__ . '\\target_published' ) ) {
		$published = target_published( $link['target'] );
		if ( null === $published || $now < $published ) {
			return 'waiting';
		}
	}
	if ( ! empty( $link['ends_at'] ) && $now >= (int) $link['ends_at'] ) {
		return 'expired';
	}
	if ( limit_reached( $link ) ) {
		return 'exhausted';
	}
	return 'active';
}

/* ---------------------------------------------------------------------------
 * Visits
 * ------------------------------------------------------------------------ */

/**
 * Is the visitor a bot, link checker or link preview?
 *
 * @param string $ua User agent.
 * @return bool
 */
function is_bot( $ua ) {
	$ua = (string) $ua;
	return '' === trim( $ua ) || 1 === preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|discord|skype|curl|wget|python|httpclient|okhttp|go-http|java\/|headless|lighthouse|monitor|pingdom|uptime|validator|scan/i', $ua );
}

/**
 * Device class from the user agent.
 *
 * @param string $ua User agent.
 * @return int
 */
function device_class( $ua ) {
	$ua = (string) $ua;
	if ( preg_match( '/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua ) ) {
		return DEVICE_TABLET;
	}
	if ( preg_match( '/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua ) ) {
		return DEVICE_MOBILE;
	}
	return DEVICE_DESKTOP;
}

/**
 * Host of the referring page, without "www.". Only the host is kept, never the path.
 *
 * @param string $referrer Referrer.
 * @return string
 */
function referrer_host( $referrer ) {
	$host = strtolower( (string) wp_parse_url( (string) $referrer, PHP_URL_HOST ) );
	$host = preg_replace( '/^www\./', '', $host );
	return substr( preg_replace( '/[^a-z0-9.-]/', '', $host ), 0, 100 );
}

/**
 * Converts a local "Y-m-d H:i" of the site to Unix time.
 *
 * @param string $local Local date-time.
 * @return int|null
 */
function local_to_timestamp( $local ) {
	$local = clean_local_datetime( $local );
	if ( '' === $local ) {
		return null;
	}
	$d = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $local, wp_timezone() );
	return $d ? $d->getTimestamp() : null;
}

/**
 * Converts Unix time to the site's local "Y-m-d\TH:i" for datetime-local fields.
 *
 * @param int|null $ts Unix time.
 * @return string
 */
function timestamp_to_local( $ts ) {
	return $ts ? wp_date( 'Y-m-d\TH:i', (int) $ts ) : '';
}

/**
 * Version of an asset file: plugin version plus modification time, so browsers load a
 * changed file right away.
 *
 * @param string $file File name in assets/.
 * @return string
 */
function asset_ver( $file ) {
	$path = dirname( CLEAN_SHORT_LINKS_FILE ) . '/assets/' . $file;
	return CLEAN_SHORT_LINKS_VERSION . '.' . ( file_exists( $path ) ? filemtime( $path ) : 0 );
}

/**
 * Who may manage short links.
 *
 * @return string Capability.
 */
function capability() {
	return apply_filters( 'clean_short_links_capability', 'edit_others_posts' );
}

/* ---------------------------------------------------------------------------
 * Variants by device, operating system and screen width
 * ------------------------------------------------------------------------ */

/**
 * Device class as a word.
 *
 * @param int $device DEVICE_* constant.
 * @return string desktop, mobile or tablet.
 */
function device_key( $device ) {
	return DEVICE_MOBILE === (int) $device ? 'mobile' : ( DEVICE_TABLET === (int) $device ? 'tablet' : 'desktop' );
}

/**
 * Operating system of the visitor – from the client hint browsers send anyway
 * (Sec-CH-UA-Platform), otherwise from the user agent. Nothing of it is stored.
 *
 * @param string $ua       User agent.
 * @param string $platform Value of Sec-CH-UA-Platform, e.g. "Android" with quotes.
 * @return string One of OS_KEYS or "other".
 */
function os_class( $ua, $platform = '' ) {
	$platform = strtolower( trim( (string) $platform, " \t\"" ) );
	$map      = array(
		'android'  => 'android',
		'ios'      => 'ios',
		'windows'  => 'windows',
		'macos'    => 'macos',
		'linux'    => 'linux',
		'chrome os' => 'linux',
		'chromeos' => 'linux',
	);
	if ( isset( $map[ $platform ] ) ) {
		return $map[ $platform ];
	}
	$ua = (string) $ua;
	if ( preg_match( '/iphone|ipad|ipod/i', $ua ) ) {
		return 'ios';
	}
	if ( preg_match( '/android/i', $ua ) ) {
		return 'android';
	}
	if ( preg_match( '/windows/i', $ua ) ) {
		return 'windows';
	}
	if ( preg_match( '/mac os x|macintosh/i', $ua ) ) {
		return 'macos';
	}
	if ( preg_match( '/linux|cros|x11/i', $ua ) ) {
		return 'linux';
	}
	return 'other';
}

/**
 * Cleans the variants of a link: each with an optional device, operating system and
 * screen width range, and a target. At most 20.
 *
 * @param mixed $in Raw value.
 * @return array[] Each {device, os, min_w, max_w, target}.
 */
function clean_variants( $in ) {
	$out = array();
	foreach ( is_array( $in ) ? $in : array() as $v ) {
		if ( ! is_array( $v ) ) {
			continue;
		}
		$target = clean_target( $v['target'] ?? '' );
		if ( '' === $target ) {
			continue;
		}
		$device = in_array( $v['device'] ?? '', array( 'mobile', 'tablet', 'desktop' ), true ) ? $v['device'] : '';
		$os     = in_array( $v['os'] ?? '', OS_KEYS, true ) ? $v['os'] : '';
		$min    = min( 10000, absint( $v['min_w'] ?? 0 ) );
		$max    = min( 10000, absint( $v['max_w'] ?? 0 ) );
		if ( $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}
		if ( '' === $device && '' === $os && ! $min && ! $max ) {
			continue; // A variant without a condition would hide the main target.
		}
		$out[] = array(
			'device' => $device,
			'os'     => $os,
			'min_w'  => $min,
			'max_w'  => $max,
			'target' => $target,
		);
		if ( count( $out ) >= 20 ) {
			break;
		}
	}
	return $out;
}

/**
 * Which variant applies? Device and operating system are known on the server; screen
 * widths only in the browser.
 *
 * @param array[] $variants Clean variants.
 * @param string  $device   desktop, mobile or tablet.
 * @param string  $os       One of OS_KEYS or "other".
 * @return array|null Null: the normal target. {target}: this variant. {screen: [[min, max,
 *                    target], …], fallback: target|null}: the browser decides by its width,
 *                    in this order; fallback when none fits (null = the normal target).
 */
function variant_plan( array $variants, $device, $os ) {
	$screen = array();
	foreach ( $variants as $v ) {
		if ( ( '' !== $v['device'] && $v['device'] !== $device ) || ( '' !== $v['os'] && $v['os'] !== $os ) ) {
			continue;
		}
		if ( ! $v['min_w'] && ! $v['max_w'] ) {
			if ( ! $screen ) {
				return array( 'target' => $v['target'] );
			}
			return array(
				'screen'   => $screen,
				'fallback' => $v['target'],
			);
		}
		$screen[] = array( $v['min_w'], $v['max_w'], $v['target'] );
	}
	return $screen ? array(
		'screen'   => $screen,
		'fallback' => null,
	) : null;
}

/* ---------------------------------------------------------------------------
 * Further domains
 * ------------------------------------------------------------------------ */

/**
 * Cleans a list of host names (domains and subdomains, lower case, without scheme or path).
 *
 * @param mixed $in List or text.
 * @return string[]
 */
function clean_hosts( $in ) {
	$list = is_array( $in ) ? $in : preg_split( '/[\s,]+/', (string) $in );
	$out  = array();
	foreach ( $list as $h ) {
		$h = strtolower( trim( (string) $h ) );
		$h = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $h );
		$h = preg_replace( '#[/?\#:].*$#', '', $h );
		$h = rtrim( $h, '.' );
		if ( '' !== $h && strlen( $h ) <= 190 && preg_match( '/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $h ) ) {
			$out[ $h ] = $h;
		}
	}
	return array_values( $out );
}
