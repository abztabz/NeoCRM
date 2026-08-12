<?php
/**
 * Installation and lifecycle operations.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Activator {
	/**
	 * Create storage, capabilities, and scheduled maintenance.
	 */
	public static function activate() {
		NeoCRM_DB::install();
		self::add_capabilities();

		if ( ! wp_next_scheduled( 'neocrm_daily_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'neocrm_daily_retention' );
		}
	}

	/**
	 * Stop scheduled work without deleting customer data.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'neocrm_daily_retention' );
	}

	/**
	 * Grant CRM access to administrators.
	 */
	private static function add_capabilities() {
		$role = get_role( 'administrator' );

		if ( $role ) {
			$role->add_cap( 'manage_neocrm' );
			$role->add_cap( 'view_neocrm' );
		}
	}
}

