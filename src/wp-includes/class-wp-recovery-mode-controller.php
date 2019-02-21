<?php

/**
 * Interface WP_Recovery_Mode_Controller
 */
interface WP_Recovery_Mode_Controller {

	/**
	 * Run the processor.
	 *
	 * This can be used for adding hooks, parsing the global request data,
	 * exiting the request due to errors, etc..
	 *
	 * @since 5.2.0
	 *
	 * @return void
	 */
	public function run();

	/**
	 * Is recovery mode active.
	 *
	 * @since 5.2.0
	 *
	 * @return bool
	 */
	public function is_recovery_mode_active();

	/**
	 * Get the recovery mode session ID.
	 *
	 * @since 5.2.0
	 *
	 * @return string|false
	 */
	public function get_recovery_mode_session_id();
}
