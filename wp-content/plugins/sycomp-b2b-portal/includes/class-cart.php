<?php
/**
 * Per-location carts.
 *
 * Each location keeps its own independent cart so that a purchase order is
 * always scoped to exactly one location — no cross-location overlap. The
 * live WooCommerce cart always represents the *active* location; carts for
 * the buyer's other locations are parked in user meta and swapped in when
 * the buyer switches location.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Cart.
 */
class Sycomp_B2B_Cart {

	/**
	 * User meta key storing parked carts: array<location_id, item[]>.
	 */
	const META_CARTS = '_sycomp_location_carts';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'sycomp_b2b_location_changed', array( __CLASS__, 'on_location_changed' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'tag_cart_item' ), 10, 2 );
	}

	/**
	 * Tag every cart item with the location it was added under.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @return array
	 */
	public static function tag_cart_item( $cart_item_data ) {
		$cart_item_data['sycomp_location'] = Sycomp_B2B_Context::get_active_location_id();
		return $cart_item_data;
	}

	/**
	 * Swap carts when the active location changes.
	 *
	 * @param int $new_location      New active location ID.
	 * @param int $previous_location Previous active location ID.
	 */
	public static function on_location_changed( $new_location, $previous_location ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$parked = self::get_parked_carts( $user_id );

		// 1. Snapshot the current (previous location's) cart.
		if ( $previous_location ) {
			$parked[ (int) $previous_location ] = self::snapshot_live_cart();
		}

		// 2. Empty the live cart.
		WC()->cart->empty_cart();

		// 3. Restore the new location's parked cart, if any.
		$restore = isset( $parked[ (int) $new_location ] ) ? $parked[ (int) $new_location ] : array();
		self::restore_to_live_cart( $restore );

		// The new location is now the live cart — drop it from the parked set.
		unset( $parked[ (int) $new_location ] );

		self::save_parked_carts( $user_id, $parked );
	}

	/**
	 * Snapshot the live WooCommerce cart to a portable item array.
	 *
	 * @return array[] Each item: product_id, variation_id, quantity.
	 */
	protected static function snapshot_live_cart() {
		$items = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$items[] = array(
				'product_id'   => (int) $cart_item['product_id'],
				'variation_id' => isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
				'variation'    => isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ? $cart_item['variation'] : array(),
				'quantity'     => (int) $cart_item['quantity'],
			);
		}
		return $items;
	}

	/**
	 * Restore a portable item array into the live WooCommerce cart.
	 *
	 * @param array[] $items Items as produced by snapshot_live_cart().
	 */
	protected static function restore_to_live_cart( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$quantity   = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			if ( ! $product_id || ! wc_get_product( $product_id ) ) {
				continue;
			}
			WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array()
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Parked cart storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Get the parked carts map for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array>
	 */
	public static function get_parked_carts( $user_id ) {
		$parked = get_user_meta( (int) $user_id, self::META_CARTS, true );
		return is_array( $parked ) ? $parked : array();
	}

	/**
	 * Save the parked carts map for a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $parked  Parked carts map.
	 */
	protected static function save_parked_carts( $user_id, $parked ) {
		update_user_meta( (int) $user_id, self::META_CARTS, $parked );
	}

	/**
	 * Number of line items currently in a location's cart.
	 *
	 * For the active location this reads the live cart; for others it reads
	 * the parked snapshot.
	 *
	 * @param int $location_id Location ID.
	 * @return int
	 */
	public static function get_location_item_count( $location_id ) {
		$location_id = (int) $location_id;
		$active      = Sycomp_B2B_Context::get_active_location_id();

		if ( $location_id === $active ) {
			return ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
		}

		$parked = self::get_parked_carts( get_current_user_id() );
		if ( empty( $parked[ $location_id ] ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $parked[ $location_id ] as $item ) {
			$count += isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
		}
		return $count;
	}

	/**
	 * Clear the live cart for the active location (used after a PO submit).
	 */
	public static function clear_active_cart() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Remove a location's parked cart entirely (used after a PO submit if
	 * the submitted location is not the active one).
	 *
	 * @param int $location_id Location ID.
	 */
	public static function clear_parked_cart( $location_id ) {
		$user_id = get_current_user_id();
		$parked  = self::get_parked_carts( $user_id );
		unset( $parked[ (int) $location_id ] );
		self::save_parked_carts( $user_id, $parked );
	}
}
