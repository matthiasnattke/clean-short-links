<?php
/**
 * Clean Short Links – tests without dependencies.
 *
 *   php tests/run.php
 *
 * Exit code 0 when all tests pass, 1 otherwise.
 *
 * @package CleanShortLinks
 */

// phpcs:disable

namespace CleanShortLinks;

require __DIR__ . '/bootstrap.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = array();

function test( $name, callable $fn ) {
	$GLOBALS['csl_test_options'] = array();
	try {
		$fn();
		++$GLOBALS['pass'];
		echo "  ✓ {$name}\n";
	} catch ( \Throwable $e ) {
		$GLOBALS['fail'][] = $name;
		echo "  ✗ {$name}\n    " . $e->getMessage() . "\n";
	}
}

function same( $expected, $actual, $label = '' ) {
	if ( $expected !== $actual ) {
		throw new \Exception( ( $label ? $label . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function local( $s ) {
	return new \DateTimeImmutable( $s, wp_timezone() );
}

/* Resolving with fake content: post:1 is published, post:2 a draft, post:3 scheduled for later. */
function url_of( $t ) {
	return array(
		'post:1' => 'https://example.com/hello/',
		'post:4' => 'https://example.com/ended/',
	)[ $t ] ?? ( 0 === strpos( $t, 'http' ) ? $t : null );
}
function published( $t ) {
	return array(
		'post:1' => local( '2026-01-01 10:00' )->getTimestamp(),
		'post:3' => local( '2026-12-24 18:00' )->getTimestamp(),
	)[ $t ] ?? null;
}
function link( array $over = array() ) {
	return array_merge(
		array(
			'id'         => 1,
			'target'     => 'post:1',
			'status'     => 'active',
			'start_mode' => '',
			'starts_at'  => null,
			'ends_at'    => null,
			'fallback'   => '',
			'rules'      => array(),
		),
		$over
	);
}
function go( array $link, $now ) {
	return resolve( $link, local( $now ), __NAMESPACE__ . '\\url_of', __NAMESPACE__ . '\\published' );
}

echo "Short addresses\n";

test( 'Normalizing: lowercase, umlauts, spaces, junk', function () {
	same( 'blaue-couch', normalize_slug( '  Blaue Couch ' ) );
	same( 'groesse-strasse', normalize_slug( 'Größe_Straße' ) );
	same( '725-jahre-peitz', normalize_slug( '725 Jahre Peitz!' ) );
	same( 'a-b', normalize_slug( '--a///b--' ) );
} );

test( 'Valid short addresses', function () {
	same( true, is_valid_slug( 'a' ) );
	same( true, is_valid_slug( '725peitz' ) );
	same( true, is_valid_slug( 'blaue-couch' ) );
	same( false, is_valid_slug( '' ) );
	same( false, is_valid_slug( '-abc' ) );
	same( false, is_valid_slug( 'abc-' ) );
	same( false, is_valid_slug( 'Ab' ) );
	same( false, is_valid_slug( str_repeat( 'a', 65 ) ) );
} );

test( 'Random short addresses avoid confusable characters', function () {
	for ( $i = 0; $i < 200; $i++ ) {
		$s = random_slug( 6 );
		same( 6, strlen( $s ) );
		same( 0, preg_match( '/[01ilo]/', $s ), $s );
	}
} );

test( 'Memorable short addresses are two plain words', function () {
	same( 1, preg_match( '/^[a-z]+-[a-z]+$/', memorable_slug( 'de_DE' ) ) );
	same( 1, preg_match( '/^[a-z]+-[a-z]+$/', memorable_slug( 'en_US' ) ) );
	list( $a, $n ) = word_lists( 'de_DE' );
	foreach ( array_merge( $a, $n ) as $w ) {
		same( 1, preg_match( '/^[a-z]+$/', $w ), $w );
	}
} );

test( 'Short address from a title: first words, at most 30 characters', function () {
	same( '725-jahre-peitz', title_slug( '725 Jahre Peitz' ) );
	same( 'das-grosse-sommerfest-im', title_slug( 'Das große Sommerfest im Park am Wasser' ) );
	same( true, strlen( title_slug( 'Donaudampfschifffahrtsgesellschaftskapitaensmuetze' ) ) <= 30 );
} );

test( 'Request paths', function () {
	same( array( SOURCE_LINK, 'blaue-couch' ), parse_path( '/s/blaue-couch', '/', 's', 'q' ) );
	same( array( SOURCE_LINK, 'blaue-couch' ), parse_path( '/S/Blaue-Couch/', '/', 's', 'q' ) );
	same( array( SOURCE_QR, '725' ), parse_path( '/Q/725', '/', 's', 'q' ) );
	same( array( SOURCE_ROOT, '725peitz' ), parse_path( '/725peitz/', '/', 's', 'q' ) );
	same( array( SOURCE_LINK, 'abc' ), parse_path( '/blog/s/abc', '/blog/', 's', 'q' ) );
	same( null, parse_path( '/other/s/abc', '/blog/', 's', 'q' ) );
	same( null, parse_path( '/2026/05/hello', '/', 's', 'q' ) );
	same( null, parse_path( '/', '/', 's', 'q' ) );
	same( null, parse_path( '/x/abc', '/', 's', 'q' ) );
} );

test( 'Prefixes are cleaned', function () {
	same( 's', clean_prefix( '/s/' ) );
	same( 'go', clean_prefix( 'GO' ) );
	same( '', clean_prefix( 'a/b' ) );
	same( '', clean_prefix( '' ) );
} );

echo "\nTargets and rules\n";

test( 'Targets', function () {
	same( 'post:12', clean_target( 'post:12' ) );
	same( '', clean_target( 'post:0' ) );
	same( 'https://1drv.ms/f/c/abc?e=x', clean_target( 'https://1drv.ms/f/c/abc?e=x' ) );
	same( '', clean_target( 'javascript:alert(1)' ) );
	same( '', clean_target( 'ftp://example.com' ) );
	same( 12, target_post_id( 'post:12' ) );
	same( 0, target_post_id( 'https://example.com' ) );
} );

test( 'Rules are cleaned: no target, no rule; all seven days means every day', function () {
	$r = clean_rules(
		array(
			array( 'target' => '' ),
			array(
				'target'    => 'post:2',
				'from'      => '2026-07-01T10:00',
				'days'      => array( '1', '2', '3', '4', '5', '6', '7' ),
				'time_from' => '25:00',
				'time_to'   => '18:30',
			),
		)
	);
	same( 1, count( $r ) );
	same( '2026-07-01 10:00', $r[0]['from'] );
	same( array(), $r[0]['days'] );
	same( '', $r[0]['time_from'] );
	same( '18:30', $r[0]['time_to'] );
} );

test( 'Rule: date range, "until" is exclusive', function () {
	$rule = array( 'from' => '2026-07-10 00:00', 'to' => '2026-07-13 00:00' );
	same( false, rule_matches( $rule, local( '2026-07-09 23:59' ) ) );
	same( true, rule_matches( $rule, local( '2026-07-10 00:00' ) ) );
	same( true, rule_matches( $rule, local( '2026-07-12 23:59' ) ) );
	same( false, rule_matches( $rule, local( '2026-07-13 00:00' ) ) );
} );

test( 'Rule: weekdays and daily times', function () {
	$rule = array( 'days' => array( 6, 7 ), 'time_from' => '18:00', 'time_to' => '23:00' );
	same( true, rule_matches( $rule, local( '2026-11-14 19:00' ) ), 'Saturday evening' );
	same( false, rule_matches( $rule, local( '2026-11-14 12:00' ) ), 'Saturday noon' );
	same( false, rule_matches( $rule, local( '2026-11-13 19:00' ) ), 'Friday evening' );
} );

test( 'Rule: times over midnight', function () {
	$rule = array( 'time_from' => '22:00', 'time_to' => '02:00' );
	same( true, rule_matches( $rule, local( '2026-11-14 23:30' ) ) );
	same( true, rule_matches( $rule, local( '2026-11-15 01:59' ) ) );
	same( false, rule_matches( $rule, local( '2026-11-15 02:00' ) ) );
	same( false, rule_matches( $rule, local( '2026-11-15 12:00' ) ) );
} );

echo "\nResolving\n";

test( 'Plain link leads to its target', function () {
	same( array( 'status' => 302, 'url' => 'https://example.com/hello/', 'state' => 'default' ), go( link(), '2026-06-01 12:00' ) );
} );

test( 'Paused link: 404', function () {
	same( 404, go( link( array( 'status' => 'paused' ) ), '2026-06-01 12:00' )['status'] );
} );

test( 'Before the start: 404 or fallback', function () {
	$l = link( array( 'start_mode' => 'date', 'starts_at' => local( '2026-07-01 00:00' )->getTimestamp() ) );
	same( 404, go( $l, '2026-06-30 23:59' )['status'] );
	same( 302, go( $l, '2026-07-01 00:00' )['status'] );
	$l['fallback'] = 'https://example.com/soon/';
	same( 'https://example.com/soon/', go( $l, '2026-06-30 23:59' )['url'] );
} );

test( 'After expiry: 410 or fallback', function () {
	$l = link( array( 'ends_at' => local( '2026-08-01 00:00' )->getTimestamp() ) );
	same( 302, go( $l, '2026-07-31 23:59' )['status'] );
	same( 410, go( $l, '2026-08-01 00:00' )['status'] );
	same( 'expired', go( $l, '2026-08-01 00:00' )['state'] );
	$l['fallback'] = 'post:4';
	same( 'https://example.com/ended/', go( $l, '2026-09-01 00:00' )['url'] );
} );

test( 'Start with publication: waits for scheduled content', function () {
	$l = link( array( 'target' => 'post:3', 'start_mode' => 'publish' ) );
	same( 404, go( $l, '2026-12-24 17:59' )['status'] );
	$l = link( array( 'target' => 'post:2', 'start_mode' => 'publish' ) );
	same( 'waiting', go( $l, '2026-12-24 17:59' )['state'], 'draft' );
} );

test( 'Draft or deleted target: 404', function () {
	same( 404, go( link( array( 'target' => 'post:2' ) ), '2026-06-01 12:00' )['status'] );
} );

test( 'Rules: the first matching rule wins, otherwise the default', function () {
	$l = link(
		array(
			'rules' => array(
				array( 'from' => '2026-07-10 00:00', 'to' => '2026-07-13 00:00', 'time_from' => '18:00', 'target' => 'https://example.com/stage/' ),
				array( 'from' => '2026-07-10 00:00', 'to' => '2026-07-13 00:00', 'target' => 'https://example.com/programme/' ),
				array( 'from' => '2026-07-13 00:00', 'target' => 'https://example.com/review/' ),
			),
		)
	);
	same( 'https://example.com/hello/', go( $l, '2026-07-01 12:00' )['url'] );
	same( 'https://example.com/programme/', go( $l, '2026-07-11 12:00' )['url'] );
	same( 'https://example.com/stage/', go( $l, '2026-07-11 20:00' )['url'] );
	same( 'rule-1', go( $l, '2026-07-11 20:00' )['state'] );
	same( 'https://example.com/review/', go( $l, '2026-08-01 12:00' )['url'] );
} );

test( 'List state', function () {
	$now = local( '2026-06-01 12:00' )->getTimestamp();
	same( 'active', link_state( link(), $now ) );
	same( 'paused', link_state( link( array( 'status' => 'paused' ) ), $now ) );
	same( 'waiting', link_state( link( array( 'starts_at' => $now + 60 ) ), $now ) );
	same( 'expired', link_state( link( array( 'ends_at' => $now ) ), $now ) );
} );

echo "\nVisits\n";

test( 'Bots, previews and tools are not counted', function () {
	same( true, is_bot( '' ) );
	same( true, is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) );
	same( true, is_bot( 'WhatsApp/2.23.20.0' ) );
	same( true, is_bot( 'curl/8.4.0' ) );
	same( false, is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' ) );
} );

test( 'Device classes', function () {
	same( DEVICE_MOBILE, device_class( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148' ) );
	same( DEVICE_MOBILE, device_class( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari/537.36' ) );
	same( DEVICE_TABLET, device_class( 'Mozilla/5.0 (Linux; Android 14; SM-X710) Safari/537.36' ) );
	same( DEVICE_TABLET, device_class( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)' ) );
	same( DEVICE_DESKTOP, device_class( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605.1.15' ) );
} );

test( 'Only the referring host is kept', function () {
	same( 'instagram.com', referrer_host( 'https://www.instagram.com/p/xyz/?igshid=123' ) );
	same( '', referrer_host( '' ) );
} );

test( 'Local times of the site', function () {
	$ts = local_to_timestamp( '2026-12-24T18:00' );
	same( '2026-12-24T18:00', timestamp_to_local( $ts ) );
	same( null, local_to_timestamp( 'nonsense' ) );
	same( '', timestamp_to_local( null ) );
} );

echo "\nPlugin\n";

test( 'Version numbers agree', function () {
	$main   = file_get_contents( dirname( __DIR__ ) . '/clean-short-links.php' );
	$readme = file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
	preg_match( '/^ \* Version:\s*(\S+)/m', $main, $h );
	preg_match( "/define\( 'CLEAN_SHORT_LINKS_VERSION', '([^']+)'/", $main, $c );
	preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $s );
	same( $h[1], $c[1], 'constant' );
	same( $h[1], $s[1], 'readme' );
} );

$fail = $GLOBALS['fail'];
echo "\n" . $GLOBALS['pass'] . ' passed, ' . count( $fail ) . " failed\n";
exit( $fail ? 1 : 0 );
