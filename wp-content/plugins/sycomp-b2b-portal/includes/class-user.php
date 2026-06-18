<?php
/**
 * User <-> Company association.
 *
 * Every portal buyer belongs to exactly one company and can see (and switch
 * between) all of that company's locations.
 *
 * @package Sycomp_B2B_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sycomp_B2B_User.
 */
class Sycomp_B2B_User {

	/**
	 * User meta key holding the company post ID.
	 */
	const META_COMPANY = '_sycomp_company_id';

	/**
	 * The company post ID a user belongs to.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return int Company post ID, or 0 if none.
	 */
	public static function get_company( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		return (int) get_user_meta( $user_id, self::META_COMPANY, true );
	}

	/**
	 * Assign a user to a company.
	 *
	 * @param int $user_id    User ID.
	 * @param int $company_id Company post ID (0 to unassign).
	 * @return bool
	 */
	public static function set_company( $user_id, $company_id ) {
		$user_id    = (int) $user_id;
		$company_id = (int) $company_id;

		if ( ! $user_id ) {
			return false;
		}

		if ( ! $company_id ) {
			return (bool) delete_user_meta( $user_id, self::META_COMPANY );
		}

		return (bool) update_user_meta( $user_id, self::META_COMPANY, $company_id );
	}

	/**
	 * Whether the user is a portal buyer (assigned to a company).
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return bool
	 */
	public static function is_portal_user( $user_id = 0 ) {
		$company_id = self::get_company( $user_id );
		if ( ! $company_id ) {
			return false;
		}
		return self::company_exists( $company_id );
	}

	/**
	 * Whether a company post exists and is published.
	 *
	 * @param int $company_id Company post ID.
	 * @return bool
	 */
	public static function company_exists( $company_id ) {
		$post = get_post( (int) $company_id );
		return $post && Sycomp_B2B_Post_Types::COMPANY === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * Locations available to a user (all locations of their company).
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return WP_Post[]
	 */
	public static function get_locations( $user_id = 0 ) {
		$company_id = self::get_company( $user_id );
		if ( ! $company_id ) {
			return array();
		}
		return Sycomp_B2B_Post_Types::get_company_locations( $company_id );
	}

	/**
	 * Whether a user may access a given location.
	 *
	 * @param int $location_id Location post ID.
	 * @param int $user_id     User ID. Defaults to current user.
	 * @return bool
	 */
	public static function can_access_location( $location_id, $user_id = 0 ) {
		$company_id = self::get_company( $user_id );
		if ( ! $company_id ) {
			return false;
		}
		$location = get_post( (int) $location_id );
		if ( ! $location || Sycomp_B2B_Post_Types::LOCATION !== $location->post_type || 'publish' !== $location->post_status ) {
			return false;
		}
		return Sycomp_B2B_Post_Types::location_belongs_to_company( $location_id, $company_id );
	}

	/**
	 * The user's company name.
	 *
	 * @param int $user_id User ID. Defaults to current user.
	 * @return string
	 */
	public static function get_company_name( $user_id = 0 ) {
		$company_id = self::get_company( $user_id );
		return $company_id ? get_the_title( $company_id ) : '';
	}

	/**
	 * Register filters/actions.
	 */
	public static function init() {
		add_filter( 'wp_mail', array( __CLASS__, 'custom_wp_mail_branding' ), 10, 1 );
	}

	/**
	 * Custom interceptor for outgoing plain-text emails to apply Sycomp B2B branding.
	 *
	 * @param array $atts Email attributes.
	 * @return array
	 */
	public static function custom_wp_mail_branding( $atts ) {
		$headers = isset( $atts['headers'] ) ? $atts['headers'] : array();
		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r", '', $headers ) );
		}
		$headers = array_filter( array_map( 'trim', $headers ) );

		$is_html = false;
		foreach ( $headers as $header ) {
			if ( stripos( $header, 'content-type:' ) !== false && stripos( $header, 'text/html' ) !== false ) {
				$is_html = true;
				break;
			}
		}

		if ( ! $is_html && ( stripos( $atts['message'], '<html' ) !== false || stripos( $atts['message'], '<body' ) !== false ) ) {
			$is_html = true;
		}

		// If it is already HTML, do not touch it (e.g. WooCommerce custom templates or already formatted emails)
		if ( $is_html ) {
			return $atts;
		}

		$message = $atts['message'];
		$subject = $atts['subject'];

		// Extract first URL to show as a primary action button if it's a password setup, reset or activation email.
		$button_html = '';
		$url_pattern = '/https?:\/\/[^\s]+/i';

		$is_activation_or_reset = ( stripos( $subject, 'password' ) !== false || stripos( $subject, 'login details' ) !== false || stripos( $subject, 'welcome' ) !== false || stripos( $subject, 'new user' ) !== false );

		if ( $is_activation_or_reset && preg_match( $url_pattern, $message, $matches ) ) {
			$action_url = trim( $matches[0], '<>.' );
			// Remove the URL from the text body to prevent showing it twice
			$message = str_replace( $matches[0], '', $message );
			// Clean up trailing/preceding text pointing to the URL
			$message = preg_replace( '/To (set|reset) your password, visit the following address:\s*/i', '', $message );
			$message = preg_replace( '/visit the following address:\s*/i', '', $message );

			$button_label = __( 'Reset / Set Password', 'sycomp-b2b-portal' );
			if ( stripos( $subject, 'login details' ) !== false || stripos( $subject, 'welcome' ) !== false || stripos( $subject, 'new user' ) !== false ) {
				$button_label = __( 'Set Password & Log In', 'sycomp-b2b-portal' );
			}

			$button_html = '
									<!-- Button -->
									<table border="0" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom: 30px; text-align: center;">
										<tr>
											<td>
												<a href="' . esc_url( $action_url ) . '" style="display: inline-block; background-color: #0c1c3c; color: #ffffff; font-size: 15px; font-weight: 600; text-decoration: none; padding: 12px 30px; border-radius: 5px; box-shadow: 0 2px 4px rgba(12, 28, 60, 0.1);">' . esc_html( $button_label ) . '</a>
											</td>
										</tr>
									</table>';
		}

		// Convert text to HTML paragraphs and linebreaks, escaping other text
		$formatted_content = wpautop( make_clickable( esc_html( $message ) ) );

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
								<td style="padding: 40px 40px 30px 40px; color: #0c1c3c; font-size: 15px; line-height: 1.6;">
									' . $formatted_content . '
									' . $button_html . '
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

		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		$atts['headers'] = $headers;
		$atts['message'] = $body;

		// Setup inline attachment for the logo
		$logo_path = get_template_directory() . '/assets/img/sycomp-logo-color.png';
		$embed_callback = function( $phpmailer ) use ( $logo_path, &$embed_callback ) {
			if ( file_exists( $logo_path ) ) {
				$phpmailer->addEmbeddedImage( $logo_path, 'sycomp-logo', 'sycomp-logo.png' );
			}
			remove_action( 'phpmailer_init', $embed_callback );
		};
		add_action( 'phpmailer_init', $embed_callback );

		return $atts;
	}
}
