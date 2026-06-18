<?php
/**
 * WooCommerce wrapper template.
 *
 * Renders all WooCommerce-controlled pages (single product pages and
 * product archives). Buyers get the boxed Sycomp Portal container; Shop
 * Managers get the full-width management content column so a product page
 * matches the rest of the manager dashboard chrome.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$sy_is_admin = function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome();

if ( $sy_is_admin ) :
	woocommerce_content();
else :
	?>
	<div class="sy-container">
		<div class="sy-main woocommerce-wrapper">
			<?php woocommerce_content(); ?>
		</div>
	</div>
	<?php
endif;

get_footer();
