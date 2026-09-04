<?php
/**
 * Invalidation for the league table's transient cache.
 *
 * The cache key used to be the latest event's published_at, which self-clears
 * on publish but not on anything after — a correction, a manual row, a
 * cancellation. Those all leave published_at untouched, so a stale table
 * could sit for up to a day. A single generation counter, bumped by every
 * write that could change what the league shows, replaces that.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO;

defined( 'ABSPATH' ) || exit;

/**
 * A sitewide counter the league shortcode mixes into its cache key.
 */
class League_Cache {

	private const OPTION = 'mvoc_streeto_league_gen';

	/**
	 * Invalidate every cached league table.
	 */
	public static function bump(): void {
		update_option( self::OPTION, self::generation() + 1, false );
	}

	/**
	 * The current generation, for mixing into a cache key.
	 */
	public static function generation(): int {
		return (int) get_option( self::OPTION, 0 );
	}
}
