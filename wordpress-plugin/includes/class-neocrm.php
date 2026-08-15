<?php
/**
 * Plugin bootstrap.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NeoCRM {
	/** @var NeoCRM|null */
	private static $instance = null;

	/**
	 * Return the singleton instance.
	 *
	 * @return NeoCRM
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
			NeoCRM_DB::maybe_upgrade();
			new NeoCRM_Tracker();
			new NeoCRM_Funnel();
			new NeoCRM_Integrations();
			new NeoCRM_Sales();
		new NeoCRM_Admin();
		new NeoCRM_Privacy();

		add_action( 'neocrm_daily_retention', array( 'NeoCRM_DB', 'run_retention' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'neo-crm', false, dirname( plugin_basename( NEOCRM_FILE ) ) . '/languages' );
	}
}
