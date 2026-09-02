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

global $wpdb;

$mvoc_streeto_tables = array(
	'overrides',
	'result_competitors',
	'results',
	'aliases',
	'competitors',
	'fetches',
	'event_sources',
	'events',
	'series',
);

foreach ( $mvoc_streeto_tables as $mvoc_streeto_table ) {
	$mvoc_streeto_name = $wpdb->prefix . 'mvoc_so_' . $mvoc_streeto_table;
	// Table names cannot be bound as parameters; the list above is a hard-coded
	// constant, so there is no untrusted input in this statement.
	$wpdb->query( "DROP TABLE IF EXISTS `{$mvoc_streeto_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

delete_option( 'mvoc_streeto_db_version' );
delete_option( 'mvoc_streeto_delete_data_on_uninstall' );

remove_role( 'mvoc_league_coordinator' );
