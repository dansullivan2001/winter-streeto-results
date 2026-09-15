<?php
/**
 * Event review: import, correct, preview, publish.
 *
 * Laid out in the order the co-ordinator actually works — import, resolve the
 * things that need a human, correct the rows, then publish. Nothing reaches the
 * public page until the last step.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Duplicate_Detector;
use MVOC\StreetO\Domain\Event_Presenter;
use MVOC\StreetO\Domain\Manual_Entry_Parser;
use MVOC\StreetO\Domain\Repeat_Entry_Detector;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\Importer;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * The screen the co-ordinator spends the evening on.
 */
class Event_Review_Screen {

	private const NONCE = 'mvoc_streeto_review';

	private Events_Repo $events;

	private Results_Repo $results;

	private Competitors_Repo $competitors;

	private Importer $importer;

	/**
	 * @param Events_Repo|null      $events      Events persistence.
	 * @param Results_Repo|null     $results     Results persistence.
	 * @param Competitors_Repo|null $competitors Competitor persistence.
	 * @param Importer|null         $importer    Import pipeline.
	 */
	public function __construct(
		?Events_Repo $events = null,
		?Results_Repo $results = null,
		?Competitors_Repo $competitors = null,
		?Importer $importer = null
	) {
		$this->events      = $events ?? new Events_Repo();
		$this->results     = $results ?? new Results_Repo();
		$this->competitors = $competitors ?? new Competitors_Repo();
		$this->importer    = $importer ?? new Importer();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$event_id = isset( $_GET['event'] ) ? (int) $_GET['event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event    = $event_id ? $this->events->find_event_by_id( $event_id ) : null;

		if ( ! $event ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Event results', 'mvoc-streeto' ); ?></h1>
				<p><?php esc_html_e( 'Choose an event to import, correct and publish its results.', 'mvoc-streeto' ); ?></p>
				<?php $this->render_event_picker( null ); ?>
			</div>
			<?php

			return;
		}

		$feedback = $this->handle_post( $event );
		$event    = $this->events->find_event_by_id( $event_id ) ?? $event;

		$rows               = $this->results->for_event( $event_id );
		$config             = $this->events->scoring_config( $this->series_for( $event ) );
		$effective          = Results_Repo::effective_rows( $rows, $config );
		$scored             = ( new Scoring_Engine( $config ) )->score_event( $effective );
		$competitors        = $this->competitors->all();
		$competitor_names   = array_column( $competitors, 'display_name', 'id' );
		$current_organisers = $this->events->organisers( $event_id );
		$duplicates         = ( new Duplicate_Detector() )->find( $effective );

		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: 1: event number, 2: event title. */
					esc_html__( 'Event %1$d — %2$s', 'mvoc-streeto' ),
					(int) $event['event_number'],
					esc_html( $event['label'] )
				);
				?>
			</h1>

			<?php $this->render_feedback( $feedback ); ?>

			<?php $this->render_event_picker( $event ); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'Results post', 'mvoc-streeto' ); ?></h2>
				<?php $this->render_page_section( $event ); ?>

				<h2><?php esc_html_e( '1. Import', 'mvoc-streeto' ); ?></h2>
				<p>
					<button type="submit" name="mvoc_streeto_action" value="import" class="button button-primary">
						<?php esc_html_e( 'Fetch from MapRun', 'mvoc-streeto' ); ?>
					</button>
					<?php if ( $event['last_fetched_at'] ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: timestamp. */
								esc_html__( 'Last imported %s. Re-importing keeps every correction below.', 'mvoc-streeto' ),
								esc_html( (string) $event['last_fetched_at'] )
							);
							?>
						</span>
					<?php endif; ?>
				</p>
				<?php $sources = $this->events->sources( $event_id ); ?>
				<details <?php echo $sources ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Paste JSON instead', 'mvoc-streeto' ); ?></summary>
					<p class="description">
						<?php esc_html_e( 'Use this where the server cannot reach MapRun. It produces exactly the same result as fetching: the response goes through identical validation and parsing.', 'mvoc-streeto' ); ?>
					</p>

					<?php if ( $sources ) : ?>
						<?php
						// The course is chosen here rather than assumed, because a
						// paste carries no course of its own. Imported against the
						// wrong source it would be scored on the wrong scale and,
						// since the reconciler matches on MapRun ids, would withdraw
						// every row already stored for that course. Pre-selected only
						// when there is one course to choose, so a real choice is
						// never made by default.
						?>
						<ol>
							<?php foreach ( $sources as $source ) : ?>
								<?php $url = \MVOC\StreetO\MapRun\Client::url_for( (string) $source['maprun_event_name'] ); ?>
								<li style="margin-bottom:0.75em;">
									<label>
										<input type="radio" name="paste_source"
											value="<?php echo esc_attr( (string) $source['id'] ); ?>"
											<?php checked( 1, count( $sources ) ); ?> />
										<?php
										printf(
											/* translators: %s: course label such as 60. */
											esc_html__( '%s minute course —', 'mvoc-streeto' ),
											esc_html( (string) $source['course_label'] )
										);
										?>
									</label>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'open in a new tab', 'mvoc-streeto' ); ?>
									</a>
									<?php esc_html_e( 'then copy everything and paste it below.', 'mvoc-streeto' ); ?>
									<br />
									<input type="text" class="large-text code" readonly
										onclick="this.select();"
										value="<?php echo esc_attr( $url ); ?>" />
								</li>
							<?php endforeach; ?>
						</ol>
						<?php if ( count( $sources ) > 1 ) : ?>
							<p class="description">
								<?php esc_html_e( 'Tick the course you opened above. The pasted results are imported against that course only; the other is left exactly as it is.', 'mvoc-streeto' ); ?>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'No MapRun event name is set for this event yet — add one on the Series and events screen and the exact URL to open will appear here.', 'mvoc-streeto' ); ?>
						</p>
					<?php endif; ?>

					<textarea name="pasted_json" rows="5" class="large-text code"
						placeholder="{&quot;errorFlag&quot;:false,&quot;results&quot;:[ ... ]}"></textarea>
					<p>
						<button type="submit" name="mvoc_streeto_action" value="import_paste" class="button button-primary">
							<?php esc_html_e( 'Import pasted JSON', 'mvoc-streeto' ); ?>
						</button>
					</p>
				</details>

				<h2><?php esc_html_e( '2. Duplicates', 'mvoc-streeto' ); ?></h2>
				<?php $this->render_duplicates( $duplicates ); ?>

				<h2><?php esc_html_e( '3. Results', 'mvoc-streeto' ); ?></h2>
				<?php $this->render_rows( $scored, $rows, $competitors, $config, (string) ( $event['event_date'] ?? '' ) ); ?>

				<h2><?php esc_html_e( '4. Add runners by hand', 'mvoc-streeto' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'For a runner whose phone failed, or for a whole event MapRun cannot score. Hand-added rows are never touched by a later import.', 'mvoc-streeto' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="manual-name"><?php esc_html_e( 'One runner', 'mvoc-streeto' ); ?></label></th>
						<td>
							<input type="text" id="manual-name" name="manual[name]" class="regular-text"
								placeholder="<?php esc_attr_e( 'Name', 'mvoc-streeto' ); ?>" />
							<input type="number" name="manual[score]" style="width:7em" step="10"
								placeholder="<?php esc_attr_e( 'Score', 'mvoc-streeto' ); ?>" />
							<input type="number" name="manual[penalty]" style="width:7em" step="1" min="0"
								placeholder="<?php esc_attr_e( 'Penalty', 'mvoc-streeto' ); ?>" />
							<select name="manual[course]">
								<?php foreach ( $config->course_labels() as $course ) : ?>
									<option value="<?php echo esc_attr( $course ); ?>"><?php echo esc_html( $course ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="manual-paste"><?php esc_html_e( 'Or paste a list', 'mvoc-streeto' ); ?></label></th>
						<td>
							<textarea id="manual-paste" name="manual_paste" rows="5" class="large-text code"
								placeholder="<?php esc_attr_e( "Name, Score, Penalty\nDave Smith, 640, 0", 'mvoc-streeto' ); ?>"></textarea>
							<p class="description">
								<?php esc_html_e( 'One runner per line: name, then score, penalty and course if you have them. Tabs or commas both work, so a column copied straight out of a spreadsheet pastes in as it is. A header row is ignored.', 'mvoc-streeto' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" name="mvoc_streeto_action" value="add_manual" class="button">
						<?php esc_html_e( 'Add runners', 'mvoc-streeto' ); ?>
					</button>
				</p>

				<h2><?php esc_html_e( '5. Organiser', 'mvoc-streeto' ); ?></h2>
				<p>
					<?php if ( $current_organisers ) : ?>
						<?php foreach ( $current_organisers as $organiser_id ) : ?>
							<label style="display:block;">
								<input type="checkbox" name="<?php echo esc_attr( 'keep_organiser[' . $organiser_id . ']' ); ?>"
									value="1" checked="checked" />
								<?php echo esc_html( $competitor_names[ $organiser_id ] ?? '#' . $organiser_id ); ?>
							</label>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="description"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></span><br />
					<?php endif; ?>
					<input type="text" style="width:16em" list="mvoc-streeto-competitors"
						name="organiser_name" placeholder="<?php esc_attr_e( 'add organiser', 'mvoc-streeto' ); ?>" />
					<datalist id="mvoc-streeto-competitors">
						<?php foreach ( $competitors as $competitor ) : ?>
							<option value="<?php echo esc_attr( $competitor['display_name'] ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<br />
					<span class="description">
						<?php esc_html_e( 'Listed on the results but not ranked. They score their best result again in the league. Almost always one person — untick to remove, or type a name to add another where an event was run jointly.', 'mvoc-streeto' ); ?>
					</span>
				</p>

				<h2><?php esc_html_e( '6. Save and publish', 'mvoc-streeto' ); ?></h2>
				<p>
					<button type="submit" name="mvoc_streeto_action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save corrections', 'mvoc-streeto' ); ?>
					</button>
					<?php if ( $event['is_published'] ) : ?>
						<button type="submit" name="mvoc_streeto_action" value="unpublish" class="button">
							<?php esc_html_e( 'Return to draft', 'mvoc-streeto' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'Published and live.', 'mvoc-streeto' ); ?></span>
					<?php else : ?>
						<button type="submit" name="mvoc_streeto_action" value="publish" class="button">
							<?php esc_html_e( 'Save and publish', 'mvoc-streeto' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'Nothing is public until you publish.', 'mvoc-streeto' ); ?></span>
					<?php endif; ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Preview', 'mvoc-streeto' ); ?></h2>
			<?php $this->render_preview( $scored, $config, $event ); ?>
		</div>
		<?php
	}

	/**
	 * The "create page" / "edit page" control at the top of the screen.
	 *
	 * @param array<string,mixed> $event Event row.
	 */
	private function render_page_section( array $event ): void {
		$page = $event['page_id'] ? get_post( (int) $event['page_id'] ) : null;

		if ( $page ) {
			?>
			<p>
				<?php
				printf(
					/* translators: %s: post status, e.g. "draft". */
					esc_html__( 'Status: %s', 'mvoc-streeto' ),
					esc_html( $page->post_status )
				);
				?>
				&mdash;
				<a href="<?php echo esc_url( (string) get_edit_post_link( $page->ID ) ); ?>">
					<?php esc_html_e( 'Edit post', 'mvoc-streeto' ); ?>
				</a>
				<?php if ( 'publish' === $page->post_status ) : ?>
					&mdash;
					<a href="<?php echo esc_url( (string) get_permalink( $page ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View', 'mvoc-streeto' ); ?>
					</a>
				<?php endif; ?>
			</p>
			<?php
			return;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			?>
			<p>
				<button type="submit" name="mvoc_streeto_action" value="create_page" class="button">
					<?php esc_html_e( 'Create draft post', 'mvoc-streeto' ); ?>
				</button>
				<span class="description">
					<?php esc_html_e( 'A draft post, categorised News and Results and tagged StreetO, with the title, a placeholder for the report, and the results and league shortcodes already filled in.', 'mvoc-streeto' ); ?>
				</span>
			</p>
			<?php
			return;
		}

		?>
		<p class="description">
			<?php esc_html_e( 'You do not have permission to create posts — ask an administrator to create this event\'s results post.', 'mvoc-streeto' ); ?>
		</p>
		<?php
	}

	/**
	 * The picker for choosing which event to work on.
	 *
	 * This screen is in the menu, so it is opened with no event at least as
	 * often as it is clicked through to from the events table - and what met
	 * you there was a sentence naming another screen. It is also above a chosen
	 * event, because the next event to look at is otherwise two screens away.
	 *
	 * The active season only - that is the season being run, and the one whose
	 * events are being imported. An event from an earlier season, reached from
	 * that season's own screen, adds its season to the list rather than being
	 * missing from a picker that claims to show what is on screen.
	 *
	 * @param array<string,mixed>|null $current The event being reviewed, if any.
	 */
	private function render_event_picker( ?array $current ): void {
		$seasons  = array();
		$shown    = array();
		$event_id = (int) ( $current['id'] ?? 0 );

		// Nothing marked active is a half-set-up site, not an error: fall back
		// to the newest season, which all_series() returns first.
		$active = $this->events->active_series() ?? ( $this->events->all_series()[0] ?? null );
		$viewed = $current ? ( $this->series_for( $current ) ?: null ) : null;

		foreach ( array( $active, $viewed ) as $series ) {
			if ( ! $series || in_array( (int) $series['id'], $shown, true ) ) {
				continue;
			}

			$events = $this->events->events( (int) $series['id'] );

			if ( $events ) {
				$shown[]   = (int) $series['id'];
				$seasons[] = array(
					'name'   => (string) $series['name'],
					'events' => $events,
				);
			}
		}

		if ( ! $seasons ) {
			?>
			<p><?php esc_html_e( 'No events yet. They are created with a season, on the Series and events screen.', 'mvoc-streeto' ); ?></p>
			<?php
			return;
		}
		?>
		<form method="get" style="margin:1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Menu::SLUG . '-review' ); ?>" />
			<label for="mvoc-review-event"><strong><?php esc_html_e( 'Event', 'mvoc-streeto' ); ?></strong></label>
			<select id="mvoc-review-event" name="event" onchange="this.form.submit()">
				<?php if ( ! $event_id ) : ?>
					<option value=""><?php esc_html_e( '— choose an event —', 'mvoc-streeto' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $seasons as $season ) : ?>
					<optgroup label="<?php echo esc_attr( $season['name'] ); ?>">
						<?php foreach ( $season['events'] as $option ) : ?>
							<option value="<?php echo esc_attr( (string) $option['id'] ); ?>"
								<?php selected( $event_id, (int) $option['id'] ); ?>>
								<?php echo esc_html( self::event_option_label( $option ) ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
			<noscript>
				<button type="submit" class="button"><?php esc_html_e( 'Go', 'mvoc-streeto' ); ?></button>
			</noscript>
		</form>
		<?php
	}

	/**
	 * One event as it reads in the picker: number, name and date.
	 *
	 * The date is there because the event being looked for is usually "the one
	 * run last night", and a venue on its own does not answer that.
	 *
	 * @param array<string,mixed> $event Event row.
	 */
	private static function event_option_label( array $event ): string {
		$label = sprintf(
			/* translators: 1: event number, 2: event title. */
			__( '%1$d — %2$s', 'mvoc-streeto' ),
			(int) $event['event_number'],
			(string) $event['label']
		);

		$date = $event['event_date']
			? mysql2date( get_option( 'date_format' ), (string) $event['event_date'] )
			: '';

		if ( '' === $date ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: event number and title, 2: the date it is run. */
			__( '%1$s (%2$s)', 'mvoc-streeto' ),
			$label,
			$date
		);
	}

	/**
	 * The series a given event belongs to.
	 *
	 * @param array<string,mixed> $event Event row.
	 * @return array<string,mixed>
	 */
	private function series_for( array $event ): array {
		foreach ( $this->events->all_series() as $series ) {
			if ( (int) $series['id'] === (int) $event['series_id'] ) {
				return $series;
			}
		}

		return array();
	}

	/**
	 * Scoring rules for the event being viewed.
	 *
	 * The POST handlers work from the event id in the query string rather than
	 * a row they were handed, and they need the same rules the table was
	 * rendered with — a penalty compared against a differently-configured one
	 * would look like a change and be recorded as a correction nobody made.
	 */
	private function config(): Scoring_Config {
		$event = $this->events->find_event_by_id( $this->event_id() );

		return $this->events->scoring_config( $event ? $this->series_for( $event ) : array() );
	}

	/**
	 * Show what an import did.
	 *
	 * @param array<string,mixed> $feedback Result of handle_post().
	 */
	private function render_feedback( array $feedback ): void {
		foreach ( $feedback['errors'] ?? array() as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		foreach ( $feedback['warnings'] ?? array() as $warning ) {
			echo '<div class="notice notice-warning"><p><strong>'
				. esc_html__( 'MapRun warning:', 'mvoc-streeto' ) . '</strong> '
				. esc_html( $warning ) . '</p></div>';
		}

		foreach ( $feedback['recovered'] ?? array() as $course => $count ) {
			// Deliberately its own notice rather than a line in the summary.
			// These rows carry a score the plugin worked out, not one MapRun
			// published, and that is the single thing about this import a
			// co-ordinator most needs to know before pressing publish.
			echo '<div class="notice notice-warning"><p>'
				. esc_html(
					sprintf(
						/* translators: 1: number of rows, 2: course label, e.g. 60. */
						_n(
							'MapRun reported no score for %1$d run on the %2$s. Its score has been worked out from the controls it punched — check it before publishing.',
							'MapRun reported no score for %1$d runs on the %2$s. Their scores have been worked out from the controls they punched — check them before publishing.',
							(int) $count,
							'mvoc-streeto'
						),
						(int) $count,
						(string) $course
					)
				)
				. '</p></div>';
		}

		if ( ! empty( $feedback['notice'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $feedback['notice'] ) . '</p></div>';
		}

		if ( ! empty( $feedback['unmatched'] ) ) {
			$url = add_query_arg(
				array(
					'page'  => Admin_Menu::SLUG . '-names',
					'event' => $this->event_id(),
				),
				admin_url( 'admin.php' )
			);

			echo '<div class="notice notice-warning"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of unrecognised names. */
						_n(
							'%d name is not recognised yet and will not score until confirmed.',
							'%d names are not recognised yet and will not score until confirmed.',
							count( $feedback['unmatched'] ),
							'mvoc-streeto'
						),
						count( $feedback['unmatched'] )
					)
				)
				. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Confirm names', 'mvoc-streeto' ) . '</a>'
				. '</p></div>';
		}
	}

	/**
	 * Render the duplicates section: what still needs deciding, then what does not.
	 *
	 * A cluster the co-ordinator has already answered stays on the screen but
	 * folds away. It cannot simply disappear: this card is the only place the
	 * course revisions are shown, so revisiting the choice anywhere else would
	 * mean picking between two bare scores. Nor can it sit here looking
	 * untouched, which is what it did before the decision was rendered — the
	 * screen invited the same decision to be made over and over.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $clusters Duplicate clusters.
	 */
	private function render_duplicates( array $clusters ): void {
		$detector = new Duplicate_Detector();

		$outstanding = array();
		$decided     = array();

		foreach ( $clusters as $cluster ) {
			$described = $detector->describe( $cluster );

			if ( $described['is_decided'] ) {
				$decided[] = $described;
			} else {
				$outstanding[] = $described;
			}
		}

		if ( ! $outstanding && ! $decided ) {
			echo '<p class="description">' . esc_html__( 'None found.', 'mvoc-streeto' ) . '</p>';

			return;
		}

		if ( $outstanding ) {
			echo '<p class="description">'
				. esc_html__( 'The same run recorded more than once — usually scored against two course revisions. Keep one; the other is excluded.', 'mvoc-streeto' )
				. '</p>';

			foreach ( $outstanding as $described ) {
				$this->render_duplicate_card( $described );
			}
		} elseif ( $decided ) {
			echo '<p class="description">'
				. esc_html__( 'All decided — nothing here needs your attention.', 'mvoc-streeto' )
				. '</p>';
		}

		if ( $decided ) {
			$this->render_decided_duplicates( $decided );
		}

		// Sections 1 and 4 each carry the button for their own action; without
		// one here the only way to commit a choice made in this panel is the
		// save at step 6, the far side of a sixty-row results table. Same
		// action as that button, deliberately: one save, not two.
		echo '<p><button type="submit" name="mvoc_streeto_action" value="save" class="button">'
			. esc_html__( 'Save corrections', 'mvoc-streeto' )
			. '</button> <span class="description">'
			. esc_html__( 'The step 6 button, brought up here — it saves everything on this screen, not only this choice.', 'mvoc-streeto' )
			. '</span></p>';
	}

	/**
	 * The clusters that have already been answered, folded away.
	 *
	 * @param array<int,array<string,mixed>> $decided Described clusters.
	 */
	private function render_decided_duplicates( array $decided ): void {
		echo '<details><summary>'
			. esc_html(
				sprintf(
					/* translators: %d: number of duplicate clusters already resolved. */
					_n( '%d already decided', '%d already decided', count( $decided ), 'mvoc-streeto' ),
					count( $decided )
				)
			)
			. '</summary>';

		echo '<p class="description">'
			. esc_html__( 'Kept for the record, and still changeable: pick the other scoring and save.', 'mvoc-streeto' )
			. '</p>';

		foreach ( $decided as $described ) {
			$this->render_duplicate_card( $described );
		}

		echo '</details>';
	}

	/**
	 * One duplicate cluster as a set of radio buttons.
	 *
	 * @param array<string,mixed> $described A cluster from Duplicate_Detector::describe().
	 */
	private function render_duplicate_card( array $described ): void {
		// Keyed by result id, not by name: two runners can share a name, and
		// two clusters sharing a field name would share a radio group, so
		// answering one would silently unanswer the other. Only the submitted
		// values are read, so the key just has to be unique.
		$first = (string) ( $described['options'][0]['result_id'] ?? 0 );
		$field = 'keep[' . rawurlencode( $first ) . ']';

		echo '<div class="card" style="max-width:none;"><h3 style="margin-top:0;">'
			. esc_html( $described['name'] ) . ' <span class="description">'
			. esc_html( $described['time_display'] ) . '</span></h3>';

		// describe()'s options, not the cluster: it puts the likelier
		// candidate — the later course revision — first, and iterating the
		// cluster would throw that ordering away.
		foreach ( $described['options'] as $option ) {
			$label = null === $option['revision']
				? __( 'no revision', 'mvoc-streeto' )
				: sprintf( 'Rev%d', (int) $option['revision'] );

			printf(
				'<p><label><input type="radio" name="%s" value="%s"%s /> %s — %s%s</label></p>',
				esc_attr( $field ),
				esc_attr( (string) $option['result_id'] ),
				// The kept option is the one still counting, so the decision is
				// visible rather than having to be remembered — and re-saving
				// the screen re-states it instead of silently unmaking it.
				$described['is_decided'] && empty( $option['is_excluded'] ) ? ' checked="checked"' : '',
				esc_html( sprintf( '%s pts', null === $option['score'] ? '—' : (string) $option['score'] ) ),
				esc_html( $label ),
				! empty( $option['is_excluded'] )
					? ' <span class="description">' . esc_html__( '— excluded', 'mvoc-streeto' ) . '</span>'
					: ''
			);
		}

		if ( ! $described['is_decided'] ) {
			// Not "nothing is chosen": the moment one is picked that reads as
			// though the click did not register. It says what is true either
			// way — nothing is picked *for* you, and picking is not saving.
			echo '<p class="description">'
				. esc_html__( 'Which scoring is right is your call — nothing is picked by default. Your choice takes effect when you save.', 'mvoc-streeto' )
				. '</p>';
		}

		echo '</div>';
	}

	/**
	 * Warn about rows that are scoring without having recorded a run.
	 *
	 * The per-row note below says the same thing, but a real event runs to
	 * sixty rows and the co-ordinator is reading them for names and scores, not
	 * hunting for a blank time. These rows rank on their score alone, so
	 * missing one publishes it — hence saying it once, up front, with the names
	 * to look for.
	 *
	 * Nothing is excluded here. Which of these is a broken upload and which is
	 * a real run MapRun mistimed is a judgement from the night, not one the
	 * plugin can make.
	 *
	 * Rows already ticked as checked are left out, as excluded ones are. Both
	 * have been answered — one by dropping the row, one by keeping it — and a
	 * warning that cannot be answered is one the co-ordinator learns to read
	 * past, taking the next real one with it.
	 *
	 * @param array<int,array<string,mixed>> $scored Scored rows.
	 */
	private function render_zero_time_notice( array $scored ): void {
		$names = array();

		foreach ( $scored as $row ) {
			if ( ! empty( $row['is_zero_time'] ) && ! $this->is_answered( $row ) ) {
				$names[] = (string) $row['display_name'];
			}
		}

		if ( ! $names ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: how many rows carry a score but no elapsed time. */
					_n(
						'%d row is scoring with no time recorded.',
						'%d rows are scoring with no time recorded.',
						count( $names ),
						'mvoc-streeto'
					),
					count( $names )
				)
			)
			. '</strong> '
			. esc_html( implode( ', ', $names ) ) . '. '
			. esc_html__( 'MapRun returned a score with an elapsed time of zero — usually a failed upload it did not mark as one. Check each against the night, then tick Exclude below for any that did not run, or "Checked" for any that did. They count towards the league until you exclude them.', 'mvoc-streeto' )
			. '</p></div>';
	}

	/**
	 * Warn about runs recorded on a day other than the event's.
	 *
	 * A MapRun course stays live after the night, so anyone with the app can run
	 * it later and their result arrives in the same response, scored and ranked
	 * like everyone else's. The duplicate detector cannot help: such a row is
	 * not a duplicate of anything.
	 *
	 * Silent where the event has no date set, which is the honest answer — there
	 * is then nothing to be off.
	 *
	 * @param array<int,array<string,mixed>> $scored     Scored rows.
	 * @param string                         $event_date Date the event was held, as `Y-m-d`.
	 */
	private function render_off_date_notice( array $scored, string $event_date ): void {
		$late = array();

		foreach ( $scored as $row ) {
			if ( Parser::is_off_date( $row['run_date'] ?? null, $event_date ) && ! $this->is_answered( $row ) ) {
				$late[] = sprintf(
					/* translators: 1: runner's name, 2: date the run was recorded. */
					__( '%1$s (%2$s)', 'mvoc-streeto' ),
					(string) $row['display_name'],
					mysql2date( 'j F Y', (string) $row['run_date'] )
				);
			}
		}

		if ( ! $late ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: how many runs were recorded on another day. */
					_n(
						'%d run was not done on the night.',
						'%d runs were not done on the night.',
						count( $late ),
						'mvoc-streeto'
					),
					count( $late )
				)
			)
			. '</strong> '
			. esc_html( implode( ', ', $late ) ) . '. '
			. esc_html__( 'The course stayed live, so these were run later and MapRun returned them with everyone else. They score in the league until you tick Exclude below, or "Checked" to let one stand.', 'mvoc-streeto' )
			. '</p></div>';
	}

	/**
	 * Warn where one runner is scoring more than once.
	 *
	 * The duplicate section above answers a different question and answers it
	 * strictly, so it says nothing about a runner whose second row is a stray
	 * recording or a second real run. Those rank, publish and take a place off
	 * everyone below them, and the league keeps whichever is better without
	 * anybody choosing it. One real event had eleven such runners out of
	 * fifty-one, three of which the duplicate detector could see.
	 *
	 * No tick answers this one. Two scoring rows for one runner is a mistake
	 * whichever way round it is, so the answer is always to exclude the rows
	 * that should not count.
	 *
	 * @param array<int,array<string,mixed>> $scored Scored rows.
	 */
	private function render_repeat_entry_notice( array $scored ): void {
		$clashes = ( new Repeat_Entry_Detector() )->find( $scored );

		if ( ! $clashes ) {
			return;
		}

		$named = array();

		foreach ( $clashes as $group ) {
			$described = Repeat_Entry_Detector::describe( $group );

			$named[] = sprintf(
				/* translators: 1: runner's name, 2: how many of their rows are scoring. */
				__( '%1$s (%2$d)', 'mvoc-streeto' ),
				$described['name'],
				$described['count']
			);
		}

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: how many runners have more than one scoring row. */
					_n(
						'%d runner is scoring more than once at this event.',
						'%d runners are scoring more than once at this event.',
						count( $named ),
						'mvoc-streeto'
					),
					count( $named )
				)
			)
			. '</strong> '
			. esc_html( implode( ', ', $named ) ) . '. '
			. esc_html__( 'Each row ranks on its own and all of them reach the published table, while the league counts only the best — a choice nobody made. Tick Exclude below on every row that should not score. A runner whose rows are not yet matched to a competitor is grouped by name here, so confirming their names may be the fix instead.', 'mvoc-streeto' )
			. '</p></div>';
	}

	/**
	 * Whether a flagged row has already been dealt with.
	 *
	 * Two answers, and the plugin does not care which was given: the row was
	 * excluded, so it no longer scores, or it was ticked as checked, so it
	 * scores on purpose. Either way somebody decided, and the warning has done
	 * its work.
	 *
	 * @param array<string,mixed> $row Scored row.
	 */
	private function is_answered( array $row ): bool {
		return ! empty( $row['is_excluded'] ) || ! empty( $row['is_checked'] );
	}

	/**
	 * The one rule the review table needs beyond core's own.
	 *
	 * Printed here rather than enqueued as a stylesheet because it is one
	 * selector that belongs to this table and no other screen, and a file would
	 * put the rule a long way from the markup that depends on it.
	 *
	 * The selector carries `table` and both of core's classes so it outranks
	 * `.widefat.striped > tbody > :nth-child(odd)`, which would otherwise win on
	 * every other row and highlight only half of them. The stripe is drawn as an
	 * inset shadow on the first cell, not a border on the row: `.widefat`
	 * collapses its borders, and a row-level border or shadow renders
	 * inconsistently once they are collapsed.
	 *
	 * Colour is never the only signal — every highlighted row also carries its
	 * note in words, and the notices above name the runners.
	 */
	private function render_row_styles(): void {
		?>
		<style>
			table.widefat.striped > tbody > tr.mvoc-needs-check,
			table.widefat > tbody > tr.mvoc-needs-check {
				background-color: #fcf9e8;
			}

			table.widefat > tbody > tr.mvoc-needs-check > td:first-child {
				box-shadow: inset 4px 0 0 #dba617;
			}

			/* Core styles p as a block, which would otherwise show the order
			   control before its script has made it work. */
			#mvoc-review-sort[hidden] {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * The editable results table.
	 *
	 * Elapsed time appears here and nowhere else. The published table
	 * deliberately omits it — the tie-break does not look at time, so printing
	 * it would only invite "why am I below someone slower?" — but the
	 * co-ordinator needs it, because it is what the late penalty is now
	 * computed from and the only way to check that penalty by eye.
	 *
	 * @param array<int,array<string,mixed>> $scored      Scored rows.
	 * @param array<int,array<string,mixed>> $stored      Stored rows, for flags.
	 * @param array<int,array<string,mixed>> $competitors Known competitors.
	 * @param Scoring_Config                 $config      Scoring rules.
	 * @param string                         $event_date  Date the event was held, as `Y-m-d`.
	 */
	private function render_rows( array $scored, array $stored, array $competitors, Scoring_Config $config, string $event_date = '' ): void {
		$flags = array_column( $stored, null, 'id' );

		// Once for the table, not once per row: the detector groups the whole
		// field, so asking it per row would regroup sixty rows sixty times.
		$elsewhere = ( new Repeat_Entry_Detector() )->elsewhere( $scored );

		$this->render_row_styles();
		$this->render_repeat_entry_notice( $scored );
		$this->render_zero_time_notice( $scored );
		$this->render_off_date_notice( $scored, $event_date );

		$this->render_sort_controls();

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Pos', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Competitor', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Course', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Time', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Score', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Penalty', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Total', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Exclude', 'mvoc-streeto' ); ?></th>
				</tr>
			</thead>
			<tbody id="mvoc-review-rows">
				<?php foreach ( array_values( $scored ) as $order => $row ) : ?>
					<?php
					$id       = (int) $row['result_id'];
					$flag     = $flags[ $id ] ?? array();
					$notes    = array();
					$off_date = Parser::is_off_date( $row['run_date'] ?? null, $event_date );

					// That runner's other rows that are scoring, which decides
					// what this row's note says more than anything about the
					// row itself does.
					$others = $elsewhere[ $id ] ?? array();

					if ( Parser::CLASSIFIER_FAILED === ( $row['classifier'] ?? '' ) ) {
						$notes[] = __( 'failed upload', 'mvoc-streeto' );
					}
					if ( Parser::CLASSIFIER_DNF === ( $row['classifier'] ?? '' ) ) {
						$notes[] = __( 'did not finish — no finish punch, so no score', 'mvoc-streeto' );
					}
					if ( ! empty( $row['is_withdrawn'] ) ) {
						$notes[] = __( 'not in the latest import — MapRun may have replaced the event', 'mvoc-streeto' );
					}
					if ( ! empty( $row['is_manual'] ) ) {
						$notes[] = __( 'added by hand', 'mvoc-streeto' );
					}
					if ( empty( $row['competitor_id'] ) ) {
						$notes[] = __( 'name not confirmed', 'mvoc-streeto' );
					}
					if ( ! empty( $row['is_zero_time'] ) ) {
						// The note stays whatever was decided, because the row
						// really is scoring without a time and the table should
						// say so. Only the clause after it changes, and each of
						// the three is the one true thing to say: an excluded
						// row is not being kept, so "checked" would misdescribe
						// it; a checked row has been dealt with, so "check
						// before publishing" would read as though the tick had
						// not registered.
						if ( ! empty( $row['is_excluded'] ) ) {
							$notes[] = __( 'scoring with no time recorded', 'mvoc-streeto' );
						} elseif ( ! empty( $row['is_checked'] ) ) {
							$notes[] = __( 'scoring with no time recorded — checked', 'mvoc-streeto' );
						} else {
							$notes[] = __( 'scoring with no time recorded — check before publishing', 'mvoc-streeto' );
						}
					}
					if ( $others && ! empty( $row['is_excluded'] ) ) {
						// The answer to "was excluding this a mistake?", given
						// where the co-ordinator is standing: fifty rows in,
						// looking at an excluded row that carries a score, with
						// no way to see whether its owner is already in the
						// table. Saying what they already have turns
						// un-excluding into a decision instead of a guess.
						$notes[] = sprintf(
							/* translators: %s: the runner's other results, e.g. "830 (18th), 730 (30th)". */
							__( 'this runner already scores here: %s', 'mvoc-streeto' ),
							implode(
								', ',
								array_map(
									static fn( array $other ): string => sprintf(
										/* translators: 1: score, 2: finishing position, e.g. 18th. */
										__( '%1$s (%2$s)', 'mvoc-streeto' ),
										(string) $other['score'],
										$other['position_label'] ?: '—'
									),
									$others
								)
							)
						);
					} elseif ( ! $others && ! empty( $row['is_excluded'] ) ) {
						// The other half of the same question, and the case
						// where un-excluding may well be right: excluded, and
						// nothing else of theirs counts, so as things stand
						// this runner is not in the results at all.
						$notes[] = __( 'nothing else of this runner\'s is scoring — they are not in the results as it stands', 'mvoc-streeto' );
					}
					if ( $others && empty( $row['is_excluded'] ) ) {
						$notes[] = __( 'this runner is scoring more than once — exclude the rows that should not count', 'mvoc-streeto' );
					}
					if ( $off_date ) {
						$notes[] = sprintf(
							/* translators: %s: the date the run was recorded, e.g. 12 April 2026. */
							__( 'run on %s, not the event date', 'mvoc-streeto' ),
							mysql2date( 'j F Y', (string) $row['run_date'] )
						);
					}

					$time_secs = $row['time_secs'] ?? null;
					$limit     = $config->time_limit_for_course( (string) ( $row['course_label'] ?? '' ) );
					$over      = ( null !== $time_secs && null !== $limit ) ? $time_secs - $limit : null;

					// Exactly the two rows the notices above name, and for the
					// same reason: each scores in the league on a figure nobody
					// has yet stood behind. A row that has been answered drops
					// out here as it does from the notices — it keeps its note
					// as the record of what was decided, but a stripe on a
					// decision already made would only dilute the ones still
					// waiting on one.
					// Two different kinds of flag, and they are kept apart on
					// purpose. The answerable ones can be right after a look at
					// the night, so they can be ticked off. A runner scoring
					// twice cannot be right either way round, so it is
					// highlighted but never offered the tick — the only answer
					// is to exclude a row, and an excluded row leaves the
					// clash by itself.
					$answerable  = ! empty( $row['is_zero_time'] ) || $off_date;
					$clashes     = $others && empty( $row['is_excluded'] );
					$needs_check = $clashes || ( $answerable && ! $this->is_answered( $row ) );

					// Not offered on an excluded row. "Keep this row" beside a
					// ticked Exclude is a contradiction the screen would then
					// have to resolve silently — and it does, in Exclude's
					// favour, since nothing in the scoring engine reads the
					// tick. Better that the two cannot be said at once.
					//
					// The stored value is untouched meanwhile, not cleared: the
					// hidden marker below is absent when there is no tick, so
					// save_corrections() leaves it alone and un-excluding the
					// row brings back the answer that was given.
					$offer_tick = $answerable && empty( $row['is_excluded'] );
					?>
					<tr<?php echo $needs_check ? ' class="mvoc-needs-check"' : ''; ?>
						data-sort-name="<?php echo esc_attr( self::surname_sort_key( $row ) ); ?>"
						data-sort-position="<?php echo esc_attr( (string) $order ); ?>">
						<td><?php echo esc_html( $row['position_label'] ?: '—' ); ?></td>
						<td>
							<?php echo esc_html( $row['display_name'] ); ?>
							<?php if ( $notes ) : ?>
								<br /><span class="description"><?php echo esc_html( implode( ', ', $notes ) ); ?></span>
							<?php endif; ?>
							<?php if ( $offer_tick ) : ?>
								<?php
								// Offered beside the warning it answers rather
								// than in a column of its own, which would be
								// empty on all but a row or two of a full field
								// — and next to Exclude it would read as a
								// second way to drop the runner.
								//
								// The hidden marker is what tells save_corrections()
								// that this row was asked the question at all.
								// Without it an unticked box and a row that
								// never offered one look identical on submit,
								// and a row whose warning has since cleared
								// would have its answer silently rewritten.
								?>
								<br />
								<label class="description">
									<input type="hidden" value="1"
										name="rows[<?php echo esc_attr( (string) $id ); ?>][checked_offered]" />
									<input type="checkbox" value="1"
										name="rows[<?php echo esc_attr( (string) $id ); ?>][checked]"
										<?php checked( ! empty( $row['is_checked'] ) ); ?> />
									<?php esc_html_e( 'Checked — keep this row', 'mvoc-streeto' ); ?>
								</label>
							<?php endif; ?>
						</td>
						<td>
							<select name="rows[<?php echo esc_attr( (string) $id ); ?>][competitor]">
								<option value="0"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></option>
								<?php foreach ( $competitors as $competitor ) : ?>
									<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"
										<?php selected( $row['competitor_id'], $competitor['id'] ); ?>>
										<?php echo esc_html( $competitor['display_name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select name="rows[<?php echo esc_attr( (string) $id ); ?>][course]">
								<?php foreach ( $config->course_labels() as $course ) : ?>
									<option value="<?php echo esc_attr( $course ); ?>"
										<?php selected( $row['course_label'], $course ); ?>>
										<?php echo esc_html( $course ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<?php echo esc_html( Parser::format_hhmmss( $time_secs ) ?: '—' ); ?>
							<?php if ( null !== $over && $over > 0 ) : ?>
								<br /><span class="description">
									<?php
									printf(
										/* translators: %s: how far over the time limit, e.g. 00:47. */
										esc_html__( '%s over', 'mvoc-streeto' ),
										esc_html( Parser::format_hhmmss( $over ) )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<input type="number" style="width:6em" step="10"
								name="rows[<?php echo esc_attr( (string) $id ); ?>][score]"
								value="<?php echo esc_attr( null === $row['score'] ? '' : (string) $row['score'] ); ?>" />
							<?php if ( Parser::SCORE_FIELD_PUNCHES === ( $row['score_source'] ?? '' ) ) : ?>
								<br /><span class="description">
									<?php esc_html_e( 'from punches', 'mvoc-streeto' ); ?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<input type="number" style="width:6em" step="1" min="0"
								name="rows[<?php echo esc_attr( (string) $id ); ?>][penalty]"
								value="<?php echo esc_attr( (string) ( $row['penalty'] ?? 0 ) ); ?>" />
							<?php if ( isset( $row['maprun_penalty'] ) && (int) $row['maprun_penalty'] !== (int) ( $row['penalty'] ?? 0 ) ) : ?>
								<br /><span class="description">
									<?php
									printf(
										/* translators: %d: the penalty MapRun charged. */
										esc_html__( 'MapRun charged %d', 'mvoc-streeto' ),
										(int) $row['maprun_penalty']
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( null === $row['total'] ? '—' : (string) $row['total'] ); ?></td>
						<td>
							<input type="checkbox" value="1"
								name="rows[<?php echo esc_attr( (string) $id ); ?>][excluded]"
								<?php checked( ! empty( $flag['is_excluded'] ) ); ?> />
							<?php if ( ! empty( $row['is_manual'] ) ) : ?>
								<br />
								<button type="submit" class="button-link delete"
									name="remove_row"
									value="<?php echo esc_attr( (string) $id ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Remove this hand-added runner?', 'mvoc-streeto' ) ); ?>');">
									<?php esc_html_e( 'Remove', 'mvoc-streeto' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $this->render_sort_script(); ?>

		<p>
			<label>
				<?php esc_html_e( 'Reason for these corrections', 'mvoc-streeto' ); ?>
				<input type="text" name="reason" class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. GPS dropout confirmed with runner', 'mvoc-streeto' ); ?>" />
			</label>
			<span class="description"><?php esc_html_e( 'Recorded against every change you save, so the table can always be explained later.', 'mvoc-streeto' ); ?></span>
		</p>
		<?php
	}

	/**
	 * What a row sorts on in surname order.
	 *
	 * The stored parts, not the displayed name split on a space: MapRun sends
	 * the two fields separately and the plugin keeps them that way, so a
	 * double-barrelled or multi-word surname sorts under the whole thing rather
	 * than under its last word. The first name is appended so that a family
	 * entering together keeps a sensible order among themselves.
	 *
	 * A name with no surname — a single-word MapRun entry — falls back to what
	 * the Name column shows, which is all there is of it. Sorting it among the
	 * surnames is right: that word is how it will be looked for on the list.
	 *
	 * @param array<string,mixed> $row Scored row.
	 */
	private static function surname_sort_key( array $row ): string {
		$surname = trim( (string) ( $row['surname'] ?? '' ) );

		if ( '' === $surname ) {
			return trim( (string) ( $row['display_name'] ?? '' ) );
		}

		return trim( $surname . ' ' . trim( (string) ( $row['first_name'] ?? '' ) ) );
	}

	/**
	 * The order control above the results table.
	 *
	 * The table is built in finishing order, which is the order it is published
	 * in and the order the scoring reads. It is the wrong order for the one
	 * check that has to be made against a piece of paper: the start list, which
	 * is by surname. Reading sixty rows in score order looking for the two
	 * names that are missing is where a runner gets lost.
	 *
	 * Display only. Nothing here is submitted, the stored order is untouched,
	 * and every field keeps its own row id, so a half-finished set of
	 * corrections survives a re-sort — which is the whole reason it is done in
	 * the browser rather than by reloading the screen.
	 *
	 * Hidden until the script below reveals it: without JavaScript the buttons
	 * would do nothing, and a dead control is worse than no control.
	 */
	private function render_sort_controls(): void {
		?>
		<p id="mvoc-review-sort" hidden>
			<strong><?php esc_html_e( 'Order', 'mvoc-streeto' ); ?></strong>
			<button type="button" class="button button-small button-primary"
				data-mvoc-sort="position" aria-pressed="true">
				<?php esc_html_e( 'Finishing position', 'mvoc-streeto' ); ?>
			</button>
			<button type="button" class="button button-small"
				data-mvoc-sort="name" aria-pressed="false">
				<?php esc_html_e( 'Surname (A–Z)', 'mvoc-streeto' ); ?>
			</button>
			<span class="description">
				<?php esc_html_e( 'Surname order is for checking the field against the start list — the Name column still reads first name first. It changes what you see and nothing else: positions, your edits and what is saved are all unaffected.', 'mvoc-streeto' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * The re-ordering itself.
	 *
	 * Inline for the same reason the one row style above is: it belongs to this
	 * table and no other screen, and it is short enough that a file would put it
	 * a long way from the markup it depends on.
	 *
	 * Rows are moved, never rebuilt, so every input keeps its value and its
	 * name. Equal names fall back to the position order the table was rendered
	 * in, so a runner with two rows keeps them in the order they are ranked.
	 */
	private function render_sort_script(): void {
		?>
		<script>
		( function () {
			var controls = document.getElementById( 'mvoc-review-sort' );
			var body     = document.getElementById( 'mvoc-review-rows' );

			if ( ! controls || ! body ) {
				return;
			}

			var buttons = Array.prototype.slice.call( controls.querySelectorAll( '[data-mvoc-sort]' ) );
			var rows    = Array.prototype.slice.call( body.rows );

			function order( row ) {
				return parseInt( row.dataset.sortPosition, 10 );
			}

			function sort( mode ) {
				rows.slice().sort( function ( a, b ) {
					if ( 'name' === mode ) {
						var compared = a.dataset.sortName.trim().localeCompare(
							b.dataset.sortName.trim(),
							undefined,
							{ sensitivity: 'base', numeric: true }
						);

						if ( compared ) {
							return compared;
						}
					}

					return order( a ) - order( b );
				} ).forEach( function ( row ) {
					body.appendChild( row );
				} );

				buttons.forEach( function ( button ) {
					var active = button.dataset.mvocSort === mode;

					button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
					button.classList.toggle( 'button-primary', active );
				} );
			}

			buttons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					sort( button.dataset.mvocSort );
				} );
			} );

			controls.hidden = false;
		}() );
		</script>
		<?php
	}

	/**
	 * Show the table as it will be published.
	 *
	 * @param array<int,array<string,mixed>>          $scored Scored rows.
	 * @param Scoring_Config                          $config Scoring rules.
	 * @param array<string,mixed>                     $event  Event row.
	 */
	private function render_preview( array $scored, Scoring_Config $config, array $event ): void {
		$organiser_ids = $this->events->organisers( (int) $event['id'] );
		$organisers    = array_values(
			array_filter(
				$this->competitors->all(),
				static fn( array $competitor ): bool => in_array( $competitor['id'], $organiser_ids, true )
			)
		);

		$model = ( new Event_Presenter( $config ) )->present( $scored, $organisers );

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $model['columns'] as $column ) {
			echo '<th>' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $model['rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['position_label'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['club'] ) . '</td>';
			echo '<td>' . esc_html( $row['course'] ) . '</td>';
			echo '<td>' . esc_html( null === $row['score'] ? '—' : (string) $row['score'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['penalty'] ) . '</td>';
			echo '<td>' . esc_html( null === $row['total'] ? '—' : (string) $row['total'] ) . '</td>';
			echo '<td>' . esc_html( null === $row['league_points'] ? '—' : (string) $row['league_points'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handle a submission.
	 *
	 * @param array<string,mixed> $event Event row.
	 * @return array<string,mixed>
	 */
	private function handle_post( array $event ): array {
		if ( ! isset( $_POST['mvoc_streeto_action'] ) && ! isset( $_POST['remove_row'] ) ) {
			return array();
		}

		check_admin_referer( self::NONCE );

		$action   = isset( $_POST['mvoc_streeto_action'] )
			? sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) )
			: '';
		$event_id = (int) $event['id'];

		// Its own field rather than an id encoded into the action: sanitize_key
		// strips the separator, so "remove:7" silently arrived as "remove7".
		if ( isset( $_POST['remove_row'] ) ) {
			return array( 'notice' => $this->remove_manual( (int) $_POST['remove_row'], $event_id ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( 'import' === $action || 'import_paste' === $action ) {
			// Not sanitised as text: that would mangle the JSON. Decoding validates it.
			$pasted = 'import_paste' === $action && isset( $_POST['pasted_json'] )
				? trim( wp_unslash( $_POST['pasted_json'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: null;

			// An empty box on the paste action is a mistake, not a request to
			// fetch. It used to fall through to `?: null` and quietly perform a
			// full HTTP fetch of every source instead — which reads as the
			// paste having worked, and on a host that blocks MapRun's port
			// returns errors for courses the co-ordinator never asked about.
			if ( 'import_paste' === $action && '' === (string) $pasted ) {
				return array( 'errors' => array( __( 'Paste the MapRun response into the box first.', 'mvoc-streeto' ) ) );
			}

			// Which course the paste is for. Only meaningful alongside $pasted;
			// the fetch path imports every source and needs no choice.
			$source_id = isset( $_POST['paste_source'] ) ? (int) $_POST['paste_source'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$result = $this->importer->import( $event_id, $pasted, $source_id );

			return array(
				'notice'    => $this->summarise( $result['summary'] ),
				'warnings'  => $result['warnings'],
				'errors'    => $result['errors'],
				'unmatched' => $result['unmatched'],
				'recovered' => $result['recovered'] ?? array(),
			);
		}

		if ( 'add_manual' === $action ) {
			return $this->add_manual_rows( $event_id );
		}

		if ( 'create_page' === $action ) {
			return $this->create_results_page( $event );
		}

		$saved = $this->save_corrections();
		$this->save_organisers( $event_id );

		if ( 'publish' === $action ) {
			$this->events->publish( $event_id );

			return array( 'notice' => __( 'Published. The results and league are now live.', 'mvoc-streeto' ) );
		}

		if ( 'unpublish' === $action ) {
			$this->events->unpublish( $event_id );

			return array( 'notice' => __( 'Returned to draft and removed from the public page.', 'mvoc-streeto' ) );
		}

		return array(
			'notice' => sprintf(
				/* translators: %d: number of corrections saved. */
				_n( 'Saved %d correction.', 'Saved %d corrections.', $saved, 'mvoc-streeto' ),
				$saved
			),
		);
	}

	/**
	 * Add hand-entered runners, from the single form or the pasted list.
	 *
	 * @param int $event_id Event id.
	 * @return array<string,mixed>
	 */
	private function add_manual_rows( int $event_id ): array {
		$parser = new Manual_Entry_Parser();
		$rows   = array();
		$errors = array();

		$single = isset( $_POST['manual'] ) && is_array( $_POST['manual'] )
			? wp_unslash( $_POST['manual'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$name = sanitize_text_field( (string) ( $single['name'] ?? '' ) );

		if ( '' !== $name ) {
			$score = trim( (string) ( $single['score'] ?? '' ) );

			$rows[] = Manual_Entry_Parser::row(
				$name,
				'' === $score ? null : (int) $score,
				(int) ( $single['penalty'] ?? 0 ),
				preg_replace( '/[^0-9]/', '', (string) ( $single['course'] ?? '60' ) )
			);
		}

		if ( isset( $_POST['manual_paste'] ) ) {
			// Not sanitised as text: that would collapse the line breaks the
			// parser splits on. Each field is sanitised after parsing instead.
			$pasted = trim( wp_unslash( $_POST['manual_paste'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( '' !== $pasted ) {
				$parsed = $parser->parse( $pasted );
				$rows   = array_merge( $rows, $parsed['rows'] );
				$errors = $parsed['errors'];
			}
		}

		if ( ! $rows ) {
			return array( 'errors' => $errors );
		}

		// An event MapRun cannot score has no source, so fall back to zero -
		// a manual row belongs to the event, not to a MapRun feed.
		$sources   = $this->events->sources( $event_id );
		$source_id = (int) ( $sources[0]['id'] ?? 0 );
		$aliases   = $this->competitors->aliases();
		$added     = 0;

		foreach ( $rows as $row ) {
			$row['first_name']   = sanitize_text_field( (string) $row['first_name'] );
			$row['surname']      = sanitize_text_field( (string) $row['surname'] );
			$row['display_name'] = sanitize_text_field( (string) $row['display_name'] );

			// Attach a competitor straight away where the name is already
			// known, so a hand-added runner scores without a second trip
			// through the confirm-names screen.
			$key                  = \MVOC\StreetO\Domain\Name_Matcher::alias_key( $row['first_name'], $row['surname'] );
			$row['competitor_id'] = $aliases[ $key ] ?? 0;

			$this->results->add_manual( $event_id, $source_id, $row );
			++$added;
		}

		return array(
			'errors' => $errors,
			'notice' => sprintf(
				/* translators: %d: number of runners added. */
				_n( 'Added %d runner by hand.', 'Added %d runners by hand.', $added, 'mvoc-streeto' ),
				$added
			),
		);
	}

	/**
	 * Remove a hand-added runner.
	 *
	 * Only manual rows can be removed. A MapRun row is excluded rather than
	 * deleted, so its raw record and audit trail survive.
	 *
	 * @param int $result_id Result id.
	 * @param int $event_id  Event being reviewed.
	 */
	private function remove_manual( int $result_id, int $event_id ): string {
		$this->results->delete_manual( $result_id, $event_id );

		return __( 'Removed.', 'mvoc-streeto' );
	}

	/**
	 * Create a draft results post pre-filled with this event's shortcodes.
	 *
	 * Called "page_id" in the schema from when this created a WP Page rather
	 * than a Post — renaming it would need its own migration for what is
	 * otherwise a cosmetic mismatch, so it stays.
	 *
	 * @param array<string,mixed> $event Event row.
	 * @return array<string,mixed>
	 */
	private function create_results_page( array $event ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array( 'errors' => array( __( 'You do not have permission to create posts.', 'mvoc-streeto' ) ) );
		}

		if ( ! empty( $event['page_id'] ) && get_post( (int) $event['page_id'] ) ) {
			return array( 'errors' => array( __( 'A post already exists for this event.', 'mvoc-streeto' ) ) );
		}

		$series = $this->series_for( $event );
		$slug   = (string) ( $series['slug'] ?? '' );
		$number = (int) $event['event_number'];

		$title = $this->results_post_title( $event, $number );
		$notes = array();

		$lines = array();

		if ( ! empty( $event['event_date'] ) ) {
			$lines[] = '<p><strong>' . esc_html__( 'Event date:', 'mvoc-streeto' ) . '</strong> '
				. esc_html( mysql2date( 'l, j F Y', (string) $event['event_date'] ) ) . '</p>';
		}

		$lines[] = '<p>' . esc_html__( '[Add the event report here.]', 'mvoc-streeto' ) . '</p>';
		$lines[] = sprintf( '[mvoc_streeto_event series="%s" number="%d"]', $slug, $number );
		$lines[] = sprintf( '[mvoc_streeto_league series="%s" through_event="%d"]', $slug, $number );

		$categories = array();
		foreach ( array( 'News', 'Results' ) as $name ) {
			$term = get_term_by( 'name', $name, 'category' );
			if ( $term instanceof \WP_Term ) {
				$categories[] = $term->term_id;
			} else {
				/* translators: %s: category name, e.g. "News". */
				$notes[] = sprintf( __( 'no "%s" category exists, so the post was created without it', 'mvoc-streeto' ), $name );
			}
		}

		// Hard-coded to this site's own theme and its "Results" ACF field
		// group, not something a plugin distributed elsewhere could rely on
		// — but this plugin only ever runs on mvoc.org. The full-width
		// template is inert if the active theme lacks it; the ACF keys are
		// inert if ACF isn't active. Either way nothing breaks, it just does
		// less. The "Results" category assigned above is what makes the ACF
		// field group's own location rule (post_category == results) show
		// the box at all.
		$meta = array( '_wp_page_template' => 'page-templates/full-width.php' );

		if ( ! empty( $event['event_date'] ) ) {
			$meta['result_date']  = str_replace( '-', '', (string) $event['event_date'] );
			$meta['_result_date'] = 'field_6620d0ee5e8e9';
		}

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_title'    => $title,
				'post_content'  => implode( "\n\n", $lines ),
				'post_author'   => get_current_user_id(),
				'post_category' => $categories,
				'tags_input'    => array( 'StreetO' ),
				'meta_input'    => $meta,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'errors' => array( $post_id->get_error_message() ) );
		}

		$this->events->set_page_id( (int) $event['id'], (int) $post_id );

		$notice = __( 'Draft post created.', 'mvoc-streeto' );
		if ( $notes ) {
			$notice .= ' ' . ucfirst( implode( '; ', $notes ) ) . '.';
		}

		return array( 'notice' => $notice );
	}

	/**
	 * "StreetO Results - Venue, Month Year", falling back to the on-screen
	 * heading format when there is no date to build a month/year from.
	 *
	 * @param array<string,mixed> $event  Event row.
	 * @param int                 $number Event number.
	 */
	private function results_post_title( array $event, int $number ): string {
		$venue = '' !== trim( (string) ( $event['venue'] ?? '' ) ) ? (string) $event['venue'] : (string) $event['label'];

		if ( empty( $event['event_date'] ) ) {
			/* translators: 1: event number, 2: event title. */
			return sprintf( __( 'Event %1$d — %2$s', 'mvoc-streeto' ), $number, $event['label'] );
		}

		/* translators: 1: venue, 2: month and year, e.g. "October 2026". */
		return sprintf(
			__( 'StreetO Results - %1$s, %2$s', 'mvoc-streeto' ),
			$venue,
			mysql2date( 'F Y', (string) $event['event_date'] )
		);
	}

	/**
	 * Apply the submitted row edits, recording only what actually changed.
	 *
	 * Writing an override for every field on every row would bury the real
	 * corrections in noise, and the audit trail exists to be readable.
	 */
	private function save_corrections(): int {
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$keep   = $this->kept_duplicates();
		$saved  = 0;

		if ( ! isset( $_POST['rows'] ) || ! is_array( $_POST['rows'] ) ) {
			return 0;
		}

		$current = array_column(
			Results_Repo::effective_rows( $this->results->for_event( $this->event_id() ), $this->config() ),
			null,
			'result_id'
		);

		foreach ( wp_unslash( $_POST['rows'] ) as $raw_id => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$id = (int) $raw_id;

			if ( ! is_array( $fields ) || ! isset( $current[ $id ] ) ) {
				continue;
			}

			$was = $current[ $id ];

			$excluded = Duplicate_Detector::is_excluded( $id, ! empty( $fields['excluded'] ), $keep );

			$updates  = array(
				'score'      => '' === ( $fields['score'] ?? '' ) ? null : (int) $fields['score'],
				'penalty'    => (int) ( $fields['penalty'] ?? 0 ),
				'course'     => preg_replace( '/[^0-9]/', '', (string) ( $fields['course'] ?? '' ) ),
				'competitor' => ( (int) ( $fields['competitor'] ?? 0 ) ) ?: null,
				'excluded'   => $excluded ? 1 : 0,
			);

			$before = array(
				'score'      => $was['score'],
				'penalty'    => $was['penalty'],
				'course'     => $was['course_label'],
				'competitor' => $was['competitor_id'],
				'excluded'   => $was['is_excluded'] ? 1 : 0,
			);

			// Only where the screen actually offered the tick. An unticked box
			// and a row with no box at all submit exactly the same thing —
			// nothing — so without the marker every row whose warning had since
			// cleared would be recorded as un-checked on the next save, each
			// one a correction nobody made.
			if ( ! empty( $fields['checked_offered'] ) ) {
				$updates['checked'] = ! empty( $fields['checked'] ) ? 1 : 0;
				$before['checked']  = $was['is_checked'] ? 1 : 0;
			}

			foreach ( $updates as $field => $value ) {
				if ( $before[ $field ] !== $value ) {
					$this->results->override( $id, $field, $value, $reason );
					++$saved;
				}
			}
		}

		return $saved;
	}

	/**
	 * Duplicate choices, as the ids to keep and the ids to exclude.
	 *
	 * @return array{kept:int[],excluded:int[]}
	 */
	private function kept_duplicates(): array {
		$chosen = array();

		if ( isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ) {
			foreach ( wp_unslash( $_POST['keep'] ) as $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$chosen[] = (int) $choice;
			}
		}

		if ( ! $chosen ) {
			return array(
				'kept'     => array(),
				'excluded' => array(),
			);
		}

		$effective = Results_Repo::effective_rows( $this->results->for_event( $this->event_id() ), $this->config() );

		return Duplicate_Detector::resolve(
			( new Duplicate_Detector() )->find( $effective ),
			$chosen
		);
	}

	/**
	 * Store the event's organisers.
	 *
	 * @param int $event_id Event id.
	 */
	private function save_organisers( int $event_id ): void {
		// organiser_name is a plain text input, always submitted with the rest
		// of the form (blank included) - unlike a checkbox, its presence is a
		// reliable sign the organiser section was actually on the page.
		if ( ! isset( $_POST['organiser_name'] ) ) {
			return;
		}

		$keep = isset( $_POST['keep_organiser'] ) && is_array( $_POST['keep_organiser'] )
			? wp_unslash( $_POST['keep_organiser'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		$kept = array();
		foreach ( $this->events->organisers( $event_id ) as $organiser_id ) {
			if ( ! empty( $keep[ $organiser_id ] ) ) {
				$kept[] = $organiser_id;
			}
		}

		$typed = sanitize_text_field( wp_unslash( $_POST['organiser_name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' !== $typed ) {
			$kept[] = $this->competitors->resolve_or_create_by_name( $typed );
		}

		$this->events->save_organisers( $event_id, array_unique( $kept ) );
	}

	/**
	 * The event currently being reviewed.
	 */
	private function event_id(): int {
		return isset( $_GET['event'] ) ? (int) $_GET['event'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Turn an import summary into a sentence.
	 *
	 * @param array<string,int> $summary Action counts.
	 */
	private function summarise( array $summary ): string {
		$parts = array();

		foreach ( $summary as $action => $count ) {
			if ( $count > 0 ) {
				$parts[] = sprintf( '%d %s', $count, $action );
			}
		}

		return $parts
			? sprintf(
				/* translators: %s: a list like "12 insert, 3 update". */
				__( 'Imported: %s.', 'mvoc-streeto' ),
				implode( ', ', $parts )
			)
			: __( 'Imported — nothing changed.', 'mvoc-streeto' );
	}
}
