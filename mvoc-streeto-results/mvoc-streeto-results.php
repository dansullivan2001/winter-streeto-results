<?php
/**
 * Plugin Name:       MVOC StreetO Results
 * Plugin URI:        https://github.com/dansullivan2001/winter-streeto-results
 * Description:       Pulls Winter StreetO series results from MapRun, lets the league co-ordinator correct them, and renders event and league tables via shortcodes.
 * Version:           0.3.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mole Valley Orienteering Club
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mvoc-streeto
 *
 * @package MVOC_StreetO
 */

defined( 'ABSPATH' ) || exit;

define( 'MVOC_STREETO_VERSION', '0.3.3' );
define( 'MVOC_STREETO_FILE', __FILE__ );
define( 'MVOC_STREETO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MVOC_STREETO_URL', plugin_dir_url( __FILE__ ) );

require_once MVOC_STREETO_DIR . 'includes/class-autoloader.php';
\MVOC\StreetO\Autoloader::register();

register_activation_hook( __FILE__, array( '\MVOC\StreetO\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\MVOC\StreetO\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\MVOC\StreetO\Plugin', 'instance' ) );
