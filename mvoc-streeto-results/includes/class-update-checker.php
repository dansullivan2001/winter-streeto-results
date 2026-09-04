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

		$vcs_api = $update_checker->getVcsApi();
		if ( null !== $vcs_api ) {
			// GitHub renamed the default branch to "main"; without this the
			// checker's fallback strategy still probes the now-nonexistent
			// "master" on every check.
			$vcs_api->setBranch( 'main' );

			// The plugin lives in a subdirectory of the repo (mvoc-streeto-results/),
			// alongside tests and docs that must not ship. Releases attach the
			// built, install-ready zip from tools/build-zip.sh as a release asset;
			// this tells the checker to fetch that asset instead of GitHub's
			// auto-generated source archive.
			$vcs_api->enableReleaseAssets();

			// Unauthenticated GitHub API calls are capped at 60/hour per IP,
			// shared across every site and every plugin on the same host —
			// easy to exhaust and the cause of "Could not determine if updates
			// are available" (HTTP 403). A token raises that to 5000/hour.
			// Public-repo read access needs no scopes at all, so this only
			// has to be defined, not kept especially secret; it still lives in
			// wp-config.php rather than the plugin so it is never committed.
			if ( defined( 'MVOC_STREETO_GITHUB_TOKEN' ) && MVOC_STREETO_GITHUB_TOKEN ) {
				$vcs_api->setAuthentication( MVOC_STREETO_GITHUB_TOKEN );
			}
		}
	}
}
