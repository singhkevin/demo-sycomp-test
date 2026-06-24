<?php
/**
 * Sycomp Portal theme — bootstrap.
 *
 * A minimal, WooCommerce-compatible classic theme. Storefront behaviour
 * (catalogue, pricing, location switching, PO workflow) lives in the
 * companion plugin "Sycomp B2B Portal". This theme handles layout,
 * branding and WooCommerce template wrapping only.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SYCOMP_THEME_VERSION', '1.8.3' );

/**
 * Theme setup.
 */
function sycomp_theme_setup() {
	load_theme_textdomain( 'sycomp-portal', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'custom-logo', array(
		'height'      => 60,
		'width'       => 240,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'html5', array(
		'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
	) );

	// WooCommerce.
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( array(
		'primary' => __( 'Primary Navigation', 'sycomp-portal' ),
		'footer'  => __( 'Footer Links', 'sycomp-portal' ),
	) );
}
add_action( 'after_setup_theme', 'sycomp_theme_setup' );

/**
 * Front-end assets.
 */
function sycomp_theme_assets() {
	wp_enqueue_style(
		'sycomp-portal',
		get_stylesheet_uri(),
		array(),
		SYCOMP_THEME_VERSION
	);

	wp_enqueue_script(
		'sycomp-portal',
		get_template_directory_uri() . '/assets/js/theme.js',
		array(),
		SYCOMP_THEME_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'sycomp_theme_assets' );

/**
 * Register the theme's widget-free footer columns as editable menus only.
 * Content (links, social, copyright) is theme-mod driven — see Customizer.
 */
function sycomp_theme_customize_register( $wp_customize ) {
	$wp_customize->add_section( 'sycomp_footer', array(
		'title'    => __( 'Sycomp Footer', 'sycomp-portal' ),
		'priority' => 130,
	) );

	$fields = array(
		'sycomp_footer_tagline'   => array( __( 'Footer tagline', 'sycomp-portal' ), 'Private B2B procurement portal for Sycomp corporate customers.' ),
		'sycomp_footer_copyright' => array( __( 'Copyright line', 'sycomp-portal' ), '© ' . gmdate( 'Y' ) . ' Sycomp. All rights reserved.' ),
		'sycomp_social_linkedin'  => array( __( 'LinkedIn URL', 'sycomp-portal' ), '' ),
		'sycomp_social_x'         => array( __( 'X / Twitter URL', 'sycomp-portal' ), '' ),
		'sycomp_social_youtube'   => array( __( 'YouTube URL', 'sycomp-portal' ), '' ),
	);

	foreach ( $fields as $id => $field ) {
		$wp_customize->add_setting( $id, array(
			'default'           => $field[1],
			'sanitize_callback' => ( false !== strpos( $id, 'social' ) ) ? 'esc_url_raw' : 'sanitize_text_field',
			'transport'         => 'refresh',
		) );
		$wp_customize->add_control( $id, array(
			'label'   => $field[0],
			'section' => 'sycomp_footer',
			'type'    => 'text',
		) );
	}
}
add_action( 'customize_register', 'sycomp_theme_customize_register' );

/**
 * Helper: theme logo URL, preferring a custom logo, then a plugin-supplied
 * Sycomp asset, then a text fallback handled in the template.
 *
 * @param string $variant 'color' or 'white'.
 * @return string Logo URL or empty string.
 */
function sycomp_logo_url( $variant = 'white' ) {
	// 1. WordPress custom logo (Appearance → Customize).
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	if ( 'color' === $variant && $custom_logo_id ) {
		$img = wp_get_attachment_image_src( $custom_logo_id, 'full' );
		if ( $img ) {
			return $img[0];
		}
	}

	// 2. Logo bundled into the theme assets folder (drop files here).
	$candidates = ( 'white' === $variant )
		? array( 'sycomp-logo-white.webp', 'sycomp-logo-white-1.webp', 'sycomp-logo-white.png' )
		: array( 'sycomp-logo-color.webp', 'sycomp-logo-full-color_no_tag.webp', 'sycomp-logo-color.png' );

	foreach ( $candidates as $file ) {
		if ( file_exists( get_template_directory() . '/assets/img/' . $file ) ) {
			return get_template_directory_uri() . '/assets/img/' . $file;
		}
	}

	return '';
}

/**
 * Wrap WooCommerce content in the theme's container.
 *
 * Buyers get the boxed container; Shop Managers get a plain full-width
 * wrapper so WooCommerce pages sit inside the management content column.
 */
function sycomp_wc_wrapper_start() {
	if ( function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome() ) {
		echo '<div class="woocommerce-wrapper sy-page-content">';
	} else {
		echo '<div class="sy-container"><div class="sy-main woocommerce-wrapper">';
	}
}
function sycomp_wc_wrapper_end() {
	if ( function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome() ) {
		echo '</div>';
	} else {
		echo '</div></div>';
	}
}
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );
add_action( 'woocommerce_before_main_content', 'sycomp_wc_wrapper_start', 10 );
add_action( 'woocommerce_after_main_content', 'sycomp_wc_wrapper_end', 10 );

/**
 * Strip the WooCommerce single-product Description / Reviews data tabs and
 * up-sells. Related products are kept (shown automatically below the
 * product summary).
 */
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15 );

/**
 * Shop Managers browse the catalogue but never buy — they do not add
 * products to a cart or raise proposals. Remove the add-to-cart
 * button (quantity + button) from the single product page for them.
 * Buyers are unaffected.
 */
function sycomp_manager_no_add_to_cart() {
	if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}
}
add_action( 'wp', 'sycomp_manager_no_add_to_cart' );

/**
 * On the single product page, show the product's category as plain text
 * for Shop Managers instead of a link — the category archive page is not
 * part of the management workflow. Buyers keep the normal linked category.
 *
 * @param string[] $links Category term links (anchor HTML).
 * @return string[]
 */
function sycomp_manager_plain_product_cat( $links ) {
	if ( is_admin() || ! is_singular( 'product' ) ) {
		return $links;
	}
	if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
		$links = array_map( 'wp_strip_all_tags', (array) $links );
	}
	return $links;
}
add_filter( 'term_links-product_cat', 'sycomp_manager_plain_product_cat' );

/**
 * Tag single product pages that have no featured image, so the layout can
 * collapse to one centred column instead of leaving a large empty gallery
 * void beside the summary.
 *
 * @param string[]   $classes Product wrapper classes.
 * @param WC_Product $product Product object.
 * @return string[]
 */
function sycomp_product_noimage_class( $classes, $product ) {
	if ( is_singular( 'product' ) && $product instanceof WC_Product && ! $product->get_image_id() ) {
		$classes[] = 'sy-product--noimage';
	}
	return $classes;
}
add_filter( 'woocommerce_post_class', 'sycomp_product_noimage_class', 10, 2 );

/**
 * A "back to catalogue" link above the single product page, so a product is
 * not a dead end.
 */
function sycomp_product_back_link() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$url = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'catalogue' ) : '';
	if ( $url ) {
		echo '<p class="sy-back"><a href="' . esc_url( $url ) . '">&larr; '
			. esc_html__( 'Back to catalogue', 'sycomp-portal' ) . '</a></p>';
	}
}
add_action( 'woocommerce_before_single_product', 'sycomp_product_back_link' );

/**
 * Remove the WooCommerce sidebar — this is a focused B2B tool, no widget rail.
 */
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/**
 * Admin notice if the companion plugin is not active.
 */
function sycomp_theme_plugin_check() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	if ( ! defined( 'SYCOMP_B2B_VERSION' ) ) {
		echo '<div class="notice notice-warning"><p><strong>Sycomp Portal:</strong> ';
		echo esc_html__( 'This theme needs the "Sycomp B2B Portal" plugin (and WooCommerce) active to provide the catalogue, pricing and PO workflow.', 'sycomp-portal' );
		echo '</p></div>';
	}
}
add_action( 'admin_notices', 'sycomp_theme_plugin_check' );
