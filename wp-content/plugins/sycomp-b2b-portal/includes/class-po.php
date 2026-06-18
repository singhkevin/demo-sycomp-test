<?php
/**
 * Purchase Order workflow.
 *
 * The portal does not take payment. A buyer builds a cart for one location
 * and submits it as a Purchase Order, which is created as a WooCommerce
 * order in the custom "PO — In Review" status. Sycomp staff review the PO
 * from the admin and approve or reject it.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_PO.
 */
class Sycomp_B2B_PO {

	const STATUS_OPEN      = 'po-open';
	const STATUS_PROCESS   = 'po-process';
	const STATUS_CLOSED    = 'po-closed';
	const STATUS_CANCELLED = 'po-cancelled';

	const GATEWAY_ID = 'sycomp_po';

	/**
	 * Order meta keys.
	 */
	const META_LOCATION  = '_sycomp_location_id';
	const META_MARKET    = '_sycomp_market';
	const META_COMPANY   = '_sycomp_company_id';
	const META_PO_REF    = '_sycomp_po_reference';
	const META_DELIVERY  = '_sycomp_delivery_address';

	/**
	 * Embedded images for the current outgoing PO email.
	 *
	 * @var array
	 */
	private static $embedded_images = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Custom order statuses.
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses_to_list' ) );
		add_filter( 'wc_order_is_editable', array( __CLASS__, 'review_is_editable' ), 10, 2 );

		// Payment gateway (the only one available on the portal).
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_po_gateway' ) );

		// No payment language and no coupon codes - a purchase order is not a sale.
		add_filter( 'woocommerce_coupons_enabled', '__return_false' );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'filter_order_totals' ), 10, 2 );

		// Checkout customisation.
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'po_reference_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_location_to_order' ), 10, 2 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'customise_checkout_fields' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'thankyou_text' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'checkout_address_selector_script' ) );

		// Send email when PO is submitted (generated).
		add_action( 'woocommerce_order_status_' . self::STATUS_OPEN, array( __CLASS__, 'send_po_generated_email' ), 10, 2 );

		// Guard the checkout.
		add_action( 'template_redirect', array( __CLASS__, 'guard_checkout' ) );
	}

	/* ---------------------------------------------------------------------
	 * Order statuses.
	 * ------------------------------------------------------------------ */

	/**
	 * Register the three PO post statuses.
	 */
	public static function register_statuses() {
		$statuses = array(
			self::STATUS_OPEN      => _x( 'PO: Open', 'Order status', 'sycomp-b2b-portal' ),
			self::STATUS_PROCESS   => _x( 'PO: In-Process', 'Order status', 'sycomp-b2b-portal' ),
			self::STATUS_CLOSED    => _x( 'PO: Closed', 'Order status', 'sycomp-b2b-portal' ),
			self::STATUS_CANCELLED => _x( 'PO: Cancelled', 'Order status', 'sycomp-b2b-portal' ),
		);

		foreach ( $statuses as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: order count. */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'sycomp-b2b-portal' ),
				)
			);
		}
	}

	/**
	 * Add the PO statuses to the WooCommerce order-status list.
	 *
	 * @param array $statuses Existing statuses.
	 * @return array
	 */
	public static function add_statuses_to_list( $statuses ) {
		$po = array(
			'wc-' . self::STATUS_OPEN      => _x( 'PO: Open', 'Order status', 'sycomp-b2b-portal' ),
			'wc-' . self::STATUS_PROCESS   => _x( 'PO: In-Process', 'Order status', 'sycomp-b2b-portal' ),
			'wc-' . self::STATUS_CLOSED    => _x( 'PO: Closed', 'Order status', 'sycomp-b2b-portal' ),
			'wc-' . self::STATUS_CANCELLED => _x( 'PO: Cancelled', 'Order status', 'sycomp-b2b-portal' ),
		);

		// Insert the PO statuses right after "pending".
		$merged = array();
		foreach ( $statuses as $key => $label ) {
			$merged[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$merged = array_merge( $merged, $po );
			}
		}
		// If pending was not present, append.
		foreach ( $po as $key => $label ) {
			if ( ! isset( $merged[ $key ] ) ) {
				$merged[ $key ] = $label;
			}
		}
		return $merged;
	}

	/**
	 * Keep PO-in-review orders editable in the admin.
	 *
	 * @param bool     $editable Whether editable.
	 * @param WC_Order $order    Order.
	 * @return bool
	 */
	public static function review_is_editable( $editable, $order ) {
		if ( $order && $order->has_status( self::STATUS_OPEN ) ) {
			return true;
		}
		return $editable;
	}

	/* ---------------------------------------------------------------------
	 * Payment gateway.
	 * ------------------------------------------------------------------ */

	/**
	 * Register the Purchase Order gateway class.
	 *
	 * The gateway file is required here (inside the WooCommerce filter) so
	 * that the abstract WC_Payment_Gateway class is guaranteed to be loaded
	 * before our subclass is declared.
	 *
	 * @param array $gateways Gateway class names.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		require_once SYCOMP_B2B_DIR . 'includes/class-po-gateway.php';
		$gateways[] = 'Sycomp_B2B_PO_Gateway';
		return $gateways;
	}

	/**
	 * On the portal, the PO gateway is the only payment option.
	 *
	 * @param array $available Available gateways.
	 * @return array
	 */
	public static function only_po_gateway( $available ) {
		if ( isset( $available[ self::GATEWAY_ID ] ) ) {
			return array( self::GATEWAY_ID => $available[ self::GATEWAY_ID ] );
		}
		return $available;
	}

	/* ---------------------------------------------------------------------
	 * Checkout customisation.
	 * ------------------------------------------------------------------ */

	/**
	 * Rename the place-order button.
	 *
	 * @param string $text Button text.
	 * @return string
	 */
	public static function order_button_text( $text ) {
		return __( 'Submit Purchase Order', 'sycomp-b2b-portal' );
	}

	/**
	 * Add a "Your PO reference" field to checkout.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public static function po_reference_field( $checkout ) {
		echo '<div class="sy-po-reference">';
		woocommerce_form_field(
			'sycomp_po_reference',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-wide' ),
				'label'       => __( 'Your PO reference', 'sycomp-b2b-portal' ),
				'placeholder' => __( 'e.g. internal requisition number', 'sycomp-b2b-portal' ),
				'required'    => false,
			),
			$checkout->get_value( 'sycomp_po_reference' )
		);
		echo '</div>';
	}

	/**
	 * Stamp the order with the active location, market, company and PO ref.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 */
	public static function attach_location_to_order( $order, $data ) {
		$location_id = Sycomp_B2B_Context::get_active_location_id();
		$market      = Sycomp_B2B_Context::get_active_market();
		$company_id  = Sycomp_B2B_User::get_company();

		if ( $location_id ) {
			$order->update_meta_data( self::META_LOCATION, $location_id );
		}
		if ( $market ) {
			$order->update_meta_data( self::META_MARKET, $market );
		}
		if ( $company_id ) {
			$order->update_meta_data( self::META_COMPANY, $company_id );
		}

		if ( ! empty( $_POST['sycomp_po_reference'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$order->update_meta_data(
				self::META_PO_REF,
				sanitize_text_field( wp_unslash( $_POST['sycomp_po_reference'] ) )
			);
		}

		// Set order billing address from the location's billing address.
		if ( $location_id ) {
			$billing = Sycomp_B2B_Post_Types::get_location_billing_address( $location_id );
			if ( empty( $billing ) && $company_id ) {
				$billing = Sycomp_B2B_Post_Types::get_company_billing_address( $company_id );
			}
			if ( ! empty( $billing ) ) {
				$order->set_billing_address_1( str_replace( array( "\r\n", "\r", "\n" ), ', ', $billing ) );
			}
		}

		// Delivery address - defaults to the location, editable at checkout.
		if ( isset( $_POST['sycomp_delivery_address'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$delivery = sanitize_textarea_field( wp_unslash( $_POST['sycomp_delivery_address'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' !== trim( $delivery ) ) {
				$order->update_meta_data( self::META_DELIVERY, $delivery );
			}
		}
		if ( $company_id ) {
			$order->set_billing_company( (string) Sycomp_B2B_User::get_company_name() );
		}

		$order->set_created_via( 'sycomp-b2b-portal' );
	}

	/**
	 * Replace the structured billing address fields with a single, editable
	 * "Delivery address" box, pre-filled from the buyer's active location.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public static function customise_checkout_fields( $fields ) {
		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			return $fields;
		}

		foreach ( array(
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
		) as $remove ) {
			unset( $fields['billing'][ $remove ] );
		}

		$location_id = Sycomp_B2B_Context::get_active_location_id();
		$drop_addresses = $location_id ? Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $location_id ) : array();
		$msp_addresses = $location_id ? Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $location_id ) : array();

		// Default fallback
		$default = '';
		if ( ! empty( $drop_addresses ) ) {
			$default = $drop_addresses[0];
		} elseif ( ! empty( $msp_addresses ) ) {
			$default = $msp_addresses[0];
		} else {
			$default = $location_id ? Sycomp_B2B_Post_Types::get_location_address( $location_id ) : '';
		}

		// Build choices for the dropdown
		$options = array(
			'' => __( '— Select predefined address (or enter custom below) —', 'sycomp-b2b-portal' ),
		);

		foreach ( $msp_addresses as $index => $addr ) {
			if ( ! empty( trim( $addr ) ) ) {
				$options[ trim( $addr ) ] = sprintf( __( 'MSP Address %d: %s', 'sycomp-b2b-portal' ), $index + 1, esc_html( wp_strip_all_tags( $addr ) ) );
			}
		}

		foreach ( $drop_addresses as $index => $addr ) {
			if ( ! empty( trim( $addr ) ) ) {
				$options[ trim( $addr ) ] = sprintf( __( 'Drop Shipping Address %d: %s', 'sycomp-b2b-portal' ), $index + 1, esc_html( wp_strip_all_tags( $addr ) ) );
			}
		}

		$options['custom'] = __( 'Custom shipping address (enter below)', 'sycomp-b2b-portal' );

		// Add select field if we have predefined addresses
		if ( count( $options ) > 2 ) {
			$fields['billing']['sycomp_delivery_address_select'] = array(
				'type'        => 'select',
				'label'       => __( 'Predefined Delivery Addresses', 'sycomp-b2b-portal' ),
				'required'    => false,
				'class'       => array( 'form-row-wide' ),
				'priority'    => 24,
				'options'     => $options,
				'default'     => $default,
			);
		}

		$fields['billing']['sycomp_delivery_address'] = array(
			'type'              => 'textarea',
			'label'             => __( 'Delivery address', 'sycomp-b2b-portal' ),
			'required'          => true,
			'class'             => array( 'form-row-wide' ),
			'priority'          => 25,
			'default'           => $default,
			'placeholder'       => __( 'Where this purchase order should be delivered', 'sycomp-b2b-portal' ),
			'custom_attributes' => array( 'rows' => '4' ),
		);

		return $fields;
	}

	/**
	 * Remove the "Payment method" row from order totals - POs carry no payment.
	 *
	 * @param array    $total_rows Order total rows.
	 * @param WC_Order $order      Order.
	 * @return array
	 */
	public static function filter_order_totals( $total_rows, $order ) {
		unset( $total_rows['payment_method'] );
		return $total_rows;
	}

	/**
	 * Replace the order-received message for a submitted PO.
	 *
	 * @param string   $text  Default text.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function thankyou_text( $text, $order ) {
		if ( $order && $order->has_status( self::STATUS_OPEN ) ) {
			return __( 'Your purchase order has been submitted and is now awaiting review by Sycomp. You can track its status under My Account.', 'sycomp-b2b-portal' );
		}
		return $text;
	}

	/**
	 * Block checkout for users who are not portal buyers or have no location.
	 */
	public static function guard_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// The order-received (thank you) endpoint must stay reachable.
		if ( is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( wc_get_checkout_url() ) );
			exit;
		}
		if ( ! Sycomp_B2B_User::is_portal_user() || ! Sycomp_B2B_Context::has_active_location() ) {
			wc_add_notice( __( 'Select a company location before submitting a purchase order.', 'sycomp-b2b-portal' ), 'error' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/* ---------------------------------------------------------------------
	 * Status helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether an order is one of the PO statuses.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_po( $order ) {
		return $order instanceof WC_Order && $order->has_status(
			array( self::STATUS_OPEN, self::STATUS_PROCESS, self::STATUS_CLOSED, self::STATUS_CANCELLED )
		);
	}

	/**
	 * Whether a user may view a purchase order.
	 *
	 * Sycomp staff may view any PO. A buyer may view every PO belonging to
	 * their own company — raised by any colleague, at any location, in any
	 * geography — but never another company's.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $user_id User ID. Defaults to the current user.
	 * @return bool
	 */
	public static function user_can_view( $order, $user_id = 0 ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return true;
		}
		$company = (int) Sycomp_B2B_User::get_company( $user_id );
		return $company > 0 && (int) $order->get_meta( self::META_COMPANY ) === $company;
	}

	/**
	 * Human label for a PO status slug.
	 *
	 * @param string $status Status slug (no wc- prefix).
	 * @return string
	 */
	public static function status_label( $status ) {
		$map = array(
			self::STATUS_OPEN      => __( 'Open', 'sycomp-b2b-portal' ),
			self::STATUS_PROCESS   => __( 'In-Process', 'sycomp-b2b-portal' ),
			self::STATUS_CLOSED    => __( 'Closed', 'sycomp-b2b-portal' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'sycomp-b2b-portal' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : wc_get_order_status_name( $status );
	}

	/**
	 * CSS badge modifier for a PO status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_badge_class( $status ) {
		switch ( $status ) {
			case self::STATUS_OPEN:
				return 'sy-badge--open';
			case self::STATUS_PROCESS:
				return 'sy-badge--process';
			case self::STATUS_CLOSED:
				return 'sy-badge--closed';
			case self::STATUS_CANCELLED:
				return 'sy-badge--cancelled';
			default:
				return 'sy-badge--draft';
		}
	}

	/**
	 * Embed images in PHPMailer.
	 *
	 * @param PHPMailer $phpmailer PHPMailer instance.
	 */
	public static function phpmailer_embed_images( $phpmailer ) {
		foreach ( self::$embedded_images as $cid => $file_path ) {
			if ( file_exists( $file_path ) ) {
				$phpmailer->addEmbeddedImage( $file_path, $cid );
			}
		}
	}

	/**
	 * Send email when a PO is generated (status becomes po-open).
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public static function send_po_generated_email( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		// Prevent duplicate email sending for the same transition.
		if ( $order->get_meta( '_sycomp_po_generated_email_sent' ) ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$buyer_name = $order->get_formatted_billing_full_name();
		if ( ! $buyer_name ) {
			$buyer_name = $email;
		}

		$number = $order->get_order_number();

		// Format date and time.
		$date_created = $order->get_date_created();
		$date_str     = $date_created ? $date_created->date_i18n( 'M d' ) : '';
		$time_str     = $date_created ? $date_created->date_i18n( 'g:i a' ) : '';

		// Subject.
		$subject = sprintf( __( 'Purchase order #%s submitted', 'sycomp-b2b-portal' ), $number );

		// Reset embedded images array.
		self::$embedded_images = array();

		// Resolve logo file path and embed it.
		$logo_path      = '';
		$logo_cid       = 'sycomp-logo';
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo_path = get_attached_file( $custom_logo_id );
		}
		if ( ! $logo_path || ! file_exists( $logo_path ) ) {
			$logo_path = get_template_directory() . '/assets/img/sycomp-logo-color.png';
		}

		if ( file_exists( $logo_path ) ) {
			self::$embedded_images[ $logo_cid ] = $logo_path;
			$logo_html = '<img src="cid:' . $logo_cid . '" alt="Sycomp" style="max-height: 40px; border: 0;" />';
		} else {
			$logo_html = '<span style="font-size: 20px; font-weight: bold; color: #0c1c3c;">Sycomp</span>';
		}

		// Items list HTML.
		$items_html = '';
		$market     = $order->get_meta( self::META_MARKET );

		foreach ( $order->get_items() as $item_id => $item ) {
			$product  = $item->get_product();
			$qty      = $item->get_quantity();
			$subtotal = $item->get_subtotal();

			$unit_price = $subtotal / ( $qty ? $qty : 1 );

			if ( class_exists( 'Sycomp_B2B_Pricing' ) && method_exists( 'Sycomp_B2B_Pricing', 'format_in_market' ) ) {
				$formatted_unit_price = wp_strip_all_tags( Sycomp_B2B_Pricing::format_in_market( $unit_price, $market ) );
				$formatted_subtotal   = wp_strip_all_tags( Sycomp_B2B_Pricing::format_in_market( $subtotal, $market ) );
			} else {
				$formatted_unit_price = wp_strip_all_tags( wc_price( $unit_price, array( 'currency' => $order->get_currency() ) ) );
				$formatted_subtotal   = wp_strip_all_tags( wc_price( $subtotal, array( 'currency' => $order->get_currency() ) ) );
			}

			$sku      = $product ? $product->get_sku() : '';
			$sku_html = $sku ? '<div style="font-size: 13px; color: #5b6472; margin-top: 4px;">SKU: ' . esc_html( $sku ) . '</div>' : '';

			// Product Image inline embedding.
			$img_html = '';
			$img_cid  = 'product-image-' . $item_id;
			$img_path = '';

			if ( $product ) {
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$img_src_arr = wp_get_attachment_image_src( $image_id, 'thumbnail' );
					if ( $img_src_arr ) {
						$upload_dir = wp_upload_dir();
						$base_url   = $upload_dir['baseurl'];
						$base_path  = $upload_dir['basedir'];
						$img_path   = str_replace( $base_url, $base_path, $img_src_arr[0] );
					}
					if ( ! $img_path || ! file_exists( $img_path ) ) {
						$img_path = get_attached_file( $image_id );
					}
				}
			}

			if ( $img_path && file_exists( $img_path ) ) {
				self::$embedded_images[ $img_cid ] = $img_path;
				$img_html = '<img src="cid:' . $img_cid . '" alt="" style="width: 64px; height: 64px; border: 1px solid #e3e6eb; border-radius: 4px; object-fit: contain; display: block;" />';
			} else {
				// Grey placeholder box.
				$img_html = '<div style="width: 64px; height: 64px; border: 1px solid #e3e6eb; border-radius: 4px; background-color: #f6f7f9; display: block;"></div>';
			}

			$items_html .= '
			<tr>
				<td style="padding: 16px 0; vertical-align: top; width: 80px; border-bottom: 1px solid #e3e6eb;">
					' . $img_html . '
				</td>
				<td style="padding: 16px 0; vertical-align: top; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; border-bottom: 1px solid #e3e6eb;">
					<div style="font-size: 14px; font-weight: 600; color: #1a1f29; line-height: 1.4;">' . esc_html( $item->get_name() ) . '</div>
					<div style="font-size: 13px; color: #5b6472; margin-top: 4px;">' . esc_html( $formatted_unit_price ) . ' &times; ' . intval( $qty ) . '</div>
					' . $sku_html . '
				</td>
				<td style="padding: 16px 0; vertical-align: top; text-align: right; font-size: 14px; font-weight: 500; color: #1a1f29; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; border-bottom: 1px solid #e3e6eb;">
					' . esc_html( $formatted_subtotal ) . '
				</td>
			</tr>';
		}

		// Totals formatting.
		$order_subtotal = $order->get_subtotal();
		$order_shipping = $order->get_shipping_total();
		$order_total    = $order->get_total();
		$currency       = $order->get_currency();

		if ( class_exists( 'Sycomp_B2B_Pricing' ) && method_exists( 'Sycomp_B2B_Pricing', 'format_in_market' ) ) {
			$formatted_order_subtotal = wp_strip_all_tags( Sycomp_B2B_Pricing::format_in_market( $order_subtotal, $market ) );
			$formatted_order_shipping = wp_strip_all_tags( Sycomp_B2B_Pricing::format_in_market( $order_shipping, $market ) );
			$formatted_order_total    = wp_strip_all_tags( Sycomp_B2B_Pricing::format_in_market( $order_total, $market ) );
		} else {
			$formatted_order_subtotal = wp_strip_all_tags( wc_price( $order_subtotal, array( 'currency' => $currency ) ) );
			$formatted_order_shipping = wp_strip_all_tags( wc_price( $order_shipping, array( 'currency' => $currency ) ) );
			$formatted_order_total    = wp_strip_all_tags( wc_price( $order_total, array( 'currency' => $currency ) ) );
		}

		// Construct HTML body.
		$body = '
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>' . esc_html( $subject ) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f7f9; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
	<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f6f7f9; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
		<tr>
			<td align="center" style="padding: 40px 16px;">
				<table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 560px;">
					<!-- Card Container -->
					<tr>
						<td style="background-color: #ffffff; border: 1px solid #e3e6eb; border-radius: 8px; padding: 32px; box-shadow: 0 1px 3px rgba(10,14,22,.05);">
							<table border="0" cellpadding="0" cellspacing="0" width="100%">
								<!-- Header/Logo INSIDE Card -->
								<tr>
									<td style="padding-bottom: 20px; border-bottom: 1px solid #e3e6eb; text-align: left;">
										' . $logo_html . '
									</td>
								</tr>
								<!-- Top Notification Text -->
								<tr>
									<td style="font-size: 15px; line-height: 1.5; color: #1a1f29; padding: 24px 0 20px 0;">
										<strong>' . esc_html( $buyer_name ) . '</strong> submitted purchase order <strong>#' . esc_html( $number ) . '</strong> on ' . esc_html( $date_str ) . ' at ' . esc_html( $time_str ) . '.
									</td>
								</tr>
								<!-- Review Button -->
								<tr>
									<td style="padding-bottom: 24px;">
										<a href="' . esc_url( $order->get_view_order_url() ) . '" style="display: inline-block; background-color: #1e4fd6; color: #ffffff; text-decoration: none; padding: 12px 20px; font-size: 14px; font-weight: 600; border-radius: 4px;">Review purchase order</a>
									</td>
								</tr>
								<!-- Divider -->
								<tr>
									<td style="border-top: 1px solid #e3e6eb; padding-top: 24px;">
										<h3 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; color: #1a1f29;">Order summary</h3>
									</td>
								</tr>
								<!-- Line Items Table -->
								<tr>
									<td>
										<table border="0" cellpadding="0" cellspacing="0" width="100%">
											' . $items_html . '
										</table>
									</td>
								</tr>
								<!-- Totals block -->
								<tr>
									<td style="padding-top: 16px;">
										<table border="0" cellpadding="0" cellspacing="0" width="100%" style="font-size: 14px; color: #5b6472; line-height: 1.5;">
											<tr>
												<td style="padding: 6px 0;">Subtotal</td>
												<td style="padding: 6px 0; text-align: right; color: #1a1f29;">' . esc_html( $formatted_order_subtotal ) . '</td>
											</tr>
											<tr>
												<td style="padding: 6px 0;">Shipping</td>
												<td style="padding: 6px 0; text-align: right; color: #1a1f29;">' . esc_html( $formatted_order_shipping ) . '</td>
											</tr>
											<tr style="font-size: 15px; font-weight: bold; color: #1a1f29;">
												<td style="padding: 12px 0 0 0; border-top: 1px solid #e3e6eb;">Total</td>
												<td style="padding: 12px 0 0 0; border-top: 1px solid #e3e6eb; text-align: right;">' . esc_html( $formatted_order_total ) . ' ' . esc_html( $currency ) . '</td>
											</tr>
										</table>
									</td>
								</tr>
								<!-- Divider -->
								<tr>
									<td style="border-top: 1px solid #e3e6eb; padding-top: 24px;">
										<h3 style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; color: #1a1f29;">Payment</h3>
										<p style="margin: 0; font-size: 14px; color: #5b6472;">Payment method chosen later</p>
									</td>
								</tr>
							</table>
						</td>
					</tr>
					<!-- Footer Meta Info -->
					<tr>
						<td style="padding-top: 24px; text-align: center; font-size: 12px; color: #5b6472;">
							<p style="margin: 0;">This is an automated notification from your Sycomp B2B procurement portal.</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
';

		// Hook into PHPMailer to attach the embedded images.
		add_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_embed_images' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $email, $subject, $body, $headers );

		// Clean up the hook immediately.
		remove_action( 'phpmailer_init', array( __CLASS__, 'phpmailer_embed_images' ) );
		self::$embedded_images = array();

		$order->update_meta_data( '_sycomp_po_generated_email_sent', '1' );
		$order->save_meta_data();
	}

	/**
	 * Output inline Javascript on the checkout page to populate the delivery
	 * address textarea when a predefined address is selected.
	 */
	public static function checkout_address_selector_script() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery(function($) {
			$(document.body).on('change', '#sycomp_delivery_address_select', function() {
				var val = $(this).val();
				if (val && val !== 'custom') {
					$('#sycomp_delivery_address').val(val).trigger('change');
				} else if (val === 'custom') {
					$('#sycomp_delivery_address').val('').trigger('change');
				}
			});
		});
		</script>
		<?php
	}
}
