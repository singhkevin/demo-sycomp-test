<?php
/**
 * Activation / deactivation routines.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Install.
 */
class Sycomp_B2B_Install {

	const OPTION_PAGES   = 'sycomp_b2b_pages';
	const OPTION_VERSION = 'sycomp_b2b_version';
	const BUYER_ROLE     = 'sycomp_buyer';

	/**
	 * Default WordPress / WooCommerce roles the portal does not use.
	 */
	const REMOVE_ROLES   = array( 'editor', 'author', 'contributor', 'subscriber', 'customer' );

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		Sycomp_B2B_Post_Types::register();
		self::add_buyer_role();
		self::remove_default_roles();
		self::create_pages();
		self::ensure_classic_checkout();

		update_option( self::OPTION_VERSION, SYCOMP_B2B_VERSION );

		flush_rewrite_rules();
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Register the dedicated buyer role.
	 */
	public static function add_buyer_role() {
		if ( ! get_role( self::BUYER_ROLE ) ) {
			add_role(
				self::BUYER_ROLE,
				__( 'Sycomp Buyer', 'sycomp-b2b-portal' ),
				array(
					'read' => true,
				)
			);
		}
	}

	/**
	 * Remove the default WordPress / WooCommerce roles the portal does not
	 * use. Only Administrator, Shop manager and Sycomp Buyer are kept.
	 */
	public static function remove_default_roles() {
		foreach ( self::REMOVE_ROLES as $role ) {
			if ( get_role( $role ) ) {
				remove_role( $role );
			}
		}
	}

	/**
	 * Restrict the roles offered on the user screens to the three the
	 * portal uses.
	 *
	 * @param array $roles Editable roles, keyed by role slug.
	 * @return array
	 */
	public static function restrict_editable_roles( $roles ) {
		$allowed = array( 'administrator', 'shop_manager', self::BUYER_ROLE );
		foreach ( array_keys( (array) $roles ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				unset( $roles[ $key ] );
			}
		}
		return $roles;
	}

	/**
	 * Create the Catalogue and My Account pages if they do not yet exist.
	 */
	public static function create_pages() {
		$pages = get_option( self::OPTION_PAGES, array() );
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}

		$defaults = array(
			'catalogue' => array(
				'title'   => __( 'Catalogue', 'sycomp-b2b-portal' ),
				'content' => '<!-- wp:shortcode -->[sycomp_catalogue]<!-- /wp:shortcode -->',
			),
			'orders'    => array(
				'title'   => __( 'Orders', 'sycomp-b2b-portal' ),
				'content' => '<!-- wp:shortcode -->[sycomp_orders]<!-- /wp:shortcode -->',
			),
			'account'   => array(
				'title'   => __( 'Account', 'sycomp-b2b-portal' ),
				'content' => '<!-- wp:shortcode -->[sycomp_account]<!-- /wp:shortcode -->',
			),
			'manage'    => array(
				'title'   => __( 'Manage', 'sycomp-b2b-portal' ),
				'content' => '<!-- wp:shortcode -->[sycomp_manager]<!-- /wp:shortcode -->',
			),
		);

		foreach ( $defaults as $key => $data ) {
			$existing = isset( $pages[ $key ] ) ? (int) $pages[ $key ] : 0;
			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $data['title'],
					'post_content' => $data['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_name'    => sanitize_title( $key ),
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$pages[ $key ] = (int) $page_id;
			}
		}

		update_option( self::OPTION_PAGES, $pages );
	}

	/**
	 * Ensure the WooCommerce Cart and Checkout pages use the classic
	 * shortcodes.
	 *
	 * The Sycomp purchase-order workflow (the "Submit Purchase Order"
	 * button, the PO reference field, location stamping on the order) is
	 * built on the classic checkout hooks, which the block-based Cart and
	 * Checkout do not fire. Modern WooCommerce ships those pages as blocks,
	 * so this converts them back to the classic shortcodes.
	 */
	public static function ensure_classic_checkout() {
		$map = array(
			'woocommerce_cart_page_id'     => '[woocommerce_cart]',
			'woocommerce_checkout_page_id' => '[woocommerce_checkout]',
		);

		foreach ( $map as $option => $shortcode ) {
			$page_id = (int) get_option( $option );
			if ( ! $page_id ) {
				continue;
			}
			$page = get_post( $page_id );
			if ( ! $page || 'page' !== $page->post_type ) {
				continue;
			}
			if ( false === strpos( (string) $page->post_content, $shortcode ) ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => $shortcode,
					)
				);
			}
		}
	}

	/**
	 * Get a portal page ID by key.
	 *
	 * @param string $key 'catalogue' or 'account'.
	 * @return int
	 */
	public static function get_page_id( $key ) {
		$pages = get_option( self::OPTION_PAGES, array() );
		return ( is_array( $pages ) && ! empty( $pages[ $key ] ) ) ? (int) $pages[ $key ] : 0;
	}
}
