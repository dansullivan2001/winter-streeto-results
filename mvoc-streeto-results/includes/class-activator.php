<?php
/**
 * Activation and deactivation routines.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and the co-ordinator role on activation.
 */
class Activator {

	public const ROLE = 'mvoc_league_coordinator';

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		Schema::install();
		self::add_role();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Deliberately does NOT drop tables or remove results: deactivating a
	 * plugin must never destroy a season's league data. Removal belongs in
	 * uninstall.php, behind an explicit opt-in.
	 */
	public static function deactivate(): void {
		// Nothing to tear down; scheduled events would be cleared here.
	}

	/**
	 * Create the League Co-ordinator role and grant the capability to admins.
	 */
	private static function add_role(): void {
		add_role(
			self::ROLE,
			__( 'League Co-ordinator', 'mvoc-streeto' ),
			array(
				'read'               => true,
				Plugin::CAPABILITY   => true,
			)
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( Plugin::CAPABILITY );
		}
	}
}
