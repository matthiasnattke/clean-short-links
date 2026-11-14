<?php
/**
 * Minimal WordPress stand-ins so the plugin logic can be tested without a WordPress install.
 *
 * @package CleanShortLinks
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'OBJECT', 'OBJECT' );

$GLOBALS['csl_test_options'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function load_plugin_textdomain( ...$args ) {}
function plugin_basename( $file ) { return 'clean-short-links/' . basename( $file ); }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_attr__( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $n, $domain = 'default' ) { return 1 === $n ? $single : $plural; }
function get_option( $name, $default = false ) { return $GLOBALS['csl_test_options'][ $name ] ?? $default; }
function update_option( $name, $value ) { $GLOBALS['csl_test_options'][ $name ] = $value; return true; }
function get_site_option( $name, $default = false ) { return $default; }
function is_multisite() { return false; }
function wp_timezone() { return new DateTimeZone( 'Europe/Berlin' ); }
function wp_timezone_string() { return 'Europe/Berlin'; }
function wp_date( $format, $ts = null, $tz = null ) { return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz ? $tz : wp_timezone() )->format( $format ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function absint( $v ) { return abs( (int) $v ); }

define( 'CLEAN_SHORT_LINKS_FILE', dirname( __DIR__ ) . '/clean-short-links.php' );
require dirname( __DIR__ ) . '/includes/core.php';
require dirname( __DIR__ ) . '/includes/checker.php';
require dirname( __DIR__ ) . '/includes/social.php';
require dirname( __DIR__ ) . '/includes/maintenance.php';
require dirname( __DIR__ ) . '/includes/calendar.php';
