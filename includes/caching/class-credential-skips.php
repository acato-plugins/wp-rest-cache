<?php
/**
 * Keeps track of REST requests that weren't cached because they carried credentials.
 *
 * @link: https://www.acato.nl
 * @since 2026.4.3
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/Caching
 */

namespace WP_Rest_Cache_Plugin\Includes\Caching;

/**
 * Keeps track of REST requests that weren't cached because they carried credentials.
 *
 * Since 2026.4.0 requests with an Authorization header (or HTTP authentication) and responses generated for an
 * authenticated user are no longer cached, so a response meant for one user is never served to another. Sites that
 * send credentials with all their REST requests (e.g. a headless front end) silently lost their caching because of
 * this. The counts kept here are used to show an admin notice explaining why, and how to cache these requests safely.
 *
 * To keep the overhead on the (uncached, so already expensive) requests low, only a sample of the skipped requests
 * is written to the database. The counts are therefore estimates.
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/Caching
 * @author     Acato
 */
class Credential_Skips {

	/**
	 * The option the counts are stored in.
	 *
	 * @var string OPTION
	 */
	const OPTION = 'wp_rest_cache_credential_skips';

	/**
	 * Skip reason: the request carried an Authorization header or HTTP authentication credentials.
	 *
	 * @var string REASON_AUTHORIZATION
	 */
	const REASON_AUTHORIZATION = 'authorization';

	/**
	 * Skip reason: the response was generated for an authenticated user.
	 *
	 * @var string REASON_AUTHENTICATED_USER
	 */
	const REASON_AUTHENTICATED_USER = 'authenticated_user';

	/**
	 * The period (in seconds) the skips are counted over, after which counting starts over.
	 *
	 * @var int PERIOD
	 */
	const PERIOD = 86400;

	/**
	 * The singleton instance of this class.
	 *
	 * @access private
	 * @var    \WP_Rest_Cache_Plugin\Includes\Caching\Credential_Skips|null $instance The singleton instance of this class.
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance of this class.
	 *
	 * @return \WP_Rest_Cache_Plugin\Includes\Caching\Credential_Skips
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Record a request that wasn't cached because it carried credentials.
	 *
	 * This runs on every skipped request, before pluggable functions are loaded (the cache is served from the Must-Use
	 * plugin), so it uses mt_rand() instead of wp_rand().
	 *
	 * @param string $reason The skip reason, one of the REASON_* constants.
	 *
	 * @return void
	 */
	public function record( $reason ) {
		$sample_rate = $this->get_sample_rate();
		if ( 0 === $sample_rate ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- wp_rand() is pluggable and not loaded yet.
		if ( $sample_rate > 1 && 1 !== mt_rand( 1, $sample_rate ) ) {
			return;
		}

		$skips            = $this->get_counts();
		$skips[ $reason ] = ( $skips[ $reason ] ?? 0 ) + $sample_rate;
		$skips['since']   = $skips['since'] ?? time();

		update_option( self::OPTION, $skips, false );
	}

	/**
	 * Get the (estimated) number of skipped requests per reason in the current period.
	 *
	 * @return array<string,int> The counts per reason, plus `since`: the start of the current period (if any).
	 */
	public function get_counts() {
		$skips = get_option( self::OPTION, [] );

		if ( ! is_array( $skips ) || ! isset( $skips['since'] ) || (int) $skips['since'] < time() - self::PERIOD ) {
			return [];
		}

		return array_map( 'intval', $skips );
	}

	/**
	 * Get the (estimated) number of skipped requests for one reason in the current period.
	 *
	 * @param string $reason The skip reason, one of the REASON_* constants.
	 *
	 * @return int The number of skipped requests.
	 */
	public function get_count( $reason ) {
		return $this->get_counts()[ $reason ] ?? 0;
	}

	/**
	 * Delete all counts, e.g. on deactivation.
	 *
	 * @return void
	 */
	public function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Get the sample rate: one in how many skipped requests is written to the database.
	 *
	 * @return int The sample rate, 0 disables counting.
	 */
	private function get_sample_rate() {
		/**
		 * Filter one in how many requests that weren't cached because of credentials is counted.
		 *
		 * Only a sample of these requests is written to the database, to keep the overhead low. Return 1 to count
		 * every request, or 0 to disable counting (and with it the admin notice).
		 *
		 * @since 2026.4.3
		 *
		 * @param int $sample_rate The sample rate. Default 10.
		 */
		return max( 0, (int) apply_filters( 'wp_rest_cache/credential_skips_sample_rate', 10 ) );
	}
}
