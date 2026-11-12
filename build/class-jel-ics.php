<?php
/**
 * Leest een ICS-agenda (zoals gepubliceerd door Outlook) en zet die om naar agenda-items.
 * - Herhalingen worden uitgerekend; aaneengesloten dagen worden samengevoegd tot één item met datumbereik.
 * - Tijden worden omgerekend naar de tijdzone van de website.
 * - Geannuleerde en privé-afspraken worden overgeslagen.
 */

class JEL_Ics {

	/** Windows-tijdzonenamen die Outlook gebruikt. */
	const WINDOWS_TZ = array(
		'w. europe standard time'       => 'Europe/Amsterdam',
		'romance standard time'         => 'Europe/Paris',
		'central europe standard time'  => 'Europe/Budapest',
		'central european standard time' => 'Europe/Warsaw',
		'gmt standard time'             => 'Europe/London',
		'greenwich standard time'       => 'Atlantic/Reykjavik',
		'utc'                           => 'UTC',
		'coordinated universal time'    => 'UTC',
	);

	const MAX_DAYS = 4000;

	/**
	 * @param string       $ics  Inhoud van het .ics-bestand.
	 * @param DateTimeZone $tz   Tijdzone van de website.
	 * @param string       $from Eerste datum (Y-m-d) die meetelt.
	 * @param string       $to   Laatste datum (Y-m-d) die meetelt.
	 * @return array Items, gesorteerd op startdatum.
	 */
	public static function parse( $ics, DateTimeZone $tz, $from, $to ) {
		$events = self::read_events( $ics );

		$masters   = array();
		$overrides = array(); // uid => [Ymd => true]
		$singles   = array();
		foreach ( $events as $ev ) {
			if ( isset( $ev['RECURRENCE-ID'] ) ) {
				$rid = self::to_datetime( $ev['RECURRENCE-ID'], $tz );
				if ( $rid ) {
					$overrides[ $ev['UID'] ][ $rid['dt']->format( 'Ymd' ) ] = true;
				}
				$singles[] = $ev;
			} elseif ( isset( $ev['RRULE'] ) ) {
				$masters[] = $ev;
			} else {
				$singles[] = $ev;
			}
		}

		$items = array();

		foreach ( $singles as $ev ) {
			$base = self::base( $ev, $tz );
			if ( $base && ! self::skip( $ev ) ) {
				$items[] = self::item( $ev, $base, $base['start'] );
			}
		}

		foreach ( $masters as $ev ) {
			$base = self::base( $ev, $tz );
			if ( ! $base || self::skip( $ev ) ) {
				continue;
			}
			$skip = isset( $overrides[ $ev['UID'] ] ) ? $overrides[ $ev['UID'] ] : array();
			foreach ( self::exdates( $ev, $tz ) as $ymd ) {
				$skip[ $ymd ] = true;
			}
			$dates = array();
			foreach ( self::occurrences( $base['start'], $ev['RRULE']['value'], $to ) as $day ) {
				if ( ! isset( $skip[ $day->format( 'Ymd' ) ] ) ) {
					$dates[] = $day;
				}
			}
			// Aaneengesloten dagen samenvoegen tot één item (bijv. POP-gesprekken ma t/m do).
			$runs = array();
			foreach ( $dates as $d ) {
				$n = count( $runs );
				if ( $n && $runs[ $n - 1 ]['last']->modify( '+1 day' )->format( 'Ymd' ) === $d->format( 'Ymd' ) ) {
					$runs[ $n - 1 ]['last'] = $d;
				} else {
					$runs[] = array( 'first' => $d, 'last' => $d );
				}
			}
			foreach ( $runs as $run ) {
				$item = self::item( $ev, $base, $run['first'] );
				// Einde = laatste dag van de reeks (plus eventuele lengte van één afspraak).
				$span        = (int) $base['start']->diff( $base['end_day'] )->format( '%a' );
				$item['end'] = $run['last']->modify( '+' . $span . ' days' )->format( 'Y-m-d' );
				$items[]     = $item;
			}
		}

		$items = array_values(
			array_filter(
				$items,
				function ( $i ) use ( $from, $to ) {
					return $i['end'] >= $from && $i['start'] <= $to;
				}
			)
		);
		usort(
			$items,
			function ( $a, $b ) {
				return array( $a['start'], $a['start_time'], $a['title'] ) <=> array( $b['start'], $b['start_time'], $b['title'] );
			}
		);
		return $items;
	}

	/* ------------------------------------------------------------------ */

	/** Ruwe VEVENT-blokken uitlezen. Elke property: ['value' => ..., 'params' => [...]]. */
	private static function read_events( $ics ) {
		$ics    = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$ics    = preg_replace( "/\n[ \t]/", '', $ics ); // regels die doorlopen samenvoegen
		$lines  = explode( "\n", $ics );
		$events = array();
		$cur    = null;
		$depth  = 0;
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$u = strtoupper( $line );
			if ( 'BEGIN:VEVENT' === $u ) {
				$cur   = array();
				$depth = 0;
				continue;
			}
			if ( null === $cur ) {
				continue;
			}
			if ( 'END:VEVENT' === $u ) {
				if ( isset( $cur['DTSTART'] ) ) {
					$cur['UID'] = isset( $cur['UID'] ) ? $cur['UID']['value'] : md5( serialize( $cur ) );
					$events[]   = $cur;
				}
				$cur = null;
				continue;
			}
			if ( 0 === strpos( $u, 'BEGIN:' ) ) {
				$depth++; // bijv. VALARM
				continue;
			}
			if ( 0 === strpos( $u, 'END:' ) ) {
				$depth = max( 0, $depth - 1 );
				continue;
			}
			if ( $depth > 0 ) {
				continue;
			}
			$prop = self::property( $line );
			if ( ! $prop ) {
				continue;
			}
			list( $name, $params, $value ) = $prop;
			if ( 'EXDATE' === $name || 'CATEGORIES' === $name ) {
				$cur[ $name ][] = array( 'value' => $value, 'params' => $params );
			} else {
				$cur[ $name ] = array( 'value' => $value, 'params' => $params );
			}
		}
		return $events;
	}

	private static function property( $line ) {
		// Splitsen op de eerste ':' die niet tussen aanhalingstekens staat.
		$len   = strlen( $line );
		$quote = false;
		$pos   = false;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $line[ $i ];
			if ( '"' === $c ) {
				$quote = ! $quote;
			} elseif ( ':' === $c && ! $quote ) {
				$pos = $i;
				break;
			}
		}
		if ( false === $pos ) {
			return null;
		}
		$head   = substr( $line, 0, $pos );
		$value  = substr( $line, $pos + 1 );
		$parts  = preg_split( '/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $head );
		$name   = strtoupper( array_shift( $parts ) );
		$params = array();
		foreach ( $parts as $p ) {
			$kv = explode( '=', $p, 2 );
			if ( 2 === count( $kv ) ) {
				$params[ strtoupper( $kv[0] ) ] = trim( $kv[1], '"' );
			}
		}
		return array( $name, $params, $value );
	}

	private static function text( $value ) {
		return trim(
			strtr(
				$value,
				array(
					'\\n' => "\n",
					'\\N' => "\n",
					'\\,' => ',',
					'\\;' => ';',
					'\\\\' => '\\',
				)
			)
		);
	}

	private static function skip( $ev ) {
		$status = isset( $ev['STATUS'] ) ? strtoupper( $ev['STATUS']['value'] ) : '';
		$class  = isset( $ev['CLASS'] ) ? strtoupper( $ev['CLASS']['value'] ) : '';
		return 'CANCELLED' === $status || 'PRIVATE' === $class || 'CONFIDENTIAL' === $class;
	}

	private static function tz( $name, DateTimeZone $fallback ) {
		if ( ! $name ) {
			return $fallback;
		}
		$key = strtolower( trim( $name ) );
		if ( isset( self::WINDOWS_TZ[ $key ] ) ) {
			return new DateTimeZone( self::WINDOWS_TZ[ $key ] );
		}
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return $fallback;
		}
	}

	/** @return array{dt: DateTimeImmutable, date_only: bool}|null */
	private static function to_datetime( $prop, DateTimeZone $tz, $value = null ) {
		$v      = trim( null === $value ? $prop['value'] : $value );
		$params = $prop['params'];
		if ( preg_match( '/^(\d{8})$/', $v ) || ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) ) {
			$d = DateTimeImmutable::createFromFormat( '!Ymd', substr( $v, 0, 8 ), $tz );
			return $d ? array( 'dt' => $d, 'date_only' => true ) : null;
		}
		if ( ! preg_match( '/^(\d{8})T(\d{4,6})(Z?)$/i', $v, $m ) ) {
			return null;
		}
		$time = str_pad( $m[2], 6, '0' );
		if ( $m[3] ) {
			$d = DateTimeImmutable::createFromFormat( 'YmdHis', $m[1] . $time, new DateTimeZone( 'UTC' ) );
		} else {
			$src = self::tz( isset( $params['TZID'] ) ? $params['TZID'] : '', $tz );
			$d   = DateTimeImmutable::createFromFormat( 'YmdHis', $m[1] . $time, $src );
		}
		return $d ? array( 'dt' => $d->setTimezone( $tz ), 'date_only' => false ) : null;
	}

	/** Start, eind en hele-dag-informatie van één afspraak. */
	private static function base( $ev, DateTimeZone $tz ) {
		$s = self::to_datetime( $ev['DTSTART'], $tz );
		if ( ! $s ) {
			return null;
		}
		$start = $s['dt'];
		$e     = isset( $ev['DTEND'] ) ? self::to_datetime( $ev['DTEND'], $tz ) : null;
		if ( $e ) {
			$end = $e['dt'];
		} elseif ( isset( $ev['DURATION'] ) ) {
			try {
				$end = $start->add( new DateInterval( ltrim( $ev['DURATION']['value'], '+' ) ) );
			} catch ( Exception $ex ) {
				$end = $start;
			}
		} else {
			$end = $s['date_only'] ? $start->modify( '+1 day' ) : $start;
		}
		if ( $end < $start ) {
			$end = $start;
		}

		$ms_allday = isset( $ev['X-MICROSOFT-CDO-ALLDAYEVENT'] ) && 'TRUE' === strtoupper( $ev['X-MICROSOFT-CDO-ALLDAYEVENT']['value'] );
		$midnight  = '0000' === $start->format( 'Hi' ) && '0000' === $end->format( 'Hi' ) && $end > $start;
		$allday    = $s['date_only'] || $ms_allday || $midnight;

		if ( $allday ) {
			$start   = $start->setTime( 0, 0 );
			$end_day = $end > $start ? $end->modify( '-1 day' )->setTime( 0, 0 ) : $start;
			if ( $end_day < $start ) {
				$end_day = $start;
			}
			return array(
				'start'      => $start,
				'end_day'    => $end_day,
				'start_time' => '',
				'end_time'   => '',
			);
		}

		// Afspraak met tijd. Eindigt hij om 00:00 op een volgende dag, dan telt die dag niet mee.
		$end_day = $end->setTime( 0, 0 );
		if ( '0000' === $end->format( 'Hi' ) && $end_day > $start->setTime( 0, 0 ) ) {
			$end_day = $end_day->modify( '-1 day' );
		}
		return array(
			'start'      => $start->setTime( 0, 0 ),
			'end_day'    => $end_day,
			'start_time' => $start->format( 'H:i' ),
			'end_time'   => $end > $start ? $end->format( 'H:i' ) : '',
		);
	}

	private static function item( $ev, $base, DateTimeImmutable $day ) {
		$span  = (int) $base['start']->diff( $base['end_day'] )->format( '%a' );
		$title = isset( $ev['SUMMARY'] ) ? self::text( $ev['SUMMARY']['value'] ) : '';

		$cats = array();
		if ( isset( $ev['CATEGORIES'] ) ) {
			foreach ( $ev['CATEGORIES'] as $c ) {
				foreach ( explode( ',', str_replace( '\\,', "\x1F", $c['value'] ) ) as $name ) {
					$name = trim( self::text( str_replace( "\x1F", ',', $name ) ) );
					if ( '' !== $name ) {
						$cats[] = $name;
					}
				}
			}
		}

		$desc = isset( $ev['DESCRIPTION'] ) ? self::text( $ev['DESCRIPTION']['value'] ) : '';
		// Teams-/Outlook-standaardtekst weglaten.
		$desc = trim( preg_split( '/_{6,}|-{6,}/', $desc )[0] );
		if ( strlen( $desc ) > 400 ) {
			$desc = rtrim( substr( $desc, 0, 400 ) ) . '…';
		}

		return array(
			'start'       => $day->format( 'Y-m-d' ),
			'end'         => $day->modify( '+' . $span . ' days' )->format( 'Y-m-d' ),
			'start_time'  => $base['start_time'],
			'end_time'    => $base['end_time'],
			'title'       => '' !== $title ? $title : '(zonder titel)',
			'categories'  => $cats,
			'location'    => isset( $ev['LOCATION'] ) ? self::text( $ev['LOCATION']['value'] ) : '',
			'description' => $desc,
		);
	}

	private static function exdates( $ev, DateTimeZone $tz ) {
		$out = array();
		if ( empty( $ev['EXDATE'] ) ) {
			return $out;
		}
		foreach ( $ev['EXDATE'] as $prop ) {
			foreach ( explode( ',', $prop['value'] ) as $v ) {
				$d = self::to_datetime( $prop, $tz, $v );
				if ( $d ) {
					$out[] = $d['dt']->format( 'Ymd' );
				}
			}
		}
		return $out;
	}

	/**
	 * Dagen waarop een herhalende afspraak plaatsvindt (DAILY, WEEKLY, MONTHLY, YEARLY;
	 * met INTERVAL, COUNT, UNTIL, BYDAY en BYMONTHDAY).
	 *
	 * @return DateTimeImmutable[]
	 */
	private static function occurrences( DateTimeImmutable $start, $rrule, $to ) {
		$r = array();
		foreach ( explode( ';', strtoupper( $rrule ) ) as $part ) {
			$kv = explode( '=', $part, 2 );
			if ( 2 === count( $kv ) ) {
				$r[ $kv[0] ] = $kv[1];
			}
		}
		$freq     = isset( $r['FREQ'] ) ? $r['FREQ'] : '';
		$interval = isset( $r['INTERVAL'] ) ? max( 1, (int) $r['INTERVAL'] ) : 1;
		$count    = isset( $r['COUNT'] ) ? (int) $r['COUNT'] : 0;
		$until    = null;
		if ( isset( $r['UNTIL'] ) && preg_match( '/^(\d{8})/', $r['UNTIL'], $m ) ) {
			$until = $m[1];
		}
		$limit = str_replace( '-', '', $to );
		if ( $until && $until < $limit ) {
			$limit = $until;
		}

		$days_map = array( 'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6 );
		$byday    = array(); // [ [ordinal|null, weekday] ]
		if ( ! empty( $r['BYDAY'] ) ) {
			foreach ( explode( ',', $r['BYDAY'] ) as $bd ) {
				if ( preg_match( '/^([+-]?\d+)?(SU|MO|TU|WE|TH|FR|SA)$/', $bd, $m ) ) {
					$byday[] = array( '' !== $m[1] ? (int) $m[1] : null, $days_map[ $m[2] ] );
				}
			}
		}
		$bymonthday = ! empty( $r['BYMONTHDAY'] ) ? array_map( 'intval', explode( ',', $r['BYMONTHDAY'] ) ) : array();

		$out   = array();
		$found = 0;
		$start = $start->setTime( 0, 0 );
		$wk0   = $start->modify( 'monday this week' );
		for ( $i = 0; $i < self::MAX_DAYS; $i++ ) {
			$d = $start->modify( '+' . $i . ' days' );
			if ( $d->format( 'Ymd' ) > $limit ) {
				break;
			}
			$match = false;
			switch ( $freq ) {
				case 'DAILY':
					$match = 0 === $i % $interval;
					break;
				case 'WEEKLY':
					$weeks = intdiv( (int) $wk0->diff( $d->modify( 'monday this week' ) )->format( '%a' ), 7 );
					$wd    = (int) $d->format( 'w' );
					$days  = $byday ? array_column( $byday, 1 ) : array( (int) $start->format( 'w' ) );
					$match = 0 === $weeks % $interval && in_array( $wd, $days, true );
					break;
				case 'MONTHLY':
					$months = ( (int) $d->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12 + (int) $d->format( 'n' ) - (int) $start->format( 'n' );
					if ( 0 !== $months % $interval ) {
						break;
					}
					if ( $byday ) {
						$match = self::matches_nth( $d, $byday );
					} else {
						$md    = $bymonthday ? $bymonthday : array( (int) $start->format( 'j' ) );
						$last  = (int) $d->format( 't' );
						$dn    = (int) $d->format( 'j' );
						foreach ( $md as $x ) {
							if ( $x === $dn || ( $x < 0 && $last + $x + 1 === $dn ) ) {
								$match = true;
							}
						}
					}
					break;
				case 'YEARLY':
					$years = (int) $d->format( 'Y' ) - (int) $start->format( 'Y' );
					$match = 0 === $years % $interval && $d->format( 'md' ) === $start->format( 'md' );
					break;
				default:
					return array( $start );
			}
			if ( ! $match ) {
				continue;
			}
			$found++;
			$out[] = $d;
			if ( $count && $found >= $count ) {
				break;
			}
		}
		return $out;
	}

	private static function matches_nth( DateTimeImmutable $d, array $byday ) {
		$wd   = (int) $d->format( 'w' );
		$dn   = (int) $d->format( 'j' );
		$last = (int) $d->format( 't' );
		foreach ( $byday as $b ) {
			list( $ord, $day ) = $b;
			if ( $day !== $wd ) {
				continue;
			}
			if ( null === $ord ) {
				return true;
			}
			$nth_from_start = intdiv( $dn - 1, 7 ) + 1;
			$nth_from_end   = -( intdiv( $last - $dn, 7 ) + 1 );
			if ( $ord === $nth_from_start || $ord === $nth_from_end ) {
				return true;
			}
		}
		return false;
	}
}
