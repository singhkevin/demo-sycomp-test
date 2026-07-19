<?php
/**
 * Purchase-order PDF.
 *
 * Lets a buyer download a PDF copy of a submitted proposal. The PDF
 * is generated on demand by Sycomp_B2B_PDF and streamed from a nonce-
 * protected, ownership-checked endpoint. It carries the Sycomp logo and,
 * when one has been uploaded, the customer company's logo.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_PO_PDF.
 */
class Sycomp_B2B_PO_PDF {

	/* Layout constants (points, A4). */
	const L        = 56.0;
	const R        = 539.28;
	const COL_PROD = 64.0;
	const WRAP_W   = 300.0;
	const COL_QTY  = 404.0;
	const COL_UNIT = 470.0;
	const COL_TOT  = 531.28;
	const BOTTOM   = 792.0;

	/* Colours (0..1 RGB). */
	const NAVY       = array( 0.05, 0.11, 0.24 );
	const GRAY       = array( 0.40, 0.43, 0.49 );
	const INK        = array( 0.10, 0.12, 0.16 );
	const RULE       = array( 0.86, 0.88, 0.91 );
	const HEAD       = array( 0.96, 0.97, 0.98 );
	const STEEL_BLUE = array( 0.31, 0.49, 0.75 );
	const PEACH      = array( 0.98, 0.73, 0.53 );
	const TOT_BG     = array( 0.66, 0.76, 0.88 );
	const WHITE      = array( 1.0, 1.0, 1.0 );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_download' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'download_button' ) );
	}

	/**
	 * Nonce-signed download URL for an order's PO PDF.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function pdf_url( $order ) {
		$id = $order->get_id();
		return add_query_arg(
			array(
				'sycomp_po_pdf' => $id,
				'_sypo'         => wp_create_nonce( 'sycomp_po_pdf_' . $id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Render the "Download proposal (PDF)" button below the order
	 * details table on the order-received and view-order pages.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function download_button( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! Sycomp_B2B_PO::user_can_view( $order ) ) {
			return;
		}
		printf(
			'<p class="sy-po-pdf-actions"><a class="sy-btn sy-btn--primary" href="%s">%s</a></p>',
			esc_url( self::pdf_url( $order ) ),
			esc_html__( 'Download proposal (PDF)', 'sycomp-b2b-portal' )
		);
	}

	/**
	 * Catch a PO PDF request, verify it, and stream the document.
	 */
	public static function maybe_download() {
		if ( ! isset( $_GET['sycomp_po_pdf'] ) ) {
			return;
		}
		$order_id = absint( wp_unslash( $_GET['sycomp_po_pdf'] ) );
		$nonce    = isset( $_GET['_sypo'] ) ? sanitize_text_field( wp_unslash( $_GET['_sypo'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'sycomp_po_pdf_' . $order_id ) ) {
			wp_die(
				esc_html__( 'This purchase-order link has expired. Please reopen it from your account.', 'sycomp-b2b-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die(
				esc_html__( 'Proposal not found.', 'sycomp-b2b-portal' ),
				'',
				array( 'response' => 404 )
			);
		}

		if ( ! Sycomp_B2B_PO::user_can_view( $order ) ) {
			wp_die(
				esc_html__( 'You are not allowed to download this proposal.', 'sycomp-b2b-portal' ),
				'',
				array( 'response' => 403 )
			);
		}

		require_once SYCOMP_B2B_DIR . 'includes/class-pdf.php';
		$body = self::build( $order )->output();

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="PO-' . $order->get_order_number() . '.pdf"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Format an amount in a market's currency convention (no symbol).
	 *
	 * @param float  $amount Amount.
	 * @param string $market Market key.
	 * @return string
	 */
	protected static function money( $amount, $market ) {
		$dec = $market ? Sycomp_B2B_Markets::decimals( $market ) : 2;
		return number_format( (float) $amount, $dec, '.', ',' );
	}

	/**
	 * Absolute file path of the Sycomp colour logo, or '' if unavailable.
	 *
	 * @return string
	 */
	protected static function sycomp_logo_path() {
		if ( ! function_exists( 'sycomp_logo_url' ) ) {
			return '';
		}
		$url = (string) sycomp_logo_url( 'color' );
		if ( '' === $url ) {
			return '';
		}
		$path = str_replace( content_url(), WP_CONTENT_DIR, $url );
		return ( $path !== $url && is_readable( $path ) ) ? $path : '';
	}

	/**
	 * Absolute file path of a company's uploaded logo, or '' if none.
	 *
	 * @param int $company_id Company post ID.
	 * @return string
	 */
	protected static function company_logo_path( $company_id ) {
		if ( ! $company_id ) {
			return '';
		}
		$logo_id = (int) get_post_meta( $company_id, '_sycomp_logo_id', true );
		if ( ! $logo_id ) {
			return '';
		}
		$path = get_attached_file( $logo_id );
		return ( is_string( $path ) && is_readable( $path ) ) ? $path : '';
	}

	/**
	 * Draw a label / value field.
	 *
	 * @param Sycomp_B2B_PDF $pdf   PDF.
	 * @param float          $x     X.
	 * @param float          $y     Label baseline.
	 * @param string         $label Label.
	 * @param string         $value Value.
	 */
	protected static function field( $pdf, $x, $y, $label, $value ) {
		$value = ( '' !== trim( (string) $value ) ) ? (string) $value : '-';
		$pdf->text( $x, $y, strtoupper( $label ), 7.5, true, self::GRAY );
		$pdf->text( $x, $y + 15, $value, 11, false, self::INK );
	}

	/**
	 * Draw a labelled address block — an uppercase label, a rule, then the
	 * given lines (each wrapped to the column width). Returns the y the
	 * block ends at.
	 *
	 * @param Sycomp_B2B_PDF $pdf   PDF.
	 * @param float          $x     Left x.
	 * @param float          $top   Label baseline y.
	 * @param float          $width Column width.
	 * @param string         $label Block label.
	 * @param string[]       $lines Text lines.
	 * @return float
	 */
	protected static function block( $pdf, $x, $top, $width, $label, $lines ) {
		$pdf->text( $x, $top, strtoupper( $label ), 8, true, self::GRAY );
		$pdf->line( $x, $top + 6, $x + $width, $top + 6, 0.6, self::RULE );
		$y = $top + 22;
		if ( empty( $lines ) ) {
			$pdf->text( $x, $y, '-', 10, false, self::INK );
			return $y + 14;
		}
		foreach ( $lines as $line ) {
			foreach ( $pdf->wrap( (string) $line, $width, 10 ) as $wrapped ) {
				$pdf->text( $x, $y, $wrapped, 10, false, self::INK );
				$y += 14;
			}
		}
		return $y;
	}

	/**
	 * Draw the line-items table header at $top; return the y below it.
	 *
	 * @param Sycomp_B2B_PDF $pdf PDF.
	 * @param float          $top Top y.
	 * @return float
	 */
	protected static function items_head( $pdf, $top ) {
		$pdf->fill_rect( self::L, $top, self::R - self::L, 16, self::STEEL_BLUE );
		$base = $top + 11;
		$pdf->text( 60.0, $base, 'Part Number', 8.0, true, self::WHITE );
		$pdf->text( 125.0, $base, 'Description', 8.0, true, self::WHITE );
		$pdf->text_right( 341.0, $base, 'QTY', 8.0, true, self::WHITE );
		$pdf->text_right( 403.0, $base, 'UNIT PRICE', 8.0, true, self::WHITE );
		$pdf->text_right( 468.0, $base, 'EXT PRICE', 8.0, true, self::WHITE );
		$pdf->text( 478.0, $base, 'ETA', 8.0, true, self::WHITE );
		
		// Draw vertical borders for header
		$x_coords = array( 56.0, 121.0, 321.0, 346.0, 408.0, 473.0, 539.28 );
		foreach ( $x_coords as $x ) {
			$pdf->line( $x, $top, $x, $top + 16, 0.5, self::INK );
		}
		$pdf->line( self::L, $top + 16, self::R, $top + 16, 0.6, self::INK );
		return $top + 16;
	}

	/**
	 * Build the purchase-order PDF.
	 *
	 * @param WC_Order $order Order.
	 * @return Sycomp_B2B_PDF
	 */
	protected static function build( $order ) {
		require_once SYCOMP_B2B_DIR . 'includes/class-pdf.php';
		$pdf = new Sycomp_B2B_PDF();

		$market   = (string) $order->get_meta( Sycomp_B2B_PO::META_MARKET );
		$currency = $market ? Sycomp_B2B_Markets::currency( $market ) : $order->get_currency();

		// Buyer.
		$buyer_name  = '';
		$buyer_email = '';
		$customer_id = $order->get_customer_id();
		if ( $customer_id ) {
			$u = get_userdata( $customer_id );
			if ( $u ) {
				$buyer_name  = $u->display_name;
				$buyer_email = $u->user_email;
			}
		}
		if ( '' === trim( $buyer_name ) ) {
			$buyer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === trim( $buyer_email ) ) {
			$buyer_email = $order->get_billing_email();
		}

		$company_id      = (int) $order->get_meta( Sycomp_B2B_PO::META_COMPANY );
		$location_id     = (int) $order->get_meta( Sycomp_B2B_PO::META_LOCATION );
		$billing_address = (string) $order->get_meta( '_sycomp_billing_address' );
		if ( empty( $billing_address ) && $location_id ) {
			$billing_address = Sycomp_B2B_Post_Types::get_location_billing_address( $location_id );
		}
		if ( empty( $billing_address ) && $company_id ) {
			$billing_address = Sycomp_B2B_Post_Types::get_company_billing_address( $company_id );
		}

		$loc_name    = $location_id ? get_the_title( $location_id ) : '';
		$delivery    = (string) $order->get_meta( Sycomp_B2B_PO::META_DELIVERY );
		$loc_addr    = ( '' !== trim( $delivery ) )
			? $delivery
			: ( $location_id ? Sycomp_B2B_Post_Types::get_location_address( $location_id ) : '' );

		$status       = $order->get_status();
		$status_label = Sycomp_B2B_PO::is_po( $order )
			? Sycomp_B2B_PO::status_label( $status )
			: wc_get_order_status_name( $status );

		$po_ref  = (string) $order->get_meta( Sycomp_B2B_PO::META_PO_REF );
		$created = $order->get_date_created();
		$date    = $created ? wc_format_datetime( $created, 'F j, Y' ) : '';

		// Account Executive details (customizable fallback)
		$ae_name  = get_option( 'sycomp_po_ae_name', 'Vanessa Nudd' );
		$ae_phone = get_option( 'sycomp_po_ae_phone', '650-793-2448' );
		$ae_email = get_option( 'sycomp_po_ae_email', 'vnudd@sycomp.com' );

		// ---- Page + header band ------------------------------------------
		$pdf->add_page();

		// Supplier details (top left)
		$supplier_lines = Sycomp_B2B_Warehouses::address_lines( $market );
		if ( count( $supplier_lines ) <= 1 ) {
			if ( 'india' === $market || empty( $market ) ) {
				$supplier_lines = array(
					'Sycomp Technologies India Pvt. Ltd.',
					'Unit 7, Level 5, Discoverer Link Bridge',
					'Building ITPL',
					'Whitefield Road, Bangalore,',
					'Bengaluru Urban, Karnataka – 560066',
				);
			}
		}
		$wrapped_supplier = array();
		foreach ( $supplier_lines as $line ) {
			foreach ( $pdf->wrap( $line, 130, 8.0 ) as $w_line ) {
				$wrapped_supplier[] = $w_line;
			}
		}
		$sy = 44;
		foreach ( $wrapped_supplier as $line ) {
			$pdf->text( self::L, $sy, $line, 8, false, self::INK );
			$sy += 9.5;
		}

		// Centered Logo
		$sycomp_logo = self::sycomp_logo_path();
		if ( $sycomp_logo ) {
			$pdf->image_file( 217.64, 44, 160, 30, $sycomp_logo );
		}

		// Dynamic Date Calculation
		$date_str = $created ? $created->date( 'n/j/Y' ) : date( 'n/j/Y' );
		if ( $created ) {
			$valid_time = clone $created;
			$valid_time->modify( '+12 days' );
			$valid_str = $valid_time->date( 'n/j/Y' );
		} else {
			$valid_str = date( 'n/j/Y', time() + 12 * 86400 );
		}

		// Dynamic Quote ID Formatting
		$quote_no = Sycomp_B2B_PO::quote_id( $order );

		// Top-right Quote Info Table
		$tbl_x  = self::R - 140;
		$tbl_y  = 44;
		$col_w1 = 60;
		$col_w2 = 80;

		// Wrap the Quote # value to fit cell width
		$quote_lines = $pdf->wrap( $quote_no, $col_w2 - 4, 8.5 );
		
		$h_date  = 14;
		$h_quote = ( count( $quote_lines ) <= 1 ) ? 14 : 10 + count( $quote_lines ) * 11.5;
		$h_cust  = 14;
		$h_valid = 14;

		$total_tbl_h = $h_date + $h_quote + $h_cust + $h_valid;

		$pdf->text_right( self::R, 36, 'PROPOSAL', 11, true, array( 0.2, 0.6, 0.8 ) );

		// Table borders (outer box)
		$pdf->line( $tbl_x, $tbl_y, self::R, $tbl_y, 0.6, self::INK );
		$pdf->line( $tbl_x, $tbl_y + $total_tbl_h, self::R, $tbl_y + $total_tbl_h, 0.6, self::INK );
		$pdf->line( $tbl_x, $tbl_y, $tbl_x, $tbl_y + $total_tbl_h, 0.6, self::INK );
		$pdf->line( self::R, $tbl_y, self::R, $tbl_y + $total_tbl_h, 0.6, self::INK );

		// Internal horizontal lines
		$pdf->line( $tbl_x, $tbl_y + $h_date, self::R, $tbl_y + $h_date, 0.5, self::INK );
		$pdf->line( $tbl_x, $tbl_y + $h_date + $h_quote, self::R, $tbl_y + $h_date + $h_quote, 0.5, self::INK );
		$pdf->line( $tbl_x, $tbl_y + $h_date + $h_quote + $h_cust, self::R, $tbl_y + $h_date + $h_quote + $h_cust, 0.5, self::INK );

		// Internal vertical separator
		$pdf->line( $tbl_x + $col_w1, $tbl_y, $tbl_x + $col_w1, $tbl_y + $total_tbl_h, 0.5, self::INK );

		$q_rows = array(
			array( 'DATE', array( $date_str ), $h_date ),
			array( 'PROPOSAL #', $quote_lines, $h_quote ),
			array( 'CUSTOMER ID', array( $customer_id ? (string) $customer_id : 'N/A' ), $h_cust ),
			array( 'VALID UNTIL', array( $valid_str ), $h_valid ),
		);

		$cur_y = $tbl_y;
		foreach ( $q_rows as $row ) {
			$h     = $row[2];
			$lines = $row[1];
			
			// Print label (centered vertically)
			$lbl_w = $pdf->width( $row[0], 7 );
			$lbl_y = $cur_y + ( $h - 7 ) / 2 + 6;
			$pdf->text( $tbl_x + ( $col_w1 - $lbl_w ) / 2, $lbl_y, $row[0], 7, true, self::INK );
			
			// Print value lines (centered vertically)
			$val_h = count( $lines ) * 11.5 - 3;
			$val_start_y = $cur_y + ( $h - $val_h ) / 2 + 7.5;
			
			foreach ( $lines as $idx => $line ) {
				$val_w = $pdf->width( $line, 8.5 );
				$pdf->text( $tbl_x + $col_w1 + ( $col_w2 - $val_w ) / 2, $val_start_y + $idx * 11.5, $line, 8.5, false, self::INK );
			}
			$cur_y += $h;
		}

		// ---- Customer / Ship To / AE band ----
		$banner_y = max( $sy, 44 + $total_tbl_h ) + 15;
		$pdf->fill_rect( self::L, $banner_y, self::R - self::L, 15, self::STEEL_BLUE );
		$pdf->text( self::L + 4, $banner_y + 11, 'Bill to', 7.5, true, self::WHITE );
		$pdf->text( 210.0, $banner_y + 11, 'Ship to', 7.5, true, self::WHITE );
		$pdf->text( 420.0, $banner_y + 11, 'ACCOUNT EXECUTIVE', 7.5, true, self::WHITE );

		$sy_comp = $banner_y + 27;

		if ( '' !== $loc_name ) {
			$loc_name_lines = $pdf->wrap( $loc_name, 146, 9.5 );
			foreach ( $loc_name_lines as $line ) {
				$pdf->text( self::L + 4, $sy_comp, $line, 9.5, true, self::INK );
				$sy_comp += 11;
			}
		}

		$bill_lines = array_values(
			array_filter(
				preg_split( '/\r\n|\r|\n/', (string) $billing_address ),
				'strlen'
			)
		);
		$wrapped_bill = array();
		foreach ( $bill_lines as $line ) {
			foreach ( $pdf->wrap( $line, 146, 9.0 ) as $w_line ) {
				$wrapped_bill[] = $w_line;
			}
		}
		foreach ( $wrapped_bill as $line ) {
			$pdf->text( self::L + 4, $sy_comp, $line, 9.0, false, self::INK );
			$sy_comp += 11;
		}
		
		$ship_lines = array_values(
			array_filter(
				preg_split( '/\r\n|\r|\n/', (string) $loc_addr ),
				'strlen'
			)
		);
		$wrapped_ship = array();
		foreach ( $ship_lines as $line ) {
			foreach ( $pdf->wrap( $line, 200, 9.0 ) as $w_line ) {
				$wrapped_ship[] = $w_line;
			}
		}
		$sy_ship = $banner_y + 27;
		foreach ( $wrapped_ship as $line ) {
			$pdf->text( 210.0, $sy_ship, $line, 9, false, self::INK );
			$sy_ship += 11;
		}

		$pdf->text( 420.0, $banner_y + 27, $ae_name, 9, true, self::INK );
		$pdf->text( 420.0, $banner_y + 38, $ae_phone, 9, false, self::INK );
		$pdf->text( 420.0, $banner_y + 49, $ae_email, 9, false, self::INK );

		$y = max( $banner_y + 60, $sy_ship, $sy_comp ) + 10;

		// Subtitle and Peach header bar (text lies just on top of the peach container)
		$pdf->text( self::L, $y, 'PRICES REFLECTED IN: ' . $currency, 8.5, true, self::INK );
		$y += 2; // Place the text just on top of the peach orange container

		// Wrap the quote number in the peach container using font size 9.5
		$peach_lines = $pdf->wrap( $quote_no, self::R - self::L - 12, 9.5 );
		$peach_count = count( $peach_lines );
		$peach_h     = 18.5 + ( $peach_count - 1 ) * 12;

		$pdf->fill_rect( self::L, $y, self::R - self::L, $peach_h, self::PEACH ); // Dynamic height to overlap steel blue header by 0.5pt
		
		foreach ( $peach_lines as $idx => $line ) {
			$pdf->text( self::L + 6, $y + 13 + $idx * 12, $line, 9.5, true, self::INK );
		}
		$y += $peach_h - 0.5; // Increment to align edge-to-edge with subsequent items

		// ---- Line items table ----
		$y = self::items_head( $pdf, $y );

		foreach ( $order->get_items() as $item ) {
			$qty     = (int) $item->get_quantity();
			$line    = (float) $item->get_total();
			$unit    = $qty ? $line / $qty : $line;
			$product = $item->get_product();
			$sku     = ( $product && $product->get_sku() ) ? $product->get_sku() : '';

			// Wrap columns to avoid overflows (compact font size 8.5)
			$sku_lines  = $pdf->wrap( $sku, 50, 8.5 );
			$name_lines = $pdf->wrap( $item->get_name(), 192, 8.5 );
			
			$row_h = 10 + max( count( $sku_lines ), count( $name_lines ) ) * 11.5;

			if ( $y + $row_h > self::BOTTOM - 60 ) {
				$pdf->add_page();
				$pdf->text( self::L, 66, 'Proposal #' . $order->get_order_number() . ' (continued)', 10, true, self::GRAY );
				$y = self::items_head( $pdf, 84 );
			}

			$base = $y + 11;
			foreach ( $sku_lines as $i => $ln ) {
				$pdf->text( 60.0, $base + $i * 11.5, $ln, 8.5, false, self::INK );
			}
			foreach ( $name_lines as $i => $ln ) {
				$pdf->text( 125.0, $base + $i * 11.5, $ln, 8.5, false, self::INK );
			}
			
			$pdf->text_right( 341.0, $base, (string) $qty, 8.5, false, self::INK );
			$pdf->text_right( 403.0, $base, self::money( $unit, $market ), 8.5, false, self::INK );
			$pdf->text_right( 468.0, $base, self::money( $line, $market ), 8.5, false, self::INK );

			// ETA logic (3-4 Weeks if not specified)
			$eta = '';
			if ( $product ) {
				$eta = (string) $product->get_meta( '_sycomp_eta' );
				if ( '' === $eta ) {
					$eta = (string) $product->get_meta( 'eta' );
				}
			}
			if ( '' === $eta ) {
				$eta = '3-4 Weeks';
			}
			$pdf->text( 478.0, $base, $eta, 8.5, false, self::INK );

			// Vertical borders
			$x_coords = array( 56.0, 121.0, 321.0, 346.0, 408.0, 473.0, 539.28 );
			foreach ( $x_coords as $x ) {
				$pdf->line( $x, $y, $x, $y + $row_h, 0.5, self::INK );
			}

			$y += $row_h;
			$pdf->line( self::L, $y, self::R, $y, 0.5, self::INK );
		}

		// ---- Totals block ----
		$fees            = $order->get_fees();
		$shipping        = (float) $order->get_shipping_total();
		$grand_total_val = (float) $order->get_total();

		$tax_rate        = Sycomp_B2B_Tax::rate( $market );
		$tax_label       = Sycomp_B2B_Tax::label( $market );
		$tax_display     = Sycomp_B2B_Tax::display_label( $market );

		$totals_rows = array();
		$totals_rows[] = array( 'SubTotal', self::money( $order->get_subtotal(), $market ) );
		// Shipping cost is always shown
		$totals_rows[] = array( 'Shipping', ( $shipping <= 0.0 ) ? 'TBD' : self::money( $shipping, $market ) );
		
		$tax_row = null;
		$other_fees = array();
		foreach ( $fees as $fee ) {
			$fee_name = $fee->get_name();
			$fee_total = (float) $fee->get_total();
			
			// Identify if this is a tax fee
			$is_tax_fee = false;
			$fee_name_lower = strtolower( $fee_name );
			$tax_label_lower = strtolower( $tax_label );
			if ( 
				( '' !== $tax_label_lower && strpos( $fee_name_lower, $tax_label_lower ) !== false ) || 
				strpos( $fee_name_lower, 'tax' ) !== false || 
				strpos( $fee_name_lower, 'gst' ) !== false || 
				strpos( $fee_name_lower, 'vat' ) !== false 
			) {
				$is_tax_fee = true;
			}
			
			if ( $is_tax_fee ) {
				$tax_row = array( $fee_name, self::money( $fee_total, $market ) );
			} else {
				$other_fees[] = array( $fee_name, self::money( $fee_total, $market ) );
			}
		}

		// Add tax row after Shipping
		if ( $tax_row ) {
			$totals_rows[] = $tax_row;
		} elseif ( $tax_rate > 0 ) {
			$tax_amount = Sycomp_B2B_Tax::calc( $order->get_subtotal(), $market );
			$totals_rows[] = array( $tax_display, self::money( $tax_amount, $market ) );
			$grand_total_val += $tax_amount;
		}

		// Add any other fees
		foreach ( $other_fees as $o_fee ) {
			$totals_rows[] = $o_fee;
		}

		$totals_rows[] = array( 'Grand Total', self::money( $grand_total_val, $market ) );

		$totals_h = count( $totals_rows ) * 13 + 10;
		if ( $y + $totals_h > self::BOTTOM - 60 ) {
			$pdf->add_page();
			$y = 84;
		}

		// Full-width totals block aligned directly below table (no gap)
		$tbl_x = self::L;
		$pdf->fill_rect( $tbl_x, $y, self::R - $tbl_x, $totals_h, self::TOT_BG );

		$pdf->line( $tbl_x, $y, self::R, $y, 0.6, self::INK );
		$pdf->line( $tbl_x, $y + $totals_h, self::R, $y + $totals_h, 0.6, self::INK );
		$pdf->line( $tbl_x, $y, $tbl_x, $y + $totals_h, 0.6, self::INK );
		$pdf->line( self::R, $y, self::R, $y + $totals_h, 0.6, self::INK );

		$gt_index = count( $totals_rows ) - 1;
		$ty = $y + 10;
		foreach ( $totals_rows as $idx => $t_row ) {
			if ( $idx === $gt_index ) {
				$ty += 2;
				// Move separator line up and text down to prevent cutting
				$pdf->line( $tbl_x, $ty - 3, self::R, $ty - 3, 0.5, self::INK );
				$pdf->text( $tbl_x + 6, $ty + 7, $t_row[0], 9.0, true, self::INK );
				$pdf->text_right( self::R - 6, $ty + 7, $t_row[1], 9.0, true, self::INK );
			} else {
				$pdf->text( $tbl_x + 6, $ty, $t_row[0], 8.5, true, self::INK );
				$pdf->text_right( self::R - 6, $ty, $t_row[1], 8.5, true, self::INK );
			}
			$ty += 13;
		}

		$y += $totals_h + 16;

		// ---- Terms and conditions section ----
		$terms = array(
			'1) Payment terms are as agreed to in the signed Master Supply Agreement (MSA). In the absence of a signed MSA, payment terms are Net 30 days depending upon the agreed upon MSA and late payments may be assessed interest at 1.5% per month.',
			'2) FOB Origin.',
			'3) Any shipping and handling costs quoted are estimates, actual shipping costs will be added at the time of invoice.',
			'4) Applicable taxes not included on this quote (including but not limited to VAT, Duties, Sales Tax, etc.) shall be charged at the time of invoice.',
			'5) This quote is CONFIDENTIAL and shall not be distributed to third parties without prior written consent from Sycomp.',
			'6) Unless otherwise noted above, quoted prices are valid for 30 days from the date of the quotation.',
			'7) Cancellation and return, if available, are subject to the manufacturer\'s policies. Dell CTO & BTO Models are non-cancellable.',
			'8) Returns on eligible items will incur a 10% restocking fee.',
			'9) Once the above said order is accepted and placed, the order cannot be cancelled after 14 working days. The products and goods cannot be returned under any circumstances, save for defective products which comply with the relevant provisions contained in our vendors standard terms and conditions...',
		);

		$terms_h = 24;
		foreach ( $terms as $term ) {
			$lines = $pdf->wrap( $term, self::R - self::L, 7.0 );
			$terms_h += count( $lines ) * 9.5;
		}

		if ( $y + $terms_h > self::BOTTOM ) {
			$pdf->add_page();
			$y = 84;
		}

		$pdf->text( self::L, $y, 'Terms', 9.5, true, self::INK );
		$pdf->line( self::L, $y + 4, self::R, $y + 4, 0.6, self::RULE );
		$y += 14;

		foreach ( $terms as $term ) {
			foreach ( $pdf->wrap( $term, self::R - self::L, 7.0 ) as $line ) {
				if ( $y + 10 > self::BOTTOM ) {
					$pdf->add_page();
					$y = 84;
				}
				$pdf->text( self::L, $y, $line, 7.0, false, self::INK );
				$y += 9.5;
			}
		}

		$y += 10;
		if ( $y + 35 > self::BOTTOM ) {
			$pdf->add_page();
			$y = 84;
		}
		$pdf->text( self::L, $y, 'Thank you!', 8.5, false, self::INK );
		$pdf->text( self::L, $y + 11, $ae_name, 8.5, false, self::INK );

		return $pdf;
	}
}
