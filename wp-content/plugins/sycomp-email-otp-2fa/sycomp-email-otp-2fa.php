<?php
/**
 * Plugin Name: Sycomp Authenticator 2FA
 * Plugin URI: https://sycomp.com
 * Description: Premium Google Authenticator (TOTP) 2FA and Hostinger SMTP integration for the Sycomp B2B Portal.
 * Version: 2.0.0
 * Author: Antigravity AI
 * Author URI: https://viralinbound.com
 * License: GPL2
 * Text Domain: sycomp-email-otp-2fa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper Class for TOTP (RFC 6238) operations
 */
class Sycomp_TOTP {
	/**
	 * Base32 character set.
	 */
	private static $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Generate a random 16-character Base32 secret key.
	 */
	public static function generate_secret() {
		$secret = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$secret .= self::$base32chars[ wp_rand( 0, 31 ) ];
		}
		return $secret;
	}

	/**
	 * Decode a Base32 string into its binary representation.
	 */
	public static function base32_decode( $base32 ) {
		$base32 = strtoupper( $base32 );
		$base32charsFlipped = array_flip( str_split( self::$base32chars ) );

		$base32 = rtrim( $base32, '=' );
		$len = strlen( $base32 );
		$n = 0;
		$j = 0;
		$binary = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $base32[ $i ];
			if ( ! isset( $base32charsFlipped[ $char ] ) ) {
				return false;
			}
			$val = $base32charsFlipped[ $char ];
			$n = ( $n << 5 ) | $val;
			$j += 5;

			if ( $j >= 8 ) {
				$j -= 8;
				$binary .= chr( ( $n >> $j ) & 0xFF );
			}
		}
		return $binary;
	}

	/**
	 * Compute the TOTP code for a given secret and time slice.
	 */
	public static function get_otp( $secret, $time_slice = null ) {
		if ( $time_slice === null ) {
			$time_slice = floor( time() / 30 );
		}

		$secret_key = self::base32_decode( $secret );
		if ( $secret_key === false ) {
			return false;
		}

		// Pack time into 8-byte binary string (big-endian 64-bit counter)
		$time = pack( 'N*', 0 ) . pack( 'N*', $time_slice );

		// Hash HMAC-SHA1
		$hmac = hash_hmac( 'sha1', $time, $secret_key, true );

		// Dynamic truncation
		$offset = ord( $hmac[19] ) & 0xf;
		$hashpart = substr( $hmac, $offset, 4 );

		// Unpack value
		$value = unpack( 'N', $hashpart );
		$value = $value[1] & 0x7fffffff;

		$code = $value % 1000000;

		return str_pad( $code, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Verify a user's code against their secret, with time drift tolerance.
	 */
	public static function verify( $secret, $code, $discrepancy = 1 ) {
		$code = sanitize_text_field( $code );
		$code = str_replace( ' ', '', $code ); // strip any spaces

		if ( strlen( $code ) !== 6 || ! ctype_digit( $code ) ) {
			return false;
		}

		$current_time_slice = floor( time() / 30 );
		for ( $i = -$discrepancy; $i <= $discrepancy; $i++ ) {
			$calculated_code = self::get_otp( $secret, $current_time_slice + $i );
			if ( $calculated_code !== false && hash_equals( $calculated_code, $code ) ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Class Sycomp_Email_OTP_2FA
 */
class Sycomp_Email_OTP_2FA {

	/**
	 * Temporary cookie name.
	 */
	const COOKIE_NAME = 'sycomp_2fa_temp_user';

	/**
	 * Maximum OTP verification attempts before session invalidation.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Initialize plugin hooks.
	 */
	public static function init() {
		// SMTP Integration (Must be retained for PO and transactional emails!)
		add_action( 'phpmailer_init', array( __CLASS__, 'smtp_configure' ) );

		// Fail-safe mail headers (force correct sender name & email on production)
		add_filter( 'wp_mail_from', array( __CLASS__, 'force_mail_from' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'force_mail_from_name' ) );

		// Login Interception
		add_filter( 'authenticate', array( __CLASS__, 'intercept_login' ), 55, 3 );

		// Custom OTP Verification Surface
		add_action( 'login_form_sycomp_2fa', array( __CLASS__, 'handle_otp_page' ) );

		// Admin Profile Management
		add_action( 'show_user_profile', array( __CLASS__, 'add_profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'add_profile_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
	}

	/* ---------------------------------------------------------------------
	 * SMTP Setup
	 * ------------------------------------------------------------------ */

	/**
	 * Configure Hostinger SMTP programmatically.
	 */
	public static function smtp_configure( $phpmailer ) {
		if ( ! defined( 'SYCOMP_SMTP_USER' ) || ! defined( 'SYCOMP_SMTP_PASS' ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = 'smtp.hostinger.com';
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Port       = 465; // Hostinger SSL
		$phpmailer->Username   = SYCOMP_SMTP_USER;
		$phpmailer->Password   = SYCOMP_SMTP_PASS;
		$phpmailer->SMTPSecure = 'ssl';

		// Set Sender Details
		$from_email = defined( 'SYCOMP_SMTP_FROM' ) ? SYCOMP_SMTP_FROM : SYCOMP_SMTP_USER;
		$from_name  = defined( 'SYCOMP_SMTP_FROM_NAME' ) ? SYCOMP_SMTP_FROM_NAME : 'Sycomp B2B Store';

		$phpmailer->From     = $from_email;
		$phpmailer->FromName = $from_name;
	}

	/**
	 * Force custom fail-safe sender email.
	 */
	public static function force_mail_from( $original_email ) {
		if ( defined( 'SYCOMP_SMTP_FROM' ) ) {
			return SYCOMP_SMTP_FROM;
		}
		return 'no-reply-sycomp@viralinbound.in';
	}

	/**
	 * Force custom fail-safe sender display name.
	 */
	public static function force_mail_from_name( $original_name ) {
		if ( defined( 'SYCOMP_SMTP_FROM_NAME' ) ) {
			return SYCOMP_SMTP_FROM_NAME;
		}
		return 'Sycomp B2B Store';
	}

	/* ---------------------------------------------------------------------
	 * Helper Session Methods
	 * ------------------------------------------------------------------ */

	/**
	 * Issue a cryptographically signed temporary cookie.
	 */
	protected static function set_temp_cookie( $user_id ) {
		$expiry = time() + 300; // 5 minutes to scan and activate setup
		$hash   = hash_hmac( 'sha256', $user_id . '|' . $expiry, SECURE_AUTH_SALT );
		$token  = $user_id . '|' . $expiry . '|' . $hash;

		setcookie( self::COOKIE_NAME, $token, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Validate the temporary signed cookie and return the User ID.
	 */
	protected static function get_temp_user_id() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return false;
		}

		$token = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		$parts = explode( '|', $token );

		if ( count( $parts ) !== 3 ) {
			return false;
		}

		list( $user_id, $expiry, $hash ) = $parts;

		if ( time() > (int) $expiry ) {
			return false;
		}

		// Recreate hash and verify signature
		$expected = hash_hmac( 'sha256', $user_id . '|' . $expiry, SECURE_AUTH_SALT );
		if ( ! hash_equals( $expected, $hash ) ) {
			return false;
		}

		return (int) $user_id;
	}

	/**
	 * Clear the temporary signed cookie.
	 */
	protected static function clear_temp_cookie() {
		setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/* ---------------------------------------------------------------------
	 * Authentication Interception
	 * ------------------------------------------------------------------ */

	/**
	 * Intercept successful login credentials verification.
	 */
	public static function intercept_login( $user, $username, $password ) {
		// Detect if credentials were submitted via any common login form field
		$is_login_post = ! empty( $_POST['log'] ) || ! empty( $_POST['username'] ) || ! empty( $_POST['login'] );
		if ( ! $is_login_post || is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		// Save user ID in a signed temporary cookie
		self::set_temp_cookie( $user->ID );

		// Prepare redirection
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$redirect_url = add_query_arg(
			array(
				'action'      => 'sycomp_2fa',
				'redirect_to' => urlencode( $redirect_to ),
			),
			wp_login_url()
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * OTP Verification Interface & Logic (TOTP)
	 * ------------------------------------------------------------------ */

	/**
	 * Render the premium setup / verification page and handle input verification.
	 */
	public static function handle_otp_page() {
		$user_id = self::get_temp_user_id();

		if ( ! $user_id ) {
			// No active session or session expired — redirect to login
			self::clear_temp_cookie();
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user   = get_user_by( 'id', $user_id );
		$errors = new WP_Error();

		if ( ! $user ) {
			self::clear_temp_cookie();
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		// Check if the user already has a saved TOTP secret key
		$stored_secret = get_user_meta( $user->ID, '_sycomp_totp_secret', true );
		$is_setup_flow = empty( $stored_secret );

		// Prepare setup details if needed
		if ( $is_setup_flow ) {
			$pending_secret = get_transient( 'sycomp_totp_pending_' . $user->ID );
			if ( ! $pending_secret ) {
				$pending_secret = Sycomp_TOTP::generate_secret();
				set_transient( 'sycomp_totp_pending_' . $user->ID, $pending_secret, 600 );
			}
			// OTP AUTH URI representation
			$qr_url = 'otpauth://totp/' . rawurlencode( 'Sycomp:' . $user->user_login ) . '?secret=' . $pending_secret . '&issuer=Sycomp';
		}

		// Process Verification POST
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['sycomp_otp'] ) ) {
			$input_otp = sanitize_text_field( wp_unslash( $_POST['sycomp_otp'] ) );
			$secret_to_verify = $is_setup_flow ? $pending_secret : $stored_secret;

			$fails = (int) get_transient( 'sycomp_otp_fails_' . $user->ID ) + 1;
			set_transient( 'sycomp_otp_fails_' . $user->ID, $fails, 600 );

			if ( $fails > self::MAX_ATTEMPTS ) {
				// Lockout user
				self::clear_temp_cookie();
				delete_transient( 'sycomp_otp_fails_' . $user->ID );
				if ( $is_setup_flow ) {
					delete_transient( 'sycomp_totp_pending_' . $user->ID );
				}
				
				// Add lockout message on wp_login screen
				wp_safe_redirect( add_query_arg( 'sycomp_locked', '1', wp_login_url() ) );
				exit;
			} elseif ( empty( $secret_to_verify ) ) {
				$errors->add( 'expired', __( 'Your session has expired. Please try logging in again.', 'sycomp-email-otp-2fa' ) );
			} elseif ( ! Sycomp_TOTP::verify( $secret_to_verify, $input_otp ) ) {
				$errors->add( 'invalid', sprintf( __( 'The code you entered is incorrect. You have %d attempts remaining.', 'sycomp-email-otp-2fa' ), self::MAX_ATTEMPTS - $fails ) );
			} else {
				// Success! 
				self::clear_temp_cookie();
				delete_transient( 'sycomp_otp_fails_' . $user->ID );

				if ( $is_setup_flow ) {
					// Save the verified secret key
					update_user_meta( $user->ID, '_sycomp_totp_secret', $secret_to_verify );
					delete_transient( 'sycomp_totp_pending_' . $user->ID );
				}

				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );
				
				// Fire standard WordPress login actions (clears failed login throttles)
				do_action( 'wp_login', $user->user_login, $user );
				
				// Redirection logic matching user roles
				$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
				if ( empty( $redirect_to ) ) {
					$roles = (array) $user->roles;
					$is_staff = in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true );
					if ( $is_staff ) {
						$redirect_to = admin_url();
					} else {
						$redirect_to = home_url( '/' );
					}
				}

				wp_safe_redirect( $redirect_to );
				exit;
			}
		}

		// RENDER INTERFACE
		$title = $is_setup_flow ? __( 'Set Up Authenticator', 'sycomp-email-otp-2fa' ) : __( 'Two-Factor Authentication', 'sycomp-email-otp-2fa' );
		login_header( $title, '', $errors );
		?>
		<style>
			#login {
				width: 35vw !important;
				min-width: 460px !important;
				max-width: 600px !important;
				padding: 12px 16px !important;
			}
			.login h1 a {
				display: none !important;
			}
			/* Container and Premium Layout */
			.sycomp-2fa-container {
				background: #ffffff !important;
				border: 1px solid var(--sy-border, #e3e6eb) !important;
				border-radius: var(--sy-radius-lg, 10px) !important;
				box-shadow: var(--sy-shadow-md, 0 2px 6px rgba(10,14,22,.07), 0 14px 32px rgba(10,14,22,.10)) !important;
				padding: 24px 28px !important;
				margin-top: 4px;
				box-sizing: border-box;
			}
			.sycomp-2fa-title {
				font-family: var(--sy-font, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
				font-size: 21px;
				font-weight: 750;
				color: var(--sy-navy, #0c1c3c);
				margin-bottom: 10px;
				text-align: center;
				letter-spacing: -0.02em;
			}
			.sycomp-2fa-description {
				font-size: var(--sy-text-sm, 14px);
				line-height: 1.55;
				color: var(--sy-muted, #5b6472);
				text-align: center;
				margin-bottom: 20px;
			}
			/* QR Code setup styles */
			.qr-code-wrapper {
				display: flex;
				justify-content: center;
				align-items: center;
				margin: 18px 0;
			}
			.qr-code-box {
				padding: 10px;
				background: #ffffff;
				border: 1px solid #e3e6eb;
				border-radius: 8px;
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
			}
			.secret-key-wrapper {
				text-align: center;
				margin-bottom: 22px;
				font-size: 13.5px;
				color: var(--sy-muted, #5b6472);
			}
			.secret-key-text {
				font-size: 15px;
				color: var(--sy-navy, #0c1c3c);
				letter-spacing: 1.5px;
				display: block;
				margin-top: 6px;
				font-family: monospace;
				background: #f5f6f8;
				padding: 6px 12px;
				border-radius: 4px;
				border: 1px dashed #cfd4dc;
				user-select: all;
			}
			/* Digit box */
			.otp-input-wrap {
				margin-bottom: 18px;
				position: relative;
			}
			.otp-field {
				width: 100% !important;
				height: 52px !important;
				font-size: 24px !important;
				font-weight: 700 !important;
				letter-spacing: 12px !important;
				text-align: center !important;
				border: 1px solid var(--sy-border, #e3e6eb) !important;
				border-radius: var(--sy-radius, 6px) !important;
				box-sizing: border-box;
				background-color: #fff !important;
				color: var(--sy-navy, #0c1c3c) !important;
				transition: border-color var(--sy-transition, .15s ease), box-shadow var(--sy-transition, .15s ease) !important;
				padding-left: 12px !important; /* offsets letter spacing centering */
			}
			.otp-field:focus {
				outline: none !important;
				border-color: var(--sy-accent, #1e4fd6) !important;
				box-shadow: 0 0 0 3px rgba(30,79,214,.18) !important;
			}
			/* Buttons */
			.sycomp-2fa-submit {
				background: var(--sy-navy, #0c1c3c) !important;
				border: none !important;
				color: #fff !important;
				font-weight: 600 !important;
				width: 100%;
				height: 46px;
				border-radius: var(--sy-radius, 6px) !important;
				font-size: 14.5px !important;
				cursor: pointer;
				transition: background 0.2s ease, transform 0.1s ease !important;
				box-shadow: 0 2px 4px rgba(12, 28, 60, 0.1) !important;
			}
			.sycomp-2fa-submit:hover {
				background: var(--sy-navy-deep, #081429) !important;
			}
			.sycomp-2fa-submit:active {
				transform: scale(0.985);
			}
			/* Errors styling overrides */
			.login .notice, .login .message, .login #login_error {
				border-radius: var(--sy-radius, 6px) !important;
				box-shadow: var(--sy-shadow-sm, 0 1px 2px rgba(10,14,22,.05)) !important;
				font-size: 13.5px !important;
				margin-bottom: 16px !important;
			}
			.login #login_error {
				border-left-color: var(--sy-danger, #c0392b) !important;
				background-color: #fdf5f4 !important;
				color: var(--sy-danger, #c0392b) !important;
			}
			.login .message.notice-success {
				border-left-color: var(--sy-success, #1f8a4c) !important;
				background-color: #f4faf6 !important;
				color: var(--sy-success, #1f8a4c) !important;
			}
			#backtoblog, #nav {
				display: none !important;
			}
			.sycomp-2fa-cancel {
				text-align: center;
				margin-top: 14px;
				font-size: 13.5px;
			}
			.sycomp-2fa-cancel a {
				color: var(--sy-muted, #5b6472);
				text-decoration: none;
			}
			.sycomp-2fa-cancel a:hover {
				color: var(--sy-navy, #0c1c3c);
				text-decoration: underline;
			}
		</style>

		<div class="sycomp-2fa-container">
			<?php if ( $is_setup_flow ) : ?>
				<div class="sycomp-2fa-title"><?php _e( 'Set Up Authenticator App', 'sycomp-email-otp-2fa' ); ?></div>
				<div class="sycomp-2fa-description">
					<?php _e( 'Add this account by scanning the QR code below using Google Authenticator or another TOTP app.', 'sycomp-email-otp-2fa' ); ?>
				</div>

				<div class="qr-code-wrapper">
					<div class="qr-code-box" id="qrcode"></div>
				</div>

				<div class="secret-key-wrapper">
					<p style="margin: 0;"><?php _e( 'Or enter this key manually into your app:', 'sycomp-email-otp-2fa' ); ?></p>
					<strong class="secret-key-text"><?php echo esc_html( $pending_secret ); ?></strong>
				</div>
			<?php else : ?>
				<div class="sycomp-2fa-title"><?php _e( 'Verification Code', 'sycomp-email-otp-2fa' ); ?></div>
				<div class="sycomp-2fa-description">
					<?php _e( 'Please enter the 6-digit authentication code from your Google Authenticator or TOTP app.', 'sycomp-email-otp-2fa' ); ?>
				</div>
			<?php endif; ?>

			<form name="sycomp_2fa_form" id="sycomp_2fa_form" action="" method="post" autocomplete="off">
				<div class="otp-input-wrap">
					<input type="text" 
						   name="sycomp_otp" 
						   id="sycomp_otp" 
						   class="otp-field" 
						   pattern="[0-9]*" 
						   inputmode="numeric" 
						   maxlength="6" 
						   placeholder="------" 
						   required 
						   autofocus />
				</div>

				<button type="submit" class="sycomp-2fa-submit">
					<?php $is_setup_flow ? _e( 'Verify & Activate App', 'sycomp-email-otp-2fa' ) : _e( 'Verify & Log In', 'sycomp-email-otp-2fa' ); ?>
				</button>
			</form>
			
			<div class="sycomp-2fa-cancel">
				<a href="#" id="cancel-link"><?php _e( '← Back to Login', 'sycomp-email-otp-2fa' ); ?></a>
			</div>
		</div>

		<script>
			// Numeric restriction on input
			document.getElementById('sycomp_otp').addEventListener('input', function(e) {
				this.value = this.value.replace(/[^0-9]/g, '');
			});

			<?php if ( $is_setup_flow ) : ?>
			// Dynamic Loading of qrcode.js to generate the QR Code in the browser
			(function() {
				var script = document.createElement('script');
				script.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
				script.onload = function() {
					new QRCode(document.getElementById("qrcode"), {
						text: "<?php echo esc_js( $qr_url ); ?>",
						width: 170,
						height: 170,
						colorDark : "#0c1c3c",
						colorLight : "#ffffff",
						correctLevel : QRCode.CorrectLevel.M
					});
				};
				document.head.appendChild(script);
			})();
			<?php endif; ?>

			// Back to login redirection handling
			var cancelEl = document.getElementById('cancel-link');
			if (cancelEl) {
				cancelEl.addEventListener('click', function(e) {
					e.preventDefault();
					document.cookie = "<?php echo self::COOKIE_NAME; ?>=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=<?php echo COOKIEPATH; ?>;";
					window.location.href = "<?php echo wp_login_url(); ?>";
				});
			}
		</script>
		<?php
		login_footer();
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Profile Setup and Resets
	 * ------------------------------------------------------------------ */

	/**
	 * Add custom 2FA fields to user profile screens in WordPress admin.
	 */
	public static function add_profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$has_totp = ! empty( get_user_meta( $user->ID, '_sycomp_totp_secret', true ) );
		?>
		<h3><?php _e( 'Sycomp Two-Factor Authentication', 'sycomp-email-otp-2fa' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label><?php _e( 'Authenticator App 2FA', 'sycomp-email-otp-2fa' ); ?></label></th>
				<td>
					<?php if ( $has_totp ) : ?>
						<span style="color: #1f8a4c; font-weight: bold; display: inline-block; margin-bottom: 8px;">
							✓ <?php _e( 'Active & Configured', 'sycomp-email-otp-2fa' ); ?>
						</span>
						<br />
						<label for="sycomp_reset_2fa">
							<input type="checkbox" name="sycomp_reset_2fa" id="sycomp_reset_2fa" value="1" />
							<strong><?php _e( 'Reset Authenticator App 2FA', 'sycomp-email-otp-2fa' ); ?></strong>
						</label>
						<p class="description">
							<?php _e( 'Check this box and save the profile to delete the current authenticator key. The user will be prompted to set up a new authenticator device on their next login.', 'sycomp-email-otp-2fa' ); ?>
						</p>
					<?php else : ?>
						<span style="color: #c0392b; font-weight: bold;">
							✗ <?php _e( 'Not Configured Yet', 'sycomp-email-otp-2fa' ); ?>
						</span>
						<p class="description">
							<?php _e( 'The user will be guided through a Setup Wizard to link their Authenticator App during their next login attempt.', 'sycomp-email-otp-2fa' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save/Process custom profile fields update.
	 */
	public static function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['sycomp_reset_2fa'] ) && '1' === $_POST['sycomp_reset_2fa'] ) {
			delete_user_meta( $user_id, '_sycomp_totp_secret' );
			delete_transient( 'sycomp_totp_pending_' . $user_id );
		}
	}
}

// Add filter to inject lockout error message into wp_login screen
add_filter( 'wp_login_errors', function( $errors ) {
	if ( isset( $_GET['sycomp_locked'] ) && '1' === $_GET['sycomp_locked'] ) {
		$errors->add( 'locked', __( 'Too many invalid passcode attempts. For security, you have been logged out. Please try again.', 'sycomp-email-otp-2fa' ) );
	}
	return $errors;
} );

// Initialize
Sycomp_Email_OTP_2FA::init();
