<?php
/**
 * Storefront context UI: the header location switcher and the homepage
 * market/country selector, plus the request handlers that action them.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Storefront.
 */
class Sycomp_B2B_Storefront {

	/**
	 * Register hooks and shortcodes.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_requests' ), 5 );
		add_shortcode( 'sycomp_market_selector', array( __CLASS__, 'market_selector_shortcode' ) );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_cart_locations' ), 5 );
		add_action( 'woocommerce_cart_is_empty', array( __CLASS__, 'render_cart_locations' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Request handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a header location switch and homepage market jumps.
	 */
	public static function handle_requests() {
		// --- Header market switcher --------------------------------------
		if ( isset( $_GET['sycomp_switch_market'] ) ) {
			$market_key = sanitize_key( wp_unslash( $_GET['sycomp_switch_market'] ) );
			$nonce      = isset( $_GET['_syc'] ) ? sanitize_text_field( wp_unslash( $_GET['_syc'] ) ) : '';

			if ( wp_verify_nonce( $nonce, 'sycomp_switch' ) ) {
				Sycomp_B2B_Context::set_active_market( $market_key );
			}

			wp_safe_redirect( remove_query_arg( array( 'sycomp_switch_market', '_syc' ) ) );
			exit;
		}

		// --- Homepage market selector --------------------------------------
		if ( isset( $_GET['sycomp_market'] ) ) {
			$market_key = sanitize_key( wp_unslash( $_GET['sycomp_market'] ) );
			$catalogue  = sycomp_b2b_page_url( 'catalogue' );

			if ( ! is_user_logged_in() ) {
				// Public visitor — send to login, then back to this market.
				$after_login = add_query_arg( 'sycomp_market', $market_key, $catalogue );
				wp_safe_redirect( wp_login_url( $after_login ) );
				exit;
			}

			if ( Sycomp_B2B_Context::set_active_market( $market_key ) ) {
				wp_safe_redirect( $catalogue );
			} else {
				// The buyer's company does not have access to this market (no priced products).
				wp_safe_redirect( add_query_arg( 'sycomp_market_unavailable', $market_key, $catalogue ) );
			}
			exit;
		}
	}

	/* ---------------------------------------------------------------------
	 * Location switcher (header).
	 * ------------------------------------------------------------------ */

	/**
	 * Render the header location switcher.
	 */
	public static function render_location_switcher() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$company_id = Sycomp_B2B_User::get_company( $user_id );
		$markets    = Sycomp_B2B_Markets::get_available_markets( $company_id );
		
		if ( empty( $markets ) ) {
			echo '<span class="sy-header__user">' . esc_html__( 'No markets available', 'sycomp-b2b-portal' ) . '</span>';
			return;
		}

		$active_market = Sycomp_B2B_Context::get_active_market();
		$company_name  = Sycomp_B2B_User::get_company_name();
		$active_flag   = $active_market && Sycomp_B2B_Markets::exists( $active_market ) ? Sycomp_B2B_Markets::get( $active_market )['flag_url'] : '';
		?>
		<div class="sy-locsw">
			<button type="button" class="sy-locsw__btn">
				<?php if ( $active_flag ) : ?>
					<img class="sy-locsw__flag" src="<?php echo esc_url( $active_flag ); ?>" alt="" aria-hidden="true">
				<?php endif; ?>
				<span class="sy-locsw__label">
					<?php
					$active_label = __( 'Select market', 'sycomp-b2b-portal' );
					if ( $active_market ) {
						$m_details = Sycomp_B2B_Markets::get( $active_market );
						if ( $m_details ) {
							$active_label = $m_details['label'] . ' (' . $m_details['currency'] . ')';
						}
					}
					echo esc_html( $active_label );
					?>
				</span>
				<span class="sy-locsw__caret" aria-hidden="true">▾</span>
			</button>
			<div class="sy-locsw__menu" role="menu">
				<div class="sy-locsw__group-label">
					<?php
					/* translators: %s: company name. */
					printf( esc_html__( '%s — switch market', 'sycomp-b2b-portal' ), esc_html( $company_name ) );
					?>
				</div>
				<?php
				foreach ( $markets as $market_key ) :
					$market     = Sycomp_B2B_Markets::get( $market_key );
					$is_active  = ( $market_key === $active_market );
					$switch_url = add_query_arg(
						array(
							'sycomp_switch_market' => $market_key,
							'_syc'                 => wp_create_nonce( 'sycomp_switch' ),
						)
					);
					?>
					<a class="sy-locsw__item <?php echo $is_active ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( $switch_url ); ?>" role="menuitem">
						<?php if ( $market && ! empty( $market['flag_url'] ) ) : ?>
							<img class="sy-locsw__flag" src="<?php echo esc_url( $market['flag_url'] ); ?>" alt="" aria-hidden="true">
						<?php endif; ?>
						<span>
							<?php
							$item_label = '';
							if ( $market ) {
								$item_label = $market['label'] . ' (' . $market['currency'] . ')';
							}
							echo esc_html( $item_label );
							?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Cart page: per-location cart switcher.
	 * ------------------------------------------------------------------ */

	/**
	 * On the cart page, list every location's cart and its item count so a
	 * buyer can move between location carts without losing track of which
	 * ones hold products.
	 */
	public static function render_cart_locations() {
		if ( ! is_user_logged_in() || ! Sycomp_B2B_User::is_portal_user() ) {
			return;
		}

		$user_id    = get_current_user_id();
		$company_id = Sycomp_B2B_User::get_company( $user_id );
		$markets    = Sycomp_B2B_Markets::get_available_markets( $company_id );

		if ( count( $markets ) < 2 ) {
			return;
		}
		
		$active_market = Sycomp_B2B_Context::get_active_market();

		echo '<section class="sy-cartswitch">';
		echo '<h2 class="sy-cartswitch__title">' . esc_html__( 'Your market carts', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<p class="sy-cartswitch__hint">' . esc_html__( "Each market keeps its own cart. Switch to review or build another market's order.", 'sycomp-b2b-portal' ) . '</p>';
		echo '<div class="sy-cartswitch__grid">';

		foreach ( $markets as $market_key ) {
			$count      = (int) Sycomp_B2B_Cart::get_market_item_count( $market_key );
			$market     = Sycomp_B2B_Markets::get( $market_key );
			$is_active  = ( $market_key === $active_market );
			$has_items  = ( $count > 0 );

			$classes = 'sy-cartswitch__card';
			if ( $is_active ) {
				$classes .= ' is-active';
			}
			$classes .= $has_items ? ' has-items' : ' is-empty';

			if ( $is_active ) {
				echo '<div class="' . esc_attr( $classes ) . '">';
			} else {
				$url = add_query_arg(
					array(
						'sycomp_switch_market' => $market_key,
						'_syc'                 => wp_create_nonce( 'sycomp_switch' ),
					)
				);
				echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '">';
			}

			echo '<span class="sy-cartswitch__head">';
			if ( $market && ! empty( $market['flag_url'] ) ) {
				echo '<img class="sy-cartswitch__flag" src="' . esc_url( $market['flag_url'] ) . '" alt="" aria-hidden="true">';
			}
			$name = $market ? $market['label'] : $market_key;
			echo '<span class="sy-cartswitch__name">' . esc_html( $name ) . '</span>';
			echo '</span>';

			echo '<span class="sy-cartswitch__meta">' . ( $market ? esc_html( $market['currency'] ) : '' ) . '</span>';

			echo '<span class="sy-cartswitch__foot">';
			if ( $has_items ) {
				echo '<span class="sy-cartswitch__count">' . sprintf(
					/* translators: %s: item count. */
					esc_html( _n( '%s item', '%s items', $count, 'sycomp-b2b-portal' ) ),
					esc_html( number_format_i18n( $count ) )
				) . '</span>';
			} else {
				echo '<span class="sy-cartswitch__count">' . esc_html__( 'Empty', 'sycomp-b2b-portal' ) . '</span>';
			}
			echo '<span class="sy-cartswitch__action">' . ( $is_active ? esc_html__( 'Current cart', 'sycomp-b2b-portal' ) : esc_html__( 'Switch', 'sycomp-b2b-portal' ) ) . '</span>';
			echo '</span>';

			echo $is_active ? '</div>' : '</a>';
		}

		echo '</div></section>';
	}

	/* ---------------------------------------------------------------------
	 * Homepage market selector.
	 * ------------------------------------------------------------------ */

	/**
	 * [sycomp_market_selector] — full-viewport flag grid for all 9 markets.
	 *
	 * @return string
	 */
	public static function market_selector_shortcode() {
		$markets       = Sycomp_B2B_Markets::all();
		$catalogue_url = sycomp_b2b_page_url( 'catalogue' );
		$logged_in     = is_user_logged_in();

		// Markets the current buyer's company actually operates in (has priced products).
		$available = array();
		if ( $logged_in ) {
			$available_keys = Sycomp_B2B_Markets::get_available_markets( Sycomp_B2B_User::get_company() );
			foreach ( $available_keys as $k ) {
				$available[ $k ] = true;
			}
		}

		$is_manager    = is_user_logged_in() && current_user_can( 'manage_woocommerce' );

		ob_start();
		
		if ( $is_manager ) :
		?>
		<section class="sy-marketsel">
			<div class="sy-marketsel__inner">
				<header class="sy-marketsel__head">
					<h1><?php esc_html_e( 'Sycomp Procurement Portal', 'sycomp-b2b-portal' ); ?></h1>
					<p>
						<?php
						echo $logged_in
							? esc_html__( 'Select a market to browse its catalogue and pricing.', 'sycomp-b2b-portal' )
							: esc_html__( 'Select a market to sign in and browse its catalogue.', 'sycomp-b2b-portal' );
						?>
					</p>
				</header>

				<?php
					// Only the markets enabled for this buyer's company are shown.
					$sycomp_visible = array();
					foreach ( $markets as $key => $market ) {
						if ( ! $logged_in || isset( $available[ $key ] ) ) {
							$sycomp_visible[ $key ] = $market;
						}
					}
				?>
				<?php if ( $logged_in && empty( $sycomp_visible ) ) : ?>
					<p class="sy-marketsel__empty"><?php esc_html_e( 'No markets have been enabled for your company yet. Please contact your Sycomp representative.', 'sycomp-b2b-portal' ); ?></p>
				<?php else : ?>
					<div class="sy-market-grid">
						<?php foreach ( $sycomp_visible as $key => $market ) :
							$jump_url = add_query_arg( 'sycomp_market', $key, $catalogue_url );
							?>
						<a class="sy-market-card" href="<?php echo esc_url( $jump_url ); ?>">
							<img class="sy-market-card__flag" src="<?php echo esc_url( $market['flag_url'] ); ?>"
								alt="<?php echo esc_attr( $market['label'] ); ?>">
							<span class="sy-market-card__name"><?php echo esc_html( $market['label'] ); ?></span>
							<span class="sy-market-card__cur"><?php echo esc_html( $market['currency'] ); ?></span>
						</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $logged_in ) : ?>
					<p class="sy-marketsel__signin">
						<a class="sy-btn sy-btn--accent" href="<?php echo esc_url( wp_login_url( $catalogue_url ) ); ?>">
							<?php esc_html_e( 'Sign in to the portal', 'sycomp-b2b-portal' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</section>
		<?php else : ?>
		<section class="sy-marketsel-layout">
			<div class="sy-container sy-storefront-banner-wrap">
				<div class="sy-storefront-banner">
					<div class="sy-storefront-banner__content">
						<h1 class="sr-only"><?php esc_html_e( 'Welcome to the Sycomp Storefront', 'sycomp-b2b-portal' ); ?></h1>
						<p class="sr-only"><?php esc_html_e( 'The Right Technology. Right When You Need It.', 'sycomp-b2b-portal' ); ?></p>
						<span class="sr-only"><?php esc_html_e( 'Select a market to browse', 'sycomp-b2b-portal' ); ?></span>
					</div>
				</div>
			</div>

			<div class="sy-market-selector-container">
				<div class="sy-marketsel__inner">
					<?php
						// Only the markets enabled for this buyer's company are shown.
						$sycomp_visible = array();
						foreach ( $markets as $key => $market ) {
							if ( ! $logged_in || isset( $available[ $key ] ) ) {
								$sycomp_visible[ $key ] = $market;
							}
						}
					?>
					<?php if ( $logged_in && empty( $sycomp_visible ) ) : ?>
						<p class="sy-marketsel__empty"><?php esc_html_e( 'No markets have been enabled for your company yet. Please contact your Sycomp representative.', 'sycomp-b2b-portal' ); ?></p>
					<?php else : ?>
						<div class="sy-market-grid">
							<?php foreach ( $sycomp_visible as $key => $market ) :
								$jump_url = add_query_arg( 'sycomp_market', $key, $catalogue_url );
								?>
							<a class="sy-market-card" href="<?php echo esc_url( $jump_url ); ?>">
								<div class="sy-market-card__flag-wrapper">
									<img class="sy-market-card__flag" src="<?php echo esc_url( $market['flag_url'] ); ?>"
										alt="<?php echo esc_attr( $market['label'] ); ?>">
								</div>
								<div class="sy-market-card__info">
									<span class="sy-market-card__name"><?php echo esc_html( $market['label'] ); ?></span>
									<span class="sy-market-card__cur"><?php echo esc_html( $market['currency'] ); ?></span>
								</div>
								<div class="sy-market-card__chevron">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="sy-chevron-icon"><polyline points="9 18 15 12 9 6"></polyline></svg>
								</div>
							</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! $logged_in ) : ?>
						<p class="sy-marketsel__signin">
							<a class="sy-btn sy-btn--accent" href="<?php echo esc_url( wp_login_url( $catalogue_url ) ); ?>">
								<?php esc_html_e( 'Sign in to the portal', 'sycomp-b2b-portal' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php endif; ?>
		<?php
		return (string) ob_get_clean();
	}
}
