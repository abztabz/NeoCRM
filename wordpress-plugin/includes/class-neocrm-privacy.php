<?php
/**
 * WordPress privacy-tool integration.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Privacy {
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	public function register_exporter( $exporters ) {
		$exporters['neocrm'] = array(
			'exporter_friendly_name' => __( 'NeoCRM contacts', 'neo-crm' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['neocrm'] = array(
			'eraser_friendly_name' => __( 'NeoCRM contacts', 'neo-crm' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email   = sanitize_email( $email_address );
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$leads   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE email = %s ORDER BY created_at', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $contact && ! $leads ) {
			return array( 'data' => array(), 'done' => true );
		}

		$items = array();
		if ( $contact ) {
			$data = array();
			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'company', 'status', 'source', 'marketing_consent', 'consent_recorded_at', 'created_at' ) as $key ) {
				$data[] = array( 'name' => $key, 'value' => (string) $contact[ $key ] );
			}
			$items[] = array( 'group_id' => 'neocrm-contact', 'group_label' => __( 'NeoCRM contact record', 'neo-crm' ), 'item_id' => 'contact-' . $contact['id'], 'data' => $data );
		}
		foreach ( $leads as $lead ) {
			$data = array();
			foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'company', 'status', 'source', 'marketing_opt_in_requested', 'opt_in_requested_at', 'created_at' ) as $key ) {
				$data[] = array( 'name' => $key, 'value' => (string) $lead[ $key ] );
			}
			$items[] = array( 'group_id' => 'neocrm-lead', 'group_label' => __( 'NeoCRM lead submissions', 'neo-crm' ), 'item_id' => 'lead-' . $lead['id'], 'data' => $data );
			$activities = $wpdb->get_results( $wpdb->prepare( 'SELECT id, subject, body, created_at FROM ' . NeoCRM_DB::table( 'activities' ) . ' WHERE lead_id = %d ORDER BY created_at', $lead['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $activities as $activity ) {
				$items[] = array( 'group_id' => 'neocrm-lead-activity', 'group_label' => __( 'NeoCRM lead messages', 'neo-crm' ), 'item_id' => 'lead-activity-' . $activity['id'], 'data' => array( array( 'name' => 'subject', 'value' => (string) $activity['subject'] ), array( 'name' => 'message', 'value' => (string) $activity['body'] ), array( 'name' => 'created_at', 'value' => (string) $activity['created_at'] ) ) );
			}
		}
		if ( $contact ) {
			$notes = $wpdb->get_results( $wpdb->prepare( 'SELECT id, body, created_at FROM ' . NeoCRM_DB::table( 'notes' ) . ' WHERE contact_id = %d ORDER BY created_at', $contact['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $notes as $note ) {
				$items[] = array( 'group_id' => 'neocrm-notes', 'group_label' => __( 'NeoCRM contact notes', 'neo-crm' ), 'item_id' => 'note-' . $note['id'], 'data' => array( array( 'name' => 'body', 'value' => (string) $note['body'] ), array( 'name' => 'created_at', 'value' => (string) $note['created_at'] ) ) );
			}
		}
		$journey_conditions = array();
		if ( $contact ) { $journey_conditions[] = $wpdb->prepare( 'contact_id = %d', $contact['id'] ); }
		if ( $leads ) {
			$lead_ids = array_map( static fn( $lead ) => absint( $lead['id'] ), $leads );
			$journey_conditions[] = 'lead_id IN (' . implode( ',', $lead_ids ) . ')';
		}
		if ( $journey_conditions ) {
			$journeys = $wpdb->get_results( 'SELECT id, stage, source, estimated_value, recurring_value, billing_interval, requirements, next_follow_up_at, communication_medium, stage_entered_at FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE ' . implode( ' OR ', $journey_conditions ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $journeys as $journey ) {
				$data = array();
				foreach ( array( 'stage', 'source', 'estimated_value', 'recurring_value', 'billing_interval', 'requirements', 'next_follow_up_at', 'communication_medium', 'stage_entered_at' ) as $key ) { $data[] = array( 'name' => $key, 'value' => (string) $journey[ $key ] ); }
				$items[] = array( 'group_id' => 'neocrm-journey', 'group_label' => __( 'NeoCRM sales journey', 'neo-crm' ), 'item_id' => 'journey-' . $journey['id'], 'data' => $data );
				$updates = $wpdb->get_results( $wpdb->prepare( 'SELECT id, stage, update_type, body, communication_medium, created_at FROM ' . NeoCRM_DB::table( 'journey_updates' ) . ' WHERE journey_id=%d ORDER BY created_at', $journey['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				foreach ( $updates as $update ) {
					$update_data = array();
					foreach ( array( 'stage', 'update_type', 'body', 'communication_medium', 'created_at' ) as $key ) { $update_data[] = array( 'name' => $key, 'value' => (string) $update[ $key ] ); }
					$items[] = array( 'group_id' => 'neocrm-journey-update', 'group_label' => __( 'NeoCRM journey team history', 'neo-crm' ), 'item_id' => 'journey-update-' . $update['id'], 'data' => $update_data );
				}
			}
		}

		return array( 'data' => $items, 'done' => true );
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email   = sanitize_email( $email_address );
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$lead_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE email = %s', $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $contact && ! $lead_ids ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$journey_conditions = array();
		if ( $contact ) { $journey_conditions[] = $wpdb->prepare( 'contact_id=%d', absint( $contact->id ) ); }
		if ( $lead_ids ) { $journey_conditions[] = 'lead_id IN (' . implode( ',', $lead_ids ) . ')'; }
		$journey_ids = $journey_conditions ? array_map( 'absint', $wpdb->get_col( 'SELECT id FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE ' . implode( ' OR ', $journey_conditions ) ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $journey_ids ) {
			$journey_placeholders = implode( ',', array_fill( 0, count( $journey_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NeoCRM_DB::table( 'journey_updates' ) . " WHERE journey_id IN ({$journey_placeholders})", $journey_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NeoCRM_DB::table( 'customer_journeys' ) . " SET requirements='', next_follow_up_at=NULL, communication_medium='', updated_at=%s WHERE id IN ({$journey_placeholders})", array_merge( array( NeoCRM_DB::now() ), $journey_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $lead_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . " WHERE contact_id IS NULL AND lead_id IN ({$placeholders})", $lead_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NeoCRM_DB::table( 'customer_journeys' ) . " SET lead_id = NULL WHERE lead_id IN ({$placeholders})", $lead_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NeoCRM_DB::table( 'activities' ) . " WHERE lead_id IN ({$placeholders})", $lead_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NeoCRM_DB::table( 'visitors' ) . " SET lead_id = NULL WHERE lead_id IN ({$placeholders})", $lead_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NeoCRM_DB::table( 'leads' ) . " WHERE id IN ({$placeholders})", $lead_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $contact ) {
			$contact_id = absint( $contact->id );
			$anonymous_email = 'deleted+' . $contact_id . '@example.invalid';
			$wpdb->update( NeoCRM_DB::table( 'contacts' ), array( 'first_name' => '', 'last_name' => '', 'email' => $anonymous_email, 'phone' => '', 'company' => '', 'company_id' => null, 'marketing_consent' => 0, 'consent_recorded_at' => null, 'updated_at' => NeoCRM_DB::now() ), array( 'id' => $contact_id ) );
			$wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'contact_id' => null ), array( 'contact_id' => $contact_id ) );
			$wpdb->update( NeoCRM_DB::table( 'deals' ), array( 'contact_id' => null ), array( 'contact_id' => $contact_id ) );
			$wpdb->update( NeoCRM_DB::table( 'tasks' ), array( 'contact_id' => null ), array( 'contact_id' => $contact_id ) );
			$wpdb->update( NeoCRM_DB::table( 'customer_journeys' ), array( 'contact_id' => null, 'updated_at' => NeoCRM_DB::now() ), array( 'contact_id' => $contact_id ) );
			$wpdb->delete( NeoCRM_DB::table( 'notes' ), array( 'contact_id' => $contact_id ) );
			$wpdb->delete( NeoCRM_DB::table( 'activities' ), array( 'contact_id' => $contact_id ) );
		}

		return array( 'items_removed' => true, 'items_retained' => true, 'messages' => array( __( 'Anonymous visitor analytics and non-contact business records may remain until their configured retention period expires.', 'neo-crm' ) ), 'done' => true );
	}

	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'NeoCRM',
			wp_kses_post(
				__( 'NeoCRM may store consented first-party website activity, including pages visited, referral information, interactions, and information voluntarily submitted through CRM forms. Configure consent and retention under NeoCRM &gt; Settings.', 'neo-crm' )
			)
		);
	}
}
