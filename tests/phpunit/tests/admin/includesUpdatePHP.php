<?php

/**
 * @group admin
 * @group upgrade
 */
class Tests_Admin_IncludesUpdatePHP extends WP_UnitTestCase {

	protected static $user;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$user = $factory->user->create_and_get( array( 'role' => 'administrator' ) );

		grant_super_admin( self::$user->ID );
	}

	public static function wpTearDownAfterClass() {
		wp_delete_user( self::$user->ID );
	}

	public function test_display_notice_when_old_php() {
		wp_set_current_user( self::$user->ID );
		add_filter( 'wp_is_php_version_outdated', '__return_true' );

		$this->assertTrue( wp_should_display_upgrade_php_notice() );
	}

	public function test_hide_notice_when_old_php() {
		wp_set_current_user( self::$user->ID );
		add_filter( 'wp_is_php_version_outdated', '__return_false' );

		$this->assertFalse( wp_should_display_upgrade_php_notice() );
	}

	public function test_hide_notice_when_dismissed_pointer() {
		wp_set_current_user( self::$user->ID );
		add_filter( 'wp_is_php_version_outdated', '__return_true' );
		update_user_meta( self::$user->ID, 'dismissed_wp_pointers', 'upgrade_php_notice' );

		$this->assertFalse( wp_should_display_upgrade_php_notice() );
	}

	public function test_hide_notice_when_lacking_capabilities() {
		wp_set_current_user( self::$user->ID );
		add_filter( 'wp_is_php_version_outdated', '__return_true' );
		add_filter( 'map_meta_cap', array( $this, 'filter_prevent_upgrade_php_cap' ), 10, 2 );

		$this->assertFalse( wp_should_display_upgrade_php_notice() );
	}

	public function filter_prevent_upgrade_php_cap( $caps, $cap ) {
		if ( 'upgrade_php' === $cap ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}
}
