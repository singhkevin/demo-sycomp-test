<?php
/**
 * Catalogue — the product grid.
 *
 * Renders the [sycomp_catalogue] shortcode: a login-gated product grid
 * with a left-hand brand / category / search filter rail. Each card
 * links through to the product page. Pricing reflects the buyer's
 * active location / market.
 *
 * Shop Managers additionally get a "View catalogue as" bar that previews
 * the grid exactly as a chosen company's buyers would see it (the company's
 * product set, optionally priced in a chosen market).
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Catalogue.
 */
class Sycomp_B2B_Catalogue {

	/**
	 * Products listed per page.
	 */
	const PER_PAGE = 60;

	/**
	 * Register hooks and the shortcode.
	 */
	public static function init() {
		add_shortcode( 'sycomp_catalogue', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_sycomp_add_to_cart', array( __CLASS__, 'ajax_add_to_cart' ) );
		add_filter( 'posts_search', array( __CLASS__, 'title_only_search' ), 10, 2 );
	}

	/**
	 * Restrict a flagged query's search to the product title only.
	 *
	 * @param string   $search Search SQL.
	 * @param WP_Query $query  Query object.
	 * @return string
	 */
	public static function title_only_search( $search, $query ) {
		$term = $query->get( 'sycomp_title_search' );
		if ( ! $term ) {
			return $search;
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		return $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s ", $like );
	}

	/* ---------------------------------------------------------------------
	 * Shortcode.
	 * ------------------------------------------------------------------ */

	/**
	 * [sycomp_catalogue].
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return sycomp_b2b_login_gate( __( 'Sign in to view the Sycomp catalogue.', 'sycomp-b2b-portal' ) );
		}

		// Shop managers browse the whole catalogue (every product, no company
		// filter). Buyers see only the products enabled for their company.
		$is_manager = current_user_can( 'manage_woocommerce' );

		if ( ! $is_manager && ! Sycomp_B2B_User::is_portal_user() ) {
			return '<div class="sy-notice sy-notice--warn">'
				. esc_html__( 'Your account is not linked to a company yet. Please contact Sycomp to be set up.', 'sycomp-b2b-portal' )
				. '</div>';
		}

		// --- Filters from the request -------------------------------------
		$brand  = isset( $_GET['sy_brand'] ) ? sanitize_title( wp_unslash( $_GET['sy_brand'] ) ) : '';        // phpcs:ignore WordPress.Security.NonceVerification
		$cat    = isset( $_GET['sy_cat'] ) ? sanitize_title( wp_unslash( $_GET['sy_cat'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['sy_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sy_q'] ) ) : '';           // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['sy_page'] ) ? max( 1, absint( wp_unslash( $_GET['sy_page'] ) ) ) : 1;         // phpcs:ignore WordPress.Security.NonceVerification

		// --- Manager "view as company" preview ----------------------------
		$as_company     = 0;
		$preview_market = '';
		if ( $is_manager ) {
			$as_company = isset( $_GET['sy_as'] ) ? absint( wp_unslash( $_GET['sy_as'] ) ) : 0;     // phpcs:ignore WordPress.Security.NonceVerification
			if ( $as_company ) {
				$co = get_post( $as_company );
				if ( ! $co || Sycomp_B2B_Post_Types::COMPANY !== $co->post_type ) {
					$as_company = 0;
				}
			}
			$preview_market = isset( $_GET['sy_mk'] ) ? sanitize_key( wp_unslash( $_GET['sy_mk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( $preview_market && ! Sycomp_B2B_Markets::exists( $preview_market ) ) {
				$preview_market = '';
			}
		}

		// Query args that must travel with the filter links / pagination so a
		// manager's preview is not lost when they also filter by brand etc.
		$extra = array();
		if ( $as_company ) {
			$extra['sy_as'] = $as_company;
		}
		if ( $preview_market ) {
			$extra['sy_mk'] = $preview_market;
		}

		// Capture the catalogue page URL now — once the product loop runs,
		// get_permalink() would return the current product's URL instead.
		$page_url = get_permalink();

		// Which market prices the grid shows: the buyer's active market, or
		// (for a manager) the market chosen in the preview bar.
		$grid_market = $is_manager ? $preview_market : Sycomp_B2B_Context::get_active_market();

		$query = self::query_products( $brand, $cat, $search, $paged, $as_company, $grid_market );

		ob_start();

		if ( function_exists( 'wc_print_notices' ) && WC()->session ) {
			echo '<div class="sy-wc-notices">';
			wc_print_notices();
			echo '</div>';
		}

		// Market-unavailable notice (set by the homepage flag selector).
		if ( isset( $_GET['sycomp_market_unavailable'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$mk = Sycomp_B2B_Markets::label( sanitize_key( wp_unslash( $_GET['sycomp_market_unavailable'] ) ) );
			echo '<div class="sy-notice sy-notice--warn">';
			/* translators: %s: market name. */
			printf( esc_html__( 'Your company has no location in %s. Showing your current location instead.', 'sycomp-b2b-portal' ), esc_html( $mk ) );
			echo '</div>';
		}

		// Manager-only "view catalogue as company" bar.
		if ( $is_manager ) {
			self::render_manager_bar( $as_company, $preview_market, $page_url, $brand, $cat, $search );
		}

		echo '<div class="sy-cat-layout">';
		echo '<button type="button" class="sy-cat-filters-toggle" aria-expanded="false" aria-controls="sy-cat-sidebar">';
		echo '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="10" y1="18" x2="14" y2="18"/></svg>';
		echo '<span>' . esc_html__( 'Filters', 'sycomp-b2b-portal' ) . '</span>';
		echo '</button>';
		self::render_filter_sidebar( $brand, $cat, $search, $page_url, $extra );
		echo '<div class="sy-cat-main">';
		self::render_grid( $query, $grid_market, $page_url, $is_manager );
		self::render_pagination( $query, $paged, $brand, $cat, $search, $page_url, $extra );
		echo '</div>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Build the product query.
	 *
	 * @param string $brand      Brand slug.
	 * @param string $cat        Category slug.
	 * @param string $search     Name search.
	 * @param int    $paged      Page number.
	 * @param int    $as_company Manager preview: restrict to this company's products.
	 * @param string $market_key Market to check for pricing.
	 * @return WP_Query
	 */
	protected static function query_products( $brand, $cat, $search, $paged, $as_company = 0, $market_key = '' ) {
		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => self::PER_PAGE,
			'paged'               => $paged,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		// Per-company visibility. Buyers always see only their company's
		// products. A Shop Manager sees the full catalogue, unless they have
		// picked a company in the "view as" bar — then they see that company's
		// product set, exactly as its buyers would.
		$is_manager = current_user_can( 'manage_woocommerce' );
		$filter_company = 0;
		if ( $is_manager ) {
			$filter_company = (int) $as_company;
		} else {
			$filter_company = (int) Sycomp_B2B_User::get_company();
		}
		if ( $filter_company ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}
			$args['meta_query'][] = array(
				'key'   => '_sycomp_company',
				'value' => $filter_company,
			);
		}

		if ( $market_key && Sycomp_B2B_Markets::exists( $market_key ) ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}
			$args['meta_query'][] = array(
				'key'     => Sycomp_B2B_Markets::price_meta_key( $market_key ),
				'value'   => '',
				'compare' => '!=',
			);
		}

		$tax_query = array();
		if ( $brand ) {
			$tax_query[] = array(
				'taxonomy' => Sycomp_B2B_Post_Types::TAX_BRAND,
				'field'    => 'slug',
				'terms'    => $brand,
			);
		}
		if ( $cat ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $cat,
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		if ( $search ) {
			$args['sycomp_title_search'] = $search;
		}

		return new WP_Query( $args );
	}

	/* ---------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * The manager-only "view catalogue as company" bar.
	 *
	 * @param int    $as_company     Selected company ID.
	 * @param string $preview_market Selected market key.
	 * @param string $page_url       Catalogue page URL.
	 * @param string $brand          Active brand filter (preserved).
	 * @param string $cat            Active category filter (preserved).
	 * @param string $search         Active search term (preserved).
	 */
	protected static function render_manager_bar( $as_company, $preview_market, $page_url, $brand, $cat, $search ) {
		$companies = Sycomp_B2B_Post_Types::get_companies();
		?>
		<form class="sy-adm-filters sy-cat-asbar" method="get" action="<?php echo esc_url( $page_url ); ?>">
			<span class="sy-cat-asbar__label"><?php esc_html_e( 'View catalogue as', 'sycomp-b2b-portal' ); ?></span>
			<select name="sy_as" aria-label="<?php esc_attr_e( 'Company', 'sycomp-b2b-portal' ); ?>" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All products (management view)', 'sycomp-b2b-portal' ); ?></option>
				<?php foreach ( $companies as $company ) : ?>
					<option value="<?php echo esc_attr( $company->ID ); ?>" <?php selected( $as_company, $company->ID ); ?>>
						<?php echo esc_html( get_the_title( $company ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<select name="sy_mk" aria-label="<?php esc_attr_e( 'Market pricing', 'sycomp-b2b-portal' ); ?>" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'No pricing', 'sycomp-b2b-portal' ); ?></option>
				<?php foreach ( Sycomp_B2B_Markets::all() as $key => $market ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $preview_market, $key ); ?>>
						<?php echo esc_html( $market['label'] . ' — ' . $market['currency'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $brand ) : ?>
				<input type="hidden" name="sy_brand" value="<?php echo esc_attr( $brand ); ?>">
			<?php endif; ?>
			<?php if ( $cat ) : ?>
				<input type="hidden" name="sy_cat" value="<?php echo esc_attr( $cat ); ?>">
			<?php endif; ?>
			<?php if ( $search ) : ?>
				<input type="hidden" name="sy_q" value="<?php echo esc_attr( $search ); ?>">
			<?php endif; ?>
			<noscript><button type="submit" class="sy-btn sy-btn--primary sy-btn--sm"><?php esc_html_e( 'Apply', 'sycomp-b2b-portal' ); ?></button></noscript>
			<span class="sy-cat-asbar__hint">
				<?php esc_html_e( 'Preview the products and prices a company’s buyers see.', 'sycomp-b2b-portal' ); ?>
			</span>
		</form>
		<?php
	}

	/**
	 * Build a catalogue URL carrying the given filter values.
	 *
	 * @param string $page_url Catalogue page URL.
	 * @param string $brand    Brand slug ('' to clear).
	 * @param string $cat      Category slug ('' to clear).
	 * @param string $search   Search term ('' to clear).
	 * @param array  $extra    Extra query args to keep (e.g. manager preview).
	 * @return string
	 */
	protected static function filter_link( $page_url, $brand, $cat, $search, $extra = array() ) {
		$args = array_filter(
			array(
				'sy_brand' => $brand,
				'sy_cat'   => $cat,
				'sy_q'     => $search,
			),
			'strlen'
		);
		$args = array_merge( $args, (array) $extra );
		return $args ? add_query_arg( $args, $page_url ) : $page_url;
	}

	/**
	 * The left-hand filter rail: search, brand list, category list.
	 *
	 * @param string $brand    Selected brand slug.
	 * @param string $cat      Selected category slug.
	 * @param string $search   Current search term.
	 * @param string $page_url Catalogue page URL.
	 * @param array  $extra    Extra query args to preserve (manager preview).
	 */
	protected static function render_filter_sidebar( $brand, $cat, $search, $page_url, $extra = array() ) {
		$brands = get_terms(
			array(
				'taxonomy'   => Sycomp_B2B_Post_Types::TAX_BRAND,
				'hide_empty' => true,
			)
		);
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		?>
		<aside class="sy-cat-sidebar" id="sy-cat-sidebar">
			<form class="sy-filter-search" method="get" action="<?php echo esc_url( $page_url ); ?>">
				<label class="sy-filter__label" for="sy_q"><?php esc_html_e( 'Search', 'sycomp-b2b-portal' ); ?></label>
				<div class="sy-filter-search__row">
					<input type="search" id="sy_q" name="sy_q" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Product name...', 'sycomp-b2b-portal' ); ?>">
					<button type="submit" class="sy-btn sy-btn--primary sy-btn--sm"><?php esc_html_e( 'Go', 'sycomp-b2b-portal' ); ?></button>
				</div>
				<?php if ( $brand ) : ?>
					<input type="hidden" name="sy_brand" value="<?php echo esc_attr( $brand ); ?>">
				<?php endif; ?>
				<?php if ( $cat ) : ?>
					<input type="hidden" name="sy_cat" value="<?php echo esc_attr( $cat ); ?>">
				<?php endif; ?>
				<?php foreach ( (array) $extra as $sy_k => $sy_v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $sy_k ); ?>" value="<?php echo esc_attr( $sy_v ); ?>">
				<?php endforeach; ?>
			</form>

			<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
			<div class="sy-filter-group">
				<h3 class="sy-filter__label"><?php esc_html_e( 'Brand', 'sycomp-b2b-portal' ); ?></h3>
				<ul class="sy-filter-list">
					<li>
						<a class="<?php echo $brand ? '' : 'is-active'; ?>"
							href="<?php echo esc_url( self::filter_link( $page_url, '', $cat, $search, $extra ) ); ?>">
							<?php esc_html_e( 'All brands', 'sycomp-b2b-portal' ); ?>
						</a>
					</li>
					<?php if ( ! is_wp_error( $brands ) ) : ?>
						<?php foreach ( $brands as $term ) : ?>
							<li>
								<a class="<?php echo ( $brand === $term->slug ) ? 'is-active' : ''; ?>"
									href="<?php echo esc_url( self::filter_link( $page_url, $term->slug, $cat, $search, $extra ) ); ?>">
									<?php echo esc_html( $term->name ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>

			<div class="sy-filter-group">
				<h3 class="sy-filter__label"><?php esc_html_e( 'Product category', 'sycomp-b2b-portal' ); ?></h3>
				<ul class="sy-filter-list sy-filter-list--cats">
					<li>
						<?php
						$all_icon = sycomp_b2b_catalogue_category_icon_url( 'view_all_categories' );
						?>
						<a class="<?php echo $cat ? '' : 'is-active'; ?>"
							href="<?php echo esc_url( self::filter_link( $page_url, $brand, '', $search, $extra ) ); ?>">
							<?php if ( $all_icon ) : ?>
								<img class="sy-filter-list__icon" src="<?php echo esc_url( $all_icon ); ?>" alt="" width="20" height="20" aria-hidden="true" decoding="async">
							<?php endif; ?>
							<span><?php esc_html_e( 'All categories', 'sycomp-b2b-portal' ); ?></span>
						</a>
					</li>
					<?php if ( ! is_wp_error( $cats ) ) : ?>
						<?php foreach ( $cats as $term ) : ?>
							<?php
							$cat_icon = sycomp_b2b_catalogue_category_icon_url( $term->slug );
							if ( ! $cat_icon ) {
								// Fallback: slugify the term name (e.g. "Monitors & Displays").
								$cat_icon = sycomp_b2b_catalogue_category_icon_url( sanitize_title( $term->name ) );
							}
							?>
							<li>
								<a class="<?php echo ( $cat === $term->slug ) ? 'is-active' : ''; ?>"
									href="<?php echo esc_url( self::filter_link( $page_url, $brand, $term->slug, $search, $extra ) ); ?>">
									<?php if ( $cat_icon ) : ?>
										<img class="sy-filter-list__icon" src="<?php echo esc_url( $cat_icon ); ?>" alt="" width="20" height="20" aria-hidden="true" decoding="async">
									<?php endif; ?>
									<span><?php echo esc_html( $term->name ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>

			<?php if ( $brand || $cat || $search ) : ?>
				<a class="sy-btn sy-btn--ghost sy-btn--sm sy-filter-clear" href="<?php echo esc_url( self::filter_link( $page_url, '', '', '', $extra ) ); ?>">
					<?php esc_html_e( 'Clear all filters', 'sycomp-b2b-portal' ); ?>
				</a>
			<?php endif; ?>
		</aside>
		<?php
	}

	/**
	 * The product grid.
	 *
	 * @param WP_Query $query      Product query.
	 * @param string   $market     Market key prices are shown in ('' = no price).
	 * @param string   $page_url   Catalogue page URL.
	 * @param bool     $is_manager Whether the viewer is a shop manager (browse-only view).
	 */
	protected static function render_grid( $query, $market, $page_url, $is_manager = false ) {
		if ( ! $query->have_posts() ) {
			echo '<div class="sy-notice">' . esc_html__( 'No products match your filters.', 'sycomp-b2b-portal' ) . '</div>';
			return;
		}

		// Pricing shows whenever a market is in context — the buyer's active
		// market, or the market a manager picked in the "view as" bar.
		$show_price = (bool) $market;

		echo '<div class="sy-catalogue__count">';
		/* translators: %s: product count. */
		printf( esc_html__( '%s products', 'sycomp-b2b-portal' ), esc_html( number_format_i18n( $query->found_posts ) ) );
		echo '</div>';

		echo '<div class="sy-prod-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( $product ) {
				self::render_card( $product, $market, $show_price, $page_url, $is_manager );
			}
		}
		wp_reset_postdata();
		echo '</div>';
	}

	/**
	 * A single product card.
	 *
	 * @param WC_Product $product    Product.
	 * @param string     $market     Active market key.
	 * @param bool       $show_price Whether to show pricing.
	 * @param string     $page_url   Catalogue page URL (add-to-cart form target).
	 * @param bool       $is_manager Whether the viewer is a shop manager (browse-only).
	 */
	protected static function render_card( $product, $market, $show_price, $page_url, $is_manager = false ) {
		$product_id = $product->get_id();
		$permalink  = get_permalink( $product_id );
		$brand      = wp_get_post_terms( $product_id, Sycomp_B2B_Post_Types::TAX_BRAND, array( 'fields' => 'names' ) );
		$cats       = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$brand_txt  = ( ! is_wp_error( $brand ) && $brand ) ? implode( ', ', $brand ) : '';
		$cat_txt    = ( ! is_wp_error( $cats ) && $cats ) ? implode( ', ', $cats ) : '';
		$tag        = trim( $brand_txt . ( ( $brand_txt && $cat_txt ) ? ' · ' : '' ) . $cat_txt );
		$priced     = ! $market || Sycomp_B2B_Pricing::is_priced_in_market( $product_id, $market );
		?>
		<div class="sy-prod-card">
			<a class="sy-prod-card__media" href="<?php echo esc_url( $permalink ); ?>">
				<?php
				if ( has_post_thumbnail( $product_id ) ) {
					echo get_the_post_thumbnail( $product_id, array( 320, 320 ) );
				} else {
					echo '<span class="sy-prod-card__ph">' . sycomp_b2b_placeholder_icon() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</a>
			<div class="sy-prod-card__body">
				<?php if ( $tag ) : ?>
					<span class="sy-prod-card__tag"><?php echo esc_html( $tag ); ?></span>
				<?php endif; ?>
				<a class="sy-prod-card__name" href="<?php echo esc_url( $permalink ); ?>">
					<?php echo esc_html( $product->get_name() ); ?>
				</a>
				<?php if ( $product->get_sku() ) : ?>
					<span class="sy-prod-card__sku"><?php echo esc_html( $product->get_sku() ); ?></span>
				<?php endif; ?>
				<div class="sy-prod-card__foot">
					<?php if ( $show_price ) : ?>
						<span class="sy-prod-card__price">
							<?php
							if ( $is_manager ) {
								// Manager preview: price explicitly in the chosen market.
								echo wp_kses_post(
									Sycomp_B2B_Pricing::format_in_market(
										Sycomp_B2B_Pricing::get_effective_price( $product_id, $market ),
										$market
									)
								);
							} else {
								echo wp_kses_post( $product->get_price_html() );
							}
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php if ( $priced && ! $is_manager ) : ?>
					<form class="sy-addform" method="post" action="<?php echo esc_url( $page_url ); ?>">
						<input type="number" class="sy-qty" name="quantity" value="1" min="1" step="1"
							aria-label="<?php esc_attr_e( 'Quantity', 'sycomp-b2b-portal' ); ?>">
						<button type="submit" class="sy-btn sy-btn--accent sy-btn--sm sy-addbtn"
							name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>"
							data-product="<?php echo esc_attr( $product_id ); ?>">
							<?php esc_html_e( 'Add to cart', 'sycomp-b2b-portal' ); ?>
						</button>
					</form>
				<?php else : ?>
					<a class="sy-btn sy-btn--ghost sy-btn--sm sy-prod-card__view" href="<?php echo esc_url( $permalink ); ?>">
						<?php esc_html_e( 'View product', 'sycomp-b2b-portal' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Pagination controls.
	 *
	 * @param WP_Query $query    Product query.
	 * @param int      $paged    Current page.
	 * @param string   $brand    Brand filter.
	 * @param string   $cat      Category filter.
	 * @param string   $search   Search filter.
	 * @param string   $page_url Catalogue page URL.
	 * @param array    $extra    Extra query args to keep (manager preview).
	 */
	protected static function render_pagination( $query, $paged, $brand, $cat, $search, $page_url, $extra = array() ) {
		$total = (int) $query->max_num_pages;
		if ( $total < 2 ) {
			return;
		}
		$keep = array_filter(
			array(
				'sy_brand' => $brand,
				'sy_cat'   => $cat,
				'sy_q'     => $search,
			)
		);
		$keep = array_merge( $keep, (array) $extra );
		echo '<nav class="sy-pagination" aria-label="' . esc_attr__( 'Catalogue pages', 'sycomp-b2b-portal' ) . '">';
		for ( $i = 1; $i <= $total; $i++ ) {
			$url = add_query_arg( array_merge( $keep, array( 'sy_page' => $i ) ), $page_url );
			if ( $i === $paged ) {
				echo '<span class="sy-pagination__item is-current">' . esc_html( $i ) . '</span>';
			} else {
				echo '<a class="sy-pagination__item" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>';
			}
		}
		echo '</nav>';
	}

	/* ---------------------------------------------------------------------
	 * AJAX add to cart.
	 * ------------------------------------------------------------------ */

	/**
	 * AJAX handler: add a product to the active location's cart.
	 */
	public static function ajax_add_to_cart() {
		check_ajax_referer( 'sycomp_catalogue', 'nonce' );

		if ( ! is_user_logged_in() || ! Sycomp_B2B_User::is_portal_user() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in to order.', 'sycomp-b2b-portal' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'sycomp-b2b-portal' ) ) );
		}

		$market = Sycomp_B2B_Context::get_active_market();
		if ( ! $market ) {
			wp_send_json_error( array( 'message' => __( 'Select a location before ordering.', 'sycomp-b2b-portal' ) ) );
		}
		if ( ! Sycomp_B2B_Pricing::is_priced_in_market( $product_id, $market ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is not available in your market.', 'sycomp-b2b-portal' ) ) );
		}

		$added = WC()->cart->add_to_cart( $product_id, $quantity );
		if ( ! $added ) {
			wp_send_json_error( array( 'message' => __( 'Could not add this product to the cart.', 'sycomp-b2b-portal' ) ) );
		}

		wp_send_json_success(
			array(
				'count'    => WC()->cart->get_cart_contents_count(),
				'cart_url' => wc_get_cart_url(),
				/* translators: 1: quantity, 2: product name. */
				'message'  => sprintf( __( 'Added %1$d x %2$s', 'sycomp-b2b-portal' ), $quantity, $product->get_name() ),
			)
		);
	}
}
