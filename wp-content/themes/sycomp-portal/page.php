<?php
/**
 * Generic page template.
 *
 * Used for the Catalogue, Orders, Account and Manage pages (which carry
 * the portal shortcodes) plus standard content pages. Portal pages render
 * their own chrome, so the generic page heading is suppressed for them.
 * On the Shop Manager dashboard the boxed container is dropped — the
 * admin layout supplies its own padded content column.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$sy_portal_ids = function_exists( 'sycomp_b2b_page_id' )
	? array_filter(
		array(
			sycomp_b2b_page_id( 'catalogue' ),
			sycomp_b2b_page_id( 'orders' ),
			sycomp_b2b_page_id( 'account' ),
			sycomp_b2b_page_id( 'manage' ),
		)
	)
	: array();

$sy_is_admin = function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome();

if ( $sy_is_admin ) :
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class(); ?>>
			<div class="sy-page-content"><?php the_content(); ?></div>
		</article>
		<?php
	endwhile;
else :
	?>
	<div class="sy-container">
		<div class="sy-main">
			<?php
			while ( have_posts() ) :
				the_post();
				$sy_hide_head = in_array( get_the_ID(), $sy_portal_ids, true );
				?>
				<article <?php post_class(); ?>>
					<?php if ( ! is_front_page() && ! $sy_hide_head && get_the_title() ) : ?>
						<header class="sy-page-head">
							<h1><?php the_title(); ?></h1>
						</header>
					<?php endif; ?>
					<div class="sy-page-content">
						<?php the_content(); ?>
					</div>
				</article>
				<?php
			endwhile;
			?>
		</div>
	</div>
	<?php
endif;

get_footer();
