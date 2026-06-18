<?php
/**
 * Theme header.
 *
 * Two chromes are rendered from here:
 *  - Shop managers on the management dashboard get a top-bar admin layout
 *    (logo + search row, then a row of flat navigation links).
 *  - Everyone else gets the white boxed storefront header bar.
 *
 * @package Sycomp_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sy_logo   = sycomp_logo_url( 'color' );
$sy_cat    = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'catalogue' ) : home_url( '/catalogue/' );
$sy_orders = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'orders' ) : home_url( '/orders/' );
$sy_acct   = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'account' ) : home_url( '/account/' );
$sy_manage = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'manage' ) : home_url( '/manage/' );

$sy_is_admin = function_exists( 'sycomp_b2b_use_manager_chrome' ) && sycomp_b2b_use_manager_chrome();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow"><?php // Private portal — keep out of search engines. ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( $sy_is_admin ? 'sy-admin-body' : '' ); ?>>
<?php wp_body_open(); ?>

<a class="sy-skip-link" href="#sy-content"><?php esc_html_e( 'Skip to content', 'sycomp-portal' ); ?></a>

<?php if ( $sy_is_admin ) : ?>
	<?php
	$sy_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard';
	$sy_active  = $sy_section;
	// The management chrome also wraps the Catalogue page — when shown
	// there, highlight "View Catalogue" instead of defaulting to Dashboard.
	if ( ! ( function_exists( 'sycomp_b2b_is_manage_page' ) && sycomp_b2b_is_manage_page() ) ) {
		$sy_active = 'catalogue';
	}
	if ( ! $sy_active ) {
		$sy_active = 'dashboard';
	}
	$sy_pq   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$sy_user = wp_get_current_user();
	$sy_name = $sy_user->display_name ? $sy_user->display_name : $sy_user->user_login;
	$sy_open_pos = ( class_exists( 'Sycomp_B2B_PO' ) )
		? count( wc_get_orders( array( 'status' => Sycomp_B2B_PO::STATUS_OPEN, 'limit' => -1, 'return' => 'ids' ) ) )
		: 0;

	$sy_nav = array(
		array( 'dashboard', __( 'Dashboard', 'sycomp-portal' ), $sy_manage ),
		array( 'products', __( 'Products', 'sycomp-portal' ), add_query_arg( 'section', 'products', $sy_manage ) ),
		array( 'categories', __( 'Categories', 'sycomp-portal' ), add_query_arg( 'section', 'categories', $sy_manage ) ),
		array( 'customers', __( 'Customers', 'sycomp-portal' ), add_query_arg( 'section', 'customers', $sy_manage ) ),
		array( 'po', __( 'Purchase Orders', 'sycomp-portal' ), add_query_arg( array( 'section' => 'po' ), $sy_manage ) ),
		array( 'catalogue', __( 'View Catalogue', 'sycomp-portal' ), $sy_cat ),
	);
	?>
	<div class="sy-admin">
		<header class="sy-admin__top">
			<div class="sy-admin__bar">
				<a class="sy-admin__brand" href="<?php echo esc_url( $sy_manage ); ?>">
					<?php if ( $sy_logo ) : ?>
						<img src="<?php echo esc_url( $sy_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
					<?php else : ?>
						<span class="sy-header__logo-text">Sycomp</span>
					<?php endif; ?>
				</a>
				<form class="sy-admin__search" method="get" action="<?php echo esc_url( $sy_manage ); ?>" role="search">
					<input type="hidden" name="section" value="search">
					<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
					<input type="search" name="q" value="<?php echo esc_attr( $sy_pq ); ?>" placeholder="<?php esc_attr_e( 'Search products, POs, customers...', 'sycomp-portal' ); ?>">
				</form>
				<div class="sy-admin__tools">
					<a class="sy-admin__bell" href="<?php echo esc_url( add_query_arg( array( 'section' => 'po', 'po' => 'open' ), $sy_manage ) ); ?>"
						aria-label="<?php echo esc_attr( sprintf( _n( '%d open purchase order', '%d open purchase orders', $sy_open_pos, 'sycomp-portal' ), $sy_open_pos ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
						<?php if ( $sy_open_pos > 0 ) : ?><span class="sy-admin__bell-dot"><?php echo esc_html( number_format_i18n( $sy_open_pos ) ); ?></span><?php endif; ?>
					</a>
					<div class="sy-accountmenu">
						<button type="button" class="sy-accountmenu__btn" aria-haspopup="true" aria-expanded="false">
							<span class="sy-admin__avatar"><?php echo esc_html( strtoupper( substr( $sy_name, 0, 1 ) ) ); ?></span>
							<span class="sy-accountmenu__caret" aria-hidden="true">▾</span>
						</button>
						<div class="sy-accountmenu__menu" role="menu">
							<div class="sy-accountmenu__head">
								<span class="sy-accountmenu__name"><?php echo esc_html( $sy_name ); ?></span>
								<span class="sy-accountmenu__email"><?php echo esc_html( $sy_user->user_email ); ?></span>
							</div>
							<a class="sy-accountmenu__item" role="menuitem" href="<?php echo esc_url( add_query_arg( 'section', 'profile', $sy_manage ) ); ?>">
								<?php esc_html_e( 'My Profile', 'sycomp-portal' ); ?>
							</a>
							<a class="sy-accountmenu__item" role="menuitem" href="<?php echo esc_url( add_query_arg( 'section', 'customers', $sy_manage ) ); ?>">
								<?php esc_html_e( 'Customers', 'sycomp-portal' ); ?>
							</a>
							<a class="sy-accountmenu__item" role="menuitem" href="<?php echo esc_url( add_query_arg( 'section', 'company', $sy_manage ) ); ?>">
								<?php esc_html_e( 'Company Details', 'sycomp-portal' ); ?>
							</a>
							<div class="sy-accountmenu__sep" role="separator"></div>
							<a class="sy-accountmenu__item sy-accountmenu__item--logout" role="menuitem" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
								<?php esc_html_e( 'Log out', 'sycomp-portal' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
			<nav class="sy-admin__nav" aria-label="<?php esc_attr_e( 'Management', 'sycomp-portal' ); ?>">
				<?php foreach ( $sy_nav as $sy_item ) : ?>
					<a class="sy-tab<?php echo ( $sy_item[0] === $sy_active ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $sy_item[2] ); ?>">
						<?php echo esc_html( $sy_item[1] ); ?>
					</a>
				<?php endforeach; ?>
				<a class="sy-tab sy-tab--logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'Logout', 'sycomp-portal' ); ?>
				</a>
			</nav>
		</header>
		<main id="sy-content" class="sy-admin__content">
<?php else : ?>
<header class="sy-header">
	<div class="sy-container">
		<div class="sy-header__bar">

			<a class="sy-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php if ( $sy_logo ) : ?>
					<img src="<?php echo esc_url( $sy_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<?php else : ?>
					<span class="sy-header__logo-text">Sycomp</span>
				<?php endif; ?>
			</a>

			<?php if ( is_user_logged_in() ) : ?>
				<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>

					<nav class="sy-header__nav" aria-label="<?php esc_attr_e( 'Management', 'sycomp-portal' ); ?>">
						<a href="<?php echo esc_url( $sy_manage ); ?>"><?php esc_html_e( 'Dashboard', 'sycomp-portal' ); ?></a>
						<a href="<?php echo esc_url( $sy_cat ); ?>"><?php esc_html_e( 'Catalogue', 'sycomp-portal' ); ?></a>
					</nav>
					<div class="sy-header__meta">
						<a class="sy-header__user" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
							<?php esc_html_e( 'Sign out', 'sycomp-portal' ); ?>
						</a>
					</div>

				<?php else : ?>

					<?php
					// Logged-in buyer's company logo, shown next to the Sycomp logo.
					$sy_company_logo = '';
					$sy_company_name = '';
					if ( class_exists( 'Sycomp_B2B_User' ) ) {
						$sy_cid = (int) Sycomp_B2B_User::get_company();
						if ( $sy_cid ) {
							$sy_company_name = get_the_title( $sy_cid );
							$sy_logo_id      = (int) get_post_meta( $sy_cid, '_sycomp_logo_id', true );
							if ( $sy_logo_id ) {
								$sy_company_logo = wp_get_attachment_image_url( $sy_logo_id, 'medium' );
							}
						}
					}
					?>
					<?php if ( $sy_company_logo || $sy_company_name ) : ?>
						<div class="sy-header__company" title="<?php echo esc_attr( $sy_company_name ); ?>">
							<?php if ( $sy_company_logo ) : ?>
								<img class="sy-header__company-logo" src="<?php echo esc_url( $sy_company_logo ); ?>" alt="<?php echo esc_attr( $sy_company_name ); ?>">
							<?php else : ?>
								<span class="sy-header__company-name"><?php echo esc_html( $sy_company_name ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<nav class="sy-header__nav" aria-label="<?php esc_attr_e( 'Primary', 'sycomp-portal' ); ?>">
						<a href="<?php echo esc_url( $sy_cat ); ?>"><?php esc_html_e( 'Catalogue', 'sycomp-portal' ); ?></a>
						<a href="<?php echo esc_url( $sy_orders ); ?>"><?php esc_html_e( 'Orders', 'sycomp-portal' ); ?></a>
						<a href="<?php echo esc_url( $sy_acct ); ?>"><?php esc_html_e( 'Account', 'sycomp-portal' ); ?></a>
						<?php if ( function_exists( 'wc_get_cart_url' ) ) : ?>
							<a href="<?php echo esc_url( wc_get_cart_url() ); ?>">
								<?php esc_html_e( 'Cart', 'sycomp-portal' ); ?>
								(<span class="sy-cart-count"><?php
									echo ( function_exists( 'WC' ) && WC()->cart ) ? esc_html( WC()->cart->get_cart_contents_count() ) : '0';
								?></span>)
							</a>
						<?php endif; ?>
					</nav>

					<div class="sy-header__meta">
						<?php
						// Location switcher — rendered by the Sycomp B2B Portal plugin.
						if ( function_exists( 'sycomp_b2b_render_location_switcher' ) ) {
							sycomp_b2b_render_location_switcher();
						}
						?>
						<a class="sy-header__user" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
							<?php esc_html_e( 'Sign out', 'sycomp-portal' ); ?>
						</a>
					</div>

				<?php endif; ?>
			<?php else : ?>
				<a class="sy-header__support" href="https://sycomp.com/customer-support/" target="_blank" rel="noopener">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
						<circle cx="12" cy="12" r="9"></circle>
						<circle cx="12" cy="12" r="3.4"></circle>
						<path d="M5.6 5.6l3.9 3.9M18.4 5.6l-3.9 3.9M5.6 18.4l3.9-3.9M18.4 18.4l-3.9-3.9"></path>
					</svg>
					<?php esc_html_e( 'Customer Support', 'sycomp-portal' ); ?>
				</a>
			<?php endif; ?>

		</div>
	</div>
</header>

<main id="sy-content">
<?php endif; ?>
