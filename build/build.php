<?php
/**
 * Zet de Outlook-agenda (ICS) om naar docs/agenda.json voor de website.
 *
 * Gebruik:  php build/build.php <agenda.ics> <docs/agenda.json> [vandaag als JJJJ-MM-DD]
 *
 * Stopt met een foutmelding (en laat agenda.json ongemoeid) als het bestand geen agenda is,
 * of als Outlook alleen "bezet/vrij" doorgeeft in plaats van de titels.
 */

require __DIR__ . '/class-jel-ics.php';

function fail( $msg ) {
	fwrite( STDERR, "FOUT: $msg\n" );
	exit( 1 );
}

$in    = $argv[1] ?? '';
$out   = $argv[2] ?? '';
$today = $argv[3] ?? '';
if ( ! $in || ! $out ) {
	fail( 'Gebruik: php build/build.php <agenda.ics> <agenda.json> [JJJJ-MM-DD]' );
}

$ics = @file_get_contents( $in );
if ( false === $ics || false === stripos( $ics, 'BEGIN:VCALENDAR' ) ) {
	fail( 'Dit is geen agenda. Gebruik de ICS-link uit Outlook (eindigt op .ics), niet de HTML-link.' );
}

$tz    = new DateTimeZone( 'Europe/Amsterdam' );
$now   = new DateTimeImmutable( $today ? $today : 'today', $tz );
$from  = $now->modify( '-1 month' )->format( 'Y-m-d' );
$to    = $now->modify( '+13 months' )->format( 'Y-m-d' );
$items = JEL_Ics::parse( $ics, $tz, $from, $to );

// Controle: als Outlook alleen beschikbaarheid publiceert, zijn alle titels "Bezet" of "Vrij".
$busy = 0;
foreach ( $items as $i ) {
	if ( in_array( strtolower( $i['title'] ), array( 'bezet', 'busy', 'vrij', 'free', 'voorlopig', 'tentative', 'afwezig', 'away' ), true ) ) {
		$busy++;
	}
}
if ( $items && $busy === count( $items ) ) {
	fail( 'Outlook geeft alleen "bezet/vrij" door. Zet bij Agenda publiceren de machtiging op "Kan alle details weergeven".' );
}

$types = array( 'vakantie', 'vrij', 'toets', 'uitslag', 'gesprek', 'groep8', 'activiteit', 'rooster', 'agenda' );

function detect_type( $title ) {
	$t = ' ' . mb_strtolower( $title ) . ' ';
	$rules = array(
		'vakantie'   => array( 'vakantie' ),
		'uitslag'    => array( 'uitslag', 'diplom' ),
		'vrij'       => array( 'lesvrij', 'roostervrij', 'leerlingen vrij', 'lln vrij', 'studiedag', 'paasdag', 'pinksterdag', 'goede vrijdag', 'koningsdag', 'bevrijdingsdag', 'hemelvaart' ),
		'groep8'     => array( 'meeloop', 'open avond', 'open huis', 'open dag', 'kennismaking' ),
		'gesprek'    => array( 'ouderavond', 'oudermiddag', 'gesprek', 'profielenavond', 'beroepenavond', 'informatieavond', 'voorlichting' ),
		'toets'      => array( 'toets', 'se1', 'se2', 'se3', ' se ', 'examen', 'afname', 'mondeling', 'tentamen' ),
		'rooster'    => array( 'rooster', 'ontwikkelweek', 'leerlingbespreking' ),
		'activiteit' => array( 'gala', 'feest', 'lunch', 'ontbijt', 'halloween', 'reis', 'kamp', 'activiteit', 'excursie', 'startweek', 'sportdag', 'musical', 'week' ),
	);
	foreach ( $rules as $type => $words ) {
		foreach ( $words as $w ) {
			if ( false !== strpos( $t, $w ) ) {
				return $type;
			}
		}
	}
	return 'agenda';
}

$clean = array();
foreach ( $items as $i ) {
	// Icoon: Outlook-categorie met de naam van een icoontype gaat voor, anders op basis van de titel.
	$type = '';
	foreach ( $i['categories'] as $cat ) {
		$c = strtolower( trim( $cat ) );
		if ( in_array( $c, $types, true ) ) {
			$type = $c;
		}
	}
	$clean[] = array(
		'start'       => $i['start'],
		'end'         => $i['end'],
		'start_time'  => $i['start_time'],
		'end_time'    => $i['end_time'],
		'title'       => $i['title'],
		'location'    => $i['location'],
		'description' => $i['description'],
		'type'        => $type ? $type : detect_type( $i['title'] ),
	);
}

// "updated" alleen aanpassen als de inhoud echt veranderd is (dan geen onnodige opslag in GitHub).
$updated = ( new DateTimeImmutable( 'now', $tz ) )->format( 'c' );
if ( is_file( $out ) ) {
	$old = json_decode( (string) file_get_contents( $out ), true );
	if ( is_array( $old ) && isset( $old['items'] ) && $old['items'] === $clean && ! empty( $old['updated'] ) ) {
		$updated = $old['updated'];
	}
}

$json = json_encode(
	array(
		'updated' => $updated,
		'items'   => $clean,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ( false === $json ) {
	fail( 'Kon agenda.json niet maken.' );
}
file_put_contents( $out, $json . "\n" );
echo count( $clean ) . " activiteiten geschreven naar $out\n";
