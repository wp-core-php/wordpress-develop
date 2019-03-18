<?php
/**
 * Error Protection API: WP_Recovery_Mode_Email_Link class
 *
 * @package WordPress
 * @since   5.2.0
 */

/**
 * Core class used to send an email with a link to begin Recovery Mode.
 *
 * @since 5.2.0
 */
final class WP_Recovery_Mode_Email_Link {

	const RATE_LIMIT_OPTION = 'recovery_mode_email_last_sent';
	const LOGIN_ACTION_ENTER = 'enter_recovery_mode';
	const LOGIN_ACTION_ENTERED = 'entered_recovery_mode';

	/**
	 * Service to handle generating and validating email keys.
	 *
	 * @since 5.2.0
	 * @var WP_Recovery_Mode_Key_Service
	 */
	private $keys;

	/**
	 * Service to handle cookies.
	 *
	 * @since 5.2.0
	 * @var WP_Recovery_Mode_Cookie_Service
	 */
	private $cookies;

	/**
	 * WP_Recovery_Mode_Email constructor.
	 */
	public function __construct() {
		$this->keys    = new WP_Recovery_Mode_Key_Service();
		$this->cookies = new WP_Recovery_Mode_Cookie_Service();
	}

	/**
	 * Enter recovery mode when the user hits wp-login.php with a valid recovery mode link.
	 *
	 * @since 5.2.0
	 */
	public function handle_begin_link() {
		if ( ! isset( $_GET['action'], $_GET['rm_key'] ) || self::LOGIN_ACTION_ENTER !== $_GET['action'] ) {
			return;
		}

		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$validated = $this->keys->validate_recovery_mode_key( $_GET['rm_key'], $this->get_link_valid_for_interval() );

		if ( is_wp_error( $validated ) ) {
			wp_die( $validated, '' );
		}

		$this->cookies->set_cookie();

		$url = add_query_arg( 'action', self::LOGIN_ACTION_ENTERED, wp_login_url() );
		wp_redirect( $url );
		die;
	}

	/**
	 * Send the recovery mode email if the rate limit has not been sent.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 * @param array $extension The extension that caused the error. {
	 *      @type string $slug The extension slug. This is the plugin or theme's directory.
	 *      @type string $type The extension type. Either 'plugin' or 'theme'.
	 * }
	 *
	 * @return true|WP_Error True if email sent, WP_Error otherwise.
	 */
	public function maybe_send_recovery_mode_email( $error, $extension ) {

		$rate_limit = $this->get_email_rate_limit();

		$last_sent = get_site_option( self::RATE_LIMIT_OPTION );

		if ( ! $last_sent || time() > $last_sent + $rate_limit ) {
			if ( ! update_site_option( self::RATE_LIMIT_OPTION, time() ) ) {
				return new WP_Error( 'storage_error',	__( 'Could not update the email last sent time.' ) );
			}

			$sent = $this->send_recovery_mode_email( $error, $extension );

			if ( $sent ) {
				return true;
			}

			return new WP_Error( 'email_failed', __( 'The email could not be sent. Possible reason: your host may have disabled the mail() function.' ) );
		}

		$err_message = sprintf(
		/* translators: 1. Last sent as a human time diff 2. Wait time as a human time diff. */
			__( 'A recovery link was already sent %1$s ago. Please wait another %2$s before requesting a new email.' ),
			human_time_diff( $last_sent ),
			human_time_diff( $last_sent + $rate_limit )
		);

		return new WP_Error( 'email_sent_already', $err_message );
	}

	/**
	 * Clear the rate limit, allowing a new recovery mode email to be sent immediately.
	 *
	 * @since 5.2.0
	 *
	 * @return bool
	 */
	public function clear_rate_limit() {
		return delete_site_option( self::RATE_LIMIT_OPTION );
	}

	/**
	 * Get a URL to begin recovery mode.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key Recovery Mode key created by {@see generate_and_store_recovery_mode_key()}
	 *
	 * @return string
	 */
	private function get_recovery_mode_begin_url( $key ) {

		$url = add_query_arg(
			array(
				'action' => self::LOGIN_ACTION_ENTER,
				'rm_key' => $key,
			),
			wp_login_url()
		);

		/**
		 * Filter the URL to begin recovery mode.
		 *
		 * @since 5.2.0
		 *
		 * @param string $url
		 * @param string $key
		 */
		return apply_filters( 'recovery_mode_begin_url', $url, $key );
	}

	/**
	 * Get the interval the recovery mode email key is valid for.
	 *
	 * @since 5.2.0
	 *
	 * @return int Interval in seconds.
	 */
	private function get_link_valid_for_interval() {

		$rate_limit = $valid_for = $this->get_email_rate_limit();

		/**
		 * Filter the amount of time the recovery mode email link is valid for.
		 *
		 * The interval time must be at least as long as the email rate limit.
		 *
		 * @since 5.2.0
		 *
		 * @param int $valid_for The number of seconds the link is valid for.
		 */
		$valid_for = apply_filters( 'recovery_mode_email_link_valid_for_interval', $valid_for );

		return max( $valid_for, $rate_limit );
	}

	/**
	 * The rate limit between sending new recovery mode email links.
	 *
	 * @since 5.2.0
	 *
	 * @return int Rate limit in seconds.
	 */
	private function get_email_rate_limit() {
		/**
		 * Filter the rate limit between sending new recovery mode email links.
		 *
		 * @since 5.2.0
		 *
		 * @param int $rate_limit Time to wait in seconds. Defaults to 4 hours.
		 */
		return apply_filters( 'recovery_mode_email_rate_limit', 4 * HOUR_IN_SECONDS );
	}

	/**
	 * Send the Recovery Mode email to the site admin email address.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 * @param array $extension Extension that caused the error.
	 *
	 * @return bool Whether the email was sent successfully.
	 */
	private function send_recovery_mode_email( $error, $extension ) {

		$key      = $this->keys->generate_and_store_recovery_mode_key();
		$url      = $this->get_recovery_mode_begin_url( $key );
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$switched_locale = false;

		// The switch_to_locale() function is loaded before it can actually be used.
		if ( function_exists( 'switch_to_locale' ) && isset( $GLOBALS['wp_locale_switcher'] ) ) {
			$switched_locale = switch_to_locale( get_locale() );
		}

		if ( $extension ) {
			$cause   = $this->get_cause( $extension );
			$details = wp_strip_all_tags( wp_get_extension_error_description( $error ) );

			if ( $details ) {
				$header  = __( 'Error Details' );
				$details = "\n\n" . $header . "\n" . str_pad( '', strlen( $header ), '=' ) . "\n" . $details;
			}
		} else {
			$cause = $details = '';
		}

		$message = __(
			'Howdy,

Your site recently crashed on ###LOCATION### and may not be working as expected.
###CAUSE###
Click the link below to initiate recovery mode and fix the problem.

This link expires in ###EXPIRES###.

###LINK### ###DETAILS###
'
		);
		$message = str_replace(
			array(
				'###LINK###',
				'###LOCATION###',
				'###EXPIRES###',
				'###CAUSE###',
				'###DETAILS###',
			),
			array(
				$url,
				'TBD',
				human_time_diff( time() + $this->get_link_valid_for_interval() ),
				$cause ? "\n{$cause}\n" : "\n",
				$details,
			),
			$message
		);

		$email = array(
			'to'      => $this->get_recovery_mode_email_address(),
			'subject' => __( '[%s] Your Site Experienced an Issue' ),
			'message' => $message,
			'headers' => '',
		);

		/**
		 * Filter the contents of the Recovery Mode email.
		 *
		 * @since 5.2.0
		 *
		 * @param array  $email Used to build wp_mail().
		 * @param string $key   Recovery mode key.
		 */
		$email = apply_filters( 'recovery_mode_email', $email, $key );

		$sent = wp_mail(
			$email['to'],
			wp_specialchars_decode( sprintf( $email['subject'], $blogname ) ),
			$email['message'],
			$email['headers']
		);

		if ( $switched_locale ) {
			restore_previous_locale();
		}

		return $sent;
	}

	/**
	 * Get the email address to send the recovery mode link to.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	private function get_recovery_mode_email_address() {
		if ( defined( 'RECOVERY_MODE_EMAIL' ) && is_email( RECOVERY_MODE_EMAIL ) ) {
			return RECOVERY_MODE_EMAIL;
		}

		return get_option( 'admin_email' );
	}

	/**
	 * Get the description indicating the possible cause for the error.
	 *
	 * @since 5.2.0
	 *
	 * @param array $extension The extension that caused the error.
	 *
	 * @return string
	 */
	private function get_cause( $extension ) {

		if ( 'plugin' === $extension['type'] ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$names = array();

			foreach ( get_plugins() as $file => $plugin ) {
				if ( 0 === strpos( $file, "{$extension['slug']}/" ) ) {
					$names[] = $plugin['Name'];
				}
			}

			if ( ! $names ) {
				$names[] = $extension['slug'];
			}

			// Multiple plugins can technically be in the same directory.
			$cause = wp_sprintf( _n( 'This may be caused by the %l plugin.', 'This may be caused by the %l plugins.', count( $names ) ), $names );
		} else {
			$theme = wp_get_theme( $extension['slug'] );
			$name  = $theme->exists() ? $theme->display( 'Name' ) : $extension['slug'];

			$cause = sprintf( __( 'This may be caused by the %s theme.' ), $name );
		}

		return $cause;
	}
}
