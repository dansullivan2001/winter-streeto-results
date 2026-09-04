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
		$events = new Events_Screen();

		// Series and events is the landing page: it is where a season starts
		// and where every event's results are reached from.
		add_menu_page(
			__( 'StreetO Results', 'mvoc-streeto' ),
			__( 'StreetO Results', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $events, 'render' ),
			'dashicons-list-view',
			30
		);

		add_submenu_page(
			self::SLUG,
			__( 'Series and events', 'mvoc-streeto' ),
			__( 'Series and events', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $events, 'render' )
		);

		$review = new Event_Review_Screen();

		add_submenu_page(
			self::SLUG,
			__( 'Event results', 'mvoc-streeto' ),
			__( 'Event results', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG . '-review',
			array( $review, 'render' )
		);

		$unmatched = new Unmatched_Screen();

		add_submenu_page(
			self::SLUG,
			__( 'Confirm names', 'mvoc-streeto' ),
			__( 'Confirm names', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG . '-names',
			array( $unmatched, 'render' )
		);

		$competitors = new Competitors_Screen();

		add_submenu_page(
			self::SLUG,
			__( 'Competitors', 'mvoc-streeto' ),
			__( 'Competitors', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG . '-competitors',
			array( $competitors, 'render' )
		);

		// Last in the menu: a setup and diagnostic tool rather than daily work.
		add_submenu_page(
			self::SLUG,
			__( 'MapRun Explorer', 'mvoc-streeto' ),
			__( 'MapRun Explorer', 'mvoc-streeto' ),
			Plugin::CAPABILITY,
			self::SLUG . '-explorer',
			array( new MapRun_Explorer_Screen(), 'render' )
		);

		// Capability is 'manage_options' rather than Plugin::CAPABILITY: a
		// League Co-ordinator must never see the full data scrub below.
		add_submenu_page(
			self::SLUG,
			__( 'Tools', 'mvoc-streeto' ),
			__( 'Tools', 'mvoc-streeto' ),
			'manage_options',
			self::SLUG . '-tools',
			array( new Tools_Screen(), 'render' )
		);
	}
}
