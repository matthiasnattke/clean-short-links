<?php
/**
 * Social media: link previews, share links and the link-in-bio page.
 *
 * Posts and pages bring their own information – title, excerpt and featured image are
 * read live, never copied. Only addresses outside the site can get their own preview text.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Is the visitor a link-preview robot of a social network or messenger?
 *
 * @param string $ua User agent.
 * @return bool
 */
function is_preview_bot( $ua ) {
	return 1 === preg_match( '/facebookexternalhit|facebot|meta-externalagent|twitterbot|linkedinbot|whatsapp|telegrambot|slackbot|discordbot|pinterest|skypeuripreview|mastodon|bluesky|redditbot|embedly|vkshare|xing-contenttabreceiver|iframely|signal/i', (string) $ua );
}

/**
 * What a preview shows for a target – read from the content itself where possible.
 *
 * @param array  $link   Link.
 * @param string $target Target the link leads to right now.
 * @param int    $depth  Recursion guard for short links leading to short links.
 * @return array{title:string,description:string,image:string,url:string,source:string}|null
 */
function preview_data( array $link, $target, $depth = 0 ) {
	if ( 'home' === $target ) {
		$icon = get_site_icon_url( 512 );
		return array(
			'title'       => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'image'       => $icon ? $icon : '',
			'url'         => home_url( '/' ),
			'source'      => 'site',
		);
	}
	$link_id = target_link_id( $target );
	if ( $link_id ) {
		$other = $depth < 3 ? link_by_id( $link_id ) : null;
		return $other ? preview_data( $other, $other['target'], $depth + 1 ) : null;
	}
	$post_id = target_post_id( $target );
	if ( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$image = get_the_post_thumbnail_url( $post, 'large' );
		$icon  = get_site_icon_url( 512 );
		// Without an excerpt: the first words of the content – block and tag borders become spaces.
		$plain = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', strip_shortcodes( $post->post_content ) );
		$plain = wp_strip_all_tags( preg_replace( '/<[^>]+>/', ' ', $plain ) );
		$text  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( preg_replace( '/\s+/', ' ', $plain ), 30, '…' );
		return array(
			'title'       => get_the_title( $post ),
			'description' => html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ),
			'image'       => $image ? $image : ( $icon ? $icon : '' ),
			'url'         => (string) get_permalink( $post ),
			'source'      => 'post',
		);
	}
	// An address outside the site: only what was entered for this link.
	$s = $link['meta']['social'] ?? array();
	if ( '' === ( $s['title'] ?? '' ) && '' === ( $s['description'] ?? '' ) && empty( $s['image'] ) ) {
		return null;
	}
	$img = ! empty( $s['image'] ) ? wp_get_attachment_image_url( (int) $s['image'], 'large' ) : '';
	return array(
		'title'       => '' !== $s['title'] ? $s['title'] : ( '' !== $link['title'] ? $link['title'] : get_bloginfo( 'name' ) ),
		'description' => $s['description'],
		'image'       => $img ? $img : '',
		'url'         => $target,
		'source'      => 'link',
	);
}

/**
 * Answers a preview robot with a small page that carries the preview and sends real
 * browsers on right away. Never returns.
 *
 * @param array  $data Preview.
 * @param string $url  Where the link leads.
 */
function render_preview( array $data, $url ) {
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	header( 'Content-Type: text/html; charset=utf-8' );
	$title = $data['title'];
	?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?></title>
<meta name="robots" content="noindex">
<meta http-equiv="refresh" content="0;url=<?php echo esc_url( $url ); ?>">
<link rel="canonical" href="<?php echo esc_url( $data['url'] ); ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
<meta property="og:title" content="<?php echo esc_attr( $title ); ?>">
<meta property="og:url" content="<?php echo esc_url( $data['url'] ); ?>">
	<?php if ( '' !== $data['description'] ) : ?>
<meta property="og:description" content="<?php echo esc_attr( $data['description'] ); ?>">
<meta name="description" content="<?php echo esc_attr( $data['description'] ); ?>">
	<?php endif; ?>
	<?php if ( '' !== $data['image'] ) : ?>
<meta property="og:image" content="<?php echo esc_url( $data['image'] ); ?>">
<meta name="twitter:image" content="<?php echo esc_url( $data['image'] ); ?>">
	<?php endif; ?>
<meta name="twitter:card" content="<?php echo '' !== $data['image'] ? 'summary_large_image' : 'summary'; ?>">
<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>">
</head>
<body><p><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a></p></body>
</html>
	<?php
	exit;
}

/**
 * Share links for the edit screen – plain links to the networks' own share pages, no
 * scripts or tracking.
 *
 * @param string $url  Short link.
 * @param string $text Text to share along.
 * @return array<string,string> Name => share URL.
 */
function share_links( $url, $text ) {
	$u = rawurlencode( $url );
	$t = rawurlencode( $text );
	return array(
		'WhatsApp' => 'https://wa.me/?text=' . rawurlencode( trim( $text . ' ' . $url ) ),
		'Telegram' => 'https://t.me/share/url?url=' . $u . '&text=' . $t,
		'Facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u,
		'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
		'X'        => 'https://x.com/intent/post?url=' . $u . '&text=' . $t,
		'Bluesky'  => 'https://bsky.app/intent/compose?text=' . rawurlencode( trim( $text . ' ' . $url ) ),
		/* translators: E-mail share option */
		__( 'E-mail', 'clean-short-links' ) => 'mailto:?subject=' . $t . '&body=' . rawurlencode( trim( $text . "\n" . $url ) ),
	);
}

/**
 * The label of a link on the link-in-bio page: its title, otherwise the title of the post
 * it leads to.
 *
 * @param array $link Link.
 * @return string
 */
function bio_label( array $link ) {
	if ( '' !== $link['title'] ) {
		return $link['title'];
	}
	$data = preview_data( $link, $link['target'] );
	return $data ? $data['title'] : target_label( $link['target'] );
}

/**
 * The link-in-bio page: site icon, name and tagline from the site settings, then one
 * button per chosen short link. Never returns.
 */
function render_bio() {
	$links = array_filter( bio_links(), fn( $l ) => 'active' === link_state( $l, time(), site_settings()['gone_days'] ) );
	$icon  = get_site_icon_url( 192 );
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	$name = get_bloginfo( 'name' );
	$name = '' !== trim( $name ) ? $name : preg_replace( '#^https?://|/$#', '', home_url( '/' ) );
	$desc = get_bloginfo( 'description' );
	?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $name ); ?></title>
<meta property="og:title" content="<?php echo esc_attr( $name ); ?>">
	<?php if ( '' !== $desc ) : ?>
<meta name="description" content="<?php echo esc_attr( $desc ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $desc ); ?>">
	<?php endif; ?>
	<?php if ( $icon ) : ?>
<link rel="icon" href="<?php echo esc_url( $icon ); ?>">
<meta property="og:image" content="<?php echo esc_url( $icon ); ?>">
	<?php endif; ?>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#0e2440;background:linear-gradient(160deg,#e7f2ff 0%,#f6f9fc 55%,#fff7f0 100%);display:flex;justify-content:center;padding:48px 18px}
main{width:100%;max-width:460px;text-align:center}
.icon{width:96px;height:96px;border-radius:26px;box-shadow:0 12px 30px rgba(14,36,64,.18);transform:rotate(-4deg)}
h1{font-size:26px;margin:18px 0 6px;letter-spacing:-.01em}.desc{margin:0 0 28px;color:#50575e}
ul{list-style:none;margin:0;padding:0;display:grid;gap:12px}
a.btn{display:block;padding:15px 20px;border-radius:16px;background:#fff;color:#0e2440;text-decoration:none;font-weight:600;border:1.5px solid #d6e6f7;box-shadow:0 2px 6px rgba(14,36,64,.06);transition:transform .15s,box-shadow .15s,border-color .15s}
a.btn:hover,a.btn:focus{transform:translateY(-2px);border-color:#e2611b;box-shadow:0 10px 24px rgba(14,36,64,.12);outline:none}
footer{margin-top:36px;font-size:13px;color:#646970}footer a{color:inherit}
@media (prefers-color-scheme:dark){body{background:#0e2440;color:#eef4fb}.desc,footer{color:#b8c7d9}a.btn{background:#16325a;color:#fff;border-color:#24477a}}
@media (prefers-reduced-motion:reduce){a.btn{transition:none}}
</style>
</head>
<body>
<main>
	<?php if ( $icon ) : ?>
	<img class="icon" src="<?php echo esc_url( $icon ); ?>" width="96" height="96" alt="">
	<?php endif; ?>
	<h1><?php echo esc_html( $name ); ?></h1>
	<?php if ( '' !== $desc ) : ?>
	<p class="desc"><?php echo esc_html( $desc ); ?></p>
	<?php endif; ?>
	<ul>
	<?php foreach ( $links as $l ) : ?>
		<li><a class="btn" href="<?php echo esc_url( add_query_arg( 'cslsrc', 'bio', short_urls( $l )['link'] ) ); ?>"><?php echo esc_html( bio_label( $l ) ); ?></a></li>
	<?php endforeach; ?>
	</ul>
	<footer><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( preg_replace( '#^https?://|/$#', '', home_url( '/' ) ) ); ?></a></footer>
</main>
</body>
</html>
	<?php
	exit;
}
