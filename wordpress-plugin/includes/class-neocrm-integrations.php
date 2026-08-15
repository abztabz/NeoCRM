<?php
/**
 * Native integrations with existing WordPress form plugins.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Integrations {
	/** Prevent duplicate processing when another Elementor action replays the same record in one request. */
	private static $processed = array();

	public function __construct() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'capture_elementor_form' ), 10, 2 );
	}

	/**
	 * Convert a successful Elementor contact/enquiry submission into a NeoCRM Lead.
	 *
	 * Elementor email and other configured actions continue normally. Forms without an
	 * email plus a message-like field are ignored, avoiding newsletter/login capture.
	 *
	 * @param object $record  Elementor form record.
	 * @param object $handler Elementor AJAX handler.
	 */
	public function capture_elementor_form( $record, $handler ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$raw_fields = $record->get( 'fields' );
		if ( ! is_array( $raw_fields ) ) {
			return;
		}

		$fields = $this->normalize_elementor_fields( $raw_fields );
		$email  = sanitize_email( $this->first_value( $fields, array( 'email', 'email_address', 'your_email' ) ) );
		$message = $this->first_value( $fields, array( 'message', 'your_message', 'enquiry', 'inquiry', 'comments', 'comment' ) );
		$name    = $this->first_value( $fields, array( 'first_name', 'firstname', 'given_name', 'name', 'your_name' ) );
		$last    = $this->first_value( $fields, array( 'last_name', 'lastname', 'surname', 'family_name' ) );

		if ( ! is_email( $email ) || '' === trim( $name ) || '' === trim( $message ) ) {
			return;
		}

		$form_name = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		$form_id   = method_exists( $record, 'get_form_settings' ) ? sanitize_key( (string) $record->get_form_settings( 'form_id' ) ) : '';
		$enabled       = (bool) apply_filters( 'neocrm_capture_elementor_form', true, $form_id, $form_name, $fields );
		if ( ! $enabled ) {
			return;
		}

		$fingerprint = hash( 'sha256', $form_id . '|' . strtolower( $email ) . '|' . $message );
		if ( isset( self::$processed[ $fingerprint ] ) ) {
			return;
		}
		self::$processed[ $fingerprint ] = true;

		global $wpdb;
		$now          = NeoCRM_DB::now();
		$visitor_uuid = isset( $_COOKIE[ NeoCRM_Tracker::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ NeoCRM_Tracker::COOKIE_NAME ] ) ) : '';
		$visitor_id   = preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $visitor_uuid ) ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'visitors' ) . ' WHERE visitor_uuid=%s', strtolower( $visitor_uuid ) ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$score        = $visitor_id ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT engagement_score FROM ' . NeoCRM_DB::table( 'visitors' ) . ' WHERE id=%d', $visitor_id ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$source       = 'elementor_form';
		if ( $form_name ) {
			$source .= ':' . sanitize_key( $form_name );
		}

		$inserted = $wpdb->insert(
			NeoCRM_DB::table( 'leads' ),
			array(
				'visitor_id'  => $visitor_id ?: null,
				'first_name'  => $this->short_text( $name, 100 ),
				'last_name'   => $this->short_text( $last, 100 ),
				'email'       => $email,
				'phone'       => $this->short_text( $this->first_value( $fields, array( 'phone', 'telephone', 'mobile', 'your_phone' ) ), 50 ),
				'company'     => $this->short_text( $this->first_value( $fields, array( 'company', 'organisation', 'organization', 'business' ) ), 190 ),
				'status'      => 'new',
				'source'      => $this->short_text( $source, 100 ),
				'score'       => max( 0, min( 100, $score ) ),
				'owner_user_id' => null,
				'marketing_opt_in_requested' => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$lead_id = (int) $wpdb->insert_id;
		if ( false === $inserted || ! $lead_id ) {
			return;
		}

		if ( $visitor_id ) {
			$wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'lead_id' => $lead_id ), array( 'id' => $visitor_id ) );
		}
		NeoCRM_Funnel::attach_lead( $lead_id, $visitor_id, 'lead', 'website' );

		$message = sanitize_textarea_field( $message );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 5000 ) : substr( $message, 0, 5000 );
		$wpdb->insert(
			NeoCRM_DB::table( 'activities' ),
			array(
				'lead_id'       => $lead_id,
				'visitor_id'    => $visitor_id ?: null,
				'activity_type' => 'form_submission',
				'subject'       => __( 'Elementor contact form enquiry received', 'neo-crm' ),
				'body'          => $message,
				'metadata'      => wp_json_encode( array( 'form_id' => $form_id, 'form_name' => $this->short_text( $form_name, 100 ), 'page_url' => $this->current_page_url() ) ),
				'created_at'    => $now,
			)
		);

		do_action( 'neocrm_lead_captured', $lead_id, $visitor_id, $record );
	}

	/** Flatten Elementor field IDs and labels into lookup-safe aliases. */
	private function normalize_elementor_fields( $raw_fields ) {
		$normalized = array();
		foreach ( $raw_fields as $id => $field ) {
			$value = is_array( $field ) ? ( $field['value'] ?? '' ) : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
			}
			$normalized[ $this->normalize_key( $id ) ] = (string) $value;
			if ( is_array( $field ) && ! empty( $field['title'] ) ) {
				$normalized[ $this->normalize_key( $field['title'] ) ] = (string) $value;
			}
		}
		return $normalized;
	}

	private function first_value( $fields, $keys ) {
		foreach ( $keys as $key ) {
			$key = $this->normalize_key( $key );
			if ( isset( $fields[ $key ] ) && '' !== trim( (string) $fields[ $key ] ) ) {
				return (string) $fields[ $key ];
			}
		}
		return '';
	}

	private function normalize_key( $value ) {
		return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( (string) $value ) ) ) );
	}

	private function short_text( $value, $length ) {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private function current_page_url() {
		$referer = wp_get_referer();
		if ( ! $referer ) {
			return '';
		}
		$parts = wp_parse_url( $referer );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return '';
		}
		return esc_url_raw( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( $parts['path'] ?? '/' ) );
	}
}
