<?php
/**
 * Custom scoring: the library of definitions, a checker, and which event uses which.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Scoring_Input;
use MVOC\StreetO\Domain\Scoring_Prompt;
use MVOC\StreetO\Domain\Scoring_Rule_Set;
use MVOC\StreetO\Domain\Scoring_Scheme_Registry;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Events_Repo;
use MVOC\StreetO\Repo\Results_Repo;
use MVOC\StreetO\Repo\Rule_Sets_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and handles the Scoring screen.
 */
class Scoring_Screen {

	private const NONCE = 'mvoc_streeto_scoring';

	private Events_Repo $events;

	private Rule_Sets_Repo $rule_sets;

	private Results_Repo $results;

	public function __construct() {
		$this->events    = new Events_Repo();
		$this->rule_sets = new Rule_Sets_Repo();
		$this->results   = new Results_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$feedback = $this->handle_post();
		$edit_id  = isset( $_REQUEST['rule_set'] ) ? (int) $_REQUEST['rule_set'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing  = $edit_id ? $this->rule_sets->find( $edit_id ) : null;
		$text     = $feedback['text'] ?? ( $editing ? $this->pretty( $editing['definition'] ) : '' );
		$series   = $this->events->active_series();
		$events   = $series ? $this->events->events( (int) $series['id'] ) : array();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Custom scoring', 'mvoc-streeto' ); ?></h1>
			<p><?php esc_html_e( 'An event is normally scored from MapRun\'s own totals. Attach a scoring definition to an event to have it rescored from each runner\'s punches instead — different control values, controls in a set order, or a bonus for completing groups. MapRun\'s own figure stays visible beside it on the review screen.', 'mvoc-streeto' ); ?></p>

			<?php foreach ( $feedback['notices'] ?? array() as $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endforeach; ?>
			<?php foreach ( $feedback['errors'] ?? array() as $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endforeach; ?>

			<?php $this->render_library(); ?>
			<?php $this->render_editor( $text, $editing, $events, (int) ( $feedback['preview_event'] ?? 0 ) ); ?>
			<?php $this->render_preview( $feedback['preview'] ?? null ); ?>
			<?php $this->render_assignments( $events ); ?>
			<?php $this->render_prompt(); ?>
		</div>
		<?php
	}

	/**
	 * Handle whichever form was submitted.
	 *
	 * @return array<string,mixed>
	 */
	private function handle_post(): array {
		$feedback = array(
			'notices' => array(),
			'errors'  => array(),
		);

		if ( ! isset( $_POST['mvoc_action'] ) ) {
			return $feedback;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['mvoc_action'] ) );

		if ( 'delete' === $action ) {
			$id = isset( $_POST['rule_set'] ) ? (int) $_POST['rule_set'] : 0;

			if ( $this->rule_sets->delete( $id ) ) {
				$feedback['notices'][] = __( 'Scoring definition deleted.', 'mvoc-streeto' );
			} else {
				$feedback['errors'][] = __( 'That definition is still attached to an event. Detach it first.', 'mvoc-streeto' );
			}

			return $feedback;
		}

		if ( 'assign' === $action ) {
			$choices = isset( $_POST['event_rule_set'] ) && is_array( $_POST['event_rule_set'] ) ? wp_unslash( $_POST['event_rule_set'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $choices as $event_id => $rule_set_id ) {
				$rule_set_id = (int) $rule_set_id;
				$this->events->set_rule_set( (int) $event_id, $rule_set_id > 0 ? $rule_set_id : null );
			}

			$feedback['notices'][] = __( 'Event scoring updated.', 'mvoc-streeto' );

			return $feedback;
		}

		// check and save both start from the pasted text.
		$text   = isset( $_POST['definition'] ) ? (string) wp_unslash( $_POST['definition'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$parsed = Scoring_Input::parse( $text, $name );

		$feedback['text'] = $text;

		if ( null === $parsed['rules'] ) {
			$feedback['errors'] = $parsed['errors'];

			return $feedback;
		}

		$preview_event = isset( $_POST['preview_event'] ) ? (int) $_POST['preview_event'] : 0;

		if ( 'save' === $action ) {
			$id = $this->rule_sets->save( $parsed['rules'], isset( $_POST['rule_set'] ) ? (int) $_POST['rule_set'] : 0 );

			$feedback['notices'][] = sprintf(
				/* translators: %s: definition name. */
				__( 'Saved "%s". Events using it have been rescored.', 'mvoc-streeto' ),
				$parsed['rules']->name
			);
			$_REQUEST['rule_set'] = $id;
		} else {
			$feedback['notices'][] = __( 'The definition is valid.', 'mvoc-streeto' );
		}

		if ( $preview_event ) {
			$feedback['preview_event'] = $preview_event;
			$feedback['preview']       = $this->preview( $parsed['rules'], $preview_event );
		}

		return $feedback;
	}

	/**
	 * Every imported runner under the definition, against MapRun's figure.
	 *
	 * @param Scoring_Rule_Set $rules    Definition being checked.
	 * @param int              $event_id Event whose results to use.
	 * @return array<int,array<string,mixed>>
	 */
	private function preview( Scoring_Rule_Set $rules, int $event_id ): array {
		$rows = array_filter(
			$this->results->for_event( $event_id ),
			static fn( array $row ): bool => empty( $row['is_manual'] ) && empty( $row['is_withdrawn'] ) && ! empty( $row['raw_punches'] )
		);

		$out = array();

		foreach ( Results_Repo::effective_rows( $rows, null, $rules ) as $row ) {
			$out[] = array(
				'name'      => $row['display_name'],
				'maprun'    => $row['maprun_score'],
				'custom'    => 'custom' === $row['score_source'] ? $row['score'] : null,
				'breakdown' => $row['score_breakdown'],
			);
		}

		return $out;
	}

	/**
	 * Pretty-print stored JSON for editing.
	 *
	 * @param string $json Stored definition.
	 */
	private function pretty( string $json ): string {
		$decoded = json_decode( $json );

		return null === $decoded ? $json : (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT );
	}

	private function render_library(): void {
		$sets = $this->rule_sets->all();

		?>
		<h2><?php esc_html_e( 'Saved definitions', 'mvoc-streeto' ); ?></h2>
		<?php if ( ! $sets ) : ?>
			<p><?php esc_html_e( 'None yet. Paste one below.', 'mvoc-streeto' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:720px">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
				<th><?php esc_html_e( 'Scheme', 'mvoc-streeto' ); ?></th>
				<th><?php esc_html_e( 'Events using it', 'mvoc-streeto' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $sets as $set ) : ?>
				<?php
				$rules  = Scoring_Rule_Set::parse( $set['definition'] )['rules'];
				$scheme = $rules ? Scoring_Scheme_Registry::find( $rules->scheme ) : null;
				?>
				<tr>
					<td><?php echo esc_html( $set['name'] ); ?></td>
					<td><?php echo esc_html( $scheme ? $scheme->label() : '—' ); ?></td>
					<td><?php echo esc_html( (string) $this->rule_sets->usage_count( $set['id'] ) ); ?></td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'page' => Admin_Menu::SLUG . '-scoring', 'rule_set' => $set['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'mvoc-streeto' ); ?></a>
						<form method="post" style="display:inline" onsubmit="return window.confirm( <?php echo esc_attr( (string) wp_json_encode( __( 'Delete this scoring definition?', 'mvoc-streeto' ) ) ); ?> );">
							<?php wp_nonce_field( self::NONCE ); ?>
							<input type="hidden" name="rule_set" value="<?php echo esc_attr( (string) $set['id'] ); ?>" />
							<button type="submit" name="mvoc_action" value="delete" class="button-link" style="color:#b32d2e"><?php esc_html_e( 'Delete', 'mvoc-streeto' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param string                         $text          Text to show in the box.
	 * @param array<string,mixed>|null       $editing       Definition being edited.
	 * @param array<int,array<string,mixed>> $events        Events of the active series.
	 * @param int                            $preview_event Event chosen for the check.
	 */
	private function render_editor( string $text, ?array $editing, array $events, int $preview_event ): void {
		?>
		<h2><?php echo esc_html( $editing ? __( 'Edit definition', 'mvoc-streeto' ) : __( 'New definition', 'mvoc-streeto' ) ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="rule_set" value="<?php echo esc_attr( (string) ( $editing['id'] ?? 0 ) ); ?>" />
			<p><?php esc_html_e( 'Paste JSON, or a list of "control, points" lines (add a third column for visit order). Use the prompt below to get an AI to write the JSON from a description of the event.', 'mvoc-streeto' ); ?></p>
			<p>
				<label><?php esc_html_e( 'Name (for a pasted list; JSON carries its own)', 'mvoc-streeto' ); ?>
					<input type="text" name="name" class="regular-text" value="<?php echo esc_attr( (string) ( $editing['name'] ?? '' ) ); ?>" />
				</label>
			</p>
			<p><textarea name="definition" rows="16" class="large-text code"><?php echo esc_textarea( $text ); ?></textarea></p>
			<p>
				<label><?php esc_html_e( 'Check against the results of', 'mvoc-streeto' ); ?>
					<select name="preview_event">
						<option value="0"><?php esc_html_e( '— no preview —', 'mvoc-streeto' ); ?></option>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( (string) $event['id'] ); ?>" <?php selected( $preview_event, (int) $event['id'] ); ?>><?php echo esc_html( $event['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<p>
				<button type="submit" name="mvoc_action" value="check" class="button"><?php esc_html_e( 'Check', 'mvoc-streeto' ); ?></button>
				<button type="submit" name="mvoc_action" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'mvoc-streeto' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>>|null $preview Preview rows, or null for none.
	 */
	private function render_preview( ?array $preview ): void {
		if ( null === $preview ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Preview', 'mvoc-streeto' ); ?></h2>
		<?php if ( ! $preview ) : ?>
			<p><?php esc_html_e( 'That event has no imported results with punches to score. Results imported before this feature are filled in when the plugin upgrades; hand-entered rows have none.', 'mvoc-streeto' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:720px">
			<thead><tr>
				<th><?php esc_html_e( 'Runner', 'mvoc-streeto' ); ?></th>
				<th><?php esc_html_e( 'MapRun', 'mvoc-streeto' ); ?></th>
				<th><?php esc_html_e( 'Custom', 'mvoc-streeto' ); ?></th>
				<th><?php esc_html_e( 'How scored', 'mvoc-streeto' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $preview as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['name'] ); ?></td>
					<td><?php echo esc_html( null === $row['maprun'] ? '—' : (string) $row['maprun'] ); ?></td>
					<td><?php echo esc_html( null === $row['custom'] ? '—' : (string) $row['custom'] ); ?></td>
					<td>
						<details><summary><?php echo esc_html( (string) count( $row['breakdown'] ) ); ?></summary>
							<ul style="margin:0">
							<?php foreach ( $row['breakdown'] as $step ) : ?>
								<li><?php echo esc_html( trim( sprintf( '%s %s: %+d %s', $step['outcome'], $step['control'], $step['points'], $step['note'] ) ) ); ?></li>
							<?php endforeach; ?>
							</ul>
						</details>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $events Events of the active series.
	 */
	private function render_assignments( array $events ): void {
		if ( ! $events ) {
			return;
		}

		$sets = $this->rule_sets->all();

		?>
		<h2><?php esc_html_e( 'Which event uses which', 'mvoc-streeto' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event['label'] ); ?></td>
						<td>
							<select name="event_rule_set[<?php echo esc_attr( (string) $event['id'] ); ?>]">
								<option value="0"><?php esc_html_e( 'Standard (MapRun\'s score)', 'mvoc-streeto' ); ?></option>
								<?php foreach ( $sets as $set ) : ?>
									<option value="<?php echo esc_attr( (string) $set['id'] ); ?>" <?php selected( (int) ( $event['scoring_rule_set_id'] ?? 0 ), $set['id'] ); ?>><?php echo esc_html( $set['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( ! empty( $event['is_published'] ) ) : ?>
								<span class="description"><?php esc_html_e( 'published: edits to the definition do not change it until reattached', 'mvoc-streeto' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="submit" name="mvoc_action" value="assign" class="button button-primary"><?php esc_html_e( 'Save event scoring', 'mvoc-streeto' ); ?></button></p>
		</form>
		<?php
	}

	private function render_prompt(): void {
		?>
		<h2><?php esc_html_e( 'Prompt for an AI assistant', 'mvoc-streeto' ); ?></h2>
		<p><?php esc_html_e( 'Copy this into an AI assistant, finish it with a description of the event, and paste the JSON it returns above. Always use Check against a real event before saving.', 'mvoc-streeto' ); ?></p>
		<p><textarea readonly rows="14" class="large-text code" onfocus="this.select()"><?php echo esc_textarea( Scoring_Prompt::text() ); ?></textarea></p>
		<?php
	}
}
