<?php
/**
 * Optional data removal for NeoCRM.
 *
 * @package NeoCRM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'neocrm_settings', array() );
if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'journey_updates', 'customer_journeys', 'notes', 'tasks', 'deals', 'pipeline_stages', 'pipelines', 'activities', 'events', 'sessions', 'visitors', 'leads', 'contacts', 'companies' ) as $name ) {
	$table = $wpdb->prefix . 'neocrm_' . $name;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'neocrm_settings' );
delete_option( 'neocrm_schema_version' );
wp_clear_scheduled_hook( 'neocrm_daily_retention' );
