<?php

final class WP_Recovery_Mode_Cookie_Service {

	/** @var string */
	private $name;

	/** @var string */
	private $domain;

	/** @var string */
	private $path;

	/** @var string */
	private $site_path;

	/**
	 * WP_Recovery_Mode_Cookie_Service constructor.
	 *
	 * @param array $opts
	 */
	public function __construct( array $opts = array() ) {
		$opts = wp_parse_args( $opts, array(
			'name'      => RECOVERY_MODE_COOKIE,
			'domain'    => COOKIE_DOMAIN,
			'path'      => COOKIEPATH,
			'site_path' => SITECOOKIEPATH,
		) );

		$this->name      = $opts['name'];
		$this->domain    = $opts['domain'];
		$this->path      = $opts['path'];
		$this->site_path = $opts['site_path'];
	}

	/**
	 * Is the recovery mode cookie set.
	 *
	 * @since 5.2.0
	 *
	 * @return bool
	 */
	public function is_cookie_set() {
		return ! empty( $_COOKIE[ $this->name ] );
	}

	/**
	 * Set the recovery mode cookie.
	 *
	 * This must be immediately followed by exiting the request.
	 *
	 * @since 5.2.0
	 */
	public function set_cookie() {

		$value = $this->generate_cookie();

		setcookie( $this->name, $value, 0, $this->path, $this->domain, is_ssl(), true );

		if ( $this->path !== $this->site_path ) {
			setcookie( $this->name, $value, 0, $this->site_path, $this->domain, is_ssl(), true );
		}
	}

	/**
	 * Clear the recovery mode cookie.
	 *
	 * @sicne 5.2.0
	 */
	public function clear_cookie() {
		setcookie( $this->name, ' ', time() - YEAR_IN_SECONDS, $this->path, $this->domain );
		setcookie( $this->name, ' ', time() - YEAR_IN_SECONDS, $this->site_path, $this->domain );
	}

	/**
	 * Validate the recovery mode cookie.
	 *
	 * @since 5.2.0
	 *
	 * @param string $cookie Optionally specify the cookie string.
	 *                       If omitted, it will be retrieved from the super global.
	 *
	 * @return true|WP_Error
	 */
	public function validate_cookie( $cookie = '' ) {

		if ( ! $cookie ) {
			if ( empty( $_COOKIE[ $this->name ] ) ) {
				return new WP_Error( 'no_cookie', __( 'No cookie present.' ) );
			}

			$cookie = $_COOKIE[ $this->name ];
		}

		$parts = $this->parse_cookie( $cookie );

		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		list( , $created_at, $random, $signature ) = $parts;

		if ( ! ctype_digit( $created_at ) ) {
			return new WP_Error( 'invalid_created_at', __( 'Invalid cookie format.' ) );
		}

		/**
		 * Filter the length of time a Recovery Mode cookie is valid for.
		 *
		 * @since 5.2.0
		 *
		 * @param int $length Length in seconds.
		 */
		$length = apply_filters( 'recovery_mode_cookie_length', WEEK_IN_SECONDS );

		if ( time() > $created_at + $length ) {
			return new WP_Error( 'expired', __( 'Cookie expired.' ) );
		}

		$to_sign = sprintf( 'recovery_mode|%s|%s', $created_at, $random );
		$hashed  = $this->recovery_mode_hash( $to_sign );

		if ( ! hash_equals( $signature, $hashed ) ) {
			return new WP_Error( 'signature_mismatch', __( 'Invalid cookie.' ) );
		}

		return true;
	}

	/**
	 * Get the session identifier from the cookie.
	 *
	 * The cookie should be validated before calling this API.
	 *
	 * @since 5.2.0
	 *
	 * @param string $cookie Optionally specify the cookie string.
	 *                       If omitted, it will be retrieved from the super global.
	 *
	 * @return string|WP_Error
	 */
	public function get_session_id_from_cookie( $cookie = '' ) {
		if ( ! $cookie ) {
			if ( empty( $_COOKIE[ $this->name ] ) ) {
				return new WP_Error( 'no_cookie' );
			}

			$cookie = $_COOKIE[ $this->name ];
		}

		$parts = $this->parse_cookie( $cookie );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		list( , , $random ) = $parts;

		return sha1( $random );
	}

	/**
	 * Parse the cookie into its four parts.
	 *
	 * @param string $cookie
	 *
	 * @return string[]|WP_Error
	 */
	private function parse_cookie( $cookie ) {
		$cookie = base64_decode( $cookie );
		$parts  = explode( '|', $cookie );

		if ( 4 !== count( $parts ) ) {
			return new WP_Error( 'invalid_format', __( 'Invalid cookie format.' ) );
		}

		return $parts;
	}

	/**
	 * Generate the recovery mode cookie value.
	 *
	 * The cookie is a base64 encoded string with the following format:
	 *
	 * recovery_mode|iat|rand|signature
	 *
	 * Where "recovery_mode" is a constant string,
	 * iat is the time the cookie was generated at,
	 * rand is a randomly generated password that is also used as a session identifier
	 * and signature is an hmac of the preceding 3 parts.
	 *
	 * @since 5.2.0
	 *
	 * @return string
	 */
	private function generate_cookie() {

		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$to_sign = sprintf( 'recovery_mode|%s|%s', time(), wp_generate_password( 20, false ) );
		$signed  = $this->recovery_mode_hash( $to_sign );

		return base64_encode( sprintf( '%s|%s', $to_sign, $signed ) );
	}

	/**
	 * A form of `wp_hash()` specific to Recovery Mode.
	 *
	 * We cannot use `wp_hash()` because it is defined in `pluggable.php` which is not loaded until after plugins are loaded,
	 * which is too late to verify the recovery mode cookie.
	 *
	 * This tries to use the `AUTH` salts first, but if they aren't valid specific salts will be generated and stored.
	 *
	 * @param string $data
	 *
	 * @return string|false
	 */
	private function recovery_mode_hash( $data ) {

		if ( ! defined( 'AUTH_KEY' ) || 'put your unique phrase here' === AUTH_KEY ) {
			$auth_key = get_site_option( 'recovery_mode_auth_key' );

			if ( ! $auth_key ) {
				if ( ! function_exists( 'wp_generate_password' ) ) {
					require_once ABSPATH . WPINC . '/pluggable.php';
				}

				$auth_key = wp_generate_password( 64, true, true );
				update_site_option( 'recovery_mode_auth_key', $auth_key );
			}
		} else {
			$auth_key = AUTH_KEY;
		}

		if ( ! defined( 'AUTH_SALT' ) || 'put your unique phrase here' === AUTH_SALT || $auth_key === AUTH_SALT ) {
			$auth_salt = get_site_option( 'recovery_mode_auth_salt' );

			if ( ! $auth_salt ) {
				if ( ! function_exists( 'wp_generate_password' ) ) {
					require_once ABSPATH . WPINC . '/pluggable.php';
				}

				$auth_salt = wp_generate_password( 64, true, true );
				update_site_option( 'recovery_mode_auth_salt', $auth_salt );
			}
		} else {
			$auth_salt = AUTH_SALT;
		}

		$secret = $auth_key . $auth_salt;

		return hash_hmac( 'sha1', $data, $secret );
	}
}
