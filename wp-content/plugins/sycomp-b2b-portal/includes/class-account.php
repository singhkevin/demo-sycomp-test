<?php
/**
 * Account & Orders pages.
 *
 * Renders the [sycomp_account] shortcode (profile + company locations)
 * and the [sycomp_orders] shortcode (purchase-order history across all
 * of the buyer's locations), plus the tab strip shared by the portal.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Account.
 */
class Sycomp_B2B_Account {

	/**
	 * Register the shortcodes.
	 */
	public static function init() {
		add_shortcode( 'sycomp_account', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sycomp_orders', array( __CLASS__, 'orders_shortcode' ) );
	}

	/**
	 * Tab strip shared by the Catalogue, Orders and Account pages.
	 *
	 * @param string $active Active tab: 'catalogue', 'orders' or 'account'.
	 * @return string
	 */
	public static function render_tabs( $active = 'catalogue' ) {
		$tabs = array(
			'catalogue' => array(
				'label' => __( 'Catalogue', 'sycomp-b2b-portal' ),
				'url'   => sycomp_b2b_page_url( 'catalogue' ),
			),
			'orders'    => array(
				'label' => __( 'Orders', 'sycomp-b2b-portal' ),
				'url'   => sycomp_b2b_page_url( 'orders' ),
			),
			'account'   => array(
				'label' => __( 'Account', 'sycomp-b2b-portal' ),
				'url'   => sycomp_b2b_page_url( 'account' ),
			),
		);

		$html = '<nav class="sy-tabs" aria-label="' . esc_attr__( 'Portal sections', 'sycomp-b2b-portal' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$class = ( $key === $active ) ? 'is-active' : '';
			$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $tab['url'] ) . '">'
				. esc_html( $tab['label'] ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	/**
	 * [sycomp_account] — profile details and company locations.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return sycomp_b2b_login_gate( __( 'Sign in to view your account.', 'sycomp-b2b-portal' ) );
		}
		if ( ! Sycomp_B2B_User::is_portal_user() ) {
			return '<div class="sy-notice sy-notice--warn">'
				. esc_html__( 'Your account is not linked to a company yet. Please contact Sycomp.', 'sycomp-b2b-portal' )
				. '</div>';
		}

		ob_start();
		self::render_profile_panel();
		self::render_locations_panel();
		return (string) ob_get_clean();
	}

	/**
	 * [sycomp_orders] — purchase-order history.
	 *
	 * @return string
	 */
	public static function orders_shortcode() {
		if ( ! is_user_logged_in() ) {
			return sycomp_b2b_login_gate( __( 'Sign in to view your orders.', 'sycomp-b2b-portal' ) );
		}
		if ( ! Sycomp_B2B_User::is_portal_user() ) {
			return '<div class="sy-notice sy-notice--warn">'
				. esc_html__( 'Your account is not linked to a company yet. Please contact Sycomp.', 'sycomp-b2b-portal' )
				. '</div>';
		}

		$order_id = isset( $_GET['sycomp_order'] ) ? absint( wp_unslash( $_GET['sycomp_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		ob_start();
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && Sycomp_B2B_PO::user_can_view( $order ) ) {
				self::render_order_detail( $order );
			} else {
				echo '<div class="sy-notice sy-notice--warn">'
					. esc_html__( 'That purchase order could not be found.', 'sycomp-b2b-portal' )
					. '</div>';
				self::render_orders_panel();
			}
		} else {
			self::render_orders_panel();
		}
		return (string) ob_get_clean();
	}

	/* ---------------------------------------------------------------------
	 * Panels.
	 * ------------------------------------------------------------------ */

	/**
	 * Profile summary.
	 */
	protected static function render_profile_panel() {
		$user = wp_get_current_user();
		?>
		<section class="sy-panel">
			<h2 class="sy-panel__title"><?php esc_html_e( 'Account', 'sycomp-b2b-portal' ); ?></h2>
			<div class="sy-panel__body sy-deflist">
				<div><span class="sy-deflist__k"><?php esc_html_e( 'Name', 'sycomp-b2b-portal' ); ?></span>
					<span class="sy-deflist__v"><?php echo esc_html( $user->display_name ); ?></span></div>
				<div><span class="sy-deflist__k"><?php esc_html_e( 'Email', 'sycomp-b2b-portal' ); ?></span>
					<span class="sy-deflist__v"><?php echo esc_html( $user->user_email ); ?></span></div>
				<div><span class="sy-deflist__k"><?php esc_html_e( 'Company', 'sycomp-b2b-portal' ); ?></span>
					<span class="sy-deflist__v"><?php echo esc_html( Sycomp_B2B_User::get_company_name() ); ?></span></div>
			</div>
		</section>
		<?php
	}

	/**
	 * Company locations and their addresses.
	 */
	protected static function render_locations_panel() {
		$locations = Sycomp_B2B_User::get_locations();
		$active_id = Sycomp_B2B_Context::get_active_location_id();
		?>
		<section class="sy-panel">
			<h2 class="sy-panel__title"><?php esc_html_e( 'Locations & addresses', 'sycomp-b2b-portal' ); ?></h2>
			<div class="sy-panel__body">
				<?php if ( empty( $locations ) ) : ?>
					<div class="sy-notice"><?php esc_html_e( 'No locations have been set up for your company yet.', 'sycomp-b2b-portal' ); ?></div>
				<?php else : ?>
					<div class="sy-loccards">
						<?php
						foreach ( $locations as $location ) :
							$market_key = Sycomp_B2B_Post_Types::get_location_market( $location->ID );
							$market     = Sycomp_B2B_Markets::get( $market_key );
							$billing    = Sycomp_B2B_Post_Types::get_location_billing_address( $location->ID );
							$drop_addrs = Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $location->ID );
							$msp_addrs  = Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $location->ID );
							$is_active  = ( (int) $location->ID === (int) $active_id );
							?>
							<div class="sy-loccard <?php echo $is_active ? 'is-active' : ''; ?>">
								<div class="sy-loccard__head">
									<?php if ( $market ) : ?>
										<img class="sy-loccard__flag" src="<?php echo esc_url( $market['flag_url'] ); ?>" alt="">
									<?php endif; ?>
									<strong><?php echo esc_html( get_the_title( $location ) ); ?></strong>
									<?php if ( $is_active ) : ?>
										<span class="sy-badge sy-badge--approved"><?php esc_html_e( 'Active', 'sycomp-b2b-portal' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="sy-loccard__meta">
									<?php echo $market ? esc_html( $market['label'] . ' · ' . $market['currency'] ) : esc_html__( 'No market assigned', 'sycomp-b2b-portal' ); ?>
								</div>
								
								<?php if ( ! empty( $billing ) ) : ?>
									<div class="sy-loccard__addr-sec" style="margin-top: 10px;">
										<span style="font-size: var(--sy-text-2xs); text-transform: uppercase; font-weight: bold; color: var(--sy-muted); display: block; margin-bottom: 2px;"><?php esc_html_e( 'Bill to', 'sycomp-b2b-portal' ); ?></span>
										<address class="sy-loccard__addr" style="font-style: normal; font-size: var(--sy-text-sm); line-height: 1.4; color: var(--sy-text);"><?php echo nl2br( esc_html( $billing ) ); ?></address>
									</div>
								<?php endif; ?>
								
								<?php if ( ! empty( $drop_addrs ) ) : ?>
									<div class="sy-loccard__addr-sec" style="margin-top: 10px;">
										<span style="font-size: var(--sy-text-2xs); text-transform: uppercase; font-weight: bold; color: var(--sy-muted); display: block; margin-bottom: 2px;"><?php esc_html_e( 'Drop Shipping Addresses', 'sycomp-b2b-portal' ); ?></span>
										<ul style="margin: 0; padding-left: 16px; font-size: var(--sy-text-sm); color: var(--sy-text); line-height: 1.4;">
											<?php foreach ( $drop_addrs as $addr ) : ?>
												<?php if ( ! empty( trim( $addr ) ) ) : ?>
													<li style="margin-bottom: 4px;"><?php echo esc_html( $addr ); ?></li>
												<?php endif; ?>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>

								<?php if ( ! empty( $msp_addrs ) ) : ?>
									<div class="sy-loccard__addr-sec" style="margin-top: 10px;">
										<span style="font-size: var(--sy-text-2xs); text-transform: uppercase; font-weight: bold; color: var(--sy-muted); display: block; margin-bottom: 2px;"><?php esc_html_e( 'MSP Shipping Addresses', 'sycomp-b2b-portal' ); ?></span>
										<ul style="margin: 0; padding-left: 16px; font-size: var(--sy-text-sm); color: var(--sy-text); line-height: 1.4;">
											<?php foreach ( $msp_addrs as $addr ) : ?>
												<?php if ( ! empty( trim( $addr ) ) ) : ?>
													<li style="margin-bottom: 4px;"><?php echo esc_html( $addr ); ?></li>
												<?php endif; ?>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Format an amount in the order's market currency.
	 *
	 * @param float  $amount Amount.
	 * @param string $market Market key.
	 * @return string
	 */
	protected static function money( $amount, $market ) {
		if ( $market && Sycomp_B2B_Markets::exists( $market ) ) {
			return Sycomp_B2B_Pricing::format_in_market( $amount, $market );
		}
		return wc_price( $amount );
	}

	/**
	 * Render a single purchase order's detail, inline on the Orders page.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_order_detail( $order ) {
		$orders_url  = sycomp_b2b_page_url( 'orders' );
		$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$market      = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$po_ref      = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
		$status      = $order->get_status();
		$status_name = Sycomp_B2B_PO::is_po( $order ) ? Sycomp_B2B_PO::status_label( $status ) : wc_get_order_status_name( $status );
		$badge       = Sycomp_B2B_PO::status_badge_class( $status );

		echo '<p class="sy-back"><a href="' . esc_url( $orders_url ) . '">&larr; ' . esc_html__( 'Back to orders', 'sycomp-b2b-portal' ) . '</a></p>';

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">';
		/* translators: %s: PO number. */
		echo esc_html( sprintf( __( 'Purchase order #%s', 'sycomp-b2b-portal' ), $order->get_order_number() ) );
		echo ' <span class="sy-badge ' . esc_attr( $badge ) . '">' . esc_html( $status_name ) . '</span>';
		echo '</h2>';
		echo '<div class="sy-panel__body sy-deflist">';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Date', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Location', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $location_id ? esc_html( get_the_title( $location_id ) ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'PO reference', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== $po_ref ? esc_html( $po_ref ) : '-' ) . '</span></div>';
		$sy_supplier = $market ? Sycomp_B2B_Warehouses::address_lines( $market ) : array();
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Supplier (ship-from)', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $sy_supplier ? wp_kses_post( implode( '<br>', array_map( 'esc_html', $sy_supplier ) ) ) : '-' ) . '</span></div>';
		$sy_billing = (string) $order->get_meta( '_sycomp_billing_address' );
		if ( empty( $sy_billing ) && $location_id ) {
			$sy_billing = Sycomp_B2B_Post_Types::get_location_billing_address( $location_id );
		}
		if ( empty( $sy_billing ) && $company_id ) {
			$sy_billing = Sycomp_B2B_Post_Types::get_company_billing_address( $company_id );
		}
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Bill to', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== trim( $sy_billing ) ? nl2br( esc_html( $sy_billing ) ) : '-' ) . '</span></div>';
		$sy_delivery = (string) $order->get_meta( Sycomp_B2B_PO::META_DELIVERY );
		if ( '' === trim( $sy_delivery ) && $location_id ) {
			$sy_delivery = (string) Sycomp_B2B_Post_Types::get_location_address( $location_id );
		}
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Delivery address', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== trim( $sy_delivery ) ? nl2br( esc_html( $sy_delivery ) ) : '-' ) . '</span></div>';
		echo '</div></section>';

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">' . esc_html__( 'Items', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body">';
		echo '<table class="sy-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Qty', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Unit price', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Line total', 'sycomp-b2b-portal' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $order->get_items() as $item ) {
			$qty     = (int) $item->get_quantity();
			$line    = (float) $item->get_total();
			$unit    = $qty ? $line / $qty : $line;
			$product = $item->get_product();
			$sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : '-';
			echo '<tr>';
			echo '<td>' . esc_html( $item->get_name() ) . '</td>';
			echo '<td>' . esc_html( $sku ) . '</td>';
			echo '<td>' . esc_html( (string) $qty ) . '</td>';
			echo '<td>' . wp_kses_post( self::money( $unit, $market ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::money( $line, $market ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody><tfoot>';
		echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html__( 'Subtotal', 'sycomp-b2b-portal' ) . '</th><th>' . wp_kses_post( self::money( $order->get_subtotal(), $market ) ) . '</th></tr>';
		foreach ( $order->get_fees() as $sy_fee ) {
			echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html( $sy_fee->get_name() ) . '</th><th>' . wp_kses_post( self::money( (float) $sy_fee->get_total(), $market ) ) . '</th></tr>';
		}
		echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html__( 'Order total', 'sycomp-b2b-portal' ) . '</th><th>' . wp_kses_post( self::money( $order->get_total(), $market ) ) . '</th></tr>';
		echo '</tfoot></table>';
		echo '</div></section>';

		printf(
			'<p class="sy-po-pdf-actions"><a class="sy-btn sy-btn--primary" href="%s">%s</a></p>',
			esc_url( Sycomp_B2B_PO_PDF::pdf_url( $order ) ),
			esc_html__( 'Download purchase order (PDF)', 'sycomp-b2b-portal' )
		);
	}

	/**
	 * Purchase-order history for the buyer's whole company — every PO
	 * raised by any colleague, at any location, in any geography.
	 */
	protected static function render_orders_panel() {
		$company_id = (int) Sycomp_B2B_User::get_company();
		$orders     = wc_get_orders(
			array(
				'status'  => array( Sycomp_B2B_PO::STATUS_OPEN, Sycomp_B2B_PO::STATUS_PROCESS, Sycomp_B2B_PO::STATUS_CLOSED, Sycomp_B2B_PO::STATUS_CANCELLED ),
				'limit'   => 200,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$orders = array_values(
			array_filter(
				$orders,
				static function ( $order ) use ( $company_id ) {
					return (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY ) === $company_id;
				}
			)
		);
		?>
		<section class="sy-panel">
			<h2 class="sy-panel__title"><?php esc_html_e( 'Company purchase orders', 'sycomp-b2b-portal' ); ?></h2>
			<div class="sy-panel__body">
				<?php if ( empty( $orders ) ) : ?>
					<div class="sy-notice"><?php esc_html_e( 'No purchase orders have been raised for your company yet.', 'sycomp-b2b-portal' ); ?></div>
				<?php else : ?>
					<p class="sy-muted"><?php esc_html_e( 'Every purchase order for your company, across all locations, raised by any team member.', 'sycomp-b2b-portal' ); ?></p>
					<table class="sy-table sy-table--stack">
						<thead>
							<tr>
								<th><?php esc_html_e( 'PO Number', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Date', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Location', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Raised by', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Status', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Items', 'sycomp-b2b-portal' ); ?></th>
								<th><?php esc_html_e( 'Total', 'sycomp-b2b-portal' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $orders as $order ) :
								self::render_order_row( $order );
							endforeach;
							?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * A single PO history row.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_order_row( $order ) {
		$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$market      = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$status      = $order->get_status();
		$po_ref      = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );

		// Total formatted in the order's own market currency.
		if ( $market && Sycomp_B2B_Markets::exists( $market ) ) {
			$total = Sycomp_B2B_Pricing::format_in_market( $order->get_total(), $market );
		} else {
			$total = $order->get_formatted_order_total();
		}

		$badge_class = Sycomp_B2B_PO::status_badge_class( $status );
		$status_name = Sycomp_B2B_PO::is_po( $order )
			? Sycomp_B2B_PO::status_label( $status )
			: wc_get_order_status_name( $status );
		?>
		<tr>
			<td>
				<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
				<?php if ( $po_ref ) : ?>
					<span class="sy-prod-sku"><?php echo esc_html( $po_ref ); ?></span>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
			<td><?php echo $location_id ? esc_html( get_the_title( $location_id ) ) : '—'; ?></td>
			<td>
				<?php
				$sy_raiser   = '';
				$sy_customer = $order->get_customer_id();
				if ( $sy_customer ) {
					$sy_u = get_userdata( $sy_customer );
					if ( $sy_u ) {
						$sy_raiser = $sy_u->display_name;
					}
				}
				if ( '' === trim( $sy_raiser ) ) {
					$sy_raiser = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				}
				if ( '' === trim( $sy_raiser ) ) {
					$sy_raiser = $order->get_billing_email();
				}
				echo esc_html( '' !== trim( $sy_raiser ) ? $sy_raiser : '—' );
				?>
			</td>
			<td><span class="sy-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $status_name ); ?></span></td>
			<td><?php echo esc_html( $order->get_item_count() ); ?></td>
			<td><?php echo wp_kses_post( $total ); ?></td>
			<td class="sy-order-actions">
				<a class="sy-btn sy-btn--ghost sy-btn--sm" href="<?php echo esc_url( add_query_arg( 'sycomp_order', $order->get_id(), sycomp_b2b_page_url( 'orders' ) ) ); ?>"><?php esc_html_e( 'View', 'sycomp-b2b-portal' ); ?></a>
				<a class="sy-btn sy-btn--ghost sy-btn--sm" href="<?php echo esc_url( Sycomp_B2B_PO_PDF::pdf_url( $order ) ); ?>"><?php esc_html_e( 'PDF', 'sycomp-b2b-portal' ); ?></a>
			</td>
		</tr>
		<?php
	}
}
