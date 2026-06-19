<?php
/**
 * Admin menu and dashboard.
 *
 * The dashboard is the Shop Manager's home: open purchase orders are
 * listed and actionable on it, with quick links to products, categories,
 * brands and companies. For the shop_manager role the wp-admin sidebar is
 * trimmed to the portal's own items only.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Admin.
 */
class Sycomp_B2B_Admin {

	const MENU_SLUG  = 'sycomp-b2b';
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'restrict_shop_manager_menu' ), 9999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'remove_inventory_tab' ), 99 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'remove_stock_column' ), 99 );
	}

	/**
	 * Register the top-level menu and the dashboard subpage.
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Sycomp B2B Portal', 'sycomp-b2b-portal' ),
			__( 'Sycomp B2B', 'sycomp-b2b-portal' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-cart',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'sycomp-b2b-portal' ),
			__( 'Dashboard', 'sycomp-b2b-portal' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'B2B Settings', 'sycomp-b2b-portal' ),
			__( 'B2B Settings', 'sycomp-b2b-portal' ),
			self::CAPABILITY,
			'sycomp-b2b-settings',
			array( __CLASS__, 'redirect_to_settings' )
		);
	}

	/**
	 * Redirect to the frontend B2B settings page.
	 */
	public static function redirect_to_settings() {
		if ( function_exists( 'sycomp_b2b_page_url' ) ) {
			wp_redirect( sycomp_b2b_page_url( 'manage' ) . '?section=company' );
			exit;
		}
		wp_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Whether the current user is a shop manager (and not an administrator).
	 *
	 * @return bool
	 */
	protected static function is_shop_manager_only() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return false;
		}
		$roles = (array) $user->roles;
		return in_array( 'shop_manager', $roles, true ) && ! in_array( 'administrator', $roles, true );
	}

	/**
	 * For shop managers, replace the wp-admin sidebar with the portal's own
	 * curated set of items only.
	 */
	public static function restrict_shop_manager_menu() {
		if ( ! self::is_shop_manager_only() ) {
			return;
		}

		// Each item: array( menu_title, capability, slug, page_title, classes, hookname, icon ).
		$menu = array(
			3  => array( __( 'Dashboard', 'sycomp-b2b-portal' ), self::CAPABILITY, sycomp_b2b_page_url( 'manage' ), '', 'menu-top', 'menu-sycomp-dash', 'dashicons-grid-view' ),
			5  => array( __( 'Products', 'sycomp-b2b-portal' ), 'edit_products', 'edit.php?post_type=product', '', 'menu-top', 'menu-sycomp-products', 'dashicons-archive' ),
			7  => array( __( 'Categories', 'sycomp-b2b-portal' ), 'manage_product_terms', 'edit-tags.php?taxonomy=product_cat&post_type=product', '', 'menu-top', 'menu-sycomp-cats', 'dashicons-category' ),
			9  => array( __( 'Brands', 'sycomp-b2b-portal' ), 'manage_product_terms', 'edit-tags.php?taxonomy=' . Sycomp_B2B_Post_Types::TAX_BRAND . '&post_type=product', '', 'menu-top', 'menu-sycomp-brands', 'dashicons-tag' ),
			85 => array( '', 'read', 'sycomp-sep', '', 'wp-menu-separator', '', '' ),
			90 => array( __( 'Logout', 'sycomp-b2b-portal' ), 'read', wp_logout_url(), '', 'menu-top', 'menu-sycomp-logout', 'dashicons-exit' ),
		);

		$GLOBALS['menu'] = $menu;

		// Keep registered sub-pages reachable — WordPress's page access
		// check walks $submenu — but drop the Products sub-items so the
		// sidebar stays flat.
		if ( isset( $GLOBALS['submenu']['edit.php?post_type=product'] ) ) {
			unset( $GLOBALS['submenu']['edit.php?post_type=product'] );
		}
	}

	/**
	 * Admin CSS for portal screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		wp_enqueue_style(
			'sycomp-b2b-admin',
			SYCOMP_B2B_URL . 'admin/css/admin.css',
			array(),
			SYCOMP_B2B_VERSION
		);

		if ( self::is_shop_manager_only() ) {
			wp_enqueue_style(
				'sycomp-b2b-rebrand',
				SYCOMP_B2B_URL . 'admin/css/admin-rebrand.css',
				array(),
				SYCOMP_B2B_VERSION
			);
		}
	}

	/**
	 * Dashboard screen.
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sycomp-b2b-portal' ) );
		}

		$companies  = wp_count_posts( Sycomp_B2B_Post_Types::COMPANY );
		$locations  = wp_count_posts( Sycomp_B2B_Post_Types::LOCATION );
		$products   = wp_count_posts( 'product' );
		$company_n  = isset( $companies->publish ) ? (int) $companies->publish : 0;
		$location_n = isset( $locations->publish ) ? (int) $locations->publish : 0;
		$product_n  = isset( $products->publish ) ? (int) $products->publish : 0;
		$open_n     = self::count_orders( Sycomp_B2B_PO::STATUS_OPEN );
		$process_n  = self::count_orders( Sycomp_B2B_PO::STATUS_PROCESS );

		$pending = wc_get_orders(
			array(
				'status'  => Sycomp_B2B_PO::STATUS_OPEN,
				'limit'   => 25,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		$tax_url = function ( $taxonomy ) {
			return admin_url( 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=product' );
		};
		?>
		<div class="wrap sycomp-admin">
			<h1><?php esc_html_e( 'Sycomp B2B Portal', 'sycomp-b2b-portal' ); ?></h1>
			<p class="sycomp-admin__lede">
				<?php esc_html_e( 'Review purchase orders, manage the catalogue and per-market pricing, and set up customer companies.', 'sycomp-b2b-portal' ); ?>
			</p>

			<div class="sycomp-stats">
				<div class="sycomp-stat sycomp-stat--alert">
					<span class="sycomp-stat__n"><?php echo esc_html( number_format_i18n( $open_n ) ); ?></span>
					<span class="sycomp-stat__l"><?php esc_html_e( 'Open POs', 'sycomp-b2b-portal' ); ?></span>
				</div>
				<div class="sycomp-stat">
					<span class="sycomp-stat__n"><?php echo esc_html( number_format_i18n( $process_n ) ); ?></span>
					<span class="sycomp-stat__l"><?php esc_html_e( 'In-Process POs', 'sycomp-b2b-portal' ); ?></span>
				</div>
				<div class="sycomp-stat">
					<span class="sycomp-stat__n"><?php echo esc_html( number_format_i18n( $product_n ) ); ?></span>
					<span class="sycomp-stat__l"><?php esc_html_e( 'Products', 'sycomp-b2b-portal' ); ?></span>
				</div>
				<div class="sycomp-stat">
					<span class="sycomp-stat__n"><?php echo esc_html( number_format_i18n( $company_n ) ); ?></span>
					<span class="sycomp-stat__l"><?php esc_html_e( 'Companies', 'sycomp-b2b-portal' ); ?></span>
				</div>
				<div class="sycomp-stat">
					<span class="sycomp-stat__n"><?php echo esc_html( number_format_i18n( $location_n ) ); ?></span>
					<span class="sycomp-stat__l"><?php esc_html_e( 'Locations', 'sycomp-b2b-portal' ); ?></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Open purchase orders', 'sycomp-b2b-portal' ); ?></h2>
			<?php if ( empty( $pending ) ) : ?>
				<p><?php esc_html_e( 'All caught up — no open purchase orders.', 'sycomp-b2b-portal' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'PO #', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Company', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Location', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Date', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Total', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'sycomp-b2b-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $pending as $order ) :
							$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
							$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
							$market      = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
							$total       = ( $market && Sycomp_B2B_Markets::exists( $market ) )
								? Sycomp_B2B_Pricing::format_in_market( $order->get_total(), $market )
								: $order->get_formatted_order_total();
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
										<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
									</a>
								</td>
								<td><?php echo $company_id ? esc_html( get_the_title( $company_id ) ) : '—'; ?></td>
								<td><?php echo $location_id ? esc_html( get_the_title( $location_id ) ) : '—'; ?></td>
								<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
								<td><?php echo wp_kses_post( $total ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
										<?php esc_html_e( 'View', 'sycomp-b2b-portal' ); ?>
									</a>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( Sycomp_B2B_Admin_PO::NONCE, 'sycomp_po_nonce' ); ?>
										<input type="hidden" name="sycomp_po_order" value="<?php echo esc_attr( $order->get_id() ); ?>">
										<button type="submit" name="sycomp_po_action" value="accept" class="button button-primary button-small">
											<?php esc_html_e( 'Accept', 'sycomp-b2b-portal' ); ?>
										</button>
										<button type="submit" name="sycomp_po_action" value="cancel" class="button button-small"
											onclick="return confirm('<?php echo esc_js( __( 'Cancel this purchase order?', 'sycomp-b2b-portal' ) ); ?>');">
											<?php esc_html_e( 'Cancel', 'sycomp-b2b-portal' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Sycomp_B2B_Admin_PO::PAGE ) ); ?>">
					<?php esc_html_e( 'Open all purchase orders', 'sycomp-b2b-portal' ); ?>
				</a>
			</p>

			<div class="sycomp-cards">
				<div class="sycomp-card">
					<h2><?php esc_html_e( 'Products & pricing', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Add or edit products. Open any product and use the "Sycomp Market Pricing" box to set its price in each of the 9 markets.', 'sycomp-b2b-portal' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>">
						<?php esc_html_e( 'Add new product', 'sycomp-b2b-portal' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
						<?php esc_html_e( 'All products', 'sycomp-b2b-portal' ); ?></a>
				</div>
				<div class="sycomp-card">
					<h2><?php esc_html_e( 'Categories & brands', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Organise the catalogue with product categories and brands — both power the catalogue filters buyers see.', 'sycomp-b2b-portal' ); ?></p>
					<a class="button" href="<?php echo esc_url( $tax_url( 'product_cat' ) ); ?>">
						<?php esc_html_e( 'Product categories', 'sycomp-b2b-portal' ); ?></a>
					<a class="button" href="<?php echo esc_url( $tax_url( Sycomp_B2B_Post_Types::TAX_BRAND ) ); ?>">
						<?php esc_html_e( 'Brands', 'sycomp-b2b-portal' ); ?></a>
				</div>
				<div class="sycomp-card">
					<h2><?php esc_html_e( 'Companies & locations', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Create customer companies, add their locations, set a company logo and tie each location to a market.', 'sycomp-b2b-portal' ); ?></p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Sycomp_B2B_Post_Types::COMPANY ) ); ?>">
						<?php esc_html_e( 'Companies', 'sycomp-b2b-portal' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Sycomp_B2B_Post_Types::LOCATION ) ); ?>">
						<?php esc_html_e( 'Locations', 'sycomp-b2b-portal' ); ?></a>
				</div>
				<div class="sycomp-card">
					<h2><?php esc_html_e( 'Buyers & data import', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Assign portal users to a company on their profile, or bulk-import products and per-market price lists from CSV.', 'sycomp-b2b-portal' ); ?></p>
					<a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">
						<?php esc_html_e( 'Manage users', 'sycomp-b2b-portal' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=sycomp-b2b-import' ) ); ?>">
						<?php esc_html_e( 'Import data', 'sycomp-b2b-portal' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Count orders in a given status.
	 *
	 * @param string $status Status slug (no wc- prefix).
	 * @return int
	 */
	public static function count_orders( $status ) {
		$orders = wc_get_orders(
			array(
				'status' => $status,
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/**
	 * Remove the inventory tab from the WooCommerce product data metabox in WP Admin.
	 *
	 * @param array $tabs Product data tabs.
	 * @return array
	 */
	public static function remove_inventory_tab( $tabs ) {
		if ( isset( $tabs['inventory'] ) ) {
			unset( $tabs['inventory'] );
		}
		return $tabs;
	}

	/**
	 * Remove the stock status column from the products list page in WP Admin.
	 *
	 * @param array $columns Products list columns.
	 * @return array
	 */
	public static function remove_stock_column( $columns ) {
		if ( isset( $columns['is_in_stock'] ) ) {
			unset( $columns['is_in_stock'] );
		}
		return $columns;
	}
}
