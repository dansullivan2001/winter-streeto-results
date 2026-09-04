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
use MVOC\StreetO\Domain\Scoring_Engine;
use MVOC\StreetO\Importer;
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
			echo '<div class="wrap"><h1>' . esc_html__( 'Event results', 'mvoc-streeto' ) . '</h1><p>'
				. esc_html__( 'Choose an event from the Series and events screen.', 'mvoc-streeto' )
				. '</p></div>';

			return;
		}

		$feedback = $this->handle_post( $event );
		$event    = $this->events->find_event_by_id( $event_id ) ?? $event;

		$rows        = $this->results->for_event( $event_id );
		$effective   = array_map( array( Results_Repo::class, 'effective' ), $rows );
		$config      = $this->events->scoring_config( $this->series_for( $event ) );
		$scored      = ( new Scoring_Engine( $config ) )->score_event( $effective );
		$competitors = $this->competitors->all();
		$duplicates  = ( new Duplicate_Detector() )->find( $effective );

		?>
		<div class="wrap">
			<h1>
				<?php
				printf(
					/* translators: 1: event number, 2: event title. */
					esc_html__( 'Event %1$d — %2$s', 'mvoc-streeto' ),
					(int) $event['event_number'],
					esc_html( $event['title'] )
				);
				?>
			</h1>

			<?php $this->render_feedback( $feedback ); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

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
						<ol>
							<?php foreach ( $sources as $source ) : ?>
								<?php $url = \MVOC\StreetO\MapRun\Client::url_for( (string) $source['maprun_event_name'] ); ?>
								<li style="margin-bottom:0.5em;">
									<?php
									printf(
										/* translators: %s: course label such as 60. */
										esc_html__( '%s minute course —', 'mvoc-streeto' ),
										esc_html( (string) $source['course_label'] )
									);
									?>
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

				<?php if ( $duplicates ) : ?>
					<h2><?php esc_html_e( '2. Duplicates', 'mvoc-streeto' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'The same run recorded more than once — usually scored against two course revisions. Keep one; the other is excluded.', 'mvoc-streeto' ); ?>
					</p>
					<?php $this->render_duplicates( $duplicates ); ?>
				<?php endif; ?>

				<h2><?php esc_html_e( '3. Results', 'mvoc-streeto' ); ?></h2>
				<?php $this->render_rows( $scored, $rows, $competitors ); ?>

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
							<input type="number" name="manual[penalty]" style="width:7em" step="10" min="0"
								placeholder="<?php esc_attr_e( 'Penalty', 'mvoc-streeto' ); ?>" />
							<select name="manual[course]">
								<option value="60">60</option>
								<option value="40">40</option>
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
					<select name="organiser_competitor_id">
						<option value="0"><?php esc_html_e( '— none —', 'mvoc-streeto' ); ?></option>
						<?php foreach ( $competitors as $competitor ) : ?>
							<option value="<?php echo esc_attr( (string) $competitor['id'] ); ?>"
								<?php selected( $event['organiser_competitor_id'], $competitor['id'] ); ?>>
								<?php echo esc_html( $competitor['display_name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description">
						<?php esc_html_e( 'Listed on the results but not ranked. They score their best result again in the league.', 'mvoc-streeto' ); ?>
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
	 * Render each duplicate cluster as one choice.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $clusters Duplicate clusters.
	 */
	private function render_duplicates( array $clusters ): void {
		$detector = new Duplicate_Detector();

		foreach ( $clusters as $cluster ) {
			$described = $detector->describe( $cluster );
			$field     = 'keep[' . rawurlencode( $described['name'] ) . ']';

			echo '<div class="card" style="max-width:none;"><h3 style="margin-top:0;">'
				. esc_html( $described['name'] ) . ' <span class="description">'
				. esc_html( $described['time_display'] ) . '</span></h3>';

			foreach ( $cluster as $row ) {
				$label = null === $row['course_revision']
					? __( 'no revision', 'mvoc-streeto' )
					: sprintf( 'Rev%d', (int) $row['course_revision'] );

				printf(
					'<p><label><input type="radio" name="%s" value="%s" /> %s — %s</label></p>',
					esc_attr( $field ),
					esc_attr( (string) $row['result_id'] ),
					esc_html( sprintf( '%s pts', (string) $row['score'] ) ),
					esc_html( $label )
				);
			}

			echo '<p class="description">'
				. esc_html__( 'Nothing is chosen for you: which scoring is right is your call.', 'mvoc-streeto' )
				. '</p></div>';
		}
	}

	/**
	 * The editable results table.
	 *
	 * @param array<int,array<string,mixed>> $scored      Scored rows.
	 * @param array<int,array<string,mixed>> $stored      Stored rows, for flags.
	 * @param array<int,array<string,mixed>> $competitors Known competitors.
	 */
	private function render_rows( array $scored, array $stored, array $competitors ): void {
		$flags = array_column( $stored, null, 'id' );

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Pos', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Competitor', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Course', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Score', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Penalty', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Total', 'mvoc-streeto' ); ?></th>
					<th><?php esc_html_e( 'Exclude', 'mvoc-streeto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scored as $row ) : ?>
					<?php
					$id    = (int) $row['result_id'];
					$flag  = $flags[ $id ] ?? array();
					$notes = array();

					if ( '--' === ( $row['classifier'] ?? '' ) ) {
						$notes[] = __( 'failed upload', 'mvoc-streeto' );
					}
					if ( ! empty( $row['is_withdrawn'] ) ) {
						$notes[] = __( 'no longer in MapRun', 'mvoc-streeto' );
					}
					if ( ! empty( $row['is_manual'] ) ) {
						$notes[] = __( 'added by hand', 'mvoc-streeto' );
					}
					if ( empty( $row['competitor_id'] ) ) {
						$notes[] = __( 'name not confirmed', 'mvoc-streeto' );
					}
					?>
					<tr>
						<td><?php echo esc_html( $row['position_label'] ?: '—' ); ?></td>
						<td>
							<?php echo esc_html( $row['display_name'] ); ?>
							<?php if ( $notes ) : ?>
								<br /><span class="description"><?php echo esc_html( implode( ', ', $notes ) ); ?></span>
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
								<?php foreach ( array( '60', '40' ) as $course ) : ?>
									<option value="<?php echo esc_attr( $course ); ?>"
										<?php selected( $row['course_label'], $course ); ?>>
										<?php echo esc_html( $course ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="number" style="width:6em" step="10"
								name="rows[<?php echo esc_attr( (string) $id ); ?>][score]"
								value="<?php echo esc_attr( null === $row['score'] ? '' : (string) $row['score'] ); ?>" />
						</td>
						<td>
							<input type="number" style="width:6em" step="10" min="0"
								name="rows[<?php echo esc_attr( (string) $id ); ?>][penalty]"
								value="<?php echo esc_attr( (string) ( $row['penalty'] ?? 0 ) ); ?>" />
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
	 * Show the table as it will be published.
	 *
	 * @param array<int,array<string,mixed>>          $scored Scored rows.
	 * @param \MVOC\StreetO\Domain\Scoring_Config     $config Scoring rules.
	 * @param array<string,mixed>                     $event  Event row.
	 */
	private function render_preview( array $scored, $config, array $event ): void {
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
			return array( 'notice' => $this->remove_manual( (int) $_POST['remove_row'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( 'import' === $action || 'import_paste' === $action ) {
			// Not sanitised as text: that would mangle the JSON. Decoding validates it.
			$pasted = 'import_paste' === $action && isset( $_POST['pasted_json'] )
				? trim( wp_unslash( $_POST['pasted_json'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: null;

			$result = $this->importer->import( $event_id, $pasted ?: null );

			return array(
				'notice'    => $this->summarise( $result['summary'] ),
				'warnings'  => $result['warnings'],
				'errors'    => $result['errors'],
				'unmatched' => $result['unmatched'],
			);
		}

		if ( 'add_manual' === $action ) {
			return $this->add_manual_rows( $event_id );
		}

		$saved = $this->save_corrections();
		$this->save_organiser( $event_id );

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
	 */
	private function remove_manual( int $result_id ): string {
		$this->results->delete_manual( $result_id );

		return __( 'Removed.', 'mvoc-streeto' );
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
			array_map( array( Results_Repo::class, 'effective' ), $this->results->for_event( $this->event_id() ) ),
			null,
			'result_id'
		);

		foreach ( wp_unslash( $_POST['rows'] ) as $raw_id => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$id = (int) $raw_id;

			if ( ! is_array( $fields ) || ! isset( $current[ $id ] ) ) {
				continue;
			}

			$was = $current[ $id ];

			$excluded = ! empty( $fields['excluded'] ) || in_array( $id, $keep['excluded'], true );
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
		$kept     = array();
		$excluded = array();

		if ( isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ) {
			foreach ( wp_unslash( $_POST['keep'] ) as $choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$kept[] = (int) $choice;
			}
		}

		if ( $kept ) {
			$effective = array_map( array( Results_Repo::class, 'effective' ), $this->results->for_event( $this->event_id() ) );

			foreach ( ( new Duplicate_Detector() )->find( $effective ) as $cluster ) {
				$ids = array_map( 'intval', array_column( $cluster, 'result_id' ) );

				// Only act on a cluster the co-ordinator actually answered.
				if ( ! array_intersect( $ids, $kept ) ) {
					continue;
				}

				$excluded = array_merge( $excluded, array_diff( $ids, $kept ) );
			}
		}

		return array(
			'kept'     => $kept,
			'excluded' => $excluded,
		);
	}

	/**
	 * Store the event's organiser.
	 *
	 * @param int $event_id Event id.
	 */
	private function save_organiser( int $event_id ): void {
		if ( ! isset( $_POST['organiser_competitor_id'] ) ) {
			return;
		}

		$event = $this->events->find_event_by_id( $event_id );
		if ( ! $event ) {
			return;
		}

		$event['organiser_competitor_id'] = (int) $_POST['organiser_competitor_id']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$this->events->save_event( (int) $event['series_id'], $event );
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
