<?php
/**
 * Admin: company & location editing, plus the user→company assignment.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Admin_Company.
 */
class Sycomp_B2B_Admin_Company {

	const NONCE = 'sycomp_location_meta';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Sycomp_B2B_Post_Types::LOCATION, array( __CLASS__, 'save_location' ), 10, 2 );
		add_action( 'save_post_' . Sycomp_B2B_Post_Types::COMPANY, array( __CLASS__, 'save_company' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media' ) );

		// Admin list columns.
		add_filter( 'manage_' . Sycomp_B2B_Post_Types::LOCATION . '_posts_columns', array( __CLASS__, 'location_columns' ) );
		add_action( 'manage_' . Sycomp_B2B_Post_Types::LOCATION . '_posts_custom_column', array( __CLASS__, 'location_column_value' ), 10, 2 );
		add_filter( 'manage_' . Sycomp_B2B_Post_Types::COMPANY . '_posts_columns', array( __CLASS__, 'company_columns' ) );
		add_action( 'manage_' . Sycomp_B2B_Post_Types::COMPANY . '_posts_custom_column', array( __CLASS__, 'company_column_value' ), 10, 2 );

		// User profile: company assignment.
		add_action( 'show_user_profile', array( __CLASS__, 'user_company_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_company_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_company' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_company' ) );
	}

	/* ---------------------------------------------------------------------
	 * Meta boxes.
	 * ------------------------------------------------------------------ */

	/**
	 * Register meta boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'sycomp_location_details',
			__( 'Location details', 'sycomp-b2b-portal' ),
			array( __CLASS__, 'render_location_box' ),
			Sycomp_B2B_Post_Types::LOCATION,
			'normal',
			'high'
		);
		add_meta_box(
			'sycomp_company_locations',
			__( 'Locations', 'sycomp-b2b-portal' ),
			array( __CLASS__, 'render_company_box' ),
			Sycomp_B2B_Post_Types::COMPANY,
			'normal',
			'default'
		);
		add_meta_box(
			'sycomp_company_logo',
			__( 'Company logo', 'sycomp-b2b-portal' ),
			array( __CLASS__, 'render_company_logo_box' ),
			Sycomp_B2B_Post_Types::COMPANY,
			'side',
			'default'
		);
	}

	/**
	 * Location meta box.
	 *
	 * @param WP_Post $post Location post.
	 */
	public static function render_location_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE . '_field' );

		$company_id        = Sycomp_B2B_Post_Types::get_location_company( $post->ID );
		$market            = Sycomp_B2B_Post_Types::get_location_market( $post->ID );
		$code              = get_post_meta( $post->ID, Sycomp_B2B_Post_Types::META_LOCATION_CODE, true );
		$address           = Sycomp_B2B_Post_Types::get_location_address( $post->ID );
		$billing_addresses = Sycomp_B2B_Post_Types::get_location_billing_addresses( $post->ID );
		$drop_shipping     = Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $post->ID );
		$msp_shipping      = Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $post->ID );
		?>
		<style>
		.sy-admin-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
			max-width: 100%;
			padding: 10px 0;
		}
		.sy-admin-field {
			display: flex;
			flex-direction: column;
			gap: 5px;
		}
		.sy-admin-field label {
			font-weight: 600;
			color: #1d2327;
		}
		.sy-admin-field input, .sy-admin-field select, .sy-admin-field textarea {
			width: 100%;
			max-width: 100%;
			box-sizing: border-box;
		}
		.sy-address-pill {
			display: flex;
			align-items: flex-start;
			justify-content: space-between;
			background: #f6f7f7;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 8px 12px;
			font-size: 13px;
			color: #2c3338;
			cursor: pointer;
			transition: background 0.2s, border-color 0.2s;
			white-space: pre-wrap;
			margin-bottom: 6px;
		}
		.sy-address-pill:hover {
			background: #f0f0f1;
			border-color: #8c8f94;
		}
		.sy-address-pill-remove {
			margin-left: 8px;
			color: #8c8f94;
			font-weight: bold;
			font-size: 16px;
			cursor: pointer;
			padding: 0 4px;
			line-height: 1;
		}
		.sy-address-pill-remove:hover {
			color: #b32d2e;
		}
		</style>
		<div class="sy-admin-grid">
			<!-- Row 1: Company and Market -->
			<div class="sy-admin-field">
				<label for="sycomp_company_id"><?php esc_html_e( 'Company', 'sycomp-b2b-portal' ); ?> <span class="description">(required)</span></label>
				<select name="sycomp_company_id" id="sycomp_company_id" required>
					<option value=""><?php esc_html_e( '— Select a company —', 'sycomp-b2b-portal' ); ?></option>
					<?php foreach ( Sycomp_B2B_Post_Types::get_companies() as $company ) : ?>
						<option value="<?php echo esc_attr( $company->ID ); ?>" <?php selected( $company_id, $company->ID ); ?>>
							<?php echo esc_html( get_the_title( $company ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description" style="margin-top: 2px;"><?php esc_html_e( 'The customer company this location belongs to.', 'sycomp-b2b-portal' ); ?></p>
			</div>

			<div class="sy-admin-field">
				<label for="sycomp_market"><?php esc_html_e( 'Market', 'sycomp-b2b-portal' ); ?> <span class="description">(required)</span></label>
				<select name="sycomp_market" id="sycomp_market" required>
					<option value=""><?php esc_html_e( '— Select a market —', 'sycomp-b2b-portal' ); ?></option>
					<?php foreach ( Sycomp_B2B_Markets::all() as $key => $m ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $market, $key ); ?>>
							<?php echo esc_html( $m['label'] . ' (' . $m['currency'] . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description" style="margin-top: 2px;"><?php esc_html_e( 'Pricing and currency this location sees are determined by its market.', 'sycomp-b2b-portal' ); ?></p>
			</div>

			<!-- Row 2: Billing Addresses and Location Code -->
			<div class="sy-admin-field">
				<label><?php esc_html_e( 'Bill to', 'sycomp-b2b-portal' ); ?></label>
				<div style="display: flex; gap: 8px; align-items: stretch;">
					<textarea id="sycomp_billing_input" rows="1" style="flex-grow: 1; resize: none; min-height: 30px; padding: 6px;" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
					<button type="button" id="sycomp_billing_add_btn" class="button" style="display: flex; align-items: center; justify-content: center; font-size: 16px;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
				</div>
				<div id="sycomp_billing_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px;">
					<?php foreach ( $billing_addresses as $addr ) : ?>
						<?php if ( ! empty( trim( $addr ) ) ) : ?>
							<div class="sy-address-pill" onclick="editAdminAddress(this, 'sycomp_billing_input')">
								<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
								<input type="hidden" name="sycomp_billing[]" value="<?php echo esc_attr( $addr ); ?>">
								<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="sy-admin-field">
				<label for="sycomp_location_code"><?php esc_html_e( 'Location code', 'sycomp-b2b-portal' ); ?></label>
				<input type="text" name="sycomp_location_code" id="sycomp_location_code" value="<?php echo esc_attr( $code ); ?>" placeholder="<?php esc_attr_e( 'optional', 'sycomp-b2b-portal' ); ?>">
			</div>

			<!-- Row 3: Drop Shipping Addresses & MSP Shipping Addresses -->
			<!-- Drop Shipping Addresses -->
			<div class="sy-admin-field">
				<label><?php esc_html_e( 'Drop Shipping Addresses', 'sycomp-b2b-portal' ); ?></label>
				<div style="display: flex; gap: 8px; align-items: stretch;">
					<textarea id="sycomp_drop_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 30px; padding: 6px;" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
					<button type="button" id="sycomp_drop_shipping_add_btn" class="button" style="display: flex; align-items: center; justify-content: center; font-size: 16px;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
				</div>
				<div id="sycomp_drop_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px;">
					<?php foreach ( $drop_shipping as $addr ) : ?>
						<?php if ( ! empty( trim( $addr ) ) ) : ?>
							<div class="sy-address-pill" onclick="editAdminAddress(this, 'sycomp_drop_shipping_input')">
								<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
								<input type="hidden" name="sycomp_drop_shipping[]" value="<?php echo esc_attr( $addr ); ?>">
								<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- MSP Shipping Addresses -->
			<div class="sy-admin-field">
				<label><?php esc_html_e( 'MSP Shipping Addresses', 'sycomp-b2b-portal' ); ?></label>
				<div style="display: flex; gap: 8px; align-items: stretch;">
					<textarea id="sycomp_msp_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 30px; padding: 6px;" placeholder="<?php esc_attr_e( 'Type address and press enter...', 'sycomp-b2b-portal' ); ?>"></textarea>
					<button type="button" id="sycomp_msp_shipping_add_btn" class="button" style="display: flex; align-items: center; justify-content: center; font-size: 16px;" title="<?php esc_attr_e( 'Add address', 'sycomp-b2b-portal' ); ?>">↵</button>
				</div>
				<div id="sycomp_msp_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px;">
					<?php foreach ( $msp_shipping as $addr ) : ?>
						<?php if ( ! empty( trim( $addr ) ) ) : ?>
							<div class="sy-address-pill" onclick="editAdminAddress(this, 'sycomp_msp_shipping_input')">
								<span class="sy-address-pill-text"><?php echo esc_html( $addr ); ?></span>
								<input type="hidden" name="sycomp_msp_shipping[]" value="<?php echo esc_attr( $addr ); ?>">
								<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		function editAdminAddress(pill, inputId) {
			var input = document.getElementById(inputId);
			var textSpan = pill.querySelector('.sy-address-pill-text');
			if (input && textSpan) {
				input.value = textSpan.textContent || textSpan.innerText;
				pill.remove();
				input.focus();
			}
		}

		function setupAdminAddressInput(inputId, btnId, listId, inputName) {
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
					editAdminAddress(pill, inputId);
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

		// Use immediate execution or DOMContentLoaded to set it up
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initAdminAddressPills);
		} else {
			initAdminAddressPills();
		}

		function initAdminAddressPills() {
			setupAdminAddressInput('sycomp_billing_input', 'sycomp_billing_add_btn', 'sycomp_billing_list', 'sycomp_billing');
			setupAdminAddressInput('sycomp_drop_shipping_input', 'sycomp_drop_shipping_add_btn', 'sycomp_drop_shipping_list', 'sycomp_drop_shipping');
			setupAdminAddressInput('sycomp_msp_shipping_input', 'sycomp_msp_shipping_add_btn', 'sycomp_msp_shipping_list', 'sycomp_msp_shipping');
		}
		</script>
		<?php
	}

	/**
	 * Company meta box — lists the company's locations.
	 *
	 * @param WP_Post $post Company post.
	 */
	public static function render_company_box( $post ) {
		$locations = Sycomp_B2B_Post_Types::get_company_locations( $post->ID );
		echo '<p>';
		printf(
			/* translators: %s: location count. */
			esc_html( _n( 'This company has %s location.', 'This company has %s locations.', count( $locations ), 'sycomp-b2b-portal' ) ),
			esc_html( number_format_i18n( count( $locations ) ) )
		);
		echo '</p>';

		if ( $locations ) {
			echo '<ul class="ul-disc">';
			foreach ( $locations as $loc ) {
				$market = Sycomp_B2B_Markets::label( Sycomp_B2B_Post_Types::get_location_market( $loc->ID ) );
				printf(
					'<li><a href="%s">%s</a> — %s</li>',
					esc_url( get_edit_post_link( $loc->ID ) ),
					esc_html( get_the_title( $loc ) ),
					esc_html( $market )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=' . Sycomp_B2B_Post_Types::LOCATION ) ),
			esc_html__( 'Add a location', 'sycomp-b2b-portal' )
		);
	}

	/**
	 * Company logo meta box - a media-library logo picker.
	 *
	 * @param WP_Post $post Company post.
	 */
	public static function render_company_logo_box( $post ) {
		wp_nonce_field( 'sycomp_company_meta', 'sycomp_company_meta_field' );
		$logo_id  = (int) get_post_meta( $post->ID, '_sycomp_logo_id', true );
		$logo_src = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$style    = 'max-width:100%;max-height:120px;border:1px solid #dcdcde;background:#fff;padding:6px;border-radius:4px;';
		?>
		<div id="sycomp-logo-preview" style="margin-bottom:10px;">
			<?php if ( $logo_src ) : ?>
				<img src="<?php echo esc_url( $logo_src ); ?>" style="<?php echo esc_attr( $style ); ?>">
			<?php else : ?>
				<em><?php esc_html_e( 'No logo set - the company name is used instead.', 'sycomp-b2b-portal' ); ?></em>
			<?php endif; ?>
		</div>
		<input type="hidden" name="sycomp_logo_id" id="sycomp-logo-id" value="<?php echo esc_attr( $logo_id ); ?>">
		<button type="button" class="button" id="sycomp-logo-pick"><?php esc_html_e( 'Select logo', 'sycomp-b2b-portal' ); ?></button>
		<button type="button" class="button" id="sycomp-logo-clear" <?php disabled( ! $logo_id ); ?>><?php esc_html_e( 'Remove', 'sycomp-b2b-portal' ); ?></button>
		<p class="description"><?php esc_html_e( 'Shown on this company\'s purchase-order PDFs. PNG or JPG; a wide logo on a light background works best.', 'sycomp-b2b-portal' ); ?></p>
		<script>
		( function () {
			var pick = document.getElementById( 'sycomp-logo-pick' );
			var clear = document.getElementById( 'sycomp-logo-clear' );
			var input = document.getElementById( 'sycomp-logo-id' );
			var preview = document.getElementById( 'sycomp-logo-preview' );
			var imgStyle = <?php echo wp_json_encode( $style ); ?>;
			var frame;
			pick.addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) { return; }
				if ( frame ) { frame.open(); return; }
				frame = wp.media( {
					title: 'Select company logo',
					library: { type: 'image' },
					multiple: false,
					button: { text: 'Use this logo' }
				} );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					var url = ( a.sizes && a.sizes.medium ) ? a.sizes.medium.url : a.url;
					input.value = a.id;
					preview.innerHTML = '<img src="' + url + '" style="' + imgStyle + '">';
					clear.disabled = false;
				} );
				frame.open();
			} );
			clear.addEventListener( 'click', function () {
				input.value = '';
				preview.innerHTML = '<em>No logo set - the company name is used instead.</em>';
				clear.disabled = true;
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Save company meta (logo).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_company( $post_id, $post ) {
		if ( ! isset( $_POST['sycomp_company_meta_field'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_company_meta_field'] ) ), 'sycomp_company_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$logo_id = isset( $_POST['sycomp_logo_id'] ) ? absint( wp_unslash( $_POST['sycomp_logo_id'] ) ) : 0;
		if ( $logo_id ) {
			update_post_meta( $post_id, '_sycomp_logo_id', $logo_id );
		} else {
			delete_post_meta( $post_id, '_sycomp_logo_id' );
		}
	}

	/**
	 * Load the media library on the company edit screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_media( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && Sycomp_B2B_Post_Types::COMPANY === $screen->post_type ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Save location meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_location( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE . '_field' ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_field' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$company_id = isset( $_POST['sycomp_company_id'] ) ? absint( wp_unslash( $_POST['sycomp_company_id'] ) ) : 0;
		$market     = isset( $_POST['sycomp_market'] ) ? sanitize_key( wp_unslash( $_POST['sycomp_market'] ) ) : '';
		$code       = isset( $_POST['sycomp_location_code'] ) ? sanitize_text_field( wp_unslash( $_POST['sycomp_location_code'] ) ) : '';

		$billing_raw = isset( $_POST['sycomp_billing'] ) ? (array) $_POST['sycomp_billing'] : array();
		$billing     = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $billing_raw ) ), 'trim' ) );

		$drop_shipping_raw = isset( $_POST['sycomp_drop_shipping'] ) ? (array) $_POST['sycomp_drop_shipping'] : array();
		$drop_shipping = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $drop_shipping_raw ) ), 'trim' ) );

		$msp_shipping_raw = isset( $_POST['sycomp_msp_shipping'] ) ? (array) $_POST['sycomp_msp_shipping'] : array();
		$msp_shipping = array_values( array_filter( array_map( 'sanitize_textarea_field', array_map( 'wp_unslash', $msp_shipping_raw ) ), 'trim' ) );

		$legacy_address = '';
		if ( ! empty( $drop_shipping ) ) {
			$legacy_address = $drop_shipping[0];
		} elseif ( ! empty( $msp_shipping ) ) {
			$legacy_address = $msp_shipping[0];
		}

		$legacy_billing = '';
		if ( ! empty( $billing ) ) {
			$legacy_billing = $billing[0];
		}

		update_post_meta( $post_id, Sycomp_B2B_Post_Types::META_LOCATION_COMPANY, $company_id );
		update_post_meta( $post_id, Sycomp_B2B_Post_Types::META_LOCATION_CODE, $code );
		update_post_meta( $post_id, Sycomp_B2B_Post_Types::META_LOCATION_ADDRESS, $legacy_address );
		update_post_meta( $post_id, '_sycomp_billing_address', $legacy_billing );
		update_post_meta( $post_id, '_sycomp_billing_addresses', $billing );
		update_post_meta( $post_id, '_sycomp_drop_shipping_addresses', $drop_shipping );
		update_post_meta( $post_id, '_sycomp_msp_shipping_addresses', $msp_shipping );

		if ( Sycomp_B2B_Markets::exists( $market ) ) {
			update_post_meta( $post_id, Sycomp_B2B_Post_Types::META_LOCATION_MARKET, $market );
		} else {
			delete_post_meta( $post_id, Sycomp_B2B_Post_Types::META_LOCATION_MARKET );
		}
	}

	/* ---------------------------------------------------------------------
	 * List columns.
	 * ------------------------------------------------------------------ */

	/**
	 * Location list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function location_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sycomp_company'] = __( 'Company', 'sycomp-b2b-portal' );
				$new['sycomp_market']  = __( 'Market', 'sycomp-b2b-portal' );
			}
		}
		return $new;
	}

	/**
	 * Location list column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function location_column_value( $column, $post_id ) {
		if ( 'sycomp_company' === $column ) {
			$company_id = Sycomp_B2B_Post_Types::get_location_company( $post_id );
			echo $company_id ? esc_html( get_the_title( $company_id ) ) : '—';
		}
		if ( 'sycomp_market' === $column ) {
			$market = Sycomp_B2B_Post_Types::get_location_market( $post_id );
			echo $market ? esc_html( Sycomp_B2B_Markets::label( $market ) ) : '—';
		}
	}

	/**
	 * Company list columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function company_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sycomp_locations'] = __( 'Locations', 'sycomp-b2b-portal' );
			}
		}
		return $new;
	}

	/**
	 * Company list column values.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function company_column_value( $column, $post_id ) {
		if ( 'sycomp_locations' === $column ) {
			echo esc_html( (string) count( Sycomp_B2B_Post_Types::get_company_locations( $post_id ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * User → company assignment.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the company selector on the user profile screen.
	 *
	 * @param WP_User $user User being edited.
	 */
	public static function user_company_field( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$current = Sycomp_B2B_User::get_company( $user->ID );
		?>
		<h2><?php esc_html_e( 'Sycomp B2B Portal', 'sycomp-b2b-portal' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="sycomp_user_company"><?php esc_html_e( 'Company', 'sycomp-b2b-portal' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'sycomp_user_company', 'sycomp_user_company_nonce' ); ?>
					<select name="sycomp_user_company" id="sycomp_user_company">
						<option value="0"><?php esc_html_e( '— Not a portal buyer —', 'sycomp-b2b-portal' ); ?></option>
						<?php foreach ( Sycomp_B2B_Post_Types::get_companies() as $company ) : ?>
							<option value="<?php echo esc_attr( $company->ID ); ?>" <?php selected( $current, $company->ID ); ?>>
								<?php echo esc_html( get_the_title( $company ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Assigning a company makes this user a portal buyer who can browse and order across that company\'s locations.', 'sycomp-b2b-portal' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the user→company assignment.
	 *
	 * @param int $user_id User ID.
	 */
	public static function save_user_company( $user_id ) {
		if ( ! isset( $_POST['sycomp_user_company_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_user_company_nonce'] ) ), 'sycomp_user_company' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$company_id = isset( $_POST['sycomp_user_company'] ) ? absint( wp_unslash( $_POST['sycomp_user_company'] ) ) : 0;
		Sycomp_B2B_User::set_company( $user_id, $company_id );
	}
}
