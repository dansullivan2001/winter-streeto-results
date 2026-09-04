<?php
/**
 * Uninstall routine.
 *
 * Dropping the tables destroys every event result and the whole league, so it
 * happens only when the site owner has explicitly opted in by setting the
 * `mvoc_streeto_delete_data_on_uninstall` option. Deleting a plugin by
 * accident must not cost the club a season.
 *
 * @package MVOC_StreetO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'mvoc_streeto_delete_data_on_uninstall' ) ) {
	return;
}

// Loaded directly rather than through the autoloader: Schema has no
// dependencies of its own, and this keeps uninstall from needing the
// MVOC_STREETO_DIR constant the autoloader relies on, which is never
// defined in this minimal context.
require_once __DIR__ . '/includes/class-schema.php';

global $wpdb;

// Sourced from Schema::table_names() rather than duplicated here: a
// hard-coded copy previously drifted out of sync with the real schema and
// silently left series_competitors behind on every uninstall.
foreach ( \MVOC\StreetO\Schema::table_names() as $mvoc_streeto_table ) {
	$mvoc_streeto_name = \MVOC\StreetO\Schema::table( $mvoc_streeto_table );
	// Table names cannot be bound as parameters; Schema::table_names() only
	// ever returns this plugin's own hard-coded table list.
	$wpdb->query( "DROP TABLE IF EXISTS `{$mvoc_streeto_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'mvoc_streeto_db_version' );
delete_option( 'mvoc_streeto_delete_data_on_uninstall' );

remove_role( 'mvoc_league_coordinator' );
