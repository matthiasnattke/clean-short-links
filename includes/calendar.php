<?php
/**
 * Rules from a calendar: an iCal file (.ics) imported once, or a calendar address that is
 * fetched again twice a day. Every event becomes a rule – its time, and as target the link
 * of the event (URL field or the first link in the description), otherwise a default.
 * Rules entered by hand are never touched; calendar rules are replaced on every sync.
 *
 * @package CleanShortLinks
 */

namespace CleanShortLinks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the events of an iCal text.
 *
 * @param string        $ics Calendar text.
 * @param \DateTimeZone $tz  Time zone for times without one (floating times).
 * @return array[] Events with summary, start, end (DateTimeImmutable), all_day, url, description, location, rrule.
 */
function parse_ics( $ics, \DateTimeZone $tz ) {
	// Unfold continuation lines (RFC 5545: a line starting with a space or tab continues the previous one).
	$text   = preg_replace( "/\r?\n[ \t]/", '', str_replace( "\r\n", "\n", (string) $ics ) );
	$events = array();
	$cur    = null;
	foreach ( explode( "\n", $text ) as $line ) {
		$line = rtrim( $line, "\r" );
		if ( 'BEGIN:VEVENT' === $line ) {
			$cur = array(
				'summary'     => '',
				'description' => '',
				'location'    => '',
				'url'         => '',
				'start'       => null,
				'end'         => null,
				'all_day'     => false,
				'rrule'       => array(),
				'status'      => '',
			);
			continue;
		}
		if ( 'END:VEVENT' === $line ) {
			if ( $cur && $cur['start'] && 'CANCELLED' !== $cur['status'] ) {
				if ( ! $cur['end'] ) {
					$cur['end'] = $cur['all_day'] ? $cur['start']->modify( '+1 day' ) : $cur['start']->modify( '+1 hour' );
				}
				$events[] = $cur;
			}
			$cur = null;
			continue;
		}
		if ( ! $cur || false === strpos( $line, ':' ) ) {
			continue;
		}
		list( $head, $value ) = explode( ':', $line, 2 );
		$parts                = explode( ';', $head );
		$name                 = strtoupper( array_shift( $parts ) );
		$params               = array();
		foreach ( $parts as $p ) {
			if ( false !== strpos( $p, '=' ) ) {
				list( $k, $v )                 = explode( '=', $p, 2 );
				$params[ strtoupper( $k ) ] = trim( $v, '"' );
			}
		}
		switch ( $name ) {
			case 'SUMMARY':
			case 'DESCRIPTION':
			case 'LOCATION':
				$cur[ strtolower( $name ) ] = ics_text( $value );
				break;
			case 'URL':
				$cur['url'] = trim( $value );
				break;
			case 'STATUS':
				$cur['status'] = strtoupper( trim( $value ) );
				break;
			case 'DTSTART':
			case 'DTEND':
				$date = ics_date( $value, $params, $tz );
				if ( $date ) {
					$cur[ 'DTSTART' === $name ? 'start' : 'end' ] = $date;
					if ( 'DTSTART' === $name ) {
						$cur['all_day'] = 'DATE' === ( $params['VALUE'] ?? '' ) || 8 === strlen( trim( $value ) );
					}
				}
				break;
			case 'RRULE':
				foreach ( explode( ';', $value ) as $pair ) {
					if ( false !== strpos( $pair, '=' ) ) {
						list( $k, $v )                   = explode( '=', $pair, 2 );
						$cur['rrule'][ strtoupper( $k ) ] = strtoupper( trim( $v ) );
					}
				}
				break;
		}
	}
	return $events;
}

/**
 * Unescapes an iCal text value.
 *
 * @param string $value Value.
 * @return string
 */
function ics_text( $value ) {
	return trim( str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), (string) $value ) );
}

/**
 * Reads an iCal date or date-time.
 *
 * @param string        $value  Value, e.g. 20270710T180000Z.
 * @param array         $params Parameters (TZID, VALUE).
 * @param \DateTimeZone $tz     Fallback time zone.
 * @return \DateTimeImmutable|null
 */
function ics_date( $value, array $params, \DateTimeZone $tz ) {
	$value = trim( $value );
	$zone  = $tz;
	if ( ! empty( $params['TZID'] ) ) {
		try {
			$zone = new \DateTimeZone( $params['TZID'] );
		} catch ( \Exception $e ) {
			$zone = $tz;
		}
	}
	if ( preg_match( '/^(\d{8})$/', $value, $m ) ) {
		$d = \DateTimeImmutable::createFromFormat( '!Ymd', $m[1], $tz );
	} elseif ( preg_match( '/^(\d{8}T\d{6})Z$/', $value, $m ) ) {
		$d = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], new \DateTimeZone( 'UTC' ) );
	} elseif ( preg_match( '/^(\d{8}T\d{6})$/', $value, $m ) ) {
		$d = \DateTimeImmutable::createFromFormat( 'Ymd\THis', $m[1], $zone );
	} else {
		return null;
	}
	return $d ? $d->setTimezone( $tz ) : null;
}

/**
 * The target of an event: its URL, otherwise the first link in description or location.
 *
 * @param array $event Event.
 * @return string URL or ''.
 */
function event_target( array $event ) {
	if ( preg_match( '#^https?://#i', $event['url'] ) ) {
		return $event['url'];
	}
	foreach ( array( $event['description'], $event['location'] ) as $text ) {
		if ( preg_match( '#https?://[^\s<>"\']+#i', (string) $text, $m ) ) {
			return rtrim( $m[0], '.,;)' );
		}
	}
	return '';
}

/**
 * Turns events into rules. Weekly and daily repetitions become one rule with weekdays and
 * daily times; other repetitions are listed one by one for the next year.
 *
 * @param array[]            $events  Events from parse_ics().
 * @param \DateTimeImmutable $now     Now (site time zone).
 * @param string             $default Target for events without a link, '' to skip them.
 * @param int                $limit   Maximum number of rules.
 * @return array[] Rules marked with source "ical".
 */
function events_to_rules( array $events, \DateTimeImmutable $now, $default = '', $limit = 200 ) {
	$rules   = array();
	$horizon = $now->modify( '+1 year' ); // Repetitions are listed one year ahead.
	$far     = $now->modify( '+2 years' ); // Single events up to two years ahead.
	$day_map = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);
	$fmt     = 'Y-m-d H:i';
	foreach ( $events as $e ) {
		$target = event_target( $e );
		$target = '' !== $target ? $target : $default;
		if ( '' === $target ) {
			continue;
		}
		$label = $e['summary'];
		$r     = $e['rrule'];
		$freq  = $r['FREQ'] ?? '';
		$step  = max( 1, (int) ( $r['INTERVAL'] ?? 1 ) );
		$until = null;
		if ( ! empty( $r['UNTIL'] ) ) {
			$until = ics_date( $r['UNTIL'], array(), $now->getTimezone() );
			if ( $until && 8 === strlen( $r['UNTIL'] ) ) {
				$until = $until->modify( '+1 day' );
			}
		}
		$count = isset( $r['COUNT'] ) ? max( 1, (int) $r['COUNT'] ) : 0;

		// Weekly (every week) and daily (every day): one rule with weekdays and daily times.
		if ( 1 === $step && ( 'DAILY' === $freq || 'WEEKLY' === $freq ) ) {
			$days = array();
			if ( 'WEEKLY' === $freq ) {
				foreach ( explode( ',', $r['BYDAY'] ?? '' ) as $d ) {
					$d = substr( $d, -2 );
					if ( isset( $day_map[ $d ] ) ) {
						$days[] = $day_map[ $d ];
					}
				}
				$days = $days ? $days : array( (int) $e['start']->format( 'N' ) );
			}
			if ( ! $until && $count ) {
				$per   = 'DAILY' === $freq ? 1 : max( 1, count( $days ) );
				$until = $e['start']->modify( '+' . ( 'DAILY' === $freq ? $count : (int) ceil( $count / $per ) * 7 ) . ' days' );
			}
			if ( $until && $until <= $now ) {
				continue;
			}
			$same_day = $e['end']->format( 'Y-m-d' ) === $e['start']->format( 'Y-m-d' );
			sort( $days );
			$rules[] = array(
				'label'     => $label,
				'from'      => $e['start']->format( 'Y-m-d' ) . ' 00:00',
				'to'        => $until ? $until->format( $fmt ) : '',
				'days'      => count( $days ) === 7 ? array() : $days,
				'time_from' => $e['all_day'] ? '' : $e['start']->format( 'H:i' ),
				'time_to'   => $e['all_day'] ? '' : ( $same_day ? $e['end']->format( 'H:i' ) : '' ),
				'target'    => $target,
				'source'    => 'ical',
			);
			continue;
		}

		// Single events and other repetitions: one rule per occurrence.
		$length = $e['end']->getTimestamp() - $e['start']->getTimestamp();
		$unit   = array(
			'DAILY'   => 'day',
			'WEEKLY'  => 'week',
			'MONTHLY' => 'month',
			'YEARLY'  => 'year',
		)[ $freq ] ?? '';
		$start  = $e['start'];
		$n      = 0;
		if ( $start > $far ) {
			continue;
		}
		do {
			$end = $start->modify( '+' . $length . ' seconds' );
			if ( $end > $now ) {
				$rules[] = array(
					'label'     => $label,
					'from'      => $start->format( $fmt ),
					'to'        => $end->format( $fmt ),
					'days'      => array(),
					'time_from' => '',
					'time_to'   => '',
					'target'    => $target,
					'source'    => 'ical',
				);
			}
			++$n;
			if ( '' === $unit ) {
				break;
			}
			$start = $start->modify( '+' . $step . ' ' . $unit );
		} while ( $start <= $horizon && ( ! $count || $n < $count ) && ( ! $until || $start < $until ) && count( $rules ) < $limit );

		if ( count( $rules ) >= $limit ) {
			break;
		}
	}
	usort( $rules, fn( $a, $b ) => strcmp( $a['from'], $b['from'] ) );
	return array_slice( $rules, 0, $limit );
}

/**
 * Replaces the calendar rules of a link; rules entered by hand stay first and unchanged.
 *
 * @param array $link  Link.
 * @param array $rules New calendar rules.
 * @return array All rules.
 */
function merge_calendar_rules( array $link, array $rules ) {
	$own = array_values( array_filter( (array) $link['rules'], fn( $r ) => 'ical' !== ( $r['source'] ?? '' ) ) );
	return array_merge( $own, $rules );
}

/**
 * Normalizes a calendar address: webcal:// becomes https://.
 *
 * @param string $url Address.
 * @return string '' if it is not a web address.
 */
function clean_calendar_url( $url ) {
	$url = trim( (string) $url );
	$url = preg_replace( '#^webcals?://#i', 'https://', $url );
	return preg_match( '#^https?://[^\s]+$#i', $url ) ? $url : '';
}

/**
 * Imports calendar text into a link.
 *
 * @param array  $link Link.
 * @param string $ics  Calendar text.
 * @return int|\WP_Error Number of calendar rules.
 */
function import_calendar( array $link, $ics ) {
	if ( false === strpos( (string) $ics, 'BEGIN:VCALENDAR' ) ) {
		return new \WP_Error( 'ics', __( 'This is not an iCal calendar.', 'clean-short-links' ) );
	}
	$default = (string) ( $link['meta']['ical_default'] ?? '' );
	$rules   = events_to_rules( parse_ics( $ics, wp_timezone() ), new \DateTimeImmutable( 'now', wp_timezone() ), '' !== $default ? $default : $link['target'] );
	foreach ( $rules as &$r ) {
		$post = url_to_postid( $r['target'] );
		if ( $post ) {
			$r['target'] = 'post:' . $post;
		}
	}
	unset( $r );
	update_rules( $link['id'], merge_calendar_rules( $link, clean_rules( $rules ) ) );
	return count( $rules );
}

/**
 * Fetches the calendar address of a link and imports it.
 *
 * @param array $link Link.
 * @return int|\WP_Error Number of calendar rules.
 */
function sync_calendar( array $link ) {
	$url = clean_calendar_url( $link['meta']['ical_url'] ?? '' );
	if ( '' === $url ) {
		return 0;
	}
	$res = wp_safe_remote_get( // Calendar import: the plugin's own feature, see readme.
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => 'Clean Short Links calendar import (' . home_url( '/' ) . ')',
		)
	);
	$result = is_wp_error( $res ) ? $res : ( 200 === (int) wp_remote_retrieve_response_code( $res ) ? import_calendar( $link, wp_remote_retrieve_body( $res ) ) : new \WP_Error( 'http', 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	$meta   = $link['meta'];
	$meta['ical_at']    = time();
	$meta['ical_error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
	$meta['ical_count'] = is_wp_error( $result ) ? (int) ( $meta['ical_count'] ?? 0 ) : (int) $result;
	update_meta( $link['id'], $meta );
	return $result;
}

/*
 * Calendar addresses are fetched again twice a day.
 */
add_action(
	'clean_short_links_calendars',
	function () {
		foreach ( links_with_calendar() as $link ) {
			sync_calendar( $link );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( (int) get_option( 'clean_short_links_db' ) === DB_VERSION && ! wp_next_scheduled( 'clean_short_links_calendars' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'clean_short_links_calendars' );
		}
	},
	20
);
