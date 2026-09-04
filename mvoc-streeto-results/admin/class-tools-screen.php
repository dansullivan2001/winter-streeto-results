<?php
/**
 * Danger-zone tools: a full data scrub for repeated local/perf testing.
 *
 * Kept as its own screen, gated behind `manage_options` rather than
 * Plugin::CAPABILITY, so a League Co-ordinator account can never reach it —
 * only a true site Administrator can wipe a season, and only after typing
 * the confirmation phrase below.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and handles the Tools screen.
 */
class Tools_Screen {

	private const NONCE = 'mvoc_streeto_tools';

	/**
	 * Exact phrase the admin must type to confirm a scrub.
	 */
	private const CONFIRM_PHRASE = 'DELETE ALL DATA';

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$notice = '';
		$error  = '';

		if ( isset( $_POST['mvoc_streeto_scrub'] ) ) {
			check_admin_referer( self::NONCE );

			$typed = isset( $_POST['confirm_phrase'] )
				? sanitize_text_field( wp_unslash( $_POST['confirm_phrase'] ) )
				: '';

			if ( self::CONFIRM_PHRASE !== $typed ) {
				$error = __( 'Nothing was deleted: the confirmation phrase did not match.', 'mvoc-streeto' );
			} else {
				Schema::truncate_all();
				$notice = __( 'All StreetO data has been deleted. Every series, event, competitor, result and MapRun snapshot is gone; the plugin is back to a fresh install.', 'mvoc-streeto' );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tools', 'mvoc-streeto' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Danger zone', 'mvoc-streeto' ); ?></h2>
			<div class="notice notice-warning inline" style="padding: 1em 1.5em; max-width: 640px;">
				<p><strong><?php esc_html_e( 'Delete all StreetO data', 'mvoc-streeto' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'Permanently empties every series, event, competitor, result, override and MapRun snapshot this plugin has stored. There is no undo. This is meant for clearing test data between runs, not for use on a live season.', 'mvoc-streeto' ); ?>
				</p>
				<form method="post" onsubmit="return window.confirm( <?php echo wp_json_encode( __( 'This will permanently delete every StreetO result. Continue?', 'mvoc-streeto' ) ); ?> );">
					<?php wp_nonce_field( self::NONCE ); ?>
					<p>
						<label for="mvoc-confirm-phrase">
							<?php
							printf(
								/* translators: %s: the exact phrase to type. */
								esc_html__( 'Type %s to confirm:', 'mvoc-streeto' ),
								'<code>' . esc_html( self::CONFIRM_PHRASE ) . '</code>'
							);
							?>
						</label>
						<br />
						<input type="text" id="mvoc-confirm-phrase" name="confirm_phrase" class="regular-text" autocomplete="off" />
					</p>
					<p>
						<button type="submit" name="mvoc_streeto_scrub" value="1" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e;">
							<?php esc_html_e( 'Delete all data', 'mvoc-streeto' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
