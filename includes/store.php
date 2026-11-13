<?php
/**
 * Database: the tables of a site and reading/writing links.
 *
 * Three tables per site:
 *  - links:  one row per short link,
 *  - slugs:  every short address in use (main, QR, old aliases, retired ones),
 *  - clicks: visits added up per day, link, source, device and referring host.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables of this plugin; there is no WordPress API for them.

const DB_VERSION = 2;

/**
 * Table names of the current site.
 *
 * @return array{links:string,slugs:string,clicks:string}
 */
function tables() {
	global $wpdb;
	return array(
		'links'  => $wpdb->prefix . 'cleansl_links',
		'slugs'  => $wpdb->prefix . 'cleansl_slugs',
		'clicks' => $wpdb->prefix . 'cleansl_clicks',
	);
}

/**
 * Creates or updates the tables of the current site.
 */
function install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t       = tables();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$t['links']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  target text NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slug varchar(64) NOT NULL DEFAULT '',
  qr_slug varchar(64) NOT NULL DEFAULT '',
  kind varchar(12) NOT NULL DEFAULT 'random',
  root tinyint(1) NOT NULL DEFAULT 0,
  start_mode varchar(10) NOT NULL DEFAULT '',
  starts_at bigint(20) unsigned NOT NULL DEFAULT 0,
  ends_at bigint(20) unsigned NOT NULL DEFAULT 0,
  fallback text NOT NULL,
  rules longtext NOT NULL,
  qr longtext NOT NULL,
  status varchar(10) NOT NULL DEFAULT 'active',
  author bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  max_clicks int(10) unsigned NOT NULL DEFAULT 0,
  clicks bigint(20) unsigned NOT NULL DEFAULT 0,
  check_status varchar(12) NOT NULL DEFAULT '',
  check_info text NULL,
  checked_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY slug (slug),
  KEY parent_id (parent_id)
) $charset;
CREATE TABLE {$t['slugs']} (
  slug varchar(64) NOT NULL,
  link_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT 'main',
  created_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (slug),
  KEY link_id (link_id)
) $charset;
CREATE TABLE {$t['clicks']} (
  link_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  source tinyint(3) unsigned NOT NULL DEFAULT 0,
  device tinyint(3) unsigned NOT NULL DEFAULT 0,
  referrer varchar(100) NOT NULL DEFAULT '',
  hits int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (link_id,day,source,device,referrer),
  KEY day (day)
) $charset;"
	);
	update_option( 'clean_short_links_db', DB_VERSION );
	if ( ! wp_next_scheduled( 'clean_short_links_prune' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'clean_short_links_prune' );
	}
	if ( ! wp_next_scheduled( 'clean_short_links_check' ) ) {
		wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', 'clean_short_links_check' );
	}
}

/*
 * Every site creates its tables the first time the plugin runs there – also sites that
 * are added to the network later. The check is a single autoloaded option.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( (int) get_option( 'clean_short_links_db' ) !== DB_VERSION ) {
			install();
		}
	}
);

/**
 * Turns a database row into a link array.
 *
 * @param object|array|null $row Row.
 * @return array|null
 */
function row_to_link( $row ) {
	if ( ! $row ) {
		return null;
	}
	$row   = (array) $row;
	$rules = json_decode( (string) $row['rules'], true );
	$qr    = json_decode( (string) $row['qr'], true );
	$check = json_decode( (string) ( $row['check_info'] ?? '' ), true );
	return array(
		'id'         => (int) $row['id'],
		'title'      => (string) $row['title'],
		'target'     => (string) $row['target'],
		'post_id'    => (int) $row['post_id'],
		'slug'       => (string) $row['slug'],
		'qr_slug'    => (string) $row['qr_slug'],
		'kind'       => (string) $row['kind'],
		'root'       => (int) $row['root'],
		'start_mode' => (string) $row['start_mode'],
		'starts_at'  => (int) $row['starts_at'] ? (int) $row['starts_at'] : null,
		'ends_at'    => (int) $row['ends_at'] ? (int) $row['ends_at'] : null,
		'fallback'   => (string) $row['fallback'],
		'rules'      => is_array( $rules ) ? $rules : array(),
		'qr'         => is_array( $qr ) ? $qr : array(),
		'status'     => (string) $row['status'],
		'author'     => (int) $row['author'],
		'created_at' => (int) $row['created_at'],
		'updated_at' => (int) $row['updated_at'],
		'parent_id'  => (int) ( $row['parent_id'] ?? 0 ),
		'max_clicks' => (int) ( $row['max_clicks'] ?? 0 ),
		'clicks'     => (int) ( $row['clicks'] ?? 0 ),
		'check'      => array(
			'status' => (string) ( $row['check_status'] ?? '' ),
			'items'  => is_array( $check ) ? $check : array(),
			'at'     => (int) ( $row['checked_at'] ?? 0 ),
		),
	);
}

/**
 * A link by ID.
 *
 * @param int $id ID.
 * @return array|null
 */
function link_by_id( $id ) {
	global $wpdb;
	return row_to_link( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', tables()['links'], $id ) ) );
}

/**
 * The newest link that leads to a post.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function get_link_for_post( $post_id ) {
	global $wpdb;
	return row_to_link( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT 1', tables()['links'], $post_id ) ) );
}

/**
 * Looks up a short address.
 *
 * @param string $slug Normalized short address.
 * @return array{0:array|null,1:string}|null Link (null for retired addresses) and kind of the address.
 */
function find_slug( $slug ) {
	global $wpdb;
	$t   = tables();
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT link_id, kind FROM %i WHERE slug = %s', $t['slugs'], $slug ) );
	if ( ! $row ) {
		return null;
	}
	if ( 'retired' === $row->kind ) {
		return array( null, 'retired' );
	}
	$link = link_by_id( (int) $row->link_id );
	return $link ? array( $link, $row->kind ) : null;
}

/**
 * Who uses a short address?
 *
 * @param string $slug Normalized short address.
 * @return object|null Row with link_id and kind.
 */
function slug_owner( $slug ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT link_id, kind FROM %i WHERE slug = %s', tables()['slugs'], $slug ) );
}

/**
 * Is a short address free for a link (or already its own)?
 *
 * @param string $slug    Normalized short address.
 * @param int    $link_id The link that wants it, 0 for a new one.
 * @return bool
 */
function slug_available( $slug, $link_id = 0 ) {
	$owner = slug_owner( $slug );
	return ! $owner || ( $link_id && (int) $owner->link_id === (int) $link_id && 'retired' !== $owner->kind );
}

/**
 * A free random short address.
 *
 * @return string
 */
function unique_random_slug( $min = 5 ) {
	for ( $len = $min; $len < 14; $len++ ) {
		for ( $i = 0; $i < 8; $i++ ) {
			$s = random_slug( $len );
			if ( slug_available( $s ) && ! in_array( $s, reserved_words(), true ) ) {
				return $s;
			}
		}
	}
	return random_slug( 12 );
}

/**
 * A free memorable short address; adds a number if all tries are taken.
 *
 * @return string
 */
function unique_memorable_slug() {
	$locale = determine_locale();
	for ( $i = 0; $i < 12; $i++ ) {
		$s = memorable_slug( $locale );
		if ( slug_available( $s ) ) {
			return $s;
		}
	}
	return unique_numbered( memorable_slug( $locale ) );
}

/**
 * Adds -2, -3, … until the short address is free.
 *
 * @param string $slug    Base.
 * @param int    $link_id Link that wants it.
 * @return string
 */
function unique_numbered( $slug, $link_id = 0 ) {
	if ( '' === $slug ) {
		return unique_random_slug();
	}
	if ( slug_available( $slug, $link_id ) && ! in_array( $slug, reserved_words(), true ) ) {
		return $slug;
	}
	for ( $n = 2; $n < 1000; $n++ ) {
		$s = substr( $slug, 0, 58 ) . '-' . $n;
		if ( slug_available( $s, $link_id ) ) {
			return $s;
		}
	}
	return unique_random_slug();
}

/**
 * Suggests a short address of a kind.
 *
 * @param string $kind  random, memorable or title.
 * @param string $title Title for kind "title".
 * @return string
 */
function suggest_slug( $kind, $title = '' ) {
	if ( 'memorable' === $kind ) {
		return unique_memorable_slug();
	}
	if ( 'title' === $kind ) {
		return unique_numbered( title_slug( $title ) );
	}
	return unique_random_slug();
}

/**
 * Checks a wished short address.
 *
 * @param string $slug    Raw short address.
 * @param int    $link_id Link that wants it.
 * @return array{slug:string,ok:bool,message:string,reserved:bool}
 */
function check_slug( $slug, $link_id = 0 ) {
	$slug = normalize_slug( $slug );
	if ( ! is_valid_slug( $slug ) ) {
		return array(
			'slug'     => $slug,
			'ok'       => false,
			'reserved' => false,
			'message'  => __( 'Use letters, digits and dashes only.', 'clean-short-links' ),
		);
	}
	if ( ! slug_available( $slug, $link_id ) ) {
		$owner = slug_owner( $slug );
		return array(
			'slug'     => $slug,
			'ok'       => false,
			'reserved' => false,
			'message'  => $owner && 'retired' === $owner->kind ? __( 'This short address was used before and is retired.', 'clean-short-links' ) : __( 'This short address is already taken.', 'clean-short-links' ),
		);
	}
	return array(
		'slug'     => $slug,
		'ok'       => true,
		'reserved' => in_array( $slug, reserved_words(), true ),
		'message'  => '',
	);
}

/**
 * Does the short form on the main address collide with content of the site?
 *
 * @param string $slug Short address.
 * @return string Empty if free, otherwise what is there.
 */
function root_collision( $slug ) {
	if ( in_array( $slug, reserved_words(), true ) ) {
		return __( 'a reserved word', 'clean-short-links' );
	}
	$page = get_page_by_path( $slug, OBJECT, get_post_types( array( 'public' => true ) ) );
	if ( $page && 'trash' !== $page->post_status ) {
		/* translators: %s: title of the page or post */
		return sprintf( __( '“%s”', 'clean-short-links' ), get_the_title( $page ) );
	}
	$post_id = url_to_postid( home_url( '/' . $slug . '/' ) );
	if ( $post_id ) {
		/* translators: %s: title of the page or post */
		return sprintf( __( '“%s”', 'clean-short-links' ), get_the_title( $post_id ) );
	}
	return '';
}

/**
 * Saves a link. Takes care of the short-address table.
 *
 * @param array $data Clean link fields; "id" updates an existing link.
 * @return int|\WP_Error Link ID.
 */
function save_link( array $data ) {
	global $wpdb;
	$t   = tables();
	$id  = (int) ( $data['id'] ?? 0 );
	$old = $id ? link_by_id( $id ) : null;
	if ( $id && ! $old ) {
		return new \WP_Error( 'missing', __( 'This short link no longer exists.', 'clean-short-links' ) );
	}
	$slug    = normalize_slug( $data['slug'] ?? '' );
	$qr_slug = normalize_slug( $data['qr_slug'] ?? '' );
	if ( ! is_valid_slug( $slug ) ) {
		return new \WP_Error( 'slug', __( 'The short address is not valid.', 'clean-short-links' ) );
	}
	if ( ! slug_available( $slug, $id ) ) {
		return new \WP_Error( 'slug', __( 'This short address is already taken.', 'clean-short-links' ) );
	}
	if ( '' !== $qr_slug && ( $qr_slug === $slug || ! is_valid_slug( $qr_slug ) ) ) {
		$qr_slug = '';
	}
	if ( '' !== $qr_slug && ! slug_available( $qr_slug, $id ) ) {
		return new \WP_Error( 'qr_slug', __( 'The QR short address is already taken.', 'clean-short-links' ) );
	}
	$target = clean_target( $data['target'] ?? '' );
	if ( '' === $target ) {
		return new \WP_Error( 'target', __( 'Please choose a target – a post, a page or a URL.', 'clean-short-links' ) );
	}
	if ( $id && target_link_id( $target ) === $id ) {
		return new \WP_Error( 'target', __( 'A short link cannot lead to itself.', 'clean-short-links' ) );
	}

	$now = time();
	$row = array(
		'title'      => sanitize_text_field( $data['title'] ?? '' ),
		'target'     => $target,
		'post_id'    => target_post_id( $target ),
		'slug'       => $slug,
		'qr_slug'    => $qr_slug,
		'kind'       => in_array( $data['kind'] ?? '', array( 'random', 'memorable', 'title', 'custom', 'invite' ), true ) ? $data['kind'] : 'custom',
		'root'       => empty( $data['root'] ) ? 0 : 1,
		'start_mode' => in_array( $data['start_mode'] ?? '', array( 'date', 'publish' ), true ) ? $data['start_mode'] : '',
		'starts_at'  => (int) ( $data['starts_at'] ?? 0 ),
		'ends_at'    => (int) ( $data['ends_at'] ?? 0 ),
		'fallback'   => clean_target( $data['fallback'] ?? '' ),
		'rules'      => wp_json_encode( clean_rules( $data['rules'] ?? array() ) ),
		'qr'         => wp_json_encode( is_array( $data['qr'] ?? null ) ? $data['qr'] : ( $old['qr'] ?? new \stdClass() ) ),
		'status'     => 'paused' === ( $data['status'] ?? '' ) ? 'paused' : 'active',
		'max_clicks' => max( 0, (int) ( $data['max_clicks'] ?? 0 ) ),
		'updated_at' => $now,
	);
	if ( 'date' !== $row['start_mode'] ) {
		$row['starts_at'] = 0;
	}

	if ( $old && ( $old['target'] !== $row['target'] || $old['fallback'] !== $row['fallback'] || wp_json_encode( $old['rules'] ) !== $row['rules'] ) ) {
		// Targets changed: old check results no longer apply.
		$row['check_status'] = '';
		$row['check_info']   = '[]';
		$row['checked_at']   = 0;
	}
	if ( $old ) {
		$wpdb->update( $t['links'], $row, array( 'id' => $id ) );
	} else {
		$row['author']     = get_current_user_id();
		$row['created_at'] = $now;
		$row['parent_id']  = (int) ( $data['parent_id'] ?? 0 );
		$wpdb->insert( $t['links'], $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'db', __( 'The short link could not be saved.', 'clean-short-links' ) );
		}
	}

	// Short addresses: the old main address becomes an alias or is retired.
	if ( $old && $old['slug'] !== $slug ) {
		$keep = $data['old_slug'] ?? 'alias';
		$wpdb->update(
			$t['slugs'],
			array(
				'kind'    => 'retire' === $keep ? 'retired' : 'alias',
				'link_id' => $id,
			),
			array( 'slug' => $old['slug'] )
		);
	}
	if ( $old && '' !== $old['qr_slug'] && $old['qr_slug'] !== $qr_slug ) {
		$wpdb->delete( $t['slugs'], array( 'slug' => $old['qr_slug'] ) );
	}
	$wpdb->replace(
		$t['slugs'],
		array(
			'slug'       => $slug,
			'link_id'    => $id,
			'kind'       => 'main',
			'created_at' => $now,
		)
	);
	if ( '' !== $qr_slug ) {
		$wpdb->replace(
			$t['slugs'],
			array(
				'slug'       => $qr_slug,
				'link_id'    => $id,
				'kind'       => 'qr',
				'created_at' => $now,
			)
		);
	}
	return $id;
}

/**
 * Saves only the QR design of a link.
 *
 * @param int   $id     Link ID.
 * @param array $design Design.
 */
function save_qr_design( $id, array $design ) {
	global $wpdb;
	$wpdb->update( tables()['links'], array( 'qr' => wp_json_encode( $design ) ), array( 'id' => (int) $id ) );
}

/**
 * Deletes a link with its short addresses and statistics.
 *
 * @param int  $id     Link ID.
 * @param bool $retire Keep the short addresses as retired so they are never handed out again.
 */
function delete_link( $id, $retire = true ) {
	global $wpdb;
	$t = tables();
	foreach ( child_ids( $id ) as $child ) {
		delete_link( $child, $retire );
	}
	$wpdb->delete( $t['links'], array( 'id' => (int) $id ) );
	$wpdb->delete( $t['clicks'], array( 'link_id' => (int) $id ) );
	if ( $retire ) {
		$wpdb->update( $t['slugs'], array( 'kind' => 'retired' ), array( 'link_id' => (int) $id ) );
	} else {
		$wpdb->delete( $t['slugs'], array( 'link_id' => (int) $id ) );
	}
}

/**
 * Further short addresses of a link (aliases and the retired ones it left behind).
 *
 * @param int $id Link ID.
 * @return object[] Rows with slug and kind.
 */
function link_aliases( $id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT slug, kind FROM %i WHERE link_id = %d AND kind IN ('alias','retired') ORDER BY created_at DESC", tables()['slugs'], $id ) );
}

/**
 * Removes an alias or retired address of a link.
 *
 * @param int    $id   Link ID.
 * @param string $slug Short address.
 */
function delete_alias( $id, $slug ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE link_id = %d AND slug = %s AND kind IN ('alias','retired')", tables()['slugs'], $id, $slug ) );
}

/**
 * Lists links.
 *
 * @param array $args search, state (waiting|expired|exhausted|paused|scheduled|broken), paged, per_page.
 * @return array{items:array,total:int}
 */
function list_links( array $args = array() ) {
	global $wpdb;
	$t        = tables();
	$per_page = max( 1, (int) ( $args['per_page'] ?? 30 ) );
	$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
	$search   = (string) ( $args['search'] ?? '' );
	$like     = '%' . $wpdb->esc_like( $search ) . '%';
	$state    = (string) ( $args['state'] ?? '' );
	$now      = time();
	// Fixed queries: every filter is switched on or off by its parameter.
	$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE parent_id = 0 AND (%s = '' OR title LIKE %s OR slug LIKE %s OR target LIKE %s) AND (%s <> 'paused' OR status = 'paused') AND (%s <> 'waiting' OR (status = 'active' AND starts_at > %d)) AND (%s <> 'expired' OR (status = 'active' AND ends_at > 0 AND ends_at <= %d)) AND (%s <> 'scheduled' OR rules <> '[]') AND (%s <> 'exhausted' OR (max_clicks > 0 AND clicks >= max_clicks)) AND (%s <> 'broken' OR check_status IN ('broken','warning'))", $t['links'], $search, $like, $like, $like, $state, $state, $now, $state, $now, $state, $state, $state ) );
	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE parent_id = 0 AND (%s = '' OR title LIKE %s OR slug LIKE %s OR target LIKE %s) AND (%s <> 'paused' OR status = 'paused') AND (%s <> 'waiting' OR (status = 'active' AND starts_at > %d)) AND (%s <> 'expired' OR (status = 'active' AND ends_at > 0 AND ends_at <= %d)) AND (%s <> 'scheduled' OR rules <> '[]') AND (%s <> 'exhausted' OR (max_clicks > 0 AND clicks >= max_clicks)) AND (%s <> 'broken' OR check_status IN ('broken','warning')) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $t['links'], $search, $like, $like, $like, $state, $state, $now, $state, $now, $state, $state, $state, $per_page, ( $paged - 1 ) * $per_page ) );
	return array(
		'items' => array_map( __NAMESPACE__ . '\\row_to_link', $rows ),
		'total' => $total,
	);
}

/**
 * Number of links of the current site.
 *
 * @return int
 */
function count_links() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE parent_id = 0', tables()['links'] ) );
}

/**
 * IDs of the invitations of a link.
 *
 * @param int $id Link ID.
 * @return int[]
 */
function child_ids( $id ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE parent_id = %d', tables()['links'], $id ) ) );
}

/**
 * The invitations of a link, oldest first.
 *
 * @param int $id Link ID.
 * @return array[]
 */
function invites( $id ) {
	global $wpdb;
	return array_map( __NAMESPACE__ . '\\row_to_link', $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE parent_id = %d ORDER BY title, id', tables()['links'], $id ) ) );
}

/**
 * Number of invitations per link.
 *
 * @param int[] $ids Link IDs.
 * @return array<int,int>
 */
function invite_counts( array $ids ) {
	global $wpdb;
	$out  = array();
	$want = array_flip( array_map( 'intval', $ids ) );
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT parent_id, COUNT(*) AS n FROM %i WHERE parent_id > 0 GROUP BY parent_id', tables()['links'] ) ) as $r ) {
		if ( isset( $want[ (int) $r->parent_id ] ) ) {
			$out[ (int) $r->parent_id ] = (int) $r->n;
		}
	}
	return $out;
}

/**
 * Creates invitations for a link: each gets a random, hard-to-guess short address.
 *
 * @param array  $parent     Main link.
 * @param string $group      Group name, e.g. "Family".
 * @param int    $count      How many.
 * @param int    $max_clicks Uses per invitation, 0 for unlimited.
 * @param int    $ends_at    Expiry (Unix time), 0 for that of the main link.
 * @return int Number created.
 */
function create_invites( array $parent, $group, $count, $max_clicks, $ends_at ) {
	$n = 0;
	for ( $i = 0; $i < min( 500, max( 1, (int) $count ) ); $i++ ) {
		$id = save_link(
			array(
				'title'      => sanitize_text_field( $group ),
				'target'     => $parent['target'],
				'slug'       => unique_random_slug( 8 ),
				'kind'       => 'invite',
				'max_clicks' => max( 0, (int) $max_clicks ),
				'ends_at'    => (int) $ends_at,
				'parent_id'  => (int) $parent['id'],
			)
		);
		if ( ! is_wp_error( $id ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Counts a use towards the click limit.
 *
 * @param int $id Link ID.
 */
function add_click( $id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET clicks = clicks + 1 WHERE id = %d', tables()['links'], $id ) );
}

/**
 * Sets the click counter of a link back to zero.
 *
 * @param int $id Link ID.
 */
function reset_clicks( $id ) {
	global $wpdb;
	$wpdb->update( tables()['links'], array( 'clicks' => 0 ), array( 'id' => (int) $id ) );
}

/**
 * Stores the result of a link check.
 *
 * @param int    $id     Link ID.
 * @param string $status ok, warning or broken.
 * @param array  $items  Single results.
 */
function save_check( $id, $status, array $items ) {
	global $wpdb;
	$wpdb->update(
		tables()['links'],
		array(
			'check_status' => $status,
			'check_info'   => wp_json_encode( $items ),
			'checked_at'   => time(),
		),
		array( 'id' => (int) $id )
	);
}

/**
 * Main links that were checked longest ago.
 *
 * @param int $limit How many.
 * @return array[]
 */
function links_to_check( $limit = 20 ) {
	global $wpdb;
	return array_map( __NAMESPACE__ . '\\row_to_link', $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE parent_id = 0 ORDER BY checked_at ASC, id ASC LIMIT %d', tables()['links'], $limit ) ) );
}

/**
 * Number of main links whose last check found a problem.
 *
 * @return int
 */
function count_problems() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE parent_id = 0 AND check_status = 'broken'", tables()['links'] ) );
}

/**
 * URL of a target right now, or null if it is not available (draft, deleted, private).
 *
 * @param string $target Target.
 * @return string|null
 */
function target_url( $target, $depth = 0 ) {
	if ( 'home' === $target ) {
		return home_url( '/' );
	}
	$link_id = target_link_id( $target );
	if ( $link_id ) {
		$link = $depth < 3 ? link_by_id( $link_id ) : null;
		if ( ! $link ) {
			return null;
		}
		$result = resolve(
			$link,
			new \DateTimeImmutable( 'now', wp_timezone() ),
			fn( $t ) => target_url( $t, $depth + 1 ),
			__NAMESPACE__ . '\\target_published'
		);
		return 302 === $result['status'] ? $result['url'] : null;
	}
	$post_id = target_post_id( $target );
	if ( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$url = get_permalink( $post );
		return $url ? $url : null;
	}
	return '' !== $target ? $target : null;
}

/**
 * Publication time of a target post, null if it is not published (yet).
 *
 * @param string $target Target.
 * @return int|null
 */
function target_published( $target ) {
	$post = get_post( target_post_id( $target ) );
	if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
		return null;
	}
	$ts = (int) get_post_time( 'U', true, $post );
	return 'publish' === $post->post_status || $ts > time() ? $ts : null;
}

/**
 * Short readable description of a target for lists.
 *
 * @param string $target Target.
 * @return string
 */
function target_label( $target ) {
	if ( 'home' === $target ) {
		return __( 'Home page', 'clean-short-links' );
	}
	$link_id = target_link_id( $target );
	if ( $link_id ) {
		$link = link_by_id( $link_id );
		if ( ! $link ) {
			return __( '(deleted short link)', 'clean-short-links' );
		}
		list( $p ) = prefixes();
		/* translators: 1: title of the short link, 2: its short address */
		return sprintf( __( 'Short link %1$s (/%2$s)', 'clean-short-links' ), '' !== $link['title'] ? '“' . $link['title'] . '”' : '', $p . '/' . $link['slug'] );
	}
	$post_id = target_post_id( $target );
	if ( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return __( '(deleted content)', 'clean-short-links' );
		}
		$title = get_the_title( $post );
		return '' !== $title ? $title : __( '(no title)', 'clean-short-links' );
	}
	return preg_replace( '#^https?://(www\.)?#', '', (string) $target );
}

/**
 * The full short URLs of a link.
 *
 * @param array $link Link.
 * @return array{link:string,qr:string,root:string,qr_upper:string}
 */
function short_urls( array $link ) {
	list( $p, $q ) = prefixes();
	$qr_slug       = '' !== ( $link['qr_slug'] ?? '' ) ? $link['qr_slug'] : $link['slug'];
	$qr            = home_url( '/' . $q . '/' . $qr_slug );
	return array(
		'link'     => home_url( '/' . $p . '/' . $link['slug'] ),
		'qr'       => $qr,
		'root'     => ! empty( $link['root'] ) && root_allowed() ? home_url( '/' . $link['slug'] ) : '',
		'qr_upper' => qr_uppercase( $qr ),
	);
}

/**
 * The QR address in capitals if that fits the compact alphanumeric QR mode – scheme, host
 * and short links are case-insensitive, so the uppercase form works the same.
 *
 * @param string $url URL.
 * @return string Uppercase URL, or the URL unchanged if it does not fit.
 */
function qr_uppercase( $url ) {
	$upper = strtoupper( $url );
	return preg_match( '#^[0-9A-Z $%*+\-./:]+$#', $upper ) && ! is_multisite_path_case_sensitive( $url ) ? $upper : $url;
}

/**
 * In a subdirectory network the site path is part of the URL and compared as typed by
 * WordPress – keep those URLs as they are.
 *
 * @param string $url URL.
 * @return bool
 */
function is_multisite_path_case_sensitive( $url ) {
	if ( ! is_multisite() || is_subdomain_install() ) {
		return false;
	}
	$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	return '/' !== $path && '' !== $path && false !== strpos( $url, $path );
}
