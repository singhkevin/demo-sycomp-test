<?php
/**
 * Sycomp warehouses — the supplier / ship-from address per market.
 *
 * Sycomp operates a warehouse in each of the nine markets. A purchase
 * order's market decides which warehouse address is shown as the supplier
 * (ship-from) block on the PO and its PDF. Addresses are managed by a Shop
 * Manager on the Company Details screen and stored in a single option.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Warehouses.
 */
class Sycomp_B2B_Warehouses {

	/**
	 * Option key holding the warehouse map (keyed by market key).
	 */
	const OPTION = 'sycomp_b2b_warehouses';

	/**
	 * All warehouses, keyed by market key, each with sensible defaults.
	 *
	 * @return array<string,array> Each entry: name, address.
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$out = array();
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			$entry = ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) ? $saved[ $key ] : array();
			$out[ $key ] = wp_parse_args(
				$entry,
				array(
					/* translators: %s: market name. */
					'name'    => sprintf( __( 'Sycomp %s', 'sycomp-b2b-portal' ), $market['label'] ),
					'address' => '',
				)
			);
		}
		return $out;
	}

	/**
	 * A single market's warehouse.
	 *
	 * @param string $market Market key.
	 * @return array|null { name, address } or null if the market is unknown.
	 */
	public static function get( $market ) {
		if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
			return null;
		}
		$all = self::all();
		return isset( $all[ $market ] ) ? $all[ $market ] : null;
	}

	/**
	 * Whether a market's warehouse has an address set.
	 *
	 * @param string $market Market key.
	 * @return bool
	 */
	public static function has_address( $market ) {
		$w = self::get( $market );
		return $w && '' !== trim( (string) $w['address'] );
	}

	/**
	 * Save the whole warehouse map.
	 *
	 * @param array $data Raw posted map: market key => { name, address }.
	 */
	public static function save_all( $data ) {
		$clean = array();
		foreach ( Sycomp_B2B_Markets::keys() as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'name'    => isset( $data[ $key ]['name'] ) ? sanitize_text_field( $data[ $key ]['name'] ) : '',
				'address' => isset( $data[ $key ]['address'] ) ? sanitize_textarea_field( $data[ $key ]['address'] ) : '',
			);
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * The supplier block lines for a market: warehouse name then address
	 * lines, ready to print. Empty array if nothing is configured.
	 *
	 * @param string $market Market key.
	 * @return string[]
	 */
	public static function address_lines( $market ) {
		$w = self::get( $market );
		if ( ! $w ) {
			return array();
		}
		$lines = array();
		$name  = trim( (string) $w['name'] );
		$addr  = trim( (string) $w['address'] );

		if ( 'india' === $market && '' === $addr ) {
			$name = 'Sycomp Technologies India Pvt. Ltd.';
			$addr = "Unit 7, Level 5, Discoverer Link Bridge\nBuilding ITPL\nWhitefield Road, Bangalore,\nBengaluru Urban, Karnataka – 560066";
		}

		if ( '' !== $name ) {
			$lines[] = $name;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $addr ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}
}
