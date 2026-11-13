<?php
/**
 * Network admin: overview of all sites and the network-wide settings.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

add_action(
	'network_admin_menu',
	function () {
		add_menu_page( __( 'Short links', 'clean-short-links' ), __( 'Short links', 'clean-short-links' ), 'manage_network_options', 'clean-short-links-network', __NAMESPACE__ . '\\page_network', 'dashicons-admin-links', 26 );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( false !== strpos( (string) $hook, 'clean-short-links-network' ) ) {
			wp_enqueue_style( 'clean-short-links-admin', plugins_url( 'assets/admin.css', CLEAN_SHORT_LINKS_FILE ), array(), asset_ver( 'admin.css' ) );
			wp_enqueue_script( 'clean-short-links-admin', plugins_url( 'assets/admin.js', CLEAN_SHORT_LINKS_FILE ), array( 'wp-api-fetch' ), asset_ver( 'admin.js' ), true );
			wp_localize_script( 'clean-short-links-admin', 'CleanShortLinksAdmin', admin_js_data() );
		}
	}
);

/**
 * Overview and settings for the whole network.
 */
function page_network() {
	$n     = network_settings();
	$sites = get_sites(
		array(
			'number'   => 200,
			'deleted'  => 0,
			'archived' => 0,
		)
	);
	$saved = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a notice.
	$sum   = array(
		'links'  => 0,
		'visits' => 0,
		'used'   => 0,
	);
	$rows  = array();
	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		$ready = (int) get_option( 'clean_short_links_db' ) === DB_VERSION;
		$row   = array(
			'name'   => get_bloginfo( 'name' ),
			'url'    => home_url( '/' ),
			'admin'  => admin_url( 'admin.php?page=clean-short-links' ),
			'new'    => admin_url( 'admin.php?page=clean-short-links-edit' ),
			'links'  => $ready ? count_links() : 0,
			'visits' => $ready ? site_total( 30 ) : 0,
			'top'    => '',
			'prefix' => prefixes(),
		);
		if ( $ready ) {
			$top = top_links( 30, 1 );
			if ( $top ) {
				$l          = link_by_id( (int) array_key_first( $top ) );
				$row['top'] = $l ? ( '' !== $l['title'] ? $l['title'] : $l['slug'] ) : '';
			}
		}
		restore_current_blog();
		$sum['links']  += $row['links'];
		$sum['visits'] += $row['visits'];
		$sum['used']   += $row['links'] ? 1 : 0;
		$rows[]         = $row;
	}
	$retention = array(
		90  => __( '3 months', 'clean-short-links' ),
		365 => __( '1 year', 'clean-short-links' ),
		730 => __( '2 years', 'clean-short-links' ),
		0   => __( 'Forever', 'clean-short-links' ),
	);
	?>
	<div class="wrap csl-wrap csl-net">
		<h1 class="screen-reader-text"><?php esc_html_e( 'Short links in the network', 'clean-short-links' ); ?></h1>
		<?php
		hero(
			__( 'Short links in the network', 'clean-short-links' ),
			'',
			'',
			function () {
				?>
				<a class="button csl-btn" href="#csl-net-settings"><?php the_icon( 'gear' ); ?><?php esc_html_e( 'Settings', 'clean-short-links' ); ?></a>
				<?php
			},
			function () use ( $sum, $sites ) {
				echo '<p class="csl-help">' . esc_html__( 'Every site has its own short links and statistics. Here you see them all and set what applies everywhere.', 'clean-short-links' ) . '</p><div class="csl-stats">';
				stat( count( $sites ), __( 'Sites', 'clean-short-links' ) );
				stat( $sum['used'], __( 'Using short links', 'clean-short-links' ) );
				stat( $sum['links'], __( 'Short links', 'clean-short-links' ) );
				stat( $sum['visits'], __( 'Visits in 30 days', 'clean-short-links' ), 'is-blue' );
				echo '</div>';
			}
		);
		?>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'clean-short-links' ); ?></p></div>
		<?php endif; ?>

		<div class="csl-sites">
			<?php foreach ( $rows as $r ) : ?>
				<article class="csl-site <?php echo $r['links'] ? 'csl-site-used' : ''; ?>">
					<div class="csl-site-top">
						<span class="csl-badge <?php echo $r['links'] ? '' : 'csl-badge-expired'; ?>"><span class="csl-dot" aria-hidden="true"></span><?php /* translators: %d: number of short links */ echo esc_html( sprintf( _n( '%d short link', '%d short links', $r['links'], 'clean-short-links' ), $r['links'] ) ); ?></span>
						<a class="csl-icon-link" href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open', 'clean-short-links' ); ?>"><?php the_icon( 'external' ); ?><span class="screen-reader-text"><?php esc_html_e( 'Open', 'clean-short-links' ); ?></span></a>
					</div>
					<h3 class="csl-site-name"><?php echo esc_html( '' !== $r['name'] ? $r['name'] : preg_replace( '#^https?://|/$#', '', $r['url'] ) ); ?></h3>
					<span class="csl-site-host"><?php echo esc_html( preg_replace( '#^https?://#', '', $r['url'] ) . $r['prefix'][0] . '/… · ' . $r['prefix'][1] . '/…' ); ?></span>
					<p class="csl-site-shows"><?php the_icon( 'chart' ); ?><?php /* translators: %s: number of visits */ echo esc_html( sprintf( __( '%s visits in 30 days', 'clean-short-links' ), number_format_i18n( $r['visits'] ) ) ); ?></p>
					<?php if ( '' !== $r['top'] ) : ?>
						<p class="csl-site-shows"><?php the_icon( 'sparkle' ); ?><?php echo esc_html( $r['top'] ); ?></p>
					<?php endif; ?>
					<div class="csl-site-actions">
						<a class="button csl-btn" href="<?php echo esc_url( $r['admin'] ); ?>"><?php the_icon( 'link' ); ?><?php esc_html_e( 'Manage', 'clean-short-links' ); ?></a>
						<a class="csl-link-ext" href="<?php echo esc_url( $r['new'] ); ?>"><?php the_icon( 'plus' ); ?><?php esc_html_e( 'New', 'clean-short-links' ); ?></a>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=clean_short_links_network' ) ); ?>" class="csl-net-form">
			<?php wp_nonce_field( 'clean_short_links_network' ); ?>
			<?php card_open( 'csl-net-settings', 'network', __( 'Settings for the whole network', 'clean-short-links' ), __( 'QR codes lead through their own prefix, so the statistics can tell scans from clicks. Letters and digits only keep the compact QR mode.', 'clean-short-links' ) ); ?>
				<div class="csl-row">
					<label class="csl-field"><span><?php esc_html_e( 'Prefix for links', 'clean-short-links' ); ?></span>
						<span class="csl-slug-input"><span class="csl-slug-pre">example.com/</span><input type="text" name="prefix" value="<?php echo esc_attr( $n['prefix'] ); ?>" class="csl-short" required><span class="csl-slug-pre">/…</span></span></label>
					<label class="csl-field"><span><?php esc_html_e( 'Prefix for QR codes', 'clean-short-links' ); ?></span>
						<span class="csl-slug-input"><span class="csl-slug-pre">example.com/</span><input type="text" name="qr_prefix" value="<?php echo esc_attr( $n['qr_prefix'] ); ?>" class="csl-short" required><span class="csl-slug-pre">/…</span></span></label>
				</div>
				<div class="csl-sub"><?php toggle( 'site_prefix', ! empty( $n['site_prefix'] ), __( 'Sites may choose their own prefixes', 'clean-short-links' ) ); ?></div>
				<div class="csl-sub"><?php toggle( 'allow_root', ! empty( $n['allow_root'] ), __( 'Allow the short form without prefix (example.com/slug) – only used where the site has no content', 'clean-short-links' ) ); ?></div>
				<div class="csl-sub">
					<span class="csl-field-label"><?php esc_html_e( 'Keep statistics', 'clean-short-links' ); ?></span>
					<span class="csl-segment">
						<?php foreach ( $retention as $d => $label ) : ?>
							<label><input type="radio" name="retention_days" value="<?php echo esc_attr( (string) $d ); ?>" <?php checked( (int) $n['retention_days'], $d ); ?>><span><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
					</span>
					<span class="csl-help"><?php esc_html_e( 'Only daily totals are stored – no IP addresses, no cookies.', 'clean-short-links' ); ?></span>
				</div>
				<div class="csl-sub">
					<label class="csl-field" for="csl-n-res"><span><?php esc_html_e( 'Reserved words', 'clean-short-links' ); ?></span></label>
					<textarea id="csl-n-res" name="reserved" rows="3" class="csl-wide code"><?php echo esc_textarea( $n['reserved'] ); ?></textarea>
					<span class="csl-help"><?php esc_html_e( 'Never used as a short form on any site, separated by spaces or commas. WordPress’ own paths like wp-admin, login and feed are always reserved.', 'clean-short-links' ); ?></span>
				</div>
			<?php card_close(); ?>
			<div class="csl-savebar"><?php submit_button( __( 'Save settings', 'clean-short-links' ), 'primary', 'submit', false ); ?></div>
		</form>
	</div>
	<?php
}

add_action(
	'network_admin_edit_clean_short_links_network',
	function () {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_network' );
		$p      = wp_unslash( $_POST );
		$prefix = clean_prefix( $p['prefix'] ?? '' );
		$qr     = clean_prefix( $p['qr_prefix'] ?? '' );
		$d      = network_defaults();
		$new    = array(
			'prefix'         => '' !== $prefix ? $prefix : $d['prefix'],
			'qr_prefix'      => '' !== $qr ? $qr : $d['qr_prefix'],
			'site_prefix'    => empty( $p['site_prefix'] ) ? 0 : 1,
			'allow_root'     => empty( $p['allow_root'] ) ? 0 : 1,
			'retention_days' => in_array( (int) ( $p['retention_days'] ?? 730 ), array( 0, 90, 365, 730 ), true ) ? (int) $p['retention_days'] : 730,
			'reserved'       => sanitize_textarea_field( $p['reserved'] ?? '' ),
		);
		if ( $new['prefix'] === $new['qr_prefix'] ) {
			$new['qr_prefix'] = 's' === $new['prefix'] ? 'q' : $d['qr_prefix'];
		}
		update_site_option( 'clean_short_links_network', $new );
		wp_safe_redirect( network_admin_url( 'admin.php?page=clean-short-links-network&updated=1' ) );
		exit;
	}
);
