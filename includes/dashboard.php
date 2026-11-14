<?php
/**
 * Dashboard widget of a site: the state of its short links at a glance, and the links
 * with problems when there are any.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_dashboard_setup',
	function () {
		if ( ! current_user_can( capability() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'clean_short_links', __( 'Short links', 'clean-short-links' ), __NAMESPACE__ . '\\dashboard_widget' );
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'index.php' === $hook && current_user_can( capability() ) ) {
			wp_enqueue_style( 'clean-short-links-admin', plugins_url( 'assets/admin.css', CLEAN_SHORT_LINKS_FILE ), array(), asset_ver( 'admin.css' ) );
		}
	}
);

/**
 * Counts of links per state.
 *
 * @return array<string,int>
 */
function state_counts() {
	$out = array();
	foreach ( array( '', 'waiting', 'expired', 'exhausted', 'paused', 'broken' ) as $state ) {
		$out[ $state ] = list_links(
			array(
				'state'    => $state,
				'per_page' => 1,
			)
		)['total'];
	}
	return $out;
}

/**
 * The widget.
 */
function dashboard_widget() {
	$c        = state_counts();
	$problems = $c['broken'] ? list_links(
		array(
			'state'    => 'broken',
			'per_page' => 8,
		)
	)['items'] : array();
	$days     = (int) site_settings()['gone_days'];
	$check    = wp_nonce_url( admin_url( 'admin-post.php?action=clean_short_links_check&id=0' ), 'clean_short_links_check_0' );
	?>
	<div class="csl-widget">
		<div class="csl-stats">
			<?php
			stat( $c[''], __( 'Short links', 'clean-short-links' ) );
			stat( site_total( 30 ), __( 'Visits in 30 days', 'clean-short-links' ), 'is-blue' );
			stat( $c['broken'], __( 'Problems', 'clean-short-links' ), $c['broken'] ? 'is-warn' : '' );
			?>
		</div>
		<p class="csl-widget-states">
			<?php
			$states = array(
				'waiting'   => __( 'Starts later', 'clean-short-links' ),
				'expired'   => __( 'Expired', 'clean-short-links' ),
				'exhausted' => __( 'Limit reached', 'clean-short-links' ),
				'paused'    => __( 'Paused', 'clean-short-links' ),
			);
			foreach ( $states as $key => $label ) {
				if ( $c[ $key ] ) {
					printf( '<a class="csl-badge csl-badge-%1$s" href="%2$s">%3$s: %4$s</a> ', esc_attr( $key ), esc_url( admin_page_url( '', array( 'state' => $key ) ) ), esc_html( $label ), esc_html( number_format_i18n( $c[ $key ] ) ) );
				}
			}
			?>
		</p>
		<?php if ( $problems ) : ?>
			<ul class="csl-widget-list">
				<?php foreach ( $problems as $l ) : ?>
					<?php
					$st   = link_state( $l, time(), $days );
					$item = current( array_filter( $l['check']['items'], fn( $i ) => 'ok' !== ( $i['status'] ?? 'ok' ) ) );
					$why  = $item ? ( '' !== $item['message'] ? $item['message'] : ( $item['code'] ? 'HTTP ' . $item['code'] : '' ) ) : '';
					?>
					<li>
						<span class="csl-dot csl-dot-<?php echo esc_attr( 'broken' === $l['check']['status'] ? 'bad' : 'warn' ); ?>" aria-hidden="true"></span>
						<span class="csl-widget-link">
							<a href="<?php echo esc_url( admin_page_url( 'edit', array( 'id' => $l['id'] ) ) ); ?>"><?php echo esc_html( '' !== $l['title'] ? $l['title'] : $l['slug'] ); ?></a>
							<small><?php echo esc_html( trim( state_label( $st ) . ( '' !== $why ? ' · ' . $why : '' ) ) ); ?></small>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php elseif ( $c[''] ) : ?>
			<p class="csl-widget-ok"><?php the_icon( 'shield' ); ?><?php esc_html_e( 'All checked short links can be reached.', 'clean-short-links' ); ?></p>
		<?php endif; ?>
		<p class="csl-widget-foot">
			<a href="<?php echo esc_url( admin_page_url( '' ) ); ?>"><?php esc_html_e( 'All short links', 'clean-short-links' ); ?></a>
			<a href="<?php echo esc_url( admin_page_url( 'edit' ) ); ?>"><?php esc_html_e( 'New short link', 'clean-short-links' ); ?></a>
			<a href="<?php echo esc_url( $check ); ?>"><?php esc_html_e( 'Check links', 'clean-short-links' ); ?></a>
		</p>
	</div>
	<?php
}
