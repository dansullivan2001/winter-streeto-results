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

use MVOC\StreetO\Domain\MapRun_Name;
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

	/**
	 * Where each user's last-viewed series is remembered.
	 */
	private const LAST_SERIES_META = 'mvoc_streeto_last_series';

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

			<?php $this->render_diagnostics( $series ); ?>

			<?php $this->render_series_bar( $all_series, $series ); ?>

			<?php if ( ! $series ) : ?>
				</div>
				<?php
				return;
			endif;

			$events      = $this->repo->events( $series['id'] );
			$competitors = $this->competitors->all();
			?>

			<?php
			// autocomplete="off": these fields have stable names and repeated
			// values, so a browser will happily restore a previous entry into
			// an emptied box on reload or back-navigation. That looks exactly
			// like a save being ignored, and it is not something the server can
			// see or correct.
			?>
			<form method="post" autocomplete="off">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="series_slug" value="<?php echo esc_attr( $series['slug'] ); ?>" />

				<?php
				// Pressing Enter in a text field submits a form using its first
				// submit button. Without this that was "Fill in the suggested
				// names", so clearing a MapRun name and pressing Enter refilled
				// it from the suggestion - which looks exactly like the save
				// having failed. Saving is the primary action, so it is the one
				// Enter should take.
				?>
				<button type="submit" name="mvoc_streeto_action" value="save"
					tabindex="-1" aria-hidden="true"
					style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;">
					<?php esc_html_e( 'Save changes', 'mvoc-streeto' ); ?>
				</button>

				<h2><?php esc_html_e( 'Series', 'mvoc-streeto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Current season', 'mvoc-streeto' ); ?></th>
						<td>
							<?php if ( ! empty( $series['is_active'] ) ) : ?>
								<strong><?php esc_html_e( 'This is the current season.', 'mvoc-streeto' ); ?></strong>
								<p class="description">
									<?php esc_html_e( 'A shortcode with no series attribute shows this one, so a standing league page never needs editing when the season rolls over.', 'mvoc-streeto' ); ?>
								</p>
							<?php else : ?>
								<button type="submit" name="mvoc_streeto_action" value="make_active" class="button">
									<?php esc_html_e( 'Make this the current season', 'mvoc-streeto' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Only one season is current at a time. Promote a new one when it actually starts — until then the public site keeps showing the season being run.', 'mvoc-streeto' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
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
				<p class="description">
					<?php esc_html_e( 'Each empty box shows a suggested name, greyed out. Use it when creating the event in MapRun and both ends match without anyone typing the same string twice — which is the only thing keeping them in step today.', 'mvoc-streeto' ); ?>
					<br />
					<?php esc_html_e( 'The format matches all eight of last season\'s events. The venue is free text at MapRun\'s end, though, so a suggestion can be right about the shape and wrong about the words — anything that does not match, just type over.', 'mvoc-streeto' ); ?>
					<br />
					<?php
					printf(
						/* translators: %s: an example MapRun folder name. */
						esc_html__( 'MapRun groups a season in a folder such as %s, which is worth keeping to the same pattern.', 'mvoc-streeto' ),
						'<code>UK/Mole Valley/StreetO 25-26 Series</code>'
					);
					?>
				</p>
				<p>
					<button type="submit" name="mvoc_streeto_action" value="suggest_names" class="button">
						<?php esc_html_e( 'Fill in the suggested names', 'mvoc-streeto' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Only fills boxes that are empty — nothing you have already entered is touched.', 'mvoc-streeto' ); ?></span>
				</p>

				<div style="overflow-x:auto;">
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
									<input type="text" style="width:16em"
										name="<?php echo esc_attr( $field . '[title]' ); ?>"
										value="<?php echo esc_attr( (string) $event['title'] ); ?>" />
								</td>
								<td>
									<select style="width:12em" name="<?php echo esc_attr( $field . '[organiser]' ); ?>">
										<option value="0"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></option>
										<?php foreach ( $competitors as $competitor ) : ?>
											<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"
												<?php selected( $event['organiser_competitor_id'], $competitor['id'] ); ?>>
												<?php echo esc_html( $competitor['display_name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<br />
									<input type="text" style="width:12em"
										name="<?php echo esc_attr( $field . '[organiser_name]' ); ?>"
										placeholder="<?php esc_attr_e( 'or type a name', 'mvoc-streeto' ); ?>" />
								</td>
								<?php foreach ( array( '60', '40' ) as $course ) : ?>
									<?php
									$suggested = MapRun_Name::suggest(
										(string) ( $event['venue'] ?: $event['title'] ),
										(string) $event['event_date'],
										$course
									);
									?>
									<td>
										<input type="text" style="width:20em"
											autocomplete="off" data-lpignore="true"
											name="<?php echo esc_attr( $field . '[source_' . $course . ']' ); ?>"
											value="<?php echo esc_attr( $sources[ $course ] ?? '' ); ?>"
											<?php if ( '' !== $suggested ) : ?>
												<?php
												// Prefixed, because an unprefixed placeholder is the
												// same string as the value it replaces: clearing a box
												// then looked identical to not having cleared it, which
												// is indistinguishable from the save having failed.
												printf(
													'placeholder="%s"',
													esc_attr(
														sprintf(
															/* translators: %s: a suggested MapRun event name. */
															__( 'Suggested: %s', 'mvoc-streeto' ),
															$suggested
														)
													)
												);
												?>
											<?php endif; ?> />
									</td>
								<?php endforeach; ?>
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
											name="delete_event"
											value="<?php echo esc_attr( (string) $event['id'] ); ?>"
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
				</div>

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
				<?php esc_html_e( 'Put these on each event page — the results first, then the league as it stood after that event. through_event caps the table at that event number but stays live, so a later correction to it still shows up here:', 'mvoc-streeto' ); ?>
			</p>
			<p>
				<code>[mvoc_streeto_event series="<?php echo esc_html( $series['slug'] ); ?>" number="1"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>" through_event="1"]</code><br />
				<code>[mvoc_streeto_league series="<?php echo esc_html( $series['slug'] ); ?>" through_event="1" category="ladies"]</code>
			</p>
			<p class="description">
				<?php esc_html_e( 'Leaving through_event out shows the full current standings, and leaving the series out too shows whichever season is current — which is what a standing "latest league" page wants:', 'mvoc-streeto' ); ?>
			</p>
			<p>
				<code>[mvoc_streeto_league]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * A readout of what the last submission actually contained.
	 *
	 * Off unless ?mvoc_debug=1 is on the URL. It exists because a report of
	 * "clearing a MapRun name does not save" could not be reproduced here -
	 * not through the repository, the save handler, or a full render with a
	 * real POST - so the next useful thing is to see what the server on the
	 * affected site is actually receiving.
	 *
	 * @param array<string,mixed>|null $series The series being edited.
	 */
	private function render_diagnostics( ?array $series ): void {
		if ( ! isset( $_GET['mvoc_debug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$lines = array();

		$lines[] = 'plugin version   : ' . MVOC_STREETO_VERSION;
		$lines[] = 'request method   : ' . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '?' ) );
		$lines[] = 'action received  : ' . ( isset( $_POST['mvoc_streeto_action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? '"' . sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) ) . '"' // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '(none - no action was posted)' );
		$lines[] = 'series_slug post : ' . ( isset( $_POST['series_slug'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? '"' . sanitize_title( wp_unslash( $_POST['series_slug'] ) ) . '"' // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '(none)' );
		$lines[] = 'series resolved  : ' . ( $series ? '"' . $series['slug'] . '" id=' . $series['id'] : '(none)' );
		$lines[] = '';

		if ( isset( $_POST['events'] ) && is_array( $_POST['events'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$lines[] = 'source fields as received:';

			foreach ( wp_unslash( $_POST['events'] ) as $number => $fields ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_array( $fields ) ) {
					continue;
				}

				foreach ( array( '60', '40' ) as $course ) {
					$key = 'source_' . $course;

					$lines[] = sprintf(
						'  event %-2s %s : %s',
						(int) $number,
						$key,
						array_key_exists( $key, $fields )
							? '"' . sanitize_text_field( (string) $fields[ $key ] ) . '"'
							: '(KEY ABSENT - the browser did not send this field)'
					);
				}
			}
		} else {
			$lines[] = 'source fields as received: (no events posted)';
		}

		$lines[] = '';
		$lines[] = 'stored now:';

		if ( $series ) {
			foreach ( $this->repo->events( (int) $series['id'] ) as $event ) {
				$sources = $this->repo->sources( (int) $event['id'] );

				$lines[] = sprintf(
					'  event %-2s : %s',
					(int) $event['event_number'],
					$sources
						? implode(
							', ',
							array_map(
								static fn( array $s ): string => $s['course_label'] . '="' . $s['maprun_event_name'] . '"',
								$sources
							)
						)
						: '(none)'
				);
			}
		}

		printf(
			'<div class="notice notice-info"><p><strong>%s</strong></p><pre style="overflow:auto;font-size:12px;">%s</pre></div>',
			esc_html__( 'Diagnostics', 'mvoc-streeto' ),
			esc_html( implode( "\n", $lines ) )
		);
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

		// An explicit choice is remembered, so coming back to this screen lands
		// where you left it rather than snapping to the newest season. This is
		// a personal preference, not shared state, which is why it lives in
		// user meta and not on the series itself.
		if ( $slug ) {
			foreach ( $all_series as $series ) {
				if ( $series['slug'] === $slug ) {
					update_user_meta( get_current_user_id(), self::LAST_SERIES_META, $slug );

					return $series;
				}
			}
		}

		$remembered = (string) get_user_meta( get_current_user_id(), self::LAST_SERIES_META, true );
		foreach ( $all_series as $series ) {
			if ( $series['slug'] === $remembered ) {
				return $series;
			}
		}

		// Nothing remembered: the season being run is a better guess than the
		// most recently created one, which may be next year's, set up early.
		foreach ( $all_series as $series ) {
			if ( ! empty( $series['is_active'] ) ) {
				return $series;
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
							<?php
							echo esc_html(
								empty( $series['is_active'] )
									? $series['name']
									: sprintf(
										/* translators: %s: series name. */
										__( '%s (current)', 'mvoc-streeto' ),
										$series['name']
									)
							);
							?>
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
					$example = $this->default_start_year( $existing );
					printf(
						/* translators: 1: example series name, 2: example slug. */
						esc_html__( 'Everything is derived from that year. %1$s becomes the name, %2$s the shortcode slug, and the eight fixtures are dated to the third Tuesday of each month from September to April — which is where all eight of the 2026/27 season\'s published dates fall. Names, dates and venues all stay editable, so move anything that clashes.', 'mvoc-streeto' ),
						'<strong>' . esc_html( Season::name( $example ) ) . '</strong>',
						'<code>' . esc_html( Season::slug( $example ) ) . '</code>'
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
		if ( ! isset( $_POST['mvoc_streeto_action'] ) && ! isset( $_POST['delete_event'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

		$action = isset( $_POST['mvoc_streeto_action'] )
			? sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) )
			: '';

		if ( 'add_series' === $action || 'seed' === $action ) {
			return $this->create_season();
		}

		// Its own field rather than an id encoded into the action: sanitize_key
		// strips the separator, so "delete:12" silently arrived as "delete12"
		// and the delete never fired.
		if ( isset( $_POST['delete_event'] ) ) {
			return $this->delete_event( (int) $_POST['delete_event'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$series = $this->current_series( $this->repo->all_series() );
		if ( ! $series ) {
			return '';
		}

		if ( 'make_active' === $action ) {
			$this->repo->set_active( (int) $series['id'] );

			return sprintf(
				/* translators: %s: series name. */
				__( '%s is now the current season.', 'mvoc-streeto' ),
				$series['name']
			);
		}

		if ( 'suggest_names' === $action ) {
			return $this->fill_suggested_names( $series );
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
	 * Fill in suggested MapRun names, without touching anything already set.
	 *
	 * Only ever fills a gap. A name the co-ordinator has typed is the one that
	 * matches MapRun, and a suggestion is a guess from one example — so the
	 * guess must never win.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function fill_suggested_names( array $series ): string {
		$filled = 0;

		foreach ( $this->repo->events( (int) $series['id'] ) as $event ) {
			$existing = array();
			foreach ( $this->repo->sources( (int) $event['id'] ) as $source ) {
				$existing[ $source['course_label'] ] = $source['maprun_event_name'];
			}

			$names = array();

			foreach ( array( '60', '40' ) as $course ) {
				if ( ! empty( $existing[ $course ] ) ) {
					$names[ $course ] = $existing[ $course ];
					continue;
				}

				$suggested = MapRun_Name::suggest(
					(string) ( $event['venue'] ?: $event['title'] ),
					(string) $event['event_date'],
					$course
				);

				// The 40-minute event often does not exist yet, so only the
				// 60 is filled by default; the short course is left for the
				// co-ordinator once MapRun has one.
				$names[ $course ] = ( '' !== $suggested && '60' === $course ) ? $suggested : '';

				if ( '' !== $names[ $course ] ) {
					++$filled;
				}
			}

			$this->repo->save_sources( (int) $event['id'], $names );
		}

		return sprintf(
			/* translators: %d: number of names filled in. */
			_n(
				'Filled in %d suggested name. Check it against MapRun before importing.',
				'Filled in %d suggested names. Check them against MapRun before importing.',
				$filled,
				'mvoc-streeto'
			),
			$filled
		);
	}

	/**
	 * Save the series name, every event, and their MapRun sources.
	 *
	 * @param array<string,mixed> $series Series row.
	 */
	private function save_all( array $series ): string {
		global $wpdb;

		$changes = array();

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

				$changes[] = __( 'renamed the series', 'mvoc-streeto' );
			}
		}

		if ( ! isset( $_POST['events'] ) || ! is_array( $_POST['events'] ) ) {
			return $changes ? ucfirst( implode( ', ', $changes ) ) . '.' : __( 'Nothing to change.', 'mvoc-streeto' );
		}

		$events_changed = 0;
		$names_set      = 0;
		$names_removed  = 0;
		$kept           = array();

		foreach ( wp_unslash( $_POST['events'] ) as $number => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$event = $this->repo->find_event( $series['id'], (int) $number );

			if ( ! $event || ! is_array( $fields ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $fields['title'] ?? '' ) );

			$updated = array(
				'event_number'            => (int) $number,
				// Saved as submitted, empty included. This used to fall back to
				// the stored title whenever the box was blank, which made
				// clearing an event's name impossible: it silently came back on
				// every save. Somewhere to display is a rendering concern, and
				// is handled by falling back to "Event N" at that point.
				'title'                   => $title,
				'venue'                   => $title,
				'event_date'              => sanitize_text_field( (string) ( $fields['event_date'] ?? '' ) ),
				'organiser_competitor_id' => $this->resolve_organiser( $fields ),
				// A published event keeps its status: taking it back to draft
				// is done on the review screen, deliberately, rather than as a
				// side effect of saving a date.
				'status'                  => $event['is_published']
					? $event['status']
					: $this->requested_status( $fields, (string) $event['status'] ),
			);

			// Compare before writing, so the message can report what actually
			// changed. Saying "saved 8 events" after one edit is technically
			// true and useless: it gives no way to tell a change took.
			if ( self::event_differs( $event, $updated ) ) {
				++$events_changed;
			}

			$this->repo->save_event( $series['id'], $updated );

			// Every course is submitted, blanks included: an empty box means
			// "remove this", which is only expressible if it is sent.
			$names = array();
			foreach ( array( '60', '40' ) as $course ) {
				$names[ $course ] = sanitize_text_field( (string) ( $fields[ 'source_' . $course ] ?? '' ) );
			}

			$result         = $this->repo->save_sources( (int) $event['id'], $names );
			$names_set     += $result['saved'];
			$names_removed += $result['removed'];
			$kept           = array_merge( $kept, $result['kept'] );
		}

		if ( $events_changed ) {
			$changes[] = sprintf(
				/* translators: %d: number of events changed. */
				_n( 'updated %d event', 'updated %d events', $events_changed, 'mvoc-streeto' ),
				$events_changed
			);
		}

		if ( $names_set ) {
			$changes[] = sprintf(
				/* translators: %d: number of MapRun event names set. */
				_n( 'set %d MapRun event name', 'set %d MapRun event names', $names_set, 'mvoc-streeto' ),
				$names_set
			);
		}

		if ( $names_removed ) {
			// Positive confirmation, because the alternative is silence - and
			// silence after clearing a box is exactly how the earlier bug felt.
			$changes[] = sprintf(
				/* translators: %d: number of MapRun event names removed. */
				_n( 'removed %d MapRun event name', 'removed %d MapRun event names', $names_removed, 'mvoc-streeto' ),
				$names_removed
			);
		}

		$blocked = $kept
			? sprintf(
				/* translators: %s: course lengths, e.g. "60, 40". */
				__( 'The MapRun name for the %s minute course could not be cleared, because results have already been imported through it and the name is the only record of where they came from. Remove or exclude those results first if you really need to clear it.', 'mvoc-streeto' ),
				implode( ', ', array_unique( $kept ) )
			)
			: '';

		if ( ! $changes ) {
			// "Nothing to change" alongside an explanation of something that
			// was deliberately not done reads as a contradiction. Where an edit
			// was blocked, that is the message.
			return $blocked ?: __( 'Nothing to change — everything was already as submitted.', 'mvoc-streeto' );
		}

		return trim( ucfirst( implode( ', ', $changes ) ) . '. ' . $blocked );
	}

	/**
	 * Whether a submitted event actually differs from the stored one.
	 *
	 * @param array<string,mixed> $stored  Event as it is.
	 * @param array<string,mixed> $updated Event as submitted.
	 */
	private static function event_differs( array $stored, array $updated ): bool {
		foreach ( array( 'title', 'venue', 'event_date', 'status' ) as $field ) {
			if ( (string) ( $stored[ $field ] ?? '' ) !== (string) ( $updated[ $field ] ?? '' ) ) {
				return true;
			}
		}

		return (int) ( $stored['organiser_competitor_id'] ?? 0 ) !== (int) ( $updated['organiser_competitor_id'] ?? 0 );
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
