<?php
/**
 * Public shortcodes for the event and league tables.
 *
 * Each event page carries that event's results followed by the updated league,
 * so the league renders eight times across the season. That is why it is cached
 * in a transient keyed on the series' latest publish.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Front;

use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\League_Builder;
use MVOC\StreetO\Domain\League_Presenter;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the front-end shortcodes.
 */
class Shortcodes {

	private const CACHE_PREFIX = 'mvoc_streeto_';

	private Events_Repo $events;

	private Results_Repo $results;

	private Competitors_Repo $competitors;

	/**
	 * @param Events_Repo|null      $events      Events persistence.
	 * @param Results_Repo|null     $results     Results persistence.
	 * @param Competitors_Repo|null $competitors Competitor persistence.
	 */
	public function __construct(
		?Events_Repo $events = null,
		?Results_Repo $results = null,
		?Competitors_Repo $competitors = null
	) {
		$this->events      = $events ?? new Events_Repo();
		$this->results     = $results ?? new Results_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
	}

	/**
	 * Hook the shortcodes and their assets in.
	 */
	public function register(): void {
		add_shortcode( 'mvoc_streeto_event', array( $this, 'render_event' ) );
		add_shortcode( 'mvoc_streeto_league', array( $this, 'render_league' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the stylesheet and expander script.
	 *
	 * Registered rather than enqueued, so a page with no results table does not
	 * carry assets it never uses.
	 */
	public function register_assets(): void {
		wp_register_style(
			'mvoc-streeto',
			MVOC_STREETO_URL . 'public/css/tables.css',
			array(),
			MVOC_STREETO_VERSION
		);

		wp_register_script(
			'mvoc-streeto-league',
			MVOC_STREETO_URL . 'public/js/league.js',
			array(),
			MVOC_STREETO_VERSION,
			true
		);
	}

	/**
	 * `[mvoc_streeto_event series="2026-27" number="3"]`
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render_event( $atts ): string {
		$atts = shortcode_atts(
			array(
				'series' => '',
				'number' => '',
			),
			$atts,
			'mvoc_streeto_event'
		);

		$event = $this->resolve_event( $atts['series'], (int) $atts['number'] );
		if ( ! $event ) {
			return $this->notice( __( 'Results are not available yet.', 'mvoc-streeto' ) );
		}

		wp_enqueue_style( 'mvoc-streeto' );

		$series = $this->resolve_series( (string) $atts['series'] );
		$config = $this->events->scoring_config( $series ?? array() );

		$scored = ( new Scoring_Engine( $config ) )->score_event(
			array_map(
				array( Results_Repo::class, 'effective' ),
				$this->results->for_event( $event['id'] )
			)
		);

		$organiser = array();
		if ( $event['organiser_competitor_id'] ) {
			foreach ( $this->competitors->all() as $competitor ) {
				if ( $competitor['id'] === $event['organiser_competitor_id'] ) {
					$organiser = $competitor;
					break;
				}
			}
		}

		$model = ( new Event_Presenter( $config ) )->present( $scored, $organiser );

		return $this->template( 'event-table', array( 'model' => $model, 'event' => $event ) );
	}

	/**
	 * `[mvoc_streeto_league series="2026-27" category="ladies"]`
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render_league( $atts ): string {
		$atts = shortcode_atts(
			array(
				'series'   => '',
				'category' => 'overall',
			),
			$atts,
			'mvoc_streeto_league'
		);

		$series = $this->resolve_series( (string) $atts['series'] );
		if ( ! $series ) {
			return $this->notice( __( 'The league is not available yet.', 'mvoc-streeto' ) );
		}

		wp_enqueue_style( 'mvoc-streeto' );
		wp_enqueue_script( 'mvoc-streeto-league' );

		$model = $this->league_model( $series, $atts['category'] );
		if ( ! $model['rows'] ) {
			return $this->notice( __( 'No league standings yet.', 'mvoc-streeto' ) );
		}

		return $this->template( 'league-table', array( 'model' => $model ) );
	}

	/**
	 * The series a shortcode refers to.
	 *
	 * An omitted series means "whichever season is current", so a standing
	 * league page on the club site never needs editing when the season rolls
	 * over. A named one always wins, so an archived season's page keeps showing
	 * that season.
	 *
	 * @param string $slug Series slug from the shortcode, possibly empty.
	 * @return array<string,mixed>|null
	 */
	private function resolve_series( string $slug ): ?array {
		$slug = trim( $slug );

		if ( '' !== $slug ) {
			return $this->events->find_series( $slug );
		}

		return $this->events->active_series();
	}

	/**
	 * Build (or reuse) the league table model for a category.
	 *
	 * @param array<string,mixed> $series   Series row.
	 * @param string              $category Category key.
	 * @return array<string,mixed>
	 */
	private function league_model( array $series, string $category ): array {
		// An event still in draft is visible to whoever could publish it, and
		// the league has to agree: showing a draft event's results next to a
		// league that ignores them made the two tables contradict each other
		// on the same page.
		$previewing = current_user_can( Plugin::CAPABILITY );

		$events = array_values(
			array_filter(
				$this->events->events( $series['id'] ),
				// A cancelled event never counts, for anyone. It is kept in the
				// series so the numbering stays stable, not so it can score.
				static fn( array $event ): bool => ! $event['is_cancelled']
					&& ( $event['is_published'] || $previewing )
			)
		);

		$model = null;
		$key   = '';

		// A preview is never cached. Sharing a cache entry with the public
		// version would be a way to leak unpublished results to visitors, and
		// no amount of key-juggling is worth that risk for one admin page view.
		if ( ! $previewing ) {
			// Keyed on the latest publish so the cache clears itself the moment
			// an event goes live, without anyone remembering to flush it.
			$stamp = '';
			foreach ( $events as $event ) {
				$stamp = max( $stamp, (string) ( $event['published_at'] ?? '' ) );
			}

			$key    = self::CACHE_PREFIX . 'league_' . md5( $series['slug'] . '|' . $category . '|' . $stamp );
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$model = ( new League_Presenter() )->present(
			$this->standings( $series, $events ),
			array_map( static fn( array $event ): string => (string) $event['title'], $events ),
			$category
		);

		$model['includes_drafts'] = $previewing && (bool) array_filter(
			$events,
			static fn( array $event ): bool => ! $event['is_published']
		);

		if ( ! $previewing ) {
			set_transient( $key, $model, DAY_IN_SECONDS );
		}

		return $model;
	}

	/**
	 * Score every published event and build the standings.
	 *
	 * @param array<string,mixed>            $series    Series row.
	 * @param array<int,array<string,mixed>> $published Published events, in order.
	 * @return array<int,array<string,mixed>>
	 */
	private function standings( array $series, array $published ): array {
		$config = $this->events->scoring_config( $series );
		$engine = new Scoring_Engine( $config );

		$competitors = array();
		foreach ( $this->competitors->all() as $competitor ) {
			$competitor['event_points'] = array_fill( 0, count( $published ), null );
			$competitors[ $competitor['id'] ] = $competitor;
		}

		foreach ( $published as $index => $event ) {
			$scored = $engine->score_event(
				array_map(
					array( Results_Repo::class, 'effective' ),
					$this->results->for_event( $event['id'] )
				)
			);

			foreach ( $scored as $row ) {
				$id = $row['competitor_id'] ?? null;

				if ( null === $id || null === $row['league_points'] || ! isset( $competitors[ $id ] ) ) {
					continue;
				}

				// A runner with two counted rows at one event keeps the better,
				// which is the safe reading of an unresolved duplicate.
				$existing = $competitors[ $id ]['event_points'][ $index ];
				$competitors[ $id ]['event_points'][ $index ] = null === $existing
					? $row['league_points']
					: max( $existing, $row['league_points'] );
			}

			if ( $event['organiser_competitor_id'] && isset( $competitors[ $event['organiser_competitor_id'] ] ) ) {
				$competitors[ $event['organiser_competitor_id'] ]['organised'] = $event['title'];
			}
		}

		// Only people who actually scored belong in the league table.
		$entrants = array_filter(
			$competitors,
			static fn( array $c ): bool => ! empty( $c['organised'] )
				|| array_filter( $c['event_points'], static fn( $p ): bool => null !== $p )
		);

		return ( new League_Builder( $config ) )->build( array_values( $entrants ) );
	}

	/**
	 * Find a published event, or one the current editor may preview.
	 *
	 * @param string $series_slug  Series slug.
	 * @param int    $event_number Event number.
	 * @return array<string,mixed>|null
	 */
	private function resolve_event( string $series_slug, int $event_number ): ?array {
		$series = $this->resolve_series( $series_slug );
		if ( ! $series ) {
			return null;
		}

		$event = $this->events->find_event( $series['id'], $event_number );
		if ( ! $event ) {
			return null;
		}

		if ( $event['is_cancelled'] ) {
			return null;
		}

		// A draft is visible only to someone who could publish it, so a
		// half-corrected table can never appear on the live site.
		if ( ! $event['is_published'] && ! current_user_can( Plugin::CAPABILITY ) ) {
			return null;
		}

		return $event;
	}

	/**
	 * Render a template with the given data.
	 *
	 * @param string              $template Template file name, without extension.
	 * @param array<string,mixed> $data     Variables for the template.
	 */
	private function template( string $template, array $data ): string {
		$path = MVOC_STREETO_DIR . 'public/templates/' . $template . '.php';

		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data, EXTR_SKIP );
		require $path;

		return (string) ob_get_clean();
	}

	/**
	 * A neutral message where a table would go.
	 *
	 * @param string $message Message text.
	 */
	private function notice( string $message ): string {
		return '<p class="mvoc-streeto-notice">' . esc_html( $message ) . '</p>';
	}
}
