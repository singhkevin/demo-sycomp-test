<?php
/**
 * Admin: purchase-order review and the Open -> In-Process -> Closed
 * lifecycle, with Cancelled as the exit.
 *
 * Provides the Purchase Orders review screen and the Accept / Close /
 * Cancel actions on the native WooCommerce order screen.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Admin_PO.
 */
class Sycomp_B2B_Admin_PO {

	const PAGE  = 'sycomp-b2b-po';
	const NONCE = 'sycomp_po_action';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_action' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_sycomp_accept_po', array( __CLASS__, 'action_accept' ) );
		add_action( 'woocommerce_order_action_sycomp_close_po', array( __CLASS__, 'action_close' ) );
		add_action( 'woocommerce_order_action_sycomp_cancel_po', array( __CLASS__, 'action_cancel' ) );
	}

	/**
	 * Register the Purchase Orders submenu.
	 */
	public static function register_menu() {
		add_submenu_page(
			Sycomp_B2B_Admin::MENU_SLUG,
			__( 'Purchase Orders', 'sycomp-b2b-portal' ),
			__( 'Purchase Orders', 'sycomp-b2b-portal' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Actions.
	 * ------------------------------------------------------------------ */

	/**
	 * Handle an Accept / Close / Cancel submission from the review screen.
	 */
	public static function maybe_handle_action() {
		if ( empty( $_POST['sycomp_po_action'] ) || empty( $_POST['sycomp_po_order'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_POST['sycomp_po_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_po_nonce'] ) ), self::NONCE ) ) {
			return;
		}

		$order_id = absint( wp_unslash( $_POST['sycomp_po_order'] ) );
		$action   = sanitize_key( wp_unslash( $_POST['sycomp_po_action'] ) );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$result = 'none';
		if ( 'accept' === $action ) {
			self::accept( $order );
			$result = 'accepted';
		} elseif ( 'close' === $action ) {
			self::close( $order );
			$result = 'closed';
		} elseif ( 'cancel' === $action ) {
			self::cancel( $order );
			$result = 'cancelled';
		}

		$redirect = add_query_arg(
			array(
				'page'        => self::PAGE,
				'sycomp_done' => $result,
				'order'       => $order_id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Accept an Open purchase order — moves it to In-Process.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function accept( $order ) {
		if ( ! $order->has_status( Sycomp_B2B_PO::STATUS_OPEN ) ) {
			return;
		}
		$order->update_status(
			Sycomp_B2B_PO::STATUS_PROCESS,
			__( 'Purchase order accepted by Sycomp; now in process.', 'sycomp-b2b-portal' )
		);
		self::notify_buyer( $order, 'accepted' );
	}

	/**
	 * Close an In-Process purchase order — marks it complete.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function close( $order ) {
		if ( ! $order->has_status( Sycomp_B2B_PO::STATUS_PROCESS ) ) {
			return;
		}
		$order->update_status(
			Sycomp_B2B_PO::STATUS_CLOSED,
			__( 'Purchase order completed and closed by Sycomp.', 'sycomp-b2b-portal' )
		);
		self::notify_buyer( $order, 'closed' );
	}

	/**
	 * Cancel an Open or In-Process purchase order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function cancel( $order ) {
		if ( ! $order->has_status( array( Sycomp_B2B_PO::STATUS_OPEN, Sycomp_B2B_PO::STATUS_PROCESS ) ) ) {
			return;
		}
		$order->update_status(
			Sycomp_B2B_PO::STATUS_CANCELLED,
			__( 'Purchase order cancelled by Sycomp.', 'sycomp-b2b-portal' )
		);
		self::notify_buyer( $order, 'cancelled' );
	}

	/**
	 * Email the buyer about a PO transition.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $what  'accepted', 'closed' or 'cancelled'.
	 */
	protected static function notify_buyer( $order, $what ) {
		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}
		$number = $order->get_order_number();

		if ( 'accepted' === $what ) {
			/* translators: %s: PO number. */
			$subject = sprintf( __( 'Your purchase order #%s has been accepted', 'sycomp-b2b-portal' ), $number );
			$body    = __( 'Sycomp has accepted your purchase order. It is now in process and our team will be in touch regarding fulfilment.', 'sycomp-b2b-portal' );
		} elseif ( 'closed' === $what ) {
			/* translators: %s: PO number. */
			$subject = sprintf( __( 'Your purchase order #%s is complete', 'sycomp-b2b-portal' ), $number );
			$body    = __( 'Your purchase order has been completed and closed by Sycomp.', 'sycomp-b2b-portal' );
		} else {
			/* translators: %s: PO number. */
			$subject = sprintf( __( 'Your purchase order #%s has been cancelled', 'sycomp-b2b-portal' ), $number );
			$body    = __( 'Your purchase order has been cancelled by Sycomp. Please contact your Sycomp representative for details.', 'sycomp-b2b-portal' );
		}

		$body .= "\n\n" . sprintf(
			/* translators: %s: order view URL. */
			__( 'View your purchase order: %s', 'sycomp-b2b-portal' ),
			$order->get_view_order_url()
		);

		wp_mail( $email, $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * Native order screen integration.
	 * ------------------------------------------------------------------ */

	/**
	 * Add the lifecycle actions to the Order actions dropdown.
	 *
	 * @param array $actions Order actions.
	 * @return array
	 */
	public static function order_actions( $actions ) {
		global $theorder;
		if ( ! $theorder instanceof WC_Order ) {
			return $actions;
		}
		if ( $theorder->has_status( Sycomp_B2B_PO::STATUS_OPEN ) ) {
			$actions['sycomp_accept_po'] = __( 'Accept purchase order (In-Process)', 'sycomp-b2b-portal' );
			$actions['sycomp_cancel_po'] = __( 'Cancel purchase order', 'sycomp-b2b-portal' );
		} elseif ( $theorder->has_status( Sycomp_B2B_PO::STATUS_PROCESS ) ) {
			$actions['sycomp_close_po']  = __( 'Close purchase order (complete)', 'sycomp-b2b-portal' );
			$actions['sycomp_cancel_po'] = __( 'Cancel purchase order', 'sycomp-b2b-portal' );
		}
		return $actions;
	}

	/**
	 * Order-action callback: accept.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_accept( $order ) {
		self::accept( $order );
	}

	/**
	 * Order-action callback: close.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_close( $order ) {
		self::close( $order );
	}

	/**
	 * Order-action callback: cancel.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_cancel( $order ) {
		self::cancel( $order );
	}

	/* ---------------------------------------------------------------------
	 * Review screen.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the Purchase Orders review screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sycomp-b2b-portal' ) );
		}

		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Sycomp_B2B_PO::STATUS_OPEN; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs   = array(
			Sycomp_B2B_PO::STATUS_OPEN      => __( 'Open', 'sycomp-b2b-portal' ),
			Sycomp_B2B_PO::STATUS_PROCESS   => __( 'In-Process', 'sycomp-b2b-portal' ),
			Sycomp_B2B_PO::STATUS_CLOSED    => __( 'Closed', 'sycomp-b2b-portal' ),
			Sycomp_B2B_PO::STATUS_CANCELLED => __( 'Cancelled', 'sycomp-b2b-portal' ),
			'all'                           => __( 'All', 'sycomp-b2b-portal' ),
		);
		if ( ! isset( $tabs[ $filter ] ) ) {
			$filter = Sycomp_B2B_PO::STATUS_OPEN;
		}

		$status_arg = ( 'all' === $filter )
			? array( Sycomp_B2B_PO::STATUS_OPEN, Sycomp_B2B_PO::STATUS_PROCESS, Sycomp_B2B_PO::STATUS_CLOSED, Sycomp_B2B_PO::STATUS_CANCELLED )
			: $filter;

		$orders = wc_get_orders(
			array(
				'status'  => $status_arg,
				'limit'   => 200,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		?>
		<div class="wrap sycomp-admin">
			<h1><?php esc_html_e( 'Purchase Orders', 'sycomp-b2b-portal' ); ?></h1>

			<?php self::render_done_notice(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo ( $key === $filter ) ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&status=' . $key ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( empty( $orders ) ) : ?>
				<p><?php esc_html_e( 'No purchase orders in this view.', 'sycomp-b2b-portal' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'PO #', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Date', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Company', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Location', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Market', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Buyer', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Items', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Total', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Status', 'sycomp-b2b-portal' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'sycomp-b2b-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) : ?>
							<?php self::render_row( $order ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a single PO row.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_row( $order ) {
		$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$market      = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$status      = $order->get_status();

		$total = ( $market && Sycomp_B2B_Markets::exists( $market ) )
			? Sycomp_B2B_Pricing::format_in_market( $order->get_total(), $market )
			: $order->get_formatted_order_total();

		$is_open    = $order->has_status( Sycomp_B2B_PO::STATUS_OPEN );
		$is_process = $order->has_status( Sycomp_B2B_PO::STATUS_PROCESS );
		?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a>
				<?php
				$po_ref = $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
				if ( $po_ref ) {
					echo '<br><small>' . esc_html( $po_ref ) . '</small>';
				}
				?>
			</td>
			<td><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></td>
			<td><?php echo $company_id ? esc_html( get_the_title( $company_id ) ) : '-'; ?></td>
			<td><?php echo $location_id ? esc_html( get_the_title( $location_id ) ) : '-'; ?></td>
			<td><?php echo $market ? esc_html( Sycomp_B2B_Markets::label( $market ) ) : '-'; ?></td>
			<td><?php echo esc_html( $order->get_formatted_billing_full_name() ? $order->get_formatted_billing_full_name() : $order->get_billing_email() ); ?></td>
			<td><?php echo esc_html( $order->get_item_count() ); ?></td>
			<td><?php echo wp_kses_post( $total ); ?></td>
			<td>
				<span class="sycomp-pill sycomp-pill--<?php echo esc_attr( str_replace( 'po-', '', $status ) ); ?>">
					<?php echo esc_html( Sycomp_B2B_PO::status_label( $status ) ); ?>
				</span>
			</td>
			<td>
				<a class="button button-small" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
					<?php esc_html_e( 'View', 'sycomp-b2b-portal' ); ?>
				</a>
				<?php if ( $is_open || $is_process ) : ?>
					<form method="post" style="display:inline">
						<?php wp_nonce_field( self::NONCE, 'sycomp_po_nonce' ); ?>
						<input type="hidden" name="sycomp_po_order" value="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php if ( $is_open ) : ?>
							<button type="submit" name="sycomp_po_action" value="accept" class="button button-primary button-small">
								<?php esc_html_e( 'Accept', 'sycomp-b2b-portal' ); ?>
							</button>
						<?php else : ?>
							<button type="submit" name="sycomp_po_action" value="close" class="button button-primary button-small">
								<?php esc_html_e( 'Close', 'sycomp-b2b-portal' ); ?>
							</button>
						<?php endif; ?>
						<button type="submit" name="sycomp_po_action" value="cancel" class="button button-small"
							onclick="return confirm('<?php echo esc_js( __( 'Cancel this purchase order?', 'sycomp-b2b-portal' ) ); ?>');">
							<?php esc_html_e( 'Cancel', 'sycomp-b2b-portal' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the result notice after a PO action.
	 */
	protected static function render_done_notice() {
		if ( empty( $_GET['sycomp_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$done  = sanitize_key( wp_unslash( $_GET['sycomp_done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$order = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$map = array(
			/* translators: %s: PO number. */
			'accepted'  => __( 'Purchase order #%s accepted — it is now In-Process. The buyer has been notified.', 'sycomp-b2b-portal' ),
			/* translators: %s: PO number. */
			'closed'    => __( 'Purchase order #%s closed. The buyer has been notified.', 'sycomp-b2b-portal' ),
			/* translators: %s: PO number. */
			'cancelled' => __( 'Purchase order #%s cancelled. The buyer has been notified.', 'sycomp-b2b-portal' ),
		);
		if ( ! isset( $map[ $done ] ) ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf( $map[ $done ], $order ) )
			. '</p></div>';
	}
}
