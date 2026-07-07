<?php
/**
 * CSV importer for products and per-market price lists.
 *
 * Products: accepts a standard Shopify product export CSV (the
 * "all in one" upload file). Rows are grouped by Handle; the importer is
 * idempotent — re-running updates products matched by SKU or Handle
 * rather than creating duplicates.
 *
 * Prices: accepts one CSV per market. The importer auto-detects the key
 * column (SKU or Handle) and the price column from the header row, so it
 * adapts to the exact shape of Shopify's price-list exports.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Importer.
 */
class Sycomp_B2B_Importer {

	/**
	 * Product meta key holding the Shopify Handle (used for price matching).
	 */
	const META_HANDLE = '_sycomp_handle';

	/**
	 * Register hooks. No runtime hooks needed; kept for a consistent API.
	 */
	public static function init() {}

	/* ---------------------------------------------------------------------
	 * CSV reading.
	 * ------------------------------------------------------------------ */

	/**
	 * Read a CSV file into a header array and an array of associative rows.
	 *
	 * @param string $path Absolute file path.
	 * @return array|WP_Error { header: string[], rows: array[] }
	 */
	public static function read_csv( $path ) {
		if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'sycomp_csv_missing', __( 'CSV file not found or not readable.', 'sycomp-b2b-portal' ) );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $handle ) {
			return new WP_Error( 'sycomp_csv_open', __( 'Could not open the CSV file.', 'sycomp-b2b-portal' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'sycomp_csv_empty', __( 'The CSV file is empty.', 'sycomp-b2b-portal' ) );
		}

		// Strip a UTF-8 BOM from the first header cell.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map( 'trim', $header );

		$rows  = array();
		$count = count( $header );
		while ( false !== ( $line = fgetcsv( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( array( null ) === $line ) {
				continue; // Blank line.
			}
			$line = array_pad( array_slice( $line, 0, $count ), $count, '' );
			$rows[] = array_combine( $header, $line );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'header' => $header,
			'rows'   => $rows,
		);
	}

	/**
	 * Find a value in a row by trying several candidate column names
	 * (case-insensitive).
	 *
	 * @param array    $row        Associative row.
	 * @param string[] $candidates Candidate header names.
	 * @return string
	 */
	protected static function field( $row, $candidates ) {
		$lower = array();
		foreach ( $row as $key => $value ) {
			$lower[ strtolower( trim( (string) $key ) ) ] = $value;
		}
		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( $candidate );
			if ( isset( $lower[ $candidate ] ) && '' !== trim( (string) $lower[ $candidate ] ) ) {
				return (string) $lower[ $candidate ];
			}
		}
		return '';
	}

	/**
	 * Detect the header name actually present for a set of candidates.
	 *
	 * @param string[] $header     CSV header.
	 * @param string[] $candidates Candidate names.
	 * @return string|null
	 */
	protected static function detect_column( $header, $candidates ) {
		$lower = array_map( 'strtolower', array_map( 'trim', $header ) );
		foreach ( $candidates as $candidate ) {
			$idx = array_search( strtolower( $candidate ), $lower, true );
			if ( false !== $idx ) {
				return $header[ $idx ];
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Product import.
	 * ------------------------------------------------------------------ */

	/**
	 * Import products from a Shopify-style product CSV.
	 *
	 * @param string $path Absolute CSV path.
	 * @param array  $args { import_images: bool, company_ids: int[] }.
	 * @return array|WP_Error Result summary.
	 */
	public static function import_products( $path, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'import_images' => false,
				'company_ids'   => array(),
			)
		);

		$csv = self::read_csv( $path );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		// Group rows by Handle so multi-image/variant rows collapse to one product.
		$groups = array();
		$order  = array();
		foreach ( $csv['rows'] as $row ) {
			$handle = self::field( $row, array( 'Handle' ) );
			if ( '' === $handle ) {
				$handle = sanitize_title( self::field( $row, array( 'Title', 'Name' ) ) );
			}
			if ( '' === $handle ) {
				continue;
			}
			if ( ! isset( $groups[ $handle ] ) ) {
				$groups[ $handle ] = array();
				$order[]           = $handle;
			}
			$groups[ $handle ][] = $row;
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $order as $handle ) {
			$rows = $groups[ $handle ];
			$main = null;
			foreach ( $rows as $row ) {
				if ( '' !== self::field( $row, array( 'Title', 'Name' ) ) ) {
					$main = $row;
					break;
				}
			}
			if ( ! $main ) {
				$skipped++;
				continue;
			}

			$result = self::upsert_product( $handle, $main, $rows, $args['import_images'], $args['company_ids'] );
			if ( is_wp_error( $result ) ) {
				$errors[] = $handle . ': ' . $result->get_error_message();
				continue;
			}
			if ( 'created' === $result ) {
				$created++;
			} else {
				$updated++;
			}
		}

		return array(
			'type'    => 'products',
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
			'total'   => count( $order ),
		);
	}

	/**
	 * Create or update a single product from a Handle group.
	 *
	 * @param string $handle        Shopify handle.
	 * @param array  $main          The main data row.
	 * @param array  $rows          All rows in the handle group.
	 * @param bool   $import_images Whether to sideload images.
	 * @param int[]  $company_ids   Company IDs to assign product visibility.
	 * @return string|WP_Error 'created' | 'updated' | WP_Error.
	 */
	protected static function upsert_product( $handle, $main, $rows, $import_images, $company_ids = array() ) {
		$title = self::field( $main, array( 'Title', 'Product Name', 'Name' ) );
		$sku   = self::field( $main, array( 'Variant SKU', 'SKU' ) );
		$body  = self::field( $main, array( 'Body (HTML)', 'Body', 'Description' ) );
		$short = self::field( $main, array( 'Short Desc', 'Short Description', 'Short description', 'Summary' ) );
		$brand = self::field( $main, array( 'Brand', 'Vendor' ) );
		$cat   = self::field( $main, array( 'Category', 'Type', 'Product Type', 'Product Category' ) );
		$price = self::field( $main, array( 'Variant Price', 'Price', 'Price (USD)' ) );
		$usd   = self::field( $main, array( 'Price (USD)', 'Price USD', 'USD Price' ) );

		// Locate an existing product by SKU, then by Handle.
		$product_id = 0;
		if ( $sku ) {
			$product_id = (int) wc_get_product_id_by_sku( $sku );
		}
		if ( ! $product_id ) {
			$by_handle = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => self::META_HANDLE,   // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'  => $handle,             // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $by_handle ) {
				$product_id = (int) $by_handle[0];
			}
		}

		$is_new  = ! $product_id;
		$product = $product_id ? wc_get_product( $product_id ) : new WC_Product_Simple();
		if ( ! $product ) {
			$product = new WC_Product_Simple();
			$is_new  = true;
		}

		$product->set_name( $title );
		$product->set_status( 'publish' );
		if ( $body ) {
			$product->set_description( wpautop( wp_kses_post( $body ) ) );
		}
		$product->set_short_description(
			$short ? wpautop( wp_kses_post( $short ) ) : wpautop( wp_trim_words( wp_strip_all_tags( $body ), 30 ) )
		);
		if ( $sku ) {
			$product->set_sku( $sku );
		}
		if ( '' !== $price && is_numeric( $price ) ) {
			$product->set_regular_price( wc_format_decimal( $price ) );
		}

		// Always in stock — per the brief, nothing is ever out of stock.
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_catalog_visibility( 'visible' );

		$product->save();
		$product_id = $product->get_id();

		update_post_meta( $product_id, self::META_HANDLE, $handle );

		if ( ! empty( $company_ids ) ) {
			Sycomp_B2B_Post_Types::set_product_companies( $product_id, $company_ids );
		}

		if ( $brand ) {
			self::assign_term( $product_id, Sycomp_B2B_Post_Types::TAX_BRAND, $brand );
		}
		if ( $cat ) {
			self::assign_term( $product_id, 'product_cat', $cat );
		}

		// Seed the US market price from the product CSV's USD column.
		if ( '' !== $usd && is_numeric( $usd ) ) {
			Sycomp_B2B_Pricing::set_market_price( $product_id, 'united_states', $usd );
		}

		// Also process any dynamic per-market price columns (e.g. "Price: germany").
		foreach ( Sycomp_B2B_Markets::all() as $market_key => $market_data ) {
			$market_price = self::field( $main, array( 'Price: ' . $market_key, 'Price ' . $market_key, $market_key . ' Price' ) );
			if ( '' !== $market_price && is_numeric( $market_price ) ) {
				Sycomp_B2B_Pricing::set_market_price( $product_id, $market_key, $market_price );
			}
		}

		if ( $import_images ) {
			self::import_images( $product_id, $rows );
		}

		return $is_new ? 'created' : 'updated';
	}

	/**
	 * Assign (creating if needed) a single taxonomy term to a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy.
	 * @param string $term_name  Term name.
	 */
	protected static function assign_term( $product_id, $taxonomy, $term_name ) {
		$term_name = trim( $term_name );
		if ( '' === $term_name ) {
			return;
		}
		$term = term_exists( $term_name, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term( $term_name, $taxonomy );
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $product_id, (int) $term['term_id'], $taxonomy, false );
		}
	}

	/**
	 * Sideload product images from the Image Src column.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $rows       Handle group rows.
	 */
	protected static function import_images( $product_id, $rows ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Collect image URLs, ordered by Image Position when present.
		$images = array();
		foreach ( $rows as $row ) {
			$url = self::field( $row, array( 'Image Src', 'Image', 'Image URL' ) );
			if ( ! $url ) {
				continue;
			}
			$pos            = (int) self::field( $row, array( 'Image Position' ) );
			$images[ $pos ? $pos : count( $images ) + 1 ] = esc_url_raw( $url );
		}
		ksort( $images );
		$images = array_values( array_unique( $images ) );
		if ( empty( $images ) ) {
			return;
		}

		$gallery = array();
		foreach ( $images as $i => $url ) {
			$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				continue;
			}
			if ( 0 === $i ) {
				set_post_thumbnail( $product_id, $attachment_id );
			} else {
				$gallery[] = $attachment_id;
			}
		}
		if ( $gallery ) {
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Price import.
	 * ------------------------------------------------------------------ */

	/**
	 * Import a per-market price list.
	 *
	 * @param string $path       Absolute CSV path.
	 * @param string $market_key Market key.
	 * @return array|WP_Error Result summary.
	 */
	public static function import_prices( $path, $market_key ) {
		if ( ! Sycomp_B2B_Markets::exists( $market_key ) ) {
			return new WP_Error( 'sycomp_bad_market', __( 'Unknown market.', 'sycomp-b2b-portal' ) );
		}

		$csv = self::read_csv( $path );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		// Detect key + price columns.
		$sku_col    = self::detect_column( $csv['header'], array( 'Variant SKU', 'SKU' ) );
		$handle_col = self::detect_column( $csv['header'], array( 'Handle', 'Product Handle' ) );
		$price_col  = self::detect_column( $csv['header'], array( 'Price', 'Variant Price', 'List Price', 'Catalog Price' ) );

		if ( ! $price_col ) {
			return new WP_Error( 'sycomp_no_price_col', __( 'Could not find a price column in the CSV header.', 'sycomp-b2b-portal' ) );
		}
		if ( ! $sku_col && ! $handle_col ) {
			return new WP_Error( 'sycomp_no_key_col', __( 'Could not find a SKU or Handle column in the CSV header.', 'sycomp-b2b-portal' ) );
		}

		$matched   = 0;
		$unmatched = 0;
		$cleared   = 0;
		$misses    = array();

		foreach ( $csv['rows'] as $row ) {
			$sku    = $sku_col ? trim( (string) ( $row[ $sku_col ] ?? '' ) ) : '';
			$handle = $handle_col ? trim( (string) ( $row[ $handle_col ] ?? '' ) ) : '';
			$price  = trim( (string) ( $row[ $price_col ] ?? '' ) );

			$product_id = 0;
			if ( $sku ) {
				$product_id = (int) wc_get_product_id_by_sku( $sku );
			}
			if ( ! $product_id && $handle ) {
				$found = get_posts(
					array(
						'post_type'   => 'product',
						'post_status' => 'any',
						'numberposts' => 1,
						'fields'      => 'ids',
						'meta_key'    => self::META_HANDLE,  // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'  => $handle,            // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				if ( $found ) {
					$product_id = (int) $found[0];
				}
			}

			if ( ! $product_id ) {
				$unmatched++;
				if ( count( $misses ) < 20 ) {
					$misses[] = $sku ? $sku : $handle;
				}
				continue;
			}

			// Strip currency symbols / thousands separators.
			$clean = preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '', $price ) );
			if ( '' === $clean || ! is_numeric( $clean ) ) {
				Sycomp_B2B_Pricing::set_market_price( $product_id, $market_key, '' );
				$cleared++;
				continue;
			}

			Sycomp_B2B_Pricing::set_market_price( $product_id, $market_key, $clean );
			$matched++;
		}

		return array(
			'type'      => 'prices',
			'market'    => $market_key,
			'matched'   => $matched,
			'cleared'   => $cleared,
			'unmatched' => $unmatched,
			'misses'    => $misses,
			'total'     => count( $csv['rows'] ),
		);
	}
}
