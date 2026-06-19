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
		// --- Header location switcher --------------------------------------
		if ( isset( $_GET['sycomp_switch_location'] ) ) {
			$location_id = absint( wp_unslash( $_GET['sycomp_switch_location'] ) );
			$nonce       = isset( $_GET['_syc'] ) ? sanitize_text_field( wp_unslash( $_GET['_syc'] ) ) : '';

			if ( wp_verify_nonce( $nonce, 'sycomp_switch' ) ) {
				Sycomp_B2B_Context::set_active_location_id( $location_id );
			}

			wp_safe_redirect( remove_query_arg( array( 'sycomp_switch_location', '_syc' ) ) );
			exit;
		}

		// --- Homepage market selector --------------------------------------
		if ( isset( $_GET['sycomp_market'] ) ) {
			$market_key   = sanitize_key( wp_unslash( $_GET['sycomp_market'] ) );
			$catalogue    = sycomp_b2b_page_url( 'catalogue' );

			if ( ! is_user_logged_in() ) {
				// Public visitor — send to login, then back to this market.
				$after_login = add_query_arg( 'sycomp_market', $market_key, $catalogue );
				wp_safe_redirect( wp_login_url( $after_login ) );
				exit;
			}

			$location_id = Sycomp_B2B_Context::get_location_for_market( $market_key );

			if ( $location_id ) {
				Sycomp_B2B_Context::set_active_location_id( $location_id );
				wp_safe_redirect( $catalogue );
			} else {
				// The buyer's company has no location in this market.
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

		$locations = Sycomp_B2B_User::get_locations();
		if ( empty( $locations ) ) {
			echo '<span class="sy-header__user">' . esc_html__( 'No locations assigned', 'sycomp-b2b-portal' ) . '</span>';
			return;
		}

		$active_id     = Sycomp_B2B_Context::get_active_location_id();
		$active_market = Sycomp_B2B_Context::get_active_market();
		$active_post   = $active_id ? get_post( $active_id ) : null;
		$company_name  = Sycomp_B2B_User::get_company_name();
		$active_flag   = $active_market ? Sycomp_B2B_Markets::get( $active_market )['flag_url'] : '';
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
				foreach ( $locations as $location ) :
					$market_key = Sycomp_B2B_Post_Types::get_location_market( $location->ID );
					$market     = Sycomp_B2B_Markets::get( $market_key );
					$is_active  = ( (int) $location->ID === (int) $active_id );
					$switch_url = add_query_arg(
						array(
							'sycomp_switch_location' => (int) $location->ID,
							'_syc'                   => wp_create_nonce( 'sycomp_switch' ),
						)
					);
					?>
					<a class="sy-locsw__item <?php echo $is_active ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( $switch_url ); ?>" role="menuitem">
						<?php if ( $market ) : ?>
							<img class="sy-locsw__flag" src="<?php echo esc_url( $market['flag_url'] ); ?>" alt="" aria-hidden="true">
						<?php endif; ?>
						<span>
							<?php
							$item_label = get_the_title( $location );
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
		$locations = Sycomp_B2B_User::get_locations();
		if ( count( $locations ) < 2 ) {
			return;
		}
		$active_id = (int) Sycomp_B2B_Context::get_active_location_id();

		echo '<section class="sy-cartswitch">';
		echo '<h2 class="sy-cartswitch__title">' . esc_html__( 'Your location carts', 'sycomp-b2b-portal' ) . '</h2>';
		echo '<p class="sy-cartswitch__hint">' . esc_html__( "Each location keeps its own cart and is submitted as its own purchase order. Switch to review or build another location's order.", 'sycomp-b2b-portal' ) . '</p>';
		echo '<div class="sy-cartswitch__grid">';

		foreach ( $locations as $location ) {
			$lid        = (int) $location->ID;
			$count      = (int) Sycomp_B2B_Cart::get_location_item_count( $lid );
			$market_key = Sycomp_B2B_Post_Types::get_location_market( $lid );
			$market     = Sycomp_B2B_Markets::get( $market_key );
			$is_active  = ( $lid === $active_id );
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
						'sycomp_switch_location' => $lid,
						'_syc'                   => wp_create_nonce( 'sycomp_switch' ),
					)
				);
				echo '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '">';
			}

			echo '<span class="sy-cartswitch__head">';
			if ( $market ) {
				echo '<img class="sy-cartswitch__flag" src="' . esc_url( $market['flag_url'] ) . '" alt="" aria-hidden="true">';
			}
			echo '<span class="sy-cartswitch__name">' . esc_html( get_the_title( $location ) ) . '</span>';
			echo '</span>';

			echo '<span class="sy-cartswitch__meta">' . ( $market ? esc_html( $market['label'] . ' · ' . $market['currency'] ) : '' ) . '</span>';

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

		// Markets the current buyer's company actually operates in.
		$available = array();
		if ( $logged_in ) {
			foreach ( Sycomp_B2B_User::get_locations() as $location ) {
				$mk = Sycomp_B2B_Post_Types::get_location_market( $location->ID );
				if ( $mk ) {
					$available[ $mk ] = true;
				}
			}
		}

		ob_start();
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
		<?php
		return (string) ob_get_clean();
	}
}
