<?php
/**
 * WP-CLI commands for the Sycomp B2B Portal.
 *
 * Usage:
 *   wp sycomp import-products <file> [--images]
 *   wp sycomp import-prices <file> --market=<key>
 *   wp sycomp import-all <folder> [--images]
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Class Sycomp_B2B_CLI.
 */
class Sycomp_B2B_CLI {

	/**
	 * Filename keyword → market key map for import-all.
	 *
	 * @var array<string,string>
	 */
	protected static $market_hints = array(
		'india'        => 'india',
		'united'       => 'united_states',
		'usa'          => 'united_states',
		'us'           => 'united_states',
		'australia'    => 'australia',
		'_au'          => 'australia',
		'japan'        => 'japan',
		'_jp'          => 'japan',
		'china'        => 'china',
		'_cn'          => 'china',
		'philippines'  => 'philippines',
		'_ph'          => 'philippines',
		'taiwan'       => 'taiwan',
		'_tw'          => 'taiwan',
		'south'        => 'south_africa',
		'africa'       => 'south_africa',
		'_za'          => 'south_africa',
		'uae'          => 'uae',
		'emirates'     => 'uae',
		'_ae'          => 'uae',
	);

	/**
	 * Import products from a Shopify product CSV.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the product CSV.
	 *
	 * [--images]
	 * : Also download product images.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function import_products( $args, $assoc_args ) {
		$file = isset( $args[0] ) ? $args[0] : '';
		$result = Sycomp_B2B_Importer::import_products(
			$file,
			array( 'import_images' => isset( $assoc_args['images'] ) )
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'Products: %d created, %d updated, %d skipped (of %d).',
				$result['created'],
				$result['updated'],
				$result['skipped'],
				$result['total']
			)
		);
		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
	}

	/**
	 * Import a per-market price list.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the price CSV.
	 *
	 * --market=<market>
	 * : Market key (india, united_states, australia, japan, china,
	 *   philippines, taiwan, south_africa, uae).
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function import_prices( $args, $assoc_args ) {
		$file   = isset( $args[0] ) ? $args[0] : '';
		$market = isset( $assoc_args['market'] ) ? sanitize_key( $assoc_args['market'] ) : '';

		$result = Sycomp_B2B_Importer::import_prices( $file, $market );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%s prices: %d priced, %d cleared, %d unmatched (of %d).',
				Sycomp_B2B_Markets::label( $result['market'] ),
				$result['matched'],
				$result['cleared'],
				$result['unmatched'],
				$result['total']
			)
		);
		if ( ! empty( $result['misses'] ) ) {
			WP_CLI::warning( 'Unmatched: ' . implode( ', ', $result['misses'] ) );
		}
	}

	/**
	 * Import everything from a data folder: the product CSV plus every
	 * price list it can identify by filename.
	 *
	 * ## OPTIONS
	 *
	 * <folder>
	 * : Path to the data folder.
	 *
	 * [--images]
	 * : Also download product images.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function import_all( $args, $assoc_args ) {
		$folder = isset( $args[0] ) ? rtrim( $args[0], '/\\' ) : '';
		if ( ! $folder || ! is_dir( $folder ) ) {
			WP_CLI::error( 'Folder not found: ' . $folder );
		}

		// 1. Products — first CSV whose name hints at the product upload.
		$product_csv = '';
		foreach ( (array) glob( $folder . '/*.csv' ) as $csv ) {
			$base = strtolower( basename( $csv ) );
			if ( false !== strpos( $base, 'all-in-one' ) || false !== strpos( $base, 'product' ) || false !== strpos( $base, 'upload' ) ) {
				$product_csv = $csv;
				break;
			}
		}
		if ( $product_csv ) {
			WP_CLI::log( 'Importing products from ' . basename( $product_csv ) . ' …' );
			$this->import_products( array( $product_csv ), $assoc_args );
		} else {
			WP_CLI::warning( 'No product CSV found in the folder root.' );
		}

		// 2. Prices — scan the folder and a "pricelists" subfolder.
		$price_dirs = array( $folder );
		foreach ( (array) glob( $folder . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( false !== stripos( basename( $dir ), 'price' ) ) {
				$price_dirs[] = $dir;
			}
		}

		$done = array();
		foreach ( $price_dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*.csv' ) as $csv ) {
				if ( $product_csv && realpath( $csv ) === realpath( $product_csv ) ) {
					continue;
				}
				$market = self::guess_market( basename( $csv ) );
				if ( ! $market || isset( $done[ $market ] ) ) {
					continue;
				}
				WP_CLI::log( 'Importing ' . Sycomp_B2B_Markets::label( $market ) . ' prices from ' . basename( $csv ) . ' …' );
				$this->import_prices( array( $csv ), array( 'market' => $market ) );
				$done[ $market ] = true;
			}
		}

		WP_CLI::success( sprintf( 'Import complete. Price lists loaded for %d of %d markets.', count( $done ), count( Sycomp_B2B_Markets::keys() ) ) );
	}

	/**
	 * Guess a market key from a price-list filename.
	 *
	 * @param string $filename Filename.
	 * @return string Market key, or '' if not recognised.
	 */
	protected static function guess_market( $filename ) {
		$name = strtolower( $filename );
		foreach ( self::$market_hints as $hint => $market ) {
			if ( false !== strpos( $name, $hint ) ) {
				return $market;
			}
		}
		return '';
	}
}

WP_CLI::add_command( 'sycomp', 'Sycomp_B2B_CLI' );
