<?php
/**
 * Front page — full-viewport market / country selector.
 *
 * The flag grid is rendered by the Sycomp B2B Portal plugin shortcode
 * [sycomp_market_selector]. This template only provides the wrapper.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="sy-home">
	<?php
	if ( shortcode_exists( 'sycomp_market_selector' ) ) {
		echo do_shortcode( '[sycomp_market_selector]' );
	} else {
		echo '<div class="sy-container"><div class="sy-main">';
		echo '<div class="sy-notice sy-notice--warn">';
		echo esc_html__( 'The Sycomp B2B Portal plugin is not active. Activate it to display the market selector.', 'sycomp-portal' );
		echo '</div></div></div>';
	}
	?>
</div>

<?php
get_footer();
