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
	 * The full rate map: currency code => units per 1 USD. USD is forced
	 * to 1; any unset currency is 0 (meaning "not configured").
	 *
	 * @return array<string,float>
	 */
	public static function rates() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = array();
		foreach ( self::currencies() as $cur ) {
			$out[ $cur ] = isset( $saved[ $cur ] ) ? (float) $saved[ $cur ] : 0.0;
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
