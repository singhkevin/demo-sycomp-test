<?php
/**
 * Per-market pricing and currency.
 *
 * Each product stores nine native prices — one per market, in that
 * market's own currency. There is NO currency conversion: the active
 * location's market decides which stored price and which currency the
 * buyer sees. Switching location switches both, instantly.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Pricing.
 */
class Sycomp_B2B_Pricing {

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Price resolution on the product object.
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_price' ), 20, 2 );

		// Purchasability — a product is only buyable in markets it is priced for.
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_is_purchasable' ), 20, 2 );

		// Currency follows the active location's market.
		add_filter( 'woocommerce_currency', array( __CLASS__, 'filter_currency' ), 20 );
		add_filter( 'woocommerce_currency_symbol', array( __CLASS__, 'filter_currency_symbol' ), 20, 2 );
		add_filter( 'wc_get_price_decimals', array( __CLASS__, 'filter_decimals' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Get a product's price for a specific market.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @return string Price as a string, or '' if not set.
	 */
	public static function get_market_price( $product_id, $market_key ) {
		if ( ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return '';
		}
		$value = get_post_meta( (int) $product_id, Sycomp_B2B_Markets::price_meta_key( $market_key ), true );
		return ( '' === $value || null === $value ) ? '' : (string) $value;
	}

	/**
	 * Set a product's price for a specific market.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @param mixed  $price      Price (numeric) or '' to clear.
	 * @return bool
	 */
	public static function set_market_price( $product_id, $market_key, $price ) {
		if ( ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return false;
		}
		$meta_key = Sycomp_B2B_Markets::price_meta_key( $market_key );

		if ( '' === $price || null === $price ) {
			delete_post_meta( (int) $product_id, $meta_key );
			return true;
		}

		$clean = wc_format_decimal( $price );
		update_post_meta( (int) $product_id, $meta_key, $clean );
		return true;
	}

	/**
	 * All nine market prices for a product, keyed by market key.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,string>
	 */
	public static function get_all_market_prices( $product_id ) {
		$prices = array();
		foreach ( Sycomp_B2B_Markets::keys() as $key ) {
			$prices[ $key ] = self::get_market_price( $product_id, $key );
		}
		return $prices;
	}

	/* ---------------------------------------------------------------------
	 * Per-market price currency (a price may be entered in a non-local
	 * currency and is converted to the market currency on read).
	 * ------------------------------------------------------------------ */

	/**
	 * The product-meta key holding a market price's entered currency.
	 *
	 * @param string $market_key Market key.
	 * @return string
	 */
	public static function price_currency_meta_key( $market_key ) {
		return '_sycomp_price_cur_' . sanitize_key( $market_key );
	}

	/**
	 * The currency a product's market price is entered in. Defaults to the
	 * market's own currency.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @return string Currency code.
	 */
	public static function get_price_currency( $product_id, $market_key ) {
		$default = Sycomp_B2B_Markets::exists( $market_key ) ? Sycomp_B2B_Markets::currency( $market_key ) : '';
		$stored  = get_post_meta( (int) $product_id, self::price_currency_meta_key( $market_key ), true );
		return $stored ? (string) $stored : $default;
	}

	/**
	 * Set the currency a product's market price is entered in. Storing the
	 * market's own currency clears the override.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @param string $currency   Currency code.
	 */
	public static function set_price_currency( $product_id, $market_key, $currency ) {
		$meta_key   = self::price_currency_meta_key( $market_key );
		$market_cur = Sycomp_B2B_Markets::exists( $market_key ) ? Sycomp_B2B_Markets::currency( $market_key ) : '';
		$currency   = sanitize_text_field( (string) $currency );
		if ( '' === $currency || $currency === $market_cur ) {
			delete_post_meta( (int) $product_id, $meta_key );
		} else {
			update_post_meta( (int) $product_id, $meta_key, $currency );
		}
	}

	/**
	 * A product's price for a market, expressed in that market's currency —
	 * converting from the entered currency when they differ. This is the
	 * value buyers are shown and charged.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @return string Price as a string, or '' if not priced.
	 */
	public static function get_effective_price( $product_id, $market_key ) {
		$raw = self::get_market_price( $product_id, $market_key );
		if ( '' === $raw || ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return $raw;
		}
		$price_cur  = self::get_price_currency( $product_id, $market_key );
		$market_cur = Sycomp_B2B_Markets::currency( $market_key );
		if ( $price_cur === $market_cur ) {
			$value = $raw;
		} else {
			$value = Sycomp_B2B_FX::convert( $raw, $price_cur, $market_cur );
		}

		// Retrieve dynamic per-product per-market GP margin percentage
		$gp_percent = self::get_product_market_gp( $product_id, $market_key );
		if ( $gp_percent > 0.0 && $gp_percent < 100.0 ) {
			// Apply GP markup dynamically: customer_cost = vendor_cost / (1 - GP%)
			$customer_cost = (float) $value / ( 1.0 - ( $gp_percent / 100.0 ) );
		} else {
			$customer_cost = (float) $value;
		}

		return (string) wc_format_decimal( $customer_cost, Sycomp_B2B_Markets::decimals( $market_key ) );
	}

	/* ---------------------------------------------------------------------
	 * Display filters.
	 * ------------------------------------------------------------------ */

	/**
	 * Replace the product price with the active market's price.
	 *
	 * @param mixed      $price   Original price.
	 * @param WC_Product $product Product object.
	 * @return mixed
	 */
	public static function filter_price( $price, $product ) {
		if ( is_admin()
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $price;
		}

		$market = Sycomp_B2B_Context::get_active_market();
		if ( ! $market || ! $product instanceof WC_Product ) {
			return $price;
		}

		$market_price = self::get_effective_price( $product->get_id(), $market );
		if ( '' === $market_price ) {
			// No price for this market — surface as unpriced.
			return '';
		}
		return $market_price;
	}

	/**
	 * B2B catalogue has no "sale" prices — the market price is the price.
	 *
	 * @param mixed      $price   Original sale price.
	 * @param WC_Product $product Product object.
	 * @return mixed
	 */
	public static function filter_sale_price( $price, $product ) {
		if ( is_admin()
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $price;
		}

		if ( Sycomp_B2B_Context::get_active_market() ) {
			return '';
		}
		return $price;
	}

	/**
	 * A product is purchasable only if it is priced in the active market.
	 *
	 * @param bool       $purchasable Whether purchasable.
	 * @param WC_Product $product     Product object.
	 * @return bool
	 */
	public static function filter_is_purchasable( $purchasable, $product ) {
		if ( is_admin()
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $purchasable;
		}

		$market = Sycomp_B2B_Context::get_active_market();
		if ( ! $market || ! $product instanceof WC_Product ) {
			return $purchasable;
		}
		return '' !== self::get_market_price( $product->get_id(), $market );
	}

	/**
	 * Currency code follows the active market.
	 *
	 * @param string $currency Current currency code.
	 * @return string
	 */
	public static function filter_currency( $currency ) {
		$market = Sycomp_B2B_Context::get_active_market();
		return $market ? Sycomp_B2B_Markets::currency( $market ) : $currency;
	}

	/**
	 * Currency symbol follows the active market.
	 *
	 * @param string $symbol   Current symbol.
	 * @param string $currency Currency code being rendered.
	 * @return string
	 */
	public static function filter_currency_symbol( $symbol, $currency ) {
		$market = Sycomp_B2B_Context::get_active_market();
		if ( ! $market ) {
			return $symbol;
		}
		// Only override the symbol for the active market's currency.
		if ( Sycomp_B2B_Markets::currency( $market ) === $currency ) {
			return Sycomp_B2B_Markets::symbol( $market );
		}
		return $symbol;
	}

	/**
	 * Decimal places follow the active market (e.g. JPY/TWD show 0).
	 *
	 * @param int $decimals Current decimal count.
	 * @return int
	 */
	public static function filter_decimals( $decimals ) {
		$market = Sycomp_B2B_Context::get_active_market();
		return $market ? Sycomp_B2B_Markets::decimals( $market ) : $decimals;
	}

	/* ---------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Format an amount in a specific market's currency, regardless of the
	 * active context. Used for historical PO display.
	 *
	 * @param float|string $amount     Amount.
	 * @param string       $market_key Market key.
	 * @return string HTML price.
	 */
	public static function format_in_market( $amount, $market_key ) {
		if ( ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return wc_price( (float) $amount );
		}
		return wc_price(
			(float) $amount,
			array(
				'currency' => Sycomp_B2B_Markets::currency( $market_key ),
				'decimals' => Sycomp_B2B_Markets::decimals( $market_key ),
			)
		);
	}

	/**
	 * Whether a product is priced in a given market.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @return bool
	 */
	public static function is_priced_in_market( $product_id, $market_key ) {
		return '' !== self::get_market_price( $product_id, $market_key );
	}

	/**
	 * Get the product-specific GP margin percentage for a market.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @return float GP margin percentage (e.g. 15.00 for 15%).
	 */
	public static function get_product_market_gp( $product_id, $market_key ) {
		$value = get_post_meta( (int) $product_id, '_sycomp_gp_' . sanitize_key( $market_key ), true );
		// Default to 15.00% if not configured (empty string or null).
		return ( '' === $value || null === $value ) ? 15.00 : (float) $value;
	}

	/**
	 * Set the product-specific GP margin percentage for a market.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $market_key Market key.
	 * @param mixed  $gp         GP percentage.
	 */
	public static function set_product_market_gp( $product_id, $market_key, $gp ) {
		$meta_key = '_sycomp_gp_' . sanitize_key( $market_key );
		if ( '' === $gp || null === $gp ) {
			delete_post_meta( (int) $product_id, $meta_key );
		} else {
			$clean = max( 0.0, min( 99.0, (float) $gp ) );
			update_post_meta( (int) $product_id, $meta_key, $clean );
		}
	}
}
