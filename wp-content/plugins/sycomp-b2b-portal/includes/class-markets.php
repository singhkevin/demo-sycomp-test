<?php
/**
 * Markets registry.
 *
 * The nine Sycomp markets, each with its own currency and price list.
 * Markets are a fixed configuration (not editable content) — pricing per
 * market is what changes per product.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Markets.
 */
class Sycomp_B2B_Markets {

	/**
	 * Raw market definitions.
	 *
	 * @return array<string,array>
	 */
	/**
	 * Raw market definitions.
	 *
	 * @return array<string,array>
	 */
	protected static function definitions() {
		$saved = get_option( 'sycomp_b2b_custom_markets' );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		return array(
			'india' => array(
				'label'        => 'India',
				'currency'     => 'INR',
				'symbol'       => '₹',
				'decimals'     => 2,
				'country_code' => 'IN',
				'order'        => 10,
			),
			'united_states' => array(
				'label'        => 'United States',
				'currency'     => 'USD',
				'symbol'       => '$',
				'decimals'     => 2,
				'country_code' => 'US',
				'order'        => 20,
			),
			'australia' => array(
				'label'        => 'Australia',
				'currency'     => 'AUD',
				'symbol'       => 'A$',
				'decimals'     => 2,
				'country_code' => 'AU',
				'order'        => 30,
			),
			'japan' => array(
				'label'        => 'Japan',
				'currency'     => 'JPY',
				'symbol'       => '¥',
				'decimals'     => 0,
				'country_code' => 'JP',
				'order'        => 40,
			),
			'china' => array(
				'label'        => 'China',
				'currency'     => 'CNY',
				'symbol'       => '¥',
				'decimals'     => 2,
				'country_code' => 'CN',
				'order'        => 50,
			),
			'philippines' => array(
				'label'        => 'Philippines',
				'currency'     => 'PHP',
				'symbol'       => '₱',
				'decimals'     => 2,
				'country_code' => 'PH',
				'order'        => 60,
			),
			'taiwan' => array(
				'label'        => 'Taiwan',
				'currency'     => 'TWD',
				'symbol'       => 'NT$',
				'decimals'     => 0,
				'country_code' => 'TW',
				'order'        => 70,
			),
			'south_africa' => array(
				'label'        => 'South Africa',
				'currency'     => 'ZAR',
				'symbol'       => 'R',
				'decimals'     => 2,
				'country_code' => 'ZA',
				'order'        => 80,
			),
			'uae' => array(
				'label'        => 'UAE',
				'currency'     => 'AED',
				'symbol'       => 'د.إ',
				'decimals'     => 2,
				'country_code' => 'AE',
				'order'        => 90,
			),
		);
	}

	/**
	 * All markets, keyed by market key, sorted by display order.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$markets = self::definitions();

		foreach ( $markets as $key => &$market ) {
			$market['key'] = $key;
			if ( empty( $market['flag_url'] ) ) {
				$market['flag_url'] = SYCOMP_B2B_URL . 'assets/flags/' . $key . '.svg';
			}
			if ( empty( $market['flag_path'] ) ) {
				$market['flag_path'] = SYCOMP_B2B_DIR . 'assets/flags/' . $key . '.svg';
			}
		}
		unset( $market );

		uasort(
			$markets,
			static function ( $a, $b ) {
				return $a['order'] <=> $b['order'];
			}
		);

		/**
		 * Filter the full market list.
		 *
		 * @param array $markets Markets keyed by market key.
		 */
		return apply_filters( 'sycomp_b2b_markets', $markets );
	}

	/**
	 * Save/Update a market in the custom registry.
	 *
	 * @param string $key  Market key.
	 * @param array  $data Market data.
	 */
	public static function save_market( $key, $data ) {
		$markets = self::definitions();
		$markets[ $key ] = wp_parse_args(
			$data,
			array(
				'label'        => '',
				'currency'     => '',
				'symbol'       => '',
				'decimals'     => 2,
				'country_code' => '',
				'order'        => 100,
				'flag_url'     => '',
			)
		);
		update_option( 'sycomp_b2b_custom_markets', $markets );
	}

	/**
	 * Delete a market from the custom registry.
	 *
	 * @param string $key Market key.
	 */
	public static function delete_market( $key ) {
		$markets = self::definitions();
		if ( isset( $markets[ $key ] ) ) {
			unset( $markets[ $key ] );
			update_option( 'sycomp_b2b_custom_markets', $markets );

			// Clean up warehouse address
			$warehouses = get_option( 'sycomp_b2b_warehouses', array() );
			if ( is_array( $warehouses ) && isset( $warehouses[ $key ] ) ) {
				unset( $warehouses[ $key ] );
				update_option( 'sycomp_b2b_warehouses', $warehouses );
			}

			// Clean up tax configuration
			$taxes = get_option( 'sycomp_b2b_taxes', array() );
			if ( is_array( $taxes ) && isset( $taxes[ $key ] ) ) {
				unset( $taxes[ $key ] );
				update_option( 'sycomp_b2b_taxes', $taxes );
			}

			// Clean up quote formats
			$quote_formats = get_option( 'sycomp_b2b_quote_formats', array() );
			if ( is_array( $quote_formats ) && isset( $quote_formats[ $key ] ) ) {
				unset( $quote_formats[ $key ] );
				update_option( 'sycomp_b2b_quote_formats', $quote_formats );
			}
		}
	}

	/**
	 * Get a single market.
	 *
	 * @param string $key Market key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$markets = self::all();
		return isset( $markets[ $key ] ) ? $markets[ $key ] : null;
	}

	/**
	 * Whether a market key is valid.
	 *
	 * @param string $key Market key.
	 * @return bool
	 */
	public static function exists( $key ) {
		return null !== self::get( $key );
	}

	/**
	 * All market keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * Currency code for a market.
	 *
	 * @param string $key Market key.
	 * @return string
	 */
	public static function currency( $key ) {
		$market = self::get( $key );
		return $market ? $market['currency'] : get_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Market label.
	 *
	 * @param string $key Market key.
	 * @return string
	 */
	public static function label( $key ) {
		$market = self::get( $key );
		return $market ? $market['label'] : ucwords( str_replace( '_', ' ', (string) $key ) );
	}

	/**
	 * Currency symbol for a market.
	 *
	 * @param string $key Market key.
	 * @return string
	 */
	public static function symbol( $key ) {
		$market = self::get( $key );
		return $market ? $market['symbol'] : '';
	}

	/**
	 * Decimal places for a market's currency.
	 *
	 * @param string $key Market key.
	 * @return int
	 */
	public static function decimals( $key ) {
		$market = self::get( $key );
		return $market ? (int) $market['decimals'] : 2;
	}

	/**
	 * The product-meta key used to store a market's price.
	 *
	 * @param string $key Market key.
	 * @return string
	 */
	public static function price_meta_key( $key ) {
		return '_sycomp_price_' . sanitize_key( $key );
	}
}
