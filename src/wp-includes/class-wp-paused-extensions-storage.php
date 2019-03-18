<?php
/**
 * Error Protection API: WP_Paused_Extensions_Storage class
 *
 * @package WordPress
 * @since 5.2.0
 */

/**
 * Core class used for storing paused extensions.
 *
 * @since 5.2.0
 */
class WP_Paused_Extensions_Storage {

	/**
	 * Option name for storing paused extensions.
	 *
	 * @since 5.2.0
	 * @var string
	 */
	protected $option_name;

	/**
	 * Constructor.
	 *
	 * @since 5.2.0
	 */
	public function __construct() {
		$this->option_name = wp_recovery_mode()->get_recovery_mode_session_id() . '_paused_extensions';
	}

	/**
	 * Records an extension error.
	 *
	 * Only one error is stored per extension, with subsequent errors for the same extension overriding the
	 * previously stored error.
	 *
	 * @since 5.2.0
	 *
	 * @param string $type      Extension type. Either 'plugin' or 'theme'.
	 * @param string $extension Plugin or theme directory name.
	 * @param array  $error     {
	 *     Error that was triggered.
	 *
	 *     @type string $type    The error type.
	 *     @type string $file    The name of the file in which the error occurred.
	 *     @type string $line    The line number in which the error occurred.
	 *     @type string $message The error message.
	 * }
	 * @return bool True on success, false on failure.
	 */
	public function record( $type, $extension, $error ) {
		if ( ! $this->is_api_loaded() ) {
			return false;
		}

		if ( is_multisite() && is_site_meta_supported() ) {
			$meta_key = $this->get_site_meta_key( $type, $extension );

			// Do not update if the error is already stored.
			if ( get_site_meta( get_current_blog_id(), $meta_key, true ) === $error ) {
				return true;
			}

			return (bool) update_site_meta( get_current_blog_id(), $meta_key, $error );
		}

		$paused_extensions = $this->get_all();

		// Do not update if the error is already stored.
		if ( isset( $paused_extensions[ $type ][ $extension ] ) && $paused_extensions[ $type ][ $extension ] === $error ) {
			return true;
		}

		$paused_extensions[ $type ][ $extension ] = $error;

		return update_option( $this->option_name, $paused_extensions );
	}

	/**
	 * Forgets a previously recorded extension error.
	 *
	 * @since 5.2.0
	 *
	 * @param string $type Extension type. Either 'plugin' or 'theme'.
	 * @param string $extension Plugin or theme directory name.
	 * @return bool True on success, false on failure.
	 */
	public function forget( $type, $extension ) {
		if ( ! $this->is_api_loaded() ) {
			return false;
		}

		if ( is_multisite() && is_site_meta_supported() ) {
			$meta_key = $this->get_site_meta_key( $type, $extension );

			// Do not delete if no error is stored.
			if ( get_site_meta( get_current_blog_id(), $meta_key ) === array() ) {
				return true;
			}

			return delete_site_meta( get_current_blog_id(), $meta_key );
		}

		$paused_extensions = $this->get_all();

		// Do not delete if no error is stored.
		if ( ! isset( $paused_extensions[ $type ][ $extension ] ) ) {
			return true;
		}

		unset( $paused_extensions[ $type ][ $extension ] );

		if ( empty( $paused_extensions[ $type ] ) ) {
			unset( $paused_extensions[ $type ] );
		}

		// Clean up the entire option if we're removing the only error.
		if ( ! $paused_extensions ) {
			return delete_option( $this->option_name );
		}

		return update_option( $this->option_name, $paused_extensions );
	}

	/**
	 * Gets the error for an extension, if paused.
	 *
	 * @since 5.2.0
	 *
	 * @param string $type Extension type. Either 'plugin' or 'theme'.
	 * @param string $extension Plugin or theme directory name.
	 * @return array|null Error that is stored, or null if the extension is not paused.
	 */
	public function get( $type, $extension ) {
		if ( ! $this->is_api_loaded() ) {
			return null;
		}

		if ( is_multisite() && is_site_meta_supported() ) {
			$error = get_site_meta( get_current_blog_id(), $this->get_site_meta_key( $type, $extension ), true );
			if ( ! $error ) {
				return null;
			}

			return $error;
		}

		$paused_extensions = $this->get_all( $type );

		if ( ! isset( $paused_extensions[ $extension ] ) ) {
			return null;
		}

		return $paused_extensions[ $extension ];
	}

	/**
	 * Gets the paused extensions with their errors.
	 *
	 * @since 5.2.0
	 *
	 * @param string $type Optionally, limit to extensions of the given type.
	 *
	 * @return array Associative array of $type => array( $extension => $error ).
	 *               If the extension type is provided, just the error entries are returned.
	 */
	public function get_all( $type = '' ) {
		if ( ! $this->is_api_loaded() ) {
			return array();
		}

		if ( is_multisite() && is_site_meta_supported() ) {
			$site_metadata = get_site_meta( get_current_blog_id() );

			$paused_extensions = array();
			foreach ( $site_metadata as $meta_key => $meta_values ) {
				if ( 0 !== strpos( $meta_key, $this->option_name . '_' ) ) {
					continue;
				}

				$error = maybe_unserialize( array_shift( $meta_values ) );

				$without_prefix = substr( $meta_key, strlen( $this->option_name . '_' ) );
				$parts          = explode( '_', $without_prefix, 2 );

				if ( ! isset( $parts[1] ) ) {
					continue;
				}

				$paused_extensions[ $parts[0] ][ $parts[1] ] = $error;
			}
		} else {
			$paused_extensions = (array) get_option( $this->option_name, array() );
		}

		if ( $type ) {
			return isset( $paused_extensions[ $type ] ) ? $paused_extensions[ $type ] : array();
		}

		return $paused_extensions;
	}

	/**
	 * Checks whether the underlying API to store paused extensions is loaded.
	 *
	 * @since 5.2.0
	 *
	 * @return bool True if the API is loaded, false otherwise.
	 */
	protected function is_api_loaded() {
		if ( is_multisite() ) {
			return function_exists( 'is_site_meta_supported' ) && function_exists( 'get_site_meta' );
		}

		return function_exists( 'get_option' );
	}

	/**
	 * Get the site meta key for storing extension errors on Multisite.
	 *
	 * @since 5.2.0
	 *
	 * @param string $type
	 * @param string $extension
	 *
	 * @return string
	 */
	private function get_site_meta_key( $type, $extension ) {
		return $this->option_name . '_' . $type . '_' . $extension;
	}
}
