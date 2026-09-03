<?php
/**
 * Competitor registry screen: edit category flags, and merge duplicates.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Importer;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;
use MVOC\StreetO\Repo\Events_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists competitors and handles edits.
 */
class Competitors_Screen {

	private const NONCE = 'mvoc_streeto_competitors';

	private Competitors_Repo $repo;

	private Events_Repo $events;

	/**
	 * @param Competitors_Repo|null $repo   Competitor persistence.
	 * @param Events_Repo|null      $events Series and events persistence.
	 */
	public function __construct( ?Competitors_Repo $repo = null, ?Events_Repo $events = null ) {
		$this->repo   = $repo ?? new Competitors_Repo();
		$this->events = $events ?? new Events_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$all_series = $this->events->all_series();
		$series     = $this->current_series( $all_series );
		$notice     = $this->handle_post( $series );

		// Over-55 belongs to a season, so the list is always shown in the
		// context of one. Without that the checkbox would have no meaning.
		$competitors = $series
			? $this->repo->all_for_series( (int) $series['id'] )
			: $this->repo->all();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Competitors', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Both categories come from MapRun, which is self-declared and sometimes wrong or missing. Correct anything here — an edit sticks and is never overwritten by a later import.', 'mvoc-streeto' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Ladies belongs to the person. Over-55 belongs to the season, because everybody\'s age changes every year — so it is shown and edited for one season at a time, and correcting it never disturbs a season already published.', 'mvoc-streeto' ); ?>
			</p>

			<?php if ( $series ) : ?>
				<?php $coverage = ( new Importer() )->maprun_age_coverage( (int) $series['id'] ); ?>
				<form method="post" style="margin:1em 0;padding:0.75em;border:1px solid #ccd0d4;background:#fff;">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="series_slug" value="<?php echo esc_attr( $series['slug'] ); ?>" />
					<strong><?php esc_html_e( 'Rebuild Over-55 from MapRun', 'mvoc-streeto' ); ?></strong>
					<p class="description">
						<?php
						printf(
							/* translators: 1: rows carrying MapRun age data, 2: total rows in the season. */
							esc_html__( 'MapRun age data is stored on %1$d of this season\'s %2$d result rows.', 'mvoc-streeto' ),
							(int) $coverage['with'],
							(int) $coverage['total']
						);
						?>
						<?php if ( $coverage['with'] < $coverage['total'] ) : ?>
							<br />
							<?php esc_html_e( 'Rows imported before the plugin began keeping it carry none. Re-import those events first — re-importing is safe and keeps every correction — then rebuild.', 'mvoc-streeto' ); ?>
						<?php endif; ?>
					</p>
					<p>
						<button type="submit" name="mvoc_streeto_action" value="refresh_over55" class="button"
							<?php disabled( 0 === $coverage['with'] ); ?>
							onclick="return confirm('<?php echo esc_js( __( 'Rebuild this season\'s Over-55 flags from MapRun? Any you have corrected by hand will be overwritten.', 'mvoc-streeto' ) ); ?>');">
							<?php esc_html_e( 'Rebuild from MapRun', 'mvoc-streeto' ); ?>
						</button>
						<span class="description"><?php esc_html_e( 'Overwrites manual corrections for this season only.', 'mvoc-streeto' ); ?></span>
					</p>
				</form>
			<?php endif; ?>

			<?php if ( $all_series ) : ?>
				<form method="get" style="margin:1em 0;">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Menu::SLUG . '-competitors' ); ?>" />
					<label for="mvoc-comp-series"><strong><?php esc_html_e( 'Season', 'mvoc-streeto' ); ?></strong></label>
					<select id="mvoc-comp-series" name="series" onchange="this.form.submit()">
						<?php foreach ( $all_series as $option ) : ?>
							<option value="<?php echo esc_attr( $option['slug'] ); ?>"
								<?php selected( $series['slug'] ?? '', $option['slug'] ); ?>>
								<?php echo esc_html( $option['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Switch', 'mvoc-streeto' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $competitors ) : ?>
				<p><?php esc_html_e( 'No competitors yet. They are created as you confirm names after an event is imported.', 'mvoc-streeto' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="series_slug" value="<?php echo esc_attr( $series['slug'] ?? '' ); ?>" />
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Club', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Ladies', 'mvoc-streeto' ); ?></th>
							<th>
								<?php
								echo $series
									? esc_html(
										sprintf(
											/* translators: %s: series name. */
											__( 'Over 55 — %s', 'mvoc-streeto' ),
											$series['name']
										)
									)
									: esc_html__( 'Over 55', 'mvoc-streeto' );
								?>
							</th>
							<th><?php esc_html_e( 'Merge into', 'mvoc-streeto' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $competitors as $competitor ) : ?>
							<?php $id = (int) $competitor['id']; ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $competitor['display_name'] ); ?></strong>
								</td>
								<td><?php echo esc_html( $competitor['club'] ); ?></td>
								<td>
									<input type="checkbox" name="is_female[<?php echo esc_attr( (string) $id ); ?>]"
										value="1" <?php checked( $competitor['is_female'] ); ?> />
								</td>
								<td>
									<input type="checkbox" name="is_over55[<?php echo esc_attr( (string) $id ); ?>]"
										value="1" <?php checked( ! empty( $competitor['is_over55'] ) ); ?>
										<?php disabled( ! $series ); ?> />
								</td>
								<td>
									<select name="merge_into[<?php echo esc_attr( (string) $id ); ?>]">
										<option value="">&mdash;</option>
										<?php foreach ( $competitors as $target ) : ?>
											<?php if ( (int) $target['id'] === $id ) : ?>
												<?php continue; ?>
											<?php endif; ?>
											<option value="<?php echo esc_attr( (string) $target['id'] ); ?>">
												<?php echo esc_html( $target['display_name'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Merging moves the absorbed competitor\'s results and name spellings across, then deletes the empty record. It cannot be undone.', 'mvoc-streeto' ); ?>
				</p>

				<p>
					<button type="submit" name="mvoc_streeto_action" value="save" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'mvoc-streeto' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Apply a submitted change, returning a notice to show.
	 */
	private function handle_post( ?array $series ): string {
		if ( ! isset( $_POST['mvoc_streeto_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['mvoc_streeto_action'] ) );

		if ( 'refresh_over55' === $action ) {
			if ( ! $series ) {
				return '';
			}

			$changed = ( new Importer() )->refresh_categories( (int) $series['id'] );

			return sprintf(
				/* translators: 1: number of flags changed, 2: series name. */
				_n(
					'Rebuilt %1$d Over-55 flag for %2$s from MapRun.',
					'Rebuilt %1$d Over-55 flags for %2$s from MapRun.',
					$changed,
					'mvoc-streeto'
				),
				$changed,
				$series['name']
			);
		}

		$female = $this->checkbox_ids( 'is_female' );
		$over55 = $this->checkbox_ids( 'is_over55' );
		$merges = $this->merge_map();

		// Merges run first: flags submitted for a competitor about to be
		// absorbed would otherwise be written to a row that is then deleted.
		foreach ( $merges as $from_id => $into_id ) {
			$this->repo->merge( $from_id, $into_id );
		}

		foreach ( $this->repo->all() as $competitor ) {
			$id = (int) $competitor['id'];

			$this->repo->update(
				$id,
				array(
					'first_name'   => $competitor['first_name'],
					'surname'      => $competitor['surname'],
					'display_name' => $competitor['display_name'],
					'club'         => $competitor['club'],
					'is_female'    => in_array( $id, $female, true ),
				)
			);

			if ( $series ) {
				$this->repo->set_over55( (int) $series['id'], $id, in_array( $id, $over55, true ) );
			}
		}

		if ( $merges ) {
			return sprintf(
				/* translators: %d: number of competitors merged. */
				_n( 'Saved, and merged %d competitor.', 'Saved, and merged %d competitors.', count( $merges ), 'mvoc-streeto' ),
				count( $merges )
			);
		}

		return __( 'Saved.', 'mvoc-streeto' );
	}

	/**
	 * The season being edited: the one asked for, else the one remembered.
	 *
	 * Shares its memory with the series screen, so switching season in one
	 * place does not leave the other looking at a different year.
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
					update_user_meta( get_current_user_id(), 'mvoc_streeto_last_series', $slug );

					return $series;
				}
			}
		}

		$remembered = (string) get_user_meta( get_current_user_id(), 'mvoc_streeto_last_series', true );
		foreach ( $all_series as $series ) {
			if ( $series['slug'] === $remembered ) {
				return $series;
			}
		}

		foreach ( $all_series as $series ) {
			if ( ! empty( $series['is_active'] ) ) {
				return $series;
			}
		}

		return $all_series[0] ?? null;
	}

	/**
	 * Competitor ids whose checkbox was ticked for a given field.
	 *
	 * @param string $field Field name.
	 * @return int[]
	 */
	private function checkbox_ids( string $field ): array {
		if ( ! isset( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}

		return array_map( 'intval', array_keys( wp_unslash( $_POST[ $field ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Requested merges, as absorbed id => surviving id.
	 *
	 * @return array<int,int>
	 */
	private function merge_map(): array {
		if ( ! isset( $_POST['merge_into'] ) || ! is_array( $_POST['merge_into'] ) ) {
			return array();
		}

		$merges = array();
		foreach ( wp_unslash( $_POST['merge_into'] ) as $from => $into ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$from_id = (int) $from;
			$into_id = (int) $into;

			if ( $from_id > 0 && $into_id > 0 && $from_id !== $into_id ) {
				$merges[ $from_id ] = $into_id;
			}
		}

		return $merges;
	}
}
