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
		wp_enqueue_style( 'clean-short-links-admin', $url . 'admin.css', array(), CLEAN_SHORT_LINKS_VERSION );
		wp_enqueue_script( 'clean-short-links-admin', $url . 'admin.js', array( 'wp-api-fetch' ), CLEAN_SHORT_LINKS_VERSION, true );
		wp_localize_script( 'clean-short-links-admin', 'CleanShortLinksAdmin', admin_js_data() );
		if ( false !== strpos( (string) $hook, 'clean-short-links-edit' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'clean-short-links-qr', $url . 'qr.js', array(), CLEAN_SHORT_LINKS_VERSION, true );
			wp_enqueue_script( 'clean-short-links-designer', $url . 'qr-designer.js', array( 'clean-short-links-qr', 'wp-api-fetch' ), CLEAN_SHORT_LINKS_VERSION, true );
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
		'paused'  => __( 'Paused', 'clean-short-links' ),
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
		echo '<small>' . esc_html( $label ) . '</small> ';
	}
	echo '<code>' . esc_html( preg_replace( '#^https?://#', '', $url ) ) . '</code>';
	echo '<button type="button" class="button-link csl-copy-btn" data-copy="' . esc_attr( $url ) . '" aria-label="' . esc_attr__( 'Copy', 'clean-short-links' ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>';
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
	);
	if ( isset( $messages[ $msg ] ) ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $msg ] ) );
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
	$list   = list_links(
		array(
			'search' => $search,
			'state'  => $state,
			'paged'  => $paged,
		)
	);
	$ids    = wp_list_pluck( $list['items'], 'id' );
	$v30    = totals( $ids, 30 );
	$vall   = totals( $ids, 0 );
	$now    = time();
	$top    = top_links( 30, 5 );
	$states = array(
		''          => __( 'All', 'clean-short-links' ),
		'waiting'   => __( 'Starts later', 'clean-short-links' ),
		'expired'   => __( 'Expired', 'clean-short-links' ),
		'paused'    => __( 'Paused', 'clean-short-links' ),
		'scheduled' => __( 'With rules', 'clean-short-links' ),
	);
	?>
	<div class="wrap csl-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Short links', 'clean-short-links' ); ?></h1>
		<a href="<?php echo esc_url( admin_page_url( 'edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add new', 'clean-short-links' ); ?></a>
		<hr class="wp-header-end">
		<?php action_notice(); ?>

		<div class="csl-summary">
			<div class="csl-card csl-kpi">
				<span><?php esc_html_e( 'Visits in the last 30 days', 'clean-short-links' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( site_total( 30 ) ) ); ?></strong>
				<?php echo chart_svg( daily( 0, 30 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in chart_svg(). ?>
			</div>
			<div class="csl-card csl-top">
				<h2><?php esc_html_e( 'Most visited', 'clean-short-links' ); ?></h2>
				<?php if ( ! $top ) : ?>
					<p class="description"><?php esc_html_e( 'No visits yet.', 'clean-short-links' ); ?></p>
				<?php else : ?>
					<ol>
						<?php
						foreach ( $top as $id => $hits ) :
							$l = link_by_id( $id );
							if ( ! $l ) {
								continue;
							}
							?>
							<li><a href="<?php echo esc_url( admin_page_url( 'edit', array( 'id' => $id ) ) ); ?>"><?php echo esc_html( '' !== $l['title'] ? $l['title'] : $l['slug'] ); ?></a> <span class="csl-num"><?php echo esc_html( number_format_i18n( $hits ) ); ?></span></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
		</div>

		<ul class="subsubsub">
			<?php
			$parts = array();
			foreach ( $states as $key => $label ) {
				$parts[] = sprintf( '<li><a href="%s"%s>%s</a>', esc_url( admin_page_url( '', '' !== $key ? array( 'state' => $key ) : array() ) ), $key === $state ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
			}
			echo implode( ' | </li>', $parts ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			?>
		</ul>
		<form method="get" class="search-box">
			<input type="hidden" name="page" value="clean-short-links">
			<?php if ( '' !== $state ) : ?>
				<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="csl-search"><?php esc_html_e( 'Search short links', 'clean-short-links' ); ?></label>
			<input type="search" id="csl-search" name="s" value="<?php echo esc_attr( $search ); ?>">
			<button class="button"><?php esc_html_e( 'Search', 'clean-short-links' ); ?></button>
		</form>

		<table class="widefat striped csl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title and target', 'clean-short-links' ); ?></th>
					<th><?php esc_html_e( 'Short link', 'clean-short-links' ); ?></th>
					<th><?php esc_html_e( 'State', 'clean-short-links' ); ?></th>
					<th class="csl-num"><?php esc_html_e( '30 days', 'clean-short-links' ); ?></th>
					<th class="csl-num"><?php esc_html_e( 'Total', 'clean-short-links' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $list['items'] ) : ?>
				<tr><td colspan="5">
					<?php esc_html_e( 'No short links yet.', 'clean-short-links' ); ?>
					<a href="<?php echo esc_url( admin_page_url( 'edit' ) ); ?>"><?php esc_html_e( 'Create the first one', 'clean-short-links' ); ?></a>
				</td></tr>
			<?php endif; ?>
			<?php
			foreach ( $list['items'] as $l ) :
				$urls  = short_urls( $l );
				$st    = link_state( $l, $now );
				$edit  = admin_page_url( 'edit', array( 'id' => $l['id'] ) );
				$del   = wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_delete&id=' . $l['id'] ), 'clean_short_links_delete_' . $l['id'] );
				$label = '' !== $l['title'] ? $l['title'] : target_label( $l['target'] );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $label ); ?></a></strong>
						<div class="csl-target">→ <?php echo esc_html( target_label( $l['target'] ) ); ?>
							<?php if ( $l['rules'] ) : ?>
								<span class="csl-badge"><?php /* translators: %d: number of rules */ echo esc_html( sprintf( _n( '+%d rule', '+%d rules', count( $l['rules'] ), 'clean-short-links' ), count( $l['rules'] ) ) ); ?></span>
							<?php endif; ?>
						</div>
						<div class="row-actions">
							<span><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'clean-short-links' ); ?></a> | </span>
							<span><a href="<?php echo esc_url( $edit . '#csl-qr' ); ?>"><?php esc_html_e( 'QR code', 'clean-short-links' ); ?></a> | </span>
							<span class="trash"><a href="<?php echo esc_url( $del ); ?>" class="csl-delete"><?php esc_html_e( 'Delete', 'clean-short-links' ); ?></a></span>
						</div>
					</td>
					<td>
						<?php copy_field( $urls['link'] ); ?>
						<?php copy_field( $urls['root'] ); ?>
					</td>
					<td><span class="csl-state csl-state-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( state_label( $st ) ); ?></span>
						<?php if ( $l['ends_at'] && 'expired' !== $st ) : ?>
							<br><small><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'until %s', 'clean-short-links' ), wp_date( get_option( 'date_format' ), $l['ends_at'] ) ) ); ?></small>
						<?php endif; ?>
					</td>
					<td class="csl-num"><?php echo esc_html( number_format_i18n( $v30[ $l['id'] ] ?? 0 ) ); ?></td>
					<td class="csl-num"><?php echo esc_html( number_format_i18n( $vall[ $l['id'] ] ?? 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $list['total'] / 30 );
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
	$pto   = $kind && 'url' !== $kind ? get_post_type_object( $kind ) : null;
	?>
	<div class="csl-picker">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $target ); ?>" class="csl-picker-value">
		<input type="search" id="<?php echo esc_attr( $id ); ?>" class="regular-text csl-picker-input" autocomplete="off" value="<?php echo esc_attr( target_post_id( $target ) ? $label : $target ); ?>" placeholder="<?php esc_attr_e( 'Search posts and pages or paste a URL', 'clean-short-links' ); ?>"<?php echo $required ? ' required' : ''; ?>>
		<span class="csl-picker-kind"><?php echo esc_html( $pto ? $pto->labels->singular_name : ( 'url' === $kind ? __( 'URL', 'clean-short-links' ) : '' ) ); ?></span>
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
			<button type="button" class="button-link csl-rule-up" aria-label="<?php esc_attr_e( 'Move up', 'clean-short-links' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
			<button type="button" class="button-link csl-rule-remove" aria-label="<?php esc_attr_e( 'Remove rule', 'clean-short-links' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
		</div>
		<div class="csl-rule-grid">
			<label><?php esc_html_e( 'From', 'clean-short-links' ); ?><br><input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[from]" value="<?php echo esc_attr( str_replace( ' ', 'T', $rule['from'] ?? '' ) ); ?>"></label>
			<label><?php esc_html_e( 'Until', 'clean-short-links' ); ?><br><input type="datetime-local" name="<?php echo esc_attr( $n ); ?>[to]" value="<?php echo esc_attr( str_replace( ' ', 'T', $rule['to'] ?? '' ) ); ?>"></label>
			<label><?php esc_html_e( 'Daily from', 'clean-short-links' ); ?><br><input type="time" name="<?php echo esc_attr( $n ); ?>[time_from]" value="<?php echo esc_attr( $rule['time_from'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Daily until', 'clean-short-links' ); ?><br><input type="time" name="<?php echo esc_attr( $n ); ?>[time_to]" value="<?php echo esc_attr( $rule['time_to'] ?? '' ); ?>"></label>
		</div>
		<div class="csl-days" role="group" aria-label="<?php esc_attr_e( 'Weekdays', 'clean-short-links' ); ?>">
			<?php
			for ( $d = 1; $d <= 7; $d++ ) :
				$wd = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d % 7 ) ) : (string) $d;
				?>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[days][]" value="<?php echo esc_attr( (string) $d ); ?>" <?php checked( in_array( $d, array_map( 'intval', (array) ( $rule['days'] ?? array() ) ), true ) ); ?>> <?php echo esc_html( $wd ); ?></label>
			<?php endfor; ?>
			<small><?php esc_html_e( 'None ticked = every day', 'clean-short-links' ); ?></small>
		</div>
		<div class="csl-rule-target">
			<label><?php esc_html_e( 'Leads to', 'clean-short-links' ); ?></label>
			<?php target_picker( $n . '[target]', $rule['target'] ?? '', 'csl-rule-target-' . $i ); ?>
		</div>
	</fieldset>
	<?php
}

/**
 * Add or edit a short link.
 */
function page_edit() {
	$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selects the link to edit.
	$link = $id ? link_by_id( $id ) : null;
	if ( $id && ! $link ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Short links', 'clean-short-links' ) . '</h1><p>' . esc_html__( 'This short link no longer exists.', 'clean-short-links' ) . '</p></div>';
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
	);
	$urls          = $link['id'] ? short_urls( $link ) : null;
	list( $p, $q ) = prefixes();
	$home          = preg_replace( '#^https?://#', '', home_url( '/' ) );
	?>
	<div class="wrap csl-wrap">
		<h1><?php echo esc_html( $link['id'] ? __( 'Edit short link', 'clean-short-links' ) : __( 'Add short link', 'clean-short-links' ) ); ?></h1>
		<?php action_notice(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="csl-edit" id="csl-form">
			<?php wp_nonce_field( 'clean_short_links_save' ); ?>
			<input type="hidden" name="action" value="clean_short_links_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $link['id'] ); ?>">
			<input type="hidden" name="qr_design" id="csl-qr-design" value="<?php echo esc_attr( wp_json_encode( $link['qr'] ? $link['qr'] : new \stdClass() ) ); ?>">

			<div class="csl-columns">
			<div class="csl-main">

			<div class="csl-card">
				<h2><?php esc_html_e( 'Target', 'clean-short-links' ); ?></h2>
				<p>
					<label for="csl-title"><?php esc_html_e( 'Title', 'clean-short-links' ); ?></label><br>
					<input type="text" id="csl-title" name="title" class="large-text" value="<?php echo esc_attr( $link['title'] ); ?>" placeholder="<?php esc_attr_e( 'Only for you, e.g. “Poster 725 years”', 'clean-short-links' ); ?>">
				</p>
				<p><label for="csl-target"><?php esc_html_e( 'Leads to', 'clean-short-links' ); ?></label></p>
				<?php target_picker( 'target', $link['target'], 'csl-target', true ); ?>
				<p class="description"><?php esc_html_e( 'Posts and pages are followed by ID – the short link keeps working when their address changes.', 'clean-short-links' ); ?></p>
			</div>

			<div class="csl-card">
				<h2><?php esc_html_e( 'Short address', 'clean-short-links' ); ?></h2>
				<div class="csl-kinds" role="radiogroup" aria-label="<?php esc_attr_e( 'Kind of short address', 'clean-short-links' ); ?>">
					<?php
					$kinds = array(
						'random'    => __( 'Random', 'clean-short-links' ),
						'memorable' => __( 'Memorable', 'clean-short-links' ),
						'title'     => __( 'From the title', 'clean-short-links' ),
						'custom'    => __( 'My own', 'clean-short-links' ),
					);
					foreach ( $kinds as $k => $label ) :
						?>
						<label><input type="radio" name="kind" value="<?php echo esc_attr( $k ); ?>" <?php checked( $link['kind'], $k ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<button type="button" class="button csl-reroll"><span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e( 'New suggestion', 'clean-short-links' ); ?></button>
				</div>
				<p class="csl-slug-row">
					<label for="csl-slug" class="screen-reader-text"><?php esc_html_e( 'Short address', 'clean-short-links' ); ?></label>
					<span class="csl-slug-pre"><?php echo esc_html( $home . $p . '/' ); ?></span><input type="text" id="csl-slug" name="slug" value="<?php echo esc_attr( $link['slug'] ); ?>" data-original="<?php echo esc_attr( $link['slug'] ); ?>" data-id="<?php echo esc_attr( (string) $link['id'] ); ?>" maxlength="64" required autocomplete="off" spellcheck="false">
				</p>
				<p class="csl-slug-msg" aria-live="polite"></p>
				<?php if ( $link['id'] ) : ?>
					<div class="csl-old-slug" hidden>
						<p><?php /* translators: %s: old short address */ echo esc_html( sprintf( __( 'What happens to the old short address “%s”?', 'clean-short-links' ), $link['slug'] ) ); ?></p>
						<label><input type="radio" name="old_slug" value="alias" checked> <?php esc_html_e( 'Keep it working as an additional address', 'clean-short-links' ); ?></label><br>
						<label><input type="radio" name="old_slug" value="retire"> <?php esc_html_e( 'Retire it – it then answers “gone” (410) and is never handed out again', 'clean-short-links' ); ?></label>
					</div>
				<?php endif; ?>
				<?php if ( root_allowed() ) : ?>
					<p>
						<label><input type="checkbox" name="root" value="1" id="csl-root" <?php checked( ! empty( $link['root'] ) || ( ! $link['id'] && ! empty( $s['root_default'] ) && 'random' !== $link['kind'] ) ); ?>>
						<?php /* translators: %s: example of the short form */ echo esc_html( sprintf( __( 'Also as short form %s', 'clean-short-links' ), $home . '…' ) ); ?></label>
						<br><span class="description"><?php esc_html_e( 'Only works as long as the site has no content at that address – existing pages always win, the link with prefix keeps working.', 'clean-short-links' ); ?></span>
					</p>
					<p class="csl-root-msg" aria-live="polite"></p>
				<?php endif; ?>
				<p>
					<label><input type="checkbox" id="csl-qr-same" <?php checked( '' === $link['qr_slug'] ); ?>>
					<?php /* translators: %s: QR prefix */ echo esc_html( sprintf( __( 'QR code uses the same short address (/%s/…)', 'clean-short-links' ), $q ) ); ?></label>
				</p>
				<p class="csl-qr-slug-row" <?php echo '' === $link['qr_slug'] ? 'hidden' : ''; ?>>
					<span class="csl-slug-pre"><?php echo esc_html( $home . $q . '/' ); ?></span><input type="text" name="qr_slug" id="csl-qr-slug" value="<?php echo esc_attr( $link['qr_slug'] ); ?>" maxlength="64" autocomplete="off" spellcheck="false">
				</p>
			</div>

			<div class="csl-card">
				<h2><?php esc_html_e( 'Time window', 'clean-short-links' ); ?></h2>
				<fieldset>
					<legend><?php esc_html_e( 'Start', 'clean-short-links' ); ?></legend>
					<label><input type="radio" name="start_mode" value="" <?php checked( $link['start_mode'], '' ); ?>> <?php esc_html_e( 'Right away', 'clean-short-links' ); ?></label>
					<label><input type="radio" name="start_mode" value="date" <?php checked( $link['start_mode'], 'date' ); ?>> <?php esc_html_e( 'At', 'clean-short-links' ); ?></label>
					<input type="datetime-local" name="starts_at" value="<?php echo esc_attr( timestamp_to_local( $link['starts_at'] ) ); ?>">
					<label class="csl-publish-start"><input type="radio" name="start_mode" value="publish" <?php checked( $link['start_mode'], 'publish' ); ?>> <?php esc_html_e( 'When the content is published', 'clean-short-links' ); ?></label>
				</fieldset>
				<p>
					<label for="csl-ends"><?php esc_html_e( 'Expires', 'clean-short-links' ); ?></label>
					<input type="datetime-local" id="csl-ends" name="ends_at" value="<?php echo esc_attr( timestamp_to_local( $link['ends_at'] ) ); ?>">
					<span class="description"><?php esc_html_e( 'Empty = never', 'clean-short-links' ); ?></span>
				</p>
				<p><label for="csl-fallback"><?php esc_html_e( 'Before the start and after expiry lead to', 'clean-short-links' ); ?></label></p>
				<?php target_picker( 'fallback', $link['fallback'], 'csl-fallback' ); ?>
				<p class="description"><?php /* translators: %s: time zone */ echo esc_html( sprintf( __( 'Empty = “not found” before the start and “gone” after expiry. Times in the site’s time zone (%s).', 'clean-short-links' ), wp_timezone_string() ) ); ?></p>
			</div>

			<div class="csl-card">
				<h2><?php esc_html_e( 'Different targets by date and time', 'clean-short-links' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The first rule that fits right now decides where the link leads. If none fits, the target above applies. Example: during the festival to the programme, in the evening to the stage plan.', 'clean-short-links' ); ?></p>
				<div class="csl-rules">
					<?php
					foreach ( $link['rules'] as $i => $rule ) {
						rule_row( $i, $rule );
					}
					?>
				</div>
				<template id="csl-rule-template"><?php rule_row( '__i__', array() ); ?></template>
				<p><button type="button" class="button csl-rule-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Add rule', 'clean-short-links' ); ?></button></p>
			</div>

			<p class="submit">
				<label class="csl-status"><input type="checkbox" name="paused" value="1" <?php checked( 'paused', $link['status'] ); ?>> <?php esc_html_e( 'Paused', 'clean-short-links' ); ?></label>
				<?php submit_button( $link['id'] ? __( 'Save short link', 'clean-short-links' ) : __( 'Create short link', 'clean-short-links' ), 'primary', 'submit', false ); ?>
			</p>
			</div>

			<div class="csl-side">
				<?php if ( $urls ) : ?>
					<?php
					$now_result = resolve( $link, new \DateTimeImmutable( 'now', wp_timezone() ), __NAMESPACE__ . '\\target_url', __NAMESPACE__ . '\\target_published' );
					?>
					<div class="csl-card">
						<h2><?php esc_html_e( 'Short link', 'clean-short-links' ); ?></h2>
						<p><?php copy_field( $urls['link'] ); ?></p>
						<?php if ( $urls['root'] ) : ?>
							<p><?php copy_field( $urls['root'], __( 'Short form', 'clean-short-links' ) ); ?></p>
						<?php endif; ?>
						<p class="csl-now">
							<?php if ( 302 === $now_result['status'] ) : ?>
								<?php esc_html_e( 'Right now it leads to', 'clean-short-links' ); ?>
								<a href="<?php echo esc_url( $now_result['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $now_result['url'] ) ); ?></a>
								<?php if ( 0 === strpos( $now_result['state'], 'rule-' ) ) : ?>
									<span class="csl-badge"><?php /* translators: %s: number of the rule */ echo esc_html( sprintf( __( 'rule %s', 'clean-short-links' ), substr( $now_result['state'], 5 ) ) ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<?php /* translators: %d: HTTP status */ echo esc_html( sprintf( __( 'Right now it answers with status %d.', 'clean-short-links' ), $now_result['status'] ) ); ?>
							<?php endif; ?>
						</p>
						<?php
						$aliases = link_aliases( $link['id'] );
						if ( $aliases ) :
							?>
							<h3><?php esc_html_e( 'Earlier short addresses', 'clean-short-links' ); ?></h3>
							<ul class="csl-aliases">
								<?php foreach ( $aliases as $a ) : ?>
									<li><code><?php echo esc_html( $a->slug ); ?></code> <small><?php echo esc_html( 'alias' === $a->kind ? __( 'still works', 'clean-short-links' ) : __( 'retired', 'clean-short-links' ) ); ?></small>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_alias&id=' . $link['id'] . '&slug=' . rawurlencode( $a->slug ) ), 'clean_short_links_alias_' . $link['id'] ) ); ?>"><?php esc_html_e( 'Release', 'clean-short-links' ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>

					<div class="csl-card" id="csl-qr">
						<h2><?php esc_html_e( 'QR code', 'clean-short-links' ); ?></h2>
						<p><?php copy_field( $urls['qr'] ); ?></p>
						<p class="description"><?php esc_html_e( 'The QR code contains the short link – time window and rules also apply to printed codes.', 'clean-short-links' ); ?></p>
						<p><button type="button" class="button csl-qr-open"><?php esc_html_e( 'Create QR code', 'clean-short-links' ); ?></button></p>
						<div class="csl-designer" hidden data-url="<?php echo esc_attr( $urls['qr'] ); ?>" data-upper="<?php echo esc_attr( $urls['qr_upper'] ); ?>" data-slug="<?php echo esc_attr( '' !== $link['qr_slug'] ? $link['qr_slug'] : $link['slug'] ); ?>">
							<div class="csl-qr-preview"></div>
							<p class="csl-qr-info description"></p>
							<div class="csl-qr-controls">
								<label><?php esc_html_e( 'Colour', 'clean-short-links' ); ?> <input type="color" data-qr="fg" value="#0e2440"></label>
								<label><?php esc_html_e( 'Background', 'clean-short-links' ); ?> <input type="color" data-qr="bg" value="#ffffff"></label>
								<label><?php esc_html_e( 'Dots', 'clean-short-links' ); ?>
									<select data-qr="style">
										<option value="square"><?php esc_html_e( 'Squares', 'clean-short-links' ); ?></option>
										<option value="rounded"><?php esc_html_e( 'Rounded', 'clean-short-links' ); ?></option>
										<option value="dots"><?php esc_html_e( 'Dots', 'clean-short-links' ); ?></option>
									</select>
								</label>
								<label><?php esc_html_e( 'Corners', 'clean-short-links' ); ?>
									<select data-qr="eyes">
										<option value="square"><?php esc_html_e( 'Square', 'clean-short-links' ); ?></option>
										<option value="rounded"><?php esc_html_e( 'Rounded', 'clean-short-links' ); ?></option>
									</select>
								</label>
								<div class="csl-qr-logo">
									<button type="button" class="button csl-qr-logo-pick"><?php esc_html_e( 'Logo or symbol …', 'clean-short-links' ); ?></button>
									<button type="button" class="button-link csl-qr-logo-remove" hidden><?php esc_html_e( 'Remove logo', 'clean-short-links' ); ?></button>
									<label class="csl-qr-logo-size" hidden><?php esc_html_e( 'Size', 'clean-short-links' ); ?> <input type="range" min="12" max="28" step="1" data-qr="logo_size" value="22"></label>
								</div>
								<label><input type="checkbox" data-qr="upper" checked> <?php esc_html_e( 'Compact code (address in capitals)', 'clean-short-links' ); ?></label>
								<p class="csl-qr-warn" aria-live="polite"></p>
							</div>
							<div class="csl-qr-actions">
								<button type="button" class="button button-primary csl-qr-svg"><?php esc_html_e( 'Download SVG', 'clean-short-links' ); ?></button>
								<label class="screen-reader-text" for="csl-qr-px"><?php esc_html_e( 'PNG size', 'clean-short-links' ); ?></label>
								<select id="csl-qr-px"><option value="512">512 px</option><option value="1024" selected>1024 px</option><option value="2048">2048 px</option></select>
								<button type="button" class="button csl-qr-png"><?php esc_html_e( 'Download PNG', 'clean-short-links' ); ?></button>
								<button type="button" class="button csl-qr-media"><?php esc_html_e( 'Save to media library', 'clean-short-links' ); ?></button>
								<p class="description"><?php esc_html_e( 'The design is stored with the short link when you save it.', 'clean-short-links' ); ?></p>
							</div>
						</div>
					</div>

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
					?>
					<div class="csl-card" id="csl-stats">
						<h2><?php esc_html_e( 'Visits', 'clean-short-links' ); ?></h2>
						<p class="csl-periods">
							<?php foreach ( array( 7, 30, 90, 365 ) as $d ) : ?>
								<a href="<?php echo esc_url( admin_page_url( 'edit', array( 'id' => $link['id'], 'days' => $d ) ) . '#csl-stats' ); ?>" class="<?php echo $d === $days ? 'current' : ''; ?>"><?php /* translators: %d: number of days */ echo esc_html( sprintf( __( '%d days', 'clean-short-links' ), $d ) ); ?></a>
							<?php endforeach; ?>
						</p>
						<p class="csl-kpi"><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></p>
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
								?>
								<div>
									<h3><?php echo esc_html( $block[0] ); ?></h3>
									<?php if ( ! $block[1] ) : ?>
										<p class="description">–</p>
									<?php else : ?>
										<ul>
										<?php foreach ( $block[1] as $k => $v ) : ?>
											<li><span><?php echo esc_html( $block[2] ? ( $block[2][ (int) $k ] ?? $k ) : ( '' !== $k ? $k : __( 'direct or app', 'clean-short-links' ) ) ); ?></span> <span class="csl-num"><?php echo esc_html( number_format_i18n( $v ) ); ?></span></li>
										<?php endforeach; ?>
										</ul>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
						<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_csv&id=' . $link['id'] ), 'clean_short_links_csv_' . $link['id'] ) ); ?>"><?php esc_html_e( 'Export as CSV', 'clean-short-links' ); ?></a></p>
						<p class="description"><?php esc_html_e( 'Counted without IP addresses or cookies. Bots and link previews are left out.', 'clean-short-links' ); ?></p>
					</div>
				<?php else : ?>
					<div class="csl-card">
						<h2><?php esc_html_e( 'QR code and statistics', 'clean-short-links' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Available once the short link is created.', 'clean-short-links' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			</div>
		</form>
		<?php if ( $link['id'] ) : ?>
			<p><a class="csl-delete submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_delete&id=' . $link['id'] ), 'clean_short_links_delete_' . $link['id'] ) ); ?>"><?php esc_html_e( 'Delete short link', 'clean-short-links' ); ?></a></p>
		<?php endif; ?>
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
		'fg'        => sanitize_hex_color( $d['fg'] ?? '' ) ? sanitize_hex_color( $d['fg'] ) : '#0e2440',
		'bg'        => sanitize_hex_color( $d['bg'] ?? '' ) ? sanitize_hex_color( $d['bg'] ) : '#ffffff',
		'style'     => in_array( $d['style'] ?? '', array( 'square', 'rounded', 'dots' ), true ) ? $d['style'] : 'square',
		'eyes'      => in_array( $d['eyes'] ?? '', array( 'square', 'rounded' ), true ) ? $d['eyes'] : 'square',
		'logo'      => absint( $d['logo'] ?? 0 ),
		'logo_size' => min( 28, max( 12, absint( $d['logo_size'] ?? 22 ) ) ),
		'upper'     => empty( $d['upper'] ) ? 0 : 1,
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
		wp_safe_redirect( admin_page_url( 'edit', array( 'id' => $result, 'csl-msg' => 'saved' ) ) );
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
		delete_link( $id, true );
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
	?>
	<div class="wrap csl-wrap">
		<h1><?php esc_html_e( 'Short link settings', 'clean-short-links' ); ?></h1>
		<?php action_notice(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'clean_short_links_settings' ); ?>
			<input type="hidden" name="action" value="clean_short_links_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Addresses', 'clean-short-links' ); ?></th>
					<td>
						<?php if ( $own_prefix ) : ?>
							<label><?php esc_html_e( 'Links', 'clean-short-links' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="prefix" value="<?php echo esc_attr( $s['prefix'] ); ?>" placeholder="<?php echo esc_attr( $n['prefix'] ); ?>" class="small-text"><code>/…</code></label><br>
							<label><?php esc_html_e( 'QR codes', 'clean-short-links' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="qr_prefix" value="<?php echo esc_attr( $s['qr_prefix'] ); ?>" placeholder="<?php echo esc_attr( $n['qr_prefix'] ); ?>" class="small-text"><code>/…</code></label>
							<p class="description"><?php esc_html_e( 'Changing a prefix changes all short links – printed QR codes then stop working. Best decide once.', 'clean-short-links' ); ?></p>
						<?php else : ?>
							<code><?php echo esc_html( home_url( '/' . $p . '/…' ) ); ?></code> · <code><?php echo esc_html( home_url( '/' . $q . '/…' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Set for the whole network.', 'clean-short-links' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Create automatically', 'clean-short-links' ); ?></th>
					<td>
						<?php foreach ( $types as $type ) : ?>
							<label><input type="checkbox" name="auto_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['auto_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'When something of these types is published for the first time it gets a short link that starts with the publication.', 'clean-short-links' ); ?></p>
						<p>
							<label for="csl-auto-kind"><?php esc_html_e( 'Kind of short address', 'clean-short-links' ); ?></label>
							<select name="auto_kind" id="csl-auto-kind">
								<option value="random" <?php selected( $s['auto_kind'], 'random' ); ?>><?php esc_html_e( 'Random', 'clean-short-links' ); ?></option>
								<option value="memorable" <?php selected( $s['auto_kind'], 'memorable' ); ?>><?php esc_html_e( 'Memorable', 'clean-short-links' ); ?></option>
								<option value="title" <?php selected( $s['auto_kind'], 'title' ); ?>><?php esc_html_e( 'From the title', 'clean-short-links' ); ?></option>
							</select>
						</p>
					</td>
				</tr>
				<?php if ( root_allowed() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Short form', 'clean-short-links' ); ?></th>
					<td><label><input type="checkbox" name="root_default" value="1" <?php checked( ! empty( $s['root_default'] ) ); ?>> <?php esc_html_e( 'New memorable and own short addresses also get the short form without prefix', 'clean-short-links' ); ?></label></td>
				</tr>
				<?php endif; ?>
				<?php if ( ! is_multisite() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Short form', 'clean-short-links' ); ?></th>
					<td><label><input type="checkbox" name="allow_root" value="1" <?php checked( ! empty( $n['allow_root'] ) ); ?>> <?php esc_html_e( 'Allow the short form without prefix (example.com/slug) – only used where the site has no content', 'clean-short-links' ); ?></label></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Statistics', 'clean-short-links' ); ?></th>
					<td><label><input type="checkbox" name="count_editors" value="1" <?php checked( ! empty( $s['count_editors'] ) ); ?>> <?php esc_html_e( 'Also count visits of logged-in authors and editors', 'clean-short-links' ); ?></label>
					<?php if ( ! is_multisite() ) : ?>
						<br><label for="csl-ret"><?php esc_html_e( 'Keep statistics', 'clean-short-links' ); ?></label>
						<select id="csl-ret" name="retention_days">
							<?php
							foreach ( array(
								90  => __( '3 months', 'clean-short-links' ),
								365 => __( '1 year', 'clean-short-links' ),
								730 => __( '2 years', 'clean-short-links' ),
								0   => __( 'Forever', 'clean-short-links' ),
							) as $d => $label ) :
								?>
								<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( (int) $n['retention_days'], $d ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Parameters', 'clean-short-links' ); ?></th>
					<td><label><input type="checkbox" name="pass_query" value="1" <?php checked( ! empty( $s['pass_query'] ) ); ?>> <?php esc_html_e( 'Pass parameters such as ?utm_source=… on to the target', 'clean-short-links' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Short links for existing content', 'clean-short-links' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'clean_short_links_bulk' ); ?>
			<input type="hidden" name="action" value="clean_short_links_bulk">
			<p>
				<?php esc_html_e( 'Create a short link for every published', 'clean-short-links' ); ?>
				<select name="type">
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( $type->name ); ?>"><?php echo esc_html( $type->labels->singular_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php esc_html_e( 'that has none yet.', 'clean-short-links' ); ?>
				<?php submit_button( __( 'Create', 'clean-short-links' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Import', 'clean-short-links' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'clean_short_links_import' ); ?>
			<input type="hidden" name="action" value="clean_short_links_import">
			<p class="description"><?php esc_html_e( 'One short link per line: short address ; target ; title – separated by semicolons or tabs. Targets on this site are recognised as posts and pages. Imported links also get the short form without prefix, so existing short links like example.com/abc keep working.', 'clean-short-links' ); ?></p>
			<textarea name="lines" rows="6" class="large-text code" placeholder="725 ; https://example.com/725-years/ ; 725 years"></textarea>
			<?php submit_button( __( 'Import', 'clean-short-links' ), 'secondary' ); ?>
		</form>
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
		);
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
