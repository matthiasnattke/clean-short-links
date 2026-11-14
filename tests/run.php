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
	same( '725-years-of-town', normalize_slug( '725 Years of Town!' ) );
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
	same( '725-years-of-town', title_slug( '725 Years of Town' ) );
	same( 'the-big-summer-party', title_slug( 'The big summer party in the park by the water' ) );
	same( true, strlen( title_slug( 'Pneumonoultramicroscopicsilicovolcanoconiosis' ) ) <= 30 );
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
	same( array( 'status' => 302, 'url' => 'https://example.com/hello/', 'state' => 'default', 'target' => 'post:1' ), go( link(), '2026-06-01 12:00' ) );
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

test( 'Click limit: used up links behave like expired ones', function () {
	$l = link( array( 'max_clicks' => 3, 'clicks' => 2 ) );
	same( 302, go( $l, '2026-06-01 12:00' )['status'] );
	$l['clicks'] = 3;
	same( 410, go( $l, '2026-06-01 12:00' )['status'] );
	same( 'exhausted', go( $l, '2026-06-01 12:00' )['state'] );
	same( 'exhausted', link_state( $l, time() ) );
	$l['fallback'] = 'https://example.com/sold-out/';
	same( 'https://example.com/sold-out/', go( $l, '2026-06-01 12:00' )['url'] );
	same( false, limit_reached( link( array( 'max_clicks' => 0, 'clicks' => 99 ) ) ) );
} );

test( 'Targets: other short links and the home page', function () {
	same( 'link:7', clean_target( 'link:7' ) );
	same( 'home', clean_target( 'home' ) );
	same( '', clean_target( 'link:0' ) );
	same( 7, target_link_id( 'link:7' ) );
	same( 0, target_link_id( 'post:7' ) );
} );

test( 'Invitations follow their main link but keep limit, counter and expiry', function () {
	$parent = link( array( 'id' => 1, 'target' => 'https://example.com/party/', 'rules' => array( array( 'target' => 'https://example.com/x/' ) ), 'ends_at' => 2000 ) );
	$invite = array( 'id' => 9, 'slug' => 'k7mq4abc', 'title' => 'Family', 'parent_id' => 1, 'max_clicks' => 1, 'clicks' => 0, 'status' => 'active', 'ends_at' => 1500, 'target' => 'https://old.example/' );
	$m = merge_parent( $invite, $parent );
	same( 9, $m['id'] );
	same( 'https://example.com/party/', $m['target'] );
	same( 1, count( $m['rules'] ) );
	same( 1500, $m['ends_at'], 'earlier expiry of the invitation wins' );
	same( 1, $m['max_clicks'] );
	$invite['ends_at'] = 3000;
	same( 2000, merge_parent( $invite, $parent )['ends_at'], 'the main link ends them all' );
	$parent['status'] = 'paused';
	same( 'paused', merge_parent( $invite, $parent )['status'] );
} );

echo "\nSocial media\n";

test( 'UTM parameters are added without overwriting existing ones', function () {
	same( 'https://example.com/a?utm_source=ig&utm_medium=social', add_utm( 'https://example.com/a', array( 'utm_source' => 'ig', 'utm_medium' => 'social' ) ) );
	same( 'https://example.com/a?x=1&utm_medium=qr#top', add_utm( 'https://example.com/a?x=1#top', array( 'utm_medium' => 'qr' ) ) );
	same( 'https://example.com/a?utm_source=own', add_utm( 'https://example.com/a?utm_source=own', array( 'utm_source' => 'ig' ) ) );
} );

test( 'UTM values come from the link, channel and source', function () {
	$l = array( 'slug' => 'fest' );
	same( array( 'utm_source' => 'ig', 'utm_medium' => 'social', 'utm_campaign' => 'fest' ), utm_params( $l, SOURCE_LINK, 'ig' ) );
	same( 'email', utm_params( $l, SOURCE_LINK, 'nl' )['utm_medium'] );
	same( array( 'utm_source' => 'qr', 'utm_medium' => 'qr', 'utm_campaign' => 'fest' ), utm_params( $l, SOURCE_QR ) );
	same( 'linkinbio', utm_params( $l, SOURCE_BIO )['utm_source'] );
} );

test( 'Permanent redirects only for links that cannot change by themselves', function () {
	$l = link( array( 'meta' => array( 'permanent' => 1 ) ) );
	same( true, permanent_allowed( $l ) );
	same( false, permanent_allowed( array_merge( $l, array( 'rules' => array( array( 'target' => 'post:1' ) ) ) ) ) );
	same( false, permanent_allowed( array_merge( $l, array( 'ends_at' => 5 ) ) ) );
	same( false, permanent_allowed( array_merge( $l, array( 'max_clicks' => 5 ) ) ) );
	same( true, permanent_allowed( array_merge( $l, array( 'start_mode' => 'publish' ) ) ), 'publish start' );
	same( false, permanent_allowed( array_merge( $l, array( 'start_mode' => 'date', 'starts_at' => 5 ) ) ), 'date start' );
	same( false, permanent_allowed( link() ) );
} );

test( 'Extra settings are cleaned', function () {
	$m = clean_meta( array( 'channels' => array( 'ig', 'nope', 'wa' ), 'utm' => '1', 'social' => array( 'title' => ' <b>Hi</b> ', 'image' => '12' ) ) );
	same( array( 'ig', 'wa' ), $m['channels'] );
	same( 1, $m['utm'] );
	same( 'Hi', $m['social']['title'] );
	same( 12, $m['social']['image'] );
	same( 0, $m['permanent'] );
} );

test( 'Preview robots are recognised', function () {
	same( true, is_preview_bot( 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)' ) );
	same( true, is_preview_bot( 'WhatsApp/2.23.20.0' ) );
	same( true, is_preview_bot( 'TelegramBot (like TwitterBot)' ) );
	same( false, is_preview_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1' ) );
} );

echo "\nMaintenance mode\n";

test( 'Shared maintenance rules: same defaults and values as Clean Maintenance', function () {
	same( array( 'during' => 'redirect', 'dead' => 'never' ), clean_maintenance_rules( null ) );
	same( array( 'during' => 'open', 'dead' => 'always' ), clean_maintenance_rules( array( 'during' => 'open', 'dead' => 'always', 'present' => '1' ) ) );
	same( array( 'during' => 'redirect', 'dead' => 'never' ), clean_maintenance_rules( array( 'during' => 'later', 'dead' => 'sometimes' ) ) );
	update_option( 'clean_maintenance_short_links', array( 'during' => 'pause', 'dead' => 'temporary' ) );
	same( array( 'during' => 'pause', 'dead' => 'temporary' ), maintenance_rules() );
	same( false, maintenance_available(), 'without Clean Maintenance nothing applies' );
} );

echo "\nCalendar\n";

function ics_sample() {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Festival\\, day one\r\nDTSTART;TZID=Europe/Berlin:20270710T180000\r\nDTEND;TZID=Europe/Berlin:20270710T230000\r\nURL:https://example.com/stage/\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Rehearsal\r\nDTSTART:20270101T170000Z\r\nDTEND:20270101T190000Z\r\nRRULE:FREQ=WEEKLY;BYDAY=FR,SA;UNTIL=20271231T000000Z\r\nDESCRIPTION:Plan: https://example.com/plan/ – see you\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Market\r\nDTSTART;VALUE=DATE:20270601\r\nDTEND;VALUE=DATE:20270603\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Old\r\nDTSTART:20200101T100000Z\r\nDTEND:20200101T110000Z\r\nURL:https://example.com/old/\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Cancelled\r\nSTATUS:CANCELLED\r\nDTSTART:20270801T100000Z\r\nURL:https://example.com/x/\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Monthly\r\nDTSTART:20270115T080000Z\r\nDTEND:20270115T090000Z\r\nRRULE:FREQ=MONTHLY;COUNT=3\r\nURL:https://example.com/month/\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nSUMMARY:Long description\r\nDTSTART:20270901T100000Z\r\nDESCRIPTION:folded https://example.com/fo\r\n lded/ text\r\nEND:VEVENT\r\n"
		. "END:VCALENDAR\r\n";
}

test( 'iCal: events, time zones, all-day, folding, cancellations', function () {
	$e = parse_ics( ics_sample(), wp_timezone() );
	same( 6, count( $e ), 'cancelled event left out' );
	same( 'Festival, day one', $e[0]['summary'] );
	same( '2027-07-10 18:00', $e[0]['start']->format( 'Y-m-d H:i' ) );
	same( '2027-01-01 18:00', $e[1]['start']->format( 'Y-m-d H:i' ), 'UTC converted to Berlin' );
	same( true, $e[2]['all_day'] );
	same( 'https://example.com/folded/', event_target( $e[5] ), 'folded line joined' );
	same( 'https://example.com/plan/', event_target( $e[1] ) );
} );

test( 'iCal: events become rules', function () {
	$now   = new \DateTimeImmutable( '2027-03-01 12:00', wp_timezone() );
	$rules = events_to_rules( parse_ics( ics_sample(), wp_timezone() ), $now, 'post:5' );
	$by    = array();
	foreach ( $rules as $r ) {
		$by[ $r['label'] ][] = $r;
	}
	same( false, isset( $by['Old'] ), 'past event skipped' );
	same( array( 5, 6 ), $by['Rehearsal'][0]['days'] );
	same( '18:00', $by['Rehearsal'][0]['time_from'] );
	same( '20:00', $by['Rehearsal'][0]['time_to'] );
	same( 'https://example.com/plan/', $by['Rehearsal'][0]['target'] );
	same( '2027-07-10 18:00', $by['Festival, day one'][0]['from'] );
	same( '2027-07-10 23:00', $by['Festival, day one'][0]['to'] );
	same( 'post:5', $by['Market'][0]['target'], 'default target for events without a link' );
	same( '2027-06-03 00:00', $by['Market'][0]['to'] );
	same( 1, count( $by['Monthly'] ), 'of 15 Jan, 15 Feb and 15 Mar only March is still ahead' );
	same( '2027-03-15 09:00', $by['Monthly'][0]['from'] );
	same( 'ical', $rules[0]['source'] );
	$far = events_to_rules( parse_ics( "BEGIN:VCALENDAR\nBEGIN:VEVENT\nSUMMARY:Far\nDTSTART:20300101T100000Z\nURL:https://example.com/\nEND:VEVENT\nEND:VCALENDAR", wp_timezone() ), $now );
	same( array(), $far, 'single events more than two years ahead are left out' );
} );

test( 'iCal: rules entered by hand stay, calendar rules are replaced', function () {
	$link = array( 'rules' => array( array( 'target' => 'post:1', 'label' => 'mine' ), array( 'target' => 'post:2', 'source' => 'ical' ) ) );
	$all  = merge_calendar_rules( $link, array( array( 'target' => 'post:3', 'source' => 'ical' ) ) );
	same( array( 'mine', null ), array( $all[0]['label'], $all[1]['label'] ?? null ) );
	same( 'post:3', $all[1]['target'] );
	same( 2, count( $all ) );
	same( 'https://example.com/cal.ics', clean_calendar_url( 'webcal://example.com/cal.ics' ) );
	same( '', clean_calendar_url( 'ftp://x' ) );
} );

echo "\nLink check\n";

test( 'Unreachable targets are recognised from the last check', function () {
	$l = link(
		array(
			'check' => array(
				'items' => array(
					array( 'role' => 'main', 'target' => 'post:1', 'status' => 'broken' ),
					array( 'role' => 'fallback', 'target' => 'https://example.com/soon/', 'status' => 'ok' ),
					array( 'role' => 'self', 'target' => 'post:9', 'status' => 'broken' ),
				),
			),
		)
	);
	same( true, target_broken( $l, 'post:1' ) );
	same( false, target_broken( $l, 'https://example.com/soon/' ) );
	same( false, target_broken( $l, 'post:9' ), 'the self-check is not a target' );
	same( false, target_broken( link(), 'post:1' ), 'not checked yet' );
} );

test( 'Unreachable targets: temporarily first, permanently after N days', function () {
	$now = 1800000000;
	$l   = link( array( 'check' => array( 'since' => $now - 5 * DAY_IN_SECONDS ) ) );
	same( 'temporary', unreachable( $l, $now, 30 ) );
	same( 'unreachable', link_state( $l, $now, 30 ) );
	$l['check']['since'] = $now - 30 * DAY_IN_SECONDS;
	same( 'gone', unreachable( $l, $now, 30 ) );
	same( 'gone', link_state( $l, $now, 30 ) );
	same( '', unreachable( link(), $now, 30 ), 'reachable or not checked' );
	same( 'paused', link_state( array_merge( $l, array( 'status' => 'paused' ) ), $now, 30 ) );
} );

test( 'Answers are classified', function () {
	same( 'ok', classify_response( 200 ) );
	same( 'ok', classify_response( 301 ) );
	same( 'warning', classify_response( 403 ) );
	same( 'warning', classify_response( 429 ) );
	same( 'broken', classify_response( 404 ) );
	same( 'broken', classify_response( 500 ) );
	same( 'broken', classify_response( 0, true ) );
} );

test( 'The worse result wins', function () {
	same( 'warning', worse( 'ok', 'warning' ) );
	same( 'broken', worse( 'broken', 'ok' ) );
	same( 'ok', worse( '', 'ok' ) );
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

test( 'Default kind: posts and pages from the title, other targets random or memorable', function () {
	same( 'title', default_kind( 'post:5', site_defaults() ) );
	same( 'random', default_kind( 'https://example.org/', site_defaults() ) );
	same( 'random', default_kind( 'home', site_defaults() ) );
	same( 'memorable', default_kind( 'https://example.org/', array( 'other_kind' => 'memorable' ) ) );
	same( 'random', default_kind( 'https://example.org/', array( 'other_kind' => 'title' ) ), 'title only for content' );
	same( 'memorable', default_kind( 'post:5', array( 'post_kind' => 'memorable' ) ) );
	same( 'title', default_kind( 'post:5', array( 'post_kind' => 'nonsense' ) ) );
} );

test( 'Changes from the editor sidebar touch only what it shows', function () {
	$old = array(
		'id' => 7, 'title' => 'T', 'target' => 'post:5', 'slug' => 'abc', 'qr_slug' => '', 'kind' => 'random', 'root' => 0,
		'start_mode' => '', 'starts_at' => null, 'ends_at' => null, 'fallback' => 'https://example.org/',
		'rules' => array( array( 'target' => 'home' ) ), 'qr' => array( 'dots' => 'round' ), 'status' => 'active', 'max_clicks' => 0,
		'meta' => clean_meta( array( 'utm' => 1, 'channels' => array( 'ig' ) ) ),
	);
	$d = editor_changes( $old, array() );
	same( 'abc', $d['slug'] );
	same( 'random', $d['kind'], 'kind kept' );
	same( 'https://example.org/', $d['fallback'], 'fallback kept' );
	same( 1, count( $d['rules'] ), 'rules kept' );
	same( array( 'ig' ), $d['meta']['channels'], 'channels kept' );
	same( 1, $d['meta']['utm'], 'utm kept' );

	$d = editor_changes( $old, array( 'slug' => 'Sommer Fest', 'kind' => 'random', 'keep_old' => false, 'paused' => true, 'root' => true, 'max_clicks' => '50', 'permanent' => true ) );
	same( 'sommer-fest', $d['slug'] );
	same( 'random', $d['kind'] );
	same( 'retire', $d['old_slug'] );
	same( 'paused', $d['status'] );
	same( true, $d['root'] );
	same( 50, $d['max_clicks'] );
	same( 1, $d['meta']['permanent'] );

	$d = editor_changes( $old, array( 'slug' => 'eigen', 'kind' => 'custom' ) );
	same( 'custom', $d['kind'] );
	same( 'alias', $d['old_slug'] );

	$d = editor_changes( $old, array( 'start_mode' => 'date', 'starts_at' => '2026-12-24T18:00', 'ends_at' => '2026-12-31T23:59' ) );
	same( 'date', $d['start_mode'] );
	same( '2026-12-24T18:00', timestamp_to_local( $d['starts_at'] ) );
	same( '2026-12-31T23:59', timestamp_to_local( $d['ends_at'] ) );

	$d = editor_changes( $old, array( 'start_mode' => 'date', 'starts_at' => '', 'ends_at' => '' ) );
	same( '', $d['start_mode'], 'date without a date starts right away' );
	same( 0, $d['ends_at'] );

	$url = $old;
	$url['target'] = 'https://example.org/x';
	same( '', editor_changes( $url, array( 'start_mode' => 'publish' ) )['start_mode'], 'publish needs a post' );
	same( 'publish', editor_changes( $old, array( 'start_mode' => 'publish' ) )['start_mode'] );
} );

echo "\nVariants and domains\n";

test( 'Operating system from client hint or user agent', function () {
	same( 'android', os_class( 'whatever', '"Android"' ) );
	same( 'windows', os_class( '', '"Windows"' ) );
	same( 'linux', os_class( '', '"Chrome OS"' ) );
	same( 'ios', os_class( 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' ) );
	same( 'ios', os_class( 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)' ) );
	same( 'android', os_class( 'Mozilla/5.0 (Linux; Android 14; Pixel 8)' ) );
	same( 'macos', os_class( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)' ) );
	same( 'windows', os_class( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ) );
	same( 'linux', os_class( 'Mozilla/5.0 (X11; Linux x86_64)' ) );
	same( 'other', os_class( '' ) );
	same( 'mobile', device_key( DEVICE_MOBILE ) );
	same( 'desktop', device_key( DEVICE_DESKTOP ) );
} );

test( 'Variants are cleaned', function () {
	$v = clean_variants(
		array(
			array( 'device' => 'mobile', 'os' => 'ios', 'target' => 'https://apps.apple.com/x' ),
			array( 'device' => '', 'os' => '', 'target' => 'https://example.org/' ),
			array( 'device' => 'phone', 'os' => 'beos', 'min_w' => 900, 'max_w' => 300, 'target' => 'https://example.org/s' ),
			array( 'os' => 'android' ),
		)
	);
	same( 2, count( $v ), 'without condition or target dropped' );
	same( 'ios', $v[0]['os'] );
	same( '', $v[1]['device'], 'unknown device ignored' );
	same( array( 300, 900 ), array( $v[1]['min_w'], $v[1]['max_w'] ), 'range swapped' );
} );

test( 'Which variant applies', function () {
	$v = clean_variants(
		array(
			array( 'os' => 'ios', 'target' => 'https://apple.example/' ),
			array( 'os' => 'android', 'target' => 'https://play.example/' ),
			array( 'max_w' => 600, 'target' => 'https://m.example/' ),
			array( 'device' => 'mobile', 'target' => 'https://phone.example/' ),
		)
	);
	same( array( 'target' => 'https://apple.example/' ), variant_plan( $v, 'mobile', 'ios' ) );
	same( array( 'target' => 'https://play.example/' ), variant_plan( $v, 'tablet', 'android' ) );
	same(
		array(
			'screen'   => array( array( 0, 600, 'https://m.example/' ) ),
			'fallback' => 'https://phone.example/',
		),
		variant_plan( $v, 'mobile', 'windows' ),
		'screen first, then the next fitting variant'
	);
	same(
		array(
			'screen'   => array( array( 0, 600, 'https://m.example/' ) ),
			'fallback' => null,
		),
		variant_plan( $v, 'desktop', 'linux' ),
		'no further variant: the normal target'
	);
	same( null, variant_plan( array(), 'desktop', 'linux' ) );
} );

test( 'Host names are cleaned', function () {
	same( array( 'cbl.de', 'kuro.couch-blog.de' ), clean_hosts( "https://CBL.de/s/x\nkuro.couch-blog.de, cbl.de" ) );
	same( array(), clean_hosts( array( 'not a host', 'localhost', '-bad.de' ) ) );
	same( array(), clean_meta( array( 'hosts' => 'nonsense' ) )['hosts'] );
} );

test( 'No permanent redirect with variants', function () {
	$l = array(
		'meta'       => clean_meta( array( 'permanent' => 1, 'variants' => array( array( 'os' => 'ios', 'target' => 'https://a.example/' ) ) ) ),
		'parent_id'  => 0,
		'rules'      => array(),
		'starts_at'  => null,
		'start_mode' => '',
		'ends_at'    => null,
		'max_clicks' => 0,
	);
	same( false, permanent_allowed( $l ) );
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
