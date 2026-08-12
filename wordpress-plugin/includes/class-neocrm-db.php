<?php
/**
 * NeoCRM persistence layer.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_DB {
	const SCHEMA_VERSION = '0.1.0';

	/**
	 * Return a prefixed table name.
	 *
	 * @param string $name Logical table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'neocrm_' . $name;
	}

	/**
	 * Install or update custom tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$visitors   = self::table( 'visitors' );
		$sessions   = self::table( 'sessions' );
		$events     = self::table( 'events' );
		$contacts   = self::table( 'contacts' );
		$activities = self::table( 'activities' );

		dbDelta( "CREATE TABLE {$contacts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			company varchar(190) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'new',
			source varchar(100) NOT NULL DEFAULT 'website',
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			marketing_consent tinyint(1) NOT NULL DEFAULT 0,
			consent_recorded_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$visitors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_uuid char(36) NOT NULL,
			contact_id bigint(20) unsigned DEFAULT NULL,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			first_landing_url text DEFAULT NULL,
			first_referrer text DEFAULT NULL,
			locale varchar(20) NOT NULL DEFAULT '',
			device_type varchar(20) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			consent_status varchar(20) NOT NULL DEFAULT 'analytics',
			sessions_count int(10) unsigned NOT NULL DEFAULT 0,
			pageviews_count int(10) unsigned NOT NULL DEFAULT 0,
			engagement_score int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY visitor_uuid (visitor_uuid),
			KEY contact_id (contact_id),
			KEY last_seen (last_seen),
			KEY engagement_score (engagement_score)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL,
			session_uuid char(36) NOT NULL,
			started_at datetime NOT NULL,
			last_activity_at datetime NOT NULL,
			landing_url text DEFAULT NULL,
			referrer text DEFAULT NULL,
			utm_source varchar(190) NOT NULL DEFAULT '',
			utm_medium varchar(190) NOT NULL DEFAULT '',
			utm_campaign varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_uuid (session_uuid),
			KEY visitor_id (visitor_id),
			KEY last_activity_at (last_activity_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			event_type varchar(40) NOT NULL,
			page_url text DEFAULT NULL,
			page_title varchar(255) NOT NULL DEFAULT '',
			element_name varchar(190) NOT NULL DEFAULT '',
			event_data longtext DEFAULT NULL,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY occurred_at (occurred_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$activities} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contact_id bigint(20) unsigned DEFAULT NULL,
			visitor_id bigint(20) unsigned DEFAULT NULL,
			activity_type varchar(40) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id),
			KEY visitor_id (visitor_id),
			KEY created_at (created_at)
		) {$charset};" );

		if ( false === get_option( 'neocrm_settings', false ) ) {
			add_option(
				'neocrm_settings',
				array(
					'tracking_enabled'         => 1,
					'consent_mode'            => 'required',
					'event_retention_days'     => 90,
					'cloud_enabled'            => 0,
					'cloud_endpoint'           => '',
					'cloud_site_id'            => '',
					'cloud_site_token'         => '',
					'delete_data_on_uninstall' => 0,
				)
			);
		}

		update_option( 'neocrm_schema_version', self::SCHEMA_VERSION );
	}

	/**
	 * Run database upgrades when the plugin code is newer.
	 */
	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION !== get_option( 'neocrm_schema_version' ) ) {
			self::install();
		}
	}

	/**
	 * Remove raw visitor events after the configured retention window.
	 */
	public static function run_retention() {
		global $wpdb;

		$settings = wp_parse_args( get_option( 'neocrm_settings', array() ), array( 'event_retention_days' => 90 ) );
		$days     = max( 7, min( 730, absint( $settings['event_retention_days'] ) ) );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $days ) );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'events' ) . ' WHERE occurred_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'sessions' ) . ' WHERE last_activity_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Return current UTC time in WordPress database format.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql', true );
	}
}
