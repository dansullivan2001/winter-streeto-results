<?php
/**
 * Series and event setup: names, dates, venues, organisers and MapRun sources.
 *
 * Everything about a fixture is editable here, because fixtures move. The list
 * seeded on creation is the club's published calendar, not a contract.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Season;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists and edits the series and its events.
 */
class Events_Screen {

	private const NONCE = 'mvoc_streeto_events';

	public const DEFAULT_SLUG = '2026-27';

	/**
	 * The season the club is currently running.
	 */
	private const CURRENT_SEASON = 2026;

	/**
	 * Statuses an event can be put into from this screen.
	 *
	 * @var array<string,string>
	 */
	private const STATUS_ACTIONS = array(
		Events_Repo::STATUS_DRAFT     => 'Draft',
		Events_Repo::STATUS_CANCELLED => 'Cancelled',
	);

	private Events_Repo $repo;

	private Competitors_Repo $competitors;

	/**
	 * @param Events_Repo|null      $repo        Events persistence.
	 * @param Competitors_Repo|null $competitors Competitor persistence.
	 */
	public function __construct( ?Events_Repo $repo = null, ?Competitors_Repo $competitors = null ) {
		$this->repo        = $repo ?? new Events_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$notice     = $this->handle_post();
		$all_series = $this->repo->all_series();
		$series     = $this->current_series( $all_series );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Series and events', 'mvoc-streeto' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_series_bar( $all_series, $series ); ?>

			<?php if ( ! $series ) : ?>
				</div>
				<?php
				return;
			endif;

			$events      = $this->repo->events( $series['id'] );
			$competitors = $this->competitors->all();
			?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="series_slug" value="<?php echo esc_attr( $series['slug'] ); ?>" />

				<h2><?php esc_html_e( 'Series', 'mvoc-streeto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="series-name"><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></label></th>
						<td>
							<input type="text" id="series-name" name="series_name" class="regular-text"
								value="<?php echo esc_attr( $series['name'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: the series slug used in shortcodes. */
									esc_html__( 'Shortcodes refer to this series as %s, which does not change when you rename it.', 'mvoc-streeto' ),
									'<code>' . esc_html( $series['slug'] ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Events', 'mvoc-streeto' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The 40-minute course is a separate MapRun event, normally the same name ending ScoreQ40. Leave it blank until one exists.', 'mvoc-streeto' ); ?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:3em"><?php esc_html_e( '#', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Title / venue', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Organiser', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun — 60 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'MapRun — 40 min', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mvoc-streeto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php
							$number  = (int) $event['event_number'];
							$field   = 'events[' . $number . ']';
							$sources = array();

							foreach ( $this->repo->sources( $event['id'] ) as $source ) {
								$sources[ $source['course_label'] ] = $source['maprun_event_name'];
							}
							?>
							<tr>
								<td><?php echo esc_html( (string) $number ); ?></td>
								<td>
									<input type="date" name="<?php echo esc_attr( $field . '[event_date]' ); ?>"
										value="<?php echo esc_attr( (string) $event['event_date'] ); ?>" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[title]' ); ?>"
										value="<?php echo esc_attr( (string) $event['title'] ); ?>" />
								</td>
								<td>
									<select name="<?php echo esc_attr( $field . '[organiser]' ); ?>">
										<option value="0"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></option>
										<?php foreach ( $competitors as $competitor ) : ?>
											<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"
												<?php selected( $event['organiser_competitor_id'], $competitor['id'] ); ?>>
												<?php echo esc_html( $competitor['display_name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<br />
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[organiser_name]' ); ?>"
										placeholder="<?php esc_attr_e( 'or type a name', 'mvoc-streeto' ); ?>" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[source_60]' ); ?>"
										value="<?php echo esc_attr( $sources['60'] ?? '' ); ?>"
										placeholder="Burpham Sep26 PXAS ScoreQ60" />
								</td>
								<td>
									<input type="text" class="regular-text"
										name="<?php echo esc_attr( $field . '[source_40]' ); ?>"
										value="<?php echo esc_attr( $sources['40'] ?? '' ); ?>"
										placeholder="&hellip; ScoreQ40" />
								</td>
								<td>
									<?php $count = $this->repo->result_count( (int) $event['id'] ); ?>
									<?php if ( $event['is_published'] ) : ?>
										<strong><?php esc_html_e( 'Published', 'mvoc-streeto' ); ?></strong>
									<?php else : ?>
										<select name="<?php echo esc_attr( $field . '[status]' ); ?>">
											<?php foreach ( self::STATUS_ACTIONS as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>"
													<?php selected( $event['status'], $value ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
									<br />
									<a href="<?php echo esc_url( $this->review_url( (int) $event['id'] ) ); ?>">
										<?php esc_html_e( 'Results', 'mvoc-streeto' ); ?>
									</a>
									<?php if ( 0 === $count ) : ?>
										<br />
										<button type="submit" class="button-link delete"
											name="mvoc_streeto_action"
											value="delete:<?php echo esc_attr( (string) $event['id'] ); ?>"
											onclick="return confirm('<?php echo esc_js( __( 'Delete this event? Nothing has been imported for it.', 'mvoc-streeto' ) ); ?>');">
											<?php esc_html_e( 'Delete', 'mvoc-streeto' ); ?>
										</button>
									<?php else : ?>
										<br />
										<span class="description">
											<?php
											printf(
												/* translators: %d: number of imported result rows. */
												esc_html( _n( '%d result', '%d results', $count, 'mvoc-streeto' ) ),
												(int) $count
											);
											?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Organisers rarely exist as competitors before the first event has been imported, so typing a name creates one. When they later appear in MapRun results, the name is already known and matches automatically.', 'mvoc-streeto' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'An event can only be deleted while nothing has been imported for it — deleting one with results would take the MapRun snapshots and your corrections with it. Mark an event that will not run as Cancelled instead, which keeps the numbering intact.', 'mvoc-streeto' ); ?>
				</p>

				<h2><?php esc_html_e( 'Add an event', 'mvoc-streeto' ); ?></h2>
				<p>
					<input type="date" name="new_event[event_date]" />
					<input type="text" name="new_event[title]" class="regular-text"
						placeholder="<?php esc_attr_e( 'Title / venue', 'mvoc-streeto' ); ?>" />
					<button type="submit" name="mvoc_streeto_action" value="add_event" class="button">
						<?php esc_html_e( 'Add', 'mvoc-streeto' ); ?>
					</button>
				</p>

				<p>
					<button type="submit" name="mvoc_streeto_action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'mvoc-streeto' ); ?>
					</button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Shortcodes', 'mvoc-streeto' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Put these on each event page — the results first, then the league.', 'mvoc-streeto' ); ?>
			</p>
			<p>
				<code>[mvoc_streeto_event series="<?php echo esc_html( $series['slug'] ); ?>" number="1"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>" category="ladies"]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * The series being edited: the one asked for, else the most recent.
	 *
	 * @param array<int,array<string,mixed>> $all_series Every series.
	 * @return array<string,mixed>|null
	 */
	private function current_series( array $all_series ): ?array {
		$slug = isset( $_GET['series'] ) ? sanitize_title( wp_unslash( $_GET['series'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $slug && isset( $_POST['series_slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_POST['series_slug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( $slug ) {
			foreach ( $all_series as $series ) {
				if ( $series['slug'] === $slug ) {
					return $series;
				}
			}
		}

		return $all_series[0] ?? null;
	}

	/**
	 * The series switcher, and the forms for creating one.
	 *
	 * @param array<int,array<string,mixed>> $all_series Every series.
	 * @param array<string,mixed>|null       $current    The one being edited.
	 */
	private function render_series_bar( array $all_series, ?array $current ): void {
		$existing = array_column( $all_series, 'slug' );
		?>
		<?php if ( $all_series ) : ?>
			<form method="get" style="margin:1em 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Menu::SLUG ); ?>" />
				<label for="mvoc-series"><strong><?php esc_html_e( 'Series', 'mvoc-streeto' ); ?></strong></label>
				<select id="mvoc-series" name="series" onchange="this.form.submit()">
					<?php foreach ( $all_series as $series ) : ?>
						<option value="<?php echo esc_attr( $series['slug'] ); ?>"
							<?php selected( $current['slug'] ?? '', $series['slug'] ); ?>>
							<?php echo esc_html( $series['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Switch', 'mvoc-streeto' ); ?></button>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'No series yet. Pick the year a season starts in and everything else follows from it.', 'mvoc-streeto' ); ?></p>
		<?php endif; ?>

		<details <?php echo $current ? '' : 'open'; ?> style="margin-bottom:1.5em;">
			<summary><?php esc_html_e( 'Start a new season', 'mvoc-streeto' ); ?></summary>
			<form method="post" style="margin-top:0.75em;">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<label for="mvoc-start-year"><?php esc_html_e( 'Season starting', 'mvoc-streeto' ); ?></label>
					<select id="mvoc-start-year" name="start_year">
						<?php foreach ( Season::selectable_years() as $year ) : ?>
							<?php $taken = in_array( Season::slug( $year ), $existing, true ); ?>
							<option value="<?php echo esc_attr( (string) $year ); ?>"
								<?php disabled( $taken ); ?>
								<?php selected( $year, $this->default_start_year( $existing ) ); ?>>
								<?php
								echo esc_html(
									$taken
										? sprintf(
											/* translators: %s: season label such as 2026/27. */
											__( '%s — already created', 'mvoc-streeto' ),
											Season::label( $year )
										)
										: Season::label( $year )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" name="mvoc_streeto_action" value="add_series" class="button button-primary">
						<?php esc_html_e( 'Create season', 'mvoc-streeto' ); ?>
					</button>
				</p>
				<p class="description">
					<?php
					printf(
						/* translators: 1: example series name, 2: example slug, 3: example shortcode. */
						esc_html__( 'Everything is derived from that year. %1$s becomes the name, %2$s the shortcode slug, and the eight fixtures are dated to the third Tuesday of each month from September to April — which is where all eight of this season\'s published dates fall. Names, dates and venues all stay editable, so move anything that clashes.', 'mvoc-streeto' ),
						'<strong>' . esc_html( Season::name( self::CURRENT_SEASON + 1 ) ) . '</strong>',
						'<code>' . esc_html( Season::slug( self::CURRENT_SEASON + 1 ) ) . '</code>',
						''
					);
					?>
				</p>
			</form>
		</details>
		<?php
	}

	/**
	 * The year to preselect: the first season not yet created.
	 *
	 * @param string[] $existing Slugs already in use.
	 */
	private function default_start_year( array $existing ): int {
		$now     = Season::season_for( (int) gmdate( 'Y' ), (int) gmdate( 'n' ) );
		$options = Season::selectable_years();

		// Prefer the current season if it is missing, then the next one along,
		// so the button does the obvious thing without a choice being made.
		foreach ( array_merge( array( $now, $now + 1 ), $options ) as $year ) {
			if ( ! in_array( Season::slug( $year ), $existing, true ) && in_array( $year, $options, true ) ) {
				return $year;
			}
		}

		return $now;
	}

	/**
	 * Admin URL for an event's review screen.
	 *
	 * @param int $event_id Event id.
	 */
	private function review_url( int $event_id ): string {
		return add_query_arg(
			array(
				'page'  => Admin_Menu::SLUG . '-review',
				'event' => $event_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Handle a submission, returning a notice.
	 */
	private function handle_post(): string {
		if ( ! isset( $_POST['mvoc_streeto_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) );

		if ( 'add_series' === $action || 'seed' === $action ) {
			return $this->create_season();
		}

		if ( 0 === strpos( $action, 'delete:' ) ) {
			return $this->delete_event( (int) substr( $action, strlen( 'delete:' ) ) );
		}

		$series = $this->current_series( $this->repo->all_series() );
		if ( ! $series ) {
			return '';
		}

		if ( 'add_event' === $action ) {
			return $this->add_event( $series );
		}

		return $this->save_all( $series );
	}

	/**
	 * Create a season and its eight fixtures from a starting year.
	 *
	 * One path rather than two. There was no useful difference between "the
	 * 2026/27 season" and "an empty series with a name and slug I type", so the
	 * year picker replaces both - and unlike free text it cannot produce a name
	 * or slug that disagrees with the other seasons.
	 */
	private function create_season(): string {
		$year = isset( $_POST['start_year'] ) ? (int) $_POST['start_year'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! in_array( $year, Season::selectable_years(), true ) ) {
			return __( 'That is not a season the plugin offers.', 'mvoc-streeto' );
		}

		$slug = Season::slug( $year );

		if ( $this->repo->find_series( $slug ) ) {
			return sprintf(
				/* translators: %s: season label such as 2026/27. */
				__( 'The %s season already exists.', 'mvoc-streeto' ),
				Season::label( $year )
			);
		}

		$series_id = $this->repo->ensure_series(
			$slug,
			Season::name( $year ),
			new \MVOC\StreetO\Domain\Scoring_Config( array( 'category_year' => $year ) )
		);

		foreach ( Season::fixtures( $year ) as $fixture ) {
			$this->repo->save_event( $series_id, $fixture );
		}

		return sprintf(
			/* translators: 1: season label, 2: shortcode slug. */
			__( 'Created %1$s with eight fixtures. Shortcodes refer to it as %2$s.', 'mvoc-streeto' ),
			Season::label( $year ),
			$slug
		);
	}

	/**
	 * Delete an event, refusing once results exist.
	 *
	 * @param int $event_id Event id.
	 */
	private function delete_event( int $event_id ): string {
		$event = $this->repo->find_event_by_id( $event_id );
		if ( ! $event ) {
			return '';
		}

		if ( ! $this->repo->delete_event( $event_id ) ) {
			return __( 'That event has results imported, so it was not deleted. Mark it Cancelled instead.', 'mvoc-streeto' );
		}

		return sprintf(
			/* translators: %s: event title. */
			__( 'Deleted "%s".', 'mvoc-streeto' ),
			$event['title']
		);
	}

	/**
	 * Append an event to the end of the series.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function add_event( array $series ): string {
		$fields = isset( $_POST['new_event'] ) && is_array( $_POST['new_event'] )
			? wp_unslash( $_POST['new_event'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$title = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );
		if ( '' === $title ) {
			return '';
		}

		$existing = $this->repo->events( $series['id'] );
		$next     = 1;
		foreach ( $existing as $event ) {
			$next = max( $next, (int) $event['event_number'] + 1 );
		}

		$this->repo->save_event(
			$series['id'],
			array(
				'event_number' => $next,
				'title'        => $title,
				'venue'        => $title,
				'event_date'   => sanitize_text_field( (string) ( $fields['event_date'] ?? '' ) ),
			)
		);

		return sprintf(
			/* translators: %d: the new event's number. */
			__( 'Added event %d.', 'mvoc-streeto' ),
			$next
		);
	}

	/**
	 * Save the series name, every event, and their MapRun sources.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function save_all( array $series ): string {
		global $wpdb;

		if ( isset( $_POST['series_name'] ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['series_name'] ) );

			if ( '' !== $name && $name !== $series['name'] ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					\MVOC\StreetO\Schema::table( 'series' ),
					array( 'name' => $name ),
					array( 'id' => $series['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		if ( ! isset( $_POST['events'] ) || ! is_array( $_POST['events'] ) ) {
			return __( 'Saved.', 'mvoc-streeto' );
		}

		$saved = 0;

		foreach ( wp_unslash( $_POST['events'] ) as $number => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$event = $this->repo->find_event( $series['id'], (int) $number );

			if ( ! $event || ! is_array( $fields ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );

			$this->repo->save_event(
				$series['id'],
				array(
					'event_number'            => (int) $number,
					'title'                   => $title ?: $event['title'],
					'venue'                   => $title ?: $event['venue'],
					'event_date'              => sanitize_text_field( (string) ( $fields['event_date'] ?? '' ) ),
					'organiser_competitor_id' => $this->resolve_organiser( $fields ),
					// A published event keeps its status: taking it back to
					// draft is done on the review screen, deliberately, rather
					// than as a side effect of saving a date.
					'status'                  => $event['is_published']
						? $event['status']
						: $this->requested_status( $fields, (string) $event['status'] ),
				)
			);

			$sources = array();
			foreach ( array( '60', '40' ) as $course ) {
				$name = sanitize_text_field( (string) ( $fields[ 'source_' . $course ] ?? '' ) );

				if ( '' !== $name ) {
					$sources[] = array(
						'maprun_event_name' => $name,
						'course_label'      => $course,
					);
				}
			}

			$this->repo->save_sources( (int) $event['id'], $sources );
			++$saved;
		}

		return sprintf(
			/* translators: %d: number of events saved. */
			_n( 'Saved %d event.', 'Saved %d events.', $saved, 'mvoc-streeto' ),
			$saved
		);
	}

	/**
	 * The status a submitted event should take.
	 *
	 * @param array<string,mixed> $fields   Submitted fields.
	 * @param string              $fallback Current status.
	 */
	private function requested_status( array $fields, string $fallback ): string {
		$status = sanitize_key( (string) ( $fields['status'] ?? '' ) );

		return isset( self::STATUS_ACTIONS[ $status ] ) ? $status : $fallback;
	}

	/**
	 * The organiser for an event: the one chosen, or one created from a name.
	 *
	 * Organisers rarely exist as competitors before the first import, so a
	 * typed name creates the record and its alias. That alias is what makes
	 * them match automatically when they next appear in MapRun results, rather
	 * than becoming a second identity for the same person.
	 *
	 * @param array<string,mixed> $fields Submitted event fields.
	 */
	private function resolve_organiser( array $fields ): int {
		$typed = sanitize_text_field( (string) ( $fields['organiser_name'] ?? '' ) );

		if ( '' !== $typed ) {
			$parts   = preg_split( '/\s+/', trim( $typed ), 2 );
			$first   = $parts[0] ?? '';
			$surname = $parts[1] ?? '';

			$competitor = array(
				'first_name'   => $first,
				'surname'      => $surname,
				'display_name' => $typed,
			);

			$alias_key = \MVOC\StreetO\Domain\Name_Matcher::alias_key( $first, $surname );

			// Reuse an existing competitor with that name rather than making a
			// second one: a duplicate identity would split their league points.
			$aliases = $this->competitors->aliases();
			if ( isset( $aliases[ $alias_key ] ) ) {
				return (int) $aliases[ $alias_key ];
			}

			return $this->competitors->create_with_alias( $competitor, $alias_key );
		}

		return (int) ( $fields['organiser'] ?? 0 );
	}
}
