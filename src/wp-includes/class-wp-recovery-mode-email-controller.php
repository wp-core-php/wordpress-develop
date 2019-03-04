<?php

final class WP_Recovery_Mode_Email_Controller implements WP_Recovery_Mode_Controller {

	const LOGIN_ACTION_ENTER = 'enter_recovery_mode';
	const LOGIN_ACTION_ENTERED = 'entered_recovery_mode';

	/** @var WP_Recovery_Mode_Cookie_Service */
	private $cookies;

	/** @var bool */
	private $is_active;

	/** @var string|false */
	private $session_id = false;

	/**
	 * WP_Recovery_Mode_Email_Processor constructor.
	 *
	 * @param WP_Recovery_Mode_Cookie_Service $cookies
	 */
	public function __construct( WP_Recovery_Mode_Cookie_Service $cookies ) { $this->cookies = $cookies; }

	/**
	 * @inheritdoc
	 */
	public function is_recovery_mode_active() {
		return $this->is_active;
	}

	/**
	 * @inheritdoc
	 */
	public function get_recovery_mode_session_id() {
		return $this->session_id;
	}

	/**
	 * @inheritdoc
	 */
	public function run() {
		add_action( 'handle_fatal_error', array( $this, 'on_fatal_error' ) );
		add_action( 'clear_auth_cookie', array( $this, 'on_clear_auth_cookie' ) );

		if ( $this->cookies->is_cookie_set() ) {
			$this->handle_cookie();

			return;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$this->handle_begin_link();
		}
	}

	/**
	 * When a fatal error occurs, send the recovery mode email.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 */
	public function on_fatal_error( $error ) {
		if ( is_protected_endpoint() ) {
			$this->maybe_send_recovery_mode_email( $error );
		}
	}

	/**
	 * Clear the recovery mode cookie when the auth cookies are cleared.
	 *
	 * @since 5.2.0
	 */
	public function on_clear_auth_cookie() {
		/** This filter is documented in wp-includes/pluggable.php */
		if ( ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}

		$this->cookies->clear_cookie();
	}

	/**
	 * Handle checking for the recovery mode cookie and validating it.
	 *
	 * @since 5.2.0
	 */
	private function handle_cookie() {
		$validated = $this->cookies->validate_cookie();

		if ( is_wp_error( $validated ) ) {
			$this->cookies->clear_cookie();

			wp_die( $validated, '' );
		}

		$this->is_active  = true;
		$this->session_id = $this->cookies->get_session_id_from_cookie();
	}

	/**
	 * Enter recovery mode when the user hits wp-login.php with a valid recovery mode link.
	 *
	 * @since 5.2.0
	 */
	private function handle_begin_link() {
		if ( ! isset( $_GET['action'], $_GET['rm_key'] ) || self::LOGIN_ACTION_ENTER !== $_GET['action'] ) {
			return;
		}

		$validated = $this->validate_recovery_mode_key( $_GET['rm_key'] );

		if ( is_wp_error( $validated ) ) {
			wp_die( $validated, '' );
		}

		$this->cookies->set_cookie();

		// This should be loaded by set_recovery_mode_cookie() but load it again to be safe.
		if ( ! function_exists( 'wp_redirect' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$url = add_query_arg( 'action', self::LOGIN_ACTION_ENTERED, wp_login_url() );
		wp_redirect( $url );
		die;
	}

	/**
	 * Create a recovery mode key.
	 *
	 * @since 5.2.0
	 *
	 * @global PasswordHash $wp_hasher
	 *
	 * @return string Recovery mode key.
	 */
	private function generate_and_store_recovery_mode_key() {

		global $wp_hasher;

		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$key = wp_generate_password( 20, false );

		/**
		 * Fires when a recovery mode key is generated for a user.
		 *
		 * @since 5.2.0
		 *
		 * @param string $key The recovery mode key.
		 */
		do_action( 'generate_recovery_mode_key', $key );

		if ( empty( $wp_hasher ) ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
			$wp_hasher = new PasswordHash( 8, true );
		}

		$hashed = $wp_hasher->HashPassword( $key );

		update_site_option( 'recovery_key', array(
			'hashed_key' => $hashed,
			'created_at' => time(),
		) );

		return $key;
	}

	/**
	 * Verify if the recovery mode key is correct.
	 *
	 * @since 5.2.0
	 *
	 * @param string $key The unhashed key.
	 *
	 * @return true|WP_Error
	 */
	private function validate_recovery_mode_key( $key ) {

		$record = get_site_option( 'recovery_key' );

		if ( ! $record ) {
			return new WP_Error( 'no_recovery_key_set', __( 'Recovery Mode not initialized.' ) );
		}

		if ( ! is_array( $record ) || ! isset( $record['hashed_key'], $record['created_at'] ) ) {
			return new WP_Error( 'invalid_recovery_key_format', __( 'Invalid recovery key format.' ) );
		}

		if ( ! function_exists( 'wp_check_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if ( ! wp_check_password( $key, $record['hashed_key'] ) ) {
			return new WP_Error( 'hash_mismatch', __( 'Invalid recovery key.' ) );
		}

		$valid_for = $this->get_link_valid_for_interval();

		if ( time() > $record['created_at'] + $valid_for ) {
			return new WP_Error( 'key_expired', __( 'Recovery key expired.' ) );
		}

		return true;
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
	 * Send the recovery mode email if the rate limit has not been sent.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 *
	 * @return true|WP_Error True if email sent, WP_Error otherwise.
	 */
	private function maybe_send_recovery_mode_email( $error ) {

		$rate_limit = $this->get_email_rate_limit();

		$last_sent = get_site_option( 'recovery_mode_email_last_sent' );

		if ( ! $last_sent || time() > $last_sent + $rate_limit ) {
			$sent = $this->send_recovery_mode_email( $error );
			update_site_option( 'recovery_mode_email_last_sent', time() );

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
	 * Send the Recovery Mode email to the site admin email address.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 *
	 * @return bool Whether the email was sent successfully.
	 */
	private function send_recovery_mode_email( $error ) {

		$key      = $this->generate_and_store_recovery_mode_key();
		$url      = $this->get_recovery_mode_begin_url( $key );
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$switched_locale = false;

		// The switch_to_locale() function is loaded before it can actually be used.
		if ( function_exists( 'switch_to_locale' ) && isset( $GLOBALS['wp_locale_switcher'] ) ) {
			$switched_locale = switch_to_locale( get_locale() );
		}

		$extension = wp_get_extension_for_error( $error );

		if ( $extension ) {
			$cause   = $this->get_cause( $extension );
			$details = $this->get_error_details( $error );

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
			'to'      => get_option( 'admin_email' ),
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
	 * Get a human readable description of the error.
	 *
	 * @since 5.2.0
	 *
	 * @param array $error Error details from {@see error_get_last()}
	 *
	 * @return string
	 */
	private function get_error_details( $error ) {
		$constants   = get_defined_constants( true );
		$constants   = isset( $constants['Core'] ) ? $constants['Core'] : $constants['internal'];
		$core_errors = array();

		foreach ( $constants as $constant => $value ) {
			if ( 0 === strpos( $constant, 'E_' ) ) {
				$core_errors[ $value ] = $constant;
			}
		}

		if ( isset( $core_errors[ $error['type'] ] ) ) {
			$error['type'] = $core_errors[ $error['type'] ];
		}

		/* translators: 1: error type, 2: error line number, 3: error file name, 4: error message */
		$error_message = __( "An error of type %1\$s in line %2\$s of the file %3\$s. \nError message: %4\$s" );

		return sprintf(
			$error_message,
			$error['type'],
			$error['line'],
			$error['file'],
			$error['message']
		);
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
