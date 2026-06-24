<?php
/**
 * Shop Manager admin — the front-end management application.
 *
 * Renders the [sycomp_manager] shortcode as a multi-section admin behind
 * the theme's top-bar chrome. The section is chosen with ?section=
 * (dashboard | products | categories | po | profile | company) and routed
 * here. Every management task — adding products, reviewing POs, importing
 * data — is handled natively so a Shop Manager never needs wp-admin.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Manager.
 */
class Sycomp_B2B_Manager {

	/**
	 * Rows per page on the Products table.
	 */
	const PER_PAGE = 40;

	/**
	 * Option key holding Sycomp's own company details.
	 */
	const OPTION_COMPANY = 'sycomp_b2b_company';

	/**
	 * Register hooks and the shortcode.
	 */
	public static function init() {
		add_shortcode( 'sycomp_manager', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_export' ), 3 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ), 4 );
	}

	/**
	 * Stream the purchase-order list as a CSV download.
	 */
	public static function maybe_export() {
		if ( empty( $_GET['sycomp_po_export'] ) || ! self::is_manager() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$nonce = isset( $_GET['_sycexp'] ) ? sanitize_text_field( wp_unslash( $_GET['_sycexp'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'sycomp_po_export' ) ) {
			return;
		}

		$stage = isset( $_GET['po'] ) ? sanitize_key( wp_unslash( $_GET['po'] ) ) : 'all';
		$mk    = isset( $_GET['mk'] ) ? sanitize_key( wp_unslash( $_GET['mk'] ) ) : '';
		$co    = isset( $_GET['co'] ) ? absint( wp_unslash( $_GET['co'] ) ) : 0;

		$map = array(
			'open'      => Sycomp_B2B_PO::STATUS_OPEN,
			'process'   => Sycomp_B2B_PO::STATUS_PROCESS,
			'closed'    => Sycomp_B2B_PO::STATUS_CLOSED,
			'cancelled' => Sycomp_B2B_PO::STATUS_CANCELLED,
		);
		$status = isset( $map[ $stage ] ) ? $map[ $stage ] : array_values( $map );

		$orders = wc_get_orders(
			array(
				'status'  => $status,
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		if ( $mk && Sycomp_B2B_Markets::exists( $mk ) ) {
			$orders = array_filter(
				$orders,
				static function ( $o ) use ( $mk ) {
					return (string) $o->get_meta( Sycomp_B2B_PO::META_MARKET ) === $mk;
				}
			);
		}
		if ( $co ) {
			$orders = array_filter(
				$orders,
				static function ( $o ) use ( $co ) {
					return (int) $o->get_meta( Sycomp_B2B_PO::META_COMPANY ) === $co;
				}
			);
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sycomp-purchase-orders-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv(
			$out,
			array( 'PO Number', 'Date', 'Status', 'Company', 'Location', 'Market', 'Currency', 'Buyer', 'Items', 'Subtotal', 'Tax', 'Total', 'PO Reference' )
		);
		foreach ( $orders as $order ) {
			$omk         = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
			$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
			$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
			$created     = $order->get_date_created();
			$fees        = 0.0;
			foreach ( $order->get_fees() as $fee ) {
				$fees += (float) $fee->get_total();
			}
			$buyer = $order->get_formatted_billing_full_name();
			fputcsv(
				$out,
				array(
					$order->get_order_number(),
					$created ? $created->date( 'Y-m-d' ) : '',
					Sycomp_B2B_PO::is_po( $order ) ? Sycomp_B2B_PO::status_label( $order->get_status() ) : wc_get_order_status_name( $order->get_status() ),
					$company_id ? get_the_title( $company_id ) : '',
					$location_id ? get_the_title( $location_id ) : '',
					$omk ? Sycomp_B2B_Markets::label( $omk ) : '',
					( $omk && Sycomp_B2B_Markets::exists( $omk ) ) ? Sycomp_B2B_Markets::currency( $omk ) : $order->get_currency(),
					$buyer ? $buyer : $order->get_billing_email(),
					$order->get_item_count(),
					$order->get_subtotal(),
					$fees,
					$order->get_total(),
					(string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF ),
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Whether the current user may use the admin.
	 *
	 * @return bool
	 */
	public static function is_manager() {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The active section key.
	 *
	 * @return string
	 */
	protected static function section() {
		$s = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $s, array( 'dashboard', 'products', 'categories', 'customers', 'po', 'search', 'profile', 'company' ), true ) ? $s : 'dashboard';
	}

	/**
	 * The manage page URL.
	 *
	 * @return string
	 */
	protected static function manage_url() {
		return sycomp_b2b_page_url( 'manage' );
	}

	/**
	 * Sycomp's own company details, with defaults.
	 *
	 * @return array
	 */
	public static function company_details() {
		$saved = get_option( self::OPTION_COMPANY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args(
			$saved,
			array(
				'name'    => get_bloginfo( 'name' ),
				'address' => '',
				'email'   => '',
				'phone'   => '',
				'website' => '',
				'logo_id' => 0,
			)
		);
	}

	/**
	 * Load the wp-admin file/media helpers needed for uploads.
	 */
	protected static function ensure_media() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/* =====================================================================
	 * Write actions.
	 * ================================================================== */

	/**
	 * Dispatch a posted admin action.
	 */
	public static function handle_actions() {
		if ( empty( $_POST['sycomp_admin_action'] ) || ! self::is_manager() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['sycomp_admin_action'] ) );

		switch ( $action ) {
			case 'po_accept':
			case 'po_close':
			case 'po_cancel':
				self::do_po_action( $action );
				break;
			case 'po_create':
				self::do_po_create();
				break;
			case 'po_duplicate':
				self::do_po_duplicate();
				break;
			case 'po_edit':
				self::do_po_edit();
				break;
			case 'product_delete':
				self::do_product_delete();
				break;
			case 'product_save':
				self::do_product_save();
				break;
			case 'cat_save':
			case 'cat_delete':
				self::do_category_action( $action );
				break;
			case 'profile_save':
				self::do_profile_save();
				break;
			case 'company_save':
				self::do_company_save();
				break;
			case 'ae_save':
				self::do_ae_save();
				break;
			case 'warehouses_save':
				self::do_warehouses_save();
				break;
			case 'quote_formats_save':
				self::do_quote_formats_save();
				break;
			case 'taxes_save':
				self::do_taxes_save();
				break;
			case 'fx_save':
				self::do_fx_save();
				break;
			case 'market_save':
				self::do_market_save();
				break;
			case 'market_delete':
				self::do_market_delete();
				break;
			case 'bulkprice_apply':
				self::do_bulkprice();
				break;
			case 'import_run':
				self::do_import();
				break;
		}
	}

	/**
	 * Run a PO lifecycle action and redirect back to its stage.
	 *
	 * @param string $action po_accept | po_close | po_cancel.
	 */
	protected static function do_po_action( $action ) {
		if ( ! self::verify( 'sycomp_po' ) ) {
			return;
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$done     = 'none';

		if ( $order && class_exists( 'Sycomp_B2B_Admin_PO' ) ) {
			if ( 'po_accept' === $action ) {
				Sycomp_B2B_Admin_PO::accept( $order );
				$done = 'accepted';
			} elseif ( 'po_close' === $action ) {
				Sycomp_B2B_Admin_PO::close( $order );
				$done = 'closed';
			} elseif ( 'po_cancel' === $action ) {
				Sycomp_B2B_Admin_PO::cancel( $order );
				$done = 'cancelled';
			}
		}

		$stage = isset( $_POST['po'] ) ? sanitize_key( wp_unslash( $_POST['po'] ) ) : 'open';
		self::redirect( array( 'section' => 'po', 'po' => $stage, 'done' => $done ) );
	}

	/**
	 * Move a product to Trash.
	 */
	protected static function do_product_delete() {
		if ( ! self::verify( 'sycomp_product' ) ) {
			return;
		}
		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( $pid && 'product' === get_post_type( $pid ) ) {
			wp_trash_post( $pid );
		}
		self::redirect( array( 'section' => 'products', 'done' => 'product_deleted' ) );
	}

	/**
	 * Create or update a product from the native product form.
	 */
	protected static function do_product_save() {
		if ( ! self::verify( 'sycomp_product_save' ) ) {
			return;
		}
		$pid = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		$name  = isset( $_POST['p_name'] ) ? sanitize_text_field( wp_unslash( $_POST['p_name'] ) ) : '';
		$sku   = isset( $_POST['p_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['p_sku'] ) ) : '';
		$desc  = isset( $_POST['p_desc'] ) ? wp_kses_post( wp_unslash( $_POST['p_desc'] ) ) : '';
		$stock = isset( $_POST['p_stock'] ) ? sanitize_text_field( wp_unslash( $_POST['p_stock'] ) ) : '';

		if ( '' === $name ) {
			self::redirect( array( 'section' => 'products', 'pview' => ( $pid ? 'edit' : 'new' ), 'pid' => $pid, 'done' => 'product_error' ) );
		}

		// Collect the nine market prices.
		$prices = array();
		$base   = '';
		foreach ( Sycomp_B2B_Markets::keys() as $mkey ) {
			$field = 'price_' . $mkey;
			$val   = isset( $_POST[ $field ] ) ? wc_clean( wp_unslash( $_POST[ $field ] ) ) : '';
			$val   = ( '' === $val ) ? '' : wc_format_decimal( $val );
			$prices[ $mkey ] = $val;
			if ( '' === $base && '' !== $val ) {
				$base = $val;
			}
		}

		try {
			$product = $pid ? wc_get_product( $pid ) : new WC_Product_Simple();
			if ( ! $product ) {
				throw new Exception( 'missing' );
			}
			$product->set_name( $name );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_sku( $sku );
			$product->set_description( $desc );
			if ( '' !== $base ) {
				$product->set_regular_price( $base );
			}
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$new_id = $product->save();
		} catch ( Exception $e ) {
			self::redirect( array( 'section' => 'products', 'pview' => ( $pid ? 'edit' : 'new' ), 'pid' => $pid, 'done' => 'product_error' ) );
		}

		if ( empty( $new_id ) ) {
			self::redirect( array( 'section' => 'products', 'pview' => ( $pid ? 'edit' : 'new' ), 'pid' => $pid, 'done' => 'product_error' ) );
		}

		// Category (single) and brand.
		$cat_id = isset( $_POST['p_cat'] ) ? absint( wp_unslash( $_POST['p_cat'] ) ) : 0;
		wp_set_object_terms( $new_id, $cat_id ? array( $cat_id ) : array(), 'product_cat' );

		$brand = isset( $_POST['p_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['p_brand'] ) ) : '';
		wp_set_object_terms( $new_id, '' !== $brand ? array( $brand ) : array(), Sycomp_B2B_Post_Types::TAX_BRAND );

		// Market prices, currency, and GP margins.
		foreach ( $prices as $mkey => $val ) {
			Sycomp_B2B_Pricing::set_market_price( $new_id, $mkey, $val );
			$sycomp_pcur = isset( $_POST[ 'pricecur_' . $mkey ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'pricecur_' . $mkey ] ) ) : '';
			Sycomp_B2B_Pricing::set_price_currency( $new_id, $mkey, $sycomp_pcur );
			$gp_val = isset( $_POST[ 'gp_' . $mkey ] ) ? wc_clean( wp_unslash( $_POST[ 'gp_' . $mkey ] ) ) : '';
			Sycomp_B2B_Pricing::set_product_market_gp( $new_id, $mkey, $gp_val );
		}

		// Per-company visibility.
		delete_post_meta( $new_id, '_sycomp_company' );
		if ( ! empty( $_POST['p_companies'] ) && is_array( $_POST['p_companies'] ) ) {
			foreach ( wp_unslash( $_POST['p_companies'] ) as $cid ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$cid = absint( $cid );
				if ( $cid ) {
					add_post_meta( $new_id, '_sycomp_company', $cid );
				}
			}
		}

		// Featured image upload.
		if ( ! empty( $_FILES['p_image']['name'] ) ) {
			self::ensure_media();
			$att = media_handle_upload( 'p_image', $new_id );
			if ( ! is_wp_error( $att ) ) {
				set_post_thumbnail( $new_id, $att );
			}
		}

		self::redirect( array( 'section' => 'products', 'done' => 'product_saved' ) );
	}

	/**
	 * Create / update / delete a product category.
	 *
	 * @param string $action cat_save | cat_delete.
	 */
	protected static function do_category_action( $action ) {
		if ( ! self::verify( 'sycomp_category' ) ) {
			return;
		}
		$done = 'none';

		if ( 'cat_save' === $action ) {
			$name   = isset( $_POST['cat_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cat_name'] ) ) : '';
			$tid    = isset( $_POST['cat_id'] ) ? absint( wp_unslash( $_POST['cat_id'] ) ) : 0;
			$parent = isset( $_POST['cat_parent'] ) ? absint( wp_unslash( $_POST['cat_parent'] ) ) : 0;
			if ( '' !== $name ) {
				if ( $tid ) {
					wp_update_term( $tid, 'product_cat', array( 'name' => $name, 'parent' => $parent ) );
					$done = 'cat_updated';
				} else {
					wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
					$done = 'cat_created';
				}
			}
		} elseif ( 'cat_delete' === $action ) {
			$tid = isset( $_POST['cat_id'] ) ? absint( wp_unslash( $_POST['cat_id'] ) ) : 0;
			if ( $tid ) {
				wp_delete_term( $tid, 'product_cat' );
				$done = 'cat_deleted';
			}
		}
		self::redirect( array( 'section' => 'categories', 'done' => $done ) );
	}

	/**
	 * Update the current user's own profile.
	 */
	protected static function do_profile_save() {
		if ( ! self::verify( 'sycomp_profile' ) ) {
			return;
		}
		$uid   = get_current_user_id();
		$first = isset( $_POST['pr_first'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_first'] ) ) : '';
		$last  = isset( $_POST['pr_last'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_last'] ) ) : '';
		$disp  = isset( $_POST['pr_display'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_display'] ) ) : '';
		$email = isset( $_POST['pr_email'] ) ? sanitize_email( wp_unslash( $_POST['pr_email'] ) ) : '';
		$pass1 = isset( $_POST['pr_pass'] ) ? (string) wp_unslash( $_POST['pr_pass'] ) : '';        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pass2 = isset( $_POST['pr_pass2'] ) ? (string) wp_unslash( $_POST['pr_pass2'] ) : '';      // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_email( $email ) ) {
			self::redirect( array( 'section' => 'profile', 'done' => 'profile_error' ) );
		}
		$owner = email_exists( $email );
		if ( $owner && (int) $owner !== (int) $uid ) {
			self::redirect( array( 'section' => 'profile', 'done' => 'profile_error' ) );
		}

		$data = array(
			'ID'           => $uid,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => '' !== $disp ? $disp : trim( $first . ' ' . $last ),
			'user_email'   => $email,
		);

		$pass_changed = false;
		if ( '' !== $pass1 || '' !== $pass2 ) {
			if ( $pass1 !== $pass2 || strlen( $pass1 ) < 6 ) {
				self::redirect( array( 'section' => 'profile', 'done' => 'profile_pass_error' ) );
			}
			$data['user_pass'] = $pass1;
			$pass_changed       = true;
		}

		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'section' => 'profile', 'done' => 'profile_error' ) );
		}

		// Keep the manager signed in after a password change.
		if ( $pass_changed ) {
			wp_set_auth_cookie( $uid, true );
		}
		self::redirect( array( 'section' => 'profile', 'done' => 'profile_saved' ) );
	}

	/**
	 * Save Sycomp's own company details.
	 */
	protected static function do_company_save() {
		if ( ! self::verify( 'sycomp_company' ) ) {
			return;
		}
		$current = self::company_details();
		$data    = array(
			'name'    => isset( $_POST['co_name'] ) ? sanitize_text_field( wp_unslash( $_POST['co_name'] ) ) : '',
			'address' => isset( $_POST['co_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['co_address'] ) ) : '',
			'email'   => isset( $_POST['co_email'] ) ? sanitize_email( wp_unslash( $_POST['co_email'] ) ) : '',
			'phone'   => isset( $_POST['co_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['co_phone'] ) ) : '',
			'website' => isset( $_POST['co_website'] ) ? esc_url_raw( wp_unslash( $_POST['co_website'] ) ) : '',
			'logo_id' => (int) $current['logo_id'],
		);

		if ( ! empty( $_FILES['co_logo']['name'] ) ) {
			self::ensure_media();
			$att = media_handle_upload( 'co_logo', 0 );
			if ( ! is_wp_error( $att ) ) {
				$data['logo_id'] = (int) $att;
			}
		}

		update_option( self::OPTION_COMPANY, $data );
		self::redirect( array( 'section' => 'company', 'done' => 'company_saved' ) );
	}

	/**
	 * Save the Account Executive details.
	 */
	protected static function do_ae_save() {
		if ( ! self::verify( 'sycomp_ae' ) ) {
			return;
		}
		$name  = isset( $_POST['ae_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_name'] ) ) : '';
		$phone = isset( $_POST['ae_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['ae_phone'] ) ) : '';
		$email = isset( $_POST['ae_email'] ) ? sanitize_email( wp_unslash( $_POST['ae_email'] ) ) : '';

		update_option( 'sycomp_po_ae_name', $name );
		update_option( 'sycomp_po_ae_phone', $phone );
		update_option( 'sycomp_po_ae_email', $email );

		self::redirect( array( 'section' => 'company', 'done' => 'ae_saved' ) );
	}

	/**
	 * Save the per-market warehouse (ship-from) addresses.
	 */
	protected static function do_warehouses_save() {
		if ( ! self::verify( 'sycomp_warehouses' ) ) {
			return;
		}
		$wh = ( isset( $_POST['wh'] ) && is_array( $_POST['wh'] ) ) ? wp_unslash( $_POST['wh'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Sycomp_B2B_Warehouses::save_all( $wh );
		self::redirect( array( 'section' => 'company', 'done' => 'warehouses_saved' ) );
	}

	/**
	 * Save the per-market quote nomenclature formats.
	 */
	protected static function do_quote_formats_save() {
		if ( ! self::verify( 'sycomp_quote_formats' ) ) {
			return;
		}
		$formats = ( isset( $_POST['quote_formats'] ) && is_array( $_POST['quote_formats'] ) ) ? wp_unslash( $_POST['quote_formats'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = array();
		foreach ( Sycomp_B2B_Markets::keys() as $key ) {
			$clean[ $key ] = isset( $formats[ $key ] ) ? sanitize_text_field( $formats[ $key ] ) : '';
		}
		update_option( 'sycomp_b2b_quote_formats', $clean );
		self::redirect( array( 'section' => 'company', 'done' => 'quote_formats_saved' ) );
	}

	/**
	 * Save the per-market tax label + rate.
	 */
	protected static function do_taxes_save() {
		if ( ! self::verify( 'sycomp_taxes' ) ) {
			return;
		}
		$tx = ( isset( $_POST['tax'] ) && is_array( $_POST['tax'] ) ) ? wp_unslash( $_POST['tax'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Sycomp_B2B_Tax::save_all( $tx );
		self::redirect( array( 'section' => 'company', 'done' => 'taxes_saved' ) );
	}

	/**
	 * Save the currency exchange rates.
	 */
	protected static function do_fx_save() {
		if ( ! self::verify( 'sycomp_fx' ) ) {
			return;
		}
		$fx = ( isset( $_POST['fx'] ) && is_array( $_POST['fx'] ) ) ? wp_unslash( $_POST['fx'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Sycomp_B2B_FX::save_rates( $fx );
		self::redirect( array( 'section' => 'company', 'done' => 'fx_saved' ) );
	}

	/**
	 * Save/Update a market.
	 */
	protected static function do_market_save() {
		if ( ! self::verify( 'sycomp_market_save' ) ) {
			return;
		}

		$key = isset( $_POST['market_key'] ) ? sanitize_key( wp_unslash( $_POST['market_key'] ) ) : '';

		if ( empty( $key ) ) {
			self::redirect( array( 'section' => 'company', 'done' => 'market_error' ) );
		}

		$existing = Sycomp_B2B_Markets::get( $key );
		$is_new   = ! $existing;
		$flag_url = $existing ? ( isset( $existing['flag_url'] ) ? $existing['flag_url'] : '' ) : '';

		// Process flag file upload if provided
		if ( ! empty( $_FILES['flag_file']['name'] ) ) {
			self::ensure_media();
			$att = media_handle_upload( 'flag_file', 0 );
			if ( ! is_wp_error( $att ) ) {
				$flag_url = wp_get_attachment_url( (int) $att );
			}
		}

		$data = array(
			'label'        => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'currency'     => isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '',
			'symbol'       => isset( $_POST['symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['symbol'] ) ) : '',
			'decimals'     => isset( $_POST['decimals'] ) ? max( 0, (int) $_POST['decimals'] ) : 2,
			'country_code' => isset( $_POST['country_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country_code'] ) ) ) : '',
			'order'        => isset( $_POST['order'] ) ? (int) $_POST['order'] : 100,
			'flag_url'     => $flag_url,
		);

		Sycomp_B2B_Markets::save_market( $key, $data );

		self::redirect( array( 'section' => 'company', 'done' => 'market_saved' ) );
	}

	/**
	 * Delete a custom market.
	 */
	protected static function do_market_delete() {
		if ( ! self::verify( 'sycomp_market_delete' ) ) {
			return;
		}

		$key = isset( $_POST['market_key'] ) ? sanitize_key( wp_unslash( $_POST['market_key'] ) ) : '';

		if ( ! empty( $key ) ) {
			Sycomp_B2B_Markets::delete_market( $key );
			self::redirect( array( 'section' => 'company', 'done' => 'market_deleted' ) );
		}

		self::redirect( array( 'section' => 'company', 'done' => 'market_error' ) );
	}

	/**
	 * Run a CSV import (products or per-market prices).
	 */
	protected static function do_import() {
		if ( ! self::verify( 'sycomp_import' ) ) {
			return;
		}
		$type = isset( $_POST['import_type'] ) ? sanitize_key( wp_unslash( $_POST['import_type'] ) ) : '';
		$uid  = get_current_user_id();

		if ( empty( $_FILES['sycomp_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sycomp_csv']['tmp_name'] ) ) {
			set_transient( 'sycomp_import_msg_' . $uid, array( 'warn', __( 'No CSV file was uploaded.', 'sycomp-b2b-portal' ) ), 120 );
			self::redirect( array( 'section' => 'products', 'pview' => 'import', 'done' => 'import_done' ) );
		}

		$tmp = $_FILES['sycomp_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		$msg = array( 'warn', __( 'Nothing was imported.', 'sycomp-b2b-portal' ) );

		if ( 'products' === $type ) {
			$result = Sycomp_B2B_Importer::import_products( $tmp, array( 'import_images' => ! empty( $_POST['import_images'] ) ) );
			if ( is_wp_error( $result ) ) {
				$msg = array( 'warn', $result->get_error_message() );
			} else {
				$msg = array(
					'success',
					sprintf(
						/* translators: 1: created, 2: updated, 3: skipped, 4: total. */
						__( 'Products imported: %1$d created, %2$d updated, %3$d skipped of %4$d rows.', 'sycomp-b2b-portal' ),
						$result['created'],
						$result['updated'],
						$result['skipped'],
						$result['total']
					),
				);
			}
		} elseif ( 'prices' === $type ) {
			$market = isset( $_POST['import_market'] ) ? sanitize_key( wp_unslash( $_POST['import_market'] ) ) : '';
			if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
				$msg = array( 'warn', __( 'Choose a valid market for the price list.', 'sycomp-b2b-portal' ) );
			} else {
				$result = Sycomp_B2B_Importer::import_prices( $tmp, $market );
				if ( is_wp_error( $result ) ) {
					$msg = array( 'warn', $result->get_error_message() );
				} else {
					$msg = array(
						'success',
						sprintf(
							/* translators: 1: market, 2: priced, 3: cleared, 4: unmatched, 5: total. */
							__( '%1$s prices imported: %2$d priced, %3$d cleared, %4$d unmatched of %5$d rows.', 'sycomp-b2b-portal' ),
							Sycomp_B2B_Markets::label( $result['market'] ),
							$result['matched'],
							$result['cleared'],
							$result['unmatched'],
							$result['total']
						),
					);
				}
			}
		}

		set_transient( 'sycomp_import_msg_' . $uid, $msg, 120 );
		self::redirect( array( 'section' => 'products', 'pview' => 'import', 'done' => 'import_done' ) );
	}

	/**
	 * Verify a posted nonce.
	 *
	 * @param string $action Nonce action.
	 * @return bool
	 */
	protected static function verify( $action ) {
		return isset( $_POST['sycomp_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_nonce'] ) ), $action );
	}

	/**
	 * Redirect back to the manage page with the given query args.
	 *
	 * @param array $args Query args.
	 */
	protected static function redirect( $args ) {
		wp_safe_redirect( add_query_arg( $args, self::manage_url() ) );
		exit;
	}

	/* =====================================================================
	 * Shortcode entry point.
	 * ================================================================== */

	/**
	 * [sycomp_manager] — render the active section.
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return sycomp_b2b_login_gate( __( 'Sign in to manage the Sycomp portal.', 'sycomp-b2b-portal' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return '<div class="sy-notice sy-notice--warn">'
				. esc_html__( 'This area is for Sycomp staff only.', 'sycomp-b2b-portal' )
				. '</div>';
		}

		ob_start();
		switch ( self::section() ) {
			case 'products':
				self::render_products();
				break;
			case 'categories':
				self::render_categories();
				break;
			case 'customers':
				Sycomp_B2B_Manager_Customers::render();
				break;
			case 'search':
				self::render_search();
				break;
			case 'po':
				self::render_po();
				break;
			case 'profile':
				self::render_profile();
				break;
			case 'company':
				self::render_company();
				break;
			default:
				self::render_dashboard();
				break;
		}
		return (string) ob_get_clean();
	}

	/* =====================================================================
	 * Shared building blocks.
	 * ================================================================== */

	/**
	 * Render the page heading row.
	 *
	 * @param string $title   Page title.
	 * @param string $lede    Sub-text.
	 * @param string $actions Pre-escaped action HTML for the right side.
	 */
	protected static function page_head( $title, $lede = '', $actions = '' ) {
		echo '<div class="sy-adm-head">';
		echo '<div class="sy-adm-head__text">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $lede ) {
			echo '<p class="sy-lede">' . esc_html( $lede ) . '</p>';
		}
		echo '</div>';
		if ( $actions ) {
			echo '<div class="sy-adm-head__actions">' . $actions . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/**
	 * Render the notice after a write action.
	 */
	protected static function notice() {
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $done ) {
			return;
		}

		if ( 'import_done' === $done ) {
			$stored = get_transient( 'sycomp_import_msg_' . get_current_user_id() );
			if ( is_array( $stored ) ) {
				delete_transient( 'sycomp_import_msg_' . get_current_user_id() );
				$cls = ( 'success' === $stored[0] ) ? 'sy-notice--success' : 'sy-notice--warn';
				echo '<div class="sy-notice ' . esc_attr( $cls ) . '">' . esc_html( $stored[1] ) . '</div>';
			}
			return;
		}

		$ok = array(
			'accepted'        => __( 'Proposal accepted — it is now In-Process. The buyer has been notified.', 'sycomp-b2b-portal' ),
			'closed'          => __( 'Proposal closed. The buyer has been notified.', 'sycomp-b2b-portal' ),
			'cancelled'       => __( 'Proposal cancelled. The buyer has been notified.', 'sycomp-b2b-portal' ),
			'product_deleted' => __( 'Product moved to Trash.', 'sycomp-b2b-portal' ),
			'product_saved'   => __( 'Product saved.', 'sycomp-b2b-portal' ),
			'cat_created'     => __( 'Category created.', 'sycomp-b2b-portal' ),
			'cat_updated'     => __( 'Category updated.', 'sycomp-b2b-portal' ),
			'cat_deleted'     => __( 'Category deleted.', 'sycomp-b2b-portal' ),
			'profile_saved'   => __( 'Your profile has been updated.', 'sycomp-b2b-portal' ),
			'company_saved'   => __( 'Company details saved.', 'sycomp-b2b-portal' ),
			'ae_saved'        => __( 'Account Executive details saved.', 'sycomp-b2b-portal' ),
			'warehouses_saved' => __( 'Bill From addresses saved.', 'sycomp-b2b-portal' ),
			'quote_formats_saved' => __( 'Quote number formats saved.', 'sycomp-b2b-portal' ),
			'taxes_saved'     => __( 'Tax rates saved.', 'sycomp-b2b-portal' ),
			'fx_saved'        => __( 'Exchange rates saved.', 'sycomp-b2b-portal' ),
			'po_created'      => __( 'Proposal created.', 'sycomp-b2b-portal' ),
			'po_updated'      => __( 'Proposal updated.', 'sycomp-b2b-portal' ),
			'bulkprice_done'  => __( 'Market prices adjusted.', 'sycomp-b2b-portal' ),
			'market_saved'    => __( 'Market settings saved.', 'sycomp-b2b-portal' ),
			'market_deleted'  => __( 'Market deleted.', 'sycomp-b2b-portal' ),
		);
		$warn = array(
			'market_error'       => __( 'Could not save or delete the market. Check if the key is valid.', 'sycomp-b2b-portal' ),
			'product_error'      => __( 'Could not save the product. Check the name and that the SKU is not already in use.', 'sycomp-b2b-portal' ),
			'profile_error'      => __( 'Could not update your profile — the email address may already be in use.', 'sycomp-b2b-portal' ),
			'profile_pass_error' => __( 'Passwords did not match or were shorter than 6 characters. Other changes were not saved.', 'sycomp-b2b-portal' ),
			'po_error'           => __( 'Could not create the proposal — please check the location and try again.', 'sycomp-b2b-portal' ),
			'po_nolines'         => __( 'Add at least one product line before creating the proposal.', 'sycomp-b2b-portal' ),
		);

		if ( isset( $ok[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--success">' . esc_html( $ok[ $done ] ) . '</div>';
		} elseif ( isset( $warn[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html( $warn[ $done ] ) . '</div>';
		}
	}

	/**
	 * Count orders in a status.
	 *
	 * @param string $status Status slug.
	 * @return int
	 */
	protected static function count_status( $status ) {
		$ids = wc_get_orders( array( 'status' => $status, 'limit' => -1, 'return' => 'ids' ) );
		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * Count published products visible to a company.
	 *
	 * @param int $cid Company ID.
	 * @return int
	 */
	protected static function count_company_products( $cid ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'   => '_sycomp_company',
						'value' => (int) $cid,
					),
				),
			)
		);
		return count( $q->posts );
	}

	/**
	 * Pagination control.
	 *
	 * @param int    $total     Total pages.
	 * @param int    $current   Current page.
	 * @param array  $base_args Query args to keep.
	 * @param string $page_key  Page query var.
	 */
	protected static function pagination( $total, $current, $base_args, $page_key ) {
		$total = (int) $total;
		if ( $total < 2 ) {
			return;
		}
		echo '<nav class="sy-pagination" aria-label="' . esc_attr__( 'Pages', 'sycomp-b2b-portal' ) . '">';
		for ( $i = 1; $i <= $total; $i++ ) {
			if ( $i === $current ) {
				echo '<span class="sy-pagination__item is-current">' . esc_html( $i ) . '</span>';
			} else {
				$url = add_query_arg( array_merge( $base_args, array( $page_key => $i ) ), self::manage_url() );
				echo '<a class="sy-pagination__item" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
			}
		}
		echo '</nav>';
	}

	/* =====================================================================
	 * Section: Dashboard.
	 * ================================================================== */

	/**
	 * Render the dashboard overview.
	 */
	protected static function render_dashboard() {
		$companies = Sycomp_B2B_Post_Types::get_companies();
		$co        = isset( $_GET['co'] ) ? absint( wp_unslash( $_GET['co'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $co && Sycomp_B2B_Post_Types::COMPANY !== get_post_type( $co ) ) {
			$co = 0;
		}

		// Pull every proposal, then (optionally) narrow to one company.
		$orders = wc_get_orders(
			array(
				'status'  => array( Sycomp_B2B_PO::STATUS_OPEN, Sycomp_B2B_PO::STATUS_PROCESS, Sycomp_B2B_PO::STATUS_CLOSED ),
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		if ( $co ) {
			$orders = array_values(
				array_filter(
					$orders,
					static function ( $order ) use ( $co ) {
						return (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY ) === $co;
					}
				)
			);
		}

		// Derive every purchase-order stat from the (filtered) order set.
		$open      = 0;
		$process   = 0;
		$closed    = 0;
		$by_market = array();
		$by_cat    = array();
		foreach ( $orders as $order ) {
			if ( $order->has_status( Sycomp_B2B_PO::STATUS_OPEN ) ) {
				$open++;
				continue;
			} elseif ( $order->has_status( Sycomp_B2B_PO::STATUS_PROCESS ) ) {
				$process++;
			} elseif ( $order->has_status( Sycomp_B2B_PO::STATUS_CLOSED ) ) {
				$closed++;
			}
			// Revenue is kept per market — currencies are never blended.
			$sycomp_omk = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
			if ( ! isset( $by_market[ $sycomp_omk ] ) ) {
				$by_market[ $sycomp_omk ] = 0.0;
			}
			$by_market[ $sycomp_omk ] += (float) $order->get_total();
			// Category spend is converted to USD so a single chart is coherent.
			$sycomp_ocur = Sycomp_B2B_Markets::exists( $sycomp_omk ) ? Sycomp_B2B_Markets::currency( $sycomp_omk ) : 'USD';
			foreach ( $order->get_items() as $item ) {
				$pid   = $item->get_product_id();
				$terms = $pid ? wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) ) : array();
				$cat   = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : __( 'Uncategorised', 'sycomp-b2b-portal' );
				if ( ! isset( $by_cat[ $cat ] ) ) {
					$by_cat[ $cat ] = 0.0;
				}
				$by_cat[ $cat ] += (float) Sycomp_B2B_FX::convert( $item->get_total(), $sycomp_ocur, 'USD' );
			}
		}
		arsort( $by_cat );

		// Products and buyers — global, or scoped to the chosen company.
		if ( $co ) {
			$prod_n = self::count_company_products( $co );
			$user_n = count(
				get_users(
					array(
						'meta_key'   => Sycomp_B2B_User::META_COMPANY, // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => $co,                            // phpcs:ignore WordPress.DB.SlowDBQuery
						'fields'     => 'ID',
					)
				)
			);
		} else {
			$products = wp_count_posts( 'product' );
			$prod_n   = isset( $products->publish ) ? (int) $products->publish : 0;
			$users    = count_users();
			$user_n   = isset( $users['total_users'] ) ? (int) $users['total_users'] : 0;
		}

		self::page_head(
			$co
				/* translators: %s: company name. */
				? sprintf( __( 'Dashboard — %s', 'sycomp-b2b-portal' ), get_the_title( $co ) )
				: __( 'Dashboard Overview', 'sycomp-b2b-portal' ),
			$co
				? __( 'Proposals and spend for this customer.', 'sycomp-b2b-portal' )
				: __( 'Review proposals and manage the Sycomp catalogue.', 'sycomp-b2b-portal' )
		);
		self::notice();

		// Customer toggle — scope the whole dashboard to one company, or all.
		if ( $companies ) {
			echo '<form class="sy-adm-filters" method="get" action="' . esc_url( self::manage_url() ) . '">';
			echo '<input type="hidden" name="section" value="dashboard">';
			echo '<strong style="font-size:.8rem;">' . esc_html__( 'Customer', 'sycomp-b2b-portal' ) . '</strong>';
			echo '<select name="co" aria-label="' . esc_attr__( 'Filter dashboard by customer', 'sycomp-b2b-portal' ) . '" onchange="this.form.submit()">';
			echo '<option value="">' . esc_html__( 'All customers', 'sycomp-b2b-portal' ) . '</option>';
			foreach ( $companies as $company ) {
				echo '<option value="' . esc_attr( $company->ID ) . '" ' . selected( $co, $company->ID, false ) . '>' . esc_html( get_the_title( $company ) ) . '</option>';
			}
			echo '</select>';
			echo '<noscript><button type="submit" class="sy-btn sy-btn--primary sy-btn--sm">' . esc_html__( 'Filter', 'sycomp-b2b-portal' ) . '</button></noscript>';
			echo '</form>';
		}

		$po_base = $co ? array( 'section' => 'po', 'co' => $co ) : array( 'section' => 'po' );
		$cards   = array(
			array(
				$co ? __( 'Company Buyers', 'sycomp-b2b-portal' ) : __( 'Total Users', 'sycomp-b2b-portal' ),
				number_format_i18n( $user_n ),
				'users',
				$co
					? add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $co ), self::manage_url() )
					: add_query_arg( 'section', 'customers', self::manage_url() ),
			),
			array(
				$co ? __( 'Products Available', 'sycomp-b2b-portal' ) : __( 'Total Products', 'sycomp-b2b-portal' ),
				number_format_i18n( $prod_n ),
				'products',
				add_query_arg( 'section', 'products', self::manage_url() ),
			),
			array( __( 'Open POs', 'sycomp-b2b-portal' ), number_format_i18n( $open ), 'open', add_query_arg( array_merge( $po_base, array( 'po' => 'open' ) ), self::manage_url() ) ),
			array( __( 'In-Process POs', 'sycomp-b2b-portal' ), number_format_i18n( $process ), 'process', add_query_arg( array_merge( $po_base, array( 'po' => 'process' ) ), self::manage_url() ) ),
			array( __( 'Closed POs', 'sycomp-b2b-portal' ), number_format_i18n( $closed ), 'closed', add_query_arg( array_merge( $po_base, array( 'po' => 'closed' ) ), self::manage_url() ) ),
		);
		echo '<div class="sy-stat-grid">';
		foreach ( $cards as $card ) {
			$tag  = ! empty( $card[3] ) ? 'a' : 'div';
			$href = ! empty( $card[3] ) ? ' href="' . esc_url( $card[3] ) . '"' : '';
			echo '<' . $tag . ' class="sy-stat sy-stat--' . esc_attr( $card[2] ) . '"' . $href . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<span class="sy-stat__l">' . esc_html( $card[0] ) . '</span>';
			echo '<span class="sy-stat__n">' . esc_html( $card[1] ) . '</span>';
			echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';

		// Revenue by market — each market shown in its own currency.
		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">' . esc_html__( 'Revenue by market', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body">';
		if ( empty( array_filter( $by_market ) ) ) {
			echo '<p class="sy-muted">' . esc_html__( 'No purchase-order revenue recorded yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<div class="sy-deflist">';
			$sycomp_usd_total = 0.0;
			foreach ( Sycomp_B2B_Markets::all() as $sycomp_rmk => $sycomp_rmarket ) {
				if ( empty( $by_market[ $sycomp_rmk ] ) ) {
					continue;
				}
				echo '<div><span class="sy-deflist__k">' . esc_html( $sycomp_rmarket['label'] ) . '</span>';
				echo '<span class="sy-deflist__v">' . wp_kses_post( Sycomp_B2B_Pricing::format_in_market( $by_market[ $sycomp_rmk ], $sycomp_rmk ) ) . '</span></div>';
				$sycomp_usd_total += Sycomp_B2B_FX::convert( $by_market[ $sycomp_rmk ], $sycomp_rmarket['currency'], 'USD' );
			}
			echo '</div>';
			echo '<p class="sy-muted" style="margin:12px 0 0;">';
			/* translators: %s: USD total. */
			echo esc_html( sprintf( __( 'Total, USD equivalent: $%s', 'sycomp-b2b-portal' ), number_format_i18n( $sycomp_usd_total, 2 ) ) );
			echo '</p>';
		}
		echo '</div></section>';

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">' . esc_html__( 'Spend by Category (USD equivalent)', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body">';
		if ( empty( array_filter( $by_cat ) ) ) {
			echo '<p class="sy-muted">' . esc_html__( 'No purchase-order spend has been recorded yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			$rows = array_slice( $by_cat, 0, 8, true );
			$max  = max( $rows );
			echo '<div class="sy-chart">';
			foreach ( $rows as $cat => $amount ) {
				$pct = ( $max > 0 ) ? max( 2, round( $amount / $max * 100 ) ) : 0;
				echo '<div class="sy-chart__row">';
				echo '<span class="sy-chart__label">' . esc_html( $cat ) . '</span>';
				echo '<span class="sy-chart__track"><span class="sy-chart__bar" style="width:' . esc_attr( $pct ) . '%"></span></span>';
				echo '<span class="sy-chart__val">' . esc_html( '$' . number_format_i18n( $amount, 2 ) ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div></section>';
	}

	/* =====================================================================
	 * Section: Search.
	 * ================================================================== */

	/**
	 * Render global search results — products, proposals and
	 * customer companies matching the query.
	 */
	protected static function render_search() {
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		self::page_head(
			__( 'Search', 'sycomp-b2b-portal' ),
			'' !== $q
				/* translators: %s: search term. */
				? sprintf( __( 'Results for “%s”', 'sycomp-b2b-portal' ), $q )
				: __( 'Search products, proposals and customers.', 'sycomp-b2b-portal' )
		);
		self::notice();

		if ( strlen( $q ) < 2 ) {
			echo '<section class="sy-panel"><div class="sy-panel__body"><p class="sy-muted">' . esc_html__( 'Type at least two characters to search.', 'sycomp-b2b-portal' ) . '</p></div></section>';
			return;
		}

		$ql = strtolower( $q );

		// Products.
		$pquery = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 15,
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => $q,
			)
		);
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Products', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body sy-panel__body--flush">';
		if ( ! $pquery->have_posts() ) {
			echo '<p class="sy-empty">' . esc_html__( 'No matching products.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin"><tbody>';
			while ( $pquery->have_posts() ) {
				$pquery->the_post();
				$edit = add_query_arg( array( 'section' => 'products', 'pview' => 'edit', 'pid' => get_the_ID() ), self::manage_url() );
				echo '<tr><td><a class="sy-link" href="' . esc_url( $edit ) . '">' . esc_html( get_the_title() ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		wp_reset_postdata();
		echo '</div></section>';

		// Proposals — matched by number or PO reference.
		$pos = wc_get_orders(
			array(
				'status'  => array( Sycomp_B2B_PO::STATUS_OPEN, Sycomp_B2B_PO::STATUS_PROCESS, Sycomp_B2B_PO::STATUS_CLOSED, Sycomp_B2B_PO::STATUS_CANCELLED ),
				'limit'   => 200,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$po_hits = array();
		foreach ( $pos as $po ) {
			$num = strtolower( (string) $po->get_order_number() );
			$ref = strtolower( (string) $po->get_meta( Sycomp_B2B_PO::META_PO_REF ) );
			if ( false !== strpos( $num, $ql ) || ( '' !== $ref && false !== strpos( $ref, $ql ) ) ) {
				$po_hits[] = $po;
			}
			if ( count( $po_hits ) >= 15 ) {
				break;
			}
		}
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Proposals', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body sy-panel__body--flush">';
		if ( empty( $po_hits ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No matching proposals.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin"><tbody>';
			foreach ( $po_hits as $po ) {
				$detail = add_query_arg( array( 'section' => 'po', 'po' => 'all', 'detail' => $po->get_id() ), self::manage_url() );
				$cid    = (int) $po->get_meta( Sycomp_B2B_PO::META_COMPANY );
				echo '<tr><td><a class="sy-link" href="' . esc_url( $detail ) . '"><strong>#' . esc_html( $po->get_order_number() ) . '</strong></a> ';
				echo '<span class="sy-muted">' . esc_html( $cid ? get_the_title( $cid ) : '' ) . '</span></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';

		// Customer companies.
		$companies = get_posts(
			array(
				'post_type'   => Sycomp_B2B_Post_Types::COMPANY,
				'post_status' => 'publish',
				'numberposts' => 15,
				'orderby'     => 'title',
				'order'       => 'ASC',
				's'           => $q,
			)
		);
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Customers', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body sy-panel__body--flush">';
		if ( empty( $companies ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No matching customers.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin"><tbody>';
			foreach ( $companies as $company ) {
				$url = add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $company->ID ), self::manage_url() );
				echo '<tr><td><a class="sy-link" href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $company ) ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';
	}

	/* =====================================================================
	 * Section: Products.
	 * ================================================================== */

	/**
	 * Route the Products section to list / form / import.
	 */
	protected static function render_products() {
		$pview = isset( $_GET['pview'] ) ? sanitize_key( wp_unslash( $_GET['pview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'new' === $pview ) {
			self::render_product_form( 0 );
		} elseif ( 'edit' === $pview ) {
			$pid = isset( $_GET['pid'] ) ? absint( wp_unslash( $_GET['pid'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			self::render_product_form( $pid );
		} elseif ( 'import' === $pview ) {
			self::render_import();
		} elseif ( 'bulkprice' === $pview ) {
			self::render_bulk_price();
		} else {
			self::render_products_list();
		}
	}

	/**
	 * Render the Products table.
	 */
	protected static function render_products_list() {
		$pq    = isset( $_GET['pq'] ) ? sanitize_text_field( wp_unslash( $_GET['pq'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification
		$pcat  = isset( $_GET['pcat'] ) ? sanitize_title( wp_unslash( $_GET['pcat'] ) ) : '';             // phpcs:ignore WordPress.Security.NonceVerification
		$pmk   = isset( $_GET['pmk'] ) ? sanitize_key( wp_unslash( $_GET['pmk'] ) ) : '';                 // phpcs:ignore WordPress.Security.NonceVerification
		$paged = isset( $_GET['ppage'] ) ? max( 1, absint( wp_unslash( $_GET['ppage'] ) ) ) : 1;          // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! Sycomp_B2B_Markets::exists( $pmk ) ) {
			$keys = Sycomp_B2B_Markets::keys();
			$pmk  = ! empty( $keys ) ? $keys[0] : '';
		}

		$actions  = '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'products', 'pview' => 'bulkprice' ), self::manage_url() ) ) . '">' . esc_html__( 'Bulk pricing', 'sycomp-b2b-portal' ) . '</a>';
		$actions .= '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'products', 'pview' => 'import' ), self::manage_url() ) ) . '">' . esc_html__( 'Import CSV', 'sycomp-b2b-portal' ) . '</a>';
		$actions .= '<a class="sy-btn sy-btn--accent sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'products', 'pview' => 'new' ), self::manage_url() ) ) . '">' . esc_html__( 'Add Product', 'sycomp-b2b-portal' ) . '</a>';

		self::page_head(
			__( 'Products', 'sycomp-b2b-portal' ),
			__( 'Every product in the Sycomp catalogue.', 'sycomp-b2b-portal' ),
			$actions
		);
		self::notice();

		echo '<form class="sy-adm-filters" method="get" action="' . esc_url( self::manage_url() ) . '">';
		echo '<input type="hidden" name="section" value="products">';
		echo '<input class="sy-adm-filters__search" type="search" name="pq" value="' . esc_attr( $pq ) . '" placeholder="' . esc_attr__( 'Search products...', 'sycomp-b2b-portal' ) . '">';
		echo '<select name="pmk" aria-label="' . esc_attr__( 'Market', 'sycomp-b2b-portal' ) . '">';
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $key, $pmk, false ) . '>' . esc_html( $market['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<select name="pcat" aria-label="' . esc_attr__( 'Category', 'sycomp-b2b-portal' ) . '">';
		echo '<option value="">' . esc_html__( 'All Categories', 'sycomp-b2b-portal' ) . '</option>';
		$all_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( ! is_wp_error( $all_cats ) ) {
			foreach ( $all_cats as $term ) {
				echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $term->slug, $pcat, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
		echo '<button class="sy-btn sy-btn--primary sy-btn--sm" type="submit">' . esc_html__( 'Filter', 'sycomp-b2b-portal' ) . '</button>';
		echo '</form>';

		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( '' !== $pq ) {
			$args['s'] = $pq;
		}
		if ( '' !== $pcat ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $pcat ),
			);
		}
		$query = new WP_Query( $args );

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">';
		/* translators: %s: product count. */
		printf( esc_html__( 'All Products (%s)', 'sycomp-b2b-portal' ), esc_html( number_format_i18n( $query->found_posts ) ) );
		echo '</h2>';
		echo '<div class="sy-panel__body sy-panel__body--flush">';
		if ( ! $query->have_posts() ) {
			echo '<p class="sy-empty">' . esc_html__( 'No products match your filters.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin sy-table--stack"><thead><tr>';
			echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Category', 'sycomp-b2b-portal' ) . '</th>';
			/* translators: %s: market name. */
			echo '<th>' . esc_html( sprintf( __( 'Price (%s)', 'sycomp-b2b-portal' ), Sycomp_B2B_Markets::label( $pmk ) ) ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			while ( $query->have_posts() ) {
				$query->the_post();
				self::product_row( get_the_ID(), $pmk );
			}
			wp_reset_postdata();
			echo '</tbody></table>';
		}
		echo '</div></section>';

		self::pagination(
			$query->max_num_pages,
			$paged,
			array( 'section' => 'products', 'pq' => $pq, 'pcat' => $pcat, 'pmk' => $pmk ),
			'ppage'
		);
	}

	/**
	 * One Products table row.
	 *
	 * @param int    $pid Product ID.
	 * @param string $mk  Market key for the price column.
	 */
	protected static function product_row( $pid, $mk ) {
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return;
		}
		$cats     = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
		$cat      = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? $cats[0] : '';
		$price    = Sycomp_B2B_Pricing::get_market_price( $pid, $mk );
		$price_h  = ( '' !== $price )
			? Sycomp_B2B_Pricing::format_in_market( $price, $mk )
			: '<span class="sy-price-na">' . esc_html__( 'Not priced', 'sycomp-b2b-portal' ) . '</span>';
		$thumb    = get_the_post_thumbnail( $pid, array( 48, 48 ) );
		$edit_url = add_query_arg( array( 'section' => 'products', 'pview' => 'edit', 'pid' => $pid ), self::manage_url() );

		echo '<tr>';
		echo '<td><div class="sy-prow">';
		echo '<span class="sy-prow__thumb">' . ( $thumb ? $thumb : '<span class="sy-prow__ph">' . sycomp_b2b_placeholder_icon() . '</span>' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="sy-prow__meta">';
		echo '<span class="sy-prow__name">' . esc_html( $product->get_name() ) . '</span>';
		if ( $product->get_sku() ) {
			echo '<span class="sy-prow__sku">' . esc_html( $product->get_sku() ) . '</span>';
		}
		echo '</span></div></td>';
		echo '<td>' . ( $cat ? '<span class="sy-chip">' . esc_html( $cat ) . '</span>' : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
		echo '<td class="sy-col-price">' . wp_kses_post( $price_h ) . '</td>';
		echo '<td class="sy-col-act"><div class="sy-rowact">';
		echo '<a class="sy-iconbtn" href="' . esc_url( $edit_url ) . '" title="' . esc_attr__( 'Edit', 'sycomp-b2b-portal' ) . '">';
		echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>';
		echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Move this product to Trash?', 'sycomp-b2b-portal' ) ) . '\');">';
		wp_nonce_field( 'sycomp_product', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="product_delete">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( $pid ) . '">';
		echo '<button class="sy-iconbtn sy-iconbtn--danger" type="submit" title="' . esc_attr__( 'Delete', 'sycomp-b2b-portal' ) . '">';
		echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h12l1-14"/></svg></button>';
		echo '</form>';
		echo '</div></td></tr>';
	}

	/**
	 * Render the native add / edit product form.
	 *
	 * @param int $pid Product ID (0 to add).
	 */
	protected static function render_product_form( $pid ) {
		$product = $pid ? wc_get_product( $pid ) : null;
		if ( $pid && ! $product ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html__( 'That product could not be found.', 'sycomp-b2b-portal' ) . '</div>';
			return;
		}

		$back = add_query_arg( 'section', 'products', self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to products', 'sycomp-b2b-portal' ) . '</a></p>';
		self::page_head( $product ? __( 'Edit Product', 'sycomp-b2b-portal' ) : __( 'Add Product', 'sycomp-b2b-portal' ) );
		self::notice();

		$cur_cat   = 0;
		$cur_brand = '';
		$cur_comp  = array();
		if ( $product ) {
			$ct = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
			$cur_cat = ( ! is_wp_error( $ct ) && ! empty( $ct ) ) ? (int) $ct[0] : 0;
			$bt = wp_get_post_terms( $pid, Sycomp_B2B_Post_Types::TAX_BRAND, array( 'fields' => 'names' ) );
			$cur_brand = ( ! is_wp_error( $bt ) && ! empty( $bt ) ) ? $bt[0] : '';
			$cur_comp  = array_map( 'intval', (array) get_post_meta( $pid, '_sycomp_company', false ) );
		}

		echo '<form class="sy-form sy-form--card" method="post" enctype="multipart/form-data" action="' . esc_url( $back ) . '">';
		wp_nonce_field( 'sycomp_product_save', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="product_save">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( $pid ) . '">';

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Product details', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<div class="sy-form__grid">';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="p_name" required value="' . esc_attr( $product ? $product->get_name() : '' ) . '"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'SKU', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="p_sku" value="' . esc_attr( $product ? $product->get_sku() : '' ) . '"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Category', 'sycomp-b2b-portal' ) . '</span><select name="p_cat">';
		echo '<option value="0">' . esc_html__( '— None —', 'sycomp-b2b-portal' ) . '</option>';
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( ! is_wp_error( $cats ) ) {
			foreach ( $cats as $term ) {
				echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $term->term_id, $cur_cat, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Brand', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="p_brand" value="' . esc_attr( $cur_brand ) . '" placeholder="' . esc_attr__( 'e.g. Logitech', 'sycomp-b2b-portal' ) . '"></label>';



		echo '<label class="sy-field sy-field--file"><span class="sy-field__label">' . esc_html__( 'Product image', 'sycomp-b2b-portal' ) . '</span>';
		if ( $product && has_post_thumbnail( $pid ) ) {
			echo '<span class="sy-form__thumb">' . get_the_post_thumbnail( $pid, array( 60, 60 ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<input type="file" name="p_image" accept="image/*"></label>';

		echo '<label class="sy-field sy-field--wide"><span class="sy-field__label">' . esc_html__( 'Description', 'sycomp-b2b-portal' ) . '</span>';
		echo '<textarea name="p_desc" rows="4">' . esc_textarea( $product ? $product->get_description() : '' ) . '</textarea></label>';

		echo '</div></div></section>';

		// Procurement Price.
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Procurement Price', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Set the procurement price (vendor cost) and GP margin for each market. Leave a market blank to keep the product unpriced there. If a price is entered in a currency other than the market\'s own, it is converted to the local currency using the exchange rates.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<div class="sy-price-grid">';
		$sycomp_currencies = Sycomp_B2B_FX::currencies();
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			$val  = $pid ? Sycomp_B2B_Pricing::get_market_price( $pid, $key ) : '';
			$pcur = $pid ? Sycomp_B2B_Pricing::get_price_currency( $pid, $key ) : $market['currency'];
			$gp   = $pid ? Sycomp_B2B_Pricing::get_product_market_gp( $pid, $key ) : 15.00;

			$cust_price = 0.0;
			if ( '' !== $val && is_numeric( $val ) ) {
				$mcur = Sycomp_B2B_Markets::currency( $key );
				if ( $pcur !== $mcur ) {
					$converted = Sycomp_B2B_FX::convert( $val, $pcur, $mcur );
				} else {
					$converted = (float) $val;
				}
				if ( $gp > 0 && $gp < 100 ) {
					$cust_price = $converted / ( 1.0 - ( $gp / 100.0 ) );
				} else {
					$cust_price = $converted;
				}
			}
			$cust_price_show = $cust_price > 0 ? (string) wc_format_decimal( $cust_price, Sycomp_B2B_Markets::decimals( $key ) ) : '';

			echo '<div class="sycomp-market-price-card" data-market="' . esc_attr( $key ) . '" style="border: 1px solid var(--sy-border); padding: 12px; border-radius: var(--sy-radius); background: #fff; display: flex; flex-direction: column; gap: 10px;">';
			echo '<h3 style="margin: 0; font-size: var(--sy-text-sm); font-weight: 700; color: var(--sy-navy);">' . esc_html( $market['label'] ) . '</h3>';
			echo '<div style="display: flex; gap: 12px; flex-wrap: wrap;">';

			// Price and Currency Select field
			echo '<div class="sy-field" style="flex: 1 1 120px;">';
			echo '<span class="sy-field__label">' . esc_html__( 'Price (Vendor Cost)', 'sycomp-b2b-portal' ) . '</span>';
			echo '<span style="display:flex;gap:6px;">';
			echo '<input type="number" step="0.01" min="0" class="sycomp-price-input" name="price_' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" style="flex:1 1 auto;min-width:0;">';
			echo '<select class="sycomp-pricecur-select" name="pricecur_' . esc_attr( $key ) . '" style="flex:0 0 78px;" aria-label="' . esc_attr__( 'Price currency', 'sycomp-b2b-portal' ) . '">';
			foreach ( $sycomp_currencies as $sycomp_c ) {
				echo '<option value="' . esc_attr( $sycomp_c ) . '" ' . selected( $pcur, $sycomp_c, false ) . '>' . esc_html( $sycomp_c ) . '</option>';
			}
			echo '</select>';
			echo '</span>';
			echo '</div>';

			// GP field
			echo '<div class="sy-field" style="flex: 0 0 80px;">';
			echo '<span class="sy-field__label">' . esc_html__( 'GP (%)', 'sycomp-b2b-portal' ) . '</span>';
			echo '<input type="number" step="0.01" min="0" max="99" class="sycomp-gp-input" name="gp_' . esc_attr( $key ) . '" value="' . esc_attr( number_format( $gp, 2, '.', '' ) ) . '" style="width:100%;">';
			echo '</div>';

			echo '</div>'; // End flex-wrap container

			// Dynamic calculated customer price pill
			$hint_display = $cust_price_show ? 'block' : 'none';
			echo '<div class="sycomp-customer-price-hint" style="font-size: 11px; font-weight: bold; padding: 4px 8px; display: ' . esc_attr( $hint_display ) . '; background: #fef9c3; border: 1px solid #fef08a; color: #854d0e; border-radius: 3px;">';
			echo esc_html__( 'Customer Price:', 'sycomp-b2b-portal' ) . ' <span class="value">' . ( $cust_price_show ? esc_html( Sycomp_B2B_Markets::symbol( $key ) . ' ' . $cust_price_show ) : '' ) . '</span>';
			echo '</div>';

			echo '</div>'; // End sycomp-market-price-card
		}
		echo '</div></div>';

		// Embed our live calculation JS script
		$rates_js = array();
		foreach ( Sycomp_B2B_FX::currencies() as $cur ) {
			$rates_js[ $cur ] = Sycomp_B2B_FX::rate( $cur );
		}
		$market_details_js = array();
		foreach ( Sycomp_B2B_Markets::all() as $mkey => $m ) {
			$market_details_js[ $mkey ] = array(
				'currency' => $m['currency'],
				'symbol'   => $m['symbol'],
				'decimals' => $m['decimals']
			);
		}
		?>
		<script>
		jQuery(document).ready(function($) {
			var fxRates = <?php echo json_encode( $rates_js ); ?>;
			var marketDetails = <?php echo json_encode( $market_details_js ); ?>;

			function convertCurrency(amount, from, to) {
				amount = parseFloat(amount);
				if (isNaN(amount)) return 0;
				if (from === to || !from || !to) return amount;
				var rateFrom = parseFloat(fxRates[from] || 0);
				var rateTo = parseFloat(fxRates[to] || 0);
				if (rateFrom <= 0 || rateTo <= 0) return amount;
				return (amount / rateFrom) * rateTo;
			}

			$('.sycomp-market-price-card').each(function() {
				var $card = $(this);
				var marketKey = $card.attr('data-market');
				var $priceInput = $card.find('.sycomp-price-input');
				var $curSelect = $card.find('.sycomp-pricecur-select');
				var $gpInput = $card.find('.sycomp-gp-input');
				var $hint = $card.find('.sycomp-customer-price-hint');
				var $valSpan = $hint.find('.value');

				function updatePrice() {
					var rawPrice = $priceInput.val().trim();
					var gpVal = parseFloat($gpInput.val()) || 0;
					if (rawPrice === '' || isNaN(parseFloat(rawPrice))) {
						$hint.hide();
						return;
					}
					var vendorCost = parseFloat(rawPrice);
					var fromCur = $curSelect.val();
					var toCur = marketDetails[marketKey].currency;
					var symbol = marketDetails[marketKey].symbol;
					var decimals = marketDetails[marketKey].decimals;

					var converted = convertCurrency(vendorCost, fromCur, toCur);
					var customerCost;
					if (gpVal > 0 && gpVal < 100) {
						customerCost = converted / (1 - (gpVal / 100));
					} else {
						customerCost = converted;
					}
					var formatted = customerCost.toFixed(decimals);
					$valSpan.text(symbol + ' ' + formatted);
					$hint.show();
				}

				$priceInput.on('input change', updatePrice);
				$curSelect.on('change', updatePrice);
				$gpInput.on('input change', updatePrice);
			});
		});
		</script>
		<?php
		echo '</section>';

		// Company visibility.
		$companies = Sycomp_B2B_Post_Types::get_companies();
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Visible to companies', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		if ( empty( $companies ) ) {
			echo '<p class="sy-muted">' . esc_html__( 'No customer companies exist yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<p class="sy-muted">' . esc_html__( 'Tick the companies that may see and order this product.', 'sycomp-b2b-portal' ) . '</p>';
			echo '<div class="sy-checkgrid">';
			foreach ( $companies as $company ) {
				$checked = in_array( (int) $company->ID, $cur_comp, true ) ? ' checked' : '';
				echo '<label class="sy-check"><input type="checkbox" name="p_companies[]" value="' . esc_attr( $company->ID ) . '"' . esc_attr( $checked ) . '> ' . esc_html( get_the_title( $company ) ) . '</label>';
			}
			echo '</div>';
		}
		echo '</div></section>';

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html( $product ? __( 'Save product', 'sycomp-b2b-portal' ) : __( 'Create product', 'sycomp-b2b-portal' ) ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a>';
		echo '</div>';
		echo '</form>';
	}

	/**
	 * Render the native CSV import view.
	 */
	protected static function render_import() {
		$back = add_query_arg( 'section', 'products', self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to products', 'sycomp-b2b-portal' ) . '</a></p>';
		self::page_head(
			__( 'Import Data', 'sycomp-b2b-portal' ),
			__( 'Bulk-load products and per-market price lists from CSV files.', 'sycomp-b2b-portal' )
		);
		self::notice();

		echo '<div class="sy-adm-split">';

		// Products CSV.
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Import products', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Upload a product export CSV. Products are matched by SKU or handle, so re-importing updates rather than duplicates.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		wp_nonce_field( 'sycomp_import', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="import_run">';
		echo '<input type="hidden" name="import_type" value="products">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'CSV file', 'sycomp-b2b-portal' ) . '</span><input type="file" name="sycomp_csv" accept=".csv" required></label>';
		echo '<label class="sy-check"><input type="checkbox" name="import_images" value="1"> ' . esc_html__( 'Also download product images (slower)', 'sycomp-b2b-portal' ) . '</label>';
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent sy-btn--sm" type="submit">' . esc_html__( 'Import products', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form></div></section>';

		// Prices CSV.
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Import a price list', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Upload one price-list CSV per market. The SKU/handle and price columns are auto-detected.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		wp_nonce_field( 'sycomp_import', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="import_run">';
		echo '<input type="hidden" name="import_type" value="prices">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Market', 'sycomp-b2b-portal' ) . '</span><select name="import_market" required>';
		echo '<option value="">' . esc_html__( '— Select market —', 'sycomp-b2b-portal' ) . '</option>';
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $market['label'] . ' (' . $market['currency'] . ')' ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'CSV file', 'sycomp-b2b-portal' ) . '</span><input type="file" name="sycomp_csv" accept=".csv" required></label>';
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent sy-btn--sm" type="submit">' . esc_html__( 'Import price list', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form></div></section>';

		echo '</div>';
	}

	/**
	 * Render the bulk per-market price adjustment tool.
	 */
	protected static function render_bulk_price() {
		$back = add_query_arg( 'section', 'products', self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to products', 'sycomp-b2b-portal' ) . '</a></p>';
		self::page_head(
			__( 'Bulk pricing', 'sycomp-b2b-portal' ),
			__( 'Adjust every price in a market by a percentage, optionally limited to one category.', 'sycomp-b2b-portal' )
		);
		self::notice();

		echo '<section class="sy-panel" style="max-width:560px;"><div class="sy-panel__body">';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_bulkprice', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="bulkprice_apply">';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Market', 'sycomp-b2b-portal' ) . '</span><select name="bp_market" required>';
		foreach ( Sycomp_B2B_Markets::all() as $bp_key => $bp_market ) {
			echo '<option value="' . esc_attr( $bp_key ) . '">' . esc_html( $bp_market['label'] . ' (' . $bp_market['currency'] . ')' ) . '</option>';
		}
		echo '</select></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Category', 'sycomp-b2b-portal' ) . '</span><select name="bp_cat">';
		echo '<option value="">' . esc_html__( 'All categories', 'sycomp-b2b-portal' ) . '</option>';
		$bp_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( ! is_wp_error( $bp_cats ) ) {
			foreach ( $bp_cats as $bp_term ) {
				echo '<option value="' . esc_attr( $bp_term->slug ) . '">' . esc_html( $bp_term->name ) . '</option>';
			}
		}
		echo '</select></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Adjustment (%)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="number" step="0.01" name="bp_pct" required placeholder="' . esc_attr__( 'e.g. 5 or -10', 'sycomp-b2b-portal' ) . '"></label>';
		echo '<p class="sy-muted" style="font-size:.8rem;">' . esc_html__( 'A positive value raises prices, a negative value lowers them. Products already priced in that market are updated; unpriced products are left alone.', 'sycomp-b2b-portal' ) . '</p>';

		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit" onclick="return confirm(\'' . esc_js( __( 'Apply this price adjustment? It cannot be undone automatically.', 'sycomp-b2b-portal' ) ) . '\');">' . esc_html__( 'Apply adjustment', 'sycomp-b2b-portal' ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a></div>';
		echo '</form></div></section>';
	}

	/**
	 * Apply a bulk percentage adjustment to a market's prices.
	 */
	protected static function do_bulkprice() {
		if ( ! self::verify( 'sycomp_bulkprice' ) ) {
			return;
		}
		$market = isset( $_POST['bp_market'] ) ? sanitize_key( wp_unslash( $_POST['bp_market'] ) ) : '';
		$cat    = isset( $_POST['bp_cat'] ) ? sanitize_title( wp_unslash( $_POST['bp_cat'] ) ) : '';
		$pct    = isset( $_POST['bp_pct'] ) ? (float) wp_unslash( $_POST['bp_pct'] ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
			self::redirect( array( 'section' => 'products', 'pview' => 'bulkprice' ) );
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( '' !== $cat ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $cat,
				),
			);
		}
		$query    = new WP_Query( $args );
		$decimals = Sycomp_B2B_Markets::decimals( $market );
		$factor   = 1 + ( $pct / 100 );
		foreach ( $query->posts as $pid ) {
			$cur = Sycomp_B2B_Pricing::get_market_price( $pid, $market );
			if ( '' === $cur ) {
				continue;
			}
			$new = (float) $cur * $factor;
			if ( $new < 0 ) {
				$new = 0.0;
			}
			Sycomp_B2B_Pricing::set_market_price( $pid, $market, wc_format_decimal( $new, $decimals ) );
		}
		self::redirect( array( 'section' => 'products', 'pview' => 'bulkprice', 'done' => 'bulkprice_done' ) );
	}

	/* =====================================================================
	 * Section: Categories.
	 * ================================================================== */

	/**
	 * Render the Categories management screen.
	 */
	protected static function render_categories() {
		$edit_id   = isset( $_GET['edit_cat'] ) ? absint( wp_unslash( $_GET['edit_cat'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit_term = $edit_id ? get_term( $edit_id, 'product_cat' ) : null;
		if ( ! $edit_term || is_wp_error( $edit_term ) ) {
			$edit_term = null;
		}

		self::page_head(
			__( 'Categories', 'sycomp-b2b-portal' ),
			__( 'Product categories power the catalogue filters buyers see.', 'sycomp-b2b-portal' )
		);
		self::notice();

		echo '<div class="sy-adm-split">';

		echo '<section class="sy-panel sy-panel--form">';
		echo '<h2 class="sy-panel__title">' . esc_html( $edit_term ? __( 'Edit Category', 'sycomp-b2b-portal' ) : __( 'Add Category', 'sycomp-b2b-portal' ) ) . '</h2>';
		echo '<div class="sy-panel__body">';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_category', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="cat_save">';
		echo '<input type="hidden" name="cat_id" value="' . esc_attr( $edit_term ? $edit_term->term_id : 0 ) . '">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="cat_name" required value="' . esc_attr( $edit_term ? $edit_term->name : '' ) . '"></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Parent category', 'sycomp-b2b-portal' ) . '</span>';
		echo '<select name="cat_parent">';
		echo '<option value="0">' . esc_html__( 'None', 'sycomp-b2b-portal' ) . '</option>';
		$all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( ! is_wp_error( $all ) ) {
			foreach ( $all as $term ) {
				if ( $edit_term && (int) $term->term_id === (int) $edit_term->term_id ) {
					continue;
				}
				$sel = ( $edit_term && (int) $term->term_id === (int) $edit_term->parent ) ? ' selected' : '';
				echo '<option value="' . esc_attr( $term->term_id ) . '"' . esc_attr( $sel ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></label>';
		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit">' . esc_html( $edit_term ? __( 'Update category', 'sycomp-b2b-portal' ) : __( 'Add category', 'sycomp-b2b-portal' ) ) . '</button>';
		if ( $edit_term ) {
			echo '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( add_query_arg( 'section', 'categories', self::manage_url() ) ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a>';
		}
		echo '</div></form></div></section>';

		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">' . esc_html__( 'All Categories', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body sy-panel__body--flush">';
		if ( is_wp_error( $cats ) || empty( $cats ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No categories yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin"><thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Slug', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Products', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $cats as $term ) {
				$ed = add_query_arg( array( 'section' => 'categories', 'edit_cat' => $term->term_id ), self::manage_url() );
				echo '<tr>';
				echo '<td><strong>' . esc_html( $term->name ) . '</strong></td>';
				echo '<td><code class="sy-code">' . esc_html( $term->slug ) . '</code></td>';
				echo '<td><span class="sy-chip">' . esc_html( number_format_i18n( $term->count ) ) . '</span></td>';
				echo '<td class="sy-col-act"><div class="sy-rowact">';
				echo '<a class="sy-iconbtn" href="' . esc_url( $ed ) . '" title="' . esc_attr__( 'Edit', 'sycomp-b2b-portal' ) . '">';
				echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>';
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Delete this category? Products keep existing.', 'sycomp-b2b-portal' ) ) . '\');">';
				wp_nonce_field( 'sycomp_category', 'sycomp_nonce' );
				echo '<input type="hidden" name="sycomp_admin_action" value="cat_delete">';
				echo '<input type="hidden" name="cat_id" value="' . esc_attr( $term->term_id ) . '">';
				echo '<button class="sy-iconbtn sy-iconbtn--danger" type="submit" title="' . esc_attr__( 'Delete', 'sycomp-b2b-portal' ) . '">';
				echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h12l1-14"/></svg></button>';
				echo '</form>';
				echo '</div></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';
		echo '</div>';
	}

	/* =====================================================================
	 * Section: Proposals.
	 * ================================================================== */

	/**
	 * Route the PO section to a stage list or a single PO detail.
	 */
	protected static function render_po() {
		if ( isset( $_GET['new'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_po_new();
			return;
		}
		$detail = isset( $_GET['detail'] ) ? absint( wp_unslash( $_GET['detail'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $detail ) {
			$order = wc_get_order( $detail );
			if ( $order && Sycomp_B2B_PO::is_po( $order ) ) {
				if ( isset( $_GET['edititems'] ) && $order->has_status( Sycomp_B2B_PO::STATUS_OPEN ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					self::render_po_edit( $order );
				} else {
					self::render_po_detail( $order );
				}
				return;
			}
		}
		self::render_po_list();
	}

	/**
	 * One product line row on the New Proposal builder.
	 *
	 * @param WP_Post[]  $products Published products.
	 * @param int|string $index    Row index (or the JS placeholder '__i__').
	 * @param string     $market   Market key — used to seed each option's
	 *                              market price for the live line-total.
	 */
	protected static function po_line_row( $products, $index, $market = '' ) {
		echo '<tr class="sy-po-line">';
		echo '<td><select name="line[' . esc_attr( $index ) . '][product]" class="sy-po-prod">';
		echo '<option value="">' . esc_html__( '— Select product —', 'sycomp-b2b-portal' ) . '</option>';
		foreach ( $products as $sycomp_p ) {
			$sycomp_wc  = wc_get_product( $sycomp_p->ID );
			$sycomp_sku = ( $sycomp_wc && $sycomp_wc->get_sku() ) ? ' (' . $sycomp_wc->get_sku() . ')' : '';
			$sycomp_mp  = $market ? Sycomp_B2B_Pricing::get_market_price( $sycomp_p->ID, $market ) : '';
			echo '<option value="' . esc_attr( $sycomp_p->ID ) . '" data-price="' . esc_attr( (string) $sycomp_mp ) . '">' . esc_html( get_the_title( $sycomp_p ) . $sycomp_sku ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="number" class="sy-po-qty" name="line[' . esc_attr( $index ) . '][qty]" min="0" step="1" placeholder="0"></td>';
		echo '<td><input type="text" class="sy-po-price" name="line[' . esc_attr( $index ) . '][price]" placeholder="' . esc_attr__( 'Market price', 'sycomp-b2b-portal' ) . '"></td>';
		echo '<td class="sy-po-linetotal sy-col-price">&mdash;</td>';
		echo '<td><button type="button" class="sy-iconbtn sy-iconbtn--danger sy-po-del" title="' . esc_attr__( 'Remove line', 'sycomp-b2b-portal' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	/**
	 * Render the New Proposal builder — a two-step screen: choose a
	 * customer location, then build the order.
	 */
	protected static function render_po_new() {
		$back = add_query_arg( array( 'section' => 'po', 'po' => 'open' ), self::manage_url() );
		$loc  = isset( $_GET['loc'] ) ? absint( wp_unslash( $_GET['loc'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$location  = $loc ? get_post( $loc ) : null;
		$valid_loc = $location && Sycomp_B2B_Post_Types::LOCATION === $location->post_type;

		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to proposals', 'sycomp-b2b-portal' ) . '</a></p>';

		// --- Step 1: choose a location ------------------------------------
		if ( ! $valid_loc ) {
			self::page_head(
				__( 'New proposal', 'sycomp-b2b-portal' ),
				__( 'Raise a proposal on behalf of a customer. Start by choosing the delivery location.', 'sycomp-b2b-portal' )
			);
			self::notice();

			echo '<section class="sy-panel" style="max-width:560px;"><div class="sy-panel__body">';
			echo '<form method="get" action="' . esc_url( self::manage_url() ) . '" class="sy-form">';
			echo '<input type="hidden" name="section" value="po">';
			echo '<input type="hidden" name="new" value="1">';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Customer location', 'sycomp-b2b-portal' ) . '</span>';
			echo '<select name="loc" required>';
			echo '<option value="">' . esc_html__( '— Select a location —', 'sycomp-b2b-portal' ) . '</option>';
			$has_loc = false;
			foreach ( Sycomp_B2B_Post_Types::get_companies() as $sycomp_co ) {
				$sycomp_locs = Sycomp_B2B_Post_Types::get_company_locations( $sycomp_co->ID );
				if ( empty( $sycomp_locs ) ) {
					continue;
				}
				echo '<optgroup label="' . esc_attr( get_the_title( $sycomp_co ) ) . '">';
				foreach ( $sycomp_locs as $sycomp_l ) {
					$has_loc     = true;
					$sycomp_lmk  = Sycomp_B2B_Post_Types::get_location_market( $sycomp_l->ID );
					echo '<option value="' . esc_attr( $sycomp_l->ID ) . '">' . esc_html( get_the_title( $sycomp_l ) . ' — ' . Sycomp_B2B_Markets::label( $sycomp_lmk ) ) . '</option>';
				}
				echo '</optgroup>';
			}
			echo '</select></label>';
			if ( ! $has_loc ) {
				echo '<p class="sy-muted">' . esc_html__( 'No customer locations exist yet — add a company and a location under Customers first.', 'sycomp-b2b-portal' ) . '</p>';
			}
			echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Continue', 'sycomp-b2b-portal' ) . '</button></div>';
			echo '</form></div></section>';
			return;
		}

		// --- Step 2: build the order for this location --------------------
		$company_id = Sycomp_B2B_Post_Types::get_location_company( $loc );
		$market     = Sycomp_B2B_Post_Types::get_location_market( $loc );
		$m          = Sycomp_B2B_Markets::get( $market );
		$loc_addr   = Sycomp_B2B_Post_Types::get_location_address( $loc );

		self::page_head(
			/* translators: %s: company name. */
			sprintf( __( 'New proposal — %s', 'sycomp-b2b-portal' ), $company_id ? get_the_title( $company_id ) : '' ),
			sprintf(
				/* translators: 1: location, 2: market, 3: currency. */
				__( '%1$s · pricing in %2$s', 'sycomp-b2b-portal' ),
				get_the_title( $location ),
				$m ? $m['label'] . ' (' . $m['currency'] . ')' : $market
			)
		);
		self::notice();

		$products = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_po_create', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="po_create">';
		echo '<input type="hidden" name="loc" value="' . esc_attr( $loc ) . '">';

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Order details', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Attribute to buyer (optional)', 'sycomp-b2b-portal' ) . '</span><select name="buyer">';
		echo '<option value="">' . esc_html__( '— No specific buyer —', 'sycomp-b2b-portal' ) . '</option>';
		foreach ( get_users( array( 'meta_key' => Sycomp_B2B_User::META_COMPANY, 'meta_value' => $company_id, 'orderby' => 'display_name' ) ) as $sycomp_b ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			echo '<option value="' . esc_attr( $sycomp_b->ID ) . '">' . esc_html( $sycomp_b->display_name . ' (' . $sycomp_b->user_email . ')' ) . '</option>';
		}
		echo '</select></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Starting status', 'sycomp-b2b-portal' ) . '</span><select name="po_status">';
		echo '<option value="process">' . esc_html__( 'In-Process', 'sycomp-b2b-portal' ) . '</option>';
		echo '<option value="open">' . esc_html__( 'Open (awaiting review)', 'sycomp-b2b-portal' ) . '</option>';
		echo '</select></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'PO reference (optional)', 'sycomp-b2b-portal' ) . '</span><input type="text" name="po_ref"></label>';
		echo '</div>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Delivery address', 'sycomp-b2b-portal' ) . '</span><textarea name="delivery" rows="3">' . esc_textarea( $loc_addr ) . '</textarea></label>';
		echo '</div></section>';

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Products', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Add the products for this order. Leave the unit price blank to use the market price, or enter a value to override it.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<table class="sy-table sy-table--admin" id="sy-po-lines" data-currency-symbol="' . esc_attr( $m ? $m['symbol'] : '' ) . '" data-decimals="' . esc_attr( (string) ( $m ? (int) $m['decimals'] : 2 ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Qty', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:160px;">' . esc_html__( 'Unit price', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:140px;">' . esc_html__( 'Line total', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:44px;"></th>';
		echo '</tr></thead><tbody>';
		for ( $sycomp_i = 0; $sycomp_i < 3; $sycomp_i++ ) {
			self::po_line_row( $products, $sycomp_i, $market );
		}
		echo '</tbody>';
		echo '<tfoot><tr><th colspan="3" class="sy-table__totlabel">' . esc_html__( 'Estimated order total', 'sycomp-b2b-portal' ) . '</th><th class="sy-po-grandtotal sy-col-price">&mdash;</th><th></th></tr></tfoot>';
		echo '</table>';
		echo '<p><button type="button" class="sy-btn sy-btn--ghost sy-btn--sm" id="sy-po-addline">' . esc_html__( '+ Add line', 'sycomp-b2b-portal' ) . '</button></p>';
		echo '<p class="sy-muted" style="font-size:.82rem;">' . esc_html__( 'Totals are an estimate from unit prices and exclude tax.', 'sycomp-b2b-portal' ) . '</p>';
		echo '</div></section>';

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Create proposal', 'sycomp-b2b-portal' ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a>';
		echo '</div>';
		echo '</form>';
		?>
		<template id="sy-po-line-tpl"><?php self::po_line_row( $products, '__i__', $market ); ?></template>
		<script>
		( function () {
			var tbody = document.querySelector( '#sy-po-lines tbody' );
			var tpl   = document.getElementById( 'sy-po-line-tpl' );
			var add   = document.getElementById( 'sy-po-addline' );
			var idx   = 100;
			function wireDelete( row ) {
				var del = row.querySelector( '.sy-po-del' );
				if ( del ) {
					del.addEventListener( 'click', function () { row.remove(); } );
				}
			}
			if ( tbody ) {
				Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), wireDelete );
			}
			if ( add && tpl && tbody ) {
				add.addEventListener( 'click', function () {
					var wrap = document.createElement( 'tbody' );
					wrap.innerHTML = tpl.innerHTML.replace( /__i__/g, String( idx++ ) ).trim();
					var row = wrap.firstElementChild;
					if ( row ) {
						tbody.appendChild( row );
						wireDelete( row );
					}
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Create a proposal on behalf of a customer.
	 */
	protected static function do_po_create() {
		if ( ! self::verify( 'sycomp_po_create' ) ) {
			return;
		}
		$loc      = isset( $_POST['loc'] ) ? absint( wp_unslash( $_POST['loc'] ) ) : 0;
		$location = $loc ? get_post( $loc ) : null;
		if ( ! $location || Sycomp_B2B_Post_Types::LOCATION !== $location->post_type ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}
		$company_id = Sycomp_B2B_Post_Types::get_location_company( $loc );
		$market     = Sycomp_B2B_Post_Types::get_location_market( $loc );
		if ( ! $company_id || ! Sycomp_B2B_Markets::exists( $market ) ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}

		$buyer_id = isset( $_POST['buyer'] ) ? absint( wp_unslash( $_POST['buyer'] ) ) : 0;
		if ( $buyer_id && (int) Sycomp_B2B_User::get_company( $buyer_id ) !== (int) $company_id ) {
			$buyer_id = 0;
		}
		$po_ref   = isset( $_POST['po_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['po_ref'] ) ) : '';
		$delivery = isset( $_POST['delivery'] ) ? sanitize_textarea_field( wp_unslash( $_POST['delivery'] ) ) : '';
		$status   = ( isset( $_POST['po_status'] ) && 'open' === $_POST['po_status'] )
			? Sycomp_B2B_PO::STATUS_OPEN
			: Sycomp_B2B_PO::STATUS_PROCESS;

		$raw_lines = ( isset( $_POST['line'] ) && is_array( $_POST['line'] ) ) ? wp_unslash( $_POST['line'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$lines     = array();
		foreach ( $raw_lines as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = isset( $row['product'] ) ? absint( $row['product'] ) : 0;
			$qty = isset( $row['qty'] ) ? absint( $row['qty'] ) : 0;
			if ( ! $pid || $qty < 1 ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$override = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			if ( '' !== $override && is_numeric( $override ) ) {
				$unit = (float) wc_format_decimal( $override );
			} else {
				$mp   = Sycomp_B2B_Pricing::get_effective_price( $pid, $market );
				$unit = ( '' !== $mp ) ? (float) $mp : 0.0;
			}
			$lines[] = array( 'product' => $product, 'qty' => $qty, 'unit' => $unit );
		}
		if ( empty( $lines ) ) {
			self::redirect( array( 'section' => 'po', 'new' => 1, 'loc' => $loc, 'done' => 'po_nolines' ) );
		}

		$order = wc_create_order();
		if ( is_wp_error( $order ) || ! $order ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}
		$order->set_currency( Sycomp_B2B_Markets::currency( $market ) );

		$items_total = 0.0;
		foreach ( $lines as $l ) {
			$line_total   = round( $l['unit'] * $l['qty'], 2 );
			$items_total += $line_total;
			$order->add_product( $l['product'], $l['qty'], array( 'subtotal' => $line_total, 'total' => $line_total ) );
		}

		$tax = Sycomp_B2B_Tax::calc( $items_total, $market );
		if ( $tax > 0 ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( Sycomp_B2B_Tax::display_label( $market ) );
			$fee->set_total( (string) $tax );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );
		}

		$order->update_meta_data( Sycomp_B2B_PO::META_LOCATION, $loc );
		$order->update_meta_data( Sycomp_B2B_PO::META_MARKET, $market );
		$order->update_meta_data( Sycomp_B2B_PO::META_COMPANY, $company_id );
		if ( '' !== $po_ref ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_PO_REF, $po_ref );
		}
		if ( '' === trim( $delivery ) ) {
			$delivery = (string) Sycomp_B2B_Post_Types::get_location_address( $loc );
		}
		if ( '' !== trim( $delivery ) ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_DELIVERY, $delivery );
			$order->set_billing_address_1( str_replace( array( "\r\n", "\r", "\n" ), ', ', $delivery ) );
		}
		$order->set_billing_company( (string) get_the_title( $company_id ) );
		$order->set_created_via( 'sycomp-b2b-portal' );

		if ( $buyer_id ) {
			$order->set_customer_id( $buyer_id );
			$buyer = get_userdata( $buyer_id );
			if ( $buyer ) {
				$order->set_billing_email( $buyer->user_email );
				$order->set_billing_first_name( (string) $buyer->first_name );
				$order->set_billing_last_name( (string) $buyer->last_name );
			}
		}

		$order->calculate_totals( false );
		$order->set_status( $status );
		$order->save();
		$order->add_order_note( __( 'Proposal raised by Sycomp staff on behalf of the customer.', 'sycomp-b2b-portal' ) );

		$stage = ( Sycomp_B2B_PO::STATUS_OPEN === $status ) ? 'open' : 'process';
		self::redirect( array( 'section' => 'po', 'po' => $stage, 'detail' => $order->get_id(), 'done' => 'po_created' ) );
	}

	/**
	 * Duplicate an existing proposal into a new Open PO.
	 */
	protected static function do_po_duplicate() {
		if ( ! self::verify( 'sycomp_po' ) ) {
			return;
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$src      = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $src || ! Sycomp_B2B_PO::is_po( $src ) ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}

		$market     = (string) $src->get_meta( Sycomp_B2B_PO::META_MARKET );
		$company_id = (int) $src->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$loc        = (int) $src->get_meta( Sycomp_B2B_PO::META_LOCATION );

		$order = wc_create_order();
		if ( is_wp_error( $order ) || ! $order ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}
		$order->set_currency( ( $market && Sycomp_B2B_Markets::exists( $market ) ) ? Sycomp_B2B_Markets::currency( $market ) : $src->get_currency() );

		$items_total = 0.0;
		foreach ( $src->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = (int) $item->get_quantity();
			if ( ! $product || $qty < 1 ) {
				continue;
			}
			$line_total   = (float) $item->get_total();
			$items_total += $line_total;
			$order->add_product( $product, $qty, array( 'subtotal' => $line_total, 'total' => $line_total ) );
		}

		if ( $market && Sycomp_B2B_Markets::exists( $market ) ) {
			$tax = Sycomp_B2B_Tax::calc( $items_total, $market );
			if ( $tax > 0 ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( Sycomp_B2B_Tax::display_label( $market ) );
				$fee->set_total( (string) $tax );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
			}
		}

		if ( $loc ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_LOCATION, $loc );
		}
		if ( $market ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_MARKET, $market );
		}
		if ( $company_id ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_COMPANY, $company_id );
			$order->set_billing_company( (string) get_the_title( $company_id ) );
		}
		$delivery = (string) $src->get_meta( Sycomp_B2B_PO::META_DELIVERY );
		if ( '' !== trim( $delivery ) ) {
			$order->update_meta_data( Sycomp_B2B_PO::META_DELIVERY, $delivery );
			$order->set_billing_address_1( str_replace( array( "\r\n", "\r", "\n" ), ', ', $delivery ) );
		}
		$order->set_created_via( 'sycomp-b2b-portal' );
		$cust = $src->get_customer_id();
		if ( $cust ) {
			$order->set_customer_id( $cust );
			$order->set_billing_email( $src->get_billing_email() );
			$order->set_billing_first_name( $src->get_billing_first_name() );
			$order->set_billing_last_name( $src->get_billing_last_name() );
		}

		$order->calculate_totals( false );
		$order->set_status( Sycomp_B2B_PO::STATUS_OPEN );
		$order->save();
		/* translators: %s: source PO number. */
		$order->add_order_note( sprintf( __( 'Duplicated from proposal #%s.', 'sycomp-b2b-portal' ), $src->get_order_number() ) );

		self::redirect( array( 'section' => 'po', 'po' => 'open', 'detail' => $order->get_id(), 'done' => 'po_created' ) );
	}

	/**
	 * Render the edit-items screen for an Open proposal.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_po_edit( $order ) {
		$stage  = isset( $_GET['po'] ) ? sanitize_key( wp_unslash( $_GET['po'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		$market = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$back   = add_query_arg( array( 'section' => 'po', 'po' => $stage, 'detail' => $order->get_id() ), self::manage_url() );

		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to proposal', 'sycomp-b2b-portal' ) . '</a></p>';
		self::page_head(
			/* translators: %s: PO number. */
			sprintf( __( 'Edit proposal #%s', 'sycomp-b2b-portal' ), $order->get_order_number() ),
			__( 'Adjust quantities, remove lines or add products. Only open POs can be edited.', 'sycomp-b2b-portal' )
		);
		self::notice();

		$products = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_po_edit', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="po_edit">';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		echo '<input type="hidden" name="po" value="' . esc_attr( $stage ) . '">';

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Current items', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body sy-panel__body--flush">';
		echo '<table class="sy-table sy-table--admin"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Qty', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:130px;">' . esc_html__( 'Unit price', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Remove', 'sycomp-b2b-portal' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $order->get_items() as $item_id => $item ) {
			$qty  = (int) $item->get_quantity();
			$line = (float) $item->get_total();
			$unit = $qty ? $line / $qty : $line;
			echo '<tr>';
			echo '<td>' . esc_html( $item->get_name() ) . '</td>';
			echo '<td><input type="number" min="1" step="1" name="exqty[' . esc_attr( $item_id ) . ']" value="' . esc_attr( $qty ) . '"></td>';
			echo '<td>' . wp_kses_post( self::po_money( $unit, $market ) ) . '</td>';
			echo '<td><input type="checkbox" name="exremove[' . esc_attr( $item_id ) . ']" value="1"></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Add products', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<table class="sy-table sy-table--admin" id="sy-po-lines" data-currency-symbol="' . esc_attr( Sycomp_B2B_Markets::symbol( $market ) ) . '" data-decimals="' . esc_attr( (string) Sycomp_B2B_Markets::decimals( $market ) ) . '"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Qty', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:160px;">' . esc_html__( 'Unit price', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:140px;">' . esc_html__( 'Line total', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="width:44px;"></th>';
		echo '</tr></thead><tbody>';
		for ( $sycomp_i = 0; $sycomp_i < 2; $sycomp_i++ ) {
			self::po_line_row( $products, $sycomp_i, $market );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="sy-btn sy-btn--ghost sy-btn--sm" id="sy-po-addline">' . esc_html__( '+ Add line', 'sycomp-b2b-portal' ) . '</button></p>';
		echo '</div></section>';

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save changes', 'sycomp-b2b-portal' ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a>';
		echo '</div>';
		echo '</form>';
		?>
		<template id="sy-po-line-tpl"><?php self::po_line_row( $products, '__i__', $market ); ?></template>
		<script>
		( function () {
			var tbody = document.querySelector( '#sy-po-lines tbody' );
			var tpl   = document.getElementById( 'sy-po-line-tpl' );
			var add   = document.getElementById( 'sy-po-addline' );
			var idx   = 100;
			function wireDelete( row ) {
				var del = row.querySelector( '.sy-po-del' );
				if ( del ) {
					del.addEventListener( 'click', function () { row.remove(); } );
				}
			}
			if ( tbody ) {
				Array.prototype.forEach.call( tbody.querySelectorAll( 'tr' ), wireDelete );
			}
			if ( add && tpl && tbody ) {
				add.addEventListener( 'click', function () {
					var wrap = document.createElement( 'tbody' );
					wrap.innerHTML = tpl.innerHTML.replace( /__i__/g, String( idx++ ) ).trim();
					var row = wrap.firstElementChild;
					if ( row ) {
						tbody.appendChild( row );
						wireDelete( row );
					}
				} );
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Apply edits to an Open proposal's line items.
	 */
	protected static function do_po_edit() {
		if ( ! self::verify( 'sycomp_po_edit' ) ) {
			return;
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || ! Sycomp_B2B_PO::is_po( $order ) || ! $order->has_status( Sycomp_B2B_PO::STATUS_OPEN ) ) {
			self::redirect( array( 'section' => 'po', 'po' => 'open', 'done' => 'po_error' ) );
		}
		$stage  = isset( $_POST['po'] ) ? sanitize_key( wp_unslash( $_POST['po'] ) ) : 'open';
		$market = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );

		$exqty    = ( isset( $_POST['exqty'] ) && is_array( $_POST['exqty'] ) ) ? wp_unslash( $_POST['exqty'] ) : array();       // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$exremove = ( isset( $_POST['exremove'] ) && is_array( $_POST['exremove'] ) ) ? wp_unslash( $_POST['exremove'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( isset( $exremove[ $item_id ] ) ) {
				$order->remove_item( $item_id );
				continue;
			}
			$old_qty   = (int) $item->get_quantity();
			$old_total = (float) $item->get_total();
			$unit      = $old_qty ? $old_total / $old_qty : $old_total;
			$new_qty   = isset( $exqty[ $item_id ] ) ? max( 1, absint( $exqty[ $item_id ] ) ) : $old_qty;
			$new_total = round( $unit * $new_qty, 2 );
			$item->set_quantity( $new_qty );
			$item->set_subtotal( $new_total );
			$item->set_total( $new_total );
			$item->save();
		}

		$raw_lines = ( isset( $_POST['line'] ) && is_array( $_POST['line'] ) ) ? wp_unslash( $_POST['line'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $raw_lines as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = isset( $row['product'] ) ? absint( $row['product'] ) : 0;
			$qty = isset( $row['qty'] ) ? absint( $row['qty'] ) : 0;
			if ( ! $pid || $qty < 1 ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$override = isset( $row['price'] ) ? trim( (string) $row['price'] ) : '';
			if ( '' !== $override && is_numeric( $override ) ) {
				$unit = (float) wc_format_decimal( $override );
			} else {
				$mp   = Sycomp_B2B_Pricing::get_effective_price( $pid, $market );
				$unit = ( '' !== $mp ) ? (float) $mp : 0.0;
			}
			$line_total = round( $unit * $qty, 2 );
			$order->add_product( $product, $qty, array( 'subtotal' => $line_total, 'total' => $line_total ) );
		}

		// Rebuild the tax fee from the new items total.
		$items_total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$items_total += (float) $item->get_total();
		}
		foreach ( $order->get_fees() as $fee_id => $fee ) {
			$order->remove_item( $fee_id );
		}
		if ( $market && Sycomp_B2B_Markets::exists( $market ) ) {
			$tax = Sycomp_B2B_Tax::calc( $items_total, $market );
			if ( $tax > 0 ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( Sycomp_B2B_Tax::display_label( $market ) );
				$fee->set_total( (string) $tax );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
			}
		}

		$order->calculate_totals( false );
		$order->save();
		$order->add_order_note( __( 'Proposal items edited by Sycomp staff.', 'sycomp-b2b-portal' ) );

		self::redirect( array( 'section' => 'po', 'po' => $stage, 'detail' => $order->get_id(), 'done' => 'po_updated' ) );
	}

	/**
	 * Render a PO list — a single lifecycle stage, or all stages — with
	 * market and customer-company filters.
	 */
	protected static function render_po_list() {
		$stages = array(
			'open'      => array( Sycomp_B2B_PO::STATUS_OPEN, __( 'Open Proposals', 'sycomp-b2b-portal' ) ),
			'process'   => array( Sycomp_B2B_PO::STATUS_PROCESS, __( 'In-Process Proposals', 'sycomp-b2b-portal' ) ),
			'closed'    => array( Sycomp_B2B_PO::STATUS_CLOSED, __( 'Closed Proposals', 'sycomp-b2b-portal' ) ),
			'cancelled' => array( Sycomp_B2B_PO::STATUS_CANCELLED, __( 'Cancelled Proposals', 'sycomp-b2b-portal' ) ),
			'all'       => array(
				array(
					Sycomp_B2B_PO::STATUS_OPEN,
					Sycomp_B2B_PO::STATUS_PROCESS,
					Sycomp_B2B_PO::STATUS_CLOSED,
					Sycomp_B2B_PO::STATUS_CANCELLED,
				),
				__( 'All Proposals', 'sycomp-b2b-portal' ),
			),
		);
		$stage = isset( $_GET['po'] ) ? sanitize_key( wp_unslash( $_GET['po'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $stages[ $stage ] ) ) {
			$stage = 'open';
		}
		$mk = isset( $_GET['mk'] ) ? sanitize_key( wp_unslash( $_GET['mk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$co = isset( $_GET['co'] ) ? absint( wp_unslash( $_GET['co'] ) ) : 0;        // phpcs:ignore WordPress.Security.NonceVerification

		$sycomp_export = '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url(
			add_query_arg(
				array_filter(
					array(
						'section'          => 'po',
						'po'               => $stage,
						'mk'               => $mk,
						'co'               => $co,
						'sycomp_po_export' => 1,
						'_sycexp'          => wp_create_nonce( 'sycomp_po_export' ),
					)
				),
				self::manage_url()
			)
		) . '">' . esc_html__( 'Export CSV', 'sycomp-b2b-portal' ) . '</a>';
		$sycomp_newpo = '<a class="sy-btn sy-btn--accent sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'po', 'new' => 1 ), self::manage_url() ) ) . '">' . esc_html__( 'New proposal', 'sycomp-b2b-portal' ) . '</a>';
		self::page_head( $stages[ $stage ][1], '', $sycomp_export . ' ' . $sycomp_newpo );
		self::notice();

		// Status filter chips — replaces the former per-status nav tabs.
		$sycomp_status_labels = array(
			'open'      => __( 'Open', 'sycomp-b2b-portal' ),
			'process'   => __( 'In-Process', 'sycomp-b2b-portal' ),
			'closed'    => __( 'Closed', 'sycomp-b2b-portal' ),
			'cancelled' => __( 'Cancelled', 'sycomp-b2b-portal' ),
			'all'       => __( 'All', 'sycomp-b2b-portal' ),
		);
		$sycomp_status_base = array( 'section' => 'po' );
		if ( $co ) {
			$sycomp_status_base['co'] = $co;
		}
		if ( $mk ) {
			$sycomp_status_base['mk'] = $mk;
		}
		echo '<nav class="sy-mkfilter sy-postatus" aria-label="' . esc_attr__( 'Proposal status', 'sycomp-b2b-portal' ) . '">';
		echo '<span class="sy-mkfilter__label">' . esc_html__( 'Status', 'sycomp-b2b-portal' ) . '</span>';
		foreach ( $sycomp_status_labels as $sycomp_sk => $sycomp_slabel ) {
			$sycomp_scount = count( wc_get_orders( array( 'status' => $stages[ $sycomp_sk ][0], 'limit' => -1, 'return' => 'ids' ) ) );
			$sycomp_surl   = add_query_arg( array_merge( $sycomp_status_base, array( 'po' => $sycomp_sk ) ), self::manage_url() );
			echo '<a class="sy-mkfilter__tab' . ( $stage === $sycomp_sk ? ' is-active' : '' ) . '" href="' . esc_url( $sycomp_surl ) . '"' . ( $stage === $sycomp_sk ? ' aria-current="page"' : '' ) . '>';
			echo esc_html( $sycomp_slabel ) . ' <span class="sy-mkfilter__count">' . esc_html( number_format_i18n( $sycomp_scount ) ) . '</span></a>';
		}
		echo '</nav>';

		$orders = wc_get_orders(
			array(
				'status'  => $stages[ $stage ][0],
				'limit'   => 200,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		if ( $mk && Sycomp_B2B_Markets::exists( $mk ) ) {
			$orders = array_values(
				array_filter(
					$orders,
					static function ( $order ) use ( $mk ) {
						return (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET ) === $mk;
					}
				)
			);
		}
		if ( $co ) {
			$orders = array_values(
				array_filter(
					$orders,
					static function ( $order ) use ( $co ) {
						return (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY ) === $co;
					}
				)
			);
		}

		// Customer-company filter — preserved across the market tabs below.
		$companies = Sycomp_B2B_Post_Types::get_companies();
		if ( $companies ) {
			echo '<form class="sy-adm-filters" method="get" action="' . esc_url( self::manage_url() ) . '">';
			echo '<input type="hidden" name="section" value="po">';
			echo '<input type="hidden" name="po" value="' . esc_attr( $stage ) . '">';
			if ( $mk ) {
				echo '<input type="hidden" name="mk" value="' . esc_attr( $mk ) . '">';
			}
			echo '<strong style="font-size:.8rem;">' . esc_html__( 'Customer', 'sycomp-b2b-portal' ) . '</strong>';
			echo '<select name="co" aria-label="' . esc_attr__( 'Filter by customer', 'sycomp-b2b-portal' ) . '" onchange="this.form.submit()">';
			echo '<option value="">' . esc_html__( 'All customers', 'sycomp-b2b-portal' ) . '</option>';
			foreach ( $companies as $company ) {
				echo '<option value="' . esc_attr( $company->ID ) . '" ' . selected( $co, $company->ID, false ) . '>' . esc_html( get_the_title( $company ) ) . '</option>';
			}
			echo '</select>';
			echo '<noscript><button type="submit" class="sy-btn sy-btn--primary sy-btn--sm">' . esc_html__( 'Filter', 'sycomp-b2b-portal' ) . '</button></noscript>';
			echo '</form>';
		}

		$base = array( 'section' => 'po', 'po' => $stage );
		if ( $co ) {
			$base['co'] = $co;
		}
		echo '<nav class="sy-mkfilter" aria-label="' . esc_attr__( 'Market', 'sycomp-b2b-portal' ) . '">';
		echo '<span class="sy-mkfilter__label">' . esc_html__( 'Market', 'sycomp-b2b-portal' ) . '</span>';
		echo '<a class="sy-mkfilter__tab' . ( $mk ? '' : ' is-active' ) . '" href="' . esc_url( add_query_arg( $base, self::manage_url() ) ) . '">' . esc_html__( 'All', 'sycomp-b2b-portal' ) . '</a>';
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			$url = add_query_arg( array_merge( $base, array( 'mk' => $key ) ), self::manage_url() );
			echo '<a class="sy-mkfilter__tab' . ( $mk === $key ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '" title="' . esc_attr( $market['label'] ) . '">' . esc_html( $market['country_code'] ) . '</a>';
		}
		echo '</nav>';

		echo '<section class="sy-panel">';
		echo '<h2 class="sy-panel__title">';
		/* translators: %s: record count. */
		printf( esc_html__( 'Viewing %s records', 'sycomp-b2b-portal' ), esc_html( number_format_i18n( count( $orders ) ) ) );
		echo '</h2>';
		echo '<div class="sy-panel__body sy-panel__body--flush">';
		if ( empty( $orders ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No proposals in this view.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin sy-table--stack"><thead><tr>';
			echo '<th>' . esc_html__( 'PO Number', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Company', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Items', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Total', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Action', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $orders as $order ) {
				self::po_row( $order, $stage );
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';
	}

	/**
	 * One PO table row.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $stage Stage key.
	 */
	protected static function po_row( $order, $stage ) {
		$company_id = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$market     = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$po_ref     = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
		$status     = $order->get_status();
		$total      = ( $market && Sycomp_B2B_Markets::exists( $market ) )
			? Sycomp_B2B_Pricing::format_in_market( $order->get_total(), $market )
			: $order->get_formatted_order_total();
		$is_open    = $order->has_status( Sycomp_B2B_PO::STATUS_OPEN );
		$is_process = $order->has_status( Sycomp_B2B_PO::STATUS_PROCESS );
		$detail_url = add_query_arg(
			array( 'section' => 'po', 'po' => $stage, 'detail' => $order->get_id() ),
			self::manage_url()
		);

		echo '<tr>';
		echo '<td><a class="sy-link" href="' . esc_url( $detail_url ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a>';
		if ( $po_ref ) {
			echo '<span class="sy-prow__sku">' . esc_html( $po_ref ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . ( $company_id ? esc_html( get_the_title( $company_id ) ) : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
		echo '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( $order->get_item_count() ) ) . '</td>';
		echo '<td class="sy-col-price">' . wp_kses_post( $total ) . '</td>';
		echo '<td><span class="sy-badge ' . esc_attr( Sycomp_B2B_PO::status_badge_class( $status ) ) . '">' . esc_html( Sycomp_B2B_PO::status_label( $status ) ) . '</span></td>';
		echo '<td class="sy-col-act"><div class="sy-rowact">';
		echo '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'View', 'sycomp-b2b-portal' ) . '</a>';
		if ( $is_open || $is_process ) {
			echo '<form method="post">';
			wp_nonce_field( 'sycomp_po', 'sycomp_nonce' );
			echo '<input type="hidden" name="po" value="' . esc_attr( $stage ) . '">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			if ( $is_open ) {
				echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_accept">' . esc_html__( 'Accept', 'sycomp-b2b-portal' ) . '</button>';
			} else {
				echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_close">' . esc_html__( 'Close', 'sycomp-b2b-portal' ) . '</button>';
			}
			echo '<button class="sy-btn sy-btn--danger sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_cancel" onclick="return confirm(\'' . esc_js( __( 'Cancel this proposal?', 'sycomp-b2b-portal' ) ) . '\');">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</button>';
			echo '</form>';
		}
		echo '</div></td></tr>';
	}

	/**
	 * Render a single proposal in full.
	 *
	 * @param WC_Order $order Order.
	 */
	protected static function render_po_detail( $order ) {
		$stage  = isset( $_GET['po'] ) ? sanitize_key( wp_unslash( $_GET['po'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		$market = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$status = $order->get_status();

		$company_id  = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$location_id = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$po_ref      = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
		$delivery    = (string) $order->get_meta( Sycomp_B2B_PO::META_DELIVERY );
		if ( '' === trim( $delivery ) && $location_id ) {
			$delivery = (string) Sycomp_B2B_Post_Types::get_location_address( $location_id );
		}
		$is_open    = $order->has_status( Sycomp_B2B_PO::STATUS_OPEN );
		$is_process = $order->has_status( Sycomp_B2B_PO::STATUS_PROCESS );

		$back = add_query_arg( array( 'section' => 'po', 'po' => $stage ), self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to proposals', 'sycomp-b2b-portal' ) . '</a></p>';

		echo '<div class="sy-adm-head"><div class="sy-adm-head__text"><h1>';
		/* translators: %s: PO number. */
		echo esc_html( sprintf( __( 'Proposal #%s', 'sycomp-b2b-portal' ), $order->get_order_number() ) );
		echo ' <span class="sy-badge ' . esc_attr( Sycomp_B2B_PO::status_badge_class( $status ) ) . '">' . esc_html( Sycomp_B2B_PO::status_label( $status ) ) . '</span>';
		echo '</h1></div><div class="sy-adm-head__actions">';
		if ( $is_open || $is_process ) {
			echo '<form method="post">';
			wp_nonce_field( 'sycomp_po', 'sycomp_nonce' );
			echo '<input type="hidden" name="po" value="' . esc_attr( $stage ) . '">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			if ( $is_open ) {
				echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_accept">' . esc_html__( 'Accept', 'sycomp-b2b-portal' ) . '</button> ';
			} else {
				echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_close">' . esc_html__( 'Close', 'sycomp-b2b-portal' ) . '</button> ';
			}
			echo '<button class="sy-btn sy-btn--danger sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_cancel" onclick="return confirm(\'' . esc_js( __( 'Cancel this proposal?', 'sycomp-b2b-portal' ) ) . '\');">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</button>';
			echo '</form>';
		}
		if ( $is_open ) {
			echo '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'po', 'po' => $stage, 'detail' => $order->get_id(), 'edititems' => 1 ), self::manage_url() ) ) . '">' . esc_html__( 'Edit items', 'sycomp-b2b-portal' ) . '</a>';
		}
		echo '<form method="post" style="display:inline;">';
		wp_nonce_field( 'sycomp_po', 'sycomp_nonce' );
		echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
		echo '<button class="sy-btn sy-btn--ghost sy-btn--sm" type="submit" name="sycomp_admin_action" value="po_duplicate">' . esc_html__( 'Duplicate', 'sycomp-b2b-portal' ) . '</button>';
		echo '</form>';
		if ( class_exists( 'Sycomp_B2B_PO_PDF' ) ) {
			echo '<a class="sy-btn sy-btn--primary sy-btn--sm" href="' . esc_url( Sycomp_B2B_PO_PDF::pdf_url( $order ) ) . '">' . esc_html__( 'Download PDF', 'sycomp-b2b-portal' ) . '</a>';
		}
		echo '</div></div>';

		self::notice();

		// Summary.
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Summary', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body sy-deflist">';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Company', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $company_id ? esc_html( get_the_title( $company_id ) ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Location', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $location_id ? esc_html( get_the_title( $location_id ) ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Market', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $market ? esc_html( Sycomp_B2B_Markets::label( $market ) ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Date', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'PO reference', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== $po_ref ? esc_html( $po_ref ) : '-' ) . '</span></div>';
		echo '<div><span class="sy-deflist__k">' . esc_html__( 'Buyer', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . esc_html( $order->get_formatted_billing_full_name() ? $order->get_formatted_billing_full_name() : $order->get_billing_email() ) . '</span></div>';
		$sycomp_supplier = $market ? Sycomp_B2B_Warehouses::address_lines( $market ) : array();
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Supplier (ship-from)', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( $sycomp_supplier ? wp_kses_post( implode( '<br>', array_map( 'esc_html', $sycomp_supplier ) ) ) : '-' ) . '</span></div>';
		$sycomp_billing = (string) $order->get_meta( '_sycomp_billing_address' );
		if ( empty( $sycomp_billing ) && $location_id ) {
			$sycomp_billing = Sycomp_B2B_Post_Types::get_location_billing_address( $location_id );
		}
		if ( empty( $sycomp_billing ) && $company_id ) {
			$sycomp_billing = Sycomp_B2B_Post_Types::get_company_billing_address( $company_id );
		}
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Bill to', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== trim( $sycomp_billing ) ? nl2br( esc_html( $sycomp_billing ) ) : '-' ) . '</span></div>';
		echo '<div class="sy-deflist__full"><span class="sy-deflist__k">' . esc_html__( 'Delivery address', 'sycomp-b2b-portal' ) . '</span><span class="sy-deflist__v">' . ( '' !== trim( $delivery ) ? nl2br( esc_html( $delivery ) ) : '-' ) . '</span></div>';
		echo '</div></section>';

		// Items.
		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Items', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<div class="sy-panel__body sy-panel__body--flush">';
		echo '<table class="sy-table sy-table--admin"><thead><tr>';
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
			echo '<td>' . wp_kses_post( self::po_money( $unit, $market ) ) . '</td>';
			echo '<td>' . wp_kses_post( self::po_money( $line, $market ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody><tfoot>';
		echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html__( 'Subtotal', 'sycomp-b2b-portal' ) . '</th><th>' . wp_kses_post( self::po_money( $order->get_subtotal(), $market ) ) . '</th></tr>';
		foreach ( $order->get_fees() as $sycomp_fee ) {
			echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html( $sycomp_fee->get_name() ) . '</th><th>' . wp_kses_post( self::po_money( (float) $sycomp_fee->get_total(), $market ) ) . '</th></tr>';
		}
		echo '<tr><th colspan="4" class="sy-table__totlabel">' . esc_html__( 'Order total', 'sycomp-b2b-portal' ) . '</th><th>' . wp_kses_post( self::po_money( $order->get_total(), $market ) ) . '</th></tr>';
		echo '</tfoot></table>';
		echo '</div></section>';
	}

	/**
	 * Format a money amount in a market's currency.
	 *
	 * @param float  $amount Amount.
	 * @param string $market Market key.
	 * @return string
	 */
	protected static function po_money( $amount, $market ) {
		if ( $market && Sycomp_B2B_Markets::exists( $market ) ) {
			return Sycomp_B2B_Pricing::format_in_market( $amount, $market );
		}
		return wc_price( (float) $amount );
	}

	/* =====================================================================
	 * Section: Profile.
	 * ================================================================== */

	/**
	 * Render the current user's profile form.
	 */
	protected static function render_profile() {
		$user = wp_get_current_user();

		self::page_head(
			__( 'My Profile', 'sycomp-b2b-portal' ),
			__( 'Update your name, contact email and password.', 'sycomp-b2b-portal' )
		);
		self::notice();

		echo '<section class="sy-panel" style="max-width:560px;"><div class="sy-panel__body">';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_profile', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="profile_save">';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'First name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="pr_first" value="' . esc_attr( $user->first_name ) . '"></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Last name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="pr_last" value="' . esc_attr( $user->last_name ) . '"></label>';
		echo '</div>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Display name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="pr_display" value="' . esc_attr( $user->display_name ) . '"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Email address', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="email" name="pr_email" required value="' . esc_attr( $user->user_email ) . '"></label>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'New password', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="password" name="pr_pass" autocomplete="new-password" placeholder="' . esc_attr__( 'Leave blank to keep current', 'sycomp-b2b-portal' ) . '"></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Confirm new password', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="password" name="pr_pass2" autocomplete="new-password"></label>';
		echo '</div>';

		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save profile', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';
	}

	/* =====================================================================
	 * Section: Company details.
	 * ================================================================== */

	/**
	 * Render Sycomp's own company-details form.
	 */
	protected static function render_company() {
		$co = self::company_details();

		self::page_head(
			__( 'Company Details', 'sycomp-b2b-portal' ),
			__( "Sycomp's own organisation details, used across the portal.", 'sycomp-b2b-portal' )
		);
		self::notice();

		echo '<section class="sy-panel" style="max-width:640px;"><div class="sy-panel__body">';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		wp_nonce_field( 'sycomp_company', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="company_save">';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Company name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="co_name" value="' . esc_attr( $co['name'] ) . '"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Address', 'sycomp-b2b-portal' ) . '</span>';
		echo '<textarea name="co_address" rows="3">' . esc_textarea( $co['address'] ) . '</textarea></label>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Support email', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="email" name="co_email" value="' . esc_attr( $co['email'] ) . '"></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Support phone', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="co_phone" value="' . esc_attr( $co['phone'] ) . '"></label>';
		echo '</div>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Website', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="url" name="co_website" value="' . esc_attr( $co['website'] ) . '" placeholder="https://"></label>';

		echo '<label class="sy-field sy-field--file"><span class="sy-field__label">' . esc_html__( 'Company logo', 'sycomp-b2b-portal' ) . '</span>';
		if ( $co['logo_id'] ) {
			$img = wp_get_attachment_image( $co['logo_id'], array( 80, 80 ) );
			if ( $img ) {
				echo '<span class="sy-form__thumb">' . $img . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '<input type="file" name="co_logo" accept="image/*"></label>';

		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save company details', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// --- Account Executive Details ---------------------------------------
		$ae_name  = get_option( 'sycomp_po_ae_name', 'Vanessa Nudd' );
		$ae_phone = get_option( 'sycomp_po_ae_phone', '650-793-2448' );
		$ae_email = get_option( 'sycomp_po_ae_email', 'vnudd@sycomp.com' );

		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Account Executive Details', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Manage the default Account Executive name and contact details displayed on proposal documents.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_ae', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="ae_save">';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Account Executive Name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="ae_name" value="' . esc_attr( $ae_name ) . '" required></label>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Phone', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="ae_phone" value="' . esc_attr( $ae_phone ) . '"></label>';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Email', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="email" name="ae_email" value="' . esc_attr( $ae_email ) . '"></label>';
		echo '</div>';

		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save Account Executive details', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// --- Quote Nomenclature Formats ------------------------------------
		$quote_formats = get_option( 'sycomp_b2b_quote_formats', array() );
		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Quote Number Nomenclature', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Configure the nomenclature format pattern for the Quote # / PO # in each market. You can drag the placeholder pills directly into the corresponding input fields, or click them to insert at the cursor position.', 'sycomp-b2b-portal' ) . '</p>';

		$placeholders = array(
			'{company}' => __( 'Company Prefix (e.g. Snap)', 'sycomp-b2b-portal' ),
			'{market}'  => __( 'Market Code (e.g. IN)', 'sycomp-b2b-portal' ),
			'{batch}'   => __( 'Batch (e.g. 123 or -Batch)', 'sycomp-b2b-portal' ),
			'{id}'      => __( 'Order ID (e.g. 282)', 'sycomp-b2b-portal' )
		);

		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_quote_formats', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="quote_formats_save">';
		foreach ( Sycomp_B2B_Markets::all() as $sycomp_fkey => $sycomp_fmarket ) {
			$sycomp_fmt = isset( $quote_formats[ $sycomp_fkey ] ) ? $quote_formats[ $sycomp_fkey ] : '';
			echo '<div class="sy-market-quote-format" style="border-top:1px solid var(--sy-border, #e3e6e8);padding-top:14px;margin-top:14px;">';
			echo '<h3 style="font-size:.95rem;margin:0 0 8px;">' . esc_html( $sycomp_fmarket['label'] ) . '</h3>';
			
			// Render pills block directly above each field
			echo '<div class="sy-placeholder-pills" style="margin: 8px 0; padding: 6px 10px; background: #f7f9fa; border: 1px solid var(--sy-border, #e3e6e8); border-radius: 4px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">';
			echo '<span style="font-size: 0.8rem; font-weight: 600; color: #6b7280; margin-right: 5px;">' . esc_html__( 'Placeholders (Drag/Click):', 'sycomp-b2b-portal' ) . '</span>';
			foreach ( $placeholders as $ph => $title ) {
				echo '<span class="sy-placeholder-pill" draggable="true" data-placeholder="' . esc_attr( $ph ) . '" title="' . esc_attr( $title ) . '" style="cursor: grab; display: inline-block; padding: 2px 8px; background: #313d53; color: #fff; border-radius: 10px; font-family: monospace; font-size: 0.8rem; font-weight: bold; user-select: none;">' . esc_html( $ph ) . '</span>';
			}
			echo '</div>';

			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Quote Pattern', 'sycomp-b2b-portal' ) . '</span>';
			echo '<input type="text" class="sy-quote-format-input" name="quote_formats[' . esc_attr( $sycomp_fkey ) . ']" value="' . esc_attr( $sycomp_fmt ) . '" placeholder="{company}{market}{batch}{id}"></label>';
			echo '</div>';
		}
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save quote formats', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// JavaScript for interactivity
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			// Handle pill actions
			var pills = document.querySelectorAll('.sy-placeholder-pill');
			pills.forEach(function(pill) {
				// Click to insert
				pill.addEventListener('click', function() {
					var value = this.getAttribute('data-placeholder');
					var container = this.closest('.sy-market-quote-format');
					var target = container ? container.querySelector('.sy-quote-format-input') : null;
					if (target) {
						var start = target.selectionStart;
						var end = target.selectionEnd;
						var text = target.value;
						target.value = text.substring(0, start) + value + text.substring(end);
						target.selectionStart = target.selectionEnd = start + value.length;
						target.focus();
					}
				});

				// Drag start
				pill.addEventListener('dragstart', function(e) {
					e.dataTransfer.setData('text/plain', this.getAttribute('data-placeholder'));
					this.style.opacity = '0.5';
				});

				pill.addEventListener('dragend', function() {
					this.style.opacity = '1';
				});
			});

			// Drop targets
			var inputs = document.querySelectorAll('.sy-quote-format-input');
			inputs.forEach(function(input) {
				input.addEventListener('dragover', function(e) {
					e.preventDefault();
					this.style.borderColor = '#313d53';
				});
				input.addEventListener('dragleave', function() {
					this.style.borderColor = '';
				});
				input.addEventListener('drop', function(e) {
					e.preventDefault();
					this.style.borderColor = '';
					var value = e.dataTransfer.getData('text/plain');
					if (value) {
						var start = this.selectionStart;
						var end = this.selectionEnd;
						var text = this.value;
						this.value = text.substring(0, start) + value + text.substring(end);
						this.selectionStart = this.selectionEnd = start + value.length;
						this.focus();
					}
				});
			});
		});
		</script>
		<?php

		// --- Bill From addresses, one per market ---------------
		$sycomp_warehouses = Sycomp_B2B_Warehouses::all();
		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Bill From', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'The Sycomp bill-from address for each market. A proposal shows the bill-from address for its market as the supplier.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_warehouses', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="warehouses_save">';
		foreach ( Sycomp_B2B_Markets::all() as $sycomp_wkey => $sycomp_wmarket ) {
			$sycomp_wh = $sycomp_warehouses[ $sycomp_wkey ];
			echo '<div style="border-top:1px solid var(--sy-border);padding-top:14px;margin-top:14px;">';
			echo '<h3 style="font-size:.95rem;margin:0 0 8px;">' . esc_html( $sycomp_wmarket['label'] ) . '</h3>';
			echo '<div class="sy-form__grid">';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Company name', 'sycomp-b2b-portal' ) . '</span>';
			echo '<input type="text" name="wh[' . esc_attr( $sycomp_wkey ) . '][name]" value="' . esc_attr( $sycomp_wh['name'] ) . '"></label>';
			echo '<label class="sy-field sy-field--wide"><span class="sy-field__label">' . esc_html__( 'Address', 'sycomp-b2b-portal' ) . '</span>';
			echo '<textarea name="wh[' . esc_attr( $sycomp_wkey ) . '][address]" rows="3">' . esc_textarea( $sycomp_wh['address'] ) . '</textarea></label>';
			echo '</div></div>';
		}
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save addresses', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// --- Tax rates, one per market -------------------------------------
		$sycomp_taxes = Sycomp_B2B_Tax::all();
		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Tax rates', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'The tax applied to proposals in each market. Prices are tax-exclusive — tax is added as an estimated line on the PO. A rate of 0 applies no tax.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_taxes', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="taxes_save">';
		foreach ( Sycomp_B2B_Markets::all() as $sycomp_tkey => $sycomp_tmarket ) {
			$sycomp_tax = $sycomp_taxes[ $sycomp_tkey ];
			echo '<div style="border-top:1px solid var(--sy-border);padding-top:14px;margin-top:14px;">';
			echo '<h3 style="font-size:.95rem;margin:0 0 8px;">' . esc_html( $sycomp_tmarket['label'] ) . ' <span class="sy-muted" style="font-weight:400;">(' . esc_html( $sycomp_tmarket['currency'] ) . ')</span></h3>';
			echo '<div class="sy-form__grid">';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Tax label', 'sycomp-b2b-portal' ) . '</span>';
			echo '<input type="text" name="tax[' . esc_attr( $sycomp_tkey ) . '][label]" value="' . esc_attr( $sycomp_tax['label'] ) . '" placeholder="' . esc_attr__( 'e.g. GST, VAT', 'sycomp-b2b-portal' ) . '"></label>';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Rate (%)', 'sycomp-b2b-portal' ) . '</span>';
			echo '<input type="number" step="0.01" min="0" name="tax[' . esc_attr( $sycomp_tkey ) . '][rate]" value="' . esc_attr( Sycomp_B2B_Tax::format_rate( $sycomp_tax['rate'] ) ) . '"></label>';
			echo '</div></div>';
		}
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save tax rates', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// --- Exchange rates ------------------------------------------------
		$sycomp_rates = Sycomp_B2B_FX::rates();
		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Exchange rates', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Used when a product is priced in a currency other than its market\'s local currency. Enter how many units of each currency equal 1 USD — USD is the base.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<form method="post" class="sy-form">';
		wp_nonce_field( 'sycomp_fx', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="fx_save">';
		echo '<div class="sy-form__grid">';
		foreach ( Sycomp_B2B_FX::currencies() as $sycomp_cur ) {
			$sycomp_is_base = ( Sycomp_B2B_FX::BASE === $sycomp_cur );
			$sycomp_rval    = isset( $sycomp_rates[ $sycomp_cur ] ) ? (float) $sycomp_rates[ $sycomp_cur ] : 0.0;
			$sycomp_rshow   = $sycomp_is_base ? '1' : ( $sycomp_rval > 0 ? rtrim( rtrim( number_format( $sycomp_rval, 4, '.', '' ), '0' ), '.' ) : '' );
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html( $sycomp_cur ) . ' ' . esc_html( $sycomp_is_base ? __( '(base)', 'sycomp-b2b-portal' ) : __( 'per 1 USD', 'sycomp-b2b-portal' ) ) . '</span>';
			echo '<input type="number" step="0.0001" min="0" name="fx[' . esc_attr( $sycomp_cur ) . ']" value="' . esc_attr( $sycomp_rshow ) . '"' . ( $sycomp_is_base ? ' readonly' : '' ) . '></label>';
		}
		echo '</div>';
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save exchange rates', 'sycomp-b2b-portal' ) . '</button></div>';
		echo '</form>';
		echo '</div></section>';

		// --- Manage Markets ------------------------------------------------
		$sycomp_all_markets = Sycomp_B2B_Markets::all();
		$default_keys = array( 'india', 'united_states', 'australia', 'japan', 'china', 'philippines', 'taiwan', 'south_africa', 'uae' );

		echo '<section class="sy-panel" style="max-width:640px;"><h2 class="sy-panel__title">' . esc_html__( 'Manage Markets & Countries', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Add, edit, or delete markets. Note that deleting a market will clean up its configurations and prices.', 'sycomp-b2b-portal' ) . '</p>';

		// Markets list table
		echo '<table class="sy-table" style="width:100%; border-collapse:collapse; margin-bottom:24px;">';
		echo '<thead><tr style="border-bottom:2px solid var(--sy-border, #e3e6e8); text-align:left;">';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Flag', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Label', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Key', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Currency', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Country', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px;">' . esc_html__( 'Order', 'sycomp-b2b-portal' ) . '</th>';
		echo '<th style="padding:8px 4px; text-align:right;">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $sycomp_all_markets as $m_key => $m_data ) {
			echo '<tr style="border-bottom:1px solid var(--sy-border, #e3e6e8);" data-key="' . esc_attr( $m_key ) . '" data-label="' . esc_attr( $m_data['label'] ) . '" data-currency="' . esc_attr( $m_data['currency'] ) . '" data-symbol="' . esc_attr( $m_data['symbol'] ) . '" data-decimals="' . esc_attr( $m_data['decimals'] ) . '" data-country-code="' . esc_attr( $m_data['country_code'] ) . '" data-order="' . esc_attr( $m_data['order'] ) . '">';
			echo '<td style="padding:8px 4px; vertical-align:middle;"><img src="' . esc_url( $m_data['flag_url'] ) . '" style="width:24px; height:auto; display:block;" alt=""></td>';
			echo '<td style="padding:8px 4px; vertical-align:middle; font-weight:600;">' . esc_html( $m_data['label'] ) . '</td>';
			echo '<td style="padding:8px 4px; vertical-align:middle; font-family:monospace; font-size:0.85rem;">' . esc_html( $m_key ) . '</td>';
			echo '<td style="padding:8px 4px; vertical-align:middle;">' . esc_html( $m_data['currency'] . ' (' . $m_data['symbol'] . ')' ) . '</td>';
			echo '<td style="padding:8px 4px; vertical-align:middle;">' . esc_html( $m_data['country_code'] ) . '</td>';
			echo '<td style="padding:8px 4px; vertical-align:middle;">' . esc_html( $m_data['order'] ) . '</td>';
			echo '<td style="padding:8px 4px; vertical-align:middle; text-align:right; white-space:nowrap;">';
			echo '<button type="button" class="sy-btn sy-btn--sm" style="margin-right:4px; padding:3px 8px; font-size:0.8rem;" onclick="editMarket(this.closest(\'tr\'))">' . esc_html__( 'Edit', 'sycomp-b2b-portal' ) . '</button>';
			echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this market? All prices and configs for this market will be lost.', 'sycomp-b2b-portal' ) ) . '\');">';
			wp_nonce_field( 'sycomp_market_delete', 'sycomp_nonce' );
			echo '<input type="hidden" name="sycomp_admin_action" value="market_delete">';
			echo '<input type="hidden" name="market_key" value="' . esc_attr( $m_key ) . '">';
			echo '<button type="submit" class="sy-btn sy-btn--sm sy-btn--danger" style="padding:3px 8px; font-size:0.8rem; background-color:#c0392b; color:#fff; border:none; border-radius:4px; cursor:pointer;">' . esc_html__( 'Delete', 'sycomp-b2b-portal' ) . '</button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Form panel to add/edit market
		echo '<div id="m_form_panel" style="background:#f7f9fa; border:1px solid var(--sy-border, #e3e6e8); padding:16px; border-radius:6px; margin-top:20px;">';
		echo '<h3 id="m_action_title" style="margin:0 0 16px; font-size:1.05rem; font-weight:600;">' . esc_html__( 'Add New Market', 'sycomp-b2b-portal' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		wp_nonce_field( 'sycomp_market_save', 'sycomp_nonce' );
		echo '<input type="hidden" name="sycomp_admin_action" value="market_save">';
		echo '<input type="hidden" id="m_is_edit" name="is_edit" value="0">';

		echo '<div class="sy-form__grid" style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--sy-border, #e3e6e8);">';
		echo '<label class="sy-field"><span class="sy-field__label" style="font-weight: 600; color: var(--sy-accent, #005A9C);">' . esc_html__( 'Quick Add Preset (Select Country)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<select id="m_country_preset" onchange="applyCountryPreset(this.value)">';
		echo '<option value="">-- ' . esc_html__( 'Select a country to autofill', 'sycomp-b2b-portal' ) . ' --</option>';
		echo '</select></label>';
		echo '</div>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field" id="m_key_container"><span class="sy-field__label">' . esc_html__( 'Market Key (lowercase, underscores only)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" id="m_key" name="market_key" required pattern="[a-z0-9_]+" title="' . esc_attr__( 'Only lowercase letters, numbers, and underscores', 'sycomp-b2b-portal' ) . '" placeholder="e.g. canada"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Market Name/Label', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" id="m_label" name="label" required placeholder="e.g. Canada"></label>';
		echo '</div>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Currency', 'sycomp-b2b-portal' ) . '</span>';
		echo '<select id="m_currency" name="currency" required><option value="">' . esc_html__( 'Select Currency', 'sycomp-b2b-portal' ) . '</option></select></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Currency Symbol (e.g. C$)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" id="m_symbol" name="symbol" required placeholder="C$"></label>';
		echo '</div>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Decimal Places', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="number" id="m_decimals" name="decimals" required min="0" max="6" value="2"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Country Code (2-letter ISO, e.g. CA)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" id="m_country_code" name="country_code" required maxlength="2" style="text-transform:uppercase;" placeholder="CA"></label>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Display Order', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="number" id="m_order" name="order" required min="0" value="100"></label>';
		echo '</div>';

		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Flag Image (SVG / PNG / JPG)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="file" name="flag_file" accept="image/*"></label>';

		echo '<div class="sy-form__actions" style="margin-top:16px;">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save Market', 'sycomp-b2b-portal' ) . '</button>';
		echo '<button class="sy-btn" type="button" id="m_cancel_edit" style="display:none; margin-left:8px;" onclick="cancelEditMarket()">' . esc_html__( 'Cancel Edit', 'sycomp-b2b-portal' ) . '</button>';
		echo '</div>';
		echo '</form></div>';

		echo '</div></section>';

		// JavaScript uploader and data copier
		?>
		<script>
		const sycompCountryData = <?php echo file_get_contents( SYCOMP_B2B_DIR . 'assets/country_data.json' ); ?>;

		document.addEventListener('DOMContentLoaded', function() {
			const presetSelect = document.getElementById('m_country_preset');
			const currencySelect = document.getElementById('m_currency');

			if (presetSelect || currencySelect) {
				const sortedKeys = Object.keys(sycompCountryData).sort((a, b) => {
					return sycompCountryData[a].name.localeCompare(sycompCountryData[b].name);
				});

				const uniqueCurrencies = [...new Set(Object.values(sycompCountryData).map(d => d.currency))].filter(Boolean).sort();

				if (presetSelect) {
					sortedKeys.forEach(k => {
						const opt = document.createElement('option');
						opt.value = k;
						opt.textContent = sycompCountryData[k].name;
						presetSelect.appendChild(opt);
					});
				}

				if (currencySelect) {
					uniqueCurrencies.forEach(curr => {
						const opt = document.createElement('option');
						opt.value = curr;
						opt.textContent = curr;
						currencySelect.appendChild(opt);
					});

					// Update symbol and decimals if currency is manually changed
					currencySelect.addEventListener('change', function() {
						const selectedCurr = this.value;
						if (selectedCurr) {
							const match = Object.values(sycompCountryData).find(d => d.currency === selectedCurr);
							if (match) {
								document.getElementById('m_symbol').value = match.symbol;
								document.getElementById('m_decimals').value = match.decimals;
							}
						}
					});
				}
			}
		});

		function applyCountryPreset(countryCode) {
			if (!countryCode || !sycompCountryData[countryCode]) return;
			const data = sycompCountryData[countryCode];
			
			document.getElementById('m_key').value = data.key;
			document.getElementById('m_label').value = data.name;
			document.getElementById('m_currency').value = data.currency;
			document.getElementById('m_symbol').value = data.symbol;
			document.getElementById('m_decimals').value = data.decimals;
			document.getElementById('m_country_code').value = data.code;
		}

		function editMarket(row) {
			document.getElementById('m_action_title').innerText = 'Edit Market: ' + row.dataset.label;
			document.getElementById('m_key').value = row.dataset.key;
			document.getElementById('m_key').readOnly = true;
			document.getElementById('m_label').value = row.dataset.label;
			document.getElementById('m_currency').value = row.dataset.currency;
			document.getElementById('m_symbol').value = row.dataset.symbol;
			document.getElementById('m_decimals').value = row.dataset.decimals;
			document.getElementById('m_country_code').value = row.dataset.countryCode;
			document.getElementById('m_order').value = row.dataset.order;
			document.getElementById('m_cancel_edit').style.display = 'inline-block';
			document.getElementById('m_is_edit').value = '1';
			document.getElementById('m_key_container').style.opacity = '0.6';
			
			const presetSelect = document.getElementById('m_country_preset');
			if (presetSelect) presetSelect.value = '';

			document.getElementById('m_form_panel').scrollIntoView({ behavior: 'smooth' });
		}

		function cancelEditMarket() {
			document.getElementById('m_action_title').innerText = 'Add New Market';
			document.getElementById('m_key').value = '';
			document.getElementById('m_key').readOnly = false;
			document.getElementById('m_label').value = '';
			document.getElementById('m_currency').value = '';
			document.getElementById('m_symbol').value = '';
			document.getElementById('m_decimals').value = '2';
			document.getElementById('m_country_code').value = '';
			document.getElementById('m_order').value = '100';
			document.getElementById('m_cancel_edit').style.display = 'none';
			document.getElementById('m_is_edit').value = '0';
			document.getElementById('m_key_container').style.opacity = '1';

			const presetSelect = document.getElementById('m_country_preset');
			if (presetSelect) presetSelect.value = '';
		}
		</script>
		<?php
	}
}
