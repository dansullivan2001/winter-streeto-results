<?php
/**
 * Competitor registry screen: edit category flags, and merge duplicates.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Admin;

use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\Plugin;
use MVOC\StreetO\Repo\Competitors_Repo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists competitors and handles edits.
 */
class Competitors_Screen {

	private const NONCE = 'mvoc_streeto_competitors';

	private Competitors_Repo $repo;

	/**
	 * @param Competitors_Repo|null $repo Competitor persistence.
	 */
	public function __construct( ?Competitors_Repo $repo = null ) {
		$this->repo = $repo ?? new Competitors_Repo();
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mvoc-streeto' ) );
		}

		$notice = $this->handle_post();

		$competitors = $this->repo->all();
		$config      = new Scoring_Config();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Competitors', 'mvoc-streeto' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %d: the year used to decide age categories. */
					esc_html__( 'Ladies and Over-55 are taken from MapRun, using age reached during %d. Correct anything MapRun has wrong — an edit here sticks and is never overwritten by a later import.', 'mvoc-streeto' ),
					(int) $config->category_year
				);
				?>
			</p>

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
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Club', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Born', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Ladies', 'mvoc-streeto' ); ?></th>
							<th><?php esc_html_e( 'Over 55', 'mvoc-streeto' ); ?></th>
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
								<td><?php echo $competitor['year_of_birth'] ? esc_html( (string) $competitor['year_of_birth'] ) : '&mdash;'; ?></td>
								<td>
									<input type="checkbox" name="is_female[<?php echo esc_attr( (string) $id ); ?>]"
										value="1" <?php checked( $competitor['is_female'] ); ?> />
								</td>
								<td>
									<input type="checkbox" name="is_over55[<?php echo esc_attr( (string) $id ); ?>]"
										value="1" <?php checked( $competitor['is_over55'] ); ?> />
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
	private function handle_post(): string {
		if ( ! isset( $_POST['mvoc_streeto_action'] ) ) {
			return '';
		}

		check_admin_referer( self::NONCE );

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
					'first_name'    => $competitor['first_name'],
					'surname'       => $competitor['surname'],
					'display_name'  => $competitor['display_name'],
					'club'          => $competitor['club'],
					'year_of_birth' => $competitor['year_of_birth'],
					'is_female'     => in_array( $id, $female, true ),
					'is_over55'     => in_array( $id, $over55, true ),
				)
			);
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
