<?php
/**
 * Public shortcodes for the event and league tables.
 *
 * Each event page carries that event's results followed by the league as it
 * stood at that point (`through_event`), so the league renders eight times
 * across the season, each showing a shorter run of events than the last. A
 * `through_event` page stays hidden until that specific event is published —
 * "the league as it stood after event 3" is not a thing until event 3 is
 * public. A page with no `through_event` always shows the full current
 * standings, built from published events only, for every viewer including an
 * editor previewing a draft. Both are cached in a transient keyed on
 * League_Cache's generation counter, which is bumped by any write that could
 * change what the table shows — not just a publish.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Front;

use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\League_Cache;
use MVOC\StreetO\League_Service;
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

	private League_Service $league;

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
		$this->league      = new League_Service( $this->events, $this->results, $this->competitors );
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
			Results_Repo::effective_rows( $this->results->for_event( $event['id'] ), $config )
		);

		$organiser_ids = $this->events->organisers( (int) $event['id'] );
		$organisers    = array_values(
			array_filter(
				$this->competitors->all(),
				static fn( array $competitor ): bool => in_array( $competitor['id'], $organiser_ids, true )
			)
		);

		$model = ( new Event_Presenter( $config ) )->present( $scored, $organisers );

		return $this->template( 'event-table', array( 'model' => $model, 'event' => $event ) );
	}

	/**
	 * `[mvoc_streeto_league series="2026-27" category="ladies"]`
	 *
	 * `through_event="3"` caps the standings at that event number, for a
	 * page recording how the league stood at the time — still computed
	 * live, so a later correction to event 3 still shows up here. If event 3
	 * is still a draft, it stays hidden from visitors but previews for
	 * whoever could publish it, the same as the event table above it on
	 * that page. Leave it out for the full current standings, built from
	 * published events only, for every viewer.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render_league( $atts ): string {
		$atts = shortcode_atts(
			array(
				'series'        => '',
				'category'      => 'overall',
				'through_event' => '',
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

		$through_event = '' === trim( (string) $atts['through_event'] )
			? null
			: max( 1, (int) $atts['through_event'] );

		// "As it stood through event N" does not exist publicly until event N
		// itself is public. An editor previewing that event still gets to see
		// it here too, exactly as they would on the event table above it.
		$can_preview = false;
		if ( null !== $through_event ) {
			$target = $this->events->find_event( (int) $series['id'], $through_event );
			if ( ! $target ) {
				return $this->notice( __( 'The league is not available yet.', 'mvoc-streeto' ) );
			}

			if ( ! $target['is_published'] ) {
				if ( ! current_user_can( Plugin::CAPABILITY ) ) {
					return $this->notice( __( 'The league is not available yet.', 'mvoc-streeto' ) );
				}
				$can_preview = true;
			}
		}

		$model = $this->league_model( $series, $atts['category'], $through_event, $can_preview );
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
	 * @param array<string,mixed> $series        Series row.
	 * @param string              $category      Category key.
	 * @param int|null            $through_event Cap standings at this event
	 *                                            number, or null for every
	 *                                            published event.
	 * @param bool                $can_preview   Whether to also include the
	 *                                            draft event this page is
	 *                                            pinned to. Only ever true
	 *                                            for a through_event page an
	 *                                            editor is allowed to preview
	 *                                            — the current standings
	 *                                            never include a draft.
	 * @return array<string,mixed>
	 */
	private function league_model( array $series, string $category, ?int $through_event, bool $can_preview ): array {
		// through_event caps a page at the standings as they stood at that point
		// in the season, not the ones since.
		$events = $this->league->events( $series, $through_event, $can_preview );

		$key = '';

		// A preview is never cached. Sharing a cache entry with the public
		// version would be a way to leak unpublished results to visitors, and
		// no amount of key-juggling is worth that risk for one editor's page view.
		if ( ! $can_preview ) {
			// Keyed on League_Cache's generation, which any write that could
			// change standings bumps — a publish, but also a correction, a
			// manual row, or a cancellation made after the fact.
			$cap    = null === $through_event ? 'latest' : (string) $through_event;
			$key    = self::CACHE_PREFIX . 'league_' . md5( $series['slug'] . '|' . $category . '|' . $cap . '|' . League_Cache::generation() );
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$model = $this->league->present( $series, $events, $category );

		$model['includes_drafts'] = $can_preview && (bool) array_filter(
			$events,
			static fn( array $event ): bool => ! $event['is_published']
		);

		if ( ! $can_preview ) {
			set_transient( $key, $model, DAY_IN_SECONDS );
		}

		return $model;
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
