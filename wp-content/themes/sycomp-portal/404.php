<?php
/**
 * 404 template.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="sy-container">
	<div class="sy-main">
		<div class="sy-card" style="padding:40px;text-align:center;max-width:520px;margin:40px auto;">
			<h1><?php esc_html_e( 'Page not found', 'sycomp-portal' ); ?></h1>
			<p class="sy-lede" style="color:var(--sy-muted);">
				<?php esc_html_e( 'The page you requested does not exist or is no longer available.', 'sycomp-portal' ); ?>
			</p>
			<p style="margin-top:20px;">
				<a class="sy-btn sy-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Back to home', 'sycomp-portal' ); ?>
				</a>
			</p>
		</div>
	</div>
</div>

<?php
get_footer();
