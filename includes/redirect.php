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
 * - example.com/w/slug (with Clean Mobile Connect) shows the link's Wallet card; it
 *   follows the same ways and dead ends as /s/, see handle_wallet().
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
	list( $p, $q, $w ) = prefixes();
	return parse_path( request_path(), (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), $p, $q, $w );
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
 * A subdomain without a site of its own (sunrise.php) or a short domain: only short links
 * offered there, everything else leads to the main site. Runs before everything else of the plugin.
 */
add_action(
	'setup_theme',
	function () {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( defined( 'CLEAN_SHORT_LINKS_ALIAS' ) && current_host() === CLEAN_SHORT_LINKS_ALIAS ) {
			handle_alias( CLEAN_SHORT_LINKS_ALIAS );
		}
		// A short domain answers like a subdomain without a site: short links without prefix
		// (q/ and w/ for codes and Wallet, any other prefix as fallback), everything else to the main site.
		// Sign-in, admin and REST stay with WordPress.
		if ( is_short_site() && ! is_admin() && ! wp_doing_ajax() && ! isset( $_GET['rest_route'] ) && ! is_system_path( request_path() ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routing.
			handle_alias( current_host() );
		}
	},
	0
);

/**
 * Answers a request on a subdomain without a site or a short domain. Never returns.
 *
 * @param string $host Host.
 */
function handle_alias( $host ) {
	list( , $q, $w ) = prefixes();

	$req    = parse_alias_path( request_path(), $q, $w );
	// A short domain first answers its own links (e.g. those of the Nextcloud sync), without prefix.
	if ( $req && ! defined( 'CLEAN_SHORT_LINKS_ALIAS' ) && is_short_site() ) {
		$own = find_slug( $req[1] );
		if ( $own ) {
			$status = (int) handle_found( $own, $req[0], $req[1], true ); // Returns only for a dead link.
			render_short_home( $status > 0 ? $status : 404, true );
			nocache_headers();
			wp_die( esc_html__( 'This short link is not available.', 'clean-short-links' ), '', array( 'response' => $status > 0 ? $status : 404 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An HTTP status code, not output.
		}
	}
	$route  = $req ? find_route( $host, $req[1] ) : null;
	if ( $req && ! $route ) {
		nextcloud_gone_redirect( $host, $req[1] ); // A deleted Nextcloud link: to the Nextcloud instance.
	}
	$status = 0;
	if ( $route && site_serves( $route['blog_id'] ) ) {
		switch_to_blog( $route['blog_id'] );
		$link = link_by_id( $route['link_id'] );
		if ( $link ) {
			$source = 'qr' === $route['kind'] && SOURCE_WALLET_ADD !== $req[0] ? SOURCE_QR : $req[0];
			$status = (int) handle_found( array( $link, $route['kind'] ), $source, $req[1], true ); // Returns only for a dead link.
			$status = $status > 0 ? $status : 404;
		}
		restore_current_blog();
	}
	if ( $status && ! defined( 'CLEAN_SHORT_LINKS_ALIAS' ) && is_short_site() ) {
		render_short_home( $status, true );
	}
	if ( $status ) {
		nocache_headers();
		wp_die( esc_html__( 'This short link is not available.', 'clean-short-links' ), '', array( 'response' => $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An HTTP status code, not output.
	}
	// Everything else: the page set for this subdomain, otherwise the main site.
	$home = (string) ( network_settings()['alias_homes'][ $host ] ?? '' );
	// A short domain says in a few words what it is – on its home page, and instead of an
	// unknown short address.
	if ( '' === $home && ! defined( 'CLEAN_SHORT_LINKS_ALIAS' ) && is_short_site() ) {
		render_short_home( '' === trim( request_path(), '/' ) ? 200 : 404 );
	}
	$home = '' === $home ? network_home_url( '/' ) : ( '/' === $home[0] ? network_home_url( $home ) : $home );
	nocache_headers();
	wp_redirect( $home, 302, 'Clean Short Links' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Set by a network administrator.
	exit;
}

/**
 * The legal links of a short domain: the addresses from the settings, else a page of this
 * site named "impressum"/"imprint" and its privacy policy page.
 *
 * @param array $t Texts of the page in the visitor's language.
 * @return array<string,string> Label => URL.
 */
function short_legal_links( array $t ) {
	$s       = site_settings();
	$imprint = (string) $s['imprint_url'];
	if ( '' === $imprint ) {
		foreach ( array( 'impressum', 'imprint' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$imprint = (string) get_permalink( $page );
				break;
			}
		}
	}
	$privacy = '' !== (string) $s['privacy_url'] ? (string) $s['privacy_url'] : (string) get_privacy_policy_url();
	$out     = array();
	if ( '' !== $imprint ) {
		$out[ (string) $t['imprint'] ] = $imprint;
	}
	if ( '' !== $privacy ) {
		$out[ (string) $t['privacy'] ] = $privacy;
	}
	return $out;
}

/**
 * The pages of a short domain: its home page (what the service is, in a few words) and the
 * error page for short links that do not lead anywhere. Never returns.
 *
 * @param int  $status 200 for the home page; 404, 410 or 503 for the error page.
 * @param bool $known  The short link exists but does not lead anywhere right now.
 */
function render_short_home( $status, $known = false ) {
	// Visitors read the page in the language their browser asks for; else in the site's language.
	$all  = short_page_texts();
	$site = strtolower( substr( (string) get_locale(), 0, 2 ) );
	$site = isset( $all[ $site ] ) ? $site : 'en';
	$lang = pick_language( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '', array_keys( $all ), $site );
	$t    = array_merge( $all['en'], $all[ $lang ] );
	$status = (int) $status;
	$host   = preg_replace( '#^https?://|/$#', '', home_url( '/' ) );
	$icon   = get_site_icon_url( 192 );
	$tag    = trim( (string) get_bloginfo( 'description' ) );
	// A link that exists but answers "not found" (not started, target unreachable) is not a typing mistake.
	$error  = 200 === $status ? null : short_error_text( $known && 404 === $status ? 503 : $status, $t );
	$path   = trim( request_path(), '/' );
	/**
	 * The texts of the home page of a short domain.
	 *
	 * @param array  $text lead, about (line on the error page), targets (three labels), facts (three short lines).
	 * @param string $host The short domain.
	 * @param string $lang Language of the visitor, two letters.
	 */
	$text = (array) apply_filters(
		'clean_short_links_short_home',
		array(
			// The site's tagline is the lead – in the site's own language only.
			'lead'    => '' !== $tag && $lang === $site ? $tag : $t['lead'],
			'about'   => sprintf( $t['about'], $host ),
			'targets' => array( $t['page'], $t['file'], $t['card'] ),
			'facts'   => array( $t['fact_use'], $t['fact_privacy'], $t['fact_private'] ),
		),
		$host,
		$lang
	);
	$legal = short_legal_links( $t );
	// Small line drawings: page, file, card – poster with a code, shield, lock.
	$svg   = function ( $d ) {
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	};
	$pics  = array(
		'page'   => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 13h7M7 16h4"/>',
		'file'   => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M10 13h5M10 17h5"/>',
		'card'   => '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18M15.5 15h2"/>',
		'qr'     => '<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.5"/><rect x="14" y="3.5" width="6.5" height="6.5" rx="1.5"/><rect x="3.5" y="14" width="6.5" height="6.5" rx="1.5"/><path d="M14 14h2.5v2.5H14zM18.5 18.5h2v2M14 20h2M20 14v2"/>',
		'shield' => '<path d="M12 3 4.5 6v6c0 4.4 3.2 7.9 7.5 9 4.3-1.1 7.5-4.6 7.5-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'lock'   => '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
	);
	$targets = array_slice( array_values( (array) ( $text['targets'] ?? array() ) ), 0, 3 );
	$facts   = array_slice( array_values( (array) ( $text['facts'] ?? array() ) ), 0, 3 );
	status_header( $status );
	nocache_headers();
	if ( 503 === $status ) {
		header( 'Retry-After: 3600' );
	}
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	header( 'Content-Language: ' . $lang );
	header( 'Vary: Accept-Language' );
	header( 'X-Robots-Tag: ' . ( $error ? 'noindex' : 'all' ) );
	?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $error ? $error['title'] . ' – ' . $host : $host ); ?></title>
	<?php if ( $error ) : ?>
<meta name="robots" content="noindex">
	<?php else : ?>
<meta name="description" content="<?php echo esc_attr( $text['lead'] ); ?>">
	<?php endif; ?>
	<?php if ( $icon ) : ?>
<link rel="icon" href="<?php echo esc_url( $icon ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $icon ); ?>">
	<?php endif; ?>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font:17px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#182949;background:#f5f7fb;display:flex;align-items:center;justify-content:center;padding:40px 20px}
main{width:100%;max-width:520px}
.icon{width:72px;height:72px;border-radius:18px;box-shadow:0 10px 26px rgba(24,41,73,.18)}
h1{font-size:44px;line-height:1.05;margin:22px 0 10px;letter-spacing:-.02em}h1 span{color:#ef7d1b}
.lead{font-size:20px;margin:0 0 22px}p{margin:0 0 14px}.muted{color:#55617a;font-size:15px}
.flow{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 26px;padding:16px;border-radius:18px;background:#fff;border:1px solid #dbe1ec}
.pill{padding:9px 14px;border-radius:999px;background:#182949;color:#fff;font:600 15px/1.2 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap}.pill b{color:#ef7d1b}
.arrow{flex:0 0 auto;color:#ef7d1b;display:flex}
.to{display:flex;gap:8px;flex-wrap:wrap}.to span{display:flex;align-items:center;gap:6px;padding:7px 11px 7px 9px;border-radius:12px;background:#f5f7fb;font-size:14px;font-weight:600}.to svg{color:#ef7d1b}
.facts{list-style:none;margin:0;padding:0;display:grid;gap:10px}.facts li{display:flex;align-items:center;gap:12px;font-size:15px}.facts svg{flex:0 0 auto;color:#55617a}
.brand{display:flex;align-items:center;gap:12px;font-weight:700;font-size:20px;letter-spacing:-.01em;color:inherit;text-decoration:none}.brand span{color:#ef7d1b}.brand .icon{width:40px;height:40px;border-radius:10px;box-shadow:none}
.err h1{font-size:34px;margin-top:34px}
.addr{display:flex;align-items:center;gap:10px;margin:22px 0;padding:14px 16px;border-radius:14px;background:#fff;border:1px solid #dbe1ec;font:15px/1.3 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}
.addr s{text-decoration-color:#ef7d1b;text-decoration-thickness:2px}
.dot{flex:0 0 auto;width:10px;height:10px;border-radius:50%;background:#ef7d1b}.later .dot{background:#e0a100}.gone .dot{background:#8a94a8}
.btn{display:inline-block;margin-top:8px;padding:12px 18px;border-radius:12px;background:#182949;color:#fff;text-decoration:none;font-weight:600}.btn:hover,.btn:focus-visible{background:#24406f}
.review{margin:26px 0 -12px;font-size:13px;color:#55617a}.review a{color:inherit}
footer{margin-top:30px;padding-top:18px;border-top:1px solid #dbe1ec;font-size:15px;display:flex;flex-wrap:wrap;gap:6px 18px}footer a{color:#182949;font-weight:600}footer a.legal{font-weight:400}
@media (prefers-color-scheme:dark){.review{color:#a9b5ca}}
@media (prefers-color-scheme:dark){.flow{background:#182949;border-color:#2a3a59}.pill{background:#101c33}.to span{background:#101c33}.facts svg{color:#a9b5ca}}
@media (prefers-color-scheme:dark){body{background:#101c33;color:#eef2f8}.muted{color:#a9b5ca}.addr{background:#182949;border-color:#2a3a59}.btn{background:#ef7d1b;color:#101c33}.btn:hover,.btn:focus-visible{background:#ff9a3d}footer{border-color:#2a3a59}footer a{color:#fff}}
</style>
</head>
<body>
	<?php $dotted = wp_kses( preg_replace( '/\./', '<span>.</span>', esc_html( $host ), 1 ), array( 'span' => array() ) ); ?>
	<?php if ( $error ) : ?>
<main class="err <?php echo esc_attr( $error['kind'] ); ?>">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php if ( $icon ) : ?>
		<img class="icon" src="<?php echo esc_url( $icon ); ?>" width="40" height="40" alt="">
		<?php endif; ?>
		<b><?php echo $dotted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></b>
	</a>
	<h1><?php echo esc_html( $error['title'] ); ?></h1>
	<p><?php echo esc_html( $error['text'] ); ?></p>
		<?php if ( '' !== $path ) : ?>
	<p class="addr"><i class="dot" aria-hidden="true"></i><?php echo esc_html( $host . '/' ); ?><?php echo 'later' === $error['kind'] ? esc_html( $path ) : '<s>' . esc_html( $path ) . '</s>'; ?></p>
		<?php endif; ?>
	<p class="muted"><?php echo esc_html( $text['about'] ); ?></p>
	<?php else : ?>
<main>
		<?php if ( $icon ) : ?>
	<img class="icon" src="<?php echo esc_url( $icon ); ?>" width="72" height="72" alt="">
		<?php endif; ?>
	<h1><?php echo $dotted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></h1>
	<p class="lead"><?php echo esc_html( $text['lead'] ); ?></p>
	<div class="flow">
		<span class="pill"><?php echo esc_html( $host ); ?><b>/</b>name</span>
		<span class="arrow"><?php echo $svg( '<path d="M4 12h15M13 6l6 6-6 6"/>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
		<span class="to">
		<?php foreach ( array( 'page', 'file', 'card' ) as $i => $pic ) : ?>
			<?php if ( isset( $targets[ $i ] ) ) : ?>
			<span><?php echo $svg( $pics[ $pic ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( $targets[ $i ] ); ?></span>
			<?php endif; ?>
		<?php endforeach; ?>
		</span>
	</div>
	<ul class="facts">
		<?php foreach ( array( 'qr', 'shield', 'lock' ) as $i => $pic ) : ?>
			<?php if ( isset( $facts[ $i ] ) ) : ?>
		<li><?php echo $svg( $pics[ $pic ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( $facts[ $i ] ); ?></li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<?php if ( '' !== (string) ( $all[ $lang ]['review'] ?? '' ) ) : ?>
		<?php $contact = (string) reset( $legal ); ?>
	<p class="review">
		<?php if ( '' !== $contact ) : ?>
		<a href="<?php echo esc_url( $contact ); ?>"><?php echo esc_html( $all[ $lang ]['review'] ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $all[ $lang ]['review'] ); ?>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<?php if ( $legal ) : ?>
	<footer>
		<?php foreach ( $legal as $label => $url ) : ?>
		<a class="legal" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</footer>
	<?php endif; ?>
</main>
</body>
</html>
	<?php
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
			handle_found( $found, $req[0], $req[1], true );
			return;
		}
		handle_route( current_host(), $req[1], $req[0], true );
	},
	1
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
	if ( ! $route || ! site_serves( $route['blog_id'] ) ) {
		return false;
	}
	$request_prefix = prefixes()[0]; // The host answers with the prefixes of the site it belongs to.
	switch_to_blog( $route['blog_id'] );
	$link  = link_by_id( $route['link_id'] );
	$found = $link && ( SOURCE_ROOT !== $source || ! empty( $link['root'] ) );
	if ( $found ) {
		handle_found( array( $link, $route['kind'] ), $source, $slug, $fast, $request_prefix ); // Returns only for a dead link.
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
		$result = handle_found( $found, $req[0], $req[1] );
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
		if ( 'retired' === $kind ) {
			nextcloud_gone_redirect( current_host(), $slug ); // Returns only when it was no Nextcloud link.
		}
		return dead_end( 410, $link, $source, $fast );
	}
	if ( 'qr' === $kind ) {
		$source = SOURCE_QR;
	}
	if ( isset( $_GET['nfc'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marks a visit from an NFC tag.
		$source = SOURCE_NFC;
	}
	if ( isset( $_GET['w'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only marks a visit from a wallet card.
		$source = SOURCE_WALLET;
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
	// With a click limit the use is claimed before the redirect, in one database step – two
	// visitors at the same moment can never both get the last use.
	if ( $used && ! empty( $link['max_clicks'] ) && visit_data( $source, $channel ) ) {
		if ( ! claim_click( $link['id'], (int) $link['max_clicks'] ) ) {
			return dead_end( 410, $link, $source, $fast );
		}
		$GLOBALS['clean_short_links_claimed'] = (int) $link['id'];
	}
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
	$url = final_url( $result['url'], $link, $source, $channel );
	// "Wallet card first on Apple devices": the card page with a Continue link instead of the redirect.
	maybe_wallet_first( $link, (string) $kind, (string) $slug, (int) $source, $channel, $result, $url, $used );
	go_to( $url, $link, $source, $used, $channel, 'default' === $result['state'] && permanent_allowed( $link ) ? 301 : 302, true );
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
	$query = forwarded_query( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameters are only passed on to the target.
	if ( ! empty( site_settings()['pass_query'] ) && $query ) {
		$url = pass_query( $url, $query );
	}
	/**
	 * Filters the address a short link leads to right now.
	 *
	 * @param string     $url    Target URL.
	 * @param array|null $link   The link, null for an unknown short address.
	 * @param int        $source 0 link, 1 QR code, 2 short form on the main address, 3 link-in-bio page, 4 NFC tag, 5 wallet card, 6 add-to-wallet address.
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
 * @param bool       $flush   Hand the answer over first (false: the answer is still to be sent).
 */
function finish_and_count( $count, $link, $source, $channel, $flush = true ) {
	if ( ! $count || ! $link ) {
		return;
	}
	$visit = visit_data( $source, $channel );
	if ( ! $visit ) {
		return;
	}
	if ( $flush && function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	} elseif ( $flush && function_exists( 'litespeed_finish_request' ) ) {
		litespeed_finish_request();
	}
	record_click( $link['id'], $visit['source'], $visit['device'], $visit['ref'] );
	if ( ( $GLOBALS['clean_short_links_claimed'] ?? 0 ) !== (int) $link['id'] ) {
		add_click( $link['id'] );
	}
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
