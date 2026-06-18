<?php
/**
 * Uninstall routine for the Sycomp B2B Portal.
 *
 * Removes plugin settings and the buyer role. Customer-facing DATA —
 * companies, locations, products, per-market pricing and purchase orders —
 * is intentionally preserved so that uninstalling never destroys the
 * catalogue or order history.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Plugin options.
delete_option( 'sycomp_b2b_pages' );
delete_option( 'sycomp_b2b_version' );

// Remove the dedicated buyer role (users keep their accounts).
if ( get_role( 'sycomp_buyer' ) ) {
	remove_role( 'sycomp_buyer' );
}
