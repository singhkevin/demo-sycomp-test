<?php
/**
 * Plugin Name:       Sycomp B2B Portal
 * Plugin URI:        https://sycomp.com
 * Description:       Private B2B procurement portal for Sycomp. Adds multi-company / multi-location accounts, per-market pricing in 9 currencies, a text-first catalogue, location switching, and a purchase-order workflow on top of WooCommerce.
 * Version:           1.17.8
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Viral Inbound
 * Author URI:        https://viralinbound.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sycomp-b2b-portal
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   8.7
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */
define( 'SYCOMP_B2B_VERSION', '1.17.7' );
define( 'SYCOMP_B2B_FILE', __FILE__ );
define( 'SYCOMP_B2B_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYCOMP_B2B_URL', plugin_dir_url( __FILE__ ) );
define( 'SYCOMP_B2B_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Security hardening.
 *
 * Loaded here — before the `plugins_loaded` action — rather than inside the
 * bootstrap below, so the module can hook `plugins_loaded` at priority 1 and
 * intercept the request early enough to hide the login page and lock down
 * wp-admin.
 *
 * Recovery: define SYCOMP_SECURITY_OFF as true in wp-config.php (or rename /
 * deactivate this plugin) to restore stock wp-login.php behaviour.
 * ---------------------------------------------------------------------- */
if ( ! ( defined( 'SYCOMP_SECURITY_OFF' ) && SYCOMP_SECURITY_OFF ) ) {
	require_once SYCOMP_B2B_DIR . 'includes/class-security.php';
	Sycomp_B2B_Security::init();
}

/* -------------------------------------------------------------------------
 * Declare HPOS (High-Performance Order Storage) compatibility.
 * ---------------------------------------------------------------------- */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/* -------------------------------------------------------------------------
 * Load the plugin once all plugins (incl. WooCommerce) are available.
 * ---------------------------------------------------------------------- */
function sycomp_b2b_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sycomp_b2b_woocommerce_missing_notice' );
		return;
	}

	$includes = array(
		'includes/class-markets.php',
		'includes/class-warehouses.php',
		'includes/class-fx.php',
		'includes/class-post-types.php',
		'includes/class-install.php',
		'includes/class-user.php',
		'includes/class-context.php',
		'includes/class-pricing.php',
		'includes/class-tax.php',
		'includes/class-cart.php',
		'includes/class-po.php',
		'includes/class-po-pdf.php',
		'includes/class-storefront.php',
		'includes/class-catalogue.php',
		'includes/class-account.php',
		'includes/class-manager.php',
		'includes/class-manager-customers.php',
		'includes/class-frontend.php',
		'includes/class-importer.php',
		'admin/class-admin.php',
		'admin/class-admin-company.php',
		'admin/class-admin-pricing.php',
		'admin/class-admin-po.php',
		'admin/class-admin-import.php',
		'includes/class-plugin.php',
	);

	foreach ( $includes as $file ) {
		require_once SYCOMP_B2B_DIR . $file;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once SYCOMP_B2B_DIR . 'includes/class-cli.php';
	}

	Sycomp_B2B_Plugin::instance();
}
add_action( 'plugins_loaded', 'sycomp_b2b_bootstrap', 20 );

/**
 * Admin notice shown when WooCommerce is not active.
 */
function sycomp_b2b_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>Sycomp B2B Portal</strong> requires WooCommerce to be installed and active.</p></div>';
}

/* -------------------------------------------------------------------------
 * Activation / deactivation.
 *
 * The installer class is loaded directly here because activation runs
 * before the plugins_loaded bootstrap above.
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, function () {
	require_once SYCOMP_B2B_DIR . 'includes/class-markets.php';
	require_once SYCOMP_B2B_DIR . 'includes/class-post-types.php';
	require_once SYCOMP_B2B_DIR . 'includes/class-install.php';
	Sycomp_B2B_Install::activate();
} );

register_deactivation_hook( __FILE__, function () {
	require_once SYCOMP_B2B_DIR . 'includes/class-install.php';
	Sycomp_B2B_Install::deactivate();
} );
