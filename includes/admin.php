<?php
/**
 * Admin screens of a site: list, edit screen with QR designer and statistics, settings.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		$cap = capability();
		add_menu_page( __( 'Short links', 'clean-short-links' ), __( 'Short links', 'clean-short-links' ), $cap, 'clean-short-links', __NAMESPACE__ . '\\page_list', 'dashicons-admin-links', 26 );
		add_submenu_page( 'clean-short-links', __( 'All short links', 'clean-short-links' ), __( 'All short links', 'clean-short-links' ), $cap, 'clean-short-links', __NAMESPACE__ . '\\page_list' );
		add_submenu_page( 'clean-short-links', __( 'Add short link', 'clean-short-links' ), __( 'Add new', 'clean-short-links' ), $cap, 'clean-short-links-edit', __NAMESPACE__ . '\\page_edit' );
		add_submenu_page( 'clean-short-links', __( 'Short link settings', 'clean-short-links' ), __( 'Settings', 'clean-short-links' ), 'manage_options', 'clean-short-links-settings', __NAMESPACE__ . '\\page_settings' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( false === strpos( (string) $hook, 'clean-short-links' ) ) {
			return;
		}
		$url = plugins_url( 'assets/', CLEAN_SHORT_LINKS_FILE );
		wp_enqueue_style( 'clean-short-links-admin', $url . 'admin.css', array(), asset_ver( 'admin.css' ) );
		wp_enqueue_script( 'clean-short-links-admin', $url . 'admin.js', array( 'wp-api-fetch' ), asset_ver( 'admin.js' ), true );
		wp_localize_script( 'clean-short-links-admin', 'CleanShortLinksAdmin', admin_js_data() );
		if ( false !== strpos( (string) $hook, 'clean-short-links-edit' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'clean-short-links-qr', $url . 'qr.js', array(), asset_ver( 'qr.js' ), true );
			wp_enqueue_script( 'clean-short-links-designer', $url . 'qr-designer.js', array( 'clean-short-links-qr', 'wp-api-fetch' ), asset_ver( 'qr-designer.js' ), true );
		}
	}
);

/*
 * The browser tab says "Edit short link" when an existing link is open.
 */
add_filter(
	'admin_title',
	function ( $title ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen is shown.
		if ( isset( $_GET['page'], $_GET['id'] ) && 'clean-short-links-edit' === $_GET['page'] && absint( $_GET['id'] ) ) {
			return str_replace( __( 'Add short link', 'clean-short-links' ), __( 'Edit short link', 'clean-short-links' ), $title );
		}
		return $title;
	}
);

/**
 * Texts and settings for admin.js and qr-designer.js.
 *
 * @return array
 */
function admin_js_data() {
	list( $p, $q ) = prefixes();
	return array(
		'home'     => home_url( '/' ),
		'prefix'   => $p,
		'qrPrefix' => $q,
		'root'     => root_allowed(),
		'i18n'     => array(
			'copied'      => __( 'Copied', 'clean-short-links' ),
			'checking'    => __( 'Checking …', 'clean-short-links' ),
			'free'        => __( 'Available.', 'clean-short-links' ),
			'reserved'    => __( 'Available with prefix – as a reserved word it cannot be used as a short form.', 'clean-short-links' ),
			/* translators: %s: title of the content at that address */
			'collision'   => __( 'The short form is taken by %s – only the link with prefix works.', 'clean-short-links' ),
			'searching'   => __( 'Searching …', 'clean-short-links' ),
			'noResults'   => __( 'Nothing found. You can also paste a URL.', 'clean-short-links' ),
			'urlTarget'   => __( 'URL', 'clean-short-links' ),
			'confirmDel'  => __( 'Delete this short link? Its short addresses stay blocked so they are never handed out again.', 'clean-short-links' ),
			'contrast'    => __( 'Low contrast – scanners may fail. Use a dark colour on a light background.', 'clean-short-links' ),
			'inverted'    => __( 'Light codes on a dark background are not read by every scanner.', 'clean-short-links' ),
			'chooseLogo'  => __( 'Choose logo or symbol', 'clean-short-links' ),
			'useLogo'     => __( 'Use', 'clean-short-links' ),
			'saved'       => __( 'Saved to the media library.', 'clean-short-links' ),
			'saveFailed'  => __( 'Could not save to the media library.', 'clean-short-links' ),
			/* translators: 1: QR version, 2: error correction level, 3: number of modules per side */
			'qrInfo'      => __( 'Version %1$s · error correction %2$s · %3$s × %3$s modules', 'clean-short-links' ),
			'compact'     => __( 'compact mode (capitals)', 'clean-short-links' ),
			'tooLong'     => __( 'The address is too long for a QR code.', 'clean-short-links' ),
			'removeRule'  => __( 'Remove this rule?', 'clean-short-links' ),
			'shortLink'   => __( 'Short link', 'clean-short-links' ),
			'homePage'    => __( 'Home page', 'clean-short-links' ),
		),
	);
}

/**
 * The URL of an admin screen of this plugin.
 *
 * @param string $page Page slug suffix ('' for the list).
 * @param array  $args Query arguments.
 * @return string
 */
function admin_page_url( $page = '', array $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=clean-short-links' . ( '' !== $page ? '-' . $page : '' ) ) );
}

/**
 * Human-readable state.
 *
 * @param string $state State.
 * @return string
 */
function state_label( $state ) {
	$labels = array(
		'active'  => __( 'Active', 'clean-short-links' ),
		'waiting' => __( 'Starts later', 'clean-short-links' ),
		'expired' => __( 'Expired', 'clean-short-links' ),
		'paused'    => __( 'Paused', 'clean-short-links' ),
		'exhausted' => __( 'Limit reached', 'clean-short-links' ),
	);
	return $labels[ $state ] ?? $state;
}

/**
 * A short URL with a copy button.
 *
 * @param string $url   URL.
 * @param string $label Optional small label in front.
 */
function copy_field( $url, $label = '' ) {
	if ( '' === $url ) {
		return;
	}
	echo '<span class="csl-copy">';
	if ( '' !== $label ) {
		echo '<small>' . esc_html( $label ) . '</small>';
	}
	echo '<code>' . esc_html( preg_replace( '#^https?://#', '', $url ) ) . '</code>';
	echo '<button type="button" class="csl-copy-btn" data-copy="' . esc_attr( $url ) . '" title="' . esc_attr__( 'Copy', 'clean-short-links' ) . '">' . icon( 'copy' ) . '<span class="screen-reader-text">' . esc_html__( 'Copy', 'clean-short-links' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG from icon().
	echo '</span>';
}

/**
 * Notice after an action, read from the redirect URL.
 */
function action_notice() {
	$msg = isset( $_GET['csl-msg'] ) ? sanitize_key( wp_unslash( $_GET['csl-msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a message text.
	$err = isset( $_GET['csl-error'] ) ? sanitize_text_field( wp_unslash( $_GET['csl-error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a message.
	$messages = array(
		'saved'    => __( 'Short link saved.', 'clean-short-links' ),
		'deleted'  => __( 'Short link deleted.', 'clean-short-links' ),
		'settings' => __( 'Settings saved.', 'clean-short-links' ),
		'alias'    => __( 'Short address removed.', 'clean-short-links' ),
		'checked'  => __( 'Link check done.', 'clean-short-links' ),
	);
	if ( isset( $messages[ $msg ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $msg ] ) );
	}
	if ( 'invites' === $msg ) {
		$n = isset( $_GET['csl-n'] ) ? absint( $_GET['csl-n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a number.
		/* translators: %d: number of invitation links */
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d invitation link created.', '%d invitation links created.', $n, 'clean-short-links' ), $n ) ) );
	}
	if ( 'bulk' === $msg || 'import' === $msg ) {
		$n = isset( $_GET['csl-n'] ) ? absint( $_GET['csl-n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a number.
		/* translators: %d: number of short links */
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d short link created.', '%d short links created.', $n, 'clean-short-links' ), $n ) ) );
	}
	if ( '' !== $err ) {
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $err ) );
	}
}

/* ---------------------------------------------------------------------------
 * List
 * ------------------------------------------------------------------------ */

/**
 * The list of all short links.
 */
function page_list() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and paging only.
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$per    = 24;
	$list   = list_links(
		array(
			'search'   => $search,
			'state'    => $state,
			'paged'    => $paged,
			'per_page' => $per,
		)
	);
	$ids    = wp_list_pluck( $list['items'], 'id' );
	$inv    = invite_counts( $ids );
	$v30    = totals( $ids, 30 );
	$vall   = totals( $ids, 0 );
	$now    = time();
	$top    = top_links( 30, 5 );
	$states = array(
		''          => __( 'All', 'clean-short-links' ),
		'waiting'   => __( 'Starts later', 'clean-short-links' ),
		'expired'   => __( 'Expired', 'clean-short-links' ),
		'paused'    => __( 'Paused', 'clean-short-links' ),
		'exhausted' => __( 'Limit reached', 'clean-short-links' ),
		'scheduled' => __( 'With rules', 'clean-short-links' ),
		'broken'    => __( 'Problems', 'clean-short-links' ),
	);
	$counts = array();
	foreach ( array_keys( $states ) as $key ) {
		$counts[ $key ] = list_links(
			array(
				'state'    => $key,
				'per_page' => 1,
			)
		)['total'];
	}
	?>
	<div class="wrap csl-wrap">
		<h1 class="screen-reader-text"><?php esc_html_e( 'Short links', 'clean-short-links' ); ?></h1>
		<?php
		hero(
			__( 'Short links', 'clean-short-links' ),
			'',
			'',
			function () {
				?>
				<a class="button button-primary csl-btn" href="<?php echo esc_url( admin_page_url( 'edit' ) ); ?>"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'New short link', 'clean-short-links' ); ?></a>
				<a class="button csl-btn" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_check&id=0' ), 'clean_short_links_check_0' ) ); ?>"><?php the_icon( 'shield' ); ?><?php esc_html_e( 'Check links', 'clean-short-links' ); ?></a>
				<a class="button csl-btn" href="<?php echo esc_url( admin_page_url( 'settings' ) ); ?>"><?php the_icon( 'gear' ); ?><?php esc_html_e( 'Settings', 'clean-short-links' ); ?></a>
				<?php
			},
			function () use ( $counts ) {
				echo '<div class="csl-stats">';
				stat( $counts[''], __( 'Short links', 'clean-short-links' ) );
				stat( site_total( 30 ), __( 'Visits in 30 days', 'clean-short-links' ), 'is-blue' );
				stat( $counts['scheduled'], __( 'With rules', 'clean-short-links' ) );
				if ( $counts['broken'] ) {
					stat( $counts['broken'], __( 'Problems', 'clean-short-links' ), 'is-warn' );
				}
				echo '</div>';
			}
		);
		action_notice();
		?>

		<div class="csl-overview">
			<section class="csl-card csl-card-chart">
				<h2><?php the_icon( 'chart' ); ?><?php esc_html_e( 'Visits in the last 30 days', 'clean-short-links' ); ?></h2>
				<?php echo chart_svg( daily( 0, 30 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in chart_svg(). ?>
			</section>
			<section class="csl-card csl-card-top">
				<h2><?php the_icon( 'sparkle' ); ?><?php esc_html_e( 'Most visited', 'clean-short-links' ); ?></h2>
				<?php if ( ! $top ) : ?>
					<p class="csl-help"><?php esc_html_e( 'No visits yet.', 'clean-short-links' ); ?></p>
				<?php else : ?>
					<ol class="csl-toplist">
						<?php
						$max = max( $top );
						foreach ( $top as $id => $hits ) :
							$l = link_by_id( $id );
							if ( ! $l ) {
								continue;
							}
							?>
							<li>
								<a href="<?php echo esc_url( admin_page_url( 'edit', array( 'id' => $id ) ) ); ?>"><?php echo esc_html( '' !== $l['title'] ? $l['title'] : $l['slug'] ); ?></a>
								<span class="csl-num"><?php echo esc_html( number_format_i18n( $hits ) ); ?></span>
								<span class="csl-bar-bg"><span style="width:<?php echo esc_attr( (string) round( 100 * $hits / $max ) ); ?>%"></span></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</section>
		</div>

		<div class="csl-toolbar">
			<nav class="csl-segment" aria-label="<?php esc_attr_e( 'Filter', 'clean-short-links' ); ?>">
				<?php foreach ( $states as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_page_url( '', '' !== $key ? array( 'state' => $key ) : array() ) ); ?>" class="<?php echo $key === $state ? 'is-current' : ''; ?>" <?php echo $key === $state ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><em><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></em></a>
				<?php endforeach; ?>
			</nav>
			<form method="get" class="csl-search" role="search">
				<input type="hidden" name="page" value="clean-short-links">
				<?php if ( '' !== $state ) : ?>
					<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="csl-search"><?php esc_html_e( 'Search short links', 'clean-short-links' ); ?></label>
				<?php the_icon( 'search' ); ?>
				<input type="search" id="csl-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search short links', 'clean-short-links' ); ?>">
			</form>
		</div>

		<div class="csl-links">
			<?php if ( ! $list['items'] ) : ?>
				<div class="csl-empty">
					<?php the_icon( 'link' ); ?>
					<p><?php echo esc_html( '' !== $search || '' !== $state ? __( 'Nothing found.', 'clean-short-links' ) : __( 'No short links yet.', 'clean-short-links' ) ); ?></p>
					<a class="button button-primary csl-btn" href="<?php echo esc_url( admin_page_url( 'edit' ) ); ?>"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'New short link', 'clean-short-links' ); ?></a>
				</div>
			<?php endif; ?>
			<?php
			foreach ( $list['items'] as $l ) :
				$urls  = short_urls( $l );
				$st    = link_state( $l, $now );
				$edit  = admin_page_url( 'edit', array( 'id' => $l['id'] ) );
				$del   = wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_delete&id=' . $l['id'] ), 'clean_short_links_delete_' . $l['id'] );
				$label = '' !== $l['title'] ? $l['title'] : target_label( $l['target'] );
				?>
				<article class="csl-link csl-link-<?php echo esc_attr( $st ); ?>">
					<div class="csl-link-top">
						<span class="csl-badge csl-badge-<?php echo esc_attr( $st ); ?>"><span class="csl-dot" aria-hidden="true"></span><?php echo esc_html( state_label( $st ) ); ?></span>
						<?php if ( in_array( $l['check']['status'], array( 'broken', 'warning' ), true ) ) : ?>
							<span class="csl-badge csl-badge-<?php echo esc_attr( 'broken' === $l['check']['status'] ? 'paused' : 'waiting' ); ?>" title="<?php esc_attr_e( 'Link check', 'clean-short-links' ); ?>"><?php the_icon( 'shield' ); ?><?php echo esc_html( 'broken' === $l['check']['status'] ? __( 'Target unreachable', 'clean-short-links' ) : __( 'Check', 'clean-short-links' ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $inv[ $l['id'] ] ) ) : ?>
							<?php /* translators: %d: number of invitation links */ ?>
							<span class="csl-badge csl-badge-soft"><?php the_icon( 'tag' ); ?><?php echo esc_html( sprintf( _n( '%d invitation', '%d invitations', $inv[ $l['id'] ], 'clean-short-links' ), $inv[ $l['id'] ] ) ); ?></span>
						<?php endif; ?>
						<?php if ( $l['rules'] ) : ?>
							<span class="csl-badge csl-badge-soft"><?php the_icon( 'split' ); ?><?php /* translators: %d: number of rules */ echo esc_html( sprintf( _n( '%d rule', '%d rules', count( $l['rules'] ), 'clean-short-links' ), count( $l['rules'] ) ) ); ?></span>
						<?php endif; ?>
						<a class="csl-icon-link" href="<?php echo esc_url( $edit . '#csl-sec-qr' ); ?>" title="<?php esc_attr_e( 'QR code', 'clean-short-links' ); ?>"><?php the_icon( 'qr' ); ?><span class="screen-reader-text"><?php esc_html_e( 'QR code', 'clean-short-links' ); ?></span></a>
					</div>
					<h3 class="csl-link-name"><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a></h3>
					<p class="csl-link-target"><?php the_icon( 'target' ); ?><span><?php echo esc_html( target_label( $l['target'] ) ); ?></span></p>
					<div class="csl-link-urls">
						<?php copy_field( $urls['link'] ); ?>
						<?php copy_field( $urls['root'] ); ?>
					</div>
					<?php if ( $l['ends_at'] && 'expired' !== $st ) : ?>
						<p class="csl-link-when"><?php the_icon( 'clock' ); ?><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'until %s', 'clean-short-links' ), wp_date( get_option( 'date_format' ), $l['ends_at'] ) ) ); ?></p>
					<?php endif; ?>
					<div class="csl-link-foot">
						<span class="csl-link-visits" title="<?php esc_attr_e( 'Visits in 30 days / total', 'clean-short-links' ); ?>"><?php the_icon( 'chart' ); ?><strong><?php echo esc_html( number_format_i18n( $v30[ $l['id'] ] ?? 0 ) ); ?></strong> / <?php echo esc_html( number_format_i18n( $vall[ $l['id'] ] ?? 0 ) ); ?></span>
						<a class="button csl-btn" href="<?php echo esc_url( $edit ); ?>"><?php the_icon( 'pencil' ); ?><?php esc_html_e( 'Edit', 'clean-short-links' ); ?></a>
						<a class="csl-icon-link csl-delete" href="<?php echo esc_url( $del ); ?>" title="<?php esc_attr_e( 'Delete', 'clean-short-links' ); ?>"><?php the_icon( 'trash' ); ?><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'clean-short-links' ); ?></span></a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		$pages = (int) ceil( $list['total'] / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Edit screen
 * ------------------------------------------------------------------------ */

/**
 * A target picker: search for content or paste a URL.
 *
 * @param string $name   Field name.
 * @param string $target Current target.
 * @param string $id     HTML ID of the search field.
 * @param bool   $required Required field.
 */
function target_picker( $name, $target, $id, $required = false ) {
	$label = '' !== $target ? target_label( $target ) : '';
	$kind  = target_post_id( $target ) ? get_post_type( target_post_id( $target ) ) : ( '' !== $target ? 'url' : '' );
	$kind  = target_link_id( $target ) ? 'link' : ( 'home' === $target ? 'home' : $kind );
	$pto   = $kind && ! in_array( $kind, array( 'url', 'link', 'home' ), true ) ? get_post_type_object( $kind ) : null;
	$names = array(
		'url'  => __( 'URL', 'clean-short-links' ),
		'link' => __( 'Short link', 'clean-short-links' ),
		'home' => __( 'Home page', 'clean-short-links' ),
	);
	?>
	<div class="csl-picker">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $target ); ?>" class="csl-picker-value">
		<input type="search" id="<?php echo esc_attr( $id ); ?>" class="regular-text csl-picker-input" autocomplete="off" value="<?php echo esc_attr( preg_match( '/^https?:/', $target ) ? $target : $label ); ?>" placeholder="<?php esc_attr_e( 'Search posts and pages or paste a URL', 'clean-short-links' ); ?>"<?php echo $required ? ' required' : ''; ?>>
		<span class="csl-picker-kind"><?php echo esc_html( $pto ? $pto->labels->singular_name : ( $names[ $kind ] ?? '' ) ); ?></span>
		<ul class="csl-picker-results" hidden></ul>
	</div>
	<?php
}

/**
 * One rule row (also used as template with empty values).
 *
 * @param int|string $i    Index or placeholder.
 * @param array      $rule Rule.
 */
function rule_row( $i, array $rule ) {
	global $wp_locale;
	$n = 'rules[' . $i . ']';
	?>
	<fieldset class="csl-rule">
		<legend class="screen-reader-text"><?php esc_html_e( 'Rule', 'clean-short-links' ); ?></legend>
		<div class="csl-rule-head">
			<span class="csl-rule-no"></span>
			<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Name, e.g. “Festival weekend”', 'clean-short-links' ); ?>" class="csl-rule-label">
			<button type="button" class="csl-icon-link csl-rule-up" title="<?php esc_attr_e( 'Move up', 'clean-short-links' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Move up', 'clean-short-links' ); ?></span></button>
			<button type="button" class="csl-icon-link csl-rule-remove" title="<?php esc_attr_e( 'Remove rule', 'clean-short-links' ); ?>"><?php the_icon( 'trash' ); ?><span class="screen-reader-text"><?php esc_html_e( 'Remove rule', 'clean-short-links' ); ?></span></button>
		</div>
		<div class="csl-rule-grid">
			<label class="csl-field"><span><?php esc_html_e( 'From', 'clean-short-links' ); ?></span><input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[from]" value="<?php echo esc_attr( str_replace( ' ', 'T', $rule['from'] ?? '' ) ); ?>"></label>
			<label class="csl-field"><span><?php esc_html_e( 'Until', 'clean-short-links' ); ?></span><input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[to]" value="<?php echo esc_attr( str_replace( ' ', 'T', $rule['to'] ?? '' ) ); ?>"></label>
			<label class="csl-field"><span><?php esc_html_e( 'Daily from', 'clean-short-links' ); ?></span><input type="time" name="<?php echo esc_attr( $n ); ?>[time_from]" value="<?php echo esc_attr( $rule['time_from'] ?? '' ); ?>"></label>
			<label class="csl-field"><span><?php esc_html_e( 'Daily until', 'clean-short-links' ); ?></span><input type="time" name="<?php echo esc_attr( $n ); ?>[time_to]" value="<?php echo esc_attr( $rule['time_to'] ?? '' ); ?>"></label>
		</div>
		<div class="csl-chips" role="group" aria-label="<?php esc_attr_e( 'Weekdays', 'clean-short-links' ); ?>">
			<?php
			for ( $d = 1; $d <= 7; $d++ ) :
				$wd = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d % 7 ) ) : (string) $d;
				?>
				<label class="csl-chip"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[days][]" value="<?php echo esc_attr( (string) $d ); ?>" <?php checked( in_array( $d, array_map( 'intval', (array) ( $rule['days'] ?? array() ) ), true ) ); ?>><span><?php echo esc_html( $wd ); ?></span></label>
			<?php endfor; ?>
			<span class="csl-help"><?php esc_html_e( 'None ticked = every day', 'clean-short-links' ); ?></span>
		</div>
		<div class="csl-rule-target">
			<span class="csl-field-label"><?php esc_html_e( 'Leads to', 'clean-short-links' ); ?></span>
			<?php target_picker( $n . '[target]', $rule['target'] ?? '', 'csl-rule-target-' . $i ); ?>
		</div>
	</fieldset>
	<?php
}

/**
 * Radio buttons as a segmented control for the QR designer.
 *
 * @param string $key     Design key.
 * @param array  $options Value => label.
 */
function qr_segment( $key, array $options ) {
	echo '<span class="csl-segment csl-segment-sm">';
	foreach ( $options as $value => $label ) {
		printf(
			'<label><input type="radio" name="csl-qr-%1$s" data-qr="%1$s" value="%2$s"><span>%3$s</span></label>',
			esc_attr( $key ),
			esc_attr( $value ),
			esc_html( $label )
		);
	}
	echo '</span>';
}

/**
 * Result of the last link check, for the header of the edit screen.
 *
 * @param array $link Link.
 */
function check_line( array $link ) {
	$c     = $link['check'];
	$check = wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_check&id=' . $link['id'] ), 'clean_short_links_check_' . $link['id'] );
	$names = array(
		'ok'      => __( 'Link check: everything reachable', 'clean-short-links' ),
		'warning' => __( 'Link check: please have a look', 'clean-short-links' ),
		'broken'  => __( 'Link check: a target is not reachable', 'clean-short-links' ),
		''        => __( 'Not checked yet', 'clean-short-links' ),
	);
	echo '<div class="csl-check-line csl-check-' . esc_attr( '' !== $c['status'] ? $c['status'] : 'none' ) . '">';
	echo '<span class="csl-badge csl-badge-check">' . icon( 'shield' ) . esc_html( $names[ $c['status'] ] ?? $names[''] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG from icon().
	if ( $c['at'] ) {
		/* translators: %s: time span, e.g. "3 hours" */
		echo ' <small>' . esc_html( sprintf( __( '%s ago', 'clean-short-links' ), human_time_diff( $c['at'] ) ) ) . '</small>';
	}
	echo ' <a class="csl-link-ext" href="' . esc_url( $check ) . '">' . esc_html__( 'Check now', 'clean-short-links' ) . '</a>';
	$problems = array_filter( $c['items'], fn( $i ) => 'ok' !== ( $i['status'] ?? 'ok' ) );
	if ( $problems ) {
		echo '<ul class="csl-check-list">';
		foreach ( $problems as $item ) {
			$what = 'self' === $item['role'] ? __( 'Short link', 'clean-short-links' ) : ( 'main' === $item['role'] ? __( 'Target', 'clean-short-links' ) : ( 'fallback' === $item['role'] ? __( 'Fallback', 'clean-short-links' ) : $item['role'] ) );
			$why  = '' !== $item['message'] ? $item['message'] : ( $item['code'] ? 'HTTP ' . $item['code'] : '' );
			echo '<li><strong>' . esc_html( $what ) . ':</strong> ' . esc_html( 'self' === $item['role'] ? preg_replace( '#^https?://#', '', $item['target'] ) : target_label( $item['target'] ) ) . ' – ' . esc_html( $why ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

/**
 * The invitation links of a main link: generator and list.
 *
 * @param array $link Main link.
 */
function invites_card( array $link ) {
	$all    = invites( $link['id'] );
	$groups = array();
	foreach ( $all as $inv ) {
		$groups[ $inv['title'] ][] = $inv;
	}
	$now = time();
	card_open( 'csl-sec-invites', 'tag', __( 'Invitation links', 'clean-short-links' ), __( 'Personal links that lead where this short link leads – each with its own hard-to-guess address, an optional limit and expiry. Create them group by group, e.g. 10 links for the family with one visit each.', 'clean-short-links' ) );
	?>
		<div class="csl-invite-gen">
			<label class="csl-field"><span><?php esc_html_e( 'Group', 'clean-short-links' ); ?></span><input type="text" name="invite_group" placeholder="<?php esc_attr_e( 'e.g. Family', 'clean-short-links' ); ?>"></label>
			<label class="csl-field"><span><?php esc_html_e( 'How many', 'clean-short-links' ); ?></span><input type="number" name="invite_count" min="1" max="500" value="10" class="csl-num-input"></label>
			<label class="csl-field"><span><?php esc_html_e( 'Visits each', 'clean-short-links' ); ?></span><input type="number" name="invite_clicks" min="0" value="1" class="csl-num-input"></label>
			<label class="csl-field"><span><?php esc_html_e( 'Expires', 'clean-short-links' ); ?></span><input type="datetime-local" name="invite_ends"></label>
			<button type="submit" name="invite_generate" value="1" class="button csl-btn"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'Create invitation links', 'clean-short-links' ); ?></button>
		</div>
		<span class="csl-help"><?php esc_html_e( '0 visits = unlimited. Without an expiry the invitations end with this short link. Creating them also saves the short link.', 'clean-short-links' ); ?></span>
		<?php if ( $all ) : ?>
			<?php foreach ( $groups as $group => $list ) : ?>
				<?php
				$used = count( array_filter( $list, fn( $i ) => $i['clicks'] > 0 ) );
				$urls = array_map( fn( $i ) => short_urls( $i )['link'], $list );
				?>
				<div class="csl-invite-group">
					<div class="csl-invite-head">
						<h3><?php echo esc_html( $group ); ?></h3>
						<?php /* translators: 1: number used, 2: number of invitations */ ?>
						<span class="csl-badge csl-badge-soft"><?php echo esc_html( sprintf( __( '%1$d of %2$d used', 'clean-short-links' ), $used, count( $list ) ) ); ?></span>
						<button type="button" class="button csl-btn csl-copy-btn" data-copy="<?php echo esc_attr( implode( "\n", $urls ) ); ?>"><?php the_icon( 'copy' ); ?><?php esc_html_e( 'Copy all', 'clean-short-links' ); ?></button>
					</div>
					<table class="csl-invites">
						<tbody>
						<?php foreach ( $list as $inv ) : ?>
							<?php $ist = link_state( $inv, $now ); ?>
							<tr>
								<td><?php copy_field( short_urls( $inv )['link'] ); ?></td>
								<td class="csl-num"><?php echo esc_html( $inv['max_clicks'] ? $inv['clicks'] . ' / ' . $inv['max_clicks'] : (string) $inv['clicks'] ); ?></td>
								<td><span class="csl-badge csl-badge-<?php echo esc_attr( $ist ); ?>"><?php echo esc_html( state_label( $ist ) ); ?></span></td>
								<td><?php echo esc_html( $inv['ends_at'] ? wp_date( get_option( 'date_format' ), $inv['ends_at'] ) : '' ); ?></td>
								<td><a class="csl-icon-link csl-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_delete&id=' . $inv['id'] ), 'clean_short_links_delete_' . $inv['id'] ) ); ?>" title="<?php esc_attr_e( 'Delete', 'clean-short-links' ); ?>"><?php the_icon( 'trash' ); ?><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'clean-short-links' ); ?></span></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
			<p><a class="csl-link-ext" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_invites_csv&id=' . $link['id'] ), 'clean_short_links_invites_' . $link['id'] ) ); ?>"><?php the_icon( 'download' ); ?><?php esc_html_e( 'Export all invitations as CSV', 'clean-short-links' ); ?></a></p>
		<?php endif; ?>
	<?php
	card_close();
}

/**
 * Add or edit a short link.
 */
function page_edit() {
	$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects the link to edit.
	$link = $id ? link_by_id( $id ) : null;
	if ( $id && ! $link ) {
		echo '<div class="wrap csl-wrap"><h1>' . esc_html__( 'Short links', 'clean-short-links' ) . '</h1><p>' . esc_html__( 'This short link no longer exists.', 'clean-short-links' ) . '</p></div>';
		return;
	}
	if ( $link && $link['parent_id'] ) {
		echo '<div class="wrap csl-wrap"><h1>' . esc_html__( 'Invitation link', 'clean-short-links' ) . '</h1><p>' . esc_html__( 'Invitation links are managed with their main link.', 'clean-short-links' ) . ' <a href="' . esc_url( admin_page_url( 'edit', array( 'id' => $link['parent_id'] ) ) . '#csl-sec-invites' ) . '">' . esc_html__( 'Go to the main link', 'clean-short-links' ) . '</a></p></div>';
		return;
	}
	$for_post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Prefills the target.
	$s        = site_settings();
	$link     = $link ? $link : array(
		'id'         => 0,
		'title'      => $for_post ? get_the_title( $for_post ) : '',
		'target'     => $for_post ? 'post:' . $for_post : '',
		'slug'       => unique_random_slug(),
		'qr_slug'    => '',
		'kind'       => 'random',
		'root'       => 0,
		'start_mode' => '',
		'starts_at'  => null,
		'ends_at'    => null,
		'fallback'   => '',
		'rules'      => array(),
		'qr'         => array(),
		'status'     => 'active',
		'max_clicks' => 0,
		'clicks'     => 0,
		'parent_id'  => 0,
		'check'      => array(
			'status' => '',
			'items'  => array(),
			'at'     => 0,
		),
	);
	$urls          = $link['id'] ? short_urls( $link ) : null;
	list( $p, $q ) = prefixes();
	$home          = preg_replace( '#^https?://#', '', home_url( '/' ) );
	$st            = $link['id'] ? link_state( $link, time() ) : '';
	$now_result    = $link['id'] ? resolve( $link, new \DateTimeImmutable( 'now', wp_timezone() ), __NAMESPACE__ . '\\target_url', __NAMESPACE__ . '\\target_published' ) : null;
	$title         = $link['id'] ? ( '' !== $link['title'] ? $link['title'] : $link['slug'] ) : __( 'New short link', 'clean-short-links' );

	$sections = array(
		'csl-sec-target' => __( 'Target', 'clean-short-links' ),
		'csl-sec-slug'   => __( 'Short address', 'clean-short-links' ),
		'csl-sec-time'   => __( 'Time window', 'clean-short-links' ),
		'csl-sec-rules'  => __( 'Rules', 'clean-short-links' ),
	);
	if ( $link['id'] ) {
		$sections['csl-sec-invites'] = __( 'Invitations', 'clean-short-links' );
		$sections['csl-sec-qr']      = __( 'QR code', 'clean-short-links' );
		$sections['csl-sec-stats'] = __( 'Visits', 'clean-short-links' );
	}
	?>
	<div class="wrap csl-wrap">
		<h1 class="screen-reader-text"><?php echo esc_html( $link['id'] ? __( 'Edit short link', 'clean-short-links' ) : __( 'Add short link', 'clean-short-links' ) ); ?></h1>
		<?php
		hero(
			$title,
			$link['id'] ? state_label( $st ) : '',
			$st,
			function () use ( $urls ) {
				if ( $urls ) {
					?>
					<button type="button" class="button csl-btn csl-copy-btn" data-copy="<?php echo esc_attr( $urls['link'] ); ?>"><?php the_icon( 'copy' ); ?><?php esc_html_e( 'Copy short link', 'clean-short-links' ); ?></button>
					<a class="button csl-btn" href="<?php echo esc_url( $urls['link'] ); ?>" target="_blank" rel="noopener"><?php the_icon( 'external' ); ?><?php esc_html_e( 'Open', 'clean-short-links' ); ?></a>
					<?php
				}
				?>
				<a class="button csl-btn" href="<?php echo esc_url( admin_page_url( '' ) ); ?>"><?php esc_html_e( 'All short links', 'clean-short-links' ); ?></a>
				<?php
			},
			function () use ( $urls, $now_result, $link ) {
				if ( ! $urls ) {
					return;
				}
				echo '<div class="csl-hero-urls">';
				copy_field( $urls['link'] );
				copy_field( $urls['root'], __( 'Short form', 'clean-short-links' ) );
				echo '</div>';
				if ( $now_result && 302 === $now_result['status'] ) {
					echo '<p class="csl-now">' . icon( 'target' ) . esc_html__( 'Right now it leads to', 'clean-short-links' ) . ' <a href="' . esc_url( $now_result['url'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://#', '', $now_result['url'] ) ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG from icon().
					if ( 0 === strpos( $now_result['state'], 'rule-' ) ) {
						/* translators: %s: number of the rule */
						echo ' <span class="csl-badge csl-badge-soft">' . esc_html( sprintf( __( 'rule %s', 'clean-short-links' ), substr( $now_result['state'], 5 ) ) ) . '</span>';
					}
					echo '</p>';
				} elseif ( $now_result ) {
					/* translators: %d: HTTP status */
					echo '<p class="csl-now">' . icon( 'target' ) . esc_html( sprintf( __( 'Right now it answers with status %d.', 'clean-short-links' ), $now_result['status'] ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG from icon().
				}
				check_line( $link );
			}
		);
		action_notice();
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="csl-layout" id="csl-form">
			<?php wp_nonce_field( 'clean_short_links_save' ); ?>
			<?php // Pressing Enter in a field saves – it must not create invitations. ?>
			<button type="submit" class="csl-default-submit" tabindex="-1" aria-hidden="true"></button>
			<input type="hidden" name="action" value="clean_short_links_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $link['id'] ); ?>">
			<input type="hidden" name="qr_design" id="csl-qr-design" value="<?php echo esc_attr( wp_json_encode( $link['qr'] ? $link['qr'] : new \stdClass() ) ); ?>">

			<?php side_nav( $sections ); ?>

			<div class="csl-main">
				<?php card_open( 'csl-sec-target', 'target', __( 'Target', 'clean-short-links' ), __( 'Where should the short link lead?', 'clean-short-links' ) ); ?>
					<label class="csl-field" for="csl-target"><span><?php esc_html_e( 'Leads to', 'clean-short-links' ); ?></span></label>
					<?php target_picker( 'target', $link['target'], 'csl-target', true ); ?>
					<span class="csl-help"><?php esc_html_e( 'Posts and pages are followed by ID – the short link keeps working when their address changes.', 'clean-short-links' ); ?></span>
					<div class="csl-sub">
						<label class="csl-field" for="csl-title"><span><?php esc_html_e( 'Title', 'clean-short-links' ); ?></span></label>
						<input type="text" id="csl-title" name="title" class="csl-wide" value="<?php echo esc_attr( $link['title'] ); ?>" placeholder="<?php esc_attr_e( 'Only for you, e.g. “Poster 725 years”', 'clean-short-links' ); ?>">
					</div>
				<?php card_close(); ?>

				<?php card_open( 'csl-sec-slug', 'tag', __( 'Short address', 'clean-short-links' ), __( 'How should the short link read?', 'clean-short-links' ) ); ?>
					<div class="csl-tiles csl-tiles-4" role="radiogroup" aria-label="<?php esc_attr_e( 'Kind of short address', 'clean-short-links' ); ?>">
						<?php
						tile( 'kind', 'random', 'random' === $link['kind'], 'shuffle', __( 'Random', 'clean-short-links' ), __( 'Short and unique, e.g. k7mq4', 'clean-short-links' ) );
						tile( 'kind', 'memorable', 'memorable' === $link['kind'], 'sparkle', __( 'Memorable', 'clean-short-links' ), __( 'Two words, e.g. blue-couch', 'clean-short-links' ) );
						tile( 'kind', 'title', 'title' === $link['kind'], 'type', __( 'From the title', 'clean-short-links' ), __( 'The first words of the title', 'clean-short-links' ) );
						tile( 'kind', 'custom', 'custom' === $link['kind'], 'pencil', __( 'My own', 'clean-short-links' ), __( 'Type whatever you like', 'clean-short-links' ) );
						?>
					</div>
					<div class="csl-sub">
						<label for="csl-slug" class="csl-field"><span><?php esc_html_e( 'Short address', 'clean-short-links' ); ?></span></label>
						<div class="csl-slug-row">
							<span class="csl-slug-input"><span class="csl-slug-pre"><?php echo esc_html( $home . $p . '/' ); ?></span><input type="text" id="csl-slug" name="slug" value="<?php echo esc_attr( $link['slug'] ); ?>" data-original="<?php echo esc_attr( $link['slug'] ); ?>" data-id="<?php echo esc_attr( (string) $link['id'] ); ?>" maxlength="64" required autocomplete="off" spellcheck="false"></span>
							<button type="button" class="button csl-btn csl-reroll"><?php the_icon( 'shuffle' ); ?><?php esc_html_e( 'New suggestion', 'clean-short-links' ); ?></button>
						</div>
						<p class="csl-slug-msg" aria-live="polite"></p>
						<?php if ( $link['id'] ) : ?>
							<div class="csl-old-slug" hidden>
								<p><strong><?php /* translators: %s: old short address */ echo esc_html( sprintf( __( 'What happens to the old short address “%s”?', 'clean-short-links' ), $link['slug'] ) ); ?></strong></p>
								<label><input type="radio" name="old_slug" value="alias" checked> <?php esc_html_e( 'Keep it working as an additional address', 'clean-short-links' ); ?></label><br>
								<label><input type="radio" name="old_slug" value="retire"> <?php esc_html_e( 'Retire it – it then answers “gone” (410) and is never handed out again', 'clean-short-links' ); ?></label>
							</div>
						<?php endif; ?>
					</div>
					<?php if ( root_allowed() ) : ?>
						<div class="csl-sub">
							<?php
							/* translators: %s: example of the short form */
							toggle( 'root', ! empty( $link['root'] ) || ( ! $link['id'] && ! empty( $s['root_default'] ) && 'random' !== $link['kind'] ), sprintf( __( 'Also as short form %s', 'clean-short-links' ), $home . '…' ), __( 'Only works as long as the site has no content at that address – existing pages always win, the link with prefix keeps working.', 'clean-short-links' ), 'csl-root' );
							?>
							<p class="csl-root-msg" aria-live="polite"></p>
						</div>
					<?php endif; ?>
					<div class="csl-sub">
						<?php
						/* translators: %s: QR prefix */
						toggle( '', '' === $link['qr_slug'], sprintf( __( 'QR code uses the same short address (/%s/…)', 'clean-short-links' ), $q ), __( 'Switch off to give printed codes an address of their own.', 'clean-short-links' ), 'csl-qr-same' );
						?>
						<div class="csl-qr-slug-row" <?php echo '' === $link['qr_slug'] ? 'hidden' : ''; ?>>
							<span class="csl-slug-input"><span class="csl-slug-pre"><?php echo esc_html( $home . $q . '/' ); ?></span><input type="text" name="qr_slug" id="csl-qr-slug" value="<?php echo esc_attr( $link['qr_slug'] ); ?>" maxlength="64" autocomplete="off" spellcheck="false"></span>
						</div>
					</div>
					<?php
					$aliases = $link['id'] ? link_aliases( $link['id'] ) : array();
					if ( $aliases ) :
						?>
						<div class="csl-sub">
							<span class="csl-field-label"><?php esc_html_e( 'Earlier short addresses', 'clean-short-links' ); ?></span>
							<ul class="csl-aliases">
								<?php foreach ( $aliases as $a ) : ?>
									<li><code><?php echo esc_html( $a->slug ); ?></code> <span class="csl-badge <?php echo 'alias' === $a->kind ? '' : 'csl-badge-expired'; ?>"><?php echo esc_html( 'alias' === $a->kind ? __( 'still works', 'clean-short-links' ) : __( 'retired', 'clean-short-links' ) ); ?></span>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_alias&id=' . $link['id'] . '&slug=' . rawurlencode( $a->slug ) ), 'clean_short_links_alias_' . $link['id'] ) ); ?>"><?php esc_html_e( 'Release', 'clean-short-links' ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				<?php card_close(); ?>

				<?php card_open( 'csl-sec-time', 'clock', __( 'Time window', 'clean-short-links' ), __( 'When should the short link work?', 'clean-short-links' ) ); ?>
					<div class="csl-tiles csl-tiles-3" role="radiogroup" aria-label="<?php esc_attr_e( 'Start', 'clean-short-links' ); ?>">
						<?php
						tile( 'start_mode', '', '' === $link['start_mode'], 'bolt', __( 'Right away', 'clean-short-links' ), __( 'Works as soon as it is saved.', 'clean-short-links' ), 'csl-tile-now' );
						tile( 'start_mode', 'date', 'date' === $link['start_mode'], 'calendar', __( 'From a date', 'clean-short-links' ), __( 'Starts at a time you choose.', 'clean-short-links' ), 'csl-tile-date' );
						tile( 'start_mode', 'publish', 'publish' === $link['start_mode'], 'page', __( 'With publication', 'clean-short-links' ), __( 'Waits until the content is published.', 'clean-short-links' ), 'csl-publish-start' );
						?>
					</div>
					<div class="csl-sub csl-row">
						<label class="csl-field csl-when-start"><span><?php esc_html_e( 'Start', 'clean-short-links' ); ?></span><input type="datetime-local" name="starts_at" value="<?php echo esc_attr( timestamp_to_local( $link['starts_at'] ) ); ?>"></label>
						<label class="csl-field"><span><?php esc_html_e( 'Expires', 'clean-short-links' ); ?></span><input type="datetime-local" id="csl-ends" name="ends_at" value="<?php echo esc_attr( timestamp_to_local( $link['ends_at'] ) ); ?>">
							<span class="csl-help"><?php esc_html_e( 'Empty = never', 'clean-short-links' ); ?></span></label>
					</div>
					<div class="csl-sub">
						<label class="csl-field" for="csl-fallback"><span><?php esc_html_e( 'Before the start and after expiry lead to', 'clean-short-links' ); ?></span></label>
						<?php target_picker( 'fallback', $link['fallback'], 'csl-fallback' ); ?>
						<span class="csl-help"><?php /* translators: %s: time zone */ echo esc_html( sprintf( __( 'Empty = “not found” before the start and “gone” after expiry. Times in the site’s time zone (%s).', 'clean-short-links' ), wp_timezone_string() ) ); ?></span>
					</div>
					<div class="csl-sub">
						<label class="csl-field" for="csl-max"><span><?php esc_html_e( 'Maximum number of visits', 'clean-short-links' ); ?></span></label>
						<div class="csl-inline">
							<input type="number" id="csl-max" name="max_clicks" min="0" step="1" class="csl-num-input" value="<?php echo esc_attr( $link['max_clicks'] ? (string) $link['max_clicks'] : '' ); ?>" placeholder="∞">
							<?php if ( $link['id'] ) : ?>
								<span class="csl-badge <?php echo limit_reached( $link ) ? 'csl-badge-exhausted' : 'csl-badge-soft'; ?>"><?php /* translators: %s: number of visits so far */ echo esc_html( sprintf( __( '%s so far', 'clean-short-links' ), number_format_i18n( $link['clicks'] ) ) ); ?></span>
								<?php if ( $link['clicks'] ) : ?>
									<label class="csl-check"><input type="checkbox" name="reset_clicks" value="1"> <?php esc_html_e( 'Reset counter', 'clean-short-links' ); ?></label>
								<?php endif; ?>
							<?php endif; ?>
						</div>
						<span class="csl-help"><?php esc_html_e( 'Empty = no limit. Once reached, the link behaves as if it had expired. Bots, link previews and logged-in editors do not count.', 'clean-short-links' ); ?></span>
					</div>
				<?php card_close(); ?>

				<?php card_open( 'csl-sec-rules', 'split', __( 'Different targets by date and time', 'clean-short-links' ), __( 'The first rule that fits right now decides where the link leads. If none fits, the target above applies. Example: during the festival to the programme, in the evening to the stage plan.', 'clean-short-links' ) ); ?>
					<div class="csl-rules">
						<?php
						foreach ( $link['rules'] as $i => $rule ) {
							rule_row( $i, $rule );
						}
						?>
					</div>
					<template id="csl-rule-template"><?php rule_row( '__i__', array() ); ?></template>
					<button type="button" class="button csl-btn csl-rule-add"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'Add rule', 'clean-short-links' ); ?></button>
				<?php card_close(); ?>

				<?php
				if ( $link['id'] ) {
					invites_card( $link );
				}
				?>

				<?php if ( $urls ) : ?>
					<?php card_open( 'csl-sec-qr', 'qr', __( 'QR code', 'clean-short-links' ), __( 'The QR code contains the short link – time window and rules also apply to printed codes.', 'clean-short-links' ) ); ?>
						<div class="csl-qr-start">
							<?php copy_field( $urls['qr'] ); ?>
							<button type="button" class="button button-primary csl-btn csl-qr-open"><?php the_icon( 'qr' ); ?><?php esc_html_e( 'Create QR code', 'clean-short-links' ); ?></button>
						</div>
						<div class="csl-designer" hidden data-url="<?php echo esc_attr( $urls['qr'] ); ?>" data-upper="<?php echo esc_attr( $urls['qr_upper'] ); ?>" data-slug="<?php echo esc_attr( '' !== $link['qr_slug'] ? $link['qr_slug'] : $link['slug'] ); ?>" data-cta="<?php esc_attr_e( 'Scan me', 'clean-short-links' ); ?>">
							<div class="csl-qr-stage">
								<div class="csl-qr-preview"></div>
								<p class="csl-qr-info csl-help"></p>
								<p class="csl-qr-warn" aria-live="polite"></p>
							</div>
							<div class="csl-qr-controls">
								<div class="csl-qr-group">
									<span class="csl-field-label"><?php esc_html_e( 'Dots', 'clean-short-links' ); ?></span>
									<?php
									qr_segment(
										'style',
										array(
											'square'    => __( 'Squares', 'clean-short-links' ),
											'rounded'   => __( 'Rounded', 'clean-short-links' ),
											'dots'      => __( 'Dots', 'clean-short-links' ),
											'connected' => __( 'Connected', 'clean-short-links' ),
										)
									);
									?>
								</div>
								<div class="csl-qr-group">
									<span class="csl-field-label"><?php esc_html_e( 'Corners', 'clean-short-links' ); ?></span>
									<?php
									qr_segment(
										'eyes',
										array(
											'square'  => __( 'Square', 'clean-short-links' ),
											'rounded' => __( 'Rounded', 'clean-short-links' ),
											'circle'  => __( 'Round', 'clean-short-links' ),
										)
									);
									?>
								</div>
								<div class="csl-qr-group csl-qr-colors">
									<label><input type="color" data-qr="fg" value="#0e2440"> <?php esc_html_e( 'Colour', 'clean-short-links' ); ?></label>
									<label class="csl-qr-bg"><input type="color" data-qr="bg" value="#ffffff"> <?php esc_html_e( 'Background', 'clean-short-links' ); ?></label>
									<label class="csl-check"><input type="checkbox" data-qr="transparent"> <?php esc_html_e( 'Transparent', 'clean-short-links' ); ?></label>
								</div>
								<div class="csl-qr-group">
									<span class="csl-field-label"><?php esc_html_e( 'Call to action', 'clean-short-links' ); ?></span>
									<?php
									qr_segment(
										'cta',
										array(
											''      => __( 'None', 'clean-short-links' ),
											'pill'  => __( 'Pill below', 'clean-short-links' ),
											'frame' => __( 'Frame with pill', 'clean-short-links' ),
										)
									);
									?>
									<input type="text" data-qr="cta_text" class="csl-qr-cta-text" maxlength="24" placeholder="<?php esc_attr_e( 'Scan me', 'clean-short-links' ); ?>">
								</div>
								<div class="csl-qr-group csl-qr-logo">
									<span class="csl-field-label"><?php esc_html_e( 'Logo or symbol', 'clean-short-links' ); ?></span>
									<button type="button" class="button csl-btn csl-qr-logo-pick"><?php esc_html_e( 'From the media library …', 'clean-short-links' ); ?></button>
									<button type="button" class="button-link csl-qr-logo-remove" hidden><?php esc_html_e( 'Remove logo', 'clean-short-links' ); ?></button>
									<label class="csl-qr-logo-size" hidden><?php esc_html_e( 'Size', 'clean-short-links' ); ?> <input type="range" min="12" max="28" step="1" data-qr="logo_size" value="22"></label>
								</div>
								<div class="csl-qr-group">
									<label class="csl-check"><input type="checkbox" data-qr="upper" checked> <?php esc_html_e( 'Compact code (address in capitals)', 'clean-short-links' ); ?></label>
								</div>
							</div>
							<div class="csl-qr-actions">
								<button type="button" class="button button-primary csl-btn csl-qr-svg"><?php the_icon( 'download' ); ?>SVG</button>
								<span class="csl-qr-png-wrap">
									<button type="button" class="button csl-btn csl-qr-png"><?php the_icon( 'download' ); ?>PNG</button>
									<label class="screen-reader-text" for="csl-qr-px"><?php esc_html_e( 'PNG size', 'clean-short-links' ); ?></label>
									<select id="csl-qr-px"><option value="512">512 px</option><option value="1024" selected>1024 px</option><option value="2048">2048 px</option></select>
								</span>
								<button type="button" class="button csl-btn csl-qr-pdf"><?php the_icon( 'download' ); ?>PDF</button>
								<button type="button" class="button csl-btn csl-qr-media"><?php the_icon( 'import' ); ?><?php esc_html_e( 'Save to media library', 'clean-short-links' ); ?></button>
								<span class="csl-help"><?php esc_html_e( 'SVG and PDF are vector files for print. The design is stored with the short link when you save it.', 'clean-short-links' ); ?></span>
							</div>
						</div>
					<?php card_close(); ?>

					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses the period of the chart.
					$days   = isset( $_GET['days'] ) && in_array( absint( $_GET['days'] ), array( 7, 30, 90, 365 ), true ) ? absint( $_GET['days'] ) : 30;
					$series = daily( $link['id'], $days );
					$src    = breakdown( $link['id'], 'source', $days );
					$dev    = breakdown( $link['id'], 'device', $days );
					$ref    = breakdown( $link['id'], 'referrer', $days );
					$total  = array_sum( $series );
					$snames = array( __( 'Link', 'clean-short-links' ), __( 'QR code', 'clean-short-links' ), __( 'Short form', 'clean-short-links' ) );
					$dnames = array( __( 'Desktop', 'clean-short-links' ), __( 'Mobile', 'clean-short-links' ), __( 'Tablet', 'clean-short-links' ) );
					card_open( 'csl-sec-stats', 'chart', __( 'Visits', 'clean-short-links' ), __( 'Counted without IP addresses or cookies. Bots and link previews are left out.', 'clean-short-links' ) );
					?>
						<div class="csl-stats-head">
							<p class="csl-stat is-blue"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong><span><?php /* translators: %d: number of days */ echo esc_html( sprintf( __( '%d days', 'clean-short-links' ), $days ) ); ?></span></p>
							<nav class="csl-segment csl-segment-sm" aria-label="<?php esc_attr_e( 'Period', 'clean-short-links' ); ?>">
								<?php foreach ( array( 7, 30, 90, 365 ) as $d ) : ?>
									<a href="<?php echo esc_url( admin_page_url( 'edit', array( 'id' => $link['id'], 'days' => $d ) ) . '#csl-sec-stats' ); ?>" class="<?php echo $d === $days ? 'is-current' : ''; ?>"><?php /* translators: %d: number of days */ echo esc_html( sprintf( __( '%d days', 'clean-short-links' ), $d ) ); ?></a>
								<?php endforeach; ?>
							</nav>
						</div>
						<?php echo chart_svg( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in chart_svg(). ?>
						<div class="csl-breakdowns">
							<?php
							foreach (
								array(
									array( __( 'Source', 'clean-short-links' ), $src, $snames ),
									array( __( 'Device', 'clean-short-links' ), $dev, $dnames ),
									array( __( 'Referring sites', 'clean-short-links' ), $ref, array() ),
								) as $block
							) :
								$sum = max( 1, array_sum( $block[1] ) );
								?>
								<div>
									<h3><?php echo esc_html( $block[0] ); ?></h3>
									<?php if ( ! $block[1] ) : ?>
										<p class="csl-help">–</p>
									<?php else : ?>
										<ul class="csl-toplist">
										<?php foreach ( $block[1] as $k => $v ) : ?>
											<li><span><?php echo esc_html( $block[2] ? ( $block[2][ (int) $k ] ?? $k ) : ( '' !== $k ? $k : __( 'direct or app', 'clean-short-links' ) ) ); ?></span> <span class="csl-num"><?php echo esc_html( number_format_i18n( $v ) ); ?></span>
												<span class="csl-bar-bg"><span style="width:<?php echo esc_attr( (string) round( 100 * $v / $sum ) ); ?>%"></span></span></li>
										<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
						<p><a class="csl-link-ext" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_csv&id=' . $link['id'] ), 'clean_short_links_csv_' . $link['id'] ) ); ?>"><?php the_icon( 'download' ); ?><?php esc_html_e( 'Export as CSV', 'clean-short-links' ); ?></a></p>
					<?php card_close(); ?>
				<?php endif; ?>

				<div class="csl-savebar">
					<?php submit_button( $link['id'] ? __( 'Save short link', 'clean-short-links' ) : __( 'Create short link', 'clean-short-links' ), 'primary', 'submit', false ); ?>
					<?php toggle( 'paused', 'paused' === $link['status'], __( 'Paused', 'clean-short-links' ) ); ?>
					<?php if ( $link['id'] ) : ?>
						<a class="csl-delete csl-savebar-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_delete&id=' . $link['id'] ), 'clean_short_links_delete_' . $link['id'] ) ); ?>"><?php the_icon( 'trash' ); ?><?php esc_html_e( 'Delete short link', 'clean-short-links' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</form>
	</div>
	<?php
}

/**
 * Cleans the QR design sent by the designer.
 *
 * @param string $json JSON.
 * @return array
 */
function clean_qr_design( $json ) {
	$d = json_decode( (string) $json, true );
	if ( ! is_array( $d ) ) {
		return array();
	}
	return array(
		'fg'          => sanitize_hex_color( $d['fg'] ?? '' ) ? sanitize_hex_color( $d['fg'] ) : '#0e2440',
		'bg'          => sanitize_hex_color( $d['bg'] ?? '' ) ? sanitize_hex_color( $d['bg'] ) : '#ffffff',
		'transparent' => empty( $d['transparent'] ) ? 0 : 1,
		'style'       => in_array( $d['style'] ?? '', array( 'square', 'rounded', 'dots', 'connected' ), true ) ? $d['style'] : 'square',
		'eyes'        => in_array( $d['eyes'] ?? '', array( 'square', 'rounded', 'circle' ), true ) ? $d['eyes'] : 'square',
		'cta'         => in_array( $d['cta'] ?? '', array( '', 'pill', 'frame' ), true ) ? $d['cta'] : '',
		'cta_text'    => substr( sanitize_text_field( $d['cta_text'] ?? '' ), 0, 24 ),
		'logo'        => absint( $d['logo'] ?? 0 ),
		'logo_size'   => min( 28, max( 12, absint( $d['logo_size'] ?? 22 ) ) ),
		'upper'       => empty( $d['upper'] ) ? 0 : 1,
	);
}


add_action(
	'admin_post_clean_short_links_save',
	function () {
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_save' );
		$p    = wp_unslash( $_POST );
		$id   = absint( $p['id'] ?? 0 );
		$kind = sanitize_key( $p['kind'] ?? 'custom' );
		$old  = $id ? link_by_id( $id ) : null;
		if ( $old && normalize_slug( $p['slug'] ?? '' ) !== $old['slug'] && 'custom' !== $kind && $old['kind'] === $kind ) {
			$kind = 'custom';
		}
		$starts = local_to_timestamp( sanitize_text_field( $p['starts_at'] ?? '' ) );
		$ends   = local_to_timestamp( sanitize_text_field( $p['ends_at'] ?? '' ) );
		$data   = array(
			'id'         => $id,
			'title'      => sanitize_text_field( $p['title'] ?? '' ),
			'target'     => sanitize_text_field( $p['target'] ?? '' ),
			'slug'       => sanitize_text_field( $p['slug'] ?? '' ),
			'qr_slug'    => sanitize_text_field( $p['qr_slug'] ?? '' ),
			'kind'       => $kind,
			'root'       => ! empty( $p['root'] ),
			'start_mode' => sanitize_key( $p['start_mode'] ?? '' ),
			'starts_at'  => $starts ? $starts : 0,
			'ends_at'    => $ends ? $ends : 0,
			'fallback'   => sanitize_text_field( $p['fallback'] ?? '' ),
			'rules'      => map_deep( (array) ( $p['rules'] ?? array() ), 'sanitize_text_field' ),
			'qr'         => clean_qr_design( $p['qr_design'] ?? '' ),
			'status'     => empty( $p['paused'] ) ? 'active' : 'paused',
			'old_slug'   => 'retire' === ( $p['old_slug'] ?? '' ) ? 'retire' : 'alias',
			'max_clicks' => absint( $p['max_clicks'] ?? 0 ),
		);
		if ( 'date' === $data['start_mode'] && ! $starts ) {
			$data['start_mode'] = '';
		}
		if ( 'publish' === $data['start_mode'] && ! target_post_id( clean_target( $data['target'] ) ) ) {
			$data['start_mode'] = '';
		}
		$result = save_link( $data );
		if ( is_wp_error( $result ) ) {
			// Keep what was typed is not possible across a redirect – go back with the message.
			wp_safe_redirect( admin_page_url( 'edit', array_filter( array( 'id' => $id, 'csl-error' => $result->get_error_message() ) ) ) );
			exit;
		}
		if ( ! empty( $p['reset_clicks'] ) ) {
			reset_clicks( $result );
		}
		$msg = array(
			'id'      => $result,
			'csl-msg' => 'saved',
		);
		if ( ! empty( $p['invite_generate'] ) ) {
			$ends     = local_to_timestamp( sanitize_text_field( $p['invite_ends'] ?? '' ) );
			$group    = sanitize_text_field( $p['invite_group'] ?? '' );
			$msg['csl-msg'] = 'invites';
			$msg['csl-n']   = create_invites( link_by_id( $result ), '' !== $group ? $group : __( 'Invitations', 'clean-short-links' ), absint( $p['invite_count'] ?? 1 ), absint( $p['invite_clicks'] ?? 1 ), $ends ? $ends : 0 );
			wp_safe_redirect( admin_page_url( 'edit', $msg ) . '#csl-sec-invites' );
			exit;
		}
		wp_safe_redirect( admin_page_url( 'edit', $msg ) );
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_check',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_check_' . $id );
		if ( $id ) {
			$link = link_by_id( $id );
			if ( $link ) {
				check_link( $link );
			}
			wp_safe_redirect( admin_page_url( 'edit', array( 'id' => $id, 'csl-msg' => 'checked' ) ) );
			exit;
		}
		foreach ( links_to_check( 25 ) as $link ) {
			check_link( $link );
		}
		wp_safe_redirect( admin_page_url( '', array( 'csl-msg' => 'checked' ) ) );
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_invites_csv',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_invites_' . $id );
		$link = link_by_id( $id );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="invitations-' . sanitize_file_name( $link ? $link['slug'] : (string) $id ) . '.csv"' );
		$out = "group,link,qr,uses,limit,expires,state\n";
		foreach ( invites( $id ) as $inv ) {
			$urls = short_urls( $inv );
			$out .= sprintf( "%s,%s,%s,%d,%d,%s,%s\n", str_replace( ',', ' ', $inv['title'] ), $urls['link'], $urls['qr'], $inv['clicks'], $inv['max_clicks'], $inv['ends_at'] ? wp_date( 'Y-m-d H:i', $inv['ends_at'] ) : '', link_state( $inv, time() ) );
		}
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download of addresses and numbers.
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_delete',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_delete_' . $id );
		$link = link_by_id( $id );
		delete_link( $id, true );
		if ( $link && $link['parent_id'] ) {
			wp_safe_redirect( admin_page_url( 'edit', array( 'id' => $link['parent_id'], 'csl-msg' => 'deleted' ) ) . '#csl-sec-invites' );
			exit;
		}
		wp_safe_redirect( admin_page_url( '', array( 'csl-msg' => 'deleted' ) ) );
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_alias',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_alias_' . $id );
		delete_alias( $id, normalize_slug( sanitize_text_field( wp_unslash( $_GET['slug'] ?? '' ) ) ) );
		wp_safe_redirect( admin_page_url( 'edit', array( 'id' => $id, 'csl-msg' => 'alias' ) ) );
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_csv',
	function () {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_csv_' . $id );
		$link = link_by_id( $id );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="short-link-' . sanitize_file_name( $link ? $link['slug'] : (string) $id ) . '.csv"' );
		echo export_csv( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download of numbers, dates and host names.
		exit;
	}
);

/* ---------------------------------------------------------------------------
 * Settings, bulk creation and import
 * ------------------------------------------------------------------------ */

/**
 * Settings screen of the site.
 */
function page_settings() {
	$s     = site_settings();
	$n     = network_settings();
	$types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $types['attachment'] );
	list( $p, $q ) = prefixes();
	$own_prefix    = ! is_multisite() || ! empty( $n['site_prefix'] );
	$home          = preg_replace( '#^https?://#', '', home_url( '/' ) );
	$retention     = array(
		90  => __( '3 months', 'clean-short-links' ),
		365 => __( '1 year', 'clean-short-links' ),
		730 => __( '2 years', 'clean-short-links' ),
		0   => __( 'Forever', 'clean-short-links' ),
	);
	$sections      = array(
		'csl-sec-addr'   => __( 'Addresses', 'clean-short-links' ),
		'csl-sec-default' => __( 'Default target', 'clean-short-links' ),
		'csl-sec-auto'   => __( 'Create automatically', 'clean-short-links' ),
		'csl-sec-check'  => __( 'Link check', 'clean-short-links' ),
		'csl-sec-stat'   => __( 'Statistics', 'clean-short-links' ),
		'csl-sec-bulk'   => __( 'Existing content', 'clean-short-links' ),
		'csl-sec-import' => __( 'Import', 'clean-short-links' ),
	);
	?>
	<div class="wrap csl-wrap">
		<h1 class="screen-reader-text"><?php esc_html_e( 'Short link settings', 'clean-short-links' ); ?></h1>
		<?php
		hero(
			__( 'Settings', 'clean-short-links' ),
			'',
			'',
			function () {
				?>
				<a class="button csl-btn" href="<?php echo esc_url( admin_page_url( '' ) ); ?>"><?php esc_html_e( 'All short links', 'clean-short-links' ); ?></a>
				<?php
			},
			function () use ( $home, $p, $q ) {
				echo '<div class="csl-hero-urls"><span class="csl-copy"><code>' . esc_html( $home . $p . '/…' ) . '</code></span><span class="csl-copy"><code>' . esc_html( $home . $q . '/…' ) . '</code></span></div>';
			}
		);
		action_notice();
		?>
		<div class="csl-layout">
			<?php side_nav( $sections ); ?>
			<div class="csl-main">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'clean_short_links_settings' ); ?>
					<input type="hidden" name="action" value="clean_short_links_settings">

					<?php card_open( 'csl-sec-addr', 'link', __( 'Addresses', 'clean-short-links' ), __( 'Under which paths short links and QR codes live.', 'clean-short-links' ) ); ?>
						<?php if ( $own_prefix ) : ?>
							<div class="csl-row">
								<label class="csl-field"><span><?php esc_html_e( 'Links', 'clean-short-links' ); ?></span>
									<span class="csl-slug-input"><span class="csl-slug-pre"><?php echo esc_html( $home ); ?></span><input type="text" name="prefix" value="<?php echo esc_attr( $s['prefix'] ); ?>" placeholder="<?php echo esc_attr( $n['prefix'] ); ?>" class="csl-short"><span class="csl-slug-pre">/…</span></span></label>
								<label class="csl-field"><span><?php esc_html_e( 'QR codes', 'clean-short-links' ); ?></span>
									<span class="csl-slug-input"><span class="csl-slug-pre"><?php echo esc_html( $home ); ?></span><input type="text" name="qr_prefix" value="<?php echo esc_attr( $s['qr_prefix'] ); ?>" placeholder="<?php echo esc_attr( $n['qr_prefix'] ); ?>" class="csl-short"><span class="csl-slug-pre">/…</span></span></label>
							</div>
							<span class="csl-help"><?php esc_html_e( 'Changing a prefix changes all short links – printed QR codes then stop working. Best decide once.', 'clean-short-links' ); ?></span>
						<?php else : ?>
							<p><code><?php echo esc_html( $home . $p . '/…' ); ?></code> · <code><?php echo esc_html( $home . $q . '/…' ); ?></code></p>
							<span class="csl-help"><?php esc_html_e( 'Set for the whole network.', 'clean-short-links' ); ?></span>
						<?php endif; ?>
						<?php if ( ! is_multisite() ) : ?>
							<div class="csl-sub"><?php toggle( 'allow_root', ! empty( $n['allow_root'] ), __( 'Allow the short form without prefix (example.com/slug) – only used where the site has no content', 'clean-short-links' ) ); ?></div>
						<?php endif; ?>
						<?php if ( root_allowed() ) : ?>
							<div class="csl-sub"><?php toggle( 'root_default', ! empty( $s['root_default'] ), __( 'New memorable and own short addresses also get the short form without prefix', 'clean-short-links' ) ); ?></div>
						<?php endif; ?>
						<div class="csl-sub"><?php toggle( 'pass_query', ! empty( $s['pass_query'] ), __( 'Pass parameters such as ?utm_source=… on to the target', 'clean-short-links' ) ); ?></div>
					<?php card_close(); ?>

					<?php card_open( 'csl-sec-default', 'target', __( 'Default target', 'clean-short-links' ), __( 'Where visitors go when a short link does not lead anywhere – instead of a “not found” page.', 'clean-short-links' ) ); ?>
						<div class="csl-tiles csl-tiles-3">
							<?php
							tile( 'default_mode', '', '' === $s['default_mode'], 'page', __( 'None', 'clean-short-links' ), __( '“Not found” or “gone” as usual.', 'clean-short-links' ) );
							tile( 'default_mode', 'home', 'home' === $s['default_mode'], 'logo', __( 'Home page', 'clean-short-links' ), __( 'Visitors land on the home page.', 'clean-short-links' ) );
							tile( 'default_mode', 'custom', 'custom' === $s['default_mode'], 'target', __( 'My choice', 'clean-short-links' ), __( 'A page, a post, a short link or a URL.', 'clean-short-links' ), 'csl-tile-default-custom' );
							?>
						</div>
						<div class="csl-sub csl-default-custom">
							<label class="csl-field" for="csl-default-url"><span><?php esc_html_e( 'Default target', 'clean-short-links' ); ?></span></label>
							<?php target_picker( 'default_url', $s['default_url'], 'csl-default-url' ); ?>
						</div>
						<div class="csl-sub"><?php toggle( 'default_unknown', ! empty( $s['default_unknown'] ), __( 'For unknown short addresses', 'clean-short-links' ), __( 'e.g. a mistyped example.com/s/…', 'clean-short-links' ) ); ?></div>
						<div class="csl-sub"><?php toggle( 'default_inactive', ! empty( $s['default_inactive'] ), __( 'For short links that do not lead anywhere right now', 'clean-short-links' ), __( 'Not started yet, expired, limit reached, paused or retired – unless the link has a fallback of its own.', 'clean-short-links' ) ); ?></div>
					<?php card_close(); ?>

					<?php card_open( 'csl-sec-auto', 'bolt', __( 'Create automatically', 'clean-short-links' ), __( 'When something of these types is published for the first time it gets a short link that starts with the publication.', 'clean-short-links' ) ); ?>
						<div class="csl-chips">
							<?php foreach ( $types as $type ) : ?>
								<label class="csl-chip"><input type="checkbox" name="auto_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['auto_types'], true ) ); ?>><span><?php echo esc_html( $type->labels->name ); ?></span></label>
							<?php endforeach; ?>
						</div>
						<div class="csl-sub">
							<span class="csl-field-label"><?php esc_html_e( 'Kind of short address', 'clean-short-links' ); ?></span>
							<div class="csl-tiles csl-tiles-3">
								<?php
								tile( 'auto_kind', 'random', 'random' === $s['auto_kind'], 'shuffle', __( 'Random', 'clean-short-links' ), __( 'Short and unique, e.g. k7mq4', 'clean-short-links' ) );
								tile( 'auto_kind', 'memorable', 'memorable' === $s['auto_kind'], 'sparkle', __( 'Memorable', 'clean-short-links' ), __( 'Two words, e.g. blue-couch', 'clean-short-links' ) );
								tile( 'auto_kind', 'title', 'title' === $s['auto_kind'], 'type', __( 'From the title', 'clean-short-links' ), __( 'The first words of the title', 'clean-short-links' ) );
								?>
							</div>
						</div>
					<?php card_close(); ?>

					<?php card_open( 'csl-sec-check', 'shield', __( 'Link check', 'clean-short-links' ), __( 'Checks whether short links answer and whether their targets can still be reached. Only the target addresses are requested – nothing else is sent.', 'clean-short-links' ) ); ?>
						<?php toggle( 'auto_check', ! empty( $s['auto_check'] ), __( 'Check automatically once a day', 'clean-short-links' ), __( 'Up to 25 short links per day, the ones checked longest ago first.', 'clean-short-links' ) ); ?>
					<?php card_close(); ?>

					<?php card_open( 'csl-sec-stat', 'chart', __( 'Statistics', 'clean-short-links' ), __( 'Only daily totals are stored – no IP addresses, no cookies.', 'clean-short-links' ) ); ?>
						<?php toggle( 'count_editors', ! empty( $s['count_editors'] ), __( 'Also count visits of logged-in authors and editors', 'clean-short-links' ) ); ?>
						<div class="csl-sub">
							<span class="csl-field-label"><?php esc_html_e( 'Keep statistics', 'clean-short-links' ); ?></span>
							<?php if ( ! is_multisite() ) : ?>
								<span class="csl-segment">
									<?php foreach ( $retention as $d => $label ) : ?>
										<label><input type="radio" name="retention_days" value="<?php echo esc_attr( (string) $d ); ?>" <?php checked( (int) $n['retention_days'], $d ); ?>><span><?php echo esc_html( $label ); ?></span></label>
									<?php endforeach; ?>
								</span>
							<?php else : ?>
								<p><?php echo esc_html( $retention[ (int) $n['retention_days'] ] ?? '' ); ?> <span class="csl-help"><?php esc_html_e( 'Set for the whole network.', 'clean-short-links' ); ?></span></p>
							<?php endif; ?>
						</div>
					<?php card_close(); ?>

					<div class="csl-savebar">
						<?php submit_button( __( 'Save settings', 'clean-short-links' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<?php card_open( 'csl-sec-bulk', 'plus', __( 'Short links for existing content', 'clean-short-links' ), __( 'One click gives every published item without a short link one – with the kind chosen above.', 'clean-short-links' ) ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="csl-inline">
						<?php wp_nonce_field( 'clean_short_links_bulk' ); ?>
						<input type="hidden" name="action" value="clean_short_links_bulk">
						<label class="screen-reader-text" for="csl-bulk-type"><?php esc_html_e( 'Content type', 'clean-short-links' ); ?></label>
						<select name="type" id="csl-bulk-type">
							<?php foreach ( $types as $type ) : ?>
								<option value="<?php echo esc_attr( $type->name ); ?>"><?php echo esc_html( $type->labels->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<button class="button csl-btn"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'Create short links', 'clean-short-links' ); ?></button>
					</form>
				<?php card_close(); ?>

				<?php card_open( 'csl-sec-import', 'import', __( 'Import', 'clean-short-links' ), __( 'One short link per line: short address ; target ; title – separated by semicolons or tabs. Targets on this site are recognised as posts and pages. Imported links also get the short form without prefix, so existing short links like example.com/abc keep working.', 'clean-short-links' ) ); ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'clean_short_links_import' ); ?>
						<input type="hidden" name="action" value="clean_short_links_import">
						<label class="screen-reader-text" for="csl-import"><?php esc_html_e( 'Import', 'clean-short-links' ); ?></label>
						<textarea id="csl-import" name="lines" rows="5" class="csl-wide code" placeholder="725 ; https://example.com/725-years/ ; 725 years"></textarea>
						<p><button class="button csl-btn"><?php the_icon( 'import' ); ?><?php esc_html_e( 'Import', 'clean-short-links' ); ?></button></p>
					</form>
				<?php card_close(); ?>
			</div>
		</div>
	</div>
	<?php
}

add_action(
	'admin_post_clean_short_links_settings',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_settings' );
		$p     = wp_unslash( $_POST );
		$s     = site_settings();
		$types = array_keys( get_post_types( array( 'public' => true ) ) );
		$new   = array(
			'prefix'        => clean_prefix( $p['prefix'] ?? '' ),
			'qr_prefix'     => clean_prefix( $p['qr_prefix'] ?? '' ),
			'auto_types'    => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['auto_types'] ?? array() ) ), $types ) ),
			'auto_kind'     => in_array( $p['auto_kind'] ?? '', array( 'random', 'memorable', 'title' ), true ) ? $p['auto_kind'] : 'random',
			'root_default'  => empty( $p['root_default'] ) ? 0 : 1,
			'count_editors' => empty( $p['count_editors'] ) ? 0 : 1,
			'pass_query'    => empty( $p['pass_query'] ) ? 0 : 1,
			'default_mode'  => in_array( $p['default_mode'] ?? '', array( 'home', 'custom' ), true ) ? $p['default_mode'] : '',
			'default_url'   => clean_target( sanitize_text_field( $p['default_url'] ?? '' ) ),
			'default_unknown'  => empty( $p['default_unknown'] ) ? 0 : 1,
			'default_inactive' => empty( $p['default_inactive'] ) ? 0 : 1,
			'auto_check'    => empty( $p['auto_check'] ) ? 0 : 1,
		);
		if ( 'custom' === $new['default_mode'] && '' === $new['default_url'] ) {
			$new['default_mode'] = '';
		}
		if ( is_multisite() && empty( network_settings()['site_prefix'] ) ) {
			$new['prefix']    = $s['prefix'];
			$new['qr_prefix'] = $s['qr_prefix'];
		}
		if ( '' !== $new['prefix'] && $new['prefix'] === $new['qr_prefix'] ) {
			$new['qr_prefix'] = '';
		}
		update_option( 'clean_short_links_site', $new );
		if ( ! is_multisite() ) {
			$n                   = network_settings();
			$n['allow_root']     = empty( $p['allow_root'] ) ? 0 : 1;
			$n['retention_days'] = in_array( (int) ( $p['retention_days'] ?? 730 ), array( 0, 90, 365, 730 ), true ) ? (int) $p['retention_days'] : 730;
			update_option( 'clean_short_links_network', $n );
		}
		wp_safe_redirect( admin_page_url( 'settings', array( 'csl-msg' => 'settings' ) ) );
		exit;
	}
);

add_action(
	'admin_post_clean_short_links_bulk',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_bulk' );
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		$n    = 0;
		if ( post_type_exists( $type ) ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $post_id ) {
				if ( ! get_link_for_post( $post_id ) && ! is_wp_error( create_for_post( $post_id, site_settings()['auto_kind'] ) ) ) {
					++$n;
				}
			}
		}
		wp_safe_redirect( admin_page_url( 'settings', array( 'csl-msg' => 'bulk', 'csl-n' => $n ) ) );
		exit;
	}
);

/**
 * Parses import lines.
 *
 * @param string $text Lines "slug ; target ; title".
 * @return array[] Each with slug, target, title.
 */
function parse_import( $text ) {
	$out = array();
	foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		$parts = array_map( 'trim', preg_split( '/\s*[;\t]\s*/', $line, 3 ) );
		if ( count( $parts ) < 2 ) {
			continue;
		}
		$slug = $parts[0];
		if ( preg_match( '#^https?://#i', $slug ) ) {
			$slug = (string) wp_parse_url( $slug, PHP_URL_PATH );
		}
		$out[] = array(
			'slug'   => normalize_slug( basename( trim( $slug, '/' ) ) ),
			'target' => $parts[1],
			'title'  => $parts[2] ?? '',
		);
	}
	return $out;
}

add_action(
	'admin_post_clean_short_links_import',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_import' );
		$n      = 0;
		$errors = array();
		foreach ( parse_import( sanitize_textarea_field( wp_unslash( $_POST['lines'] ?? '' ) ) ) as $row ) {
			$target  = $row['target'];
			$post_id = url_to_postid( $target );
			if ( $post_id ) {
				$target = 'post:' . $post_id;
			}
			$result = save_link(
				array(
					'title'  => $row['title'],
					'target' => $target,
					'slug'   => $row['slug'],
					'kind'   => 'custom',
					'root'   => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $row['slug'] . ': ' . $result->get_error_message();
			} else {
				++$n;
			}
		}
		$args = array(
			'csl-msg' => 'import',
			'csl-n'   => $n,
		);
		if ( $errors ) {
			$args['csl-error'] = implode( ' · ', $errors );
		}
		wp_safe_redirect( admin_page_url( 'settings', $args ) );
		exit;
	}
);

/* ---------------------------------------------------------------------------
 * Posts list: a column with the short link
 * ------------------------------------------------------------------------ */

add_filter(
	'post_row_actions',
	__NAMESPACE__ . '\\row_action',
	10,
	2
);
add_filter(
	'page_row_actions',
	__NAMESPACE__ . '\\row_action',
	10,
	2
);

/**
 * "Short link" in the row actions of posts and pages.
 *
 * @param array    $actions Actions.
 * @param \WP_Post $post    Post.
 * @return array
 */
function row_action( $actions, $post ) {
	if ( ! current_user_can( capability() ) || ! is_post_type_viewable( $post->post_type ) ) {
		return $actions;
	}
	$link = get_link_for_post( $post->ID );
	if ( $link ) {
		$actions['clean_short_links'] = sprintf( '<a href="%s">%s</a>', esc_url( admin_page_url( 'edit', array( 'id' => $link['id'] ) ) ), esc_html__( 'Short link', 'clean-short-links' ) );
	} else {
		$actions['clean_short_links'] = sprintf( '<a href="%s">%s</a>', esc_url( admin_page_url( 'edit', array( 'post' => $post->ID ) ) ), esc_html__( 'Create short link', 'clean-short-links' ) );
	}
	return $actions;
}
