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
	 * Shared nonce action for locations editing on account page.
	 */
	const NONCE = 'sycomp_account_nonce';

	/**
	 * Register the shortcodes and actions.
	 */
	public static function init() {
		add_shortcode( 'sycomp_account', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sycomp_orders', array( __CLASS__, 'orders_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ), 4 );
	}

	/**
	 * Verify the nonce on a posted form.
	 */
	protected static function verify_nonce() {
		return isset( $_POST['sycomp_account_nonce_field'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_account_nonce_field'] ) ), self::NONCE );
	}

	/**
	 * Output the nonce field.
	 */
	protected static function nonce_field() {
		wp_nonce_field( self::NONCE, 'sycomp_account_nonce_field' );
	}

	/**
	 * Route incoming write actions.
	 */
	public static function handle_actions() {
		if ( ! is_user_logged_in() || ! Sycomp_B2B_User::is_portal_user() ) {
			return;
		}

		if ( empty( $_POST['sycomp_account_action'] ) || ! self::verify_nonce() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['sycomp_account_action'] ) );
		$cid    = Sycomp_B2B_User::get_company();

		if ( 'location_save' === $action ) {
			self::do_location_save( $cid );
		} elseif ( 'location_delete' === $action ) {
			self::do_location_delete( $cid );
		}
	}

	/**
	 * Save/Update location.
	 */
	protected static function do_location_save( $cid ) {
		$lid = isset( $_POST['lid'] ) ? absint( wp_unslash( $_POST['lid'] ) ) : 0;

		$name    = isset( $_POST['l_name'] ) ? sanitize_text_field( wp_unslash( $_POST['l_name'] ) ) : '';
		$market  = isset( $_POST['l_market'] ) ? sanitize_key( wp_unslash( $_POST['l_market'] ) ) : '';
		$code    = isset( $_POST['l_code'] ) ? sanitize_text_field( wp_unslash( $_POST['l_code'] ) ) : '';

		$billing_raw = isset( $_POST['l_billing'] ) ? (array) $_POST['l_billing'] : array();
		$billing = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $billing_raw ) ), 'trim' ) );

		$drop_shipping_raw = isset( $_POST['l_drop_shipping'] ) ? (array) $_POST['l_drop_shipping'] : array();
		$drop_shipping = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $drop_shipping_raw ) ), 'trim' ) );

		$msp_shipping_raw = isset( $_POST['l_msp_shipping'] ) ? (array) $_POST['l_msp_shipping'] : array();
		$msp_shipping = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $msp_shipping_raw ) ), 'trim' ) );

		$legacy_address = '';
		if ( ! empty( $drop_shipping ) ) {
			$legacy_address = $drop_shipping[0];
		} elseif ( ! empty( $msp_shipping ) ) {
			$legacy_address = $msp_shipping[0];
		}

		if ( '' === $name || ! Sycomp_B2B_Markets::exists( $market ) ) {
			wp_safe_redirect( add_query_arg( 'cdone', 'error', sycomp_b2b_page_url( 'account' ) ) );
			exit;
		}

		if ( $lid ) {
			if ( get_post_type( $lid ) !== Sycomp_B2B_Post_Types::LOCATION || Sycomp_B2B_Post_Types::get_location_company( $lid ) !== $cid ) {
				wp_safe_redirect( add_query_arg( 'cdone', 'error', sycomp_b2b_page_url( 'account' ) ) );
				exit;
			}
			wp_update_post( array( 'ID' => $lid, 'post_title' => $name ) );
		} else {
			$lid = wp_insert_post(
				array(
					'post_type'   => Sycomp_B2B_Post_Types::LOCATION,
					'post_title'  => $name,
					'post_status' => 'publish',
				)
			);
		}

		if ( $lid && ! is_wp_error( $lid ) ) {
			$legacy_billing = ! empty( $billing ) ? $billing[0] : '';
			update_post_meta( $lid, Sycomp_B2B_Post_Types::META_LOCATION_COMPANY, $cid );
			update_post_meta( $lid, Sycomp_B2B_Post_Types::META_LOCATION_MARKET, $market );
			update_post_meta( $lid, Sycomp_B2B_Post_Types::META_LOCATION_CODE, $code );
			update_post_meta( $lid, Sycomp_B2B_Post_Types::META_LOCATION_ADDRESS, $legacy_address );
			update_post_meta( $lid, '_sycomp_billing_address', $legacy_billing );
			update_post_meta( $lid, '_sycomp_billing_addresses', $billing );
			update_post_meta( $lid, '_sycomp_drop_shipping_addresses', $drop_shipping );
			update_post_meta( $lid, '_sycomp_msp_shipping_addresses', $msp_shipping );
		}

		wp_safe_redirect( add_query_arg( 'cdone', 'location_saved', sycomp_b2b_page_url( 'account' ) ) );
		exit;
	}

	/**
	 * Trash location.
	 */
	protected static function do_location_delete( $cid ) {
		$lid = isset( $_POST['lid'] ) ? absint( wp_unslash( $_POST['lid'] ) ) : 0;
		if ( $lid && get_post_type( $lid ) === Sycomp_B2B_Post_Types::LOCATION && Sycomp_B2B_Post_Types::get_location_company( $lid ) === $cid ) {
			wp_trash_post( $lid );
			wp_safe_redirect( add_query_arg( 'cdone', 'location_deleted', sycomp_b2b_page_url( 'account' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'cdone', 'error', sycomp_b2b_page_url( 'account' ) ) );
		exit;
	}

	/**
	 * Output notices based on cdone query arg.
	 */
	protected static function notice() {
		$done = isset( $_GET['cdone'] ) ? sanitize_key( wp_unslash( $_GET['cdone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $done ) {
			return;
		}
		$ok = array(
			'location_saved'   => __( 'Location saved.', 'sycomp-b2b-portal' ),
			'location_deleted' => __( 'Location deleted.', 'sycomp-b2b-portal' ),
		);
		$warn = array(
			'error' => __( 'Something went wrong — please check the form and try again.', 'sycomp-b2b-portal' ),
		);
		if ( isset( $ok[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--success">' . esc_html( $ok[ $done ] ) . '</div>';
		} elseif ( isset( $warn[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html( $warn[ $done ] ) . '</div>';
		}
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
		$cid       = Sycomp_B2B_User::get_company();

		// Handle edit mode
		$edit_lid = isset( $_GET['lid'] ) ? absint( wp_unslash( $_GET['lid'] ) ) : 0;
		$edit_loc = null;
		if ( $edit_lid && Sycomp_B2B_Post_Types::LOCATION === get_post_type( $edit_lid )
			&& Sycomp_B2B_Post_Types::get_location_company( $edit_lid ) === $cid ) {
			$edit_loc = get_post( $edit_lid );
		}

		$show_form   = isset( $_GET['add_loc'] ) || $edit_loc;
		$cur_market  = $edit_loc ? Sycomp_B2B_Post_Types::get_location_market( $edit_loc->ID ) : '';
		$cur_code    = $edit_loc ? (string) get_post_meta( $edit_loc->ID, Sycomp_B2B_Post_Types::META_LOCATION_CODE, true ) : '';
		$cur_billing = $edit_loc ? Sycomp_B2B_Post_Types::get_location_billing_addresses( $edit_loc->ID ) : array();
		$cur_drop    = $edit_loc ? Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $edit_loc->ID ) : array();
		$cur_msp     = $edit_loc ? Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $edit_loc->ID ) : array();
		?>
		<section class="sy-panel">
			<h2 class="sy-panel__title" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
				<span><?php esc_html_e( 'Locations & addresses', 'sycomp-b2b-portal' ); ?></span>
				<?php if ( ! $show_form ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'add_loc', 1, sycomp_b2b_page_url( 'account' ) ) ); ?>" class="sy-btn sy-btn--accent sy-btn--sm"><?php esc_html_e( 'Add Location', 'sycomp-b2b-portal' ); ?></a>
				<?php endif; ?>
			</h2>
			<div class="sy-panel__body">
				<?php self::notice(); ?>

				<?php if ( $show_form ) : ?>
					<form method="post" class="sy-form" style="margin-bottom: 24px; border-bottom: 1px solid var(--sy-border); padding-bottom: 24px;">
						<?php self::nonce_field(); ?>
						<input type="hidden" name="sycomp_account_action" value="location_save">
						<input type="hidden" name="lid" value="<?php echo esc_attr( $edit_loc ? $edit_loc->ID : 0 ); ?>">
						<style>
						.sy-address-pill {
							display: flex;
							align-items: flex-start;
							justify-content: space-between;
							background: #f3f4f6;
							border: 1px solid #e5e7eb;
							border-radius: 6px;
							padding: 8px 12px;
							font-size: 13px;
							color: #1f2937;
							cursor: pointer;
							transition: background 0.2s, border-color 0.2s;
							white-space: pre-wrap;
						}
						.sy-address-pill:hover {
							background: #e5e7eb;
							border-color: #d1d5db;
						}
						.sy-address-pill-remove {
							margin-left: 8px;
							color: #9ca3af;
							font-weight: bold;
							font-size: 16px;
							cursor: pointer;
							padding: 0 4px;
							line-height: 1;
						}
						.sy-address-pill-remove:hover {
							color: #ef4444;
						}
						</style>

						<div class="sy-form__grid">
							<label class="sy-field"><span class="sy-field__label"><?php esc_html_e( 'Billing name', 'sycomp-b2b-portal' ); ?></span>
							<input type="text" name="l_name" required value="<?php echo esc_attr( $edit_loc ? get_the_title( $edit_loc ) : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. India Office', 'sycomp-b2b-portal' ); ?>"></label>
							
							<label class="sy-field"><span class="sy-field__label"><?php esc_html_e( 'Currency', 'sycomp-b2b-portal' ); ?></span>
							<select name="l_market" required>
								<option value=""><?php esc_html_e( '— Select currency —', 'sycomp-b2b-portal' ); ?></option>
								<?php foreach ( Sycomp_B2B_Markets::all() as $key => $market ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_market, $key ); ?>><?php echo esc_html( $market['label'] . ' (' . $market['currency'] . ')' ); ?></option>
								<?php endforeach; ?>
							</select></label>
						</div>

						<div class="sy-field">
							<span class="sy-field__label"><?php esc_html_e( 'Billing address', 'sycomp-b2b-portal' ); ?></span>
							<div style="display: flex; gap: 8px; align-items: stretch;">
								<textarea id="l_billing_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
								<button type="button" id="l_billing_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
							</div>
							<div id="l_billing_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
								<?php foreach ( $cur_billing as $addr ) : ?>
									<?php if ( ! empty( trim( $addr ) ) ) : ?>
										<div class="sy-address-pill" onclick="editAddress(this, 'l_billing_input')">
											<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
											<input type="hidden" name="l_billing[]" value="<?php echo esc_attr( $addr ); ?>">
											<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</div>

						<label class="sy-field"><span class="sy-field__label"><?php esc_html_e( 'Location code', 'sycomp-b2b-portal' ); ?></span>
						<input type="text" name="l_code" value="<?php echo esc_attr( $cur_code ); ?>" placeholder="<?php esc_attr_e( 'optional', 'sycomp-b2b-portal' ); ?>"></label>

						<div class="sy-field">
							<span class="sy-field__label"><?php esc_html_e( 'Drop Shipping Addresses', 'sycomp-b2b-portal' ); ?></span>
							<div style="display: flex; gap: 8px; align-items: stretch;">
								<textarea id="l_drop_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
								<button type="button" id="l_drop_shipping_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
							</div>
							<div id="l_drop_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
								<?php foreach ( $cur_drop as $addr ) : ?>
									<?php if ( ! empty( trim( $addr ) ) ) : ?>
										<div class="sy-address-pill" onclick="editAddress(this, 'l_drop_shipping_input')">
											<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
											<input type="hidden" name="l_drop_shipping[]" value="<?php echo esc_attr( $addr ); ?>">
											<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="sy-field">
							<span class="sy-field__label"><?php esc_html_e( 'MSP Shipping Addresses', 'sycomp-b2b-portal' ); ?></span>
							<div style="display: flex; gap: 8px; align-items: stretch;">
								<textarea id="l_msp_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
								<button type="button" id="l_msp_shipping_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
							</div>
							<div id="l_msp_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">
								<?php foreach ( $cur_msp as $addr ) : ?>
									<?php if ( ! empty( trim( $addr ) ) ) : ?>
										<div class="sy-address-pill" onclick="editAddress(this, 'l_msp_shipping_input')">
											<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
											<input type="hidden" name="l_msp_shipping[]" value="<?php echo esc_attr( $addr ); ?>">
											<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="sy-form__actions">
							<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit"><?php echo $edit_loc ? esc_html__( 'Update location', 'sycomp-b2b-portal' ) : esc_html__( 'Add location', 'sycomp-b2b-portal' ); ?></button>
							<a class="sy-btn sy-btn--ghost sy-btn--sm" href="<?php echo esc_url( sycomp_b2b_page_url( 'account' ) ); ?>"><?php esc_html_e( 'Cancel', 'sycomp-b2b-portal' ); ?></a>
						</div>
					</form>

					<script type="text/javascript">
					function editAddress(pill, inputId) {
						var input = document.getElementById(inputId);
						var textSpan = pill.querySelector('.sy-address-pill-text');
						if (input && textSpan) {
							input.value = textSpan.textContent || textSpan.innerText;
							pill.remove();
							input.focus();
						}
					}

					function setupAddressInput(inputId, btnId, listId, inputName) {
						var input = document.getElementById(inputId);
						var btn = document.getElementById(btnId);
						var list = document.getElementById(listId);

						if (!input || !btn || !list) return;

						function addAddress() {
							var val = input.value.trim();
							if (val === '') return;

							var pill = document.createElement('div');
							pill.className = 'sy-address-pill';
							pill.addEventListener('click', function() {
								editAddress(pill, inputId);
							});

							var textSpan = document.createElement('span');
							textSpan.className = 'sy-address-pill-text';
							textSpan.textContent = val;
							pill.appendChild(textSpan);

							var hiddenInput = document.createElement('input');
							hiddenInput.type = 'hidden';
							hiddenInput.name = inputName + '[]';
							hiddenInput.value = val;
							pill.appendChild(hiddenInput);

							var removeCross = document.createElement('span');
							removeCross.className = 'sy-address-pill-remove';
							removeCross.innerHTML = '&times;';
							removeCross.addEventListener('click', function(e) {
								e.stopPropagation();
								pill.remove();
							});
							pill.appendChild(removeCross);

							list.appendChild(pill);
							input.value = '';
							input.focus();
						}

						btn.addEventListener('click', addAddress);

						input.addEventListener('keydown', function(e) {
							if (e.key === 'Enter' && !e.shiftKey) {
								e.preventDefault();
								addAddress();
							}
						});
					}

					document.addEventListener('DOMContentLoaded', function() {
						setupAddressInput('l_billing_input', 'l_billing_add_btn', 'l_billing_list', 'l_billing');
						setupAddressInput('l_drop_shipping_input', 'l_drop_shipping_add_btn', 'l_drop_shipping_list', 'l_drop_shipping');
						setupAddressInput('l_msp_shipping_input', 'l_msp_shipping_add_btn', 'l_msp_shipping_list', 'l_msp_shipping');
					});
					</script>
				<?php endif; ?>

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
										<span style="font-size: var(--sy-text-2xs); text-transform: uppercase; font-weight: bold; color: var(--sy-muted); display: block; margin-bottom: 2px;"><?php esc_html_e( 'Billing Address', 'sycomp-b2b-portal' ); ?></span>
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

								<div class="sy-loccard__actions" style="margin-top: 14px; display: flex; gap: 8px; border-top: 1px solid var(--sy-border); padding-top: 10px;">
									<a href="<?php echo esc_url( add_query_arg( 'lid', $location->ID, sycomp_b2b_page_url( 'account' ) ) ); ?>" class="sy-btn sy-btn--ghost sy-btn--sm"><?php esc_html_e( 'Edit', 'sycomp-b2b-portal' ); ?></a>
									<form method="post" style="display:inline; margin: 0;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this location?', 'sycomp-b2b-portal' ) ); ?>');">
										<?php self::nonce_field(); ?>
										<input type="hidden" name="sycomp_account_action" value="location_delete">
										<input type="hidden" name="lid" value="<?php echo esc_attr( $location->ID ); ?>">
										<button class="sy-btn sy-btn--ghost sy-btn--sm" style="color:var(--sy-red, #ef4444);" type="submit"><?php esc_html_e( 'Delete', 'sycomp-b2b-portal' ); ?></button>
									</form>
								</div>
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
