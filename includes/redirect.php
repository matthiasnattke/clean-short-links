<?php
/**
 * Front end: turns a short address into a redirect and counts the visit.
 *
 * - example.com/s/slug and example.com/q/slug are answered as early as possible: as soon
 *   as WordPress can build permalinks (setup_theme) – before the theme, init, the query
 *   and any template.
 *   Only what needs a rendered page (a dead link's 404, the maintenance page) waits for
 *   WordPress to parse the request.
 * - The visit is counted after the visitor already has the redirect.
 * - The short form example.com/slug is only looked at when WordPress found nothing there
 *   (a 404), so existing content always wins.
 * - Short addresses of other sites of the network that are also offered on this host are
 *   found through the network-wide routes table.
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
 * Host of the current request, lower case and without port.
 *
 * @return string
 */
function current_host() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	return preg_replace( '/:\d+$/', '', $host );
}

/**
 * Would Clean Maintenance pause short links right now? Then the request takes the normal
 * way, so the maintenance page can be rendered.
 *
 * @return bool
 */
function maintenance_pauses() {
	return maintenance_available() && 'pause' === maintenance_rules()['during'] && \clean_maintenance_blocks_visitor();
}

/**
 * Status set for the 404/410 page that follows a dead short link.
 *
 * @var int
 */
$GLOBALS['clean_short_links_status'] = 0;

/*
 * Registration switched off: the sign-up page – where WordPress also sends unknown
 * subdomains – can lead to the home page of the network instead.
 */
add_action(
	'before_signup_header',
	function () {
		if ( ! is_multisite() || empty( network_settings()['signup_home'] ) || 'none' !== get_site_option( 'registration', 'none' ) ) {
			return;
		}
		wp_safe_redirect( network_home_url( '/' ), 302, 'Clean Short Links' );
		exit;
	}
);

/*
 * A subdomain without a site of its own (sunrise.php): only short links offered there,
 * everything else leads to the main site. Runs before everything else of the plugin.
 */
add_action(
	'setup_theme',
	function () {
		if ( ! defined( 'CLEAN_SHORT_LINKS_ALIAS' ) || current_host() !== CLEAN_SHORT_LINKS_ALIAS || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		handle_alias( CLEAN_SHORT_LINKS_ALIAS );
	},
	0
);

/**
 * Answers a request on a subdomain without a site. Never returns.
 *
 * @param string $host Host.
 */
function handle_alias( $host ) {
	$req    = parse_alias_path( request_path(), prefixes()[1] );
	$route  = $req ? find_route( $host, $req[1] ) : null;
	$status = 0;
	if ( $route && get_site( $route['blog_id'] ) ) {
		switch_to_blog( $route['blog_id'] );
		$link = link_by_id( $route['link_id'] );
		if ( $link ) {
			$status = (int) handle( array( $link, $route['kind'] ), 'qr' === $route['kind'] ? SOURCE_QR : $req[0], $req[1], true ); // Returns only for a dead link.
			$status = $status > 0 ? $status : 404;
		}
		restore_current_blog();
	}
	if ( $status ) {
		nocache_headers();
		wp_die( esc_html__( 'This short link is not available.', 'clean-short-links' ), '', array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An HTTP status code, not output.
	}
	// Everything else: the page set for this subdomain, otherwise the main site.
	$home = (string) ( network_settings()['alias_homes'][ $host ] ?? '' );
	$home = '' === $home ? network_home_url( '/' ) : ( '/' === $home[0] ? network_home_url( $home ) : $home );
	nocache_headers();
	wp_redirect( $home, 302, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Set by a network administrator.
	exit;
}

/*
 * The fast way: answer before the theme and init are loaded. Everything that needs a page
 * (404 of a dead link, maintenance page, link-in-bio) is left to the normal way below.
 */
add_action(
	'setup_theme',
	function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$req = current_request();
		if ( ! $req || SOURCE_ROOT === $req[0] || maintenance_pauses() ) {
			return;
		}
		$s = site_settings();
		if ( ! empty( $s['bio_enabled'] ) && SOURCE_LINK === $req[0] && $req[1] === $s['bio_slug'] ) {
			return;
		}
		$found = find_slug( $req[1] );
		if ( $found ) {
			handle( $found, $req[0], $req[1], true );
			return;
		}
		handle_route( current_host(), $req[1], $req[0], true );
	},
	1
);

/*
 * The home page of a short domain leads to the main site of the network.
 */
add_action(
	'setup_theme',
	function () {
		if ( ! is_multisite() || is_admin() || wp_doing_ajax() || wp_doing_cron() || is_main_site() ) {
			return;
		}
		if ( ! in_array( get_current_blog_id(), array_map( 'intval', (array) network_settings()['short_sites'] ), true ) ) {
			return;
		}
		if ( '/' === request_path() && ! is_user_logged_in() ) {
			nocache_headers();
			wp_redirect( network_home_url( '/' ), 302, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The main site of the own network.
			exit;
		}
	},
	2
);

/**
 * A short address offered on this host by another site of the network.
 *
 * @param string $host   Host.
 * @param string $slug   Short address.
 * @param int    $source Source.
 * @param bool   $fast   On the fast way: leave dead links to the normal way.
 * @return bool True when there is such a link (then it was dead – redirects never return).
 */
function handle_route( $host, $slug, $source, $fast ) {
	if ( ! is_multisite() ) {
		return false;
	}
	$route = find_route( $host, $slug );
	if ( ! $route || ! get_site( $route['blog_id'] ) ) {
		return false;
	}
	switch_to_blog( $route['blog_id'] );
	$link  = link_by_id( $route['link_id'] );
	$found = $link && ( SOURCE_ROOT !== $source || ! empty( $link['root'] ) );
	if ( $found ) {
		handle( array( $link, $route['kind'] ), $source, $slug, $fast ); // Returns only for a dead link.
	}
	restore_current_blog();
	return $found;
}

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
		maybe_pause_for_maintenance();
		$s = site_settings();
		if ( ! empty( $s['bio_enabled'] ) && SOURCE_LINK === $req[0] && $req[1] === $s['bio_slug'] ) {
			render_bio();
		}
		$found = find_slug( $req[1] );
		if ( ! $found ) {
			if ( handle_route( current_host(), $req[1], $req[0], false ) ) {
				$wp->query_vars = array( 'error' => '404' );
				return;
			}
			// Unknown short address: the default target of the site, if set – otherwise WordPress' 404 page.
			$default = default_target_url( 'unknown' );
			if ( $default ) {
				go_to( $default, null, $req[0], false );
			}
			maybe_maintenance_for_dead_link( 404 );
			return;
		}
		$result = handle( $found, $req[0], $req[1] );
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
		if ( ! $found ) {
			maybe_pause_for_maintenance();
			handle_route( current_host(), $req[1], SOURCE_ROOT, false );
			return;
		}
		if ( ( $found[0] && empty( $found[0]['root'] ) ) || 'retired' === $found[1] ) {
			return;
		}
		maybe_pause_for_maintenance();
		handle( $found, SOURCE_ROOT, $req[1] );
	},
	-10 // Before the maintenance mode of Clean Maintenance (priority 0).
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
		if ( 404 === (int) $code && 503 === $status ) {
			header( 'Retry-After: 3600' );
			return $protocol . ' 503 Service Unavailable';
		}
		return $header;
	},
	10,
	4
);

/**
 * The site's default target as URL, if it applies to this case.
 *
 * @param string $case unknown (no such short link) or inactive (not started, expired, used up, paused, retired).
 * @return string|null
 */
function default_target_url( $case ) {
	$s = site_settings();
	if ( empty( $s[ 'default_' . $case ] ) ) {
		return null;
	}
	if ( 'home' === $s['default_mode'] ) {
		return home_url( '/' );
	}
	if ( 'custom' === $s['default_mode'] && '' !== $s['default_url'] ) {
		return target_url( $s['default_url'] );
	}
	return null;
}

/**
 * Redirects or prepares the dead-link page.
 *
 * Invitations follow their main link: target, rules, time window and fallback come from
 * there, their own click limit and expiry still apply.
 *
 * @param array  $found  Result of find_slug().
 * @param int    $source Where the visit came from.
 * @param string $slug   The short address that was requested.
 * @param bool   $fast   On the fast way: a dead link without a default target is left to
 *                       the normal way (returns -1), which can render the 404 page.
 * @return int 0 when redirected (never returns then), otherwise the status of the dead link.
 */
function handle( array $found, $source, $slug = '', $fast = false ) {
	list( $link, $kind ) = $found;
	$channel = $link && in_array( $kind, array( 'channel', 'place' ), true ) && 0 === strpos( $slug, $link['slug'] . '-' ) ? substr( $slug, strlen( $link['slug'] ) + 1 ) : '';
	if ( 'place' === $kind && '' !== $channel ) {
		$channel = 'p:' . $channel; // A location: counted as "@p:<key>".
	}
	if ( isset( $_GET['cslsrc'] ) && 'bio' === $_GET['cslsrc'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marks a visit from the link-in-bio page.
		$source = SOURCE_BIO;
	}
	if ( $link && ! empty( $link['parent_id'] ) ) {
		$parent = link_by_id( $link['parent_id'] );
		$link   = $parent ? merge_parent( $link, $parent ) : null;
	}
	if ( 'retired' === $kind || ! $link ) {
		return dead_end( 410, $link, $source, $fast );
	}
	if ( 'qr' === $kind ) {
		$source = SOURCE_QR;
	}
	if ( isset( $_GET['nfc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marks a visit from an NFC tag.
		$source = SOURCE_NFC;
	}
	$result = resolve(
		$link,
		new \DateTimeImmutable( 'now', wp_timezone() ),
		__NAMESPACE__ . '\\target_url',
		__NAMESPACE__ . '\\target_published'
	);
	if ( 302 !== $result['status'] ) {
		return dead_end( $result['status'], $link, $source, $fast );
	}
	// The link check found this target unreachable: the link's own fallback, otherwise the
	// default target of the site, otherwise the 404 page.
	if ( ! empty( site_settings()['skip_broken'] ) && target_broken( $link, $result['target'] ) ) {
		$fallback = $link['fallback'] ?? '';
		$url      = '' !== $fallback && $fallback !== $result['target'] && ! target_broken( $link, $fallback ) ? target_url( $fallback ) : null;
		if ( ! $url ) {
			// Temporarily: "try again later" (503). Unreachable for too long: "gone" (410).
			return dead_end( 'gone' === unreachable( $link, time(), site_settings()['gone_days'] ) ? 410 : 503, $link, $source, $fast );
		}
		$result = array(
			'status' => 302,
			'url'    => $url,
			'state'  => 'unreachable',
			'target' => $fallback,
		);
	}
	// Social networks and messengers get a preview – from the post itself, or what was
	// entered for an address outside the site. Without one they simply follow the redirect.
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	if ( is_preview_bot( $ua ) ) {
		$data = preview_data( $link, $result['target'] );
		if ( $data ) {
			render_preview( $data, $result['url'] );
		}
	}
	// Only real uses count – not the way to the fallback page of an expired link.
	$used = 'default' === $result['state'] || 0 === strpos( $result['state'], 'rule-' );
	// Other targets by device, operating system or screen width – for the main target;
	// rules by date and time come first.
	if ( 'default' === $result['state'] && ! empty( $link['meta']['variants'] ) ) {
		$platform = isset( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ) ) : '';
		$plan     = variant_plan( $link['meta']['variants'], device_key( device_class( $ua ) ), os_class( $ua, $platform ) );
		if ( $plan && isset( $plan['target'] ) ) {
			$v = target_url( $plan['target'] );
			if ( $v ) {
				$result['url'] = $v;
			}
		} elseif ( $plan ) {
			$options = array();
			foreach ( $plan['screen'] as $o ) {
				$v = target_url( $o[2] );
				if ( $v ) {
					$options[] = array( $o[0], $o[1], final_url( $v, $link, $source, $channel ) );
				}
			}
			$fallback = null !== $plan['fallback'] ? target_url( $plan['fallback'] ) : null;
			$fallback = final_url( $fallback ? $fallback : $result['url'], $link, $source, $channel );
			if ( $options ) {
				render_chooser( $options, $fallback, $link, $source, $channel );
			}
		}
	}
	go_to( final_url( $result['url'], $link, $source, $channel ), $link, $source, $used, $channel, 'default' === $result['state'] && permanent_allowed( $link ) ? 301 : 302, true );
	return 0;
}

/**
 * The address a visitor is sent to: with UTM parameters if wanted, with the query
 * parameters of the short link passed on, and through the filter.
 *
 * @param string     $url     Target URL.
 * @param array|null $link    Link.
 * @param int        $source  Source.
 * @param string     $channel Channel key.
 * @return string
 */
function final_url( $url, $link, $source, $channel = '' ) {
	if ( $link && ! empty( $link['meta']['utm'] ) ) {
		$url = add_utm( $url, utm_params( $link, $source, $channel ) );
	}
	$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameters are only passed on to the target.
	unset( $query['cslsrc'], $query['nfc'] );
	if ( ! empty( site_settings()['pass_query'] ) && $query ) {
		$url = add_query_arg( map_deep( $query, 'rawurlencode' ), $url );
	}
	/**
	 * Filters the address a short link leads to right now.
	 *
	 * @param string     $url    Target URL.
	 * @param array|null $link   The link, null for an unknown short address.
	 * @param int        $source 0 link, 1 QR code, 2 short form on the main address, 3 link-in-bio page, 4 NFC tag.
	 */
	return (string) apply_filters( 'clean_short_links_redirect', $url, $link, $source );
}

/**
 * A tiny page that lets the browser pick the target by its screen width – only for links
 * with such variants. About 1 KB, no external files, a strict content security policy, and
 * without JavaScript the fallback. Counts the visit once. Never returns.
 *
 * @param array[]    $options  [min width, max width, URL] in order.
 * @param string     $fallback URL when none fits.
 * @param array      $link     Link.
 * @param int        $source   Source.
 * @param string     $channel  Channel key.
 */
function render_chooser( array $options, $fallback, array $link, $source, $channel ) {
	$js   = '(function(){var d=JSON.parse(document.getElementById("d").textContent),w=(window.screen&&screen.width)||window.innerWidth||0,u=d.f,i,o;for(i=0;i<d.o.length;i++){o=d.o[i];if((!o[0]||w>=o[0])&&(!o[1]||w<=o[1])){u=o[2];break}}location.replace(u)})();';
	$hash = base64_encode( hash( 'sha256', $js, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Hash for the content security policy.
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( "Content-Security-Policy: default-src 'none'; script-src 'sha256-" . $hash . "'; base-uri 'none'; form-action 'none'" );
	$data = wp_json_encode(
		array(
			'o' => $options,
			'f' => $fallback,
		),
		JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
	);
	$host = (string) wp_parse_url( $fallback, PHP_URL_HOST );
	echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $host ) . '</title>';
	echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $fallback ) . '"></noscript>';
	echo '<script type="application/json" id="d">' . $data . '</script><script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML characters escaped; fixed script.
	echo '</head><body><p><a href="' . esc_url( $fallback ) . '">' . esc_html( $host ) . '</a></p></body></html>';
	finish_and_count( true, $link, $source, $channel );
	exit;
}

/**
 * A link that does not lead anywhere right now: the site's default target, or 404/410.
 *
 * @param int        $status 404, 410 or 503.
 * @param array|null $link   Link.
 * @param int        $source Source.
 * @param bool       $fast   On the fast way: leave it to the normal way.
 * @return int Status (only when not redirected), -1 when left to the normal way.
 */
function dead_end( $status, $link, $source, $fast = false ) {
	$default = default_target_url( 'inactive' );
	if ( $default ) {
		go_to( final_url( $default, null, $source ), $link, $source, false );
	}
	if ( $fast ) {
		return -1;
	}
	maybe_maintenance_for_dead_link( $status );
	$GLOBALS['clean_short_links_status'] = $status;
	nocache_headers();
	return $status;
}

/**
 * Sends the visitor on. Never returns.
 *
 * @param string     $url    Target URL, already final (see final_url()).
 * @param array|null $link   Link.
 * @param int        $source Source.
 * @param bool       $count   Count the visit.
 * @param string     $channel Channel key of a channel address.
 * @param int        $code    302, or 301 for permanent links.
 * @param bool       $final   The URL went through final_url() already.
 */
function go_to( $url, $link, $source, $count, $channel = '', $code = 302, $final = false ) {
	if ( ! $final && null === $link ) {
		$url = final_url( $url, null, $source );
	}
	if ( 301 === $code ) {
		// Permanent: browsers and search engines may remember it (visits from them are then no longer counted).
		header( 'Cache-Control: public, max-age=86400' );
	} else {
		nocache_headers();
	}
	header( 'X-Robots-Tag: noindex' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	// Targets may be on other hosts by design, so this is not wp_safe_redirect().
	wp_redirect( $url, $code, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	finish_and_count( $count, $link, $source, $channel );
	exit;
}

/**
 * Hands the answer to the visitor first, then counts – the redirect does not wait for
 * the database.
 *
 * @param bool       $count   Count the visit.
 * @param array|null $link    Link.
 * @param int        $source  Source.
 * @param string     $channel Channel key.
 */
function finish_and_count( $count, $link, $source, $channel ) {
	if ( ! $count || ! $link ) {
		return;
	}
	$visit = visit_data( $source, $channel );
	if ( ! $visit ) {
		return;
	}
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	}
	record_click( $link['id'], $visit['source'], $visit['device'], $visit['ref'] );
	add_click( $link['id'] );
}

/**
 * What is counted for a visit – without IP address, cookie or user ID. Nothing for
 * robots, HEAD requests, prefetching and (unless wanted) editors. Visitors who ask for
 * privacy (Global Privacy Control or Do Not Track) are counted without the referring host;
 * the network can switch referring hosts off for everyone.
 *
 * @param int    $source  Source.
 * @param string $channel Channel key – stored in place of the referring host as "@key".
 * @return array{source:int,device:int,ref:string}|null
 */
function visit_data( $source, $channel = '' ) {
	$h      = function ( $key ) {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	};
	$method = '' !== $h( 'REQUEST_METHOD' ) ? $h( 'REQUEST_METHOD' ) : 'GET';
	$ua     = $h( 'HTTP_USER_AGENT' );
	if ( 'GET' !== $method || is_bot( $ua ) ) {
		return null;
	}
	if ( false !== stripos( $h( 'HTTP_SEC_PURPOSE' ) . $h( 'HTTP_PURPOSE' ) . $h( 'HTTP_X_MOZ' ) . $h( 'HTTP_X_PURPOSE' ), 'prefetch' ) ) {
		return null;
	}
	if ( empty( site_settings()['count_editors'] ) && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return null;
	}
	$private = '1' === $h( 'HTTP_SEC_GPC' ) || '1' === $h( 'HTTP_DNT' ) || empty( network_settings()['referrers'] );
	$ref     = $private || ! isset( $_SERVER['HTTP_REFERER'] ) ? '' : referrer_host( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) );
	if ( '' !== $channel ) {
		$ref = '@' . $channel;
	}
	return array(
		'source' => (int) $source,
		'device' => device_class( $ua ),
		'ref'    => $ref,
	);
}
