<?php
/**
 * Theme footer.
 *
 * Navy boxed footer — matches the Sycomp login-screen chrome. On the Shop
 * Manager dashboard it sits inside the admin layout's main column and the
 * sidebar wrappers are closed after it.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sy_is_admin = function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome();

$sy_logo = sycomp_logo_url( 'white' );

$sy_nav = array(
	'Solutions'        => 'https://sycomp.com/solutions/',
	'Services'         => 'https://sycomp.com/services/',
	'Industries'       => 'https://sycomp.com/industries/',
	'Resources'        => 'https://sycomp.com/resources/',
	'About'            => 'https://sycomp.com/about/',
	'Careers'          => 'https://sycomp.com/careers/',
	'Customer Support' => 'https://sycomp.com/customer-support/',
);

$sy_social = array(
	'Instagram' => array( 'https://www.instagram.com/sycomp_inc/', '<path d="M12 2.2c3.2 0 3.6 0 4.9.07 3.3.15 4.8 1.7 4.95 4.95.06 1.3.07 1.7.07 4.88s0 3.6-.07 4.88c-.15 3.25-1.7 4.8-4.95 4.95-1.3.06-1.7.07-4.9.07s-3.6 0-4.9-.07c-3.25-.15-4.8-1.7-4.95-4.95C2.2 15.6 2.2 15.2 2.2 12s0-3.6.07-4.88C2.42 3.87 3.97 2.32 7.22 2.17 8.5 2.2 8.9 2.2 12 2.2zm0 3.6a6.2 6.2 0 100 12.4 6.2 6.2 0 000-12.4zm0 10.2a4 4 0 110-8 4 4 0 010 8zm6.4-10.5a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9z"/>' ),
	'Facebook'  => array( 'https://www.facebook.com/SycompInc/', '<path d="M22 12a10 10 0 10-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0022 12z"/>' ),
	'X'         => array( 'https://x.com/Sycomp_Inc', '<path d="M18.9 2H22l-7.5 8.6L23 22h-6.9l-5.4-7-6.2 7H1.4l8-9.2L1 2h7l4.9 6.5zM17.7 20h1.7L7.4 3.9H5.6z"/>' ),
	'YouTube'   => array( 'https://www.youtube.com/@Sycomp', '<path d="M23 12s0-3.2-.4-4.7a3 3 0 00-2.1-2.1C18.7 4.8 12 4.8 12 4.8s-6.7 0-8.5.4A3 3 0 001.4 7.3C1 8.8 1 12 1 12s0 3.2.4 4.7a3 3 0 002.1 2.1c1.8.4 8.5.4 8.5.4s6.7 0 8.5-.4a3 3 0 002.1-2.1C23 15.2 23 12 23 12zM9.8 15.3V8.7l5.7 3.3z"/>' ),
	'LinkedIn'  => array( 'https://www.linkedin.com/company/sycomp/', '<path d="M4.98 3.5a2.5 2.5 0 110 5 2.5 2.5 0 010-5zM3 9h4v12H3zM10 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.77-1.95 4.03 0 4.78 2.6 4.78 5.97V21h-4v-5.3c0-1.27-.03-2.9-1.8-2.9-1.8 0-2.07 1.4-2.07 2.8V21H10z"/>' ),
);
?>
</main>

<footer class="sy-footer<?php echo $sy_is_admin ? ' sy-footer--admin' : ''; ?>">
	<div class="sy-container">
		<div class="sy-footer__row">
			<a class="sy-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php if ( $sy_logo ) : ?>
					<img src="<?php echo esc_url( $sy_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<?php else : ?>
					<strong style="color:#fff;font-size:1.1rem;">Sycomp</strong>
				<?php endif; ?>
			</a>
			<nav class="sy-footer__nav" aria-label="<?php esc_attr_e( 'Footer', 'sycomp-portal' ); ?>">
				<?php foreach ( $sy_nav as $sy_label => $sy_url ) : ?>
					<a href="<?php echo esc_url( $sy_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $sy_label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="sy-footer__social">
				<?php foreach ( $sy_social as $sy_name => $sy_data ) : ?>
					<a href="<?php echo esc_url( $sy_data[0] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $sy_name ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $sy_data[1]; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="sy-footer__divider"></div>
		<div class="sy-footer__bottom">
			<span class="sy-footer__copy">
				<?php echo esc_html( '© ' . gmdate( 'Y' ) . ' Sycomp A Technology Company, Inc. All rights reserved.' ); ?>
			</span>
			<span class="sy-footer__legal">
				<a href="https://sycomp.com/termsandconditions/" target="_blank" rel="noopener"><?php esc_html_e( 'Terms and conditions', 'sycomp-portal' ); ?></a>
				<a href="https://sycomp.com/privacy-policy/" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'sycomp-portal' ); ?></a>
			</span>
		</div>
	</div>
</footer>

<?php if ( $sy_is_admin ) : ?>
	</div><!-- /.sy-admin -->
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
