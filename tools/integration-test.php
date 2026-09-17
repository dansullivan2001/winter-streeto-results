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

use MVOC\StreetO\Domain\Scoring_Config;
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
foreach ( array( 'raw_is_over55', 'is_withdrawn', 'resolved_penalty', 'score_source' ) as $column ) {
	check( "results.$column exists", in_array( $column, $rcols, true ) );
}

// dbDelta never drops a column, so a retired one has to be removed explicitly.
// If that failed, the data would still be there while the code claimed it was
// not - which is the whole point of removing it.
foreach ( array( 'year_of_birth', 'is_over55' ) as $gone ) {
	check( "competitors.$gone dropped", ! in_array( $gone, $columns, true ) );
}
check( 'results.raw_year_of_birth dropped', ! in_array( 'raw_year_of_birth', $rcols, true ) );

foreach ( \MVOC\StreetO\Schema::table_names() as $name ) {
	$table = \MVOC\StreetO\Schema::table( $name );
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );
	$dob   = array_filter( $cols, fn( $c ) => false !== strpos( $c, 'birth' ) );
	check( "$name holds no date of birth", array() === $dob, implode( ', ', $dob ) );
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

$events_repo->save_sources( $event_id, array( '60' => 'X ScoreQ60' ) );
check( 'source saved', 1 === count( $events_repo->sources( $event_id ) ) );

// Clearing a name must actually clear it. It used to be silently ignored,
// because saving was additive and an empty box never reached the repository.
$events_repo->save_sources( $event_id, array( '60' => 'X ScoreQ60 renamed' ) );
$renamed = $events_repo->sources( $event_id );
check( 'renaming a source works', 'X ScoreQ60 renamed' === ( $renamed[0]['maprun_event_name'] ?? '' ) );

$events_repo->save_sources( $event_id, array( '60' => '' ) );
check( 'clearing a source removes it', 0 === count( $events_repo->sources( $event_id ) ) );

$events_repo->save_sources( $event_id, array( '60' => 'X ScoreQ60' ) );

// An event title must be clearable - it used to fall back to the stored value
// whenever the box was empty, so clearing one silently came back on every save.
$events_repo->save_event( $series_id, array( 'event_number' => 1, 'title' => '', 'venue' => '' ) );
$cleared = $events_repo->find_event( $series_id, 1 );
check( 'an event title can be cleared', '' === $cleared['title'], '"' . $cleared['title'] . '"' );
check( 'and it still has something to display', 'Event 1' === $cleared['label'], $cleared['label'] );

$events_repo->save_event( $series_id, array( 'event_number' => 1, 'title' => 'Test event', 'venue' => 'Test event' ) );
check( 'a title set again is used for display', 'Test event' === $events_repo->find_event( $series_id, 1 )['label'] );

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

// And a correction taken back off must fall back again. An empty Penalty box on
// the review screen writes this null, and nothing without a database can prove
// it arrives as one: a null written as an empty string or a zero would read back
// as a correction to nothing, which is the opposite of what was asked for.
$results_repo->override( $result_id, 'penalty', null, 'correction withdrawn' );
$cleared = Results_Repo::effective( $results_repo->for_event( $event_id )[0] );
check( 'a cleared penalty falls back again', 20 === $cleared['penalty'], var_export( $cleared['penalty'], true ) );
check( 'and clearing is itself recorded', 3 === count( $results_repo->overrides_for_event( $event_id ) ) );

echo "\nDeleting events\n";
check( 'delete refused while results exist', false === $events_repo->delete_event( $event_id ) );
check( 'result count seen', 1 === $events_repo->result_count( $event_id ) );

// While a result still exists, clearing its source must not orphan it - the
// name is the only record of where the result came from.
$guard = $events_repo->save_sources( $event_id, array( '60' => '' ) );
check( 'a source with results is not silently removed', array( '60' ) === $guard['kept'] );
check( 'and it is still there', 1 === count( $events_repo->sources( $event_id ) ) );

// Scoped to its event as well as its id, so a stale id from a form cannot
// reach across to a row the co-ordinator is not looking at.
$results_repo->delete_manual( $result_id, $event_id );
check( 'manual row removed', 0 === $events_repo->result_count( $event_id ) );

$wrong_event = $events_repo->save_event(
	$series_id,
	array( 'event_number' => 9, 'title' => 'Other event' )
);
$other_row = $results_repo->add_manual(
	$wrong_event,
	0,
	array( 'first_name' => 'Wrong', 'surname' => 'Event', 'score' => 100, 'course_label' => '60' )
);
$results_repo->delete_manual( $other_row, $event_id );
check(
	'a row belonging to another event is not deleted',
	1 === $events_repo->result_count( $wrong_event )
);
$results_repo->delete_manual( $other_row, $wrong_event );
check( 'and it goes when its own event is named', 0 === $events_repo->result_count( $wrong_event ) );
$events_repo->delete_event( $wrong_event );

check( 'delete allowed once empty', true === $events_repo->delete_event( $event_id ) );
check( 'event gone', null === $events_repo->find_event_by_id( $event_id ) );

$fetches = \MVOC\StreetO\Schema::table( 'fetches' );
$orphans = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM `$fetches` WHERE event_source_id = %d", $source_id )
);
check( 'snapshots cleared with the event', 0 === $orphans );
check( 'sources cleared with the event', 0 === count( $events_repo->sources( $event_id ) ) );

echo "\nPer-season categories\n";
$comp_repo = new Competitors_Repo();
$comp_id   = $comp_repo->create_with_alias(
	array( 'first_name' => 'Cat', 'surname' => 'Tester', 'is_female' => true ),
	'cat tester'
);
check( 'competitor created without a birth year', $comp_id > 0 );

$comp_repo->set_over55( $series_id, $comp_id, true );
$flags = $comp_repo->over55_for_series( $series_id );
check( 'flag stored for the season', ! empty( $flags[ $comp_id ] ) );

// The same person in another season must not inherit it.
$later_id = $events_repo->ensure_series( $slug . '-later', 'Later season' );
$later    = $comp_repo->over55_for_series( $later_id );
check( 'another season does not inherit the flag', empty( $later[ $comp_id ] ) );

$merged = array_column( $comp_repo->all_for_series( $series_id ), 'is_over55', 'id' );
check( 'flags merge into the competitor list', ! empty( $merged[ $comp_id ] ) );

$wpdb->delete( \MVOC\StreetO\Schema::table( 'series' ), array( 'id' => $later_id ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'competitors' ), array( 'id' => $comp_id ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'aliases' ), array( 'competitor_id' => $comp_id ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'series_competitors' ), array( 'competitor_id' => $comp_id ), array( '%d' ) );

echo "\nActive season\n";
$other_slug = 'itest2-' . wp_generate_password( 6, false, false );
$other_id   = $events_repo->ensure_series( $other_slug, 'Second integration series' );

$active = $events_repo->active_series();
check( 'an active season exists', null !== $active );
check(
	'a later season does not steal the flag',
	$active && (int) $active['id'] !== $other_id,
	'promoting next year early would swap the public site over before it starts'
);

$events_repo->set_active( $other_id );
$now_active = $events_repo->active_series();
check( 'promotion takes effect', $now_active && (int) $now_active['id'] === $other_id );

$actives = array_filter( $events_repo->all_series(), fn( $s ) => ! empty( $s['is_active'] ) );
check( 'exactly one season is active', 1 === count( $actives ), (string) count( $actives ) );

if ( $active ) {
	$events_repo->set_active( (int) $active['id'] );
}
$wpdb->delete( \MVOC\StreetO\Schema::table( 'series' ), array( 'id' => $other_id ), array( '%d' ) );

echo "\nScoring ladder migration\n";

// A series stores the whole scoring config, so one created before the ladder
// started at 100 keeps the old one until the migration moves it. Two series
// are set up with that old ladder — one active, one not — because the
// migration is supposed to touch only the season being run.
$retired_ladder = array();
for ( $position = 1; $position <= 50; $position++ ) {
	$retired_ladder[] = 51 - $position;
}

$old_ladder_config = (string) json_encode(
	array_merge( get_object_vars( new Scoring_Config() ), array( 'points_ladder' => $retired_ladder ) )
);

$running_slug   = 'itest3-' . wp_generate_password( 6, false, false );
$finished_slug  = 'itest4-' . wp_generate_password( 6, false, false );
$running_id     = $events_repo->ensure_series( $running_slug, 'Season being run' );
$finished_id    = $events_repo->ensure_series( $finished_slug, 'Season already published' );
$series_table   = \MVOC\StreetO\Schema::table( 'series' );

foreach ( array( $running_id, $finished_id ) as $id ) {
	$wpdb->update(
		$series_table,
		array( 'scoring_config' => $old_ladder_config ),
		array( 'id' => $id ),
		array( '%s' ),
		array( '%d' )
	);
}

$was_active = $events_repo->active_series();
$events_repo->set_active( $running_id );

\MVOC\StreetO\Schema::install();

$running_config  = $events_repo->scoring_config( $events_repo->find_series( $running_slug ) );
$finished_config = $events_repo->scoring_config( $events_repo->find_series( $finished_slug ) );

check(
	'the season being run moves onto the 100 ladder',
	100 === $running_config->points_for_position( 1 ),
	(string) $running_config->points_for_position( 1 )
);
check(
	'a season already published keeps the ladder it was published with',
	50 === $finished_config->points_for_position( 1 ),
	(string) $finished_config->points_for_position( 1 )
);

// Running it again must not undo anything: the migration only ever replaces a
// ladder that is exactly the retired default.
\MVOC\StreetO\Schema::install();
check(
	'a second upgrade leaves the migrated ladder alone',
	100 === $events_repo->scoring_config( $events_repo->find_series( $running_slug ) )->points_for_position( 1 )
);

if ( $was_active ) {
	$events_repo->set_active( (int) $was_active['id'] );
}

$wpdb->delete( $series_table, array( 'id' => $running_id ), array( '%d' ) );
$wpdb->delete( $series_table, array( 'id' => $finished_id ), array( '%d' ) );

echo "\nMerging competitors\n";

// The SQL merge() issues had never been executed anywhere before these checks.
// It re-points every table referencing a competitor and then deletes the
// absorbed record, so a table it forgets is left pointing at an id that no
// longer resolves — silently, because nothing joins back to complain.
//
// event_organisers was forgotten exactly that way: the organiser vanished from
// the published event table and lost their league bonus, with no error raised.
// Three of these tables also carry a unique key on (something, competitor_id),
// so where both records already hold a row for the same season, event or
// result, a blind UPDATE violates the key and fails without saying so. Both
// hazards are set up deliberately below.
$merge_slug  = 'itest5-' . wp_generate_password( 6, false, false );
$merge_serie = $events_repo->ensure_series( $merge_slug, 'Merge test series' );
$merge_event = $events_repo->save_event(
	$merge_serie,
	array( 'event_number' => 1, 'title' => 'Merge test event' )
);

$keep_id   = $comp_repo->create_with_alias(
	array( 'first_name' => 'David', 'surname' => 'Mergeton' ),
	'david mergeton'
);
$absorb_id = $comp_repo->create_with_alias(
	array( 'first_name' => 'Dave', 'surname' => 'Mergeton' ),
	'dave mergeton'
);
check( 'two competitors to merge', $keep_id > 0 && $absorb_id > 0 && $keep_id !== $absorb_id );

// Both in the same season, both organising the same event, both on the same
// result: every unique key that could collide, does.
$comp_repo->set_over55( $merge_serie, $keep_id, false );
$comp_repo->set_over55( $merge_serie, $absorb_id, true );
$events_repo->save_organisers( $merge_event, array( $keep_id, $absorb_id ) );
check( 'both organise the event', 2 === count( $events_repo->organisers( $merge_event ) ) );

$merge_row = $results_repo->add_manual(
	$merge_event,
	0,
	array( 'first_name' => 'Dave', 'surname' => 'Mergeton', 'score' => 300, 'course_label' => '60' )
);
$results_repo->override( $merge_row, 'competitor', $absorb_id, 'integration test' );

// Nothing in the plugin writes result_competitors yet — it is reserved for the
// shared-map case, see docs/roadmap.md — so it is populated directly here. The
// point is that merge() must already cope if it ever is.
$rc_table = \MVOC\StreetO\Schema::table( 'result_competitors' );
foreach ( array( $keep_id, $absorb_id ) as $linked ) {
	$wpdb->insert( $rc_table, array( 'result_id' => $merge_row, 'competitor_id' => $linked ), array( '%d', '%d' ) );
}
check(
	'both are linked to the same result',
	2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$rc_table` WHERE result_id = %d", $merge_row ) )
);

$comp_repo->merge( $absorb_id, $keep_id );

check( 'the absorbed competitor is gone', null === $wpdb->get_var(
	$wpdb->prepare(
		'SELECT id FROM `' . \MVOC\StreetO\Schema::table( 'competitors' ) . '` WHERE id = %d',
		$absorb_id
	)
) );

// The defect that shipped: organiser credit must survive the merge.
$after_organisers = $events_repo->organisers( $merge_event );
check(
	'the organiser survives the merge',
	array( $keep_id ) === $after_organisers,
	'got ' . implode( ',', $after_organisers )
);

$aliases_after = $comp_repo->aliases();
check(
	'both spellings now resolve to the survivor',
	( $aliases_after['david mergeton'] ?? 0 ) === $keep_id
		&& ( $aliases_after['dave mergeton'] ?? 0 ) === $keep_id
);

$merged_row = $results_repo->for_event( $merge_event );
check(
	'the result follows the survivor',
	$keep_id === (int) ( $merged_row[0]['competitor_id'] ?? 0 ),
	var_export( $merged_row[0]['competitor_id'] ?? null, true )
);

// Collisions resolved rather than silently failing: one row each, on the
// survivor, in all three uniquely-keyed tables.
$sc_table = \MVOC\StreetO\Schema::table( 'series_competitors' );
check(
	'one season category row remains, on the survivor',
	array( (string) $keep_id ) === $wpdb->get_col(
		$wpdb->prepare( "SELECT competitor_id FROM `$sc_table` WHERE series_id = %d", $merge_serie )
	)
);
check(
	'one result link remains, on the survivor',
	array( (string) $keep_id ) === $wpdb->get_col(
		$wpdb->prepare( "SELECT competitor_id FROM `$rc_table` WHERE result_id = %d", $merge_row )
	)
);
check(
	'no rows anywhere still point at the absorbed competitor',
	0 === (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `$rc_table` WHERE competitor_id = %d", $absorb_id )
	) + (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `$sc_table` WHERE competitor_id = %d", $absorb_id )
	) + (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM `' . \MVOC\StreetO\Schema::table( 'event_organisers' ) . '` WHERE competitor_id = %d',
			$absorb_id
		)
	)
);

echo "\nDeleting an event with organisers\n";

// delete_event() removes snapshots and sources before the event row. It used to
// leave event_organisers behind, which matters because a season is seeded with
// an organiser against each fixture months ahead — so deleting one that was
// never run is the normal case, not an edge one.
$org_event = $events_repo->save_event(
	$merge_serie,
	array( 'event_number' => 2, 'title' => 'Organiser cleanup' )
);
$events_repo->save_organisers( $org_event, array( $keep_id ) );
check( 'organiser assigned to a fixture', 1 === count( $events_repo->organisers( $org_event ) ) );

check( 'the fixture deletes', true === $events_repo->delete_event( $org_event ) );
check(
	'its organiser rows go with it',
	0 === (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM `' . \MVOC\StreetO\Schema::table( 'event_organisers' ) . '` WHERE event_id = %d',
			$org_event
		)
	)
);

// Clean up the merge fixtures.
$wpdb->delete( \MVOC\StreetO\Schema::table( 'overrides' ), array( 'result_id' => $merge_row ), array( '%d' ) );
$results_repo->delete_manual( $merge_row, $merge_event );
$wpdb->delete( $rc_table, array( 'result_id' => $merge_row ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'results' ), array( 'event_id' => $merge_event ), array( '%d' ) );
$events_repo->delete_event( $merge_event );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'aliases' ), array( 'competitor_id' => $keep_id ), array( '%d' ) );
$wpdb->delete( $sc_table, array( 'competitor_id' => $keep_id ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'competitors' ), array( 'id' => $keep_id ), array( '%d' ) );
$wpdb->delete( \MVOC\StreetO\Schema::table( 'series' ), array( 'id' => $merge_serie ), array( '%d' ) );

echo "\nSeason derivation\n";
check( 'slug matches the live series format', '2026-27' === Season::slug( 2026 ) );
check( 'fixtures land on third Tuesdays', '2026-09-15' === Season::fixtures( 2026 )[0]['event_date'] );

// Clean up.
$wpdb->delete( \MVOC\StreetO\Schema::table( 'series' ), array( 'id' => $series_id ), array( '%d' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
