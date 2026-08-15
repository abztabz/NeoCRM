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
	const SCHEMA_VERSION = '0.5.0';

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
		$leads      = self::table( 'leads' );
		$activities = self::table( 'activities' );
		$companies  = self::table( 'companies' );
		$pipelines  = self::table( 'pipelines' );
		$stages     = self::table( 'pipeline_stages' );
		$deals      = self::table( 'deals' );
		$tasks      = self::table( 'tasks' );
		$notes      = self::table( 'notes' );
		$journeys   = self::table( 'customer_journeys' );
		$journey_updates = self::table( 'journey_updates' );

		dbDelta( "CREATE TABLE {$companies} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			domain varchar(190) NOT NULL DEFAULT '',
			phone varchar(50) NOT NULL DEFAULT '',
			website text DEFAULT NULL,
			industry varchar(120) NOT NULL DEFAULT '',
			address longtext DEFAULT NULL,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name),
			KEY domain (domain),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$leads} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned DEFAULT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL,
			phone varchar(50) NOT NULL DEFAULT '',
			company varchar(190) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT 'new',
			source varchar(100) NOT NULL DEFAULT 'website_form',
			score int(11) NOT NULL DEFAULT 0,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			marketing_opt_in_requested tinyint(1) NOT NULL DEFAULT 0,
			opt_in_requested_at datetime DEFAULT NULL,
			converted_contact_id bigint(20) unsigned DEFAULT NULL,
			converted_company_id bigint(20) unsigned DEFAULT NULL,
			converted_deal_id bigint(20) unsigned DEFAULT NULL,
			converted_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY status (status),
			KEY visitor_id (visitor_id),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$contacts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			company_id bigint(20) unsigned DEFAULT NULL,
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
			KEY company_id (company_id),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$pipelines} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY is_default (is_default)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$stages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_id bigint(20) unsigned NOT NULL,
			name varchar(120) NOT NULL,
			position int(10) unsigned NOT NULL DEFAULT 0,
			probability int(10) unsigned NOT NULL DEFAULT 0,
			color char(7) NOT NULL DEFAULT '#3157d5',
			is_closed tinyint(1) NOT NULL DEFAULT 0,
			is_won tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY pipeline_position (pipeline_id, position)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$deals} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pipeline_id bigint(20) unsigned NOT NULL,
			stage_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned DEFAULT NULL,
			company_id bigint(20) unsigned DEFAULT NULL,
			name varchar(190) NOT NULL,
			amount decimal(18,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT 'AED',
			probability int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			expected_close_date date DEFAULT NULL,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY pipeline_stage (pipeline_id, stage_id),
			KEY contact_id (contact_id),
			KEY company_id (company_id),
			KEY status (status),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$tasks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contact_id bigint(20) unsigned DEFAULT NULL,
			company_id bigint(20) unsigned DEFAULT NULL,
			deal_id bigint(20) unsigned DEFAULT NULL,
			title varchar(190) NOT NULL,
			description longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			priority varchar(20) NOT NULL DEFAULT 'normal',
			due_at datetime DEFAULT NULL,
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_due (status, due_at),
			KEY contact_id (contact_id),
			KEY company_id (company_id),
			KEY deal_id (deal_id),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$notes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contact_id bigint(20) unsigned DEFAULT NULL,
			company_id bigint(20) unsigned DEFAULT NULL,
			deal_id bigint(20) unsigned DEFAULT NULL,
			body longtext NOT NULL,
			author_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY contact_created (contact_id, created_at),
			KEY company_created (company_id, created_at),
			KEY deal_created (deal_id, created_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$journeys} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned DEFAULT NULL,
			lead_id bigint(20) unsigned DEFAULT NULL,
			contact_id bigint(20) unsigned DEFAULT NULL,
			company_id bigint(20) unsigned DEFAULT NULL,
			deal_id bigint(20) unsigned DEFAULT NULL,
			stage varchar(30) NOT NULL DEFAULT 'visitor',
			source varchar(100) NOT NULL DEFAULT 'website',
			entry_method varchar(30) NOT NULL DEFAULT 'website',
			estimated_value decimal(18,2) NOT NULL DEFAULT 0,
			recurring_value decimal(18,2) NOT NULL DEFAULT 0,
			billing_interval varchar(20) NOT NULL DEFAULT '',
			requirements longtext DEFAULT NULL,
			next_follow_up_at datetime DEFAULT NULL,
			communication_medium varchar(20) NOT NULL DEFAULT '',
			owner_user_id bigint(20) unsigned DEFAULT NULL,
			stage_entered_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY visitor_id (visitor_id),
			UNIQUE KEY lead_id (lead_id),
			KEY contact_id (contact_id),
			KEY deal_id (deal_id),
			KEY stage_updated (stage, updated_at),
			KEY owner_user_id (owner_user_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$journey_updates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			journey_id bigint(20) unsigned NOT NULL,
			stage varchar(30) NOT NULL,
			update_type varchar(30) NOT NULL DEFAULT 'team_note',
			body longtext NOT NULL,
			communication_medium varchar(20) NOT NULL DEFAULT '',
			author_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY journey_stage_created (journey_id, stage, created_at),
			KEY update_type (update_type)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$visitors} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_uuid char(36) NOT NULL,
			lead_id bigint(20) unsigned DEFAULT NULL,
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
			KEY lead_id (lead_id),
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
			lead_id bigint(20) unsigned DEFAULT NULL,
			contact_id bigint(20) unsigned DEFAULT NULL,
			visitor_id bigint(20) unsigned DEFAULT NULL,
			activity_type varchar(40) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			body longtext DEFAULT NULL,
			metadata longtext DEFAULT NULL,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
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
		$wpdb->query( 'UPDATE ' . self::table( 'visitors' ) . " SET ip_hash = '' WHERE ip_hash <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::seed_default_pipeline();
		self::backfill_customer_journeys();
	}

	/** Build lifecycle records for data created before the unified funnel existed. */
	private static function backfill_customer_journeys() {
		global $wpdb;
		$journeys = self::table( 'customer_journeys' );
		$visitors = self::table( 'visitors' );
		$leads    = self::table( 'leads' );
		$contacts = self::table( 'contacts' );
		$deals    = self::table( 'deals' );
		$now      = self::now();

		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$journeys} (visitor_id, stage, source, entry_method, stage_entered_at, created_at, updated_at) SELECT id, 'visitor', 'website', 'website', %s, first_seen, last_seen FROM {$visitors}", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$journeys} j INNER JOIN {$leads} l ON l.visitor_id=j.visitor_id SET j.lead_id=l.id, j.stage=CASE l.status WHEN 'working' THEN 'contacted' WHEN 'qualified' THEN 'qualified' WHEN 'disqualified' THEN 'lost' ELSE 'lead' END, j.source=l.source, j.updated_at=l.updated_at WHERE j.lead_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$journeys} (lead_id, stage, source, entry_method, owner_user_id, stage_entered_at, created_at, updated_at) SELECT l.id, CASE l.status WHEN 'working' THEN 'contacted' WHEN 'qualified' THEN 'qualified' WHEN 'disqualified' THEN 'lost' ELSE 'lead' END, l.source, 'upgrade', l.owner_user_id, %s, l.created_at, l.updated_at FROM {$leads} l LEFT JOIN {$journeys} j ON j.lead_id=l.id WHERE j.id IS NULL", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$journeys} j INNER JOIN {$leads} l ON l.id=j.lead_id INNER JOIN {$contacts} c ON c.id=l.converted_contact_id SET j.contact_id=c.id, j.company_id=c.company_id, j.stage=CASE c.status WHEN 'customer' THEN 'paying_client' WHEN 'recurring' THEN 'recurring_client' WHEN 'lost' THEN 'lost' WHEN 'qualified' THEN 'qualified' WHEN 'contacted' THEN 'contacted' ELSE j.stage END, j.updated_at=c.updated_at WHERE j.contact_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$journeys} (contact_id, company_id, stage, source, entry_method, owner_user_id, stage_entered_at, created_at, updated_at) SELECT c.id, c.company_id, CASE c.status WHEN 'customer' THEN 'paying_client' WHEN 'recurring' THEN 'recurring_client' WHEN 'lost' THEN 'lost' WHEN 'qualified' THEN 'qualified' WHEN 'contacted' THEN 'contacted' ELSE 'lead' END, c.source, 'upgrade', c.owner_user_id, %s, c.created_at, c.updated_at FROM {$contacts} c LEFT JOIN {$journeys} j ON j.contact_id=c.id WHERE j.id IS NULL", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$journeys} j INNER JOIN {$deals} d ON d.contact_id=j.contact_id SET j.deal_id=d.id, j.company_id=COALESCE(j.company_id,d.company_id), j.estimated_value=d.amount, j.stage=CASE d.status WHEN 'won' THEN 'paying_client' WHEN 'lost' THEN 'lost' ELSE j.stage END, j.updated_at=d.updated_at WHERE j.deal_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Create the first sales pipeline without overwriting a user's stages. */
	private static function seed_default_pipeline() {
		global $wpdb;
		$pipelines = self::table( 'pipelines' );
		$stages    = self::table( 'pipeline_stages' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pipelines}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$now = self::now();
		$wpdb->insert( $pipelines, array( 'name' => 'Sales Pipeline', 'is_default' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$pipeline_id = (int) $wpdb->insert_id;
		$defaults = array(
			array( 'New', 10, '#64748b' ),
			array( 'Qualified', 25, '#3157d5' ),
			array( 'Proposal', 50, '#7c3aed' ),
			array( 'Negotiation', 75, '#d97706' ),
			array( 'Won', 100, '#07834a', 1, 1 ),
			array( 'Lost', 0, '#b42318', 1, 0 ),
		);
		foreach ( $defaults as $position => $stage ) {
			$wpdb->insert(
				$stages,
				array(
					'pipeline_id' => $pipeline_id,
					'name'        => $stage[0],
					'position'    => $position,
					'probability' => $stage[1],
					'color'       => $stage[2],
					'is_closed'   => $stage[3] ?? 0,
					'is_won'      => $stage[4] ?? 0,
				)
			);
		}
	}

	/** Record a human-readable CRM audit activity. */
	public static function log_activity( $type, $subject, $contact_id = null, $metadata = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'activities' ),
			array(
				'contact_id'   => $contact_id ? absint( $contact_id ) : null,
				'activity_type'=> sanitize_key( $type ),
				'subject'      => sanitize_text_field( $subject ),
				'metadata'     => wp_json_encode( is_array( $metadata ) ? $metadata : array() ),
				'actor_user_id'=> get_current_user_id() ?: null,
				'created_at'   => self::now(),
			)
		);
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
