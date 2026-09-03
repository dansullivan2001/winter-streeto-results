<?php
/**
 * Integration smoke test against a real WordPress and database.
 *
 * Usage: php tools/integration-test.php ~/Local\ Sites/mvoc/app/public
 *
 * Creates a throwaway series, exercises the persistence layer against it, and
 * deletes it again. Safe to run repeatedly; it touches nothing pre-existing.
 *
 * The unit tests cover the domain layer thoroughly and touch no database at
 * all, which is exactly why three bugs got through: a column the repo wrote but
 * the schema lacked, a constant removed with a caller left behind, and an id
 * encoded into a form value that sanitize_key quietly mangled. None of them
 * were visible without WordPress actually running.
 */

$site = $argv[1] ?? getenv( 'WP_SITE_PATH' );

if ( ! $site || ! is_readable( $site . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tools/integration-test.php /path/to/wordpress\n" );
	fwrite( STDERR, "For Local, that is ~/Local Sites/<site>/app/public\n" );
	exit( 2 );
}

// Local serves MySQL over a unix socket. wp-config says "localhost", which the
// web server resolves but CLI PHP does not, so point it at the socket first -
// define() keeps the first value, so wp-config's own define becomes a no-op.
$socket = getenv( 'WP_DB_SOCKET' ) ?: current(
	glob( getenv( 'HOME' ) . '/Library/Application Support/Local/run/*/mysql/mysqld.sock' ) ?: array()
);

if ( $socket ) {
	define( 'DB_HOST', 'localhost:' . $socket );
}

$GLOBALS['table_prefix'] = 'wp_';
define( 'WP_USE_THEMES', false );
require $site . '/wp-load.php';

use MVOC\StreetO\Domain\Season;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

$pass = 0;
$fail = 0;

function check( string $what, bool $ok, string $detail = '' ): void {
	global $pass, $fail;

	if ( $ok ) {
		++$pass;
		printf( "  ok    %s\n", $what );
		return;
	}

	++$fail;
	printf( "  FAIL  %s%s\n", $what, $detail ? " — $detail" : '' );
}

global $wpdb;

echo "Schema\n";
foreach ( \MVOC\StreetO\Schema::table_names() as $name ) {
	$table = \MVOC\StreetO\Schema::table( $name );
	check( "table $name exists", (bool) $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) );
}

// Every column a repo declares it writes must really be there.
$competitors = \MVOC\StreetO\Schema::table( 'competitors' );
$columns     = $wpdb->get_col( "SHOW COLUMNS FROM `$competitors`" );
foreach ( Competitors_Repo::COLUMNS as $column ) {
	check( "competitors.$column exists", in_array( $column, $columns, true ) );
}

$results = \MVOC\StreetO\Schema::table( 'results' );
$rcols   = $wpdb->get_col( "SHOW COLUMNS FROM `$results`" );
foreach ( array( 'raw_year_of_birth', 'is_withdrawn', 'resolved_penalty' ) as $column ) {
	check( "results.$column exists", in_array( $column, $rcols, true ) );
}

echo "\nSeries and events\n";
$events_repo = new Events_Repo();
$slug        = 'itest-' . wp_generate_password( 6, false, false );
$series_id   = $events_repo->ensure_series( $slug, 'Integration test series' );
check( 'series created', $series_id > 0 );

$event_id = $events_repo->save_event(
	$series_id,
	array( 'event_number' => 1, 'title' => 'Test event', 'event_date' => '2026-09-15' )
);
check( 'event created', $event_id > 0 );

$events_repo->save_sources( $event_id, array( array( 'maprun_event_name' => 'X ScoreQ60', 'course_label' => '60' ) ) );
check( 'source saved', 1 === count( $events_repo->sources( $event_id ) ) );

echo "\nManual rows\n";
$results_repo = new Results_Repo();
$source_id    = (int) $events_repo->sources( $event_id )[0]['id'];
$result_id    = $results_repo->add_manual(
	$event_id,
	$source_id,
	array( 'first_name' => 'Test', 'surname' => 'Runner', 'score' => 500, 'penalty' => 20, 'course_label' => '60' )
);
check( 'manual row added', $result_id > 0 );

$row = $results_repo->for_event( $event_id )[0] ?? array();
check( 'manual row reads back', ! empty( $row ) && 'Runner' === $row['raw_surname'] );
check( 'manual flag set', ! empty( $row['is_manual'] ) );

$effective = Results_Repo::effective( $row );
check( 'penalty survives', 20 === $effective['penalty'], var_export( $effective['penalty'], true ) );

echo "\nOverrides\n";
$results_repo->override( $result_id, 'score', 640, 'integration test' );
$after = Results_Repo::effective( $results_repo->for_event( $event_id )[0] );
check( 'override applied', 640 === $after['score'], var_export( $after['score'], true ) );
check( 'override recorded', 1 === count( $results_repo->overrides_for_event( $event_id ) ) );

// A penalty corrected *to* zero must stick rather than fall back to raw.
$results_repo->override( $result_id, 'penalty', 0, 'penalty removed' );
$zeroed = Results_Repo::effective( $results_repo->for_event( $event_id )[0] );
check( 'penalty corrected to zero sticks', 0 === $zeroed['penalty'], var_export( $zeroed['penalty'], true ) );

echo "\nDeleting events\n";
check( 'delete refused while results exist', false === $events_repo->delete_event( $event_id ) );
check( 'result count seen', 1 === $events_repo->result_count( $event_id ) );

$results_repo->delete_manual( $result_id );
check( 'manual row removed', 0 === $events_repo->result_count( $event_id ) );
check( 'delete allowed once empty', true === $events_repo->delete_event( $event_id ) );
check( 'event gone', null === $events_repo->find_event_by_id( $event_id ) );

$fetches = \MVOC\StreetO\Schema::table( 'fetches' );
$orphans = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM `$fetches` WHERE event_source_id = %d", $source_id )
);
check( 'snapshots cleared with the event', 0 === $orphans );
check( 'sources cleared with the event', 0 === count( $events_repo->sources( $event_id ) ) );

echo "\nSeason derivation\n";
check( 'slug matches the live series format', '2026-27' === Season::slug( 2026 ) );
check( 'fixtures land on third Tuesdays', '2026-09-15' === Season::fixtures( 2026 )[0]['event_date'] );

// Clean up.
$wpdb->delete( \MVOC\StreetO\Schema::table( 'series' ), array( 'id' => $series_id ), array( '%d' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
