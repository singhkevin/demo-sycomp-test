<?php
/**
 * The Purchase Order payment gateway.
 *
 * It collects no payment details. "Paying" with it simply files the order
 * as a PO awaiting Sycomp review. This file is loaded lazily by
 * Sycomp_B2B_PO::register_gateway() so that WC_Payment_Gateway is
 * guaranteed to be available before the subclass below is declared.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

/**
 * Class Sycomp_B2B_PO_Gateway.
 */
class Sycomp_B2B_PO_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Sycomp_B2B_PO::GATEWAY_ID;
		$this->method_title       = __( 'Sycomp Purchase Order', 'sycomp-b2b-portal' );
		$this->method_description = __( 'Files the order as a purchase order awaiting Sycomp review. No payment is taken.', 'sycomp-b2b-portal' );
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Submit Purchase Order', 'sycomp-b2b-portal' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Purchase Order', 'sycomp-b2b-portal' ) );
		$this->description = $this->get_option( 'description', __( 'Submit this order as a purchase order. Sycomp will review and confirm it.', 'sycomp-b2b-portal' ) );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'sycomp-b2b-portal' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable the Purchase Order workflow', 'sycomp-b2b-portal' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'   => __( 'Title', 'sycomp-b2b-portal' ),
				'type'    => 'text',
				'default' => __( 'Purchase Order', 'sycomp-b2b-portal' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'sycomp-b2b-portal' ),
				'type'    => 'textarea',
				'default' => __( 'Submit this order as a purchase order. Sycomp will review and confirm it.', 'sycomp-b2b-portal' ),
			),
		);
	}

	/**
	 * Process the "payment" — file the order as a PO in review.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$order->update_status(
			Sycomp_B2B_PO::STATUS_OPEN,
			__( 'Purchase order submitted by buyer; awaiting Sycomp review.', 'sycomp-b2b-portal' )
		);

		// Empty only the active location's cart — other locations keep theirs.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
