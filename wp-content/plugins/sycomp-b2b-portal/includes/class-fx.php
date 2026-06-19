<?php
/**
 * Currency exchange rates.
 *
 * A product's price in a given market may be entered in a currency other
 * than that market's local currency. To keep WooCommerce single-currency,
 * such a price is converted to the market's currency at display and order
 * time using a manager-maintained exchange-rate table.
 *
 * Rates are stored as "units of the currency per 1 USD" (USD is the base).
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_FX.
 */
class Sycomp_B2B_FX {

	/**
	 * Option key holding the rate map (keyed by currency code).
	 */
	const OPTION = 'sycomp_b2b_fx';

	/**
	 * Base currency — its rate is always 1.
	 */
	const BASE = 'USD';

	/**
	 * The distinct currencies used across the nine markets.
	 *
	 * @return string[]
	 */
	public static function currencies() {
		$out = array();
		foreach ( Sycomp_B2B_Markets::all() as $market ) {
			$out[ $market['currency'] ] = $market['currency'];
		}
		return array_values( $out );
	}

	/**
	 * Default fallback exchange rates (units of currency per 1 USD)
	 * used if the manager hasn't configured a rate and the live fetch fails.
	 *
	 * @return array<string,float>
	 */
	public static function default_rates() {
		return array(
			'INR' => 83.0,
			'USD' => 1.0,
			'AUD' => 1.5,
			'JPY' => 150.0,
			'CNY' => 7.2,
			'PHP' => 58.0,
			'TWD' => 32.0,
			'ZAR' => 18.0,
			'AED' => 3.67,
			'SAR' => 3.75,
		);
	}

	/**
	 * Fetch live exchange rates from the public API, cached for 12 hours.
	 *
	 * @return array<string,float>
	 */
	public static function get_live_rates() {
		$cached = get_transient( 'sycomp_b2b_live_rates' );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( 'https://open.er-api.com/v6/latest/USD', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['rates'] ) ) {
			return array();
		}

		$rates = array();
		foreach ( self::currencies() as $cur ) {
			if ( isset( $data['rates'][ $cur ] ) ) {
				$rates[ $cur ] = (float) $data['rates'][ $cur ];
			}
		}

		set_transient( 'sycomp_b2b_live_rates', $rates, 12 * HOUR_IN_SECONDS );
		return $rates;
	}

	/**
	 * The full rate map: currency code => units per 1 USD. USD is forced
	 * to 1; any unset currency falls back to live rates or default rates.
	 *
	 * @return array<string,float>
	 */
	public static function rates() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$live     = self::get_live_rates();
		$defaults = self::default_rates();
		$out      = array();
		foreach ( self::currencies() as $cur ) {
			if ( isset( $saved[ $cur ] ) && (float) $saved[ $cur ] > 0 ) {
				$out[ $cur ] = (float) $saved[ $cur ];
			} elseif ( isset( $live[ $cur ] ) && (float) $live[ $cur ] > 0 ) {
				$out[ $cur ] = (float) $live[ $cur ];
			} else {
				$out[ $cur ] = isset( $defaults[ $cur ] ) ? $defaults[ $cur ] : 0.0;
			}
		}
		$out[ self::BASE ] = 1.0;
		return $out;
	}

	/**
	 * The rate for one currency (units per 1 USD).
	 *
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function rate( $currency ) {
		$rates = self::rates();
		$currency = strtoupper( trim( $currency ) );
		return isset( $rates[ $currency ] ) ? (float) $rates[ $currency ] : 0.0;
	}

	/**
	 * Save the rate map (USD is ignored — always the base).
	 *
	 * @param array $data Raw posted map: currency code => rate.
	 */
	public static function save_rates( $data ) {
		$clean = array();
		foreach ( self::currencies() as $cur ) {
			if ( self::BASE === $cur ) {
				continue;
			}
			$rate = isset( $data[ $cur ] ) ? (float) $data[ $cur ] : 0.0;
			$clean[ $cur ] = $rate > 0 ? $rate : 0.0;
		}
		update_option( self::OPTION, $clean );
		delete_transient( 'sycomp_b2b_live_rates' );
	}

	/**
	 * Convert an amount between two currencies.
	 *
	 * If the currencies match, or either rate is not configured, the amount
	 * is returned unchanged — so an un-configured rate never corrupts a
	 * price, it just leaves it un-converted until the manager sets rates.
	 *
	 * @param float|string $amount Amount.
	 * @param string       $from   Source currency code.
	 * @param string       $to     Target currency code.
	 * @return float
	 */
	public static function convert( $amount, $from, $to ) {
		$amount = (float) $amount;
		$from   = strtoupper( trim( $from ) );
		$to     = strtoupper( trim( $to ) );
		if ( $from === $to || '' === $from || '' === $to ) {
			return $amount;
		}
		$rate_from = self::rate( $from );
		$rate_to   = self::rate( $to );
		if ( $rate_from <= 0 || $rate_to <= 0 ) {
			return $amount;
		}
		return $amount / $rate_from * $rate_to;
	}

	/**
	 * Whether at least one non-base rate has been configured.
	 *
	 * @return bool
	 */
	public static function has_rates() {
		foreach ( self::rates() as $cur => $rate ) {
			if ( self::BASE !== $cur && $rate > 0 ) {
				return true;
			}
		}
		return false;
	}
}
