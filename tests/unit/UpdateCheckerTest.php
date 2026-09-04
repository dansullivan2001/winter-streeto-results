<?php
/**
 * Guards the update checker's use of the vendored library.
 *
 * v0.3.4 shipped a fatal: setBranch() was called on the VCS API object, where
 * it does not exist - it belongs to the update checker. Nothing caught it,
 * because Update_Checker::init() runs on plugins_loaded and needs WordPress, so
 * no unit test ever executed it. This reads the source instead and checks each
 * call against the library, which needs neither.
 *
 * @package MVOC_StreetO
 */

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class UpdateCheckerTest extends TestCase {

	private function plugin_dir(): string {
		return dirname( __DIR__, 2 ) . '/mvoc-streeto-results/';
	}

	private function source(): string {
		return (string) file_get_contents( $this->plugin_dir() . 'includes/class-update-checker.php' );
	}

	/**
	 * Methods the vendored library defines, anywhere under a given directory.
	 *
	 * @param string $subdir Path under lib/plugin-update-checker/Puc/v5p7/.
	 * @return string[]
	 */
	private function library_methods( string $subdir = '' ): array {
		$base  = $this->plugin_dir() . 'lib/plugin-update-checker/Puc/v5p7/' . $subdir;
		$found = array();

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			preg_match_all(
				'/(?:public |abstract public )function\s+(\w+)\s*\(/',
				(string) file_get_contents( $file->getPathname() ),
				$m
			);
			$found = array_merge( $found, $m[1] );
		}

		return array_unique( $found );
	}

	public function test_the_library_is_vendored(): void {
		$this->assertFileExists( $this->plugin_dir() . 'lib/plugin-update-checker/plugin-update-checker.php' );
	}

	public function test_every_method_called_on_the_checker_exists(): void {
		preg_match_all( '/\$update_checker->(\w+)\(/', $this->source(), $m );
		$this->assertNotEmpty( $m[1], 'expected the checker to be configured' );

		$available = $this->library_methods();

		foreach ( array_unique( $m[1] ) as $method ) {
			$this->assertContains(
				$method,
				$available,
				sprintf( '$update_checker->%s() is not defined anywhere in the library.', $method )
			);
		}
	}

	public function test_every_method_called_on_the_vcs_api_exists_on_it(): void {
		// The specific regression: setBranch() is on the checker, not the API,
		// so calling it here fataled on every page load.
		preg_match_all( '/\$vcs_api->(\w+)\(/', $this->source(), $m );

		$api = array_merge(
			$this->library_methods( 'Vcs/' ),
			$this->library_methods( '' )
		);

		// Methods that belong only to the checker must not be called on the API.
		$checker_only = array( 'setBranch' );

		foreach ( array_unique( $m[1] ) as $method ) {
			$this->assertNotContains(
				$method,
				$checker_only,
				sprintf( '$vcs_api->%s() belongs to the update checker, not the VCS API.', $method )
			);
			$this->assertContains( $method, $api, sprintf( '$vcs_api->%s() is not defined.', $method ) );
		}
	}

	public function test_the_api_object_is_null_checked_before_use(): void {
		// getVcsApi() can return null, and a fatal here takes the whole site
		// down rather than just the update notice.
		$this->assertMatchesRegularExpression(
			'/if \(\s*null !== \$vcs_api\s*\)/',
			$this->source()
		);
	}
}
