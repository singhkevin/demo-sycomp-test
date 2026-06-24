<?php
/**
 * Per-market taxation.
 *
 * Each of the nine markets has its own tax label and rate, managed by a
 * Shop Manager on the Company Details screen. Prices are tax-exclusive;
 * the tax is added to a proposal as an estimated fee line in the
 * buyer's market. A PO remains a proposal, not a tax invoice — the
 * tax shown is an estimate.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Tax.
 */
class Sycomp_B2B_Tax {

	/**
	 * Option key holding the tax map (keyed by market key).
	 */
	const OPTION = 'sycomp_b2b_taxes';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_cart_tax' ) );
	}

	/* ---------------------------------------------------------------------
	 * Storage.
	 * ------------------------------------------------------------------ */

	/**
	 * All market tax settings, keyed by market key, with defaults.
	 *
	 * @return array<string,array> Each entry: label, rate (float).
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = array();
		foreach ( Sycomp_B2B_Markets::keys() as $key ) {
			$entry = ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) ? $saved[ $key ] : array();
			$entry = wp_parse_args(
				$entry,
				array(
					'label' => __( 'Tax', 'sycomp-b2b-portal' ),
					'rate'  => 10.0,
				)
			);
			$entry['rate']  = (float) $entry['rate'];
			$out[ $key ]    = $entry;
		}
		return $out;
	}

	/**
	 * A single market's tax settings.
	 *
	 * @param string $market Market key.
	 * @return array|null { label, rate } or null if the market is unknown.
	 */
	public static function get( $market ) {
		if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
			return null;
		}
		$all = self::all();
		return isset( $all[ $market ] ) ? $all[ $market ] : null;
	}

	/**
	 * The tax rate (percentage) for a market.
	 *
	 * @param string $market Market key.
	 * @return float
	 */
	public static function rate( $market ) {
		$t = self::get( $market );
		return $t ? (float) $t['rate'] : 0.0;
	}

	/**
	 * The tax label for a market (e.g. "GST", "VAT").
	 *
	 * @param string $market Market key.
	 * @return string
	 */
	public static function label( $market ) {
		$t     = self::get( $market );
		$label = $t ? trim( (string) $t['label'] ) : '';
		return '' !== $label ? $label : __( 'Tax', 'sycomp-b2b-portal' );
	}

	/**
	 * Save the whole tax map.
	 *
	 * @param array $data Raw posted map: market key => { label, rate }.
	 */
	public static function save_all( $data ) {
		$clean = array();
		foreach ( Sycomp_B2B_Markets::keys() as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}
			$rate = isset( $data[ $key ]['rate'] ) ? (float) $data[ $key ]['rate'] : 0.0;
			if ( $rate < 0 ) {
				$rate = 0.0;
			}
			$clean[ $key ] = array(
				'label' => isset( $data[ $key ]['label'] ) ? sanitize_text_field( $data[ $key ]['label'] ) : '',
				'rate'  => $rate,
			);
		}
		update_option( self::OPTION, $clean );
	}

	/* ---------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Format a rate for display, trimming trailing zeros (18.00 -> "18").
	 *
	 * @param float $rate Rate.
	 * @return string
	 */
	public static function format_rate( $rate ) {
		$s = number_format( (float) $rate, 2, '.', '' );
		$s = rtrim( rtrim( $s, '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * A market's tax label with its rate, e.g. "GST (18%)".
	 *
	 * @param string $market Market key.
	 * @return string
	 */
	public static function display_label( $market ) {
		$rate = self::rate( $market );
		if ( $rate <= 0 ) {
			return self::label( $market );
		}
		/* translators: 1: tax label, 2: rate. */
		return sprintf( __( '%1$s (%2$s%%)', 'sycomp-b2b-portal' ), self::label( $market ), self::format_rate( $rate ) );
	}

	/**
	 * The tax amount on a net value for a market, rounded to the market's
	 * currency decimals.
	 *
	 * @param float|string $net    Net amount.
	 * @param string       $market Market key.
	 * @return float
	 */
	public static function calc( $net, $market ) {
		$rate = self::rate( $market );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		$decimals = Sycomp_B2B_Markets::decimals( $market );
		return round( (float) $net * $rate / 100, $decimals );
	}

	/* ---------------------------------------------------------------------
	 * Cart integration.
	 * ------------------------------------------------------------------ */

	/**
	 * Add the estimated tax for the buyer's active market as a cart fee.
	 *
	 * Running on woocommerce_cart_calculate_fees means the tax flows into
	 * the cart total, the checkout, the submitted proposal and its
	 * emails automatically — no WooCommerce tax tables involved.
	 *
	 * @param WC_Cart $cart Cart being calculated.
	 */
	public static function add_cart_tax( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}
		$market = Sycomp_B2B_Context::get_active_market();
		if ( ! $market ) {
			return;
		}
		$rate = self::rate( $market );
		if ( $rate <= 0 ) {
			return;
		}

		$base = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['line_total'] ) ) {
				$base += (float) $item['line_total'];
			} elseif ( isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
				$base += (float) $item['data']->get_price() * ( isset( $item['quantity'] ) ? (int) $item['quantity'] : 1 );
			}
		}
		if ( $base <= 0 ) {
			return;
		}

		$tax = self::calc( $base, $market );
		if ( $tax <= 0 ) {
			return;
		}
		$cart->add_fee( self::display_label( $market ), $tax, false );
	}
}
