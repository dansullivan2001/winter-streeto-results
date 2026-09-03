<?php
/**
 * Imports a MapRun event: fetch, snapshot, parse, resolve, reconcile.
 *
 * The order matters. The raw payload is stored verbatim *before* anything is
 * parsed, so that if the parser ever gets something wrong the original response
 * is still on record and the event can be rebuilt from it.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

use MVOC\StreetO\Domain\Competitor_Registry;
use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\MapRun\Client;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an import for one event, across all its MapRun sources.
 */
class Importer {

	private Client $client;

	private Events_Repo $events;

	private Results_Repo $results;

	private Competitors_Repo $competitors;

	private Competitor_Registry $registry;

	private Import_Reconciler $reconciler;

	/**
	 * @param Client|null              $client      MapRun client.
	 * @param Events_Repo|null         $events      Events persistence.
	 * @param Results_Repo|null        $results     Results persistence.
	 * @param Competitors_Repo|null    $competitors Competitor persistence.
	 * @param Competitor_Registry|null $registry    Name resolution.
	 */
	public function __construct(
		?Client $client = null,
		?Events_Repo $events = null,
		?Results_Repo $results = null,
		?Competitors_Repo $competitors = null,
		?Competitor_Registry $registry = null
	) {
		$this->client      = $client ?? new Client();
		$this->events      = $events ?? new Events_Repo();
		$this->results     = $results ?? new Results_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
		$this->registry    = $registry ?? new Competitor_Registry();
		$this->reconciler  = new Import_Reconciler();
	}

	/**
	 * Import every source configured for an event.
	 *
	 * @param int         $event_id Event id.
	 * @param string|null $pasted   Pasted JSON, used instead of fetching when given.
	 * @return array{summary:array<string,int>,warnings:string[],unmatched:array<int,array<string,mixed>>,errors:string[]}
	 */
	public function import( int $event_id, ?string $pasted = null ): array {
		$sources  = $this->events->sources( $event_id );
		$summary  = array();
		$warnings = array();
		$errors   = array();
		$parsed   = array();

		foreach ( $sources as $source ) {
			try {
				$result = null === $pasted
					? $this->client->fetch( $source['maprun_event_name'] )
					: $this->client->ingest( $pasted );
			} catch ( \RuntimeException $e ) {
				// One course failing must not abandon the other. A 40-minute
				// event that nobody entered returns an error, and that should
				// not block importing the 60.
				$errors[] = sprintf( '%s: %s', $source['course_label'], $e->getMessage() );
				continue;
			}

			if ( ! empty( $result['warning'] ) ) {
				$warnings[] = sprintf( '%s: %s', $source['course_label'], $result['warning'] );
			}

			$rows = $this->with_categories(
				( new Parser() )->parse( $result['rows'], (string) $source['course_label'] ),
				$event_id
			);

			$fetch_id = $this->events->record_fetch(
				(int) $source['id'],
				$result['payload'],
				count( $rows ),
				null === $pasted ? 'http' : 'paste'
			);

			$actions = $this->reconciler->reconcile(
				$this->results->for_source( (int) $source['id'] ),
				$rows
			);

			foreach ( $actions as $action ) {
				$this->results->apply_action( $action, $event_id, (int) $source['id'], $fetch_id );
			}

			$summary = self::add_summaries( $summary, Import_Reconciler::summarise( $actions ) );
			$parsed  = array_merge( $parsed, $rows );

			// A paste only ever covers one source, so stop after the first.
			if ( null !== $pasted ) {
				break;
			}
		}

		$this->events->touch_fetched( $event_id );
		$this->link_competitors( $event_id );
		$this->sync_categories( $event_id );

		$resolution = $this->registry->resolve(
			$parsed,
			$this->competitors->all(),
			$this->competitors->aliases()
		);

		return array(
			'summary'   => $summary,
			'warnings'  => $warnings,
			'errors'    => $errors,
			'unmatched' => $resolution['unmatched'],
		);
	}

	/**
	 * Attach competitors to any result row whose name is already confirmed.
	 *
	 * Run after every import so that names confirmed at an earlier event
	 * resolve without the co-ordinator doing anything. A row whose competitor
	 * has already been set by hand is left alone.
	 *
	 * @param int $event_id Event id.
	 * @return int Rows newly linked.
	 */
	public function link_competitors( int $event_id ): int {
		$aliases = $this->competitors->aliases();
		$linked  = 0;

		foreach ( $this->results->for_event( $event_id ) as $row ) {
			if ( $row['competitor_id'] ) {
				continue;
			}

			$key = Domain\Name_Matcher::alias_key(
				(string) $row['raw_first_name'],
				(string) $row['raw_surname']
			);

			if ( isset( $aliases[ $key ] ) ) {
				$this->results->override(
					(int) $row['id'],
					'competitor',
					$aliases[ $key ],
					'matched by confirmed name'
				);
				++$linked;
			}
		}

		return $linked;
	}

	/**
	 * Record each linked competitor's category for this event's season.
	 *
	 * Derived from what MapRun said at import and stored per season, so a
	 * runner joins the Over-55 category from the season it applies rather than
	 * appearing in it across every season already published.
	 *
	 * An existing flag is normally left alone: the co-ordinator may have
	 * corrected it, and MapRun's self-declared data should not overwrite a
	 * deliberate fix.
	 *
	 * That guard cannot tell a deliberate correction from a value that was
	 * only ever written by a bulk save, so `$force` exists to rebuild a
	 * season's flags from MapRun when they are known to be wrong. It is never
	 * automatic — overwriting someone's corrections has to be asked for.
	 *
	 * @param int  $event_id Event id.
	 * @param bool $force    Overwrite flags that already exist.
	 * @return int Flags recorded or changed.
	 */
	public function sync_categories( int $event_id, bool $force = false ): int {
		$event = $this->events->find_event_by_id( $event_id );
		if ( ! $event ) {
			return 0;
		}

		$series_id = (int) $event['series_id'];
		$existing  = $this->competitors->over55_for_series( $series_id );
		$recorded  = 0;

		foreach ( $this->results->for_event( $event_id ) as $row ) {
			$competitor_id = (int) ( $row['competitor_id'] ?? 0 );

			if ( ! $competitor_id || null === $row['raw_is_over55'] ) {
				continue;
			}

			$flag = (bool) $row['raw_is_over55'];

			if ( array_key_exists( $competitor_id, $existing ) ) {
				if ( ! $force || $existing[ $competitor_id ] === $flag ) {
					continue;
				}
			}

			$this->competitors->set_over55( $series_id, $competitor_id, $flag );
			$existing[ $competitor_id ] = $flag;
			++$recorded;
		}

		return $recorded;
	}

	/**
	 * Rebuild a season's Over-55 flags from what MapRun supplied.
	 *
	 * The recovery path when the flags are wrong — after a schema change, or a
	 * bulk save that wrote a value for everyone. It reads what was stored at
	 * import, so events imported before MapRun's age data was being kept need
	 * re-importing first; how many rows carry it is reported by
	 * maprun_age_coverage() so that is visible rather than guessed at.
	 *
	 * @param int $series_id Series id.
	 * @return int Flags changed.
	 */
	public function refresh_categories( int $series_id ): int {
		$changed = 0;

		foreach ( $this->events->events( $series_id ) as $event ) {
			$changed += $this->sync_categories( (int) $event['id'], true );
		}

		return $changed;
	}

	/**
	 * How many of a season's result rows carry MapRun's age data.
	 *
	 * @param int $series_id Series id.
	 * @return array{with:int,total:int}
	 */
	public function maprun_age_coverage( int $series_id ): array {
		$with  = 0;
		$total = 0;

		foreach ( $this->events->events( $series_id ) as $event ) {
			foreach ( $this->results->for_event( (int) $event['id'] ) as $row ) {
				++$total;

				if ( null !== $row['raw_is_over55'] ) {
					++$with;
				}
			}
		}

		return array(
			'with'  => $with,
			'total' => $total,
		);
	}

	/**
	 * Link confirmed names across every event in every series.
	 *
	 * Called after the co-ordinator confirms names, so that rows already
	 * imported pick up their competitor straight away. Without it a name
	 * confirmed after an import would not attach until the next one, which is
	 * a re-fetch the co-ordinator has no other reason to run.
	 *
	 * @return int Rows newly linked.
	 */
	public function link_all_events(): int {
		$linked = 0;

		foreach ( $this->events->all_series() as $series ) {
			foreach ( $this->events->events( (int) $series['id'] ) as $event ) {
				$linked += $this->link_competitors( (int) $event['id'] );
				$this->sync_categories( (int) $event['id'] );
			}
		}

		return $linked;
	}

	/**
	 * Turn each row's year of birth into a category flag, then drop the year.
	 *
	 * This is the only point at which a date of birth exists in the system, and
	 * it does not survive past it.
	 *
	 * @param array<int,array<string,mixed>> $rows     Parsed rows.
	 * @param int                            $event_id Event id.
	 * @return array<int,array<string,mixed>>
	 */
	private function with_categories( array $rows, int $event_id ): array {
		$event  = $this->events->find_event_by_id( $event_id );
		$series = null;

		foreach ( $this->events->all_series() as $candidate ) {
			if ( $event && (int) $candidate['id'] === (int) $event['series_id'] ) {
				$series = $candidate;
				break;
			}
		}

		$config = $this->events->scoring_config( $series ?? array() );

		foreach ( $rows as $index => $row ) {
			$year = $row['year_of_birth'] ?? null;

			$rows[ $index ]['is_over55'] = is_numeric( $year )
				? $config->is_over55( (int) $year )
				: null;

			unset( $rows[ $index ]['year_of_birth'] );
		}

		return $rows;
	}

	/**
	 * Add two action summaries together.
	 *
	 * @param array<string,int> $running Accumulated totals.
	 * @param array<string,int> $next    Totals to add.
	 * @return array<string,int>
	 */
	private static function add_summaries( array $running, array $next ): array {
		foreach ( $next as $action => $count ) {
			$running[ $action ] = ( $running[ $action ] ?? 0 ) + $count;
		}

		return $running;
	}
}
