<?php
/**
 * PSR-4-style autoloader mapping the MVOC\StreetO namespace onto the
 * WordPress `class-{slug}.php` file naming convention.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

defined( 'ABSPATH' ) || exit;

/**
 * Maps namespaced class names to files under includes/, admin/ and public/.
 */
class Autoloader {

	private const NAMESPACE_PREFIX = 'MVOC\\StreetO\\';

	/**
	 * Sub-namespace => directory, relative to the plugin root.
	 *
	 * Anything not listed here falls back to includes/, which is where the
	 * top-level classes (Plugin, Activator, Schema) live.
	 *
	 * @var array<string,string>
	 */
	private const DIRECTORY_MAP = array(
		'MapRun' => 'includes/maprun',
		'Domain' => 'includes/domain',
		'Repo'   => 'includes/repo',
		'Admin'  => 'admin',
		'Front'  => 'public',
	);

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and load a class.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		$directory = 'includes';
		if ( $parts && isset( self::DIRECTORY_MAP[ $parts[0] ] ) ) {
			$directory = self::DIRECTORY_MAP[ $parts[0] ];
		}

		// Acme_Widget => class-acme-widget.php
		$filename = 'class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';
		$path     = MVOC_STREETO_DIR . $directory . '/' . $filename;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
