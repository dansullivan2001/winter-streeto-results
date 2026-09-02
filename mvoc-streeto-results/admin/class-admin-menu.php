<?php
/**
 * Admin menu registration.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the StreetO Results menu.
 */
class Admin_Menu {

	public const SLUG = 'mvoc-streeto';

	/**
	 * Hook into the admin menu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	}

	/**
	 * Register menu and submenu pages.
	 */
	public function add_pages(): void {
		$explorer = new MapRun_Explorer_Screen();

		add_menu_page(
			__( 'StreetO Results', 'mvoc-streeto' ),
			__( 'StreetO Results', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $explorer, 'render' ),
			'dashicons-list-view',
			30
		);

		add_submenu_page(
			self::SLUG,
			__( 'MapRun Explorer', 'mvoc-streeto' ),
			__( 'MapRun Explorer', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $explorer, 'render' )
		);
	}
}
