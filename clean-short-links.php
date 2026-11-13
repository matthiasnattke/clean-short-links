<?php
/**
 * Plugin Name:       Clean Short Links
 * Plugin URI:        https://couch-blog.de/projekte/clean-short-links/
 * Description:       Short links for posts, pages and any URL: random, memorable or your own, with start and end dates, targets that change by date and time, QR codes with your logo and privacy-friendly click statistics. In a multisite network every site has its own short links, and the network admin shows them all at a glance.
 * Version:           0.3.0
 * Network:           true
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Matthias Nattke
 * Author URI:        https://couch-blog.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       clean-short-links
 * Domain Path:       /languages
 *
 * @package CleanShortLinks
 */

defined( 'ABSPATH' ) || exit;

define( 'CLEAN_SHORT_LINKS_VERSION', '0.3.0' );
define( 'CLEAN_SHORT_LINKS_FILE', __FILE__ );

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/redirect.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/checker.php';
require_once __DIR__ . '/includes/social.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/ui.php';
	require_once __DIR__ . '/includes/admin.php';
	if ( is_multisite() ) {
		require_once __DIR__ . '/includes/network.php';
	}
}
