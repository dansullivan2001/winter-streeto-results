<?php
/**
 * Tests that a league model cached by an older plugin version cannot be served
 * to a newer template that expects more of it.
 *
 * This is here because it got through. 1.0.0 added `counting_events` to the
 * league model so the published footnote could state the series' own rule
 * instead of a hard-coded "best 5". The model is cached in a transient for a
 * day, keyed on the series, category, cap and League_Cache's generation — and
 * that generation tracks changes to the *data*, not to the shape of the model
 * built from it. Updating the plugin bumped nothing.
 *
 * So for up to 24 hours after the update, a page with a warm cache rendered
 * "The best 0 results count." under the live league table, with two PHP
 * warnings per request behind it. Every test passed, because none of them
 * looked at a model the current code had not just built.
 *
 * Two defences are asserted here: the key now carries the plugin version, and
 * the template tolerates the key being absent anyway.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\Domain\Scoring_Config;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CachedModelShapeTest extends TestCase {

	private function shortcodes_source(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/mvoc-streeto-results/public/class-shortcodes.php'
		);
	}

	private function league_template(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/mvoc-streeto-results/public/templates/league-table.php'
		);
	}

	/**
	 * The cache key must change when the code that builds the model changes.
	 */
	public function test_the_league_cache_key_carries_the_plugin_version(): void {
		$source = $this->shortcodes_source();

		$start = strpos( $source, '$key    = self::CACHE_PREFIX' );
		$this->assertNotFalse( $start, 'the league cache key has moved or been renamed' );

		$key = substr( $source, $start, 400 );

		$this->assertStringContainsString(
			'MVOC_STREETO_VERSION',
			$key,
			'A model cached by an earlier version would be served to this one, '
				. 'which may expect keys that version never set.'
		);
		$this->assertStringContainsString(
			'League_Cache::generation()',
			$key,
			'the data-change invalidation must survive alongside the version'
		);
	}

	/**
	 * Every key the published template reads without an empty()/isset() guard
	 * must be one the presenter always sets.
	 *
	 * Guarded reads are fine — that is how a model from an older version is
	 * tolerated. Unguarded ones are the hazard, so they are held to the
	 * presenter's actual output.
	 */
	public function test_the_template_only_reads_model_keys_the_presenter_sets(): void {
		$model = ( new League_Presenter( new Scoring_Config() ) )->present( array(), array(), 'overall' );

		preg_match_all( "/\\\$model\\['([a-z_]+)'\\]/", $this->league_template(), $matches );
		$this->assertNotEmpty( $matches[1], 'the template reads no model keys — has it been rewritten?' );

		$guarded = array();
		foreach ( array( 'empty', 'isset' ) as $guard ) {
			preg_match_all( "/{$guard}\\(\\s*\\\$model\\['([a-z_]+)'\\]/", $this->league_template(), $found );
			$guarded = array_merge( $guarded, $found[1] );
		}

		foreach ( array_unique( $matches[1] ) as $key ) {
			if ( in_array( $key, $guarded, true ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				$key,
				$model,
				sprintf(
					'The league template reads $model[\'%s\'] unguarded, but League_Presenter '
					. 'does not set it — a cached or older model would render wrongly.',
					$key
				)
			);
		}
	}

	/**
	 * The belt to the version key's braces: a model missing the footnote's key
	 * must drop the sentence, never publish a wrong number.
	 */
	public function test_the_footnote_is_guarded_against_a_model_without_it(): void {
		$this->assertMatchesRegularExpression(
			"/empty\(\s*\\\$model\['counting_events'\]\s*\)/",
			$this->league_template(),
			'an unguarded read here published "The best 0 results count." once already'
		);
	}

	/**
	 * And the presenter really does always set it, so the guard never fires in
	 * normal operation.
	 */
	public function test_the_presenter_always_sets_the_counting_rule(): void {
		foreach ( array( 1, 5, 8 ) as $counting ) {
			$model = ( new League_Presenter( new Scoring_Config( array( 'counting_events' => $counting ) ) ) )
				->present( array(), array(), 'overall' );

			$this->assertSame( $counting, $model['counting_events'] );
		}
	}
}
