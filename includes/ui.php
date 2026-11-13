<?php
/**
 * Building blocks of the admin screens – the same design language as Clean Maintenance:
 * hero, side navigation, cards, tiles, switches and a sticky save bar.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * A small inline icon.
 *
 * @param string $name Icon name.
 * @return string SVG markup (static, no user data).
 */
function icon( $name ) {
	$paths = array(
		'logo'     => '<path d="M5 10h11v3.5a5.5 5.5 0 0 1-11 0z"/><path d="M16 11h1.5a2.5 2.5 0 0 1 0 5H16"/><path d="M8.6 13.2h-.8a1.2 1.2 0 0 0 0 2.4h.8M12.4 13.2h.8a1.2 1.2 0 0 1 0 2.4h-.8M8.8 14.4h3.4"/><path d="M7.5 7.5c0-2 1.6-3.5 3.6-3.5H14M12.8 2.6 14.4 4l-1.6 1.4"/><path d="M4 21h14"/>',
		'link'     => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
		'target'   => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
		'tag'      => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
		'shuffle'  => '<path d="M3 7h3.5c3 0 4 10 7 10H21M3 17h3.5c1.2 0 2-1.4 2.7-3M13.8 10c.7-1.6 1.5-3 2.7-3H21"/><path d="m18 4 3 3-3 3M18 14l3 3-3 3"/>',
		'sparkle'  => '<path d="M12 3c.7 4.3 2.7 6.3 7 7-4.3.7-6.3 2.7-7 7-.7-4.3-2.7-6.3-7-7 4.3-.7 6.3-2.7 7-7z"/><path d="M19 15.5c.3 1.6 1 2.2 2.5 2.5-1.6.3-2.2 1-2.5 2.5-.3-1.6-1-2.2-2.5-2.5 1.6-.3 2.2-1 2.5-2.5z"/>',
		'type'     => '<path d="M5 6V4h14v2M12 4v16M9 20h6"/>',
		'pencil'   => '<path d="M15 4.5 19.5 9 9 19.5H4.5V15z"/><path d="m13 6.5 4.5 4.5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'bolt'     => '<path d="M13 3 5 13.5h6L10 21l8-10.5h-6z"/>',
		'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/>',
		'page'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
		'split'    => '<path d="M4 12h6l4-6h6M14 18h6M10 12l4 6"/><path d="m18 4 2 2-2 2M18 16l2 2-2 2"/>',
		'qr'       => '<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.5"/><rect x="14" y="3.5" width="6.5" height="6.5" rx="1.5"/><rect x="3.5" y="14" width="6.5" height="6.5" rx="1.5"/><path d="M14 14h2.5v2.5H14zM18.5 18.5H20.5V20.5M14 20h2M20 14v2"/>',
		'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
		'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 6.5l2.6 1.5M17.2 16l2.6 1.5M4.2 17.5 6.8 16M17.2 8l2.6-1.5"/><circle cx="12" cy="12" r="7"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
		'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'trash'    => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
		'pause'    => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
		'import'   => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
		'network'  => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
		'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
		'shield'   => '<path d="M12 3 4.5 6v6c0 4.4 3.2 7.9 7.5 9 4.3-1.1 7.5-4.6 7.5-9V6z"/><path d="m9 12 2 2 4-4"/>',
		'download' => '<path d="M12 4v11M7 10l5 5 5-5"/><path d="M5 20h14"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="csl-ico" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

/**
 * Prints an icon.
 *
 * @param string $name Icon name.
 */
function the_icon( $name ) {
	echo icon( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
}

/**
 * The header of a screen.
 *
 * @param string   $title   Title.
 * @param string   $pill    Text of the state pill ('' for none).
 * @param string   $state   State class of the pill.
 * @param callable $actions Prints the buttons on the right.
 * @param callable $extra   Prints something below the title (stats).
 */
function hero( $title, $pill = '', $state = '', $actions = null, $extra = null ) {
	?>
	<header class="csl-hero">
		<span class="csl-hero-icon"><?php the_icon( 'logo' ); ?></span>
		<div class="csl-hero-text">
			<p class="csl-eyebrow">Clean Short Links</p>
			<p class="csl-hero-title"><?php echo esc_html( $title ); ?></p>
			<?php if ( '' !== $pill ) : ?>
				<p class="csl-pill csl-pill-<?php echo esc_attr( $state ); ?>"><span class="csl-dot" aria-hidden="true"></span><?php echo esc_html( $pill ); ?></p>
			<?php endif; ?>
			<?php
			if ( $extra ) {
				$extra();
			}
			?>
		</div>
		<?php if ( $actions ) : ?>
			<div class="csl-hero-actions"><?php $actions(); ?></div>
		<?php endif; ?>
	</header>
	<?php
}

/**
 * The left navigation with the plugin info below.
 *
 * @param array $sections Anchor => label.
 */
function side_nav( array $sections ) {
	?>
	<nav class="csl-nav" aria-label="<?php esc_attr_e( 'Sections', 'clean-short-links' ); ?>">
		<?php foreach ( $sections as $anchor => $label ) : ?>
			<a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
		<div class="csl-about">
			<p><strong>Clean Short Links</strong><?php /* translators: %s: version number */ echo esc_html( sprintf( __( 'Version %s', 'clean-short-links' ), CLEAN_SHORT_LINKS_VERSION ) ); ?></p>
			<p><a href="<?php echo esc_url( admin_page_url( '' ) ); ?>"><?php esc_html_e( 'All short links', 'clean-short-links' ); ?></a> · <a href="<?php echo esc_url( admin_page_url( 'settings' ) ); ?>"><?php esc_html_e( 'Settings', 'clean-short-links' ); ?></a></p>
		</div>
	</nav>
	<?php
}

/**
 * The opening of a card.
 *
 * @param string $id    HTML ID.
 * @param string $icon  Icon name.
 * @param string $title Title.
 * @param string $lead  Lead text.
 * @param string $class Extra class.
 */
function card_open( $id, $icon, $title, $lead = '', $class = '' ) {
	?>
	<section class="csl-card <?php echo esc_attr( $class ); ?>" id="<?php echo esc_attr( $id ); ?>">
		<h2><?php the_icon( $icon ); ?><?php echo esc_html( $title ); ?></h2>
		<?php if ( '' !== $lead ) : ?>
			<p class="csl-lead"><?php echo esc_html( $lead ); ?></p>
		<?php endif; ?>
	<?php
}

/**
 * The end of a card.
 */
function card_close() {
	echo '</section>';
}

/**
 * A radio tile.
 *
 * @param string $name    Field name.
 * @param string $value   Value.
 * @param bool   $checked Selected.
 * @param string $icon    Icon name.
 * @param string $title   Title.
 * @param string $text    Description.
 * @param string $class   Extra class.
 */
function tile( $name, $value, $checked, $icon, $title, $text, $class = '' ) {
	?>
	<label class="csl-tile <?php echo esc_attr( $class ); ?>">
		<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?>>
		<span class="csl-tile-ico"><?php the_icon( $icon ); ?></span>
		<span class="csl-tile-title"><?php echo esc_html( $title ); ?></span>
		<span class="csl-tile-text"><?php echo esc_html( $text ); ?></span>
	</label>
	<?php
}

/**
 * A switch.
 *
 * @param string $name    Field name.
 * @param bool   $checked Current value.
 * @param string $label   Label.
 * @param string $help    Help text.
 * @param string $id      HTML ID of the checkbox.
 */
function toggle( $name, $checked, $label, $help = '', $id = '' ) {
	?>
	<label class="csl-switch">
		<input type="checkbox" <?php echo '' !== $name ? 'name="' . esc_attr( $name ) . '" value="1"' : ''; ?> <?php echo '' !== $id ? 'id="' . esc_attr( $id ) . '"' : ''; ?> <?php checked( $checked ); ?>>
		<span class="csl-switch-track" aria-hidden="true"></span>
		<span class="csl-switch-text"><strong><?php echo esc_html( $label ); ?></strong>
		<?php if ( '' !== $help ) : ?>
			<span class="csl-help"><?php echo esc_html( $help ); ?></span>
		<?php endif; ?>
		</span>
	</label>
	<?php
}

/**
 * A small number tile for the header.
 *
 * @param int|string $value Value.
 * @param string     $label Label.
 * @param string     $class Extra class.
 */
function stat( $value, $label, $class = '' ) {
	?>
	<p class="csl-stat <?php echo esc_attr( $class ); ?>"><strong><?php echo esc_html( is_numeric( $value ) ? number_format_i18n( (float) $value ) : $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></p>
	<?php
}
