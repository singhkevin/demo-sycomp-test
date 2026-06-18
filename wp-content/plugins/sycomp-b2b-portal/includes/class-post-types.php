<?php
/**
 * Custom post types: Companies and Locations.
 *
 * A Company has many Locations. Each Location is tied to one market.
 * A portal user belongs to one Company and may switch between any of
 * that company's Locations.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Post_Types.
 */
class Sycomp_B2B_Post_Types {

	const COMPANY  = 'sycomp_company';
	const LOCATION = 'sycomp_location';

	/**
	 * Brand taxonomy on products (Category uses WooCommerce's product_cat).
	 */
	const TAX_BRAND = 'sycomp_brand';

	/**
	 * Meta keys.
	 */
	const META_LOCATION_COMPANY = '_sycomp_company_id';
	const META_LOCATION_MARKET  = '_sycomp_market';
	const META_LOCATION_CODE    = '_sycomp_location_code';
	const META_LOCATION_ADDRESS = '_sycomp_address';

	/**
	 * Company billing (bill-to) address.
	 */
	const META_COMPANY_BILLING = '_sycomp_billing_address';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register post types and the brand taxonomy.
	 */
	public static function register() {
		self::register_brand_taxonomy();

		// Standard "post" capabilities: administrators (and shop managers)
		// can manage these. The custom caps must NOT reuse a real primitive
		// cap name, or WordPress treats that name as a meta capability.
		$shared = array(
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => 'sycomp-b2b',
			'show_in_rest'    => false,
			'hierarchical'    => false,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => false,
			'query_var'       => false,
		);

		register_post_type(
			self::COMPANY,
			array_merge(
				$shared,
				array(
					'labels' => array(
						'name'          => __( 'Companies', 'sycomp-b2b-portal' ),
						'singular_name' => __( 'Company', 'sycomp-b2b-portal' ),
						'add_new'       => __( 'Add Company', 'sycomp-b2b-portal' ),
						'add_new_item'  => __( 'Add Company', 'sycomp-b2b-portal' ),
						'edit_item'     => __( 'Edit Company', 'sycomp-b2b-portal' ),
						'new_item'      => __( 'New Company', 'sycomp-b2b-portal' ),
						'view_item'     => __( 'View Company', 'sycomp-b2b-portal' ),
						'search_items'  => __( 'Search Companies', 'sycomp-b2b-portal' ),
						'not_found'     => __( 'No companies found', 'sycomp-b2b-portal' ),
						'all_items'     => __( 'Companies', 'sycomp-b2b-portal' ),
						'menu_name'     => __( 'Companies', 'sycomp-b2b-portal' ),
					),
				)
			)
		);

		register_post_type(
			self::LOCATION,
			array_merge(
				$shared,
				array(
					'labels' => array(
						'name'          => __( 'Locations', 'sycomp-b2b-portal' ),
						'singular_name' => __( 'Location', 'sycomp-b2b-portal' ),
						'add_new'       => __( 'Add Location', 'sycomp-b2b-portal' ),
						'add_new_item'  => __( 'Add Location', 'sycomp-b2b-portal' ),
						'edit_item'     => __( 'Edit Location', 'sycomp-b2b-portal' ),
						'new_item'      => __( 'New Location', 'sycomp-b2b-portal' ),
						'view_item'     => __( 'View Location', 'sycomp-b2b-portal' ),
						'search_items'  => __( 'Search Locations', 'sycomp-b2b-portal' ),
						'not_found'     => __( 'No locations found', 'sycomp-b2b-portal' ),
						'all_items'     => __( 'Locations', 'sycomp-b2b-portal' ),
						'menu_name'     => __( 'Locations', 'sycomp-b2b-portal' ),
					),
				)
			)
		);
	}

	/**
	 * Register the product Brand taxonomy.
	 */
	public static function register_brand_taxonomy() {
		register_taxonomy(
			self::TAX_BRAND,
			'product',
			array(
				'labels'            => array(
					'name'          => __( 'Brands', 'sycomp-b2b-portal' ),
					'singular_name' => __( 'Brand', 'sycomp-b2b-portal' ),
					'search_items'  => __( 'Search Brands', 'sycomp-b2b-portal' ),
					'all_items'     => __( 'All Brands', 'sycomp-b2b-portal' ),
					'edit_item'     => __( 'Edit Brand', 'sycomp-b2b-portal' ),
					'add_new_item'  => __( 'Add New Brand', 'sycomp-b2b-portal' ),
					'menu_name'     => __( 'Brands', 'sycomp-b2b-portal' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'hierarchical'      => false,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Relationship helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * All companies.
	 *
	 * @return WP_Post[]
	 */
	public static function get_companies() {
		return get_posts(
			array(
				'post_type'        => self::COMPANY,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Locations belonging to a company.
	 *
	 * @param int $company_id Company post ID.
	 * @return WP_Post[]
	 */
	public static function get_company_locations( $company_id ) {
		$company_id = (int) $company_id;
		if ( ! $company_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'   => self::LOCATION,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
				'meta_key'    => self::META_LOCATION_COMPANY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $company_id,                 // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
	}

	/**
	 * The company a location belongs to.
	 *
	 * @param int $location_id Location post ID.
	 * @return int Company post ID, or 0.
	 */
	public static function get_location_company( $location_id ) {
		return (int) get_post_meta( (int) $location_id, self::META_LOCATION_COMPANY, true );
	}

	/**
	 * The market key a location is tied to.
	 *
	 * @param int $location_id Location post ID.
	 * @return string Market key, or empty string.
	 */
	public static function get_location_market( $location_id ) {
		return (string) get_post_meta( (int) $location_id, self::META_LOCATION_MARKET, true );
	}

	/**
	 * Whether a location belongs to a given company.
	 *
	 * @param int $location_id Location post ID.
	 * @param int $company_id  Company post ID.
	 * @return bool
	 */
	public static function location_belongs_to_company( $location_id, $company_id ) {
		return self::get_location_company( $location_id ) === (int) $company_id;
	}

	/**
	 * Human-readable location address (multiline string).
	 *
	 * @param int $location_id Location post ID.
	 * @return string
	 */
	public static function get_location_address( $location_id ) {
		return (string) get_post_meta( (int) $location_id, self::META_LOCATION_ADDRESS, true );
	}

	/**
	 * A company's billing (bill-to) address.
	 *
	 * @param int $company_id Company post ID.
	 * @return string
	 */
	public static function get_company_billing_address( $company_id ) {
		return (string) get_post_meta( (int) $company_id, self::META_COMPANY_BILLING, true );
	}

	/**
	 * A location's billing (bill-to) address.
	 *
	 * @param int $location_id Location post ID.
	 * @return string
	 */
	public static function get_location_billing_address( $location_id ) {
		return (string) get_post_meta( (int) $location_id, '_sycomp_billing_address', true );
	}

	/**
	 * A location's drop shipping addresses.
	 *
	 * @param int $location_id Location post ID.
	 * @return array
	 */
	public static function get_location_drop_shipping_addresses( $location_id ) {
		$addresses = get_post_meta( (int) $location_id, '_sycomp_drop_shipping_addresses', true );
		return is_array( $addresses ) ? $addresses : array();
	}

	/**
	 * A location's MSP shipping addresses.
	 *
	 * @param int $location_id Location post ID.
	 * @return array
	 */
	public static function get_location_msp_shipping_addresses( $location_id ) {
		$addresses = get_post_meta( (int) $location_id, '_sycomp_msp_shipping_addresses', true );
		return is_array( $addresses ) ? $addresses : array();
	}
}
