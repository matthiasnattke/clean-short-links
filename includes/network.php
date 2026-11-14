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
	$q     = isset( $_GET['csl_q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['csl_q'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A search, changes nothing.
	$found = array();
	$sum   = array(
		'links'  => 0,
		'visits' => 0,
		'used'   => 0,
	);
	$rows  = array();
	foreach ( $sites as $site ) {
		switch_to_blog( (int) $site->blog_id );
		$ready = (int) get_option( 'clean_short_links_db' ) === DB_VERSION;
		if ( $ready && '' !== $q && count( $found ) < 100 ) {
			foreach ( list_links(
				array(
					'search'   => $q,
					'per_page' => 25,
				)
			)['items'] as $l ) {
				$found[] = array(
					'site'   => get_bloginfo( 'name' ),
					'title'  => '' !== $l['title'] ? $l['title'] : $l['slug'],
					'url'    => short_urls( $l )['link'],
					'target' => $l['target'],
					'label'  => target_label( $l['target'] ),
					'state'  => link_state( $l, time(), site_settings()['gone_days'] ),
					'edit'   => admin_url( 'admin.php?page=clean-short-links-edit&id=' . $l['id'] ),
				);
			}
		}
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

		<form method="get" action="<?php echo esc_url( network_admin_url( 'admin.php' ) ); ?>" class="csl-card csl-net-search" role="search">
			<input type="hidden" name="page" value="clean-short-links-network">
			<label class="csl-field" for="csl-net-q"><span><?php esc_html_e( 'Find short links on all sites', 'clean-short-links' ); ?></span></label>
			<div class="csl-net-search-row">
				<span class="csl-search"><?php the_icon( 'search' ); ?><input type="search" id="csl-net-q" name="csl_q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Title, short address or target', 'clean-short-links' ); ?>"></span>
				<?php submit_button( __( 'Search', 'clean-short-links' ), 'secondary', '', false ); ?>
			</div>
			<?php if ( '' !== $q ) : ?>
				<?php if ( ! $found ) : ?>
					<p class="csl-help"><?php esc_html_e( 'No short link found.', 'clean-short-links' ); ?></p>
				<?php else : ?>
					<ul class="csl-net-results">
						<?php foreach ( $found as $f ) : ?>
							<li>
								<span class="csl-dot csl-dot-<?php echo esc_attr( 'active' === $f['state'] ? 'ok' : 'warn' ); ?>" aria-hidden="true"></span>
								<span class="csl-net-result">
									<a href="<?php echo esc_url( $f['edit'] ); ?>"><strong><?php echo esc_html( $f['title'] ); ?></strong></a>
									<small><?php echo esc_html( $f['site'] ); ?> · <code><?php echo esc_html( preg_replace( '#^https?://#', '', $f['url'] ) ); ?></code> → <?php echo esc_html( $f['label'] ); ?></small>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</form>

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
				<div class="csl-sub"><?php toggle( 'referrers', ! empty( $n['referrers'] ), __( 'Store the host of referring websites', 'clean-short-links' ), __( 'Off: only date, source and device class are counted. Visitors who ask for privacy (Global Privacy Control, Do Not Track) are always counted without it.', 'clean-short-links' ) ); ?></div>
				<div class="csl-sub"><?php toggle( 'signup_home', ! empty( $n['signup_home'] ), __( 'Sign-up page leads to the home page', 'clean-short-links' ), __( 'Only while registration is switched off: instead of "Registration has been disabled" visitors see the home page – also those who open an unknown subdomain.', 'clean-short-links' ) ); ?></div>
				<div class="csl-sub">
					<label class="csl-field" for="csl-n-alias"><span><?php esc_html_e( 'Subdomains without a site', 'clean-short-links' ); ?></span></label>
					<textarea id="csl-n-alias" name="alias_hosts" rows="3" class="csl-wide" placeholder="go.example.com&#10;about.example.com /about" spellcheck="false"><?php echo esc_textarea( implode( "\n", array_map( fn( $h ) => trim( $h . ' ' . ( $n['alias_homes'][ $h ] ?? '' ) ), (array) $n['alias_hosts'] ) ) ); ?></textarea>
					<span class="csl-help"><?php esc_html_e( 'One per line, optionally followed by where its home page leads – a page of the main site (/about) or an address. Every site can offer its short links there – also without prefix (go.example.com/summer) –, no site needs to be created. Everything else on these subdomains leads to that page, otherwise to the main site. The domain must point to this server (e.g. by a wildcard DNS entry and certificate).', 'clean-short-links' ); ?></span>
					<?php if ( defined( 'CLEAN_SHORT_LINKS_SUNRISE' ) ) : ?>
						<span class="csl-help"><span class="csl-dot csl-dot-ok" aria-hidden="true"></span> <?php esc_html_e( 'The sunrise file is active.', 'clean-short-links' ); ?></span>
					<?php else : ?>
						<?php /* translators: 1: file in the plugin, 2: target file, 3: line for wp-config.php */ ?>
						<span class="csl-help csl-host-warn"><?php echo esc_html( sprintf( __( 'Not active yet: WordPress sends unknown subdomains to its sign-up page before any plugin is loaded. Copy %1$s to %2$s and add %3$s to wp-config.php.', 'clean-short-links' ), 'clean-short-links/sunrise.php', 'wp-content/sunrise.php', "define( 'SUNRISE', true );" ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="csl-sub">
					<span class="csl-field-label"><?php esc_html_e( 'Short domains', 'clean-short-links' ); ?></span>
					<span class="csl-help"><?php esc_html_e( 'Sites of the network with their own short domain (e.g. a short .de domain as an empty site). Their links can be offered from every site, the home page of a short domain leads to the main site.', 'clean-short-links' ); ?></span>
					<div class="csl-chips">
						<?php foreach ( $sites as $site ) : ?>
							<?php
							if ( '/' !== $site->path ) {
								continue;
							}
							?>
							<label class="csl-chip"><input type="checkbox" name="short_sites[]" value="<?php echo esc_attr( (string) $site->blog_id ); ?>" <?php checked( in_array( (int) $site->blog_id, array_map( 'intval', (array) $n['short_sites'] ), true ) ); ?>><span><?php echo esc_html( $site->domain ); ?></span></label>
						<?php endforeach; ?>
					</div>
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
		$alias  = clean_alias_hosts(
			(string) ( $p['alias_hosts'] ?? '' ),
			array_map(
				fn( $s ) => strtolower( $s->domain ),
				get_sites(
					array(
						'number' => 1000,
						'fields' => '',
					)
				)
			)
		);
		$new    = array(
			'prefix'         => '' !== $prefix ? $prefix : $d['prefix'],
			'qr_prefix'      => '' !== $qr ? $qr : $d['qr_prefix'],
			'site_prefix'    => empty( $p['site_prefix'] ) ? 0 : 1,
			'allow_root'     => empty( $p['allow_root'] ) ? 0 : 1,
			'retention_days' => in_array( (int) ( $p['retention_days'] ?? 730 ), array( 0, 90, 365, 730 ), true ) ? (int) $p['retention_days'] : 730,
			'reserved'       => sanitize_textarea_field( $p['reserved'] ?? '' ),
			'referrers'      => empty( $p['referrers'] ) ? 0 : 1,
			'short_sites'    => array_values( array_filter( array_map( 'absint', (array) ( $p['short_sites'] ?? array() ) ), 'get_site' ) ),
			'signup_home'    => empty( $p['signup_home'] ) ? 0 : 1,
			'alias_hosts'    => $alias['hosts'],
			'alias_homes'    => $alias['homes'],
		);
		// For sunrise.php, which runs before the network is loaded.
		update_site_option(
			'clean_short_links_alias_hosts',
			array(
				'hosts' => $new['alias_hosts'],
				'blog'  => get_main_site_id(),
			)
		);
		wp_cache_delete( 'alias_hosts', 'clean_short_links' );
		if ( $new['prefix'] === $new['qr_prefix'] ) {
			$new['qr_prefix'] = 's' === $new['prefix'] ? 'q' : $d['qr_prefix'];
		}
		update_site_option( 'clean_short_links_network', $new );
		wp_safe_redirect( network_admin_url( 'admin.php?page=clean-short-links-network&updated=1' ) );
		exit;
	}
);
