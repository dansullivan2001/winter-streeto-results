<?php
/**
 * Wires up GitHub-based update checks, so the plugin shows an "update
 * available" notice on the Plugins screen like anything installed from
 * WordPress.org.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

use YahnisElsts\PluginUpdateChecker\v5p7\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Points the vendored Plugin Update Checker library at this plugin's GitHub
 * releases.
 */
class Update_Checker {

	private const REPOSITORY_URL = 'https://github.com/dansullivan2001/winter-streeto-results/';

	/**
	 * Register the update check.
	 */
	public static function init(): void {
		require_once MVOC_STREETO_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		$update_checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			MVOC_STREETO_FILE,
			'mvoc-streeto-results'
		);

		// The plugin lives in a subdirectory of the repo (mvoc-streeto-results/),
		// alongside tests and docs that must not ship. Releases attach the
		// built, install-ready zip from tools/build-zip.sh as a release asset;
		// this tells the checker to fetch that asset instead of GitHub's
		// auto-generated source archive.
		$vcs_api = $update_checker->getVcsApi();
		if ( null !== $vcs_api ) {
			$vcs_api->enableReleaseAssets();
		}
	}
}
