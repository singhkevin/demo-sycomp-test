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

	const SESSION_KEY = 'sycomp_active_market';
	const META_KEY    = '_sycomp_active_market';

	/**
	 * Runtime cache of the resolved active market key.
	 *
	 * @var string|null
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
	 * The currently active market for the logged-in user.
	 *
	 * @return string Market key or empty string.
	 */
	public static function get_active_market() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		if ( ! is_user_logged_in() ) {
			self::$cache = '';
			return '';
		}

		$user_id   = get_current_user_id();
		$candidate = '';

		// 1. Session.
		$session = self::session();
		if ( $session ) {
			$candidate = (string) $session->get( self::SESSION_KEY, '' );
		}

		// 2. User meta (last used).
		if ( ! $candidate ) {
			$candidate = (string) get_user_meta( $user_id, self::META_KEY, true );
		}

		// Validate access; otherwise resolve a default.
		$company_id = Sycomp_B2B_User::get_company( $user_id );
		$available  = Sycomp_B2B_Markets::get_available_markets( $company_id );
		
		if ( ! $candidate || ! in_array( $candidate, $available, true ) ) {
			$candidate = empty( $available ) ? '' : $available[0];
			if ( $candidate ) {
				self::persist( $candidate, $user_id );
			}
		}

		self::$cache = $candidate;
		return $candidate;
	}

	/**
	 * Set the active market.
	 *
	 * @param string $market_key Market key.
	 * @return bool True on success.
	 */
	public static function set_active_market( $market_key ) {
		$user_id    = get_current_user_id();
		$company_id = Sycomp_B2B_User::get_company( $user_id );
		$available  = Sycomp_B2B_Markets::get_available_markets( $company_id );

		if ( ! $user_id || ! in_array( $market_key, $available, true ) ) {
			return false;
		}

		$previous = self::get_active_market();
		if ( $previous === $market_key ) {
			return true;
		}

		self::persist( $market_key, $user_id );
		self::$cache = $market_key;

		/**
		 * Fires after the active market changes.
		 *
		 * @param string $market_key New active market key.
		 * @param string $previous   Previous active market key.
		 */
		do_action( 'sycomp_b2b_market_changed', $market_key, $previous );

		return true;
	}

	/**
	 * Persist the active market to session + user meta.
	 *
	 * @param string $market_key Market key.
	 * @param int    $user_id    User ID.
	 */
	protected static function persist( $market_key, $user_id ) {
		$session = self::session();
		if ( $session ) {
			$session->set( self::SESSION_KEY, $market_key );
		}
		update_user_meta( (int) $user_id, self::META_KEY, $market_key );
	}

	/**
	 * The currently active location ID for the logged-in user.
	 * Returns 0 if the user is shopping in a market where they have no assigned location.
	 *
	 * @return int
	 */
	public static function get_active_location_id() {
		$market = self::get_active_market();
		return $market ? self::get_location_for_market( $market ) : 0;
	}

	/**
	 * Set the active location (wrapper for legacy calls).
	 *
	 * @param int $location_id Location post ID.
	 * @return bool True on success.
	 */
	public static function set_active_location_id( $location_id ) {
		$market = Sycomp_B2B_Post_Types::get_location_market( $location_id );
		return $market ? self::set_active_market( $market ) : false;
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
	 * Whether a usable active market is set.
	 *
	 * @return bool
	 */
	public static function has_active_location() {
		return self::get_active_market() !== '';
	}

	/**
	 * Resolve default market (fallback).
	 *
	 * @param int $user_id User ID.
	 * @return string Market key or ''.
	 */
	public static function resolve_default_location( $user_id = 0 ) {
		$company_id = Sycomp_B2B_User::get_company( $user_id );
		$available  = Sycomp_B2B_Markets::get_available_markets( $company_id );
		return empty( $available ) ? '' : $available[0];
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
