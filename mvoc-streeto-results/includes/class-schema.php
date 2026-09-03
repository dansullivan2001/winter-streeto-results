<?php
/**
 * Database schema and migrations.
 *
 * The design keeps three layers strictly separate:
 *
 *   raw       - `fetches` holds every MapRun response verbatim, never edited.
 *   overrides - `overrides` holds corrections as their own rows.
 *   computed  - event and league tables are derived from raw + overrides.
 *
 * This is what lets the co-ordinator re-fetch an event without losing the
 * corrections they have already made, and lets any published number be traced
 * back to the MapRun response it came from.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

defined( 'ABSPATH' ) || exit;

/**
 * Table definitions, created and migrated with dbDelta.
 */
class Schema {

	/**
	 * Bumped whenever a table definition changes.
	 *
	 * 2: penalty columns on results, organiser on events, result_competitors
	 *    join table — all needed once the scoring rules were pinned down.
	 * 3: competitors.year_of_birth (written by the repo since M3 but never
	 *    added here), plus results.is_withdrawn and events.published_at.
	 *    resolved_penalty becomes nullable so that a penalty corrected *to*
	 *    zero is distinguishable from no correction at all.
	 * 4: results.raw_year_of_birth. Without it the Over-55 category could not
	 *    be derived when creating a competitor from an already-imported row,
	 *    because MapRun's YearOfBirth was parsed and then thrown away.
	 */
	public const DB_VERSION = 4;

	public const OPTION_DB_VERSION = 'mvoc_streeto_db_version';

	/**
	 * Table name (without prefix) => short description.
	 */
	private const TABLES = array(
		'series',
		'events',
		'event_sources',
		'fetches',
		'competitors',
		'aliases',
		'results',
		'result_competitors',
		'overrides',
	);

	/**
	 * Fully qualified table name for a logical table.
	 *
	 * @param string $table Logical name, e.g. 'results'.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . 'mvoc_so_' . $table;
	}

	/**
	 * Create or migrate all tables.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Run migrations if the stored version is behind the code.
	 *
	 * Called on every load so that updating the plugin files is enough; the
	 * co-ordinator never has to remember to deactivate and reactivate.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * All CREATE TABLE statements, in dbDelta's required format.
	 *
	 * dbDelta is fussy: two spaces after PRIMARY KEY, one field per line, and
	 * KEY names must be present. Reformatting this casually will break it.
	 *
	 * @return string[]
	 */
	private static function definitions(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$sql     = array();

		// A season, e.g. "Winter StreetO 2026-27", holding its scoring config.
		$table = self::table( 'series' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			scoring_config longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		// One club event, typically offering both a 60 and a 45 minute course.
		$table = self::table( 'events' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			series_id bigint(20) unsigned NOT NULL,
			event_number smallint(5) unsigned NOT NULL,
			title varchar(255) NOT NULL,
			event_date date NULL,
			venue varchar(255) NOT NULL DEFAULT '',
			organiser_competitor_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			last_fetched_at datetime NULL,
			published_at datetime NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY series_id (series_id),
			UNIQUE KEY series_event (series_id,event_number)
		) {$charset};";

		// One row per MapRun event feeding a club event. Two rows where the 60
		// and 45 are separate MapRun events; one row where they share an event.
		$table = self::table( 'event_sources' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			maprun_event_name varchar(255) NOT NULL,
			course_label varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id)
		) {$charset};";

		// Immutable snapshot of a MapRun response. Never updated, only inserted.
		$table = self::table( 'fetches' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_source_id bigint(20) unsigned NOT NULL,
			payload longtext NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'http',
			row_count int(10) unsigned NOT NULL DEFAULT 0,
			fetched_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			fetched_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY event_source_id (event_source_id)
		) {$charset};";

		// Canonical competitor identity across the whole series.
		$table = self::table( 'competitors' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			first_name varchar(100) NOT NULL DEFAULT '',
			surname varchar(100) NOT NULL DEFAULT '',
			display_name varchar(255) NOT NULL,
			club varchar(100) NOT NULL DEFAULT '',
			year_of_birth smallint(5) unsigned NULL,
			is_female tinyint(1) NOT NULL DEFAULT 0,
			is_over55 tinyint(1) NOT NULL DEFAULT 0,
			notes text NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY surname (surname)
		) {$charset};";

		// Confirmed name variants. Grows as the co-ordinator resolves names, so
		// each spelling costs one click once rather than every month.
		$table = self::table( 'aliases' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competitor_id bigint(20) unsigned NOT NULL,
			normalised_name varchar(255) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY competitor_id (competitor_id),
			UNIQUE KEY normalised_name (normalised_name)
		) {$charset};";

		// One parsed result row. Raw columns hold what MapRun said; resolved
		// columns hold what will be published after any override is applied.
		$table = self::table( 'results' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			event_source_id bigint(20) unsigned NOT NULL,
			fetch_id bigint(20) unsigned NOT NULL,
			maprun_id varchar(64) NOT NULL DEFAULT '',
			competitor_id bigint(20) unsigned NULL,
			raw_first_name varchar(100) NOT NULL DEFAULT '',
			raw_surname varchar(100) NOT NULL DEFAULT '',
			raw_club varchar(100) NOT NULL DEFAULT '',
			raw_gender varchar(10) NOT NULL DEFAULT '',
			raw_year_of_birth smallint(5) unsigned NULL,
			classifier varchar(20) NOT NULL DEFAULT '',
			course_label varchar(20) NOT NULL DEFAULT '',
			raw_score int(11) NULL,
			raw_penalty int(11) NOT NULL DEFAULT 0,
			raw_time_secs int(10) unsigned NULL,
			resolved_score int(11) NULL,
			resolved_penalty int(11) NULL,
			resolved_time_secs int(10) unsigned NULL,
			resolved_course_label varchar(20) NOT NULL DEFAULT '',
			is_excluded tinyint(1) NOT NULL DEFAULT 0,
			is_manual tinyint(1) NOT NULL DEFAULT 0,
			is_withdrawn tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY competitor_id (competitor_id),
			KEY maprun_id (maprun_id)
		) {$charset};";

		// A result normally belongs to one competitor, recorded on results.
		// competitor_id. This table covers the rare shared-map case, where two
		// people run one map and both take the row's league points. Modelling
		// it as a relationship keeps the scoring engine from special-casing
		// pairs, and the co-ordinator establishes the link explicitly rather
		// than it being guessed from an "&" in the name.
		$table = self::table( 'result_competitors' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			result_id bigint(20) unsigned NOT NULL,
			competitor_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY result_id (result_id),
			KEY competitor_id (competitor_id),
			UNIQUE KEY result_competitor (result_id,competitor_id)
		) {$charset};";

		// An audit trail of corrections. Kept separate from `results` so that a
		// re-fetch can rebuild the raw columns and replay these on top.
		$table = self::table( 'overrides' );
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			result_id bigint(20) unsigned NOT NULL,
			field varchar(50) NOT NULL,
			new_value text NULL,
			reason varchar(255) NOT NULL DEFAULT '',
			author_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY result_id (result_id)
		) {$charset};";

		return $sql;
	}

	/**
	 * Logical table names, for uninstall.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return self::TABLES;
	}
}
