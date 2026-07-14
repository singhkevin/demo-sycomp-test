<?php
/**
 * Front-end glue: asset loading, whole-site login gating, and full Sycomp
 * branding for the WordPress login screen — modelled on the Sycomp store
 * password page (white header with logo + support link, the team-photo
 * background, and a footer row of logo / navigation / social).
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Frontend.
 */
class Sycomp_B2B_Frontend {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gate_site' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'manager_home_redirect' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'disable_portal_caching' ), 5 );
		// Late priority so other plugins cannot override staff → Manage routing.
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 9999, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar_visibility' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		add_action( 'login_footer', array( __CLASS__, 'login_chrome' ) );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_logo_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_logo_text' ) );
		add_filter( 'woocommerce_get_stock_html', '__return_empty_string' );
	}

	/**
	 * Enqueue the portal stylesheet and script.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'sycomp-b2b-portal', SYCOMP_B2B_URL . 'assets/css/portal.css', array( 'select2' ), SYCOMP_B2B_VERSION );
		wp_enqueue_script( 'sycomp-b2b-portal', SYCOMP_B2B_URL . 'assets/js/portal.js', array( 'jquery' ), SYCOMP_B2B_VERSION, true );
		wp_localize_script(
			'sycomp-b2b-portal',
			'SycompB2B',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sycomp_catalogue' ),
				'i18n'    => array(
					'adding' => __( 'Adding…', 'sycomp-b2b-portal' ),
					'added'  => __( 'Added', 'sycomp-b2b-portal' ),
					'error'  => __( 'Error', 'sycomp-b2b-portal' ),
				),
			)
		);
	}

	/**
	 * The entire portal is private. Any front-end page, viewed logged-out,
	 * is sent to the site home page — where the security module renders the
	 * buyer sign-in form in place, so the login is never a separate exposed
	 * URL.
	 */
	public static function gate_site() {
		if ( is_user_logged_in() ) {
			return;
		}
		if ( ! apply_filters( 'sycomp_b2b_require_login', true ) ) {
			return;
		}
		// The home page itself shows the sign-in form — never redirect it
		// to itself.
		if ( class_exists( 'Sycomp_B2B_Security' ) && Sycomp_B2B_Security::is_root_request() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * The shop manager's home screen is the management dashboard, not the
	 * public market selector. Any time a shop manager lands on the site
	 * front page they are sent to the Manage dashboard instead. Buyers and
	 * administrators are unaffected.
	 */
	public static function manager_home_redirect() {
		if ( ! is_user_logged_in() || ! is_front_page() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! in_array( 'shop_manager', (array) $user->roles, true ) ) {
			return;
		}
		$manage = sycomp_b2b_page_url( 'manage' );
		if ( $manage ) {
			wp_safe_redirect( $manage );
			exit;
		}
	}

	/**
	 * Prevent caching of portal pages for logged-in buyers and managers.
	 */
	public static function disable_portal_caching() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( Sycomp_B2B_User::is_portal_user() || current_user_can( 'manage_woocommerce' ) ) {
			if ( ! is_admin() && ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			if ( ! headers_sent() ) {
				nocache_headers();
			}
		}
	}

	/**
	 * Route users after login.
	 *
	 * Administrators and shop managers always land on the Manage dashboard
	 * (not wp-admin). WordPress defaults the login form's redirect_to to
	 * admin_url(), and when that value survives filters unchanged, core
	 * sends caps-capable users straight into /wp-admin/.
	 *
	 * Buyers go to the portal home (market selector).
	 *
	 * @param string           $redirect_to Default redirect.
	 * @param string           $requested   Requested redirect.
	 * @param WP_User|WP_Error $user        User or error.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		$roles     = (array) $user->roles;
		$is_staff  = in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true );
		$manage_url = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'manage' ) : '';

		if ( $is_staff ) {
			return $manage_url ? $manage_url : home_url( '/manage/' );
		}

		return home_url( '/' );
	}

	/**
	 * Hide the WordPress admin bar for everyone except administrators, so
	 * the portal and the manager dashboard stay free of WordPress chrome.
	 *
	 * @param bool $show Whether to show the bar.
	 * @return bool
	 */
	public static function admin_bar_visibility( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Resolve a Sycomp logo URL by variant.
	 *
	 * @param string $variant 'color' or 'white'.
	 * @return string
	 */
	protected static function logo( $variant ) {
		if ( function_exists( 'sycomp_logo_url' ) ) {
			return (string) sycomp_logo_url( $variant );
		}
		return '';
	}

	/**
	 * CSS for the login screen.
	 */
	public static function login_styles() {
		$bg = get_template_directory_uri() . '/assets/img/sycomp-login-bg.png';
		?>
		<style>
			body.login {
				background: #0c1c3c url('<?php echo esc_url( $bg ); ?>') no-repeat center center;
				background-size: cover;
				margin: 0;
				display: flex;
				flex-direction: column;
				min-height: 100vh;
				padding-top: 76px;
				box-sizing: border-box;
			}
			.login h1 { display: none; }
			#backtoblog { display: none; }

			/* Header bar: white, full-colour logo left, support link right. */
			body.login .syc-login-header {
				position: fixed; top: 0; left: 0; right: 0; height: 76px;
				background: #ffffff; border-bottom: 1px solid #e3e6eb; z-index: 100;
			}
			body.login .syc-login-header__inner {
				max-width: 1300px; height: 100%; margin: 0 auto; box-sizing: border-box;
				padding: 0 48px;
				display: flex; align-items: center; justify-content: space-between;
			}
			body.login .syc-login-header__logo img { height: 40px; width: auto; display: block; }
			body.login .syc-login-header__support {
				display: inline-flex; align-items: center; gap: 9px;
				color: #0c1c3c; font-weight: 600; font-size: .98rem; text-decoration: none;
			}
			body.login .syc-login-header__support:hover { color: #1e4fd6; }
			body.login .syc-login-header__support svg { width: 20px; height: 20px; }

			/* Form column — vertically centred over the photo. */
			#login {
				width: 100%; max-width: 420px; margin: 0 auto; box-sizing: border-box;
				flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; justify-content: center;
				padding: 28px 16px;
			}
			body.login .syc-login-intro {
				text-align: center; color: #ffffff; font-size: 1.4rem;
				font-weight: 650; margin: 0 0 4px; text-shadow: 0 1px 12px rgba(0,0,0,.55);
			}
			body.login .syc-login-sub {
				text-align: center; color: rgba(255,255,255,.9); font-size: .92rem;
				margin: 0 0 20px; text-shadow: 0 1px 8px rgba(0,0,0,.6);
			}
			.login form {
				background: #ffffff; border: 0; border-radius: 10px;
				box-shadow: 0 16px 50px rgba(0,0,0,.35); padding: 28px 26px; margin: 0;
			}
			.login label { color: #1a1f29; font-size: .9rem; }
			.login input[type=text], .login input[type=password] {
				border-radius: 6px; border-color: #c9ced6; padding: 9px 11px; font-size: 1rem;
			}
			.login input[type=text]:focus, .login input[type=password]:focus {
				border-color: #1e4fd6; box-shadow: 0 0 0 1px #1e4fd6; outline: 0;
			}
			.wp-core-ui .button-primary {
				background: #0c1c3c; border-color: #0c1c3c; border-radius: 6px;
				text-shadow: none; box-shadow: none; font-weight: 600; padding: 4px 18px;
			}
			.wp-core-ui .button-primary:hover { background: #1e4fd6; border-color: #1e4fd6; }
			.login #nav { text-align: center; padding: 16px 0 4px; }
			.login #nav a,
			.login #nav a:visited { color: #ffffff; text-shadow: 0 1px 10px rgba(0,0,0,.85); }
			.login #nav a:hover { color: #c9d8f5; }
			.login .message, .login .notice { border-left-color: #1e4fd6; border-radius: 6px; }
			.login #login_error { border-left-color: #c0392b; border-radius: 6px; }

			/* Footer: one row (logo left / nav centre / social right), a
			   divider, then the copyright line with legal links at right. */
			body.login .syc-login-footer {
				flex-shrink: 0; background: #0c1c3c; color: rgba(255,255,255,.72);
				padding: 32px 0 28px;
			}
			body.login .syc-login-footer__inner {
				max-width: 1300px; margin: 0 auto; box-sizing: border-box; padding: 0 48px;
			}
			body.login .syc-login-footer__row {
				display: flex; align-items: center; justify-content: space-between;
				gap: 24px; flex-wrap: wrap;
			}
			body.login .syc-login-footer__logo img { height: 38px; width: auto; display: block; }
			body.login .syc-login-footer__nav {
				display: flex; flex: 1; justify-content: center; flex-wrap: wrap; gap: 8px 22px;
			}
			body.login .syc-login-footer__nav a { color: rgba(255,255,255,.82); font-size: .96rem; text-decoration: none; }
			body.login .syc-login-footer__nav a:hover { color: #ffffff; }
			body.login .syc-login-footer__social { display: flex; gap: 11px; }
			body.login .syc-login-footer__social a {
				width: 37px; height: 37px; border-radius: 50%;
				background: rgba(255,255,255,.1);
				display: flex; align-items: center; justify-content: center;
			}
			body.login .syc-login-footer__social a:hover { background: rgba(255,255,255,.2); }
			body.login .syc-login-footer__social svg { width: 17px; height: 17px; fill: #ffffff; }
			body.login .syc-login-footer__divider { height: 1px; background: rgba(255,255,255,.12); margin: 16px 0 12px; }
			body.login .syc-login-footer__bottom { position: relative; text-align: center; min-height: 20px; }
			body.login .syc-login-footer__copy { font-size: .92rem; font-weight: 600; color: rgba(255,255,255,.82); }
			body.login .syc-login-footer__legal { position: absolute; right: 0; top: 50%; transform: translateY(-50%); }
			body.login .syc-login-footer__legal a {
				color: rgba(255,255,255,.6); font-size: .86rem; text-decoration: none; margin-left: 18px;
			}
			body.login .syc-login-footer__legal a:hover { color: #ffffff; }
			@media ( max-width: 860px ) {
				body.login .syc-login-header__inner,
				body.login .syc-login-footer__inner { padding: 0 20px; }
				body.login .syc-login-footer__row { justify-content: center; text-align: center; }
				/* Full-width so the links wrap inside the row instead of the
				   nav box overflowing the viewport. */
				body.login .syc-login-footer__nav { flex: 1 1 100%; }
				body.login .syc-login-footer__legal { position: static; transform: none; display: block; margin-top: 6px; }
				body.login .syc-login-footer__legal a { margin: 0 9px; }
			}
			@media (max-width: 767px), (max-height: 720px) {
				html, body.login {
					height: auto !important;
					min-height: 100vh;
				}
				body.login {
					padding-top: 0;
				}
				body.login .syc-login-header {
					position: static;
					height: auto;
					border-bottom: 1px solid #e3e6eb;
					order: 1;
				}
				body.login .syc-login-header__inner {
					height: 60px;
					padding: 0 16px;
				}
				body.login .syc-login-header__logo img {
					height: 32px;
				}
				body.login .syc-login-header__support {
					font-size: 0.88rem;
				}
				body.login .syc-login-header__support svg {
					width: 18px;
					height: 18px;
				}
				#login {
					order: 2;
					margin: 20px auto;
					padding: 16px 12px;
					flex: none;
					min-height: auto;
					height: auto;
				}
				body.login .syc-login-intro {
					font-size: 1.25rem;
					margin-bottom: 2px;
				}
				body.login .syc-login-sub {
					font-size: 0.88rem;
					margin-bottom: 16px;
				}
				.login form {
					padding: 20px 20px;
				}
				.login #nav {
					padding: 12px 0 0;
				}
				body.login .syc-login-footer {
					order: 3;
					padding: 20px 0 16px;
				}
				body.login .syc-login-footer__logo img {
					height: 30px;
				}
				body.login .syc-login-footer__nav {
					gap: 6px 16px;
				}
				body.login .syc-login-footer__nav a {
					font-size: 0.88rem;
				}
				body.login .syc-login-footer__divider {
					margin: 12px 0 10px;
				}
				body.login .syc-login-footer__copy {
					font-size: 0.85rem;
				}
				body.login .syc-login-footer__legal {
					position: static;
					transform: none;
					display: block;
					margin-top: 6px;
				}
				body.login .syc-login-footer__legal a {
					font-size: 0.8rem;
					margin: 0 8px;
				}
			}
		</style>
		<?php
	}

	/**
	 * Heading shown above the login form.
	 *
	 * @param string $message Existing login message.
	 * @return string
	 */
	public static function login_message( $message ) {
		if ( '' !== trim( (string) $message ) ) {
			return $message;
		}
		$html  = '<p class="syc-login-intro">' . esc_html__( 'Sycomp Procurement Portal', 'sycomp-b2b-portal' ) . '</p>';
		$html .= '<p class="syc-login-sub">' . esc_html__( 'Sign in with the credentials provided by Sycomp.', 'sycomp-b2b-portal' ) . '</p>';
		return $html;
	}

	/**
	 * Render the fixed header bar and the footer on the login screen.
	 */
	public static function login_chrome() {
		$color = self::logo( 'color' );
		$white = self::logo( 'white' );

		$nav = array(
			'Solutions'        => 'https://sycomp.com/solutions/',
			'Services'         => 'https://sycomp.com/services/',
			'Industries'       => 'https://sycomp.com/industries/',
			'Resources'        => 'https://sycomp.com/resources/',
			'About'            => 'https://sycomp.com/about/',
			'Careers'          => 'https://sycomp.com/careers/',
			'Customer Support' => 'https://sycomp.com/customer-support/',
		);
		$social = array(
			'Instagram' => array( 'https://www.instagram.com/sycomp_inc/', '<path d="M12 2.2c3.2 0 3.6 0 4.9.07 3.3.15 4.8 1.7 4.95 4.95.06 1.3.07 1.7.07 4.88s0 3.6-.07 4.88c-.15 3.25-1.7 4.8-4.95 4.95-1.3.06-1.7.07-4.9.07s-3.6 0-4.9-.07c-3.25-.15-4.8-1.7-4.95-4.95C2.2 15.6 2.2 15.2 2.2 12s0-3.6.07-4.88C2.42 3.87 3.97 2.32 7.22 2.17 8.5 2.2 8.9 2.2 12 2.2zm0 3.6a6.2 6.2 0 100 12.4 6.2 6.2 0 000-12.4zm0 10.2a4 4 0 110-8 4 4 0 010 8zm6.4-10.5a1.45 1.45 0 100 2.9 1.45 1.45 0 000-2.9z"/>' ),
			'Facebook'  => array( 'https://www.facebook.com/SycompInc/', '<path d="M22 12a10 10 0 10-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0022 12z"/>' ),
			'X'         => array( 'https://x.com/Sycomp_Inc', '<path d="M18.9 2H22l-7.5 8.6L23 22h-6.9l-5.4-7-6.2 7H1.4l8-9.2L1 2h7l4.9 6.5zM17.7 20h1.7L7.4 3.9H5.6z"/>' ),
			'YouTube'   => array( 'https://www.youtube.com/@Sycomp', '<path d="M23 12s0-3.2-.4-4.7a3 3 0 00-2.1-2.1C18.7 4.8 12 4.8 12 4.8s-6.7 0-8.5.4A3 3 0 001.4 7.3C1 8.8 1 12 1 12s0 3.2.4 4.7a3 3 0 002.1 2.1c1.8.4 8.5.4 8.5.4s6.7 0 8.5-.4a3 3 0 002.1-2.1C23 15.2 23 12 23 12zM9.8 15.3V8.7l5.7 3.3z"/>' ),
			'LinkedIn'  => array( 'https://www.linkedin.com/company/sycomp/', '<path d="M4.98 3.5a2.5 2.5 0 110 5 2.5 2.5 0 010-5zM3 9h4v12H3zM10 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.77-1.95 4.03 0 4.78 2.6 4.78 5.97V21h-4v-5.3c0-1.27-.03-2.9-1.8-2.9-1.8 0-2.07 1.4-2.07 2.8V21H10z"/>' ),
		);
		?>
		<div class="syc-login-header">
			<div class="syc-login-header__inner">
			<a class="syc-login-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php if ( $color ) : ?>
					<img src="<?php echo esc_url( $color ); ?>" alt="Sycomp">
				<?php else : ?>
					<strong style="color:#0c1c3c;font-size:1.2rem;">Sycomp</strong>
				<?php endif; ?>
			</a>
			<a class="syc-login-header__support" href="https://sycomp.com/customer-support/" target="_blank" rel="noopener">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<circle cx="12" cy="12" r="9"></circle>
					<circle cx="12" cy="12" r="3.4"></circle>
					<path d="M5.6 5.6l3.9 3.9M18.4 5.6l-3.9 3.9M5.6 18.4l3.9-3.9M18.4 18.4l-3.9-3.9"></path>
				</svg>
				<?php esc_html_e( 'Customer Support', 'sycomp-b2b-portal' ); ?>
			</a>
			</div>
		</div>

		<footer class="syc-login-footer">
			<div class="syc-login-footer__inner">
			<div class="syc-login-footer__row">
				<a class="syc-login-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php if ( $white ) : ?>
						<img src="<?php echo esc_url( $white ); ?>" alt="Sycomp">
					<?php else : ?>
						<strong style="color:#fff;font-size:1.1rem;">Sycomp</strong>
					<?php endif; ?>
				</a>
				<nav class="syc-login-footer__nav">
					<?php foreach ( $nav as $label => $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="syc-login-footer__social">
					<?php foreach ( $social as $name => $data ) : ?>
						<a href="<?php echo esc_url( $data[0] ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( $name ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $data[1]; // phpcs:ignore WordPress.Security.EscapeOutput ?></svg>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="syc-login-footer__divider"></div>
			<div class="syc-login-footer__bottom">
				<span class="syc-login-footer__copy">
					<?php echo esc_html( '© ' . gmdate( 'Y' ) . ' Sycomp A Technology Company, Inc. All rights reserved.' ); ?>
				</span>
				<span class="syc-login-footer__legal">
					<a href="https://sycomp.com/termsandconditions/" target="_blank" rel="noopener"><?php esc_html_e( 'Terms and conditions', 'sycomp-b2b-portal' ); ?></a>
					<a href="https://sycomp.com/privacy-policy/" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy Policy', 'sycomp-b2b-portal' ); ?></a>
				</span>
			</div>
		</footer>
		<?php
	}

	/**
	 * Login logo links to the portal home.
	 *
	 * @return string
	 */
	public static function login_logo_url() {
		return home_url( '/' );
	}

	/**
	 * Login logo title text.
	 *
	 * @return string
	 */
	public static function login_logo_text() {
		return __( 'Sycomp Procurement Portal', 'sycomp-b2b-portal' );
	}
}
