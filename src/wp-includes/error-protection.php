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
