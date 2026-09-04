<?php
/**
 * Persistence for series, events and their MapRun sources.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Repo;

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\League_Cache;
use MVOC\StreetO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes series, events and event_sources.
 */
class Events_Repo {

	/**
	 * Columns written to the events table.
	 *
	 * @var string[]
	 */
	public const EVENT_COLUMNS = array(
		'series_id',
		'event_number',
		'title',
		'event_date',
		'venue',
		'organiser_competitor_id',
		'status',
	);

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';

	/**
	 * An event that will not take place.
	 *
	 * Kept rather than deleted, which is what the club's own workbook did with
	 * the two events cancelled in 2019/20: the series keeps its shape, event
	 * numbering stays stable, and nobody has to wonder later why the season
	 * jumps from 3 to 5.
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Find a series by slug.
	 *
	 * @param string $slug Series slug.
	 * @return array<string,mixed>|null
	 */
	public function find_series( string $slug ): ?array {
		global $wpdb;

		$table = Schema::table( 'series' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE slug = %s", $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['id'] = (int) $row['id'];

		return $row;
	}

	/**
	 * Every series, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_series(): array {
		global $wpdb;

		$table = Schema::table( 'series' );
		$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		return array_map(
			static function ( array $row ): array {
				$row['id'] = (int) $row['id'];
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * The season currently being run, if one is marked.
	 *
	 * @return array<string,mixed>|null
	 */
	public function active_series(): ?array {
		global $wpdb;

		$table = Schema::table( 'series' );
		$row   = $wpdb->get_row( "SELECT * FROM `{$table}` WHERE is_active = 1 LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		if ( ! $row ) {
			return null;
		}

		$row['id'] = (int) $row['id'];

		return $row;
	}

	/**
	 * Mark one series as the season being run, clearing any other.
	 *
	 * Exactly one is active, so a shortcode with no series attribute has an
	 * unambiguous answer.
	 *
	 * @param int $series_id Series id.
	 */
	public function set_active( int $series_id ): void {
		global $wpdb;

		$table = Schema::table( 'series' );

		$wpdb->query( "UPDATE `{$table}` SET is_active = 0 WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'is_active' => 1 ),
			array( 'id' => $series_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Create a series if its slug is new, and return its id either way.
	 *
	 * @param string              $slug   Series slug.
	 * @param string              $name   Display name.
	 * @param Scoring_Config|null $config Scoring rules; defaults are used when omitted.
	 */
	public function ensure_series( string $slug, string $name, ?Scoring_Config $config = null ): int {
		$existing = $this->find_series( $slug );
		if ( $existing ) {
			return $existing['id'];
		}

		global $wpdb;

		// The first series created becomes the active one, so a fresh install
		// works without a second step. A later season does not steal the flag:
		// next year's league should not replace the live one on the public site
		// months before it starts, so promoting it stays deliberate.
		$first = null === $this->active_series();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'series' ),
			array(
				'slug'           => $slug,
				'name'           => $name,
				'scoring_config' => ( $config ?? new Scoring_Config() )->to_json(),
				'is_active'      => $first ? 1 : 0,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The scoring rules for a series.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	public function scoring_config( array $series ): Scoring_Config {
		$json = (string) ( $series['scoring_config'] ?? '' );

		return '' === $json ? new Scoring_Config() : Scoring_Config::from_json( $json );
	}

	/**
	 * Store a series' scoring rules.
	 *
	 * @param int            $series_id Series id.
	 * @param Scoring_Config $config    Scoring rules.
	 */
	public function save_scoring_config( int $series_id, Scoring_Config $config ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'series' ),
			array( 'scoring_config' => $config->to_json() ),
			array( 'id' => $series_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Every event in a series, in running order.
	 *
	 * @param int $series_id Series id.
	 * @return array<int,array<string,mixed>>
	 */
	public function events( int $series_id ): array {
		global $wpdb;

		$table = Schema::table( 'events' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE series_id = %d ORDER BY event_number", $series_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_event' ), $rows ?: array() );
	}

	/**
	 * One event by series and number.
	 *
	 * @param int $series_id    Series id.
	 * @param int $event_number Event number within the series.
	 * @return array<string,mixed>|null
	 */
	public function find_event( int $series_id, int $event_number ): ?array {
		global $wpdb;

		$table = Schema::table( 'events' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE series_id = %d AND event_number = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$series_id,
				$event_number
			),
			ARRAY_A
		);

		return $row ? $this->hydrate_event( $row ) : null;
	}

	/**
	 * One event by id.
	 *
	 * @param int $event_id Event id.
	 * @return array<string,mixed>|null
	 */
	public function find_event_by_id( int $event_id ): ?array {
		global $wpdb;

		$table = Schema::table( 'events' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $this->hydrate_event( $row ) : null;
	}

	/**
	 * Cast an event row to the types the rest of the plugin expects.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function hydrate_event( array $row ): array {
		$row['id']                      = (int) $row['id'];
		$row['series_id']               = (int) $row['series_id'];
		$row['event_number']            = (int) $row['event_number'];
		$row['organiser_competitor_id'] = $row['organiser_competitor_id']
			? (int) $row['organiser_competitor_id']
			: null;
		$row['is_published']            = self::STATUS_PUBLISHED === $row['status'];

		// A title may legitimately be empty - clearing one has to be possible -
		// so everywhere that needs something to print gets a fallback rather
		// than a blank league column heading or an empty page title.
		$row['label'] = '' !== trim( (string) $row['title'] )
			? $row['title']
			: sprintf(
				/* translators: %d: event number within the series. */
				__( 'Event %d', 'mvoc-streeto' ),
				(int) $row['event_number']
			);
		$row['is_cancelled']            = self::STATUS_CANCELLED === $row['status'];

		return $row;
	}

	/**
	 * Create or update an event, returning its id.
	 *
	 * @param int                 $series_id Series id.
	 * @param array<string,mixed> $event     Event fields, including event_number.
	 */
	public function save_event( int $series_id, array $event ): int {
		global $wpdb;

		$number   = (int) ( $event['event_number'] ?? 0 );
		$existing = $this->find_event( $series_id, $number );

		$values = array(
			'series_id'               => $series_id,
			'event_number'            => $number,
			'title'                   => (string) ( $event['title'] ?? '' ),
			'event_date'              => ( $event['event_date'] ?? '' ) ?: null,
			'venue'                   => (string) ( $event['venue'] ?? '' ),
			'organiser_competitor_id' => ( $event['organiser_competitor_id'] ?? 0 ) ?: null,
			'status'                  => (string) ( $event['status'] ?? self::STATUS_DRAFT ),
		);

		$formats = array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' );

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::table( 'events' ),
				$values,
				array( 'id' => $existing['id'] ),
				$formats,
				array( '%d' )
			);

			League_Cache::bump();

			return $existing['id'];
		}

		$wpdb->insert( Schema::table( 'events' ), $values, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		League_Cache::bump();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Publish an event, stamping the time the cache is keyed on.
	 *
	 * @param int $event_id Event id.
	 */
	public function publish( int $event_id ): void {
		$this->set_status( $event_id, self::STATUS_PUBLISHED, current_time( 'mysql' ) );
	}

	/**
	 * Return an event to draft, taking it off the public page.
	 *
	 * @param int $event_id Event id.
	 */
	public function unpublish( int $event_id ): void {
		$this->set_status( $event_id, self::STATUS_DRAFT, null );
	}

	/**
	 * @param int         $event_id     Event id.
	 * @param string      $status       New status.
	 * @param string|null $published_at Publish timestamp, or null to clear.
	 */
	private function set_status( int $event_id, string $status, ?string $published_at ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'events' ),
			array(
				'status'       => $status,
				'published_at' => $published_at,
			),
			array( 'id' => $event_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		League_Cache::bump();
	}

	/**
	 * Record that an event has just been fetched.
	 *
	 * @param int $event_id Event id.
	 */
	public function touch_fetched( int $event_id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'events' ),
			array( 'last_fetched_at' => current_time( 'mysql' ) ),
			array( 'id' => $event_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * How many result rows an event holds.
	 *
	 * @param int $event_id Event id.
	 */
	public function result_count( int $event_id ): int {
		global $wpdb;

		$table = Schema::table( 'results' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_id = %d", $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Delete an event, but only while it holds no results.
	 *
	 * Deliberately refuses once anything has been imported. Removing an event
	 * with results would take the raw MapRun snapshots and the co-ordinator's
	 * corrections with it, and there is no undo — an event that will not run
	 * should be cancelled instead, which keeps the series' shape.
	 *
	 * @param int $event_id Event id.
	 * @return bool Whether it was deleted.
	 */
	public function delete_event( int $event_id ): bool {
		if ( $this->result_count( $event_id ) > 0 ) {
			return false;
		}

		global $wpdb;

		// Snapshots belong to sources, so they go before the sources do.
		$fetches = Schema::table( 'fetches' );
		$sources = Schema::table( 'event_sources' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM `{$fetches}` WHERE event_source_id IN
				 ( SELECT id FROM `{$sources}` WHERE event_id = %d )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event_id
			)
		);

		$wpdb->delete( $sources, array( 'event_id' => $event_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'events' ), array( 'id' => $event_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return true;
	}

	/**
	 * MapRun sources feeding an event, one per course.
	 *
	 * @param int $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	public function sources( int $event_id ): array {
		global $wpdb;

		$table = Schema::table( 'event_sources' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE event_id = %d ORDER BY course_label DESC", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']       = (int) $row['id'];
				$row['event_id'] = (int) $row['event_id'];
				return $row;
			},
			$rows ?: array()
		);
	}

	/**
	 * Set an event's MapRun sources to exactly what is given.
	 *
	 * Declarative rather than additive: a course whose name is submitted empty
	 * is removed, not skipped. It used to be additive, which meant clearing a
	 * mistyped name silently did nothing and the old one came back on reload.
	 *
	 * A source that already has results is kept even when cleared, because
	 * deleting it would orphan those rows from the event they were imported
	 * for, and the name is the only record of where they came from. The caller
	 * is told, so it can say so rather than appearing to ignore the edit.
	 *
	 * @param int                  $event_id Event id.
	 * @param array<string,string> $names    Course label => MapRun event name.
	 * @return array{saved:int,removed:int,kept:string[]} Courses kept despite being cleared.
	 */
	public function save_sources( int $event_id, array $names ): array {
		global $wpdb;

		$table   = Schema::table( 'event_sources' );
		$saved   = 0;
		$removed = 0;
		$kept    = array();

		$existing = array();
		foreach ( $this->sources( $event_id ) as $source ) {
			$existing[ (string) $source['course_label'] ] = $source;
		}

		foreach ( $names as $course => $name ) {
			$course = trim( (string) $course );
			$name   = trim( (string) $name );

			if ( '' === $course ) {
				continue;
			}

			$current = $existing[ $course ] ?? null;

			if ( '' === $name ) {
				if ( ! $current ) {
					continue;
				}

				if ( $this->source_result_count( (int) $current['id'] ) > 0 ) {
					$kept[] = $course;
					continue;
				}

				$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array( 'id' => (int) $current['id'] ),
					array( '%d' )
				);
				++$removed;
				continue;
			}

			if ( $current ) {
				if ( $current['maprun_event_name'] !== $name ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$table,
						array( 'maprun_event_name' => $name ),
						array( 'id' => (int) $current['id'] ),
						array( '%s' ),
						array( '%d' )
					);
					++$saved;
				}
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'event_id'          => $event_id,
					'maprun_event_name' => $name,
					'course_label'      => $course,
				),
				array( '%d', '%s', '%s' )
			);
			++$saved;
		}

		return array(
			'saved'   => $saved,
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	/**
	 * How many result rows were imported through one source.
	 *
	 * @param int $event_source_id Source id.
	 */
	public function source_result_count( int $event_source_id ): int {
		global $wpdb;

		$table = Schema::table( 'results' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE event_source_id = %d", $event_source_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Store a raw MapRun snapshot and return its id.
	 *
	 * @param int    $event_source_id Source id.
	 * @param string $payload         Raw JSON, verbatim.
	 * @param int    $row_count       Rows the payload contained.
	 * @param string $source          'http' or 'paste'.
	 */
	public function record_fetch( int $event_source_id, string $payload, int $row_count, string $source ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'fetches' ),
			array(
				'event_source_id' => $event_source_id,
				'payload'         => $payload,
				'source'          => $source,
				'row_count'       => $row_count,
				'fetched_by'      => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
