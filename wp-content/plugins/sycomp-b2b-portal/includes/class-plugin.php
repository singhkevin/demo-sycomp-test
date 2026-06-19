<?php
/**
 * Main plugin loader and shared global helper functions.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Plugin.
 */
class Sycomp_B2B_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Sycomp_B2B_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Sycomp_B2B_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up every module.
	 */
	protected function __construct() {
		// Core / front-end.
		Sycomp_B2B_Post_Types::init();
		Sycomp_B2B_Pricing::init();
		Sycomp_B2B_Tax::init();
		Sycomp_B2B_Cart::init();
		Sycomp_B2B_PO::init();
		Sycomp_B2B_PO_PDF::init();
		Sycomp_B2B_Storefront::init();
		Sycomp_B2B_Catalogue::init();
		Sycomp_B2B_Account::init();
		Sycomp_B2B_User::init();
		Sycomp_B2B_Manager::init();
		Sycomp_B2B_Manager_Customers::init();
		Sycomp_B2B_Frontend::init();
		Sycomp_B2B_Importer::init();

		// Admin.
		if ( is_admin() ) {
			Sycomp_B2B_Admin::init();
			Sycomp_B2B_Admin_Company::init();
			Sycomp_B2B_Admin_Pricing::init();
			Sycomp_B2B_Admin_PO::init();
			Sycomp_B2B_Admin_Import::init();
		}

		// Self-heal: make sure the portal pages exist.
		add_action( 'admin_init', array( $this, 'maybe_create_pages' ) );
		// Keep the buyer role available even if activation was skipped.
		add_action( 'admin_init', array( $this, 'maybe_add_role' ) );
		// Keep the unused default roles out of the system.
		add_action( 'admin_init', array( $this, 'maybe_clean_roles' ) );
		add_filter( 'editable_roles', array( 'Sycomp_B2B_Install', 'restrict_editable_roles' ) );

		// Allow SVG uploads for custom flags
		add_filter( 'upload_mimes', array( $this, 'allow_svg_upload' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'check_svg_filetype' ), 10, 4 );
	}

	/**
	 * Allow SVG uploads.
	 *
	 * @param array $mimes Mime types.
	 * @return array
	 */
	public function allow_svg_upload( $mimes ) {
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Bypass security restrictions for SVG extension check.
	 *
	 * @param array  $data     Data.
	 * @param string $file     File path.
	 * @param string $filename Filename.
	 * @param array  $mimes    Mime types.
	 * @return array
	 */
	public function check_svg_filetype( $data, $file, $filename, $mimes ) {
		$filetype = wp_check_filetype( $filename, $mimes );
		$ext      = $filetype['ext'];
		$type     = $filetype['type'];
		if ( in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			$data['ext']  = $ext;
			$data['type'] = $type;
		}
		return $data;
	}

	/**
	 * Recreate the portal pages if they were deleted.
	 */
	public function maybe_create_pages() {
		if ( ! sycomp_b2b_page_id( 'catalogue' ) || ! sycomp_b2b_page_id( 'orders' ) || ! sycomp_b2b_page_id( 'account' ) || ! sycomp_b2b_page_id( 'manage' ) ) {
			Sycomp_B2B_Install::create_pages();
		}
	}

	/**
	 * Ensure the Sycomp Buyer role exists.
	 */
	public function maybe_add_role() {
		if ( ! get_role( Sycomp_B2B_Install::BUYER_ROLE ) ) {
			Sycomp_B2B_Install::add_buyer_role();
		}
	}

	/**
	 * Remove the unused default roles — re-runs in case a WooCommerce
	 * update has re-created the customer role.
	 */
	public function maybe_clean_roles() {
		Sycomp_B2B_Install::remove_default_roles();
	}
}

/* =========================================================================
 * Global helper functions — used by the theme and templates.
 * ====================================================================== */

if ( ! function_exists( 'sycomp_b2b_page_id' ) ) {
	/**
	 * Get a portal page ID by key ('catalogue' | 'account').
	 *
	 * @param string $key Page key.
	 * @return int
	 */
	function sycomp_b2b_page_id( $key ) {
		return Sycomp_B2B_Install::get_page_id( $key );
	}
}

if ( ! function_exists( 'sycomp_b2b_page_url' ) ) {
	/**
	 * Get a portal page URL by key, with a sensible fallback.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	function sycomp_b2b_page_url( $key ) {
		$id = sycomp_b2b_page_id( $key );
		if ( $id ) {
			return get_permalink( $id );
		}
		return home_url( '/' . sanitize_title( $key ) . '/' );
	}
}

if ( ! function_exists( 'sycomp_b2b_is_manage_page' ) ) {
	/**
	 * Whether the current request is the Shop Manager dashboard page.
	 *
	 * @return bool
	 */
	function sycomp_b2b_is_manage_page() {
		$id = sycomp_b2b_page_id( 'manage' );
		return $id && is_page( $id );
	}
}

if ( ! function_exists( 'sycomp_b2b_use_manager_chrome' ) ) {
	/**
	 * Whether the Shop Manager admin chrome — the top-bar header with the
	 * management nav, and the full-width content column — should be used
	 * for the current request.
	 *
	 * True for Shop Managers on the Manage dashboard, the Catalogue page
	 * and single product pages, so those screens keep the same header and
	 * full-width layout as the rest of the management screens. Buyers
	 * (who lack the manage_woocommerce capability) are never affected.
	 *
	 * @return bool
	 */
	function sycomp_b2b_use_manager_chrome() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( sycomp_b2b_is_manage_page() ) {
			return true;
		}
		if ( is_singular( 'product' ) ) {
			return true;
		}
		$catalogue_id = sycomp_b2b_page_id( 'catalogue' );
		return $catalogue_id && is_page( $catalogue_id );
	}
}

if ( ! function_exists( 'sycomp_b2b_render_location_switcher' ) ) {
	/**
	 * Render the header location switcher (called by the theme header).
	 */
	function sycomp_b2b_render_location_switcher() {
		Sycomp_B2B_Storefront::render_location_switcher();
	}
}

if ( ! function_exists( 'sycomp_b2b_login_gate' ) ) {
	/**
	 * A standard "please sign in" block for shortcodes hit while logged out.
	 *
	 * @param string $message Context message.
	 * @return string
	 */
	function sycomp_b2b_login_gate( $message = '' ) {
		$message = $message ? $message : __( 'Please sign in to continue.', 'sycomp-b2b-portal' );
		$login   = wp_login_url( home_url( add_query_arg( array() ) ) );

		$html  = '<div class="sy-login-wrap"><div class="sy-card">';
		$html .= '<h1>' . esc_html__( 'Sign in required', 'sycomp-b2b-portal' ) . '</h1>';
		$html .= '<p>' . esc_html( $message ) . '</p>';
		$html .= '<p><a class="sy-btn sy-btn--accent sy-btn--block" href="' . esc_url( $login ) . '">'
			. esc_html__( 'Sign in', 'sycomp-b2b-portal' ) . '</a></p>';
		$html .= '</div></div>';
		return $html;
	}
}

if ( ! function_exists( 'sycomp_b2b_placeholder_icon' ) ) {
	/**
	 * A neutral product glyph shown in place of a missing product image, so
	 * image-less cards read as a deliberate spec tile rather than an empty box.
	 *
	 * @return string Inline SVG markup (safe, static).
	 */
	function sycomp_b2b_placeholder_icon() {
		return '<svg class="sy-ph-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>'
			. '<path d="m3.3 7 8.7 5 8.7-5"/>'
			. '<path d="M12 22V12"/>'
			. '</svg>';
	}
}
