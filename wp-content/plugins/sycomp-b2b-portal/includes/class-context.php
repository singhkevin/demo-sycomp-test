<?php
/**
 * Active location context.
 *
 * The single source of truth for "which location is the buyer currently
 * shopping". Pricing, currency and the active cart all key off this.
 *
 * Storage:
 *  - WC session  : the working context for the browsing session.
 *  - User meta   : the last-used location, restored on the next login.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Context.
 */
class Sycomp_B2B_Context {

	const SESSION_KEY = 'sycomp_active_location';
	const META_KEY    = '_sycomp_active_location';

	/**
	 * Runtime cache of the resolved active location ID.
	 *
	 * @var int|null
	 */
	protected static $cache = null;

	/**
	 * Safe accessor for the WooCommerce session.
	 *
	 * @return WC_Session|null
	 */
	protected static function session() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			return WC()->session;
		}
		return null;
	}

	/**
	 * The currently active location ID for the logged-in user.
	 *
	 * Falls back to the user's last-used location, then to the first
	 * accessible location. Always returns a location the user may access,
	 * or 0 if the user has no accessible locations.
	 *
	 * @return int
	 */
	public static function get_active_location_id() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( ! is_user_logged_in() ) {
			self::$cache = 0;
			return 0;
		}

		$user_id   = get_current_user_id();
		$candidate = 0;

		// 1. Session.
		$session = self::session();
		if ( $session ) {
			$candidate = (int) $session->get( self::SESSION_KEY, 0 );
		}

		// 2. User meta (last used).
		if ( ! $candidate ) {
			$candidate = (int) get_user_meta( $user_id, self::META_KEY, true );
		}

		// Validate access; otherwise resolve a default.
		if ( ! $candidate || ! Sycomp_B2B_User::can_access_location( $candidate, $user_id ) ) {
			$candidate = self::resolve_default_location( $user_id );
			if ( $candidate ) {
				self::persist( $candidate, $user_id );
			}
		}

		self::$cache = $candidate;
		return $candidate;
	}

	/**
	 * Set the active location.
	 *
	 * @param int $location_id Location post ID.
	 * @return bool True on success.
	 */
	public static function set_active_location_id( $location_id ) {
		$location_id = (int) $location_id;
		$user_id     = get_current_user_id();

		if ( ! $user_id || ! Sycomp_B2B_User::can_access_location( $location_id, $user_id ) ) {
			return false;
		}

		$previous = self::get_active_location_id();
		if ( $previous === $location_id ) {
			return true;
		}

		self::persist( $location_id, $user_id );
		self::$cache = $location_id;

		/**
		 * Fires after the active location changes.
		 *
		 * The cart module listens to this to swap the per-location cart.
		 *
		 * @param int $location_id New active location ID.
		 * @param int $previous    Previous active location ID.
		 */
		do_action( 'sycomp_b2b_location_changed', $location_id, $previous );

		return true;
	}

	/**
	 * Persist the active location to session + user meta.
	 *
	 * @param int $location_id Location post ID.
	 * @param int $user_id     User ID.
	 */
	protected static function persist( $location_id, $user_id ) {
		$session = self::session();
		if ( $session ) {
			$session->set( self::SESSION_KEY, (int) $location_id );
		}
		update_user_meta( (int) $user_id, self::META_KEY, (int) $location_id );
	}

	/**
	 * The active location post object.
	 *
	 * @return WP_Post|null
	 */
	public static function get_active_location() {
		$id = self::get_active_location_id();
		return $id ? get_post( $id ) : null;
	}

	/**
	 * Whether a usable active location is set.
	 *
	 * @return bool
	 */
	public static function has_active_location() {
		return self::get_active_location_id() > 0;
	}

	/**
	 * The market key for the active location.
	 *
	 * @return string Market key, or '' if none.
	 */
	public static function get_active_market() {
		$id = self::get_active_location_id();
		if ( ! $id ) {
			return '';
		}
		$market = Sycomp_B2B_Post_Types::get_location_market( $id );
		return Sycomp_B2B_Markets::exists( $market ) ? $market : '';
	}

	/**
	 * The first accessible location for a user, used as the default.
	 *
	 * @param int $user_id User ID.
	 * @return int Location post ID, or 0.
	 */
	public static function resolve_default_location( $user_id = 0 ) {
		$locations = Sycomp_B2B_User::get_locations( $user_id );
		if ( empty( $locations ) ) {
			return 0;
		}
		return (int) $locations[0]->ID;
	}

	/**
	 * Find an accessible location in a given market.
	 *
	 * Used by the homepage flag selector: a buyer who picks "United States"
	 * is switched to their company's US location.
	 *
	 * @param string $market_key Market key.
	 * @param int    $user_id    User ID. Defaults to current user.
	 * @return int Location post ID, or 0 if the company has none in that market.
	 */
	public static function get_location_for_market( $market_key, $user_id = 0 ) {
		if ( ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return 0;
		}
		foreach ( Sycomp_B2B_User::get_locations( $user_id ) as $location ) {
			if ( Sycomp_B2B_Post_Types::get_location_market( $location->ID ) === $market_key ) {
				return (int) $location->ID;
			}
		}
		return 0;
	}

	/**
	 * Reset the runtime cache (used after programmatic context changes).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}
}
