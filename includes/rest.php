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
 * @param string $kind    random, memorable or title.
 * @return int|\WP_Error Link ID.
 */
function create_for_post( $post_id, $kind = 'random' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return new \WP_Error( 'missing', __( 'This content does not exist.', 'clean-short-links' ) );
	}
	$kind  = in_array( $kind, array( 'random', 'memorable', 'title' ), true ) ? $kind : 'random';
	$title = get_the_title( $post );
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
		create_for_post( $post->ID, $s['auto_kind'] );
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
	$urls = short_urls( $link );
	return array(
		'id'     => $link['id'],
		'slug'   => $link['slug'],
		'urls'   => $urls,
		'state'  => link_state( $link, time(), site_settings()['gone_days'] ),
		'rules'  => count( $link['rules'] ),
		'visits' => totals( array( $link['id'] ), 30 )[ $link['id'] ] ?? 0,
		'edit'   => admin_url( 'admin.php?page=clean-short-links-edit&id=' . $link['id'] ),
		'qr'     => admin_url( 'admin.php?page=clean-short-links-edit&id=' . $link['id'] . '#csl-sec-qr' ),
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
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'callback'            => fn( $r ) => array( 'slug' => suggest_slug( $r['kind'], $r['title'] ) ),
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
							'default' => 'random',
						),
					),
					'callback'            => function ( $r ) {
						$id = create_for_post( (int) $r['id'], $r['kind'] );
						return is_wp_error( $id ) ? $id : array( 'link' => link_for_js( link_by_id( $id ) ) );
					},
				),
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
				'kind'      => site_settings()['auto_kind'],
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
					'edit'       => __( 'Edit, schedule, statistics', 'clean-short-links' ),
					/* translators: %d: number of visits */
					'visits'     => __( '%d visits in the last 30 days', 'clean-short-links' ),
					'waiting'    => __( 'Starts later', 'clean-short-links' ),
					'expired'    => __( 'Expired', 'clean-short-links' ),
					'paused'     => __( 'Paused', 'clean-short-links' ),
					'scheduled'  => __( 'Changes its target by date and time', 'clean-short-links' ),
					'saveFirst'  => __( 'Save the content once to create a short link.', 'clean-short-links' ),
					'collision'  => __( 'The short form is covered by this content’s own address and does not work – the link with prefix always does.', 'clean-short-links' ),
					'createFail' => __( 'The short link could not be created.', 'clean-short-links' ),
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
