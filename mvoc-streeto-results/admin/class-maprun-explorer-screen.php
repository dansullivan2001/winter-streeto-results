<?php
/**
 * MapRun Explorer: connectivity check and raw response viewer.
 *
 * This screen exists to answer two setup questions with evidence rather than
 * assumption:
 *
 *   1. Can this host reach the MapRun API on port 8886 at all?
 *   2. What does a real Winter StreetO response actually contain — in
 *      particular, which field carries the score MapRun computes for a score
 *      event? That name is not yet confirmed, and guessing it would fail
 *      quietly at the worst moment.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\MapRun\Client;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and handles the explorer screen.
 */
class MapRun_Explorer_Screen {

	private const NONCE = 'mvoc_streeto_explore';

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$event_name  = '';
		$pasted      = '';
		$error       = '';
		$rows        = array();
		$inventory   = array();
		$parsed      = array();
		$connectivity = null;

		if ( isset( $_POST['mvoc_streeto_action'] ) ) {
			check_admin_referer( self::NONCE );

			$action     = sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) );
			$event_name = isset( $_POST['event_name'] ) ? sanitize_text_field( wp_unslash( $_POST['event_name'] ) ) : '';

			// JSON must not be sanitised as text: that would mangle the payload
			// we are trying to inspect verbatim. Validate by decoding instead.
			$pasted = isset( $_POST['pasted_json'] ) ? trim( wp_unslash( $_POST['pasted_json'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			$client = new Client();

			try {
				if ( 'check' === $action ) {
					$connectivity = $client->check_connectivity();
				} elseif ( 'fetch' === $action ) {
					$rows = $client->fetch( $event_name )['rows'];
				} elseif ( 'paste' === $action ) {
					$rows = $client->ingest( $pasted )['rows'];
				}
			} catch ( \RuntimeException $e ) {
				$error = $e->getMessage();
			}

			if ( $rows ) {
				$inventory = Parser::field_inventory( $rows );
				$parsed    = ( new Parser() )->parse( array_slice( $rows, 0, 10 ) );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MapRun Explorer', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Inspect a raw MapRun response before wiring an event up. Use this once at setup to confirm this server can reach MapRun, and to identify which field carries the score.', 'mvoc-streeto' ); ?>
			</p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $connectivity ) ) : ?>
				<div class="notice notice-<?php echo $connectivity['ok'] ? 'success' : 'warning'; ?>">
					<p><?php echo esc_html( $connectivity['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( '1. Can this server reach MapRun?', 'mvoc-streeto' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: API host and port. */
						esc_html__( 'The MapRun API listens on %s. Some hosting blocks outbound traffic to non-standard ports; if this check fails, use the Paste JSON box below instead — everything else works the same way.', 'mvoc-streeto' ),
						'<code>' . esc_html( Client::API_HOST . ':' . Client::API_PORT ) . '</code>'
					);
					?>
				</p>
				<p>
					<button type="submit" name="mvoc_streeto_action" value="check" class="button">
						<?php esc_html_e( 'Test connection', 'mvoc-streeto' ); ?>
					</button>
				</p>

				<h2><?php esc_html_e( '2. Inspect an event', 'mvoc-streeto' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mvoc-event-name"><?php esc_html_e( 'MapRun event name', 'mvoc-streeto' ); ?></label></th>
						<td>
							<input type="text" id="mvoc-event-name" name="event_name" class="regular-text"
								value="<?php echo esc_attr( $event_name ); ?>" />
							<p class="description"><?php esc_html_e( 'The full event name exactly as published in MapRun.', 'mvoc-streeto' ); ?></p>
							<button type="submit" name="mvoc_streeto_action" value="fetch" class="button button-primary">
								<?php esc_html_e( 'Fetch from MapRun', 'mvoc-streeto' ); ?>
							</button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mvoc-pasted-json"><?php esc_html_e( 'Or paste JSON', 'mvoc-streeto' ); ?></label></th>
						<td>
							<textarea id="mvoc-pasted-json" name="pasted_json" rows="6" class="large-text code"
								placeholder="{&quot;errorFlag&quot;:false,&quot;results&quot;:[ ... ]}"><?php echo esc_textarea( $pasted ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Open the API URL in a browser and paste the response here. Handled identically to a direct fetch.', 'mvoc-streeto' ); ?>
							</p>
							<button type="submit" name="mvoc_streeto_action" value="paste" class="button">
								<?php esc_html_e( 'Read pasted JSON', 'mvoc-streeto' ); ?>
							</button>
						</td>
					</tr>
				</table>
			</form>

			<?php if ( $inventory ) : ?>
				<h2>
					<?php
					printf(
						/* translators: %d: number of result rows. */
						esc_html__( 'Fields found across %d rows', 'mvoc-streeto' ),
						count( $rows )
					);
					?>
				</h2>
				<?php $this->render_score_hint( $parsed ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Present in', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Sample value', 'mvoc-streeto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $inventory as $field => $meta ) : ?>
							<tr>
								<td><code><?php echo esc_html( $field ); ?></code></td>
								<td><?php echo esc_html( $meta['type'] ); ?></td>
								<td>
									<?php
									printf(
										/* translators: 1: rows containing the field, 2: total rows. */
										esc_html__( '%1$d of %2$d', 'mvoc-streeto' ),
										(int) $meta['count'],
										count( $rows )
									);
									?>
								</td>
								<td><?php echo esc_html( $meta['sample'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'First rows, as parsed', 'mvoc-streeto' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Club', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Gender', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Classifier', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Time', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Score', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Punches', 'mvoc-streeto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $parsed as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['display_name'] ); ?></td>
								<td><?php echo esc_html( $row['club'] ); ?></td>
								<td><?php echo esc_html( $row['gender'] ); ?></td>
								<td><?php echo esc_html( $row['classifier'] ); ?></td>
								<td><?php echo esc_html( $row['time_display'] ); ?></td>
								<td>
									<?php
									echo null === $row['score']
										? '<em>' . esc_html__( 'not found', 'mvoc-streeto' ) . '</em>'
										: esc_html( (string) $row['score'] );
									?>
								</td>
								<td><?php echo esc_html( (string) count( $row['punches'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Say plainly whether the score field was identified.
	 *
	 * @param array<int,array<string,mixed>> $parsed Parsed sample rows.
	 */
	private function render_score_hint( array $parsed ): void {
		$fields = array_filter( array_column( $parsed, 'score_field' ) );

		if ( $fields ) {
			$field = (string) reset( $fields );
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				sprintf(
					/* translators: %s: field name. */
					esc_html__( 'Score found in the %s field. Record this — it pins the last unconfirmed part of the MapRun contract.', 'mvoc-streeto' ),
					'<code>' . esc_html( $field ) . '</code>'
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'No score field recognised. Check the field list below for the column holding each runner\'s points, and add its name to the parser.', 'mvoc-streeto' )
		);
	}
}
