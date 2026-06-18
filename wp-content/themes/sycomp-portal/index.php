<?php
/**
 * Fallback template.
 *
 * The portal runs on the front page (market selector) and shortcode-driven
 * pages. This template is the WordPress-required generic fallback.
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
		<?php if ( have_posts() ) : ?>
			<header class="sy-page-head">
				<h1>
					<?php
					if ( is_home() && ! is_front_page() ) {
						single_post_title();
					} elseif ( is_search() ) {
						/* translators: %s: search query. */
						printf( esc_html__( 'Search results for: %s', 'sycomp-portal' ), '<span>' . esc_html( get_search_query() ) . '</span>' );
					} else {
						esc_html_e( 'Latest', 'sycomp-portal' );
					}
					?>
				</h1>
			</header>
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article <?php post_class( 'sy-card' ); ?> style="padding:24px;margin-bottom:20px;">
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
				<?php
			endwhile;

			the_posts_pagination();
		else :
			?>
			<div class="sy-notice"><?php esc_html_e( 'Nothing found.', 'sycomp-portal' ); ?></div>
		<?php endif; ?>
	</div>
</div>

<?php
get_footer();
