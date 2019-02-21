<?php

final class WP_Recovery_Mode_Email_Controller implements WP_Recovery_Mode_Controller {

	const LOGIN_ACTION_BEGIN = 'begin_recovery_mode';
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

		if ( isset( $_COOKIE[ RECOVERY_MODE_COOKIE ] ) ) {
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
	 */
	public function on_fatal_error() {
		$this->maybe_send_recovery_mode_email();
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
		if ( ! isset( $_GET['action'], $_GET['rm_key'] ) || self::LOGIN_ACTION_BEGIN !== $_GET['action'] ) {
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

		$valid_for = HOUR_IN_SECONDS;

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
				'action' => self::LOGIN_ACTION_BEGIN,
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
	 * Send the recovery mode email if the rate limit has not been sent.
	 *
	 * @since 5.2.0
	 *
	 * @return true|WP_Error True if email sent, WP_Error otherwise.
	 */
	private function maybe_send_recovery_mode_email() {

		/**
		 * Filter the rate limit between sending new recovery mode email links.
		 *
		 * @since 5.2.0
		 *
		 * @param int $rate_limit Time to wait in seconds.
		 */
		$rate_limit = apply_filters( 'recovery_mode_email_rate_limit', HOUR_IN_SECONDS );

		$last_sent = get_site_option( 'recovery_mode_email_last_sent' );

		if ( ! $last_sent || time() > $last_sent + $rate_limit ) {
			$sent = $this->send_recovery_mode_email();
			update_site_option( 'recovery_mode_email_last_sent', time() );

			if ( $sent ) {
				return true;
			}

			return new WP_Error( 'email_failed', __( 'The email could not be sent. Possible reason: your host may have disabled the mail() function.' ) );
		}

		$error = sprintf(
		/* translators: 1. Last sent as a human time diff 2. Wait time as a human time diff. */
			__( 'A recovery link was already sent %1$s ago. Please wait another %2$s before requesting a new email.' ),
			human_time_diff( $last_sent ),
			human_time_diff( $last_sent + $rate_limit )
		);

		return new WP_Error( 'email_sent_already', $error );
	}

	/**
	 * Send the Recovery Mode email to the site admin email address.
	 *
	 * @since 5.2.0
	 *
	 * @return bool Whether the email was sent successfully.
	 */
	private function send_recovery_mode_email() {

		$key      = $this->generate_and_store_recovery_mode_key();
		$url      = $this->get_recovery_mode_begin_url( $key );
		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		$switched_locale = false;

		// The switch_to_locale() function is loaded before it can actually be used.
		if ( function_exists( 'switch_to_locale' ) && isset( $GLOBALS['wp_locale_switcher'] ) ) {
			$switched_locale = switch_to_locale( get_locale() );
		}

		$message = __(
			'Howdy,

Your site recently experienced a fatal error. Click the link below to initiate recovery mode to fix the problem.

This link expires in one hour.

###LINK###'
		);
		$message = str_replace( '###LINK###', $url, $message );

		$email = array(
			'to'      => get_option( 'admin_email' ),
			'subject' => __( '[%s] Recovery Mode' ),
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
}
