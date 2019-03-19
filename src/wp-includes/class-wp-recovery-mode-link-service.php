<?php
/**
 * Error Protection API: WP_Recovery_Mode_Link_Handler class
 *
 * @package WordPress
 * @since   5.2.0
 */

/**
 * Core class used to generate and handle recovery mode links.
 *
 * @since 5.2.0
 */
class WP_Recovery_Mode_Link_Service {
	const LOGIN_ACTION_ENTER = 'enter_recovery_mode';
	const LOGIN_ACTION_ENTERED = 'entered_recovery_mode';

	/**
	 * Service to generate and validate recovery mode keys.
	 *
	 * @since 5.2.0
	 * @var WP_Recovery_Mode_Key_Service
	 */
	private $keys;

	/**
	 * WP_Recovery_Mode_Link_Service constructor.
	 */
	public function __construct() {
		$this->keys = new WP_Recovery_Mode_Key_Service();
	}

	/**
	 * Generate a URL to begin recovery mode.
	 *
	 * Only one recovery mode URL can may be valid at the same time.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	public function generate_url() {
		$key = $this->keys->generate_and_store_recovery_mode_key();

		return $this->get_recovery_mode_begin_url( $key );
	}

	/**
	 * Enter recovery mode when the user hits wp-login.php with a valid recovery mode link.
	 *
	 * @since 5.2.0
	 *
	 * @param WP_Recovery_Mode_Cookie_Service $cookies Service to set the recovery mode cookie if the link is valid.
	 * @param int                             $ttl     Number of seconds the link should be valid for.
	 */
	public function handle_begin_link( WP_Recovery_Mode_Cookie_Service $cookies, $ttl ) {
		if ( ! isset( $GLOBALS['pagenow'] ) || 'wp-login.php' !== $GLOBALS['pagenow'] ) {
			return;
		}

		if ( ! isset( $_GET['action'], $_GET['rm_key'] ) || self::LOGIN_ACTION_ENTER !== $_GET['action'] ) {
			return;
		}

		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$validated = $this->keys->validate_recovery_mode_key( $_GET['rm_key'], $ttl );

		if ( is_wp_error( $validated ) ) {
			wp_die( $validated, '' );
		}

		$cookies->set_cookie();

		$url = add_query_arg( 'action', self::LOGIN_ACTION_ENTERED, wp_login_url() );
		wp_redirect( $url );
		die;
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
}
