<?php
/**
 * Posts and pages: automatic short links on publishing, WordPress' own shortlink,
 * and the REST routes used by the block editor and the edit screen.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a short link for a post.
 *
 * @param int    $post_id Post ID.
 * @param string $kind    random, memorable or title; empty for the site's default.
 * @return int|\WP_Error Link ID.
 */
function create_for_post( $post_id, $kind = '' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new \WP_Error( 'missing', __( 'This content does not exist.', 'clean-short-links' ) );
	}
	$kind  = in_array( $kind, array( 'random', 'memorable', 'title' ), true ) ? $kind : default_kind( 'post:' . $post->ID, site_settings() );
	$title = plain_post_title( $post->ID );
	return save_link(
		array(
			'title'      => $title,
			'target'     => 'post:' . $post->ID,
			'slug'       => suggest_slug( $kind, $title ),
			'kind'       => $kind,
			'root'       => 'random' !== $kind && ! empty( site_settings()['root_default'] ),
			'start_mode' => 'publish',
		)
	);
}

/*
 * Automatic short links for the post types chosen in the settings.
 */
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || wp_is_post_revision( $post ) ) {
			return;
		}
		$s = site_settings();
		if ( ! in_array( $post->post_type, (array) $s['auto_types'], true ) || get_link_for_post( $post->ID ) ) {
			return;
		}
		create_for_post( $post->ID );
	},
	10,
	3
);

/*
 * "Copy short link" in WordPress and wp_get_shortlink() use our short link.
 */
add_filter(
	'pre_get_shortlink',
	function ( $shortlink, $id, $context ) {
		if ( 'query' === $context && is_singular() ) {
			$id = get_queried_object_id();
		}
		$link = $id ? get_link_for_post( (int) $id ) : null;
		return $link ? short_urls( $link )['link'] : $shortlink;
	},
	10,
	3
);

/**
 * Everything the editor needs to know about a link.
 *
 * @param array|null $link Link.
 * @return array|null
 */
function link_for_js( $link ) {
	if ( ! $link ) {
		return null;
	}
	$urls     = short_urls( $link );
	$problems = array();
	foreach ( $link['check']['items'] as $item ) {
		if ( 'ok' === ( $item['status'] ?? 'ok' ) ) {
			continue;
		}
		$what       = 'self' === $item['role'] ? __( 'Short link', 'clean-short-links' ) : ( 'main' === $item['role'] ? __( 'Target', 'clean-short-links' ) : ( 'fallback' === $item['role'] ? __( 'Fallback', 'clean-short-links' ) : $item['role'] ) );
		$why        = '' !== $item['message'] ? $item['message'] : ( $item['code'] ? 'HTTP ' . $item['code'] : '' );
		$problems[] = trim( $what . ': ' . $why, ': ' );
	}
	$channels = array();
	foreach ( $link['meta']['channels'] as $key ) {
		$channels[ $key ] = home_url( '/' . prefixes()[0] . '/' . $link['slug'] . '-' . $key );
	}
	return array(
		'id'         => $link['id'],
		'slug'       => $link['slug'],
		'kind'       => $link['kind'],
		'urls'       => $urls,
		'state'      => link_state( $link, time(), site_settings()['gone_days'] ),
		'rules'      => count( $link['rules'] ),
		'visits'     => totals( array( $link['id'] ), 30 )[ $link['id'] ] ?? 0,
		'edit'       => admin_url( 'admin.php?page=clean-short-links-edit&id=' . $link['id'] ),
		'qr'         => admin_url( 'admin.php?page=clean-short-links-edit&id=' . $link['id'] . '#csl-sec-qr' ),
		'root'       => ! empty( $link['root'] ),
		'paused'     => 'paused' === $link['status'],
		'start_mode' => $link['start_mode'],
		'starts_at'  => timestamp_to_local( $link['starts_at'] ),
		'ends_at'    => timestamp_to_local( $link['ends_at'] ),
		'max_clicks' => $link['max_clicks'],
		'clicks'     => $link['clicks'],
		'permanent'  => ! empty( $link['meta']['permanent'] ),
		'permanent_works' => permanent_allowed( $link ),
		'utm'        => ! empty( $link['meta']['utm'] ),
		'bio'        => ! empty( $link['meta']['bio'] ),
		'channels'   => $link['meta']['channels'],
		'channel_urls' => $channels,
		'check'      => array(
			'status'   => $link['check']['status'],
			/* translators: %s: time span, e.g. "3 hours" */
			'at'       => $link['check']['at'] ? sprintf( __( '%s ago', 'clean-short-links' ), human_time_diff( $link['check']['at'] ) ) : '',
			'problems' => $problems,
		),
	);
}

add_action(
	'rest_api_init',
	function () {
		$manage = fn() => current_user_can( capability() );

		register_rest_route(
			'clean-short-links/v1',
			'/suggest',
			array(
				'methods'             => 'GET',
				'permission_callback' => $manage,
				'args'                => array(
					'kind'  => array(
						'type'    => 'string',
						'default' => 'random',
					),
					'title'  => array(
						'type'    => 'string',
						'default' => '',
					),
					// Without a title of its own: the plain title of the post the link leads to.
					'target' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'callback'            => function ( $r ) {
					$title   = trim( (string) $r['title'] );
					$post_id = target_post_id( clean_target( (string) $r['target'] ) );
					if ( '' === $title && $post_id ) {
						$title = plain_post_title( $post_id );
					}
					return array( 'slug' => suggest_slug( $r['kind'], $title ) );
				},
			)
		);

		register_rest_route(
			'clean-short-links/v1',
			'/links',
			array(
				'methods'             => 'GET',
				'permission_callback' => $manage,
				'args'                => array(
					'search'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'skip'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'callback'            => function ( $r ) {
					$out = array();
					foreach ( list_links(
						array(
							'search'   => $r['search'],
							'per_page' => 8,
						)
					)['items'] as $l ) {
						if ( (int) $l['id'] === (int) $r['skip'] ) {
							continue;
						}
						$out[] = array(
							'id'    => $l['id'],
							'title' => '' !== $l['title'] ? $l['title'] : $l['slug'],
							'slug'  => $l['slug'],
							'label' => target_label( 'link:' . $l['id'] ),
						);
					}
					return $out;
				},
			)
		);

		register_rest_route(
			'clean-short-links/v1',
			'/check',
			array(
				'methods'             => 'GET',
				'permission_callback' => $manage,
				'args'                => array(
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
					'id'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'callback'            => function ( $r ) {
					$c         = check_slug( $r['slug'], (int) $r['id'] );
					$c['root'] = $c['ok'] && root_allowed() ? root_collision( $c['slug'] ) : '';
					return $c;
				},
			)
		);

		register_rest_route(
			'clean-short-links/v1',
			'/post/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => fn( $r ) => current_user_can( 'edit_post', (int) $r['id'] ),
					'callback'            => fn( $r ) => array( 'link' => link_for_js( get_link_for_post( (int) $r['id'] ) ) ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => fn( $r ) => current_user_can( 'edit_post', (int) $r['id'] ) && current_user_can( capability() ),
					'args'                => array(
						'kind' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'callback'            => function ( $r ) {
						$id = create_for_post( (int) $r['id'], $r['kind'] );
						return is_wp_error( $id ) ? $id : array( 'link' => link_for_js( link_by_id( $id ) ) );
					},
				),
			)
		);

		// Changes from the sidebar of the block editor.
		register_rest_route(
			'clean-short-links/v1',
			'/link/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => function ( $r ) {
					$old = link_by_id( (int) $r['id'] );
					if ( ! $old ) {
						return new \WP_Error( 'missing', __( 'This short link no longer exists.', 'clean-short-links' ), array( 'status' => 404 ) );
					}
					$in = map_deep( (array) $r->get_json_params(), fn( $v ) => is_string( $v ) ? sanitize_text_field( $v ) : $v );
					$id = save_link( editor_changes( $old, $in ) );
					if ( is_wp_error( $id ) ) {
						$id->add_data( array( 'status' => 400 ) );
						return $id;
					}
					if ( ! empty( $in['reset_clicks'] ) ) {
						reset_clicks( $id );
					}
					$saved = link_by_id( $id );
					if ( $saved && '' === $saved['check']['status'] ) {
						check_link( $saved );
					}
					return array( 'link' => link_for_js( link_by_id( $id ) ) );
				},
			)
		);

		register_rest_route(
			'clean-short-links/v1',
			'/link/(?P<id>\d+)/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => $manage,
				'callback'            => function ( $r ) {
					$link = link_by_id( (int) $r['id'] );
					if ( ! $link ) {
						return new \WP_Error( 'missing', __( 'This short link no longer exists.', 'clean-short-links' ), array( 'status' => 404 ) );
					}
					check_link( $link );
					return array( 'link' => link_for_js( link_by_id( $link['id'] ) ) );
				},
			)
		);
	}
);

/*
 * The panel in the block editor.
 */
add_action(
	'enqueue_block_editor_assets',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! post_type_supports( (string) $screen->post_type, 'editor' ) || ! is_post_type_viewable( (string) $screen->post_type ) ) {
			return;
		}
		$url = plugins_url( 'assets/', CLEAN_SHORT_LINKS_FILE );
		wp_enqueue_script( 'clean-short-links-editor', $url . 'editor.js', array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch' ), asset_ver( 'editor.js' ), true );
		wp_localize_script(
			'clean-short-links-editor',
			'CleanShortLinksEditor',
			array(
				'canCreate' => current_user_can( capability() ),
				'kind'      => default_kind( 'post:1', site_settings() ),
				'root'      => root_allowed(),
				'bio'       => ! empty( site_settings()['bio_enabled'] ),
				'base'      => home_url( '/' . prefixes()[0] . '/' ),
				'i18n'      => array(
					'panel'      => __( 'Short link', 'clean-short-links' ),
					'none'       => __( 'This content has no short link yet.', 'clean-short-links' ),
					'noRights'   => __( 'Ask an editor to create a short link.', 'clean-short-links' ),
					'random'     => __( 'Random', 'clean-short-links' ),
					'memorable'  => __( 'Memorable', 'clean-short-links' ),
					'title'      => __( 'From the title', 'clean-short-links' ),
					'create'     => __( 'Create short link', 'clean-short-links' ),
					'copy'       => __( 'Copy', 'clean-short-links' ),
					'copied'     => __( 'Copied', 'clean-short-links' ),
					'short'      => __( 'Short form', 'clean-short-links' ),
					'qr'         => __( 'QR code', 'clean-short-links' ),
					/* translators: %d: number of visits */
					'visits'     => __( '%d visits in the last 30 days', 'clean-short-links' ),
					'waiting'    => __( 'Starts later', 'clean-short-links' ),
					'expired'    => __( 'Expired', 'clean-short-links' ),
					'paused'     => __( 'Paused', 'clean-short-links' ),
					'scheduled'  => __( 'Changes its target by date and time', 'clean-short-links' ),
					'saveFirst'  => __( 'Save the content once to create a short link.', 'clean-short-links' ),
					'collision'  => __( 'The short form is covered by this content’s own address and does not work – the link with prefix always does.', 'clean-short-links' ),
					'createFail' => __( 'The short link could not be created.', 'clean-short-links' ),
					'address'     => __( 'Short address', 'clean-short-links' ),
					'keepOld'     => __( 'Keep the old address as an alias', 'clean-short-links' ),
					'rootToggle'  => __( 'Short form without prefix', 'clean-short-links' ),
					/* translators: %s: what already uses the address, e.g. the title of a page */
					'rootTaken'   => __( 'Taken by %s – the short form will not work.', 'clean-short-links' ),
					'active'      => __( 'Active', 'clean-short-links' ),
					'start'       => __( 'Starts', 'clean-short-links' ),
					'startPub'    => __( 'With the publication of this content.', 'clean-short-links' ),
					/* translators: %s: date and time the content is scheduled for */
					'startPlanned' => __( 'With the scheduled publication on %s.', 'clean-short-links' ),
					/* translators: %s: date and time */
					'endsAt'      => __( 'Ends on %s – change it on the edit screen.', 'clean-short-links' ),
					'limit'       => __( 'Click limit', 'clean-short-links' ),
					'limitHelp'   => __( '0 = no limit', 'clean-short-links' ),
					/* translators: 1: clicks so far, 2: limit */
					'used'        => __( '%1$d of %2$d clicks used', 'clean-short-links' ),
					'reset'       => __( 'Reset counter', 'clean-short-links' ),
					'permanent'   => __( 'Permanent redirect (301)', 'clean-short-links' ),
					'permHelp'    => __( 'Only takes effect without end, rules and limit.', 'clean-short-links' ),
					'bioToggle'   => __( 'Show on the link-in-bio page', 'clean-short-links' ),
					'save'        => __( 'Save short link', 'clean-short-links' ),
					'saved'       => __( 'Saved.', 'clean-short-links' ),
					'unsaved'     => __( 'Saved together with the content, or right away with the button.', 'clean-short-links' ),
					'saveFail'    => __( 'The short link could not be saved.', 'clean-short-links' ),
					'checkNow'    => __( 'Check now', 'clean-short-links' ),
					'checkOk'     => __( 'Link check: everything reachable', 'clean-short-links' ),
					'checkWarn'   => __( 'Link check: please have a look', 'clean-short-links' ),
					'checkBad'    => __( 'Link check: a target is not reachable', 'clean-short-links' ),
					'checkNone'   => __( 'Not checked yet', 'clean-short-links' ),
					'more'        => __( 'More: end, rules, QR design, invitations, statistics', 'clean-short-links' ),
					'exhausted'   => __( 'Limit reached', 'clean-short-links' ),
					'unreachable' => __( 'Temporarily unreachable', 'clean-short-links' ),
					'gone'        => __( 'Permanently unreachable', 'clean-short-links' ),
				),
			)
		);
	}
);

/* ---------------------------------------------------------------------------
 * Link search while writing
 * ------------------------------------------------------------------------ */

/**
 * Short links matching a search, as suggestions for the link search of the editors.
 *
 * @param string $search Search term.
 * @param int    $limit  How many.
 * @return array[] Each with id, title, url and label.
 */
function link_suggestions( $search, $limit = 6 ) {
	$search = trim( (string) $search );
	if ( '' === $search || ! current_user_can( 'edit_posts' ) ) {
		return array();
	}
	$out = array();
	foreach ( list_links(
		array(
			'search'   => $search,
			'per_page' => $limit,
		)
	)['items'] as $l ) {
		if ( 'active' !== $l['status'] ) {
			continue;
		}
		$name  = '' !== $l['title'] ? $l['title'] : bio_label( $l );
		$out[] = array(
			'id'    => $l['id'],
			/* translators: 1: title of the short link, 2: its address without https:// */
			'title' => sprintf( __( '%1$s – %2$s', 'clean-short-links' ), $name, preg_replace( '#^https?://#', '', short_urls( $l )['link'] ) ),
			'url'   => short_urls( $l )['link'],
		);
	}
	return $out;
}

/*
 * Block editor: the link search (paragraphs, buttons, navigation, images …) asks
 * /wp/v2/search. Short links are added to the first page of results for posts, labelled
 * "Short link"; inserting one inserts the short address.
 */
add_filter(
	'rest_post_dispatch',
	function ( $response, $server, $request ) {
		if ( '/wp/v2/search' !== $request->get_route() || ! ( $response instanceof \WP_REST_Response ) || $response->is_error() ) {
			return $response;
		}
		$type = (string) $request->get_param( 'type' );
		if ( $request->get_param( 'csl_skip' ) || (int) $request->get_param( 'page' ) > 1 || ( '' !== $type && 'post' !== $type ) ) {
			return $response;
		}
		$items = link_suggestions( (string) $request->get_param( 'search' ) );
		if ( ! $items ) {
			return $response;
		}
		$data = (array) $response->get_data();
		foreach ( $items as $i ) {
			$data[] = array(
				'id'      => 'clean-short-link-' . $i['id'],
				'title'   => $i['title'],
				'url'     => $i['url'],
				'type'    => 'post',
				'subtype' => __( 'Short link', 'clean-short-links' ),
			);
		}
		$response->set_data( $data );
		return $response;
	},
	10,
	3
);

/*
 * Classic editor: the "Insert/edit link" dialog.
 */
add_filter(
	'wp_link_query',
	function ( $results, $query ) {
		if ( ! empty( $query['offset'] ) || empty( $query['s'] ) ) {
			return $results;
		}
		foreach ( link_suggestions( $query['s'] ) as $i ) {
			$results[] = array(
				'ID'        => 0,
				'title'     => $i['title'],
				'permalink' => $i['url'],
				'info'      => __( 'Short link', 'clean-short-links' ),
			);
		}
		return $results;
	},
	10,
	2
);
