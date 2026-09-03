<?php
/**
 * Plugin bootstrap: wires up admin screens and front-end shortcodes.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
class Plugin {

	/**
	 * Capability required to manage the league.
	 *
	 * Granted to a dedicated "League Co-ordinator" role on activation, so the
	 * co-ordinator gets these screens without full site administration.
	 */
	public const CAPABILITY = 'mvoc_manage_streeto';

	/**
	 * Sole instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get (and on first call, build) the instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function boot(): void {
		Schema::maybe_upgrade();

		( new Front\Shortcodes() )->register();

		if ( is_admin() ) {
			( new Admin\Admin_Menu() )->register();
		}
	}
}
