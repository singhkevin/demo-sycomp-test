<?php
/**
 * Admin: data import screen for products and per-market price lists.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_Admin_Import.
 */
class Sycomp_B2B_Admin_Import {

	const PAGE = 'sycomp-b2b-import';

	/**
	 * Holds the result/notice to render after processing.
	 *
	 * @var array
	 */
	protected static $notices = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_upload' ) );
	}

	/**
	 * Register the Import submenu.
	 */
	public static function register_menu() {
		add_submenu_page(
			Sycomp_B2B_Admin::MENU_SLUG,
			__( 'Import Data', 'sycomp-b2b-portal' ),
			__( 'Import Data', 'sycomp-b2b-portal' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Process an uploaded CSV.
	 */
	public static function maybe_handle_upload() {
		if ( empty( $_POST['sycomp_import_type'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_POST['sycomp_import_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sycomp_import_nonce'] ) ), 'sycomp_import' ) ) {
			return;
		}

		$type = sanitize_key( wp_unslash( $_POST['sycomp_import_type'] ) );

		if ( empty( $_FILES['sycomp_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['sycomp_csv']['tmp_name'] ) ) {
			self::$notices[] = array( 'error', __( 'No CSV file was uploaded.', 'sycomp-b2b-portal' ) );
			return;
		}

		$tmp  = $_FILES['sycomp_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$name = isset( $_FILES['sycomp_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['sycomp_csv']['name'] ) ) : '';

		if ( $name && ! preg_match( '/\.csv$/i', $name ) ) {
			self::$notices[] = array( 'error', __( 'Please upload a .csv file.', 'sycomp-b2b-portal' ) );
			return;
		}

		// Raise limits for a potentially long import.
		@set_time_limit( 0 ); // phpcs:ignore
		wc_set_time_limit( 0 );

		if ( 'products' === $type ) {
			$import_images = ! empty( $_POST['sycomp_import_images'] );
			$result        = Sycomp_B2B_Importer::import_products( $tmp, array( 'import_images' => $import_images ) );
			self::record_result( $result );
		} elseif ( 'prices' === $type ) {
			$market = isset( $_POST['sycomp_market'] ) ? sanitize_key( wp_unslash( $_POST['sycomp_market'] ) ) : '';
			if ( ! Sycomp_B2B_Markets::exists( $market ) ) {
				self::$notices[] = array( 'error', __( 'Please choose a valid market for the price list.', 'sycomp-b2b-portal' ) );
				return;
			}
			$result = Sycomp_B2B_Importer::import_prices( $tmp, $market );
			self::record_result( $result );
		}
	}

	/**
	 * Translate an importer result into an admin notice.
	 *
	 * @param array|WP_Error $result Importer result.
	 */
	protected static function record_result( $result ) {
		if ( is_wp_error( $result ) ) {
			self::$notices[] = array( 'error', $result->get_error_message() );
			return;
		}

		if ( 'products' === $result['type'] ) {
			$msg = sprintf(
				/* translators: 1: created, 2: updated, 3: skipped, 4: total. */
				__( 'Product import complete: %1$d created, %2$d updated, %3$d skipped (of %4$d).', 'sycomp-b2b-portal' ),
				$result['created'],
				$result['updated'],
				$result['skipped'],
				$result['total']
			);
			self::$notices[] = array( 'success', $msg );
			if ( ! empty( $result['errors'] ) ) {
				self::$notices[] = array(
					'warning',
					__( 'Some rows had problems: ', 'sycomp-b2b-portal' ) . esc_html( implode( ' | ', array_slice( $result['errors'], 0, 10 ) ) ),
				);
			}
		} elseif ( 'prices' === $result['type'] ) {
			$msg = sprintf(
				/* translators: 1: market, 2: matched, 3: cleared, 4: unmatched, 5: total. */
				__( '%1$s price import complete: %2$d priced, %3$d cleared, %4$d unmatched (of %5$d rows).', 'sycomp-b2b-portal' ),
				Sycomp_B2B_Markets::label( $result['market'] ),
				$result['matched'],
				$result['cleared'],
				$result['unmatched'],
				$result['total']
			);
			self::$notices[] = array( 'success', $msg );
			if ( ! empty( $result['misses'] ) ) {
				self::$notices[] = array(
					'warning',
					__( 'Unmatched SKUs/handles (first 20): ', 'sycomp-b2b-portal' ) . esc_html( implode( ', ', $result['misses'] ) ),
				);
			}
		}
	}

	/**
	 * Render the import screen.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'sycomp-b2b-portal' ) );
		}
		?>
		<div class="wrap sycomp-admin">
			<h1><?php esc_html_e( 'Import Data', 'sycomp-b2b-portal' ); ?></h1>

			<?php foreach ( self::$notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice[1] ); ?></p>
				</div>
			<?php endforeach; ?>

			<div class="sycomp-cards">
				<div class="sycomp-card">
					<h2><?php esc_html_e( '1. Import products', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Upload the Shopify product export CSV (e.g. shopify-upload-all-in-one.csv). Products are matched by SKU or Handle, so re-importing updates rather than duplicates.', 'sycomp-b2b-portal' ); ?></p>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'sycomp_import', 'sycomp_import_nonce' ); ?>
						<input type="hidden" name="sycomp_import_type" value="products">
						<p><input type="file" name="sycomp_csv" accept=".csv" required></p>
						<p>
							<label>
								<input type="checkbox" name="sycomp_import_images" value="1">
								<?php esc_html_e( 'Also download product images (slower — recommended via WP-CLI for large catalogues)', 'sycomp-b2b-portal' ); ?>
							</label>
						</p>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import products', 'sycomp-b2b-portal' ); ?></button></p>
					</form>
				</div>

				<div class="sycomp-card">
					<h2><?php esc_html_e( '2. Import a market price list', 'sycomp-b2b-portal' ); ?></h2>
					<p><?php esc_html_e( 'Upload one price-list CSV per market. The importer auto-detects the SKU/Handle and price columns from the header row. Import products first.', 'sycomp-b2b-portal' ); ?></p>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( 'sycomp_import', 'sycomp_import_nonce' ); ?>
						<input type="hidden" name="sycomp_import_type" value="prices">
						<p>
							<label for="sycomp_market"><?php esc_html_e( 'Market', 'sycomp-b2b-portal' ); ?></label><br>
							<select name="sycomp_market" id="sycomp_market" required>
								<option value=""><?php esc_html_e( '— Select market —', 'sycomp-b2b-portal' ); ?></option>
								<?php foreach ( Sycomp_B2B_Markets::all() as $key => $m ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $m['label'] . ' (' . $m['currency'] . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
						<p><input type="file" name="sycomp_csv" accept=".csv" required></p>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import price list', 'sycomp-b2b-portal' ); ?></button></p>
					</form>
				</div>
			</div>

			<div class="sycomp-card" style="margin-top:16px;">
				<h2><?php esc_html_e( 'WP-CLI alternative', 'sycomp-b2b-portal' ); ?></h2>
				<p><?php esc_html_e( 'For large imports (especially with images) run from the command line:', 'sycomp-b2b-portal' ); ?></p>
				<pre>wp sycomp import-products /path/to/shopify-upload-all-in-one.csv --images
wp sycomp import-prices /path/to/india.csv --market=india
wp sycomp import-all /path/to/data-folder</pre>
			</div>
		</div>
		<?php
	}
}
