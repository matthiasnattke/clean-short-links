<?php
/**
 * Wallet cards in the link editor: the "Wallet card" section, saving it and issuing a card anew.
 * Only active with Clean Mobile Connect (see wallet_enabled()).
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Key of a card in the form: `link` for the short link itself, `place-<key>` for a location.
 *
 * @param string $place Location key, '' for the link.
 * @return string
 */
function wallet_field_key( string $place ): string {
	return '' === $place ? 'link' : 'place-' . $place;
}

/**
 * What the "Offer a Wallet card" switch means for the card.
 *
 * @param bool $offer    Switch is on.
 * @param bool $has_card A card exists already.
 * @return string|null 'active', 'off', or null when nothing is created (off, no card).
 */
function wallet_save_input( bool $offer, bool $has_card ): ?string {
	if ( $offer ) {
		return 'active';
	}
	return $has_card ? 'off' : null;
}

/**
 * The cards of a link in the form: the link itself and each location.
 *
 * @param array $link Link.
 * @param array $urls Short URLs.
 * @return array[] Entries with key, place, label, wallet address and live-data defaults.
 */
function wallet_entries( array $link, array $urls ): array {
	$post    = target_post_id( $link['target'] );
	$image   = $post ? (int) get_post_thumbnail_id( $post ) : 0;
	$entries = array();
	$places  = array( '' => __( 'The short link itself', 'clean-short-links' ) );
	foreach ( $urls['places'] as $key => $pl ) {
		/* translators: %s: name of a location */
		$places[ $key ] = sprintf( __( 'Location: %s', 'clean-short-links' ), $pl['name'] );
	}
	foreach ( $places as $place => $label ) {
		$key       = wallet_field_key( (string) $place );
		$wallet    = '' === $place ? (string) $urls['wallet'] : (string) $urls['places'][ $place ]['wallet'];
		$defaults  = wallet_live_data( array(), $link, (string) $place, $urls, $image );
		$entries[] = array(
			'key'      => $key,
			'place'    => (string) $place,
			'ref'      => wallet_ref( $link['id'], (string) $place ),
			'label'    => $label,
			'wallet'   => $wallet,
			'defaults' => $defaults + array(
				'guest_url'  => $wallet,
				'guest'      => true,
				'field_name' => 'clean_mobile_connect_wallet[' . $key . ']',
			),
		);
	}
	return $entries;
}

/**
 * The Wallet guest addresses of a link as choices for a code (QR designer, NFC tag): one
 * entry for the short link itself and one for each location whose card is offered.
 *
 * @param array         $urls    short_urls() of the link.
 * @param int           $link_id Link ID, 0 for none.
 * @param string        $for     qr or nfc (the entries are the same, the code decides how to use them).
 * @param callable|null $offered Receives a card reference, returns whether the card is offered; null asks Connect.
 * @return array[] Entries: label, address, address in capitals (when compact), location key ('' for the short link itself).
 */
function wallet_code_entries( array $urls, int $link_id, string $for, ?callable $offered = null ): array {
	unset( $for );
	if ( $link_id <= 0 ) {
		return array();
	}
	if ( null === $offered ) {
		if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) ) {
			return array();
		}
		$offered = static function ( $ref ) {
			$card = \clean_mobile_connect_wallet_card( WALLET_OWNER, $ref );
			return $card && 'active' === ( $card['status'] ?? '' );
		};
	}
	$entries = array();
	if ( '' !== (string) ( $urls['wallet'] ?? '' ) && $offered( wallet_ref( $link_id ) ) ) {
		$entries[] = array( __( 'Wallet card: the short link itself', 'clean-short-links' ), (string) $urls['wallet'], qr_uppercase( (string) $urls['wallet'] ), '' );
	}
	foreach ( (array) ( $urls['places'] ?? array() ) as $key => $pl ) {
		if ( '' !== (string) ( $pl['wallet'] ?? '' ) && $offered( wallet_ref( $link_id, (string) $key ) ) ) {
			/* translators: %s: name of a location */
			$entries[] = array( sprintf( __( 'Wallet card: %s', 'clean-short-links' ), $pl['name'] ), (string) $pl['wallet'], qr_uppercase( (string) $pl['wallet'] ), (string) $key );
		}
	}
	return $entries;
}

/**
 * Prints the card editor of Connect. Connect 0.2.2 and later takes options: what the link
 * fills in live (QR code and link) is shown as a note instead of a field. Older versions
 * only take three arguments.
 *
 * @param string $ref      Card reference.
 * @param array  $defaults Live data of the link.
 */
function wallet_editor_box( string $ref, array $defaults ): void {
	$args = array( WALLET_OWNER, $ref, $defaults );
	if ( wallet_editor_takes_options( wallet_connect_api() ) ) {
		$args[] = array( 'fixed' => array( 'qr', 'link' ) );
	}
	\clean_mobile_connect_wallet_editor_box( ...$args );
}

/**
 * Does Connect take the options argument (editor_box and editor_input)? From API 4 on.
 *
 * @param int|null $api Connect's API level.
 * @return bool
 */
function wallet_editor_takes_options( ?int $api ): bool {
	return wallet_continue_supported( $api );
}

/**
 * Card: Wallet card – offer the link as a card for Apple Wallet.
 *
 * @param array $link Link.
 * @param array $urls Short URLs.
 */
function wallet_card( array $link, array $urls ) {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) ) {
		return;
	}
	card_open( 'csl-sec-wallet', 'wallet', __( 'Wallet card', 'clean-short-links' ), __( 'Visitors add the short link to Apple Wallet as a card: it shows the title, an image and a QR code that opens the link. The card fills itself from this link: title, image, dates, colours and logo follow it on their own.', 'clean-short-links' ) );
	$available = function_exists( 'clean_mobile_connect_wallet_available' ) ? clean_mobile_connect_wallet_available() : array( 'ok' => true );
	$notice    = array( 'type' => '', 'message' => '' );
	if ( function_exists( 'CleanMobileConnect\\editor_notice' ) ) {
		$notice = \CleanMobileConnect\editor_notice( $available );
	} elseif ( empty( $available['ok'] ) ) {
		$notice = array( 'type' => 'error', 'message' => __( 'Wallet cards are not available yet. Please check the settings of Clean Mobile Connect.', 'clean-short-links' ) );
	}
	if ( '' !== (string) ( $notice['message'] ?? '' ) ) {
		printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', 'error' === $notice['type'] ? 'error' : 'warning', esc_html( $notice['message'] ) );
	}
	$entries = wallet_entries( $link, $urls );
	if ( count( $entries ) > 1 ) {
		?>
		<label class="csl-field csl-code-for"><span><?php esc_html_e( 'Code for', 'clean-short-links' ); ?></span>
			<select data-code-for="wallet">
				<?php foreach ( $entries as $e ) : ?>
					<option value="<?php echo esc_attr( $e['key'] ); ?>"><?php echo esc_html( $e['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}
	foreach ( $entries as $i => $e ) {
		$card = \clean_mobile_connect_wallet_card( WALLET_OWNER, $e['ref'] );
		$on   = $card && 'active' === ( $card['status'] ?? '' );
		$name = 'csl_wallet[' . $e['key'] . ']';
		?>
		<div class="csl-wallet-panel" data-wallet-panel="<?php echo esc_attr( $e['key'] ); ?>"<?php echo $i ? ' hidden' : ''; ?>>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[seen]" value="1">
			<label class="csl-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[offer]" value="1"<?php checked( $on ); ?>>
				<span class="csl-switch-track" aria-hidden="true"></span>
				<span class="csl-switch-text"><strong><?php esc_html_e( 'Offer a Wallet card', 'clean-short-links' ); ?></strong>
					<span class="csl-help"><?php esc_html_e( 'Switched off, cards on iPhones stay as they are and the Wallet address shows “not available”.', 'clean-short-links' ); ?></span></span>
			</label>
			<?php
			if ( $on && '' !== $e['wallet'] ) {
				?>
				<div class="csl-wallet-address">
					<span class="csl-field-label"><?php esc_html_e( 'Wallet address', 'clean-short-links' ); ?></span>
					<?php copy_field( $e['wallet'] ); ?>
					<div class="csl-wallet-qr" data-url="<?php echo esc_attr( $e['wallet'] ); ?>" data-upper="<?php echo esc_attr( qr_uppercase( $e['wallet'] ) ); ?>" data-print="<?php echo esc_attr( preg_replace( '#^https?://#', '', $e['wallet'] ) ); ?>"></div>
					<button type="button" class="button csl-btn csl-wallet-design" data-entry="<?php echo esc_attr( 'wallet:' . $e['place'] ); ?>"><?php the_icon( 'qr' ); ?><?php esc_html_e( 'Design QR code', 'clean-short-links' ); ?></button>
				</div>
				<?php
			}
			if ( $card ) {
				// Two steps without window.confirm(); the submit button belongs to the form printed after the editor form.
				?>
				<div class="csl-wallet-anew">
					<button type="button" class="button csl-btn csl-wallet-anew-start"><?php esc_html_e( 'Issue anew', 'clean-short-links' ); ?></button>
					<span class="csl-wallet-anew-confirm" role="alert" hidden>
						<strong><?php esc_html_e( 'Really issue anew?', 'clean-short-links' ); ?></strong>
						<span class="csl-help"><?php esc_html_e( 'Cards already handed out are ended and grey out on every iPhone. The Wallet address keeps working with a new card.', 'clean-short-links' ); ?></span>
						<button type="submit" form="<?php echo esc_attr( 'csl-wallet-anew-' . $e['key'] ); ?>" class="button button-primary csl-btn"><?php esc_html_e( 'Yes, issue anew', 'clean-short-links' ); ?></button>
						<button type="button" class="button csl-btn csl-wallet-anew-cancel"><?php esc_html_e( 'Cancel', 'clean-short-links' ); ?></button>
					</span>
				</div>
				<?php
			}
			if ( function_exists( 'clean_mobile_connect_wallet_editor_box' ) ) {
				?>
				<details class="csl-wallet-custom">
					<summary><?php esc_html_e( 'Customize card', 'clean-short-links' ); ?></summary>
					<p class="csl-help"><?php esc_html_e( 'Title, image, date, colours and logo come from this link. Anything you enter here replaces them.', 'clean-short-links' ); ?></p>
					<?php wallet_editor_box( $e['ref'], $e['defaults'] ); ?>
				</details>
				<?php
			}
			?>
		</div>
		<?php
	}
	card_close();
}

/**
 * QR section: "Wallet card first on Apple devices". Shown only while a card of the link or
 * of one of its locations is offered; without Connect the stored choice is kept as it is.
 *
 * @param array $link Link.
 * @param array $urls short_urls() of the link.
 */
function wallet_first_option( array $link, array $urls ): void {
	$on = ! empty( $link['meta']['wallet_first'] );
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) || ! empty( $link['parent_id'] ) ) {
		printf( '<input type="hidden" name="meta[wallet_first]" value="%s">', $on ? '1' : '0' );
		return;
	}
	$offered = (bool) wallet_code_entries( $urls, (int) $link['id'], 'qr' );
	?>
	<div class="csl-wallet-first"<?php echo $offered ? '' : ' hidden'; ?>>
		<?php toggle( 'meta[wallet_first]', $on, __( 'On iPhone, iPad and Mac, offer the Wallet card first', 'clean-short-links' ), __( 'Every scan on these devices shows the card page with a Continue button; others go straight to the target.', 'clean-short-links' ) ); ?>
	</div>
	<?php
}

/**
 * Save the cards posted with the link form.
 *
 * @param int   $link_id Saved link.
 * @param array $posted  Unslashed POST data.
 */
function wallet_save_posted( int $link_id, array $posted ): void {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) || ! function_exists( 'clean_mobile_connect_wallet_save_card' ) || ! function_exists( 'clean_mobile_connect_wallet_editor_input' ) ) {
		return;
	}
	$link = link_by_id( $link_id );
	if ( ! $link || ! empty( $link['parent_id'] ) || ! is_array( $posted['csl_wallet'] ?? null ) ) {
		return;
	}
	$boxes  = is_array( $posted['clean_mobile_connect_wallet'] ?? null ) ? $posted['clean_mobile_connect_wallet'] : array();
	$places = array_column( (array) $link['meta']['places'], 'key' );
	foreach ( $posted['csl_wallet'] as $key => $row ) {
		$key = (string) $key;
		if ( 'link' === $key ) {
			$place = '';
		} elseif ( 0 === strpos( $key, 'place-' ) && in_array( substr( $key, 6 ), $places, true ) ) {
			$place = substr( $key, 6 );
		} else {
			continue;
		}
		$ref    = wallet_ref( $link_id, $place );
		$had_card = null !== \clean_mobile_connect_wallet_card( WALLET_OWNER, $ref );
		$status   = wallet_save_input( ! empty( $row['offer'] ), $had_card );
		if ( null === $status ) {
			continue;
		}
		$input           = \clean_mobile_connect_wallet_editor_input( WALLET_OWNER, $ref, is_array( $boxes[ $key ] ?? null ) ? $boxes[ $key ] : array() );
		$input['status'] = $status;
		if ( ! $had_card && in_array( $input['style'] ?? 'generic', array( '', 'generic' ), true ) ) {
			$input['style'] = wallet_auto_style( $link );
		}
		\clean_mobile_connect_wallet_save_card( WALLET_OWNER, $ref, $input );
	}
}

/**
 * Is this a card of the link that can be issued anew?
 *
 * @param array|null $link  Link, null if it does not exist.
 * @param string     $place Location key, '' for the link itself.
 * @return bool
 */
function wallet_anew_valid( ?array $link, string $place ): bool {
	if ( ! $link || ! empty( $link['parent_id'] ) ) {
		return false;
	}
	return '' === $place || in_array( $place, array_column( (array) ( $link['meta']['places'] ?? array() ), 'key' ), true );
}

/**
 * The small forms of "Issue anew" – printed after the editor form, because forms cannot be nested.
 * The confirm buttons in the Wallet section point at them with the form attribute.
 *
 * @param array $link Link.
 * @param array $urls Short URLs.
 */
function wallet_anew_forms( array $link, array $urls ) {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) || ! function_exists( 'clean_mobile_connect_wallet_issue_anew' ) ) {
		return;
	}
	foreach ( wallet_entries( $link, $urls ) as $e ) {
		if ( ! \clean_mobile_connect_wallet_card( WALLET_OWNER, $e['ref'] ) ) {
			continue;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="<?php echo esc_attr( 'csl-wallet-anew-' . $e['key'] ); ?>">
			<input type="hidden" name="action" value="clean_short_links_wallet_anew">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'clean_short_links_wallet_anew_' . (int) $link['id'] ) ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $link['id'] ); ?>">
			<input type="hidden" name="place" value="<?php echo esc_attr( $e['place'] ); ?>">
		</form>
		<?php
	}
}

add_action(
	'admin_post_clean_short_links_wallet_anew',
	function () {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ), '', array( 'response' => 405 ) );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The ID is part of the nonce action checked right below.
		if ( ! current_user_can( capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'clean-short-links' ) );
		}
		check_admin_referer( 'clean_short_links_wallet_anew_' . $id );
		$place = isset( $_POST['place'] ) ? sanitize_key( wp_unslash( $_POST['place'] ) ) : '';
		$args  = array( 'id' => $id );
		if ( wallet_enabled() && function_exists( 'clean_mobile_connect_wallet_issue_anew' ) && wallet_anew_valid( link_by_id( $id ), $place ) ) {
			$args['csl-msg'] = \clean_mobile_connect_wallet_issue_anew( WALLET_OWNER, wallet_ref( $id, $place ) ) ? 'wallet-anew' : 'wallet-none';
		} else {
			$args['csl-error'] = __( 'A new Wallet card could not be issued: the short link or location does not exist or Wallet cards are not available.', 'clean-short-links' );
		}
		wp_safe_redirect( admin_page_url( 'edit', $args ) . '#csl-sec-wallet' );
		exit;
	}
);

/**
 * Does the link have a card that is offered (the link itself or one of its locations)?
 *
 * @param array $link Link.
 * @return bool
 */
function wallet_link_offered( array $link ): bool {
	if ( ! wallet_enabled() || ! function_exists( 'clean_mobile_connect_wallet_card' ) || ! empty( $link['parent_id'] ) ) {
		return false;
	}
	foreach ( wallet_refs_of( $link ) as $ref ) {
		$card = \clean_mobile_connect_wallet_card( WALLET_OWNER, $ref );
		if ( $card && 'active' === ( $card['status'] ?? '' ) ) {
			return true;
		}
	}
	return false;
}
