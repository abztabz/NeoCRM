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
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email = %s', sanitize_email( $email_address ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $contact ) {
			return array( 'data' => array(), 'done' => true );
		}

		$data = array();
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'company', 'status', 'source', 'marketing_consent', 'consent_recorded_at', 'created_at' ) as $key ) {
			$data[] = array( 'name' => $key, 'value' => (string) $contact[ $key ] );
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'neocrm-contact',
					'group_label' => __( 'NeoCRM contact record', 'neo-crm' ),
					'item_id'     => 'contact-' . $contact['id'],
					'data'        => $data,
				),
			),
			'done' => true,
		);
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		$email   = sanitize_email( $email_address );
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $contact ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$anonymous_email = 'deleted+' . absint( $contact->id ) . '@example.invalid';
		$wpdb->update(
			NeoCRM_DB::table( 'contacts' ),
			array(
				'first_name'         => '',
				'last_name'          => '',
				'email'              => $anonymous_email,
				'phone'              => '',
				'company'            => '',
				'marketing_consent'  => 0,
				'consent_recorded_at' => null,
				'updated_at'         => NeoCRM_DB::now(),
			),
			array( 'id' => absint( $contact->id ) )
		);
		$wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'contact_id' => null ), array( 'contact_id' => absint( $contact->id ) ) );
		$wpdb->delete( NeoCRM_DB::table( 'activities' ), array( 'contact_id' => absint( $contact->id ) ) );

		return array( 'items_removed' => true, 'items_retained' => false, 'messages' => array(), 'done' => true );
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
