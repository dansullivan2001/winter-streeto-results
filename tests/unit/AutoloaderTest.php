<?php
/**
 * Tests that every plugin class resolves to a real file.
 *
 * A mismatch between a class name and its filename is invisible until
 * WordPress fatals on the live site, so it is worth catching here.
 *
 * @package MVOC_StreetO
 */

use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Autoloader
 */
class AutoloaderTest extends TestCase {

	/**
	 * @dataProvider class_provider
	 */
	public function test_class_resolves_to_a_file( string $class_name ): void {
		$this->assertTrue(
			class_exists( $class_name ),
			$class_name . ' did not resolve — check the file name matches the class name.'
		);
	}

	public function class_provider(): array {
		return array(
			array( \MVOC\StreetO\Plugin::class ),
			array( \MVOC\StreetO\Activator::class ),
			array( \MVOC\StreetO\Schema::class ),
			array( \MVOC\StreetO\MapRun\Client::class ),
			array( \MVOC\StreetO\MapRun\Parser::class ),
			array( \MVOC\StreetO\Domain\Scoring_Config::class ),
			array( \MVOC\StreetO\Domain\Scoring_Engine::class ),
			array( \MVOC\StreetO\Domain\League_Builder::class ),
			array( \MVOC\StreetO\Domain\Duplicate_Detector::class ),
			array( \MVOC\StreetO\Domain\Name_Matcher::class ),
			array( \MVOC\StreetO\Domain\Competitor_Registry::class ),
			array( \MVOC\StreetO\Repo\Competitors_Repo::class ),
			array( \MVOC\StreetO\Admin\Competitors_Screen::class ),
			array( \MVOC\StreetO\Admin\Unmatched_Screen::class ),
			array( \MVOC\StreetO\Domain\Import_Reconciler::class ),
			array( \MVOC\StreetO\Domain\Event_Presenter::class ),
			array( \MVOC\StreetO\Domain\League_Presenter::class ),
			array( \MVOC\StreetO\Repo\Events_Repo::class ),
			array( \MVOC\StreetO\Repo\Results_Repo::class ),
			array( \MVOC\StreetO\Importer::class ),
			array( \MVOC\StreetO\Front\Shortcodes::class ),
			array( \MVOC\StreetO\Admin\Events_Screen::class ),
			array( \MVOC\StreetO\Admin\Event_Review_Screen::class ),
			array( \MVOC\StreetO\Admin\Admin_Menu::class ),
			array( \MVOC\StreetO\Admin\MapRun_Explorer_Screen::class ),
		);
	}

	public function test_unrelated_namespaces_are_ignored(): void {
		// The autoloader must not claim classes it does not own, or it will
		// interfere with other plugins on the site.
		$this->assertFalse( class_exists( 'Some\\Other\\Plugin\\Thing' ) );
	}
}
