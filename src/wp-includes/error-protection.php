<?php
/**
 * Error Protection API: Functions
 *
 * @package WordPress
 * @since 5.1.0
 */

/**
 * Gets the instance for storing paused plugins.
 *
 * @since 5.1.0
 *
 * @return WP_Paused_Extensions_Storage Paused plugins storage.
 */
function wp_paused_plugins() {
	static $wp_paused_plugins_storage = null;

	if ( null === $wp_paused_plugins_storage ) {
		$wp_paused_plugins_storage = new WP_Paused_Extensions_Storage( 'paused_plugins', 'paused_plugin_' );
	}

	return $wp_paused_plugins_storage;
}

/**
 * Gets the instance for storing paused themes.
 *
 * @since 5.1.0
 *
 * @return WP_Paused_Extensions_Storage Paused themes storage.
 */
function wp_paused_themes() {
	static $wp_paused_themes_storage = null;

	if ( null === $wp_paused_themes_storage ) {
		$wp_paused_themes_storage = new WP_Paused_Extensions_Storage( 'paused_themes', 'paused_theme_' );
	}

	return $wp_paused_themes_storage;
}

/**
 * Records the extension error as a database option.
 *
 * @since 5.1.0
 *
 * @global array $wp_theme_directories
 *
 * @param array $error Error that was triggered.
 * @return bool Whether the error was correctly recorded.
 */
function wp_record_extension_error( $error ) {
	global $wp_theme_directories;

	$error_file    = wp_normalize_path( $error['file'] );
	$wp_plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );

	if ( 0 === strpos( $error_file, $wp_plugin_dir ) ) {
		$callback = 'wp_paused_plugins';
		$path     = str_replace( $wp_plugin_dir . '/', '', $error_file );
	} else {
		foreach ( $wp_theme_directories as $theme_directory ) {
			$theme_directory = wp_normalize_path( $theme_directory );
			if ( 0 === strpos( $error_file, $theme_directory ) ) {
				$callback = 'wp_paused_themes';
				$path     = str_replace( $theme_directory . '/', '', $error_file );
			}
		}
	}

	if ( empty( $callback ) || empty( $path ) ) {
		return false;
	}

	$parts     = explode( '/', $path );
	$extension = array_shift( $parts );

	return call_user_func( $callback )->set( $extension, $error );
}

/**
 * Forgets a previously recorded extension error again.
 *
 * @since 5.1.0
 *
 * @param string $type Type of the extension.
 * @param string $extension Relative path of the extension.
 * @return bool Whether the extension error was successfully forgotten.
 */
function wp_forget_extension_error( $type, $extension ) {
	switch ( $type ) {
		case 'plugins':
			$callback          = 'wp_paused_plugins';
			list( $extension ) = explode( '/', $extension );
			break;
		case 'themes':
			$callback          = 'wp_paused_themes';
			list( $extension ) = explode( '/', $extension );
			break;
	}

	if ( empty( $callback ) || empty( $extension ) ) {
		return false;
	}

	return call_user_func( $callback )->unset( $extension );
}

/**
 * Determines whether we are dealing with an error that WordPress should handle
 * in order to protect the admin backend against WSODs.
 *
 * @param array $error Error information retrieved from error_get_last().
 *
 * @return bool Whether WordPress should handle this error.
 */
function wp_should_handle_error( $error ) {
	if ( ! isset( $error['type'] ) ) {
		return false;
	}

	$error_types_to_handle = array(
		E_ERROR,
		E_PARSE,
		E_USER_ERROR,
		E_COMPILE_ERROR,
		E_RECOVERABLE_ERROR,
	);

	return in_array( $error['type'], $error_types_to_handle, true );
}

/**
 * Wraps the shutdown handler function so it can be made pluggable at a later
 * stage.
 *
 * @since 5.1.0
 *
 * @return void
 */
function wp_shutdown_handler_wrapper() {
	if ( defined( 'WP_EXECUTION_SUCCEEDED' ) && WP_EXECUTION_SUCCEEDED ) {
		return;
	}

	// Load the pluggable shutdown handler in case we found one.
	if ( function_exists( 'wp_handle_shutdown' ) ) {
		$stop_propagation = false;

		try {
			$stop_propagation = (bool) wp_handle_shutdown();
		} catch ( Exception $exception ) {
			// Catch exceptions and remain silent.
		}

		if ( $stop_propagation ) {
			return;
		}
	}

	$error = error_get_last();

	// No error, just skip the error handling code.
	if ( null === $error ) {
		return;
	}

	// Bail early if this error should not be handled.
	if ( ! wp_should_handle_error( $error ) ) {
		return;
	}

	try {
		// Persist the detected error.
		wp_record_extension_error( $error );

		/*
		 * If we happen to be on a protected endpoint, we try to redirect to
		 * catch multiple errors in one go.
		 */
		if ( is_protected_endpoint() ) {
			/*
			 * Pluggable is usually loaded after plugins, so we manually
			 * include it here for redirection functionality.
			 */
			if ( ! function_exists( 'wp_redirect' ) ) {
				include ABSPATH . WPINC . '/pluggable.php';
			}

			$scheme = is_ssl() ? 'https://' : 'http://';

			$url = "{$scheme}{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
			wp_redirect( $url );
			exit;
		}

		// Load custom PHP error template, if present.
		$php_error_pluggable = WP_CONTENT_DIR . '/php-error.php';
		if ( is_readable( $php_error_pluggable ) ) {
			/*
			 * This drop-in should control the HTTP status code and print the
			 * HTML markup indicating that a PHP error occurred. Alternatively,
			 * `wp_die()` can be used.
			 */
			require_once $php_error_pluggable;
			die();
		}

		// Otherwise, fall back to a default wp_die() message.
		$message = sprintf(
			'<p>%s</p>',
			__( 'The site is experiencing technical difficulties.' )
		);

		if ( function_exists( 'get_admin_url' ) ) {
			$message .= sprintf(
				'<hr><p><em>%s <a href="%s">%s</a></em></p>',
				__( 'Are you the site owner?' ),
				get_admin_url(),
				__( 'Log into the admin backend to fix this.' )
			);
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the message that the default PHP error page displays.
			 *
			 * @since 5.1.0
			 *
			 * @param string $message HTML error message to display.
			 */
			$message = apply_filters( 'wp_technical_issues_display', $message );
		}

		wp_die( $message, '', 500 );

	} catch ( Exception $exception ) {
		// Catch exceptions and remain silent.
	}
}

/**
 * Registers the WordPress premature shutdown handler.
 *
 * @since 5.1.0
 *
 * @return void
 */
function wp_register_premature_shutdown_handler() {
	register_shutdown_function( 'wp_shutdown_handler_wrapper' );
}
