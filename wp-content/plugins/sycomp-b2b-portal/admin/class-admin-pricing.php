<?php
/**
 * Admin: per-market pricing on the product edit screen.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Admin_Pricing.
 */
class Sycomp_B2B_Admin_Pricing {

	const NONCE = 'sycomp_pricing_meta';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );

		add_filter( 'manage_product_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Register the pricing meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'sycomp_market_pricing',
			__( 'Procurement Price', 'sycomp-b2b-portal' ),
			array( __CLASS__, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render the per-market price fields.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_field' );
		$prices = Sycomp_B2B_Pricing::get_all_market_prices( $post->ID );
		?>
		<p class="description">
			<?php esc_html_e( 'Set the procurement price (vendor cost) and GP margin for each market. Leave a market blank to make the product unavailable there. These prices override the standard WooCommerce price for portal buyers.', 'sycomp-b2b-portal' ); ?>
		</p>
		<table class="sycomp-pricing-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Market', 'sycomp-b2b-portal' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'sycomp-b2b-portal' ); ?></th>
					<th><?php esc_html_e( 'Price (Vendor Cost)', 'sycomp-b2b-portal' ); ?></th>
					<th><?php esc_html_e( 'GP (%)', 'sycomp-b2b-portal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Sycomp_B2B_Markets::all() as $key => $market ) : ?>
					<?php 
					$sycomp_pc = Sycomp_B2B_Pricing::get_price_currency( $post->ID, $key ); 
					$gp = Sycomp_B2B_Pricing::get_product_market_gp( $post->ID, $key );
					$cust_price = 0.0;
					$raw = $prices[ $key ];
					if ( '' !== $raw && is_numeric( $raw ) ) {
						$mcur = Sycomp_B2B_Markets::currency( $key );
						if ( $sycomp_pc !== $mcur ) {
							$converted = Sycomp_B2B_FX::convert( $raw, $sycomp_pc, $mcur );
						} else {
							$converted = (float) $raw;
						}
						if ( $gp > 0 && $gp < 100 ) {
							$cust_price = $converted / ( 1.0 - ( $gp / 100.0 ) );
						} else {
							$cust_price = $converted;
						}
					}
					$cust_price_show = $cust_price > 0 ? (string) wc_format_decimal( $cust_price, Sycomp_B2B_Markets::decimals( $key ) ) : '';
					?>
					<tr data-market="<?php echo esc_attr( $key ); ?>">
						<td><strong><?php echo esc_html( $market['label'] ); ?></strong></td>
						<td>
							<select name="sycomp_price_cur[<?php echo esc_attr( $key ); ?>]">
								<?php foreach ( Sycomp_B2B_FX::currencies() as $sycomp_c ) : ?>
									<option value="<?php echo esc_attr( $sycomp_c ); ?>" <?php selected( $sycomp_pc, $sycomp_c ); ?>><?php echo esc_html( $sycomp_c ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<input type="text" class="sycomp-price-input"
								name="sycomp_price[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $raw ); ?>"
								placeholder="0.00">
							<div class="sycomp-customer-price-hint" style="font-size: 11px; font-weight: bold; padding: 4px 8px; margin-top: 5px; display: <?php echo $cust_price_show ? 'block' : 'none'; ?>; background: #fef9c3; border: 1px solid #fef08a; color: #854d0e; border-radius: 3px;">
								Customer Price: <span class="value"><?php echo $cust_price_show ? Sycomp_B2B_Markets::symbol( $key ) . ' ' . $cust_price_show : ''; ?></span>
							</div>
						</td>
						<td>
							<input type="number" step="0.01" min="0" max="99" class="sycomp-gp-input"
								name="sycomp_gp[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( number_format( $gp, 2, '.', '' ) ); ?>"
								placeholder="15.00" style="width: 70px;">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
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

			$('.sycomp-pricing-table tbody tr').each(function() {
				var $row = $(this);
				var marketKey = $row.attr('data-market');
				var $priceInput = $row.find('.sycomp-price-input');
				var $curSelect = $row.find('select[name^="sycomp_price_cur"]');
				var $gpInput = $row.find('.sycomp-gp-input');
				var $hint = $row.find('.sycomp-customer-price-hint');
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

		<h4 style="margin:18px 0 4px;">Available to companies</h4>
		<p class="description">Tick the companies whose buyers may see this product in their catalogue. A product with no company ticked is hidden from every catalogue.</p>
		<?php
		$sycomp_enabled = array_map( 'strval', (array) get_post_meta( $post->ID, '_sycomp_company' ) );
		$sycomp_companies = Sycomp_B2B_Post_Types::get_companies();
		if ( empty( $sycomp_companies ) ) {
			echo '<p><em>' . esc_html__( 'No companies created yet.', 'sycomp-b2b-portal' ) . '</em></p>';
		}
		foreach ( $sycomp_companies as $sycomp_company ) :
			?>
			<label style="display:block;margin:4px 0;">
				<input type="checkbox" name="sycomp_company[]" value="<?php echo esc_attr( $sycomp_company->ID ); ?>"
					<?php checked( in_array( (string) $sycomp_company->ID, $sycomp_enabled, true ) ); ?>>
				<?php echo esc_html( get_the_title( $sycomp_company ) ); ?>
			</label>
			<?php
		endforeach;
	}

	/**
	 * Save per-market prices.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_field' ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_field' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted = isset( $_POST['sycomp_price'] ) && is_array( $_POST['sycomp_price'] )
			? wp_unslash( $_POST['sycomp_price'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		$cur_in = ( isset( $_POST['sycomp_price_cur'] ) && is_array( $_POST['sycomp_price_cur'] ) )
			? wp_unslash( $_POST['sycomp_price_cur'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		$gp_in = ( isset( $_POST['sycomp_gp'] ) && is_array( $_POST['sycomp_gp'] ) )
			? wp_unslash( $_POST['sycomp_gp'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();

		foreach ( Sycomp_B2B_Markets::keys() as $market_key ) {
			$raw = isset( $submitted[ $market_key ] ) ? trim( (string) $submitted[ $market_key ] ) : '';
			Sycomp_B2B_Pricing::set_market_price( $post_id, $market_key, $raw );
			
			$cur = isset( $cur_in[ $market_key ] ) ? sanitize_text_field( $cur_in[ $market_key ] ) : '';
			Sycomp_B2B_Pricing::set_price_currency( $post_id, $market_key, $cur );
			
			$gp = isset( $gp_in[ $market_key ] ) ? trim( (string) $gp_in[ $market_key ] ) : '';
			Sycomp_B2B_Pricing::set_product_market_gp( $post_id, $market_key, $gp );
		}

		// Company availability.
		$companies = isset( $_POST['sycomp_company'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['sycomp_company'] ) ) : array();
		delete_post_meta( $post_id, '_sycomp_company' );
		foreach ( array_unique( array_filter( $companies ) ) as $sycomp_cid ) {
			add_post_meta( $post_id, '_sycomp_company', $sycomp_cid );
		}
	}

	/* ---------------------------------------------------------------------
	 * Product list column.
	 * ------------------------------------------------------------------ */

	/**
	 * Add a "Market pricing" column to the product list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'price' === $key ) {
				$new['sycomp_pricing'] = __( 'Market pricing', 'sycomp-b2b-portal' );
			}
		}
		if ( ! isset( $new['sycomp_pricing'] ) ) {
			$new['sycomp_pricing'] = __( 'Market pricing', 'sycomp-b2b-portal' );
		}
		return $new;
	}

	/**
	 * Render the "Market pricing" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'sycomp_pricing' !== $column ) {
			return;
		}
		$prices = Sycomp_B2B_Pricing::get_all_market_prices( $post_id );
		$set    = count( array_filter( $prices, static function ( $v ) {
			return '' !== $v;
		} ) );
		$total  = count( $prices );

		$class = ( $set === $total ) ? 'sycomp-pill--ok' : ( $set ? 'sycomp-pill--partial' : 'sycomp-pill--none' );
		printf(
			'<span class="sycomp-pill %s">%s / %s</span>',
			esc_attr( $class ),
			esc_html( (string) $set ),
			esc_html( (string) $total )
		);
	}
}
