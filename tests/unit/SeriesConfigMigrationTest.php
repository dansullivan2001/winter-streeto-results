<?php
/**
 * Tests for the series scoring-config migration.
 *
 * A series stores its whole scoring config as JSON, so a change to the code's
 * defaults does not reach a season already created. The migration that moves
 * the active series onto the current league points ladder therefore decides
 * what to overwrite and what to leave, and that decision is what is tested
 * here — the surrounding query needs a database, this does not.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Schema::series_config_on_current_ladder
 */
class SeriesConfigMigrationTest extends TestCase {

	/**
	 * The ladder the old default produced: 50 for first, 1 for fiftieth.
	 *
	 * @return int[]
	 */
	private function retired_ladder(): array {
		$ladder = array();
		for ( $position = 1; $position <= 50; $position++ ) {
			$ladder[] = 51 - $position;
		}

		return $ladder;
	}

	/**
	 * A stored config as a series created before the change carries it.
	 */
	private function stored_config_with_old_ladder(): string {
		$config                = new Scoring_Config();
		$as_array              = get_object_vars( $config );
		$as_array['points_ladder'] = $this->retired_ladder();

		return (string) json_encode( $as_array );
	}

	public function test_the_old_default_ladder_is_replaced(): void {
		$migrated = Schema::series_config_on_current_ladder( $this->stored_config_with_old_ladder() );

		$this->assertNotNull( $migrated );
		$this->assertSame( 100, Scoring_Config::from_json( (string) $migrated )->points_for_position( 1 ) );
		$this->assertSame( 1, Scoring_Config::from_json( (string) $migrated )->points_for_position( 100 ) );
	}

	public function test_everything_else_in_the_config_survives(): void {
		$stored = json_decode( $this->stored_config_with_old_ladder(), true );

		$stored['counting_events']      = 4;
		$stored['category_year']        = 2019;
		$stored['organiser_bonus_mode'] = Scoring_Config::BONUS_ADDED;

		$migrated = Schema::series_config_on_current_ladder( (string) json_encode( $stored ) );
		$config   = Scoring_Config::from_json( (string) $migrated );

		$this->assertSame( 4, $config->counting_events );
		$this->assertSame( 2019, $config->category_year );
		$this->assertSame( Scoring_Config::BONUS_ADDED, $config->organiser_bonus_mode );
		$this->assertSame( 100, $config->points_for_position( 1 ) );
	}

	public function test_a_series_already_on_the_current_ladder_is_left_alone(): void {
		$this->assertNull(
			Schema::series_config_on_current_ladder( ( new Scoring_Config() )->to_json() )
		);
	}

	public function test_running_it_twice_changes_nothing_the_second_time(): void {
		$once = Schema::series_config_on_current_ladder( $this->stored_config_with_old_ladder() );

		$this->assertNull( Schema::series_config_on_current_ladder( (string) $once ) );
	}

	public function test_a_deliberately_set_ladder_is_left_alone(): void {
		$stored                    = json_decode( ( new Scoring_Config() )->to_json(), true );
		$stored['points_ladder']   = array( 20, 18, 16, 14, 12 );

		$this->assertNull( Schema::series_config_on_current_ladder( (string) json_encode( $stored ) ) );
	}

	public function test_a_config_with_no_ladder_of_its_own_is_left_alone(): void {
		// Nothing to migrate: it already picks up the code's default ladder.
		$this->assertNull( Schema::series_config_on_current_ladder( '{"counting_events":5}' ) );
	}

	public function test_unreadable_stored_json_is_left_alone(): void {
		$this->assertNull( Schema::series_config_on_current_ladder( '' ) );
		$this->assertNull( Schema::series_config_on_current_ladder( 'not json' ) );
		$this->assertNull( Schema::series_config_on_current_ladder( '[]' ) );
	}
}
