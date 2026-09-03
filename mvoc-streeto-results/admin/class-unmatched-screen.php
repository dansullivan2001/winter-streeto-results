<?php
/**
 * Unmatched-names queue: confirm who each new MapRun name belongs to.
 *
 * This is the screen that replaces the spreadsheet's "League Check" column,
 * where the co-ordinator counted name occurrences by eye to spot mismatches.
 * Every confirmation here is stored as an alias, so a spelling is decided once
 * and then resolves silently at every later event.
 *
 * Nothing is ever pre-selected, however strong the suggestion. A wrong merge
 * hands one runner another's league points, and that is a worse failure than
 * asking a question that turns out to be easy.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Competitor_Registry;
use MVOC\StreetO\Domain\Name_Matcher;
use MVOC\StreetO\MapRun\Client;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches an event and queues its unrecognised names for confirmation.
 */
class Unmatched_Screen {

	private const NONCE = 'mvoc_streeto_unmatched';

	private Competitors_Repo $repo;

	private Competitor_Registry $registry;

	/**
	 * @param Competitors_Repo|null    $repo     Competitor persistence.
	 * @param Competitor_Registry|null $registry Resolution logic.
	 */
	public function __construct( ?Competitors_Repo $repo = null, ?Competitor_Registry $registry = null ) {
		$this->repo     = $repo ?? new Competitors_Repo();
		$this->registry = $registry ?? new Competitor_Registry();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$event_name = '';
		$pasted     = '';
		$error      = '';
		$warning    = '';
		$notice     = '';
		$unmatched  = array();
		$resolved   = 0;

		if ( isset( $_POST['mvoc_streeto_action'] ) ) {
			check_admin_referer( self::NONCE );

			$action     = sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) );
			$event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : '';
			// Not sanitised as text: that would mangle the JSON. Validated by decoding.
			$pasted = isset( $_POST['pasted_json'] ) ? trim( wp_unslash( $_POST['pasted_json'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( 'confirm' === $action ) {
				$notice = $this->confirm_choices();
			}

			try {
				$rows = $this->load_rows( $action, $event_name, $pasted, $warning );

				if ( null !== $rows ) {
					$result    = $this->registry->resolve( $rows, $this->repo->all(), $this->repo->aliases() );
					$unmatched = $result['unmatched'];
					$resolved  = count( $result['rows'] ) - count( $unmatched );
				}
			} catch ( \RuntimeException $e ) {
				$error = $e->getMessage();
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Confirm names', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Load an event and tell the plugin who each unrecognised name belongs to. Each answer is remembered, so a spelling is only ever asked about once.', 'mvoc-streeto' ); ?>
			</p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $warning ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'MapRun warning:', 'mvoc-streeto' ); ?></strong> <?php echo esc_html( $warning ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mvoc-event-name"><?php esc_html_e( 'MapRun event name', 'mvoc-streeto' ); ?></label></th>
						<td>
							<input type="text" id="mvoc-event-name" name="event_name" class="regular-text"
								value="<?php echo esc_attr( $event_name ); ?>" />
							<button type="submit" name="mvoc_streeto_action" value="fetch" class="button button-primary">
								<?php esc_html_e( 'Load', 'mvoc-streeto' ); ?>
							</button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvoc-pasted-json"><?php esc_html_e( 'Or paste JSON', 'mvoc-streeto' ); ?></label></th>
						<td>
							<textarea id="mvoc-pasted-json" name="pasted_json" rows="4" class="large-text code"><?php echo esc_textarea( $pasted ); ?></textarea>
							<button type="submit" name="mvoc_streeto_action" value="paste" class="button">
								<?php esc_html_e( 'Load pasted JSON', 'mvoc-streeto' ); ?>
							</button>
						</td>
					</tr>
				</table>

				<?php if ( $unmatched ) : ?>
					<h2>
						<?php
						printf(
							/* translators: 1: names needing a decision, 2: rows already recognised. */
							esc_html__( '%1$d name(s) to confirm — %2$d row(s) already recognised', 'mvoc-streeto' ),
							count( $unmatched ),
							(int) $resolved
						);
						?>
					</h2>

					<?php foreach ( $unmatched as $entry ) : ?>
						<?php $this->render_entry( $entry ); ?>
					<?php endforeach; ?>

					<p>
						<button type="submit" name="mvoc_streeto_action" value="confirm" class="button button-primary">
							<?php esc_html_e( 'Confirm selected', 'mvoc-streeto' ); ?>
						</button>
						<span class="description">
							<?php esc_html_e( 'Anything left on "Decide later" is skipped and will be asked again.', 'mvoc-streeto' ); ?>
						</span>
					</p>
				<?php elseif ( $resolved > 0 ) : ?>
					<div class="notice notice-success inline">
						<p>
							<?php
							printf(
								/* translators: %d: number of rows. */
								esc_html__( 'Every name is recognised — all %d rows resolved without asking.', 'mvoc-streeto' ),
								(int) $resolved
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one queued name with its options.
	 *
	 * @param array<string,mixed> $entry Queue entry.
	 */
	private function render_entry( array $entry ): void {
		$key    = (string) $entry['alias_key'];
		$field  = 'choice[' . $key . ']';
		$detail = array_filter(
			array(
				$entry['club'],
				$entry['year_of_birth'] ? sprintf( 'b. %d', (int) $entry['year_of_birth'] ) : '',
				'F' === $entry['gender'] ? __( 'female', 'mvoc-streeto' ) : '',
			)
		);

		?>
		<div class="card" style="max-width:none;margin-bottom:1em;">
			<h3 style="margin-top:0;">
				<?php echo esc_html( $entry['display_name'] ); ?>
				<?php if ( $detail ) : ?>
					<span class="description">— <?php echo esc_html( implode( ', ', $detail ) ); ?></span>
				<?php endif; ?>
			</h3>

			<?php
			// Carried through the form so that confirming does not depend on
			// MapRun being reachable a second time.
			$proposed = $entry['proposed'];
			$base     = 'proposed[' . $key . ']';
			foreach ( array( 'first_name', 'surname', 'display_name', 'club' ) as $plain ) :
				?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[' . $plain . ']' ); ?>"
					value="<?php echo esc_attr( (string) $proposed[ $plain ] ); ?>" />
			<?php endforeach; ?>
			<input type="hidden" name="<?php echo esc_attr( $base . '[year_of_birth]' ); ?>"
				value="<?php echo esc_attr( (string) ( $proposed['year_of_birth'] ?? 0 ) ); ?>" />
			<?php if ( $proposed['is_female'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[is_female]' ); ?>" value="1" />
			<?php endif; ?>
			<?php if ( $proposed['is_over55'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $base . '[is_over55]' ); ?>" value="1" />
			<?php endif; ?>

			<p>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="" checked />
					<?php esc_html_e( 'Decide later', 'mvoc-streeto' ); ?>
				</label>
			</p>

			<p>
				<label>
					<input type="radio" name="<?php echo esc_attr( $field ); ?>" value="new" />
					<?php esc_html_e( 'New competitor', 'mvoc-streeto' ); ?>
					<span class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: Ladies yes/no, 2: Over-55 yes/no. */
								__( '(Ladies: %1$s, Over 55: %2$s — from MapRun, editable afterwards)', 'mvoc-streeto' ),
								$entry['proposed']['is_female'] ? __( 'yes', 'mvoc-streeto' ) : __( 'no', 'mvoc-streeto' ),
								$entry['proposed']['is_over55'] ? __( 'yes', 'mvoc-streeto' ) : __( 'no', 'mvoc-streeto' )
							)
						);
						?>
					</span>
				</label>
			</p>

			<?php if ( $entry['suggestions'] ) : ?>
				<p><strong><?php esc_html_e( 'Or the same person as:', 'mvoc-streeto' ); ?></strong></p>
				<?php foreach ( $entry['suggestions'] as $suggestion ) : ?>
					<p style="margin-left:1.5em;">
						<label>
							<input type="radio" name="<?php echo esc_attr( $field ); ?>"
								value="<?php echo esc_attr( (string) $suggestion['competitor']['id'] ); ?>" />
							<?php echo esc_html( (string) ( $suggestion['competitor']['display_name'] ?? '' ) ); ?>
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: confidence score, 2: reasons. */
										__( '— %1$d%% match: %2$s', 'mvoc-streeto' ),
										(int) $suggestion['score'],
										$suggestion['reasons']
											? implode( ', ', $suggestion['reasons'] )
											: __( 'similar name', 'mvoc-streeto' )
									)
								);
								?>
							</span>
						</label>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Load rows for the requested action, or null when none was asked for.
	 *
	 * @param string $action     Submitted action.
	 * @param string $event_name MapRun event name.
	 * @param string $pasted     Pasted JSON.
	 * @param string $warning    Warning message, set by reference.
	 * @return array<int,array<string,mixed>>|null
	 * @throws \RuntimeException On a bad fetch or payload.
	 */
	private function load_rows( string $action, string $event_name, string $pasted, string &$warning ): ?array {
		$client = new Client();

		if ( 'fetch' === $action && '' !== $event_name ) {
			$result = $client->fetch( $event_name );
		} elseif ( 'paste' === $action && '' !== $pasted ) {
			$result = $client->ingest( $pasted );
		} else {
			return null;
		}

		$warning = (string) ( $result['warning'] ?? '' );

		return ( new Parser() )->parse( $result['rows'], '60' );
	}

	/**
	 * Apply the co-ordinator's choices, returning a notice.
	 *
	 * Choices are keyed by alias key, and each is either a competitor id to
	 * link the spelling to, or "new" to create a competitor from the MapRun
	 * details. An empty value means "decide later" and is skipped.
	 */
	private function confirm_choices(): string {
		if ( ! isset( $_POST['choice'] ) || ! is_array( $_POST['choice'] ) ) {
			return '';
		}

		$proposals = $this->proposals_from_post();
		$linked    = 0;
		$created   = 0;

		foreach ( wp_unslash( $_POST['choice'] ) as $raw_key => $raw_choice ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$alias_key = Name_Matcher::normalise( (string) $raw_key );
			$choice    = sanitize_text_field( (string) $raw_choice );

			if ( '' === $alias_key || '' === $choice ) {
				continue;
			}

			if ( 'new' === $choice ) {
				if ( isset( $proposals[ $alias_key ] ) ) {
					$this->repo->create_with_alias( $proposals[ $alias_key ], $alias_key );
					++$created;
				}
				continue;
			}

			$competitor_id = (int) $choice;
			if ( $competitor_id > 0 ) {
				$this->repo->link_alias( $alias_key, $competitor_id );
				++$linked;
			}
		}

		if ( 0 === $linked && 0 === $created ) {
			return '';
		}

		return sprintf(
			/* translators: 1: competitors created, 2: names linked to existing competitors. */
			__( 'Created %1$d competitor(s) and linked %2$d name(s).', 'mvoc-streeto' ),
			$created,
			$linked
		);
	}

	/**
	 * Competitor details for names chosen as "new", keyed by alias key.
	 *
	 * Carried through the form rather than re-fetched, so confirming does not
	 * depend on MapRun being reachable a second time.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function proposals_from_post(): array {
		if ( ! isset( $_POST['proposed'] ) || ! is_array( $_POST['proposed'] ) ) {
			return array();
		}

		$proposals = array();

		foreach ( wp_unslash( $_POST['proposed'] ) as $raw_key => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! is_array( $fields ) ) {
				continue;
			}

			$alias_key = Name_Matcher::normalise( (string) $raw_key );

			$proposals[ $alias_key ] = array(
				'first_name'    => sanitize_text_field( (string) ( $fields['first_name'] ?? '' ) ),
				'surname'       => sanitize_text_field( (string) ( $fields['surname'] ?? '' ) ),
				'display_name'  => sanitize_text_field( (string) ( $fields['display_name'] ?? '' ) ),
				'club'          => sanitize_text_field( (string) ( $fields['club'] ?? '' ) ),
				'year_of_birth' => (int) ( $fields['year_of_birth'] ?? 0 ),
				'is_female'     => ! empty( $fields['is_female'] ),
				'is_over55'     => ! empty( $fields['is_over55'] ),
			);
		}

		return $proposals;
	}
}
