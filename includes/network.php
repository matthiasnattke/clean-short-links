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
			wp_enqueue_style( 'clean-short-links-admin', plugins_url( 'assets/admin.css', CLEAN_SHORT_LINKS_FILE ), array(), CLEAN_SHORT_LINKS_VERSION );
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
	);
	$rows  = array();
	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		$ready = (int) get_option( 'clean_short_links_db' ) === DB_VERSION;
		$row   = array(
			'name'   => get_bloginfo( 'name' ),
			'url'    => home_url( '/' ),
			'admin'  => admin_url( 'admin.php?page=clean-short-links' ),
			'links'  => $ready ? count_links() : 0,
			'visits' => $ready ? site_total( 30 ) : 0,
			'top'    => '',
			'prefix' => implode( ' · ', prefixes() ),
		);
		if ( $ready ) {
			$top = top_links( 30, 1 );
			if ( $top ) {
				$l          = link_by_id( (int) array_key_first( $top ) );
				$row['top'] = $l ? ( '' !== $l['title'] ? $l['title'] : $l['slug'] ) . ' (' . number_format_i18n( reset( $top ) ) . ')' : '';
			}
		}
		restore_current_blog();
		$sum['links']  += $row['links'];
		$sum['visits'] += $row['visits'];
		$rows[]         = $row;
	}
	?>
	<div class="wrap csl-wrap">
		<h1><?php esc_html_e( 'Short links in the network', 'clean-short-links' ); ?></h1>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'clean-short-links' ); ?></p></div>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Every site has its own short links and statistics. Here you see them all and set what applies everywhere.', 'clean-short-links' ); ?></p>

		<table class="widefat striped csl-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'clean-short-links' ); ?></th>
					<th><?php esc_html_e( 'Prefixes', 'clean-short-links' ); ?></th>
					<th class="csl-num"><?php esc_html_e( 'Short links', 'clean-short-links' ); ?></th>
					<th class="csl-num"><?php esc_html_e( 'Visits (30 days)', 'clean-short-links' ); ?></th>
					<th><?php esc_html_e( 'Most visited', 'clean-short-links' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $r['admin'] ); ?>"><?php echo esc_html( $r['name'] ); ?></a></strong><br><small><?php echo esc_html( preg_replace( '#^https?://#', '', $r['url'] ) ); ?></small></td>
						<td><code><?php echo esc_html( $r['prefix'] ); ?></code></td>
						<td class="csl-num"><?php echo esc_html( number_format_i18n( $r['links'] ) ); ?></td>
						<td class="csl-num"><?php echo esc_html( number_format_i18n( $r['visits'] ) ); ?></td>
						<td><?php echo esc_html( $r['top'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="2"><?php esc_html_e( 'All sites', 'clean-short-links' ); ?></th>
					<th class="csl-num"><?php echo esc_html( number_format_i18n( $sum['links'] ) ); ?></th>
					<th class="csl-num"><?php echo esc_html( number_format_i18n( $sum['visits'] ) ); ?></th>
					<th></th>
				</tr>
			</tfoot>
		</table>

		<h2><?php esc_html_e( 'Settings for the whole network', 'clean-short-links' ); ?></h2>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=clean_short_links_network' ) ); ?>">
			<?php wp_nonce_field( 'clean_short_links_network' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="csl-n-prefix"><?php esc_html_e( 'Prefix for links', 'clean-short-links' ); ?></label></th>
					<td><code>example.com/</code><input type="text" id="csl-n-prefix" name="prefix" value="<?php echo esc_attr( $n['prefix'] ); ?>" class="small-text" required><code>/…</code></td>
				</tr>
				<tr>
					<th scope="row"><label for="csl-n-qr"><?php esc_html_e( 'Prefix for QR codes', 'clean-short-links' ); ?></label></th>
					<td><code>example.com/</code><input type="text" id="csl-n-qr" name="qr_prefix" value="<?php echo esc_attr( $n['qr_prefix'] ); ?>" class="small-text" required><code>/…</code>
						<p class="description"><?php esc_html_e( 'QR codes lead through their own prefix, so the statistics can tell scans from clicks. Letters and digits only keep the compact QR mode.', 'clean-short-links' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sites', 'clean-short-links' ); ?></th>
					<td>
						<label><input type="checkbox" name="site_prefix" value="1" <?php checked( ! empty( $n['site_prefix'] ) ); ?>> <?php esc_html_e( 'Sites may choose their own prefixes', 'clean-short-links' ); ?></label><br>
						<label><input type="checkbox" name="allow_root" value="1" <?php checked( ! empty( $n['allow_root'] ) ); ?>> <?php esc_html_e( 'Allow the short form without prefix (example.com/slug) – only used where the site has no content', 'clean-short-links' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csl-n-ret"><?php esc_html_e( 'Keep statistics', 'clean-short-links' ); ?></label></th>
					<td>
						<select id="csl-n-ret" name="retention_days">
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
						<p class="description"><?php esc_html_e( 'Only daily totals are stored – no IP addresses, no cookies.', 'clean-short-links' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="csl-n-res"><?php esc_html_e( 'Reserved words', 'clean-short-links' ); ?></label></th>
					<td><textarea id="csl-n-res" name="reserved" rows="3" class="large-text code"><?php echo esc_textarea( $n['reserved'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Never used as a short form on any site, separated by spaces or commas. WordPress’ own paths like wp-admin, login and feed are always reserved.', 'clean-short-links' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
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
