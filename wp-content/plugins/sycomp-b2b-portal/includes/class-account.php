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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_attach_po_file' ) );
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
		self::render_po_file_notice();
		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && Sycomp_B2B_PO::user_can_view( $order ) ) {
				self::render_order_detail( $order );
			} else {
				echo '<div class="sy-notice sy-notice--warn">'
					. esc_html__( 'That proposal could not be found.', 'sycomp-b2b-portal' )
					. '</div>';
				self::render_orders_panel();
			}
		} else {
			self::render_orders_panel();
		}
		return (string) ob_get_clean();
	}

	/**
	 * Flash notice after a buyer attaches a PO file.
	 */
	protected static function render_po_file_notice() {
		if ( empty( $_GET['sycomp_po_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['sycomp_po_file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'ok'       => __( 'Purchase order file attached.', 'sycomp-b2b-portal' ),
			'invalid'  => __( 'Please upload a PDF or image file.', 'sycomp-b2b-portal' ),
			'error'    => __( 'The file could not be uploaded. Please try again.', 'sycomp-b2b-portal' ),
			'denied'   => __( 'You cannot attach a file to that proposal.', 'sycomp-b2b-portal' ),
		);
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		$class = ( 'ok' === $key ) ? 'sy-notice' : 'sy-notice sy-notice--warn';
		echo '<div class="' . esc_attr( $class ) . '">' . esc_html( $messages[ $key ] ) . '</div>';
	}

	/**
	 * Handle buyer upload of a customer PO file against a proposal.
	 */
	public static function maybe_attach_po_file() {
		if ( empty( $_POST['sycomp_attach_po'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! Sycomp_B2B_User::is_portal_user() ) {
			return;
		}
		if ( empty( $_POST['sycomp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_nonce'] ) ), 'sycomp_attach_po' ) ) {
			return;
		}

		$orders_url = sycomp_b2b_page_url( 'orders' );
		$order_id   = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order      = $order_id ? wc_get_order( $order_id ) : false;

		// Buyers only: must belong to the same company as the proposal.
		$company = (int) Sycomp_B2B_User::get_company();
		if ( ! $order || ! $company || (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY ) !== $company ) {
			wp_safe_redirect( add_query_arg( 'sycomp_po_file', 'denied', $orders_url ) );
			exit;
		}

		if ( empty( $_FILES['sycomp_po_file']['name'] ) ) {
			wp_safe_redirect( add_query_arg( 'sycomp_po_file', 'invalid', $orders_url ) );
			exit;
		}

		$file = $_FILES['sycomp_po_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$ext   = isset( $check['ext'] ) ? strtolower( (string) $check['ext'] ) : '';
		$allowed = array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp' );
		if ( ! $ext || ! in_array( $ext, $allowed, true ) ) {
			wp_safe_redirect( add_query_arg( 'sycomp_po_file', 'invalid', $orders_url ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att_id = media_handle_upload( 'sycomp_po_file', 0 );
		if ( is_wp_error( $att_id ) ) {
			wp_safe_redirect( add_query_arg( 'sycomp_po_file', 'error', $orders_url ) );
			exit;
		}

		$old_id = Sycomp_B2B_PO::po_file_id( $order );
		$order->update_meta_data( Sycomp_B2B_PO::META_PO_FILE, (int) $att_id );
		$order->save();

		if ( $old_id && $old_id !== (int) $att_id ) {
			wp_delete_attachment( $old_id, true );
		}

		wp_safe_redirect( add_query_arg( 'sycomp_po_file', 'ok', $orders_url ) );
		exit;
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
	 * Render a single proposal's detail, inline on the Orders page.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_order_detail( $order ) {
		$orders_url  = sycomp_b2b_page_url( 'orders' );
		$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$market      = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$po_ref      = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
		$quote_id    = Sycomp_B2B_PO::quote_id( $order );
		$country     = Sycomp_B2B_PO::country_label( $order );
		$po_file_url = Sycomp_B2B_PO::po_file_url( $order );
		$po_file_id  = Sycomp_B2B_PO::po_file_id( $order );
		$status      = $order->get_status();
		$status_name = Sycomp_B2B_PO::is_po( $order ) ? Sycomp_B2B_PO::status_label( $status ) : wc_get_order_status_name( $status );
		$badge       = Sycomp_B2B_PO::status_badge_class( $status );

		echo '<p class="sy-back"><a href="' . esc_url( $orders_url ) . '">&larr; ' . esc_html__( 'Back to orders', 'sycomp-b2b-portal' ) . '</a></p>';

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">';
		/* translators: %s: Quote ID. */
		echo esc_html( sprintf( __( 'Proposal %s', 'sycomp-b2b-portal' ), $quote_id ? $quote_id : '#' . $order->get_order_number() ) );
		echo ' <span class="sy-badge ' . esc_attr( $badge ) . '">' . esc_html( $status_name ) . '</span>';
		echo '</h2>';
		echo '<div class="sy-panel__body sy-deflist">';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Quote ID', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $quote_id ? esc_html( $quote_id ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Country', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $country ? esc_html( $country ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Date', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Location', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $location_id ? esc_html( get_the_title( $location_id ) ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'PO reference', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== $po_ref ? esc_html( $po_ref ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Purchase Order', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">';
		if ( $po_file_url ) {
			$label = get_the_title( $po_file_id );
			echo '<a class="sy-link" href="' . esc_url( $po_file_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ? $label : __( 'View file', 'sycomp-b2b-portal' ) ) . '</a>';
		} else {
			echo '-';
		}
		echo '</span></div>';
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
			esc_html__( 'Download proposal (PDF)', 'sycomp-b2b-portal' )
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
			<h2 class="sy-panel__title"><?php esc_html_e( 'Company proposals', 'sycomp-b2b-portal' ); ?></h2>
			<div class="sy-panel__body">
				<?php if ( empty( $orders ) ) : ?>
					<div class="sy-notice"><?php esc_html_e( 'No proposals have been raised for your company yet.', 'sycomp-b2b-portal' ); ?></div>
				<?php else : ?>
					<p class="sy-muted"><?php esc_html_e( 'Every proposal for your company, across all locations, raised by any team member.', 'sycomp-b2b-portal' ); ?></p>
					<div class="sy-table-scroll">
					<table class="sy-table sy-table--stack sy-table--orders">
						<thead>
							<tr>
								<th class="sy-o-col sy-o-col--quote"><?php esc_html_e( 'Quote ID', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--country"><?php esc_html_e( 'Country', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--date"><?php esc_html_e( 'Date', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--status"><?php esc_html_e( 'Status', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--items"><?php esc_html_e( 'Items', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--location"><?php esc_html_e( 'Location', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--raiser"><?php esc_html_e( 'Raised by', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--total"><?php esc_html_e( 'Total', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--act sy-col-act"><?php esc_html_e( 'Action', 'sycomp-b2b-portal' ); ?></th>
								<th class="sy-o-col sy-o-col--po"><?php esc_html_e( 'Purchase Order', 'sycomp-b2b-portal' ); ?></th>
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
					</div>
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
		$quote_id    = Sycomp_B2B_PO::quote_id( $order );
		$country     = Sycomp_B2B_PO::country_label( $order );
		$po_file_url = Sycomp_B2B_PO::po_file_url( $order );
		$po_file_id  = Sycomp_B2B_PO::po_file_id( $order );

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
			<td class="sy-o-col sy-o-col--quote">
				<strong><?php echo esc_html( $quote_id ? $quote_id : '#' . $order->get_order_number() ); ?></strong>
				<?php if ( $po_ref ) : ?>
					<span class="sy-prod-sku"><?php echo esc_html( $po_ref ); ?></span>
				<?php endif; ?>
			</td>
			<td class="sy-o-col sy-o-col--country"><?php echo $country ? esc_html( $country ) : '—'; ?></td>
			<td class="sy-o-col sy-o-col--date"><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
			<td class="sy-o-col sy-o-col--status"><span class="sy-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $status_name ); ?></span></td>
			<td class="sy-o-col sy-o-col--items"><?php echo esc_html( $order->get_item_count() ); ?></td>
			<td class="sy-o-col sy-o-col--location"><span class="sy-o-cell"><?php echo $location_id ? esc_html( get_the_title( $location_id ) ) : '—'; ?></span></td>
			<td class="sy-o-col sy-o-col--raiser"><span class="sy-o-cell"><?php
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
			?></span></td>
			<td class="sy-o-col sy-o-col--total sy-col-price"><?php echo wp_kses_post( $total ); ?></td>
			<td class="sy-o-col sy-o-col--act sy-col-act sy-order-actions">
				<div class="sy-rowact">
					<a class="sy-btn sy-btn--ghost sy-btn--sm" href="<?php echo esc_url( add_query_arg( 'sycomp_order', $order->get_id(), sycomp_b2b_page_url( 'orders' ) ) ); ?>"><?php esc_html_e( 'View', 'sycomp-b2b-portal' ); ?></a>
					<a class="sy-btn sy-btn--ghost sy-btn--sm" href="<?php echo esc_url( Sycomp_B2B_PO_PDF::pdf_url( $order ) ); ?>"><?php esc_html_e( 'PDF', 'sycomp-b2b-portal' ); ?></a>
				</div>
			</td>
			<td class="sy-o-col sy-o-col--po sy-po-attach">
				<?php if ( $po_file_url ) : ?>
					<a class="sy-link" href="<?php echo esc_url( $po_file_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( get_the_title( $po_file_id ) ? get_the_title( $po_file_id ) : __( 'View file', 'sycomp-b2b-portal' ) ); ?>
					</a>
				<?php endif; ?>
				<form class="sy-po-attach__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( sycomp_b2b_page_url( 'orders' ) ); ?>">
					<?php wp_nonce_field( 'sycomp_attach_po', 'sycomp_nonce' ); ?>
					<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
					<input type="hidden" name="sycomp_attach_po" value="1">
					<label class="sy-po-attach__pick">
						<span class="sy-btn sy-btn--ghost sy-btn--sm"><?php echo $po_file_url ? esc_html__( 'Replace', 'sycomp-b2b-portal' ) : esc_html__( 'Attach', 'sycomp-b2b-portal' ); ?></span>
						<input type="file" name="sycomp_po_file" accept=".pdf,image/*,.jpg,.jpeg,.png,.gif,.webp" required aria-label="<?php esc_attr_e( 'Purchase order file', 'sycomp-b2b-portal' ); ?>">
					</label>
					<span class="sy-po-attach__name sy-muted" data-empty="<?php esc_attr_e( 'No file chosen', 'sycomp-b2b-portal' ); ?>"><?php esc_html_e( 'No file chosen', 'sycomp-b2b-portal' ); ?></span>
					<button class="sy-btn sy-btn--primary sy-btn--sm sy-po-attach__submit" type="submit" hidden><?php esc_html_e( 'Upload', 'sycomp-b2b-portal' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}
}
