<?php
/**
 * Security hardening for the Sycomp B2B portal.
 *
 * Login model — two separate, role-gated entry points:
 *
 *  - Buyers sign in at the site home page itself (https://example.com/).
 *    A logged-out visitor sees the sign-in form rendered in place; the URL
 *    never changes and no "login page" slug is ever exposed. Only buyer
 *    accounts may sign in here.
 *
 *  - Administrators and shop managers sign in at a separate hidden URL
 *    (default: /sycomp-staff-k7m2x9/). Buyers cannot sign in there.
 *
 *  - wp-login.php returns a 404; /wp-admin/ is administrators-only and
 *    returns a 404 to logged-out visitors.
 *
 * Other hardening: XML-RPC blocked, REST API gated to authenticated users,
 * author enumeration blocked, failed-login throttling, version fingerprints
 * stripped, dashboard file editor + Application Passwords disabled, and
 * standard security response headers.
 *
 * Recovery — if you are ever locked out:
 *  - Define `SYCOMP_SECURITY_OFF` as true in wp-config.php, or
 *  - rename / deactivate the plugin folder.
 * Either restores stock wp-login.php behaviour immediately.
 *
 * The staff URL can be changed with a `SYCOMP_STAFF_LOGIN_SLUG` constant in
 * wp-config.php or the `sycomp_b2b_staff_login_slug` filter.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Security.
 */
class Sycomp_B2B_Security {

	/**
	 * Default hidden slug for the administrator / shop-manager login.
	 */
	const DEFAULT_STAFF_SLUG = 'sycomp-staff-k7m2x9';

	/**
	 * Failed sign-ins, per IP, before a temporary lockout.
	 */
	const LOCKOUT_LIMIT = 5;

	/**
	 * Lockout / failed-attempt window, in minutes.
	 */
	const LOCKOUT_MINUTES = 15;

	/**
	 * Which login surface is currently being served: '', 'buyer' or 'staff'.
	 *
	 * @var string
	 */
	protected static $login_context = '';

	/**
	 * Register hooks. Called from the main plugin file *before* the
	 * `plugins_loaded` action fires.
	 */
	public static function init() {
		// Disable the dashboard plugin / theme file editor.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// --- Login routing + wp-admin lockdown. ---
		add_action( 'plugins_loaded', array( __CLASS__, 'block_xmlrpc' ), 1 );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_request' ) );
		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 10, 4 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_network_site_url' ), 10, 3 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_wp_redirect' ), 10, 1 );
		add_filter( 'login_url', array( __CLASS__, 'filter_login_like_url' ), 20, 1 );
		add_filter( 'logout_url', array( __CLASS__, 'filter_login_like_url' ), 20, 1 );
		add_filter( 'lostpassword_url', array( __CLASS__, 'filter_login_like_url' ), 20, 1 );
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );

		// --- XML-RPC. ---
		add_filter( 'xmlrpc_enabled', array( __CLASS__, 'filter_xmlrpc_enabled' ) );
		add_filter( 'wp_headers', array( __CLASS__, 'strip_pingback_header' ) );

		// --- Author / user enumeration. ---
		add_action( 'template_redirect', array( __CLASS__, 'block_author_enumeration' ), 5 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_users_endpoint' ) );

		// --- REST API: authenticated users only. ---
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'require_rest_auth' ) );

		// --- Strip version fingerprints. ---
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );

		// --- Brute-force throttle, error masking + role gating. ---
		add_filter( 'authenticate', array( __CLASS__, 'check_login_lockout' ), 30, 1 );
		add_filter( 'authenticate', array( __CLASS__, 'mask_auth_error' ), 40, 1 );
		add_filter( 'authenticate', array( __CLASS__, 'enforce_login_context' ), 45, 1 );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failed_login' ) );
		add_action( 'wp_login', array( __CLASS__, 'clear_failed_logins' ) );

		// --- Disable Application Passwords (unused by the portal). ---
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		// --- Security response headers. ---
		add_action( 'send_headers', array( __CLASS__, 'security_headers' ) );
		add_action( 'login_init', array( __CLASS__, 'security_headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'security_headers' ) );

		// Staff login form should not default redirect_to to /wp-admin/.
		add_action( 'login_init', array( __CLASS__, 'force_staff_login_redirect' ), 5 );
		add_filter( 'admin_email_check_interval', array( __CLASS__, 'disable_admin_email_check_on_staff_login' ) );

		// Buyers sign in with Sycomp-issued credentials only — no WordPress.com
		// account is relevant to them. Suppress Jetpack's SSO button on the
		// buyer login surface; leave it available on the hidden staff URL.
		add_filter( 'jetpack_sso_allowed_actions', array( __CLASS__, 'filter_jetpack_sso_allowed_actions' ) );
	}

	/**
	 * Whether the current login surface is the hidden staff URL.
	 *
	 * @return bool
	 */
	public static function is_staff_login_context() {
		return 'staff' === self::$login_context;
	}

	/**
	 * Point staff logins at the Manage dashboard instead of wp-admin.
	 *
	 * WordPress seeds the login form's redirect_to with admin_url() when
	 * none is supplied. That survives into the POST and, without a winning
	 * login_redirect filter, drops administrators into /wp-admin/.
	 */
	public static function force_staff_login_redirect() {
		if ( ! self::is_staff_login_context() ) {
			return;
		}

		$manage = function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'manage' ) : home_url( '/manage/' );
		if ( ! $manage ) {
			$manage = home_url( '/manage/' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the login form target only.
		$current = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : '';
		$is_admin_target = ( '' === $current
			|| 'wp-admin/' === $current
			|| false !== strpos( $current, '/wp-admin' )
			|| untrailingslashit( $current ) === untrailingslashit( admin_url() ) );

		if ( $is_admin_target ) {
			$_REQUEST['redirect_to'] = $manage;
			$_GET['redirect_to']     = $manage;
			$_POST['redirect_to']    = $manage;
		}
	}

	/**
	 * Skip the periodic "confirm admin email" interstitial on the staff login.
	 *
	 * That screen rebuilds a redirect through wp_login_url() and often ends
	 * up sending administrators into wp-admin afterward.
	 *
	 * @param int $interval Seconds between checks.
	 * @return int
	 */
	public static function disable_admin_email_check_on_staff_login( $interval ) {
		if ( self::is_staff_login_context() ) {
			return 0;
		}
		return $interval;
	}

	/* ---------------------------------------------------------------------
	 * Login URLs.
	 * ------------------------------------------------------------------ */

	/**
	 * The hidden staff (administrator / shop-manager) login slug.
	 *
	 * @return string
	 */
	public static function staff_slug() {
		$slug = self::DEFAULT_STAFF_SLUG;
		if ( defined( 'SYCOMP_STAFF_LOGIN_SLUG' ) && SYCOMP_STAFF_LOGIN_SLUG ) {
			$slug = (string) SYCOMP_STAFF_LOGIN_SLUG;
		}
		/**
		 * Filter the hidden staff login slug.
		 *
		 * @param string $slug Staff login slug.
		 */
		$slug = sanitize_title( (string) apply_filters( 'sycomp_b2b_staff_login_slug', $slug ) );
		return $slug ? $slug : self::DEFAULT_STAFF_SLUG;
	}

	/**
	 * The buyer login URL — the site home page itself.
	 *
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function buyer_login_url( $scheme = null ) {
		return home_url( '/', $scheme );
	}

	/**
	 * The hidden staff login URL.
	 *
	 * @param string|null $scheme URL scheme.
	 * @return string
	 */
	public static function staff_login_url( $scheme = null ) {
		$home = home_url( '/', $scheme );
		$slug = self::staff_slug();

		if ( ! get_option( 'permalink_structure' ) ) {
			return $home . '?' . $slug;
		}
		// `user_trailingslashit()` needs the WP_Rewrite global, which may not
		// exist this early — fall back to a plain trailing slash.
		$url = $home . $slug;
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof WP_Rewrite ) {
			return user_trailingslashit( $url );
		}
		return trailingslashit( $url );
	}

	/* ---------------------------------------------------------------------
	 * Request classification.
	 * ------------------------------------------------------------------ */

	/**
	 * The relative path of the site home, without a trailing slash.
	 *
	 * @return string
	 */
	protected static function home_path() {
		return untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	}

	/**
	 * The current request path, lower-cased and without a trailing slash.
	 *
	 * @return string
	 */
	protected static function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return strtolower( untrailingslashit( $path ) );
	}

	/**
	 * Whether the request targets the site home page.
	 *
	 * @return bool
	 */
	public static function is_root_request() {
		return self::request_path() === strtolower( self::home_path() );
	}

	/**
	 * Whether the request targets the hidden staff login URL.
	 *
	 * @return bool
	 */
	public static function is_staff_request() {
		$expected = strtolower( self::home_path() . '/' . self::staff_slug() );
		if ( self::request_path() === $expected ) {
			return true;
		}
		// Plain-permalink fallback: /?sycomp-staff-xxxx.
		if ( ! get_option( 'permalink_structure' ) && isset( $_GET[ self::staff_slug() ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Request handling.
	 * ------------------------------------------------------------------ */

	/**
	 * Runs on `plugins_loaded` (priority 1). Blocks XML-RPC outright.
	 *
	 * Exception: Jetpack's connection handshake and heartbeat run over
	 * `xmlrpc.php?for=jetpack` (the standard signal Jetpack itself and every
	 * major security plugin use to tell its calls apart from pingback/XML-RPC
	 * abuse) — blocking that endpoint prevents the site from ever connecting
	 * to WordPress.com and surfaces as a 403 "transport error" in Jetpack.
	 */
	public static function block_xmlrpc() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false === stripos( (string) $uri, 'xmlrpc.php' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['for'] ) && 'jetpack' === $_GET['for'] ) {
			return;
		}
		status_header( 403 );
		nocache_headers();
		exit( 'XML-RPC services are disabled on this site.' );
	}

	/**
	 * `xmlrpc_enabled` filter — disables XML-RPC methods that require
	 * authentication (e.g. the classic wp.newPost publishing API).
	 *
	 * Exception: Jetpack's own registration/connection handshake calls
	 * WordPress core's wp_xmlrpc_server::login() directly as part of
	 * xmlrpc.php?for=jetpack (verified in Jetpack's
	 * projects/packages/connection/src/class-manager.php). That method
	 * checks this exact filter and 405s with "XML-RPC services are
	 * disabled on this site" if it resolves false — independent of, and
	 * even after fixing, block_xmlrpc()'s own request-level gate above.
	 * Forcing this unconditionally false silently broke Jetpack's
	 * handshake at the WordPress-core level regardless of that fix.
	 *
	 * @param bool $is_enabled Whether XML-RPC methods requiring auth are enabled.
	 * @return bool
	 */
	public static function filter_xmlrpc_enabled( $is_enabled ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['for'] ) && 'jetpack' === $_GET['for'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Runs on `wp_loaded`. Enforces the wp-admin lockdown and routes the
	 * two login surfaces.
	 */
	public static function handle_request() {
		global $pagenow;

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? strtolower( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		// 1. A direct hit on the real wp-login.php — make it vanish.
		if ( ! is_admin() && false !== strpos( $uri, 'wp-login.php' ) ) {
			self::not_found();
		}

		// 2. wp-admin: administrators only.
		if ( is_admin()
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& 'admin-ajax.php' !== $pagenow
			&& 'admin-post.php' !== $pagenow ) {

			if ( ! is_user_logged_in() ) {
				// Logged-out: behave as if /wp-admin/ does not exist.
				self::not_found();
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				// Logged-in non-administrator: send to the portal.
				wp_safe_redirect( self::portal_home_url() );
				exit;
			}
		}

		// 3. The hidden staff login URL.
		if ( self::is_staff_request() ) {
			if ( is_user_logged_in() ) {
				$user     = wp_get_current_user();
				$roles    = $user ? (array) $user->roles : array();
				$is_staff = in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true );
				$redirect = $is_staff
					? ( function_exists( 'sycomp_b2b_page_url' ) ? sycomp_b2b_page_url( 'manage' ) : admin_url() )
					: home_url( '/' );
				wp_safe_redirect( $redirect );
				exit;
			}
			self::serve_login( 'staff' );
		}

		// 4. The buyer login — rendered in place on the site home page,
		//    but only for logged-out visitors (or to process a logout).
		if ( self::is_root_request() ) {
			$action = '';
			if ( isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$action = sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( ! is_user_logged_in() || 'logout' === $action ) {
				self::serve_login( 'buyer' );
			}
		}
	}

	/**
	 * Hand the request to WordPress's login handler, tagged with the
	 * surface (buyer / staff) it is being served as.
	 *
	 * @param string $context 'buyer' or 'staff'.
	 */
	protected static function serve_login( $context ) {
		self::$login_context = ( 'staff' === $context ) ? 'staff' : 'buyer';

		global $pagenow, $error, $interim_login, $action, $user_login; // phpcs:ignore
		$pagenow = 'wp-login.php';

		// Jetpack's SSO module hooks `login_init` too, and — if
		// `jetpack_sso_bypass_login_forward_wpcom` resolves true (an explicit
		// add_filter() call, or a WordPress.com-side default for hosted
		// sites) — unconditionally redirects the load to wordpress.com,
		// including this internal one. That fights with the wp-login.php →
		// home/staff-URL rewriting below (filter_wp_redirect) and produces
		// an infinite bounce. Force the filter false for the duration of
		// this internal load so our own branded login surfaces stay in
		// control; on the staff surface, Jetpack's SSO button (if enabled)
		// can still render alongside the form — only the automatic redirect
		// is suppressed. On the buyer surface, SSO is suppressed entirely by
		// filter_jetpack_sso_allowed_actions() below, so this filter is
		// belt-and-suspenders there.
		// Verified against Jetpack's actual source: bypass_login_forward_wpcom()
		// in projects/packages/connection/src/sso/class-helpers.php simply
		// returns apply_filters( 'jetpack_sso_bypass_login_forward_wpcom', false ).
		add_filter( 'jetpack_sso_bypass_login_forward_wpcom', '__return_false', 999 );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * `jetpack_sso_allowed_actions` filter — restricts which login
	 * "actions" Jetpack's SSO module engages for at all (button rendering,
	 * forced-redirect check, everything). Buyers sign in with Sycomp-issued
	 * credentials only and have no WordPress.com accounts, so the
	 * "Log in with WordPress.com" button is irrelevant there. Suppress SSO
	 * entirely on the buyer login; leave the default (unchanged) on the
	 * staff surface, where an admin may actually want it.
	 *
	 * Verified against Jetpack's actual source
	 * (projects/packages/connection/src/sso/class-helpers.php
	 * display_sso_form_for_action()) — an empty array here means
	 * Jetpack_SSO::login_init() never calls display_sso_login_form()
	 * (the method that hooks the button onto `login_form`) at all.
	 *
	 * @param array $allowed_actions Actions Jetpack SSO engages for.
	 * @return array
	 */
	public static function filter_jetpack_sso_allowed_actions( $allowed_actions ) {
		if ( 'buyer' === self::$login_context ) {
			return array();
		}
		return $allowed_actions;
	}

	/**
	 * Send a bare 404 — no theme rendering, reveals nothing.
	 */
	protected static function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><title>404 Not Found</title></head><body>';
		echo '<h1>Not Found</h1><p>The requested URL was not found on this server.</p>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Where to send a logged-in non-administrator who reaches wp-admin.
	 *
	 * @return string
	 */
	protected static function portal_home_url() {
		$user = wp_get_current_user();
		if ( $user && in_array( 'shop_manager', (array) $user->roles, true )
			&& function_exists( 'sycomp_b2b_page_url' ) ) {
			$manage = sycomp_b2b_page_url( 'manage' );
			if ( $manage ) {
				return $manage;
			}
		}
		return home_url( '/' );
	}

	/* ---------------------------------------------------------------------
	 * URL rewriting — keep WordPress pointing at the right login surface.
	 * ------------------------------------------------------------------ */

	/**
	 * Rewrite any URL that still references wp-login.php to whichever login
	 * surface is in play (staff URL during a staff login, otherwise the
	 * buyer home page).
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	protected static function rewrite_wp_login( $url ) {
		if ( ! is_string( $url ) || false === strpos( $url, 'wp-login.php' ) ) {
			return $url;
		}
		$scheme = is_ssl() ? 'https' : null;
		$base   = ( 'staff' === self::$login_context )
			? self::staff_login_url( $scheme )
			: self::buyer_login_url( $scheme );

		$parts = explode( '?', $url, 2 );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			parse_str( $parts[1], $args );
			return add_query_arg( $args, $base );
		}
		return $base;
	}

	/**
	 * `site_url` filter.
	 *
	 * @param string $url     Site URL.
	 * @param string $path    Path.
	 * @param string $scheme  Scheme.
	 * @param int    $blog_id Blog ID.
	 * @return string
	 */
	public static function filter_site_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		return self::rewrite_wp_login( $url );
	}

	/**
	 * `network_site_url` filter.
	 *
	 * @param string $url    Network site URL.
	 * @param string $path   Path.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	public static function filter_network_site_url( $url, $path = '', $scheme = null ) {
		return self::rewrite_wp_login( $url );
	}

	/**
	 * `wp_redirect` filter.
	 *
	 * @param string $location Redirect target.
	 * @return string
	 */
	public static function filter_wp_redirect( $location ) {
		return self::rewrite_wp_login( $location );
	}

	/**
	 * `login_url` / `logout_url` / `lostpassword_url` filter.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	public static function filter_login_like_url( $url ) {
		return self::rewrite_wp_login( $url );
	}

	/* ---------------------------------------------------------------------
	 * XML-RPC.
	 * ------------------------------------------------------------------ */

	/**
	 * Remove the X-Pingback header.
	 *
	 * @param array $headers HTTP headers.
	 * @return array
	 */
	public static function strip_pingback_header( $headers ) {
		if ( is_array( $headers ) && isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	/* ---------------------------------------------------------------------
	 * User / author enumeration.
	 * ------------------------------------------------------------------ */

	/**
	 * Block ?author=N scans and author archive URLs that leak usernames.
	 */
	public static function block_author_enumeration() {
		if ( is_admin() ) {
			return;
		}
		$author = isset( $_GET['author'] ) ? trim( (string) wp_unslash( $_GET['author'] ) ) : ''; // phpcs:ignore WordPress.Security
		if ( '' !== $author && ctype_digit( $author ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
		if ( is_author() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Remove the REST user-listing endpoints.
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public static function restrict_users_endpoint( $endpoints ) {
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/* ---------------------------------------------------------------------
	 * REST API gate.
	 * ------------------------------------------------------------------ */

	/**
	 * Require authentication for every REST request — the portal is fully
	 * private, so there is no anonymous REST surface to expose.
	 *
	 * Exceptions:
	 *  - Jetpack's own REST routes (connection handshake, sync, IDC
	 *    resolution, etc.) authenticate each request themselves via a
	 *    signed request, not a logged-in WordPress session — some of those
	 *    calls (like the initial connection) necessarily happen with no WP
	 *    user at all. Blocking them here breaks the Jetpack connection.
	 *  - The bare REST index (`/wp-json/`) is WordPress core's public route
	 *    discovery document — it lists available namespaces/routes but no
	 *    private data. WordPress.com pings it to confirm the REST API is
	 *    reachable at all before attempting anything else; leaving it
	 *    gated behind our own auth makes the site look REST-broken.
	 *
	 * @param WP_Error|null|true $result Existing authentication result.
	 * @return WP_Error|null|true
	 */
	public static function require_rest_auth( $result ) {
		if ( ! empty( $result ) || is_wp_error( $result ) ) {
			return $result;
		}
		if ( self::is_jetpack_rest_request() || self::is_rest_index_request() ) {
			return $result;
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'sycomp_rest_forbidden',
				__( 'REST API access is restricted to signed-in portal users.', 'sycomp-b2b-portal' ),
				array( 'status' => 401 )
			);
		}
		return $result;
	}

	/**
	 * Whether the current request targets one of Jetpack's own REST routes.
	 *
	 * Verified against Jetpack's actual source (projects/packages/connection/src
	 * — class-rest-connector.php and identity-crisis/class-rest-endpoints.php):
	 * connection, sync, and identity-crisis routes all register under the
	 * single `jetpack/v4` namespace; there is no separate `jetpack-idc`
	 * namespace.
	 *
	 * @return bool
	 */
	protected static function is_jetpack_rest_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// Match the parsed path only (pretty permalinks), never the raw
		// REQUEST_URI — that also contains the query string, and a substring
		// match against it let a request to *any* other REST route bypass the
		// login requirement below just by appending "?x=/jetpack/v4" to it.
		$prefix = untrailingslashit( '/' . trim( (string) rest_get_url_prefix(), '/' ) );
		if ( preg_match( '#^' . preg_quote( $prefix, '#' ) . '/jetpack/v\d#i', $path ) ) {
			return true;
		}

		// Plain-permalink fallback: /?rest_route=/jetpack/v4/...
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['rest_route'] ) ) {
			$route = (string) wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( preg_match( '#^/jetpack/v\d#i', $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current request targets the bare REST index (the route
	 * discovery document at the API root), not a specific namespace.
	 *
	 * @return bool
	 */
	protected static function is_rest_index_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		// Pretty permalinks: /wp-json or /wp-json/ — nothing after the prefix.
		$prefix = untrailingslashit( '/' . trim( (string) rest_get_url_prefix(), '/' ) );
		if ( untrailingslashit( (string) $path ) === $prefix ) {
			return true;
		}

		// Plain permalinks: /?rest_route=/ — the root route, nothing deeper.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['rest_route'] ) && '/' === trim( (string) wp_unslash( $_GET['rest_route'] ) ) ) {
			return true;
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Brute-force throttle, error masking + role gating.
	 * ------------------------------------------------------------------ */

	/**
	 * The requesting client's IP address.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );
		return $ip ? $ip : 'unknown';
	}

	/**
	 * Transient key holding the failed-attempt count for this IP.
	 *
	 * @return string
	 */
	protected static function lockout_key() {
		return 'sycomp_login_fail_' . md5( self::client_ip() );
	}

	/**
	 * Count a failed sign-in.
	 *
	 * @param string $username Attempted username (unused).
	 */
	public static function record_failed_login( $username = '' ) {
		$key   = self::lockout_key();
		$fails = (int) get_transient( $key ) + 1;
		set_transient( $key, $fails, self::LOCKOUT_MINUTES * MINUTE_IN_SECONDS );
	}

	/**
	 * Clear the failed-attempt counter after a successful sign-in.
	 *
	 * @param string $user_login User login (unused).
	 */
	public static function clear_failed_logins( $user_login = '' ) {
		delete_transient( self::lockout_key() );
	}

	/**
	 * Block sign-in attempts from an IP that has exceeded the limit.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login_lockout( $user ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'POST' !== $method ) {
			return $user;
		}
		$fails = (int) get_transient( self::lockout_key() );
		if ( $fails >= self::LOCKOUT_LIMIT ) {
			return new WP_Error(
				'sycomp_login_locked',
				sprintf(
					/* translators: %d: number of minutes. */
					__( 'Too many failed sign-in attempts. Please wait about %d minutes and try again.', 'sycomp-b2b-portal' ),
					self::LOCKOUT_MINUTES
				)
			);
		}
		return $user;
	}

	/**
	 * Replace credential-specific login errors with a single generic
	 * message, so an attacker cannot tell whether a username exists.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public static function mask_auth_error( $user ) {
		if ( ! is_wp_error( $user ) ) {
			return $user;
		}
		$leaky = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo' );
		if ( array_intersect( $user->get_error_codes(), $leaky ) ) {
			return new WP_Error(
				'sycomp_invalid_login',
				__( 'The username or password you entered is not valid.', 'sycomp-b2b-portal' )
			);
		}
		return $user;
	}

	/**
	 * Enforce that each login surface only accepts its own roles: buyers at
	 * the home page, administrators / shop managers at the staff URL.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public static function enforce_login_context( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user; // Only gate successful authentication.
		}
		$context = self::$login_context;
		if ( 'buyer' !== $context && 'staff' !== $context ) {
			return $user; // Not one of our login surfaces.
		}

		$roles    = (array) $user->roles;
		$is_staff = in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true );

		if ( ( 'buyer' === $context && $is_staff ) || ( 'staff' === $context && ! $is_staff ) ) {
			return new WP_Error(
				'sycomp_wrong_portal',
				__( 'This account is not permitted to sign in from this page.', 'sycomp-b2b-portal' )
			);
		}
		return $user;
	}

	/* ---------------------------------------------------------------------
	 * Security headers.
	 * ------------------------------------------------------------------ */

	/**
	 * Send standard security response headers.
	 */
	public static function security_headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-XSS-Protection: 0' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}
}
