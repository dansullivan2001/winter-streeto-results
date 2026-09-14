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
	 * Whether bumps are being held back for a batch.
	 */
	private static bool $deferred = false;

	/**
	 * Whether a held-back bump is waiting to be applied.
	 */
	private static bool $pending = false;

	/**
	 * Hold bumps until release(), collapsing a batch into one write.
	 *
	 * An import calls the repo once per result row and each of those bumps,
	 * so a 64-row event wrote the option 64 times to invalidate the same
	 * tables once. Nothing is lost by collapsing them: the generation only
	 * has to differ from the one the cached entries were built under, not
	 * count the writes.
	 */
	public static function defer(): void {
		self::$deferred = true;
	}

	/**
	 * Stop holding bumps, applying one if any were asked for.
	 *
	 * Call from a `finally`, so a failed import cannot leave the cache
	 * deferred for the rest of the request.
	 */
	public static function release(): void {
		self::$deferred = false;

		if ( self::$pending ) {
			self::$pending = false;
			self::bump();
		}
	}

	/**
	 * Invalidate every cached league table.
	 */
	public static function bump(): void {
		if ( self::$deferred ) {
			self::$pending = true;

			return;
		}

		update_option( self::OPTION, self::generation() + 1, false );
	}

	/**
	 * The current generation, for mixing into a cache key.
	 */
	public static function generation(): int {
		return (int) get_option( self::OPTION, 0 );
	}
}
