<?php
/**
 * First-party visitor tracking and lead capture.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Tracker {
	const COOKIE_NAME = 'neocrm_visitor';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
		add_shortcode( 'neocrm_lead_form', array( $this, 'render_lead_form' ) );
	}

	/**
	 * Register public, rate-limited REST endpoints.
	 */
	public function register_routes() {
		register_rest_route(
			'neo-crm/v1',
			'/event',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture_event' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'neo-crm/v1',
			'/lead',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture_lead' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Load the small first-party tracker on public pages.
	 */
	public function enqueue_tracker() {
		$settings = wp_parse_args(
			get_option( 'neocrm_settings', array() ),
			array( 'tracking_enabled' => 1, 'consent_mode' => 'required' )
		);

		if ( empty( $settings['tracking_enabled'] ) ) {
			return;
		}

		wp_enqueue_script( 'neocrm-tracker', NEOCRM_URL . 'assets/js/tracker.js', array(), NEOCRM_VERSION, true );
		wp_enqueue_style( 'neocrm-public', NEOCRM_URL . 'assets/css/public.css', array(), NEOCRM_VERSION );
		wp_localize_script(
			'neocrm-tracker',
			'NeoCRMConfig',
			array(
				'eventUrl'    => esc_url_raw( rest_url( 'neo-crm/v1/event' ) ),
				'leadUrl'     => esc_url_raw( rest_url( 'neo-crm/v1/lead' ) ),
				'nonce'       => wp_create_nonce( 'neocrm_public' ),
				'consentMode' => in_array( $settings['consent_mode'], array( 'required', 'external', 'disabled' ), true ) ? $settings['consent_mode'] : 'required',
				'cookieDays'  => 180,
				'i18n'        => array(
					'banner'    => __( 'We use first-party analytics to understand how this website is used and improve your experience.', 'neo-crm' ),
					'accept'    => __( 'Allow analytics', 'neo-crm' ),
					'essential' => __( 'Essential only', 'neo-crm' ),
					'success'   => __( 'Thank you. Your enquiry has been received.', 'neo-crm' ),
					'error'     => __( 'We could not send your enquiry. Please try again.', 'neo-crm' ),
				),
			)
		);
	}

	/**
	 * Store an allowed analytics event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function capture_event( WP_REST_Request $request ) {
		global $wpdb;

		if ( ! $this->is_same_site_request( $request ) ) {
			return new WP_Error( 'neocrm_origin', __( 'Request origin is not allowed.', 'neo-crm' ), array( 'status' => 403 ) );
		}

		if ( ! $this->check_rate_limit( 'event', 180 ) ) {
			return new WP_Error( 'neocrm_rate_limit', __( 'Too many requests.', 'neo-crm' ), array( 'status' => 429 ) );
		}

		$settings = wp_parse_args( get_option( 'neocrm_settings', array() ), array( 'tracking_enabled' => 1 ) );
		if ( empty( $settings['tracking_enabled'] ) || 'analytics' !== sanitize_key( (string) $request->get_param( 'consent' ) ) ) {
			return new WP_REST_Response( array( 'recorded' => false ), 202 );
		}

		$allowed_types = array( 'page_view', 'click', 'scroll', 'heartbeat', 'form_started', 'download', 'voice_intent' );
		$event_type    = sanitize_key( (string) $request->get_param( 'event_type' ) );
		if ( ! in_array( $event_type, $allowed_types, true ) ) {
			return new WP_Error( 'neocrm_event_type', __( 'Event type is not allowed.', 'neo-crm' ), array( 'status' => 400 ) );
		}

		$visitor_uuid = $this->valid_uuid( $request->get_param( 'visitor_uuid' ) );
		$session_uuid = $this->valid_uuid( $request->get_param( 'session_uuid' ) );
		$visitor_uuid = $visitor_uuid ? $visitor_uuid : wp_generate_uuid4();
		$session_uuid = $session_uuid ? $session_uuid : wp_generate_uuid4();
		$request->set_param( 'visitor_uuid', $visitor_uuid );
		$request->set_param( 'session_uuid', $session_uuid );
		$cloud_recorded = ! empty( $settings['cloud_enabled'] ) && true === $this->forward_event_to_cloud( $request, $settings, $event_type );
		$now          = NeoCRM_DB::now();
		$page_url     = $this->clean_url( $request->get_param( 'page_url' ) );
		$referrer     = $this->clean_url( $request->get_param( 'referrer' ) );
		$locale       = substr( sanitize_text_field( (string) $request->get_param( 'locale' ) ), 0, 20 );
		$device       = wp_is_mobile() ? 'mobile' : 'desktop';

		$visitor = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'visitors' ) . ' WHERE visitor_uuid = %s', $visitor_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $visitor ) {
			$wpdb->insert(
				NeoCRM_DB::table( 'visitors' ),
				array(
					'visitor_uuid'      => $visitor_uuid,
					'first_seen'        => $now,
					'last_seen'         => $now,
					'first_landing_url' => $page_url,
					'first_referrer'    => $referrer,
					'locale'            => $locale,
					'device_type'       => $device,
					'ip_hash'           => '',
					'consent_status'    => 'analytics',
				)
			);
			$visitor_id = (int) $wpdb->insert_id;
			} else {
				$visitor_id = (int) $visitor->id;
			$wpdb->update(
				NeoCRM_DB::table( 'visitors' ),
				array( 'last_seen' => $now, 'locale' => $locale, 'device_type' => $device ),
				array( 'id' => $visitor_id )
				);
			}
			NeoCRM_Funnel::ensure_visitor( $visitor_id, $referrer ? 'referral' : 'direct' );

			$session = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'sessions' ) . ' WHERE session_uuid = %s AND visitor_id = %d', $session_uuid, $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $session ) {
			$wpdb->insert(
				NeoCRM_DB::table( 'sessions' ),
				array(
					'visitor_id'      => $visitor_id,
					'session_uuid'    => $session_uuid,
					'started_at'      => $now,
					'last_activity_at'=> $now,
					'landing_url'     => $page_url,
					'referrer'        => $referrer,
					'utm_source'      => $this->short_text( $request->get_param( 'utm_source' ), 190 ),
					'utm_medium'      => $this->short_text( $request->get_param( 'utm_medium' ), 190 ),
					'utm_campaign'    => $this->short_text( $request->get_param( 'utm_campaign' ), 190 ),
				)
			);
			$session_id = (int) $wpdb->insert_id;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NeoCRM_DB::table( 'visitors' ) . ' SET sessions_count = sessions_count + 1 WHERE id = %d', $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$session_id = (int) $session->id;
			$wpdb->update( NeoCRM_DB::table( 'sessions' ), array( 'last_activity_at' => $now ), array( 'id' => $session_id ) );
		}

		$wpdb->insert(
			NeoCRM_DB::table( 'events' ),
			array(
				'visitor_id'  => $visitor_id,
				'session_id'  => $session_id,
				'event_type'  => $event_type,
				'page_url'    => $page_url,
				'page_title'  => $this->short_text( $request->get_param( 'page_title' ), 255 ),
				'element_name'=> $this->short_text( $request->get_param( 'element_name' ), 190 ),
				'event_data'  => wp_json_encode( $this->sanitize_event_data( $request->get_param( 'event_data' ) ) ),
				'occurred_at' => $now,
			)
		);

		$score = $this->score_for_event( $event_type, $page_url );
		$sql   = 'UPDATE ' . NeoCRM_DB::table( 'visitors' ) . ' SET engagement_score = LEAST(100, engagement_score + %d)';
		if ( 'page_view' === $event_type ) {
			$sql .= ', pageviews_count = pageviews_count + 1';
		}
		$sql .= ' WHERE id = %d';
		$wpdb->query( $wpdb->prepare( $sql, $score, $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return new WP_REST_Response(
			array(
				'recorded'     => true,
				'storage'      => $cloud_recorded ? 'cloud_and_local' : 'local',
				'visitor_uuid' => $visitor_uuid,
				'session_uuid' => $session_uuid,
			),
			201
		);
	}

	/**
	 * Create or update a contact and attach the visitor journey.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function capture_lead( WP_REST_Request $request ) {
		global $wpdb;
		$settings = wp_parse_args( get_option( 'neocrm_settings', array() ), array( 'cloud_enabled' => 0 ) );
		if ( strlen( (string) $request->get_body() ) > 20000 ) {
			return new WP_Error( 'neocrm_payload_too_large', __( 'The enquiry is too large.', 'neo-crm' ), array( 'status' => 413 ) );
		}

		if ( ! $this->is_same_site_request( $request ) || ! wp_verify_nonce( sanitize_text_field( (string) $request->get_param( 'nonce' ) ), 'neocrm_public' ) ) {
			return new WP_Error( 'neocrm_form_security', __( 'The form security check failed.', 'neo-crm' ), array( 'status' => 403 ) );
		}
		if ( ! $this->check_rate_limit( 'lead', 12 ) ) {
			return new WP_Error( 'neocrm_rate_limit', __( 'Too many requests.', 'neo-crm' ), array( 'status' => 429 ) );
		}
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_REST_Response( array( 'created' => true ), 201 );
		}

		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$first_name = $this->short_text( $request->get_param( 'first_name' ), 100 );
		$last_name  = $this->short_text( $request->get_param( 'last_name' ), 100 );
		if ( ! is_email( $email ) || '' === $first_name ) {
			return new WP_Error( 'neocrm_form_fields', __( 'A valid name and email address are required.', 'neo-crm' ), array( 'status' => 400 ) );
		}

		$now       = NeoCRM_DB::now();
		$company   = $this->short_text( $request->get_param( 'company' ), 190 );
		$marketing = rest_sanitize_boolean( $request->get_param( 'marketing_consent' ) ) ? 1 : 0;
		$data      = array(
			'first_name'          => $first_name,
			'last_name'           => $last_name,
			'email'               => $email,
			'phone'               => $this->short_text( $request->get_param( 'phone' ), 50 ),
			'company'             => $company,
			'status'              => 'new',
			'source'              => 'website_form',
			'marketing_opt_in_requested' => $marketing,
			'opt_in_requested_at' => $marketing ? $now : null,
			'updated_at'          => $now,
			'created_at'          => $now,
		);

		$lead_created = $wpdb->insert( NeoCRM_DB::table( 'leads' ), $data );
		$lead_id = (int) $wpdb->insert_id;
		if ( false === $lead_created || ! $lead_id ) {
			return new WP_Error( 'neocrm_lead_create_failed', __( 'The enquiry could not be saved. Please try again.', 'neo-crm' ), array( 'status' => 503 ) );
		}

		$visitor_id  = null;
		$visitor_uuid = $this->valid_uuid( $request->get_param( 'visitor_uuid' ) );
		if ( $visitor_uuid ) {
			$visitor_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'visitors' ) . ' WHERE visitor_uuid = %s', $visitor_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $visitor_id ) {
				$wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'lead_id' => $lead_id ), array( 'id' => $visitor_id ) );
				$wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'visitor_id' => $visitor_id, 'score' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT engagement_score FROM ' . NeoCRM_DB::table( 'visitors' ) . ' WHERE id = %d', $visitor_id ) ) ), array( 'id' => $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
			NeoCRM_Funnel::attach_lead( $lead_id, $visitor_id, 'lead', 'website' );

			$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 5000 ) : substr( $message, 0, 5000 );
		$wpdb->insert(
			NeoCRM_DB::table( 'activities' ),
			array(
				'lead_id'       => $lead_id,
				'contact_id'    => null,
				'visitor_id'    => $visitor_id ?: null,
				'activity_type' => 'form_submission',
				'subject'       => __( 'Website enquiry received', 'neo-crm' ),
				'body'          => $message,
				'metadata'      => wp_json_encode( array( 'page_url' => $this->clean_url( $request->get_param( 'page_url' ) ) ) ),
				'created_at'    => $now,
			)
		);

		do_action( 'neocrm_lead_captured', $lead_id, $visitor_id, $request );
		if ( ! empty( $settings['cloud_enabled'] ) && true === apply_filters( 'neocrm_cloud_lead_forwarding_enabled', false ) ) {
			$this->forward_lead_to_cloud( $request, $settings );
		}

		return new WP_REST_Response( array( 'created' => true, 'lead_id' => $lead_id, 'identity_status' => 'unverified' ), 201 );
	}

	/**
	 * Render the Release 0.1 lead form.
	 *
	 * @return string
	 */
	public function render_lead_form() {
		ob_start();
		?>
		<form class="neocrm-lead-form" data-neocrm-lead-form novalidate>
			<div class="neocrm-field-row">
				<label><?php esc_html_e( 'First name', 'neo-crm' ); ?><input name="first_name" type="text" autocomplete="given-name" required></label>
				<label><?php esc_html_e( 'Last name', 'neo-crm' ); ?><input name="last_name" type="text" autocomplete="family-name"></label>
			</div>
			<label><?php esc_html_e( 'Email', 'neo-crm' ); ?><input name="email" type="email" autocomplete="email" required></label>
			<label><?php esc_html_e( 'Phone', 'neo-crm' ); ?><input name="phone" type="tel" autocomplete="tel"></label>
			<label><?php esc_html_e( 'Company', 'neo-crm' ); ?><input name="company" type="text" autocomplete="organization"></label>
			<label><?php esc_html_e( 'How can we help?', 'neo-crm' ); ?><textarea name="message" rows="5"></textarea></label>
			<label class="neocrm-consent"><input name="marketing_consent" type="checkbox" value="1"> <?php esc_html_e( 'I request relevant follow-up communications. My address must be verified before marketing messages are sent.', 'neo-crm' ); ?></label>
			<label class="neocrm-honeypot" aria-hidden="true">Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label>
			<input name="visitor_uuid" type="hidden" value="">
			<button type="submit"><?php esc_html_e( 'Send enquiry', 'neo-crm' ); ?></button>
			<p class="neocrm-form-status" role="status" aria-live="polite"></p>
		</form>
		<?php
		return ob_get_clean();
	}

	private function is_same_site_request( WP_REST_Request $request ) {
		$source = $request->get_header( 'origin' );
		if ( ! $source ) {
			$source = $request->get_header( 'referer' );
		}
		if ( ! $source ) {
			return true;
		}
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
	}

	private function forward_event_to_cloud( WP_REST_Request $request, $settings, $event_type ) {
		$endpoint = esc_url_raw( $settings['cloud_endpoint'] ?? '' );
		$site_id  = sanitize_text_field( $settings['cloud_site_id'] ?? '' );
		$token    = NeoCRM_Secrets::decrypt( $settings['cloud_site_token'] ?? '' );
		if ( ! $this->valid_cloud_endpoint( $endpoint ) || ! $site_id || ! $token ) {
			return false;
		}
		$payload = array(
			'request_id'   => wp_generate_uuid4(),
			'event_type'   => $event_type,
			'visitor_uuid' => $this->valid_uuid( $request->get_param( 'visitor_uuid' ) ),
			'session_uuid' => $this->valid_uuid( $request->get_param( 'session_uuid' ) ),
			'page_url'     => $this->clean_url( $request->get_param( 'page_url' ) ),
			'page_title'   => $this->short_text( $request->get_param( 'page_title' ), 255 ),
			'referrer'     => $this->clean_url( $request->get_param( 'referrer' ) ),
			'locale'       => $this->short_text( $request->get_param( 'locale' ), 20 ),
			'device_type'  => wp_is_mobile() ? 'mobile' : 'desktop',
			'element_name' => $this->short_text( $request->get_param( 'element_name' ), 190 ),
			'event_data'   => $this->sanitize_event_data( $request->get_param( 'event_data' ) ),
			'utm_source'   => $this->short_text( $request->get_param( 'utm_source' ), 190 ),
			'utm_medium'   => $this->short_text( $request->get_param( 'utm_medium' ), 190 ),
			'utm_campaign' => $this->short_text( $request->get_param( 'utm_campaign' ), 190 ),
		);
		if ( ! $payload['visitor_uuid'] || ! $payload['session_uuid'] ) {
			return false;
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'       => 'application/json',
					'Origin'             => untrailingslashit( home_url() ),
					'X-NeoCRM-Site'      => $site_id,
					'X-NeoCRM-Token'     => $token,
				),
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	private function forward_lead_to_cloud( WP_REST_Request $request, $settings ) {
		$event_endpoint = esc_url_raw( $settings['cloud_endpoint'] ?? '' );
		if ( ! $this->valid_cloud_endpoint( $event_endpoint ) ) {
			return false;
		}
		$endpoint       = preg_replace( '#/events/?$#', '/leads', $event_endpoint );
		$site_id        = sanitize_text_field( $settings['cloud_site_id'] ?? '' );
		$token          = NeoCRM_Secrets::decrypt( $settings['cloud_site_token'] ?? '' );
		if ( ! $endpoint || $endpoint === $event_endpoint || ! $site_id || ! $token ) {
			return false;
		}
		$payload = array(
			'request_id'       => wp_generate_uuid4(),
			'visitor_uuid'     => $this->valid_uuid( $request->get_param( 'visitor_uuid' ) ),
			'first_name'      => $this->short_text( $request->get_param( 'first_name' ), 100 ),
			'last_name'       => $this->short_text( $request->get_param( 'last_name' ), 100 ),
			'email'           => sanitize_email( (string) $request->get_param( 'email' ) ),
			'phone'           => $this->short_text( $request->get_param( 'phone' ), 50 ),
			'company'         => $this->short_text( $request->get_param( 'company' ), 190 ),
			'message'         => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
			'marketing_consent' => rest_sanitize_boolean( $request->get_param( 'marketing_consent' ) ),
			'page_url'        => $this->clean_url( $request->get_param( 'page_url' ) ),
		);
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'   => 'application/json',
					'Origin'         => untrailingslashit( home_url() ),
					'X-NeoCRM-Site'  => $site_id,
					'X-NeoCRM-Token' => $token,
				),
				'body' => wp_json_encode( $payload ),
			)
		);
		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300;
	}

	private function valid_cloud_endpoint( $endpoint ) {
		$parts = wp_parse_url( $endpoint );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || '/api/v1/events' !== ( $parts['path'] ?? '' ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		$allowed_hosts = array_map( 'strtolower', (array) apply_filters( 'neocrm_allowed_cloud_hosts', array( 'neocrm-ingestion.vercel.app' ) ) );
		return in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), $allowed_hosts, true );
	}

	private function check_rate_limit( $bucket, $limit ) {
		$key   = 'neocrm_rate_' . sanitize_key( $bucket ) . '_' . substr( $this->ip_hash(), 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}

	private function valid_uuid( $value ) {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		return preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value ) ? $value : '';
	}

	private function clean_url( $value ) {
		$url   = esc_url_raw( (string) $value );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$clean  = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//';
		$clean .= $parts['host'];
		$clean .= isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$clean .= isset( $parts['path'] ) ? $parts['path'] : '/';
		return substr( esc_url_raw( $clean ), 0, 2048 );
	}

	private function short_text( $value, $length ) {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	private function sanitize_event_data( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array( 'href', 'depth', 'seconds', 'intent_category', 'language' );
		$clean = array();
		foreach ( array_slice( $value, 0, 20, true ) as $key => $item ) {
			$key = sanitize_key( (string) $key );
			if ( is_scalar( $item ) && in_array( $key, $allowed, true ) ) {
				$clean[ $key ] = 'href' === $key ? $this->clean_url( $item ) : $this->short_text( $item, 255 );
			}
		}
		return $clean;
	}

	private function score_for_event( $type, $url ) {
		$scores = array( 'page_view' => 2, 'click' => 3, 'scroll' => 1, 'heartbeat' => 0, 'form_started' => 15, 'download' => 8, 'voice_intent' => 15 );
		$score  = isset( $scores[ $type ] ) ? $scores[ $type ] : 0;
		if ( 'page_view' === $type && preg_match( '/pricing|quote|consult|contact/i', (string) $url ) ) {
			$score += 10;
		}
		return $score;
	}
}
