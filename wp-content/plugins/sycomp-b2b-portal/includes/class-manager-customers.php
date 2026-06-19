<?php
/**
 * Shop Manager — Customers section.
 *
 * Front-end management of customer companies: company records and logos,
 * the locations under each company, the buyer accounts assigned to them,
 * and which products each company may see in the catalogue. Rendered
 * inside the [sycomp_manager] dashboard as the "Customers" section, so a
 * Shop Manager can onboard a customer end-to-end without touching wp-admin.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Manager_Customers.
 */
class Sycomp_B2B_Manager_Customers {

	/**
	 * Shared nonce action for every form in this section.
	 */
	const NONCE = 'sycomp_customers';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ), 4 );
	}

	/* =====================================================================
	 * Helpers.
	 * ================================================================== */

	/**
	 * Whether the current user may use this section.
	 *
	 * @return bool
	 */
	protected static function is_manager() {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The Manage page URL.
	 *
	 * @return string
	 */
	protected static function manage_url() {
		return sycomp_b2b_page_url( 'manage' );
	}

	/**
	 * Redirect back into the Customers section with the given query args.
	 *
	 * @param array $args Query args (section=customers is added automatically).
	 */
	protected static function redirect( $args ) {
		$args = array_merge( array( 'section' => 'customers' ), $args );
		wp_safe_redirect( add_query_arg( $args, self::manage_url() ) );
		exit;
	}

	/**
	 * Verify the section nonce on a posted form.
	 *
	 * @return bool
	 */
	protected static function verify() {
		return isset( $_POST['sycomp_customer_nonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_customer_nonce'] ) ), self::NONCE );
	}

	/**
	 * Output the shared nonce field.
	 */
	protected static function nonce_field() {
		wp_nonce_field( self::NONCE, 'sycomp_customer_nonce' );
	}

	/**
	 * Load the wp-admin media helpers for logo uploads.
	 */
	protected static function ensure_media() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Fetch a valid, published company post.
	 *
	 * @param int $cid Company ID.
	 * @return WP_Post|null
	 */
	protected static function company( $cid ) {
		$post = get_post( (int) $cid );
		if ( $post && Sycomp_B2B_Post_Types::COMPANY === $post->post_type && 'publish' === $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * Buyer user objects assigned to a company.
	 *
	 * @param int $cid Company ID.
	 * @return WP_User[]
	 */
	protected static function company_buyers( $cid ) {
		return get_users(
			array(
				'meta_key'   => Sycomp_B2B_User::META_COMPANY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (int) $cid,                    // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'display_name',
			)
		);
	}

	/**
	 * Count products visible to a company.
	 *
	 * @param int $cid Company ID.
	 * @return int
	 */
	protected static function company_product_count( $cid ) {
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

	/* =====================================================================
	 * Write actions.
	 * ================================================================== */

	/**
	 * Dispatch a posted Customers action.
	 */
	public static function handle_actions() {
		if ( empty( $_POST['sycomp_customer_action'] ) || ! self::is_manager() || ! self::verify() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['sycomp_customer_action'] ) );

		switch ( $action ) {
			case 'company_save':
				self::do_company_save();
				break;
			case 'company_delete':
				self::do_company_delete();
				break;
			case 'location_save':
				self::do_location_save();
				break;
			case 'location_delete':
				self::do_location_delete();
				break;
			case 'buyer_create':
				self::do_buyer_create();
				break;
			case 'buyer_assign':
				self::do_buyer_assign();
				break;
			case 'buyer_remove':
				self::do_buyer_remove();
				break;
			case 'products_save':
				self::do_products_save();
				break;
		}
	}

	/**
	 * Create or update a company.
	 */
	protected static function do_company_save() {
		$cid  = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		$name = isset( $_POST['c_name'] ) ? sanitize_text_field( wp_unslash( $_POST['c_name'] ) ) : '';

		if ( '' === $name ) {
			self::redirect( array( 'cv' => ( $cid ? 'edit' : 'new' ), 'cid' => $cid, 'cdone' => 'error' ) );
		}

		if ( $cid && self::company( $cid ) ) {
			wp_update_post( array( 'ID' => $cid, 'post_title' => $name ) );
			$created = false;
		} else {
			$cid = wp_insert_post(
				array(
					'post_type'   => Sycomp_B2B_Post_Types::COMPANY,
					'post_title'  => $name,
					'post_status' => 'publish',
				)
			);
			$created = true;
		}

		if ( ! $cid || is_wp_error( $cid ) ) {
			self::redirect( array( 'cv' => 'new', 'cdone' => 'error' ) );
		}

		// Logo upload (optional).
		if ( ! empty( $_FILES['c_logo']['name'] ) ) {
			self::ensure_media();
			$att = media_handle_upload( 'c_logo', $cid );
			if ( ! is_wp_error( $att ) ) {
				update_post_meta( $cid, '_sycomp_logo_id', (int) $att );
			}
		}

		// Billing (bill-to) address removed from Company details.

		self::redirect(
			array(
				'cv'    => 'edit',
				'cid'   => $cid,
				'cdone' => $created ? 'company_created' : 'company_saved',
			)
		);
	}

	/**
	 * Delete a company — trashes its locations and unassigns its buyers.
	 */
	protected static function do_company_delete() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		if ( $cid && self::company( $cid ) ) {
			foreach ( Sycomp_B2B_Post_Types::get_company_locations( $cid ) as $loc ) {
				wp_trash_post( $loc->ID );
			}
			foreach ( self::company_buyers( $cid ) as $buyer ) {
				Sycomp_B2B_User::set_company( $buyer->ID, 0 );
			}
			wp_trash_post( $cid );
		}
		self::redirect( array( 'cdone' => 'company_deleted' ) );
	}

	/**
	 * Create or update a location under a company.
	 */
	protected static function do_location_save() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		$lid = isset( $_POST['lid'] ) ? absint( wp_unslash( $_POST['lid'] ) ) : 0;

		if ( ! $cid || ! self::company( $cid ) ) {
			self::redirect( array( 'cdone' => 'error' ) );
		}

		$name    = '';
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

		if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'lid' => $lid, 'cdone' => 'error' ) );
		}

		if ( $lid && Sycomp_B2B_Post_Types::LOCATION === get_post_type( $lid ) ) {
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

		self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'location_saved' ) );
	}

	/**
	 * Delete (trash) a location.
	 */
	protected static function do_location_delete() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		$lid = isset( $_POST['lid'] ) ? absint( wp_unslash( $_POST['lid'] ) ) : 0;
		if ( $lid && Sycomp_B2B_Post_Types::LOCATION === get_post_type( $lid ) ) {
			wp_trash_post( $lid );
		}
		self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'location_deleted' ) );
	}

	/**
	 * Create a new buyer user and assign it to a company.
	 */
	protected static function do_buyer_create() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		if ( ! $cid || ! self::company( $cid ) ) {
			self::redirect( array( 'cdone' => 'error' ) );
		}
		if ( ! current_user_can( 'create_users' ) ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'no_caps' ) );
		}

		$first = isset( $_POST['b_first'] ) ? sanitize_text_field( wp_unslash( $_POST['b_first'] ) ) : '';
		$last  = isset( $_POST['b_last'] ) ? sanitize_text_field( wp_unslash( $_POST['b_last'] ) ) : '';
		$email = isset( $_POST['b_email'] ) ? sanitize_email( wp_unslash( $_POST['b_email'] ) ) : '';
		$pass  = isset( $_POST['b_pass'] ) ? (string) wp_unslash( $_POST['b_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! is_email( $email ) || email_exists( $email ) ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'buyer_email' ) );
		}
		if ( strlen( $pass ) < 8 ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'buyer_pass' ) );
		}

		$uid = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $email,
				'role'         => Sycomp_B2B_Install::BUYER_ROLE,
			)
		);

		if ( is_wp_error( $uid ) ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'error' ) );
		}

		Sycomp_B2B_User::set_company( $uid, $cid );

		// Retrieve company details for the welcome email
		$company = self::company( $cid );
		$company_name = $company ? $company->post_title : '';
		
		$subject = sprintf( __( 'Welcome to Sycomp B2B Store - %s', 'sycomp-b2b-portal' ), $company_name );
		
		$body = '
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title>' . esc_html( $subject ) . '</title>
		</head>
		<body style="margin: 0; padding: 0; background-color: #f5f6f8; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
			<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f6f8; padding: 40px 20px;">
				<tr>
					<td align="center">
						<table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); border: 1px solid #e3e6eb;">
							<!-- Header -->
							<tr>
								<td style="background-color: #ffffff; border-bottom: 1px solid #f0f2f5; padding: 30px 40px; text-align: center;">
									<img src="cid:sycomp-logo" alt="Sycomp Logo" style="max-height: 50px; width: auto; display: block; margin: 0 auto;">
								</td>
							</tr>
							<!-- Body -->
							<tr>
								<td style="padding: 40px 40px 30px 40px; color: #0c1c3c;">
									<h1 style="font-size: 22px; font-weight: 700; margin-top: 0; margin-bottom: 20px; letter-spacing: -0.02em;">Welcome to the Sycomp B2B Store</h1>
									<p style="font-size: 15px; line-height: 1.6; color: #5b6472; margin-bottom: 24px;">
										Hello ' . esc_html( trim( $first . ' ' . $last ) ? trim( $first . ' ' . $last ) : $email ) . ',
									</p>
									<p style="font-size: 15px; line-height: 1.6; color: #5b6472; margin-bottom: 24px;">
										An administrator has set up a new buyer account for you representing <strong>' . esc_html( $company_name ) . '</strong>. Below are your login credentials to access the B2B store.
									</p>
									
									<!-- Credentials Box -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 30px; padding: 20px;">
										<tr>
											<td style="padding-bottom: 10px; font-size: 14px; color: #64748b; font-weight: 600;">Login URL:</td>
											<td style="padding-bottom: 10px; font-size: 14px; color: #0c1c3c; font-weight: 500;"><a href="' . esc_url( wp_login_url() ) . '" style="color: #1e4fd6; text-decoration: none;">' . esc_html( wp_login_url() ) . '</a></td>
										</tr>
										<tr>
											<td style="padding-bottom: 10px; font-size: 14px; color: #64748b; font-weight: 600;">Username / Email:</td>
											<td style="padding-bottom: 10px; font-size: 14px; color: #0c1c3c; font-weight: 500; font-family: monospace;">' . esc_html( $email ) . '</td>
										</tr>
										<tr>
											<td style="font-size: 14px; color: #64748b; font-weight: 600;">Temporary Password:</td>
											<td style="font-size: 14px; color: #0c1c3c; font-weight: 500; font-family: monospace;">' . esc_html( $pass ) . '</td>
										</tr>
									</table>
									
									<!-- Button -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 30px; text-align: center;">
										<tr>
											<td>
												<a href="' . esc_url( wp_login_url() ) . '" style="display: inline-block; background-color: #0c1c3c; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; padding: 12px 30px; border-radius: 5px; box-shadow: 0 2px 4px rgba(12, 28, 60, 0.1);">Log In to Your Account</a>
											</td>
										</tr>
									</table>

									<p style="font-size: 14px; line-height: 1.6; color: #7b8492; margin-bottom: 0;">
										<em>Note: For security reasons, you will be prompted to configure your Two-Factor Authenticator (TOTP) app upon your first login. You can change your password at any time from your profile page.</em>
									</p>
								</td>
							</tr>
							<!-- Footer -->
							<tr>
								<td style="background-color: #fafbfc; border-top: 1px solid #f0f2f5; padding: 24px 40px; text-align: center; font-size: 13px; color: #7b8492;">
									&copy; ' . esc_html( gmdate( 'Y' ) ) . ' Sycomp. All rights reserved.
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>';
		
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);
		
		// Setup inline attachment for the logo
		$logo_path = get_template_directory() . '/assets/img/sycomp-logo-color.png';
		$embed_callback = function( $phpmailer ) use ( $logo_path ) {
			if ( file_exists( $logo_path ) ) {
				$phpmailer->addEmbeddedImage( $logo_path, 'sycomp-logo', 'sycomp-logo.png' );
			}
		};
		add_action( 'phpmailer_init', $embed_callback );
		
		wp_mail( $email, $subject, $body, $headers );
		
		remove_action( 'phpmailer_init', $embed_callback );

		self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'buyer_created' ) );
	}

	/**
	 * Assign an existing user to a company.
	 */
	protected static function do_buyer_assign() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		$uid = isset( $_POST['b_user'] ) ? absint( wp_unslash( $_POST['b_user'] ) ) : 0;
		if ( ! $cid || ! self::company( $cid ) || ! $uid ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'error' ) );
		}
		$user = get_userdata( $uid );
		if ( ! $user || in_array( 'administrator', (array) $user->roles, true ) ) {
			self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'error' ) );
		}
		Sycomp_B2B_User::set_company( $uid, $cid );
		self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'buyer_assigned' ) );
	}

	/**
	 * Remove a buyer from a company (the user account is kept).
	 */
	protected static function do_buyer_remove() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		$uid = isset( $_POST['b_user'] ) ? absint( wp_unslash( $_POST['b_user'] ) ) : 0;
		if ( $uid && (int) Sycomp_B2B_User::get_company( $uid ) === $cid ) {
			Sycomp_B2B_User::set_company( $uid, 0 );
		}
		self::redirect( array( 'cv' => 'edit', 'cid' => $cid, 'cdone' => 'buyer_removed' ) );
	}

	/**
	 * Save the set of products a company may see.
	 */
	protected static function do_products_save() {
		$cid = isset( $_POST['cid'] ) ? absint( wp_unslash( $_POST['cid'] ) ) : 0;
		if ( ! $cid || ! self::company( $cid ) ) {
			self::redirect( array( 'cdone' => 'error' ) );
		}

		$enabled = isset( $_POST['sycomp_products'] ) && is_array( $_POST['sycomp_products'] )
			? array_map( 'absint', wp_unslash( $_POST['sycomp_products'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$enabled = array_flip( $enabled );

		$products = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		foreach ( $products as $pid ) {
			$current = array_map( 'intval', (array) get_post_meta( $pid, '_sycomp_company', false ) );
			$has     = in_array( $cid, $current, true );
			$want    = isset( $enabled[ $pid ] );

			if ( $want && ! $has ) {
				add_post_meta( $pid, '_sycomp_company', $cid );
			} elseif ( ! $want && $has ) {
				delete_post_meta( $pid, '_sycomp_company', $cid );
			}
		}

		self::redirect( array( 'cv' => 'products', 'cid' => $cid, 'cdone' => 'products_saved' ) );
	}

	/* =====================================================================
	 * Rendering.
	 * ================================================================== */

	/**
	 * Render the active Customers view (called by the manager dashboard).
	 */
	public static function render() {
		if ( ! self::is_manager() ) {
			echo '<div class="sy-notice sy-notice--warn">'
				. esc_html__( 'This area is for Sycomp staff only.', 'sycomp-b2b-portal' )
				. '</div>';
			return;
		}

		$cv  = isset( $_GET['cv'] ) ? sanitize_key( wp_unslash( $_GET['cv'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		$cid = isset( $_GET['cid'] ) ? absint( wp_unslash( $_GET['cid'] ) ) : 0;            // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'new' === $cv ) {
			self::render_company_form( 0 );
		} elseif ( 'edit' === $cv && $cid ) {
			self::render_company_workspace( $cid );
		} elseif ( 'products' === $cv && $cid ) {
			self::render_products_screen( $cid );
		} else {
			self::render_company_list();
		}
	}

	/**
	 * Page heading row.
	 *
	 * @param string $title   Title.
	 * @param string $lede    Sub-text.
	 * @param string $actions Pre-escaped action HTML.
	 */
	protected static function head( $title, $lede = '', $actions = '' ) {
		echo '<div class="sy-adm-head"><div class="sy-adm-head__text">';
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
	 * Render the result notice after an action.
	 */
	protected static function notice() {
		$done = isset( $_GET['cdone'] ) ? sanitize_key( wp_unslash( $_GET['cdone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $done ) {
			return;
		}
		$ok = array(
			'company_created'  => __( 'Company created. Now add its locations, buyers and product access below.', 'sycomp-b2b-portal' ),
			'company_saved'    => __( 'Company details saved.', 'sycomp-b2b-portal' ),
			'company_deleted'  => __( 'Company deleted.', 'sycomp-b2b-portal' ),
			'location_saved'   => __( 'Location saved.', 'sycomp-b2b-portal' ),
			'location_deleted' => __( 'Location deleted.', 'sycomp-b2b-portal' ),
			'buyer_created'    => __( 'Buyer account created and assigned to this company.', 'sycomp-b2b-portal' ),
			'buyer_assigned'   => __( 'Buyer assigned to this company.', 'sycomp-b2b-portal' ),
			'buyer_removed'    => __( 'Buyer removed from this company.', 'sycomp-b2b-portal' ),
			'products_saved'   => __( 'Product access updated for this company.', 'sycomp-b2b-portal' ),
		);
		$warn = array(
			'error'      => __( 'Something went wrong — please check the form and try again.', 'sycomp-b2b-portal' ),
			'buyer_email' => __( 'That email address is not valid or is already in use.', 'sycomp-b2b-portal' ),
			'buyer_pass' => __( 'Choose a password of at least 8 characters.', 'sycomp-b2b-portal' ),
			'no_caps'    => __( 'Your account is not permitted to create users — ask an administrator.', 'sycomp-b2b-portal' ),
		);
		if ( isset( $ok[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--success">' . esc_html( $ok[ $done ] ) . '</div>';
		} elseif ( isset( $warn[ $done ] ) ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html( $warn[ $done ] ) . '</div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * View: company list.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the company list table.
	 */
	protected static function render_company_list() {
		$companies = Sycomp_B2B_Post_Types::get_companies();

		$add = '<a class="sy-btn sy-btn--accent sy-btn--sm" href="'
			. esc_url( add_query_arg( array( 'section' => 'customers', 'cv' => 'new' ), self::manage_url() ) )
			. '">' . esc_html__( 'Add company', 'sycomp-b2b-portal' ) . '</a>';

		self::head(
			__( 'Customers', 'sycomp-b2b-portal' ),
			__( 'Customer companies, their locations, buyers and catalogue access.', 'sycomp-b2b-portal' ),
			$add
		);
		self::notice();

		echo '<section class="sy-panel"><div class="sy-panel__body sy-panel__body--flush">';
		if ( empty( $companies ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No customer companies yet. Use “Add company” to create the first one.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin sy-table--stack"><thead><tr>';
			echo '<th>' . esc_html__( 'Company', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Locations', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Buyers', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Products', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $companies as $company ) {
				$cid       = (int) $company->ID;
				$edit_url  = add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $cid ), self::manage_url() );
				$locations = count( Sycomp_B2B_Post_Types::get_company_locations( $cid ) );
				$buyers    = count( self::company_buyers( $cid ) );
				$products  = self::company_product_count( $cid );

				echo '<tr>';
				echo '<td><a class="sy-link" href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( get_the_title( $company ) ) . '</strong></a></td>';
				echo '<td><span class="sy-chip">' . esc_html( number_format_i18n( $locations ) ) . '</span></td>';
				echo '<td><span class="sy-chip">' . esc_html( number_format_i18n( $buyers ) ) . '</span></td>';
				echo '<td><span class="sy-chip">' . esc_html( number_format_i18n( $products ) ) . '</span></td>';
				echo '<td class="sy-col-act"><div class="sy-rowact">';
				echo '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Manage', 'sycomp-b2b-portal' ) . '</a>';
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Delete this company? Its locations are removed and its buyers are unassigned.', 'sycomp-b2b-portal' ) ) . '\');">';
				self::nonce_field();
				echo '<input type="hidden" name="sycomp_customer_action" value="company_delete">';
				echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
				echo '<button class="sy-iconbtn sy-iconbtn--danger" type="submit" title="' . esc_attr__( 'Delete', 'sycomp-b2b-portal' ) . '">';
				echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h12l1-14"/></svg></button>';
				echo '</form>';
				echo '</div></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';
	}

	/* ---------------------------------------------------------------------
	 * View: new-company form.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the standalone "create company" form.
	 *
	 * @param int $cid Unused (always 0 — kept for signature clarity).
	 */
	protected static function render_company_form( $cid = 0 ) {
		$back = add_query_arg( 'section', 'customers', self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to customers', 'sycomp-b2b-portal' ) . '</a></p>';
		self::head( __( 'Add a customer company', 'sycomp-b2b-portal' ), __( 'Create the company, then add its locations, buyers and product access.', 'sycomp-b2b-portal' ) );
		self::notice();

		echo '<section class="sy-panel" style="max-width:560px;"><div class="sy-panel__body">';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		self::nonce_field();
		echo '<input type="hidden" name="sycomp_customer_action" value="company_save">';
		echo '<input type="hidden" name="cid" value="0">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Company name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="c_name" required></label>';
		echo '<label class="sy-field sy-field--file"><span class="sy-field__label">' . esc_html__( 'Company logo (optional)', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="file" name="c_logo" accept="image/*"></label>';
		echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Create company', 'sycomp-b2b-portal' ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a></div>';
		echo '</form></div></section>';
	}

	/* ---------------------------------------------------------------------
	 * View: company workspace.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the full company workspace: details, locations, buyers, access.
	 *
	 * @param int $cid Company ID.
	 */
	protected static function render_company_workspace( $cid ) {
		$company = self::company( $cid );
		if ( ! $company ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html__( 'That company could not be found.', 'sycomp-b2b-portal' ) . '</div>';
			self::render_company_list();
			return;
		}

		$back = add_query_arg( 'section', 'customers', self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to customers', 'sycomp-b2b-portal' ) . '</a></p>';
		self::head(
			/* translators: %s: company name. */
			sprintf( __( 'Customer: %s', 'sycomp-b2b-portal' ), get_the_title( $company ) ),
			__( 'Company details, locations, buyers and catalogue access.', 'sycomp-b2b-portal' )
		);
		self::notice();

		self::panel_company_details( $company );
		self::panel_locations( $cid );
		self::panel_buyers( $cid );
		self::panel_product_access( $cid );
	}

	/**
	 * Panel: company name + logo.
	 *
	 * @param WP_Post $company Company post.
	 */
	protected static function panel_company_details( $company ) {
		$cid     = (int) $company->ID;
		$logo_id = (int) get_post_meta( $cid, '_sycomp_logo_id', true );

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Company details', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<form method="post" enctype="multipart/form-data" class="sy-form">';
		self::nonce_field();
		echo '<input type="hidden" name="sycomp_customer_action" value="company_save">';
		echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Company name', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="c_name" required value="' . esc_attr( get_the_title( $company ) ) . '"></label>';
		echo '<label class="sy-field sy-field--file"><span class="sy-field__label">' . esc_html__( 'Company logo', 'sycomp-b2b-portal' ) . '</span>';
		if ( $logo_id ) {
			$img = wp_get_attachment_image( $logo_id, array( 70, 70 ) );
			if ( $img ) {
				echo '<span class="sy-form__thumb">' . $img . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '<input type="file" name="c_logo" accept="image/*"></label>';
		echo '</div>';

		// Billing address moved to locations form.

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save details', 'sycomp-b2b-portal' ) . '</button>';
		echo '</div>';
		echo '</form>';

		echo '<form method="post" style="margin-top:14px;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this company? Its locations are removed and its buyers are unassigned.', 'sycomp-b2b-portal' ) ) . '\');">';
		self::nonce_field();
		echo '<input type="hidden" name="sycomp_customer_action" value="company_delete">';
		echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
		echo '<button class="sy-btn sy-btn--ghost sy-btn--sm" type="submit">' . esc_html__( 'Delete company', 'sycomp-b2b-portal' ) . '</button>';
		echo '</form>';
		echo '</div></section>';
	}

	/**
	 * Panel: the company's locations, with an add / edit form.
	 *
	 * @param int $cid Company ID.
	 */
	protected static function panel_locations( $cid ) {
		$edit_lid = isset( $_GET['lid'] ) ? absint( wp_unslash( $_GET['lid'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$edit_loc = null;
		if ( $edit_lid && Sycomp_B2B_Post_Types::LOCATION === get_post_type( $edit_lid )
			&& Sycomp_B2B_Post_Types::get_location_company( $edit_lid ) === $cid ) {
			$edit_loc = get_post( $edit_lid );
		}

		$cur_market  = $edit_loc ? Sycomp_B2B_Post_Types::get_location_market( $edit_loc->ID ) : '';
		$cur_code    = $edit_loc ? (string) get_post_meta( $edit_loc->ID, Sycomp_B2B_Post_Types::META_LOCATION_CODE, true ) : '';
		$cur_billing = $edit_loc ? Sycomp_B2B_Post_Types::get_location_billing_addresses( $edit_loc->ID ) : array();
		$cur_drop    = $edit_loc ? Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $edit_loc->ID ) : array();
		$cur_msp     = $edit_loc ? Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $edit_loc->ID ) : array();
		$locations   = Sycomp_B2B_Post_Types::get_company_locations( $cid );

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Locations', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p class="sy-muted">' . esc_html__( 'Each location is tied to one market — that market sets the currency and price list its buyers see.', 'sycomp-b2b-portal' ) . '</p>';

		echo '<form method="post" class="sy-form">';
		self::nonce_field();
		echo '<input type="hidden" name="sycomp_customer_action" value="location_save">';
		echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
		echo '<input type="hidden" name="lid" value="' . esc_attr( $edit_loc ? $edit_loc->ID : 0 ) . '">';
		echo '<style>';
		echo '
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
		';
		echo '</style>';

		echo '<div class="sy-form__grid">';
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Currency', 'sycomp-b2b-portal' ) . '</span><select name="l_market" required>';
		echo '<option value="">' . esc_html__( '— Select currency —', 'sycomp-b2b-portal' ) . '</option>';
		foreach ( Sycomp_B2B_Markets::all() as $key => $market ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $cur_market, $key, false ) . '>' . esc_html( $market['label'] . ' (' . $market['currency'] . ')' ) . '</option>';
		}
		echo '</select></label>';
		
		echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Location code', 'sycomp-b2b-portal' ) . '</span>';
		echo '<input type="text" name="l_code" value="' . esc_attr( $cur_code ) . '" placeholder="' . esc_attr__( 'optional', 'sycomp-b2b-portal' ) . '"></label>';

		// Row 2: Bill to (spans full width)
		echo '<div class="sy-field" style="grid-column: span 2;">';
		echo '<span class="sy-field__label">' . esc_html__( 'Bill to', 'sycomp-b2b-portal' ) . '</span>';
		echo '<div style="display: flex; gap: 8px; align-items: stretch;">';
		echo '<textarea id="l_billing_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="' . esc_attr__( 'Type address and press enter...', 'sycomp-b2b-portal' ) . '"></textarea>';
		echo '<button type="button" id="l_billing_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="' . esc_attr__( 'Add address', 'sycomp-b2b-portal' ) . '">↵</button>';
		echo '</div>';
		echo '<div id="l_billing_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">';
		foreach ( $cur_billing as $addr ) {
			if ( ! empty( trim( $addr ) ) ) {
				echo '<div class="sy-address-pill" onclick="editAddress(this, \'l_billing_input\')">';
				echo '<span class="sy-address-pill-text">' . esc_html( $addr ) . '</span>';
				echo '<input type="hidden" name="l_billing[]" value="' . esc_attr( $addr ) . '">';
				echo '<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>';
				echo '</div>';
			}
		}
		echo '</div>';
		echo '</div>';

		// Row 3: Drop Shipping Addresses & MSP Shipping Addresses (50/50 split)
		// Drop Shipping Addresses Group
		echo '<div class="sy-field">';
		echo '<span class="sy-field__label">' . esc_html__( 'Drop Shipping Addresses', 'sycomp-b2b-portal' ) . '</span>';
		echo '<div style="display: flex; gap: 8px; align-items: stretch;">';
		echo '<textarea id="l_drop_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="' . esc_attr__( 'Type address and press enter...', 'sycomp-b2b-portal' ) . '"></textarea>';
		echo '<button type="button" id="l_drop_shipping_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="' . esc_attr__( 'Add address', 'sycomp-b2b-portal' ) . '">↵</button>';
		echo '</div>';
		echo '<div id="l_drop_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">';
		foreach ( $cur_drop as $addr ) {
			if ( ! empty( trim( $addr ) ) ) {
				echo '<div class="sy-address-pill" onclick="editAddress(this, \'l_drop_shipping_input\')">';
				echo '<span class="sy-address-pill-text">' . esc_html( $addr ) . '</span>';
				echo '<input type="hidden" name="l_drop_shipping[]" value="' . esc_attr( $addr ) . '">';
				echo '<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>';
				echo '</div>';
			}
		}
		echo '</div>';
		echo '</div>';

		// MSP Shipping Addresses Group
		echo '<div class="sy-field">';
		echo '<span class="sy-field__label">' . esc_html__( 'MSP Shipping Addresses', 'sycomp-b2b-portal' ) . '</span>';
		echo '<div style="display: flex; gap: 8px; align-items: stretch;">';
		echo '<textarea id="l_msp_shipping_input" rows="1" style="flex-grow: 1; resize: none; min-height: 38px; padding: 8px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius);" placeholder="' . esc_attr__( 'Type address and press enter...', 'sycomp-b2b-portal' ) . '"></textarea>';
		echo '<button type="button" id="l_msp_shipping_add_btn" class="sy-btn sy-btn--ghost" style="padding: 0 16px; display: flex; align-items: center; justify-content: center; font-size: 18px; border: 1px solid var(--sy-border); border-radius: var(--sy-radius); line-height: 1;" title="' . esc_attr__( 'Add address', 'sycomp-b2b-portal' ) . '">↵</button>';
		echo '</div>';
		echo '<div id="l_msp_shipping_list" style="margin-top: 8px; display: flex; flex-direction: column; gap: 6px;">';
		foreach ( $cur_msp as $addr ) {
			if ( ! empty( trim( $addr ) ) ) {
				echo '<div class="sy-address-pill" onclick="editAddress(this, \'l_msp_shipping_input\')">';
				echo '<span class="sy-address-pill-text">' . esc_html( $addr ) . '</span>';
				echo '<input type="hidden" name="l_msp_shipping[]" value="' . esc_attr( $addr ) . '">';
				echo '<span class="sy-address-pill-remove" onclick="event.stopPropagation(); this.parentElement.remove();">&times;</span>';
				echo '</div>';
			}
		}
		echo '</div>';
		echo '</div>';

		echo '</div>';

		// Dynamic JS handlers for adding/removing/editing addresses
		?>
		<script type="text/javascript">
		function toggleLocationDetails(locId, btn) {
			var row = document.getElementById('loc-details-' + locId);
			if (row) {
				if (row.style.display === 'none') {
					row.style.display = 'table-row';
					btn.style.color = 'var(--sy-accent, #1e4fd6)';
				} else {
					row.style.display = 'none';
					btn.style.color = '';
				}
			}
		}

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

				// Create the pill
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
					e.preventDefault(); // Prevent new line or form submit
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
		<?php

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent sy-btn--sm" type="submit">' . esc_html( $edit_loc ? __( 'Update location', 'sycomp-b2b-portal' ) : __( 'Add location', 'sycomp-b2b-portal' ) ) . '</button>';
		if ( $edit_loc ) {
			echo '<a class="sy-btn sy-btn--ghost sy-btn--sm" href="' . esc_url( add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $cid ), self::manage_url() ) ) . '">' . esc_html__( 'Cancel edit', 'sycomp-b2b-portal' ) . '</a>';
		}
		echo '</div>';
		echo '</form>';

		if ( empty( $locations ) ) {
			echo '<p class="sy-muted" style="margin-top:12px;">' . esc_html__( 'No locations yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin sy-table--stack" style="margin-top:14px;"><thead><tr>';
			echo '<th>' . esc_html__( 'Location', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Currency', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Code', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $locations as $loc ) {
				$mk      = Sycomp_B2B_Post_Types::get_location_market( $loc->ID );
				$lcode   = (string) get_post_meta( $loc->ID, Sycomp_B2B_Post_Types::META_LOCATION_CODE, true );
				$ed_url  = add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $cid, 'lid' => $loc->ID ), self::manage_url() );
				echo '<tr>';
				echo '<td><strong>' . esc_html( get_the_title( $loc ) ) . '</strong></td>';
				echo '<td>' . ( $mk ? esc_html( Sycomp_B2B_Markets::label( $mk ) ) : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
				echo '<td>' . ( '' !== $lcode ? esc_html( $lcode ) : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
				$billing    = Sycomp_B2B_Post_Types::get_location_billing_address( $loc->ID );
				$drop_addrs = Sycomp_B2B_Post_Types::get_location_drop_shipping_addresses( $loc->ID );
				$msp_addrs  = Sycomp_B2B_Post_Types::get_location_msp_shipping_addresses( $loc->ID );

				echo '<td class="sy-col-act"><div class="sy-rowact">';
				echo '<button type="button" class="sy-iconbtn sy-view-loc-btn" onclick="toggleLocationDetails(' . esc_attr( $loc->ID ) . ', this);" title="' . esc_attr__( 'View Saved Fields', 'sycomp-b2b-portal' ) . '" style="background:none; border:none; padding:4px; cursor:pointer; display:inline-flex; align-items:center;">';
				echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px; height:16px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
				echo '</button>';
				echo '<a class="sy-iconbtn" href="' . esc_url( $ed_url ) . '" title="' . esc_attr__( 'Edit', 'sycomp-b2b-portal' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>';
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Delete this location?', 'sycomp-b2b-portal' ) ) . '\');">';
				self::nonce_field();
				echo '<input type="hidden" name="sycomp_customer_action" value="location_delete">';
				echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
				echo '<input type="hidden" name="lid" value="' . esc_attr( $loc->ID ) . '">';
				echo '<button class="sy-iconbtn sy-iconbtn--danger" type="submit" title="' . esc_attr__( 'Delete', 'sycomp-b2b-portal' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h12l1-14"/></svg></button>';
				echo '</form>';
				echo '</div></td></tr>';

				// Details Row
				echo '<tr id="loc-details-' . esc_attr( $loc->ID ) . '" class="sy-loc-details-row" style="display:none; background:#fafafa;">';
				echo '<td colspan="4" style="padding: 12px 16px; border-top: 1px solid #e5e7eb;">';
				echo '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; text-align: left;">';
				
				// Billing
				echo '<div>';
				echo '<span style="font-size: 11px; text-transform: uppercase; font-weight: bold; color: #6b7280; display: block; margin-bottom: 4px;">' . esc_html__( 'Bill to', 'sycomp-b2b-portal' ) . '</span>';
				echo '<div style="font-size: 13px; color: #374151; white-space: pre-wrap; line-height: 1.4;">' . ( ! empty( $billing ) ? esc_html( $billing ) : '<span style="color:#9ca3af;">—</span>' ) . '</div>';
				echo '</div>';

				// Drop Shipping
				echo '<div>';
				echo '<span style="font-size: 11px; text-transform: uppercase; font-weight: bold; color: #6b7280; display: block; margin-bottom: 4px;">' . esc_html__( 'Drop Shipping Addresses', 'sycomp-b2b-portal' ) . '</span>';
				if ( ! empty( $drop_addrs ) ) {
					echo '<ol style="margin: 0; padding-left: 16px; font-size: 13px; color: #374151; line-height: 1.4;">';
					foreach ( $drop_addrs as $addr ) {
						echo '<li style="margin-bottom: 4px;">' . esc_html( $addr ) . '</li>';
					}
					echo '</ol>';
				} else {
					echo '<span style="font-size: 13px; color:#9ca3af;">—</span>';
				}
				echo '</div>';

				// MSP Shipping
				echo '<div>';
				echo '<span style="font-size: 11px; text-transform: uppercase; font-weight: bold; color: #6b7280; display: block; margin-bottom: 4px;">' . esc_html__( 'MSP Shipping Addresses', 'sycomp-b2b-portal' ) . '</span>';
				if ( ! empty( $msp_addrs ) ) {
					echo '<ol style="margin: 0; padding-left: 16px; font-size: 13px; color: #374151; line-height: 1.4;">';
					foreach ( $msp_addrs as $addr ) {
						echo '<li style="margin-bottom: 4px;">' . esc_html( $addr ) . '</li>';
					}
					echo '</ol>';
				} else {
					echo '<span style="font-size: 13px; color:#9ca3af;">—</span>';
				}
				echo '</div>';

				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';
	}

	/**
	 * Panel: the company's buyer accounts.
	 *
	 * @param int $cid Company ID.
	 */
	protected static function panel_buyers( $cid ) {
		$buyers = self::company_buyers( $cid );

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Buyers', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';

		if ( empty( $buyers ) ) {
			echo '<p class="sy-muted">' . esc_html__( 'No buyers assigned to this company yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin sy-table--stack"><thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Email', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th class="sy-col-act">' . esc_html__( 'Actions', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $buyers as $buyer ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $buyer->display_name ) . '</strong></td>';
				echo '<td>' . esc_html( $buyer->user_email ) . '</td>';
				echo '<td class="sy-col-act"><div class="sy-rowact">';
				echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Remove this buyer from the company? Their account is kept.', 'sycomp-b2b-portal' ) ) . '\');">';
				self::nonce_field();
				echo '<input type="hidden" name="sycomp_customer_action" value="buyer_remove">';
				echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
				echo '<input type="hidden" name="b_user" value="' . esc_attr( $buyer->ID ) . '">';
				echo '<button class="sy-btn sy-btn--ghost sy-btn--sm" type="submit">' . esc_html__( 'Remove', 'sycomp-b2b-portal' ) . '</button>';
				echo '</form>';
				echo '</div></td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<div class="sy-adm-split" style="margin-top:16px;">';

		// Create a new buyer.
		echo '<div>';
		echo '<h3 style="font-size:.95rem;margin:0 0 8px;">' . esc_html__( 'Add a new buyer', 'sycomp-b2b-portal' ) . '</h3>';
		if ( current_user_can( 'create_users' ) ) {
			echo '<form method="post" class="sy-form">';
			self::nonce_field();
			echo '<input type="hidden" name="sycomp_customer_action" value="buyer_create">';
			echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
			echo '<div class="sy-form__grid">';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'First name', 'sycomp-b2b-portal' ) . '</span><input type="text" name="b_first"></label>';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Last name', 'sycomp-b2b-portal' ) . '</span><input type="text" name="b_last"></label>';
			echo '</div>';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Email address', 'sycomp-b2b-portal' ) . '</span><input type="email" name="b_email" required></label>';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Temporary password', 'sycomp-b2b-portal' ) . '</span><input type="text" name="b_pass" required minlength="8" placeholder="' . esc_attr__( 'At least 8 characters', 'sycomp-b2b-portal' ) . '"></label>';
			echo '<p class="sy-muted" style="font-size:.8rem;">' . esc_html__( 'The buyer signs in with their email and this password, and can change it later under My Profile.', 'sycomp-b2b-portal' ) . '</p>';
			echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--accent sy-btn--sm" type="submit">' . esc_html__( 'Create buyer', 'sycomp-b2b-portal' ) . '</button></div>';
			echo '</form>';
		} else {
			echo '<p class="sy-muted">' . esc_html__( 'Your account cannot create users. Ask an administrator, or assign an existing user.', 'sycomp-b2b-portal' ) . '</p>';
		}
		echo '</div>';

		// Assign an existing buyer.
		echo '<div>';
		echo '<h3 style="font-size:.95rem;margin:0 0 8px;">' . esc_html__( 'Assign an existing buyer', 'sycomp-b2b-portal' ) . '</h3>';
		$candidates = get_users( array( 'role' => Sycomp_B2B_Install::BUYER_ROLE, 'orderby' => 'display_name' ) );
		$available  = array();
		foreach ( $candidates as $user ) {
			if ( (int) Sycomp_B2B_User::get_company( $user->ID ) !== $cid ) {
				$available[] = $user;
			}
		}
		if ( empty( $available ) ) {
			echo '<p class="sy-muted">' . esc_html__( 'No other buyer accounts are available to assign.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<form method="post" class="sy-form">';
			self::nonce_field();
			echo '<input type="hidden" name="sycomp_customer_action" value="buyer_assign">';
			echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';
			echo '<label class="sy-field"><span class="sy-field__label">' . esc_html__( 'Buyer', 'sycomp-b2b-portal' ) . '</span><select name="b_user" required>';
			echo '<option value="">' . esc_html__( '— Select a buyer —', 'sycomp-b2b-portal' ) . '</option>';
			foreach ( $available as $user ) {
				$current = (int) Sycomp_B2B_User::get_company( $user->ID );
				$suffix  = $current ? ' — ' . get_the_title( $current ) : ' — ' . __( 'unassigned', 'sycomp-b2b-portal' );
				echo '<option value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name . ' (' . $user->user_email . ')' . $suffix ) . '</option>';
			}
			echo '</select></label>';
			echo '<p class="sy-muted" style="font-size:.8rem;">' . esc_html__( 'A buyer belongs to one company at a time — assigning moves them here.', 'sycomp-b2b-portal' ) . '</p>';
			echo '<div class="sy-form__actions"><button class="sy-btn sy-btn--primary sy-btn--sm" type="submit">' . esc_html__( 'Assign buyer', 'sycomp-b2b-portal' ) . '</button></div>';
			echo '</form>';
		}
		echo '</div>';

		echo '</div>';
		echo '</div></section>';
	}

	/**
	 * Panel: a summary of catalogue access with a link to the editor.
	 *
	 * @param int $cid Company ID.
	 */
	protected static function panel_product_access( $cid ) {
		$enabled = self::company_product_count( $cid );
		$total   = (int) wp_count_posts( 'product' )->publish;
		$url     = add_query_arg( array( 'section' => 'customers', 'cv' => 'products', 'cid' => $cid ), self::manage_url() );

		echo '<section class="sy-panel"><h2 class="sy-panel__title">' . esc_html__( 'Catalogue access', 'sycomp-b2b-portal' ) . '</h2><div class="sy-panel__body">';
		echo '<p>';
		printf(
			/* translators: 1: enabled count, 2: total count. */
			esc_html__( 'This company can see %1$s of %2$s products in the catalogue.', 'sycomp-b2b-portal' ),
			'<strong>' . esc_html( number_format_i18n( $enabled ) ) . '</strong>',
			esc_html( number_format_i18n( $total ) )
		);
		echo '</p>';
		echo '<p class="sy-muted">' . esc_html__( 'Choose exactly which products this company’s buyers may browse and order.', 'sycomp-b2b-portal' ) . '</p>';
		echo '<a class="sy-btn sy-btn--primary sy-btn--sm" href="' . esc_url( $url ) . '">' . esc_html__( 'Manage product access', 'sycomp-b2b-portal' ) . '</a>';
		echo '</div></section>';
	}

	/* ---------------------------------------------------------------------
	 * View: product access editor.
	 * ------------------------------------------------------------------ */

	/**
	 * Render the per-company product visibility editor.
	 *
	 * @param int $cid Company ID.
	 */
	protected static function render_products_screen( $cid ) {
		$company = self::company( $cid );
		if ( ! $company ) {
			echo '<div class="sy-notice sy-notice--warn">' . esc_html__( 'That company could not be found.', 'sycomp-b2b-portal' ) . '</div>';
			self::render_company_list();
			return;
		}

		$back = add_query_arg( array( 'section' => 'customers', 'cv' => 'edit', 'cid' => $cid ), self::manage_url() );
		echo '<p class="sy-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to company', 'sycomp-b2b-portal' ) . '</a></p>';
		self::head(
			/* translators: %s: company name. */
			sprintf( __( 'Product access — %s', 'sycomp-b2b-portal' ), get_the_title( $company ) ),
			__( 'Tick the products this company’s buyers may see in the catalogue.', 'sycomp-b2b-portal' )
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
		self::nonce_field();
		echo '<input type="hidden" name="sycomp_customer_action" value="products_save">';
		echo '<input type="hidden" name="cid" value="' . esc_attr( $cid ) . '">';

		echo '<div class="sy-adm-filters">';
		echo '<input type="search" id="sy-prodfilter" class="sy-adm-filters__search" placeholder="' . esc_attr__( 'Filter products…', 'sycomp-b2b-portal' ) . '">';
		echo '<button type="button" class="sy-btn sy-btn--ghost sy-btn--sm" id="sy-prodall">' . esc_html__( 'Enable all', 'sycomp-b2b-portal' ) . '</button>';
		echo '<button type="button" class="sy-btn sy-btn--ghost sy-btn--sm" id="sy-prodnone">' . esc_html__( 'Disable all', 'sycomp-b2b-portal' ) . '</button>';
		echo '<span class="sy-muted" id="sy-prodcount" style="margin-left:auto;"></span>';
		echo '</div>';

		echo '<section class="sy-panel"><div class="sy-panel__body sy-panel__body--flush">';
		if ( empty( $products ) ) {
			echo '<p class="sy-empty">' . esc_html__( 'No products exist yet.', 'sycomp-b2b-portal' ) . '</p>';
		} else {
			echo '<table class="sy-table sy-table--admin" id="sy-prodgrid"><thead><tr>';
			echo '<th>' . esc_html__( 'Enabled', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Product', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Category', 'sycomp-b2b-portal' ) . '</th>';
			echo '<th>' . esc_html__( 'Brand', 'sycomp-b2b-portal' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $products as $product ) {
				$pid     = (int) $product->ID;
				$current = array_map( 'intval', (array) get_post_meta( $pid, '_sycomp_company', false ) );
				$checked = in_array( $cid, $current, true );
				$wc      = wc_get_product( $pid );
				$sku     = ( $wc && $wc->get_sku() ) ? $wc->get_sku() : '';
				$name    = get_the_title( $product );

				$cats   = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
				$cat    = ( ! is_wp_error( $cats ) && ! empty( $cats ) ) ? $cats[0] : '';
				$brands = wp_get_post_terms( $pid, Sycomp_B2B_Post_Types::TAX_BRAND, array( 'fields' => 'names' ) );
				$brand  = ( ! is_wp_error( $brands ) && ! empty( $brands ) ) ? $brands[0] : '';

				$thumb = get_the_post_thumbnail( $pid, array( 40, 40 ) );
				$label = strtolower( $name . ' ' . $sku . ' ' . $brand . ' ' . $cat );

				echo '<tr class="sy-prodrow" data-label="' . esc_attr( $label ) . '">';
				echo '<td><input type="checkbox" class="sy-prodcb" name="sycomp_products[]" value="' . esc_attr( $pid ) . '" ' . checked( $checked, true, false ) . '></td>';
				echo '<td><div class="sy-prow">';
				echo '<span class="sy-prow__thumb">' . ( $thumb ? $thumb : '<span class="sy-prow__ph">' . sycomp_b2b_placeholder_icon() . '</span>' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<span class="sy-prow__meta"><span class="sy-prow__name">' . esc_html( $name ) . '</span>';
				if ( $sku ) {
					echo '<span class="sy-prow__sku">' . esc_html( $sku ) . '</span>';
				}
				echo '</span></div></td>';
				echo '<td>' . ( $cat ? '<span class="sy-chip">' . esc_html( $cat ) . '</span>' : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
				echo '<td>' . ( $brand ? esc_html( $brand ) : '<span class="sy-muted">&mdash;</span>' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div></section>';

		echo '<div class="sy-form__actions">';
		echo '<button class="sy-btn sy-btn--accent" type="submit">' . esc_html__( 'Save product access', 'sycomp-b2b-portal' ) . '</button>';
		echo '<a class="sy-btn sy-btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'sycomp-b2b-portal' ) . '</a>';
		echo '</div>';
		echo '</form>';
		?>
		<script>
		( function () {
			var filter = document.getElementById( 'sy-prodfilter' );
			var grid   = document.getElementById( 'sy-prodgrid' );
			var count  = document.getElementById( 'sy-prodcount' );
			if ( ! grid ) { return; }
			var rows = Array.prototype.slice.call( grid.querySelectorAll( '.sy-prodrow' ) );
			var boxes = Array.prototype.slice.call( grid.querySelectorAll( '.sy-prodcb' ) );

			function refresh() {
				var on = boxes.filter( function ( b ) { return b.checked; } ).length;
				count.textContent = on + ' / ' + boxes.length + ' selected';
			}
			grid.addEventListener( 'change', refresh );
			refresh();

			if ( filter ) {
				filter.addEventListener( 'input', function () {
					var q = filter.value.trim().toLowerCase();
					rows.forEach( function ( r ) {
						r.style.display = ( ! q || r.getAttribute( 'data-label' ).indexOf( q ) !== -1 ) ? '' : 'none';
					} );
				} );
			}
			function setVisible( state ) {
				rows.forEach( function ( r ) {
					if ( r.style.display === 'none' ) { return; }
					var cb = r.querySelector( '.sy-prodcb' );
					if ( cb ) { cb.checked = state; }
				} );
				refresh();
			}
			var all = document.getElementById( 'sy-prodall' );
			var none = document.getElementById( 'sy-prodnone' );
			if ( all ) { all.addEventListener( 'click', function () { setVisible( true ); } ); }
			if ( none ) { none.addEventListener( 'click', function () { setVisible( false ); } ); }
		} )();
		</script>
		<?php
	}
}
