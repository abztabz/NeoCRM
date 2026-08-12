<?php
/**
 * WordPress admin experience.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_neocrm_update_contact', array( $this, 'update_contact' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'NeoCRM', 'neo-crm' ),
			__( 'NeoCRM', 'neo-crm' ),
			'view_neocrm',
			'neocrm',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			26
		);
		add_submenu_page( 'neocrm', __( 'Dashboard', 'neo-crm' ), __( 'Dashboard', 'neo-crm' ), 'view_neocrm', 'neocrm', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'neocrm', __( 'Visitors', 'neo-crm' ), __( 'Visitors', 'neo-crm' ), 'view_neocrm', 'neocrm-visitors', array( $this, 'render_visitors' ) );
		add_submenu_page( 'neocrm', __( 'Contacts', 'neo-crm' ), __( 'Contacts', 'neo-crm' ), 'view_neocrm', 'neocrm-contacts', array( $this, 'render_contacts' ) );
		add_submenu_page( 'neocrm', __( 'Settings', 'neo-crm' ), __( 'Settings', 'neo-crm' ), 'manage_neocrm', 'neocrm-settings', array( $this, 'render_settings' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'neocrm' ) ) {
			return;
		}
		wp_enqueue_style( 'neocrm-admin', NEOCRM_URL . 'assets/css/admin.css', array(), NEOCRM_VERSION );
	}

	public function register_settings() {
		register_setting(
			'neocrm_settings_group',
			'neocrm_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$current = wp_parse_args( get_option( 'neocrm_settings', array() ), array( 'cloud_site_token' => '' ) );
		$endpoint = esc_url_raw( $input['cloud_endpoint'] ?? '' );
		if ( $endpoint && 'https' !== wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) {
			$endpoint = '';
			add_settings_error( 'neocrm_settings', 'neocrm_https', __( 'The NeoCRM Cloud endpoint must use HTTPS.', 'neo-crm' ) );
		}
		$site_id = strtolower( sanitize_text_field( $input['cloud_site_id'] ?? '' ) );
		if ( $site_id && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $site_id ) ) {
			$site_id = '';
			add_settings_error( 'neocrm_settings', 'neocrm_site_id', __( 'The NeoCRM Cloud site ID is invalid.', 'neo-crm' ) );
		}
		$token = trim( (string) ( $input['cloud_site_token_plain'] ?? '' ) );
		$encrypted_token = $current['cloud_site_token'];
		if ( $token ) {
			$encrypted_token = NeoCRM_Secrets::encrypt( $token );
			if ( ! $encrypted_token ) {
				add_settings_error( 'neocrm_settings', 'neocrm_crypto', __( 'This server cannot securely encrypt the NeoCRM Cloud token.', 'neo-crm' ) );
			}
		}
		return array(
			'tracking_enabled'         => empty( $input['tracking_enabled'] ) ? 0 : 1,
			'consent_mode'            => in_array( $input['consent_mode'] ?? '', array( 'required', 'external', 'disabled' ), true ) ? $input['consent_mode'] : 'required',
			'event_retention_days'     => max( 7, min( 730, absint( $input['event_retention_days'] ?? 90 ) ) ),
			'cloud_enabled'            => empty( $input['cloud_enabled'] ) ? 0 : 1,
			'cloud_endpoint'           => untrailingslashit( $endpoint ),
			'cloud_site_id'            => $site_id,
			'cloud_site_token'         => $encrypted_token,
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
		);
	}

	public function render_dashboard() {
		$this->guard();
		global $wpdb;

		$visitors_table = NeoCRM_DB::table( 'visitors' );
		$contacts_table = NeoCRM_DB::table( 'contacts' );
		$events_table   = NeoCRM_DB::table( 'events' );
		$since          = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$live_since     = gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) );
		$stats          = array(
			'live'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$visitors_table} WHERE last_seen >= %s", $live_since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'visitors'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$visitors_table} WHERE last_seen >= %s", $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pageviews'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE event_type = 'page_view' AND occurred_at >= %s", $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'contacts'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$contacts_table}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'high_intent' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$visitors_table} WHERE engagement_score >= 50 AND contact_id IS NULL" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$recent = $wpdb->get_results( "SELECT v.*, c.first_name, c.last_name, c.email FROM {$visitors_table} v LEFT JOIN {$contacts_table} c ON c.id = v.contact_id ORDER BY v.last_seen DESC LIMIT 8" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap neocrm-wrap">
			<?php $this->header( __( 'Relationship overview', 'neo-crm' ), __( 'From first visit to identified contact.', 'neo-crm' ) ); ?>
			<div class="neocrm-stats">
				<?php $this->stat( __( 'Live now', 'neo-crm' ), $stats['live'], __( 'Last 5 minutes', 'neo-crm' ) ); ?>
				<?php $this->stat( __( 'Visitors', 'neo-crm' ), $stats['visitors'], __( 'Last 24 hours', 'neo-crm' ) ); ?>
				<?php $this->stat( __( 'Page views', 'neo-crm' ), $stats['pageviews'], __( 'Last 24 hours', 'neo-crm' ) ); ?>
				<?php $this->stat( __( 'Contacts', 'neo-crm' ), $stats['contacts'], __( 'All identified people', 'neo-crm' ) ); ?>
				<?php $this->stat( __( 'High intent', 'neo-crm' ), $stats['high_intent'], __( 'Anonymous score 50+', 'neo-crm' ) ); ?>
			</div>
			<section class="neocrm-panel">
				<div class="neocrm-panel-head"><div><h2><?php esc_html_e( 'Recent visitor activity', 'neo-crm' ); ?></h2><p><?php esc_html_e( 'Anonymous journeys become named contacts after a lead form is submitted.', 'neo-crm' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-visitors' ) ); ?>"><?php esc_html_e( 'View all visitors', 'neo-crm' ); ?></a></div>
				<?php $this->visitor_table( $recent ); ?>
			</section>
			<section class="neocrm-panel neocrm-onboarding">
				<h2><?php esc_html_e( 'Activate your first complete journey', 'neo-crm' ); ?></h2>
				<p><?php esc_html_e( 'Add this shortcode to a WordPress page. A submitted enquiry will create a contact and attach the visitor’s consented activity history.', 'neo-crm' ); ?></p>
				<code>[neocrm_lead_form]</code>
			</section>
		</div>
		<?php
	}

	public function render_visitors() {
		$this->guard();
		$visitor_id = absint( $_GET['visitor_id'] ?? 0 );
		if ( $visitor_id ) {
			$this->render_visitor_detail( $visitor_id );
			return;
		}
		global $wpdb;
		$visitors = NeoCRM_DB::table( 'visitors' );
		$contacts = NeoCRM_DB::table( 'contacts' );
		$rows     = $wpdb->get_results( "SELECT v.*, c.first_name, c.last_name, c.email FROM {$visitors} v LEFT JOIN {$contacts} c ON c.id = v.contact_id ORDER BY v.last_seen DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap neocrm-wrap">
			<?php $this->header( __( 'Visitors', 'neo-crm' ), __( 'First-party journeys captured with permission.', 'neo-crm' ) ); ?>
			<section class="neocrm-panel">
				<?php $this->visitor_table( $rows ); ?>
			</section>
		</div>
		<?php
	}

	private function render_visitor_detail( $visitor_id ) {
		global $wpdb;
		$visitors = NeoCRM_DB::table( 'visitors' );
		$contacts = NeoCRM_DB::table( 'contacts' );
		$events   = NeoCRM_DB::table( 'events' );
		$visitor  = $wpdb->get_row( $wpdb->prepare( "SELECT v.*, c.first_name, c.last_name, c.email FROM {$visitors} v LEFT JOIN {$contacts} c ON c.id = v.contact_id WHERE v.id = %d", $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $visitor ) {
			wp_die( esc_html__( 'Visitor not found.', 'neo-crm' ) );
		}
		$journey = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$events} WHERE visitor_id = %d ORDER BY occurred_at DESC LIMIT 200", $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$name    = $visitor->contact_id ? trim( $visitor->first_name . ' ' . $visitor->last_name ) : 'Visitor ' . strtoupper( substr( $visitor->visitor_uuid, 0, 6 ) );
		?>
		<div class="wrap neocrm-wrap">
			<a class="neocrm-back" href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-visitors' ) ); ?>">&larr; <?php esc_html_e( 'All visitors', 'neo-crm' ); ?></a>
			<?php $this->header( $name, $visitor->contact_id ? __( 'Identified visitor journey', 'neo-crm' ) : __( 'Anonymous visitor journey', 'neo-crm' ) ); ?>
			<div class="neocrm-profile-grid">
				<section class="neocrm-panel neocrm-profile-card">
					<h2><?php esc_html_e( 'Visitor profile', 'neo-crm' ); ?></h2>
					<dl><dt><?php esc_html_e( 'Status', 'neo-crm' ); ?></dt><dd><?php echo $visitor->contact_id ? esc_html__( 'Identified', 'neo-crm' ) : esc_html__( 'Anonymous', 'neo-crm' ); ?></dd><dt><?php esc_html_e( 'Email', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $visitor->email ?: '—' ); ?></dd><dt><?php esc_html_e( 'First source', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $this->source_label( $visitor->first_referrer ) ); ?></dd><dt><?php esc_html_e( 'Locale', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $visitor->locale ?: '—' ); ?></dd><dt><?php esc_html_e( 'Device', 'neo-crm' ); ?></dt><dd><?php echo esc_html( ucfirst( $visitor->device_type ?: 'unknown' ) ); ?></dd><dt><?php esc_html_e( 'First seen', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $this->relative_time( $visitor->first_seen ) ); ?></dd></dl>
				</section>
				<section class="neocrm-panel neocrm-profile-card">
					<h2><?php esc_html_e( 'Engagement', 'neo-crm' ); ?></h2>
					<div class="neocrm-profile-score"><?php echo absint( $visitor->engagement_score ); ?><small>/ 100</small></div>
					<dl><dt><?php esc_html_e( 'Sessions', 'neo-crm' ); ?></dt><dd><?php echo absint( $visitor->sessions_count ); ?></dd><dt><?php esc_html_e( 'Page views', 'neo-crm' ); ?></dt><dd><?php echo absint( $visitor->pageviews_count ); ?></dd><dt><?php esc_html_e( 'Last active', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $this->relative_time( $visitor->last_seen ) ); ?></dd></dl>
				</section>
			</div>
			<section class="neocrm-panel">
				<h2><?php esc_html_e( 'Journey timeline', 'neo-crm' ); ?></h2>
				<div class="neocrm-timeline">
				<?php if ( ! $journey ) : ?><p class="neocrm-empty"><?php esc_html_e( 'No retained events for this visitor.', 'neo-crm' ); ?></p><?php endif; ?>
				<?php foreach ( $journey as $event ) : ?>
					<article class="neocrm-event"><span class="dashicons <?php echo esc_attr( $this->event_icon( $event->event_type ) ); ?>"></span><div><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $event->event_type ) ) ); ?></strong><p><?php echo esc_html( $event->page_title ?: $event->element_name ?: __( 'Website activity', 'neo-crm' ) ); ?></p><?php if ( $event->page_url ) : ?><a href="<?php echo esc_url( $event->page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_html_excerpt( $event->page_url, 90, '…' ) ); ?></a><?php endif; ?></div><time><?php echo esc_html( $this->relative_time( $event->occurred_at ) ); ?></time></article>
				<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
	}

	public function render_contacts() {
		$this->guard();
		global $wpdb;
		$table    = NeoCRM_DB::table( 'contacts' );
		$contacts = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		?>
		<div class="wrap neocrm-wrap">
			<?php $this->header( __( 'Contacts', 'neo-crm' ), __( 'People who identified themselves through the website.', 'neo-crm' ) ); ?>
			<section class="neocrm-panel neocrm-table-wrap">
				<table class="widefat striped neocrm-table">
					<thead><tr><th><?php esc_html_e( 'Contact', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Company', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Status', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Source', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Consent', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Updated', 'neo-crm' ); ?></th></tr></thead>
					<tbody>
					<?php if ( ! $contacts ) : ?><tr><td colspan="6" class="neocrm-empty"><?php esc_html_e( 'No contacts yet. Add the NeoCRM lead form to begin.', 'neo-crm' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $contacts as $contact ) : ?>
						<tr>
							<td><strong><?php echo esc_html( trim( $contact->first_name . ' ' . $contact->last_name ) ); ?></strong><br><a href="mailto:<?php echo esc_attr( $contact->email ); ?>"><?php echo esc_html( $contact->email ); ?></a><?php if ( $contact->phone ) : ?><br><span class="neocrm-muted"><?php echo esc_html( $contact->phone ); ?></span><?php endif; ?></td>
							<td><?php echo esc_html( $contact->company ?: '—' ); ?></td>
							<td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_update_contact"><input type="hidden" name="contact_id" value="<?php echo absint( $contact->id ); ?>"><?php wp_nonce_field( 'neocrm_update_contact_' . $contact->id ); ?><select name="status" onchange="this.form.submit()"><?php foreach ( array( 'new' => __( 'New', 'neo-crm' ), 'contacted' => __( 'Contacted', 'neo-crm' ), 'qualified' => __( 'Qualified', 'neo-crm' ), 'customer' => __( 'Customer', 'neo-crm' ), 'lost' => __( 'Lost', 'neo-crm' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $contact->status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></form></td>
							<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $contact->source ) ) ); ?></td>
							<td><?php echo $contact->marketing_consent ? '<span class="neocrm-pill is-positive">' . esc_html__( 'Granted', 'neo-crm' ) . '</span>' : '<span class="neocrm-pill">' . esc_html__( 'Not granted', 'neo-crm' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( $this->relative_time( $contact->updated_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_neocrm' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage NeoCRM.', 'neo-crm' ) );
		}
		$settings = wp_parse_args( get_option( 'neocrm_settings', array() ), array( 'tracking_enabled' => 1, 'consent_mode' => 'required', 'event_retention_days' => 90, 'cloud_enabled' => 0, 'cloud_endpoint' => '', 'cloud_site_id' => '', 'cloud_site_token' => '', 'delete_data_on_uninstall' => 0 ) );
		?>
		<div class="wrap neocrm-wrap">
			<?php $this->header( __( 'Settings', 'neo-crm' ), __( 'Control tracking, consent, and data retention.', 'neo-crm' ) ); ?>
			<form method="post" action="options.php" class="neocrm-panel neocrm-settings-form">
				<?php settings_fields( 'neocrm_settings_group' ); ?>
				<h2><?php esc_html_e( 'Visitor tracking', 'neo-crm' ); ?></h2>
				<label class="neocrm-toggle"><input type="checkbox" name="neocrm_settings[tracking_enabled]" value="1" <?php checked( $settings['tracking_enabled'] ); ?>><span><strong><?php esc_html_e( 'Enable first-party tracking', 'neo-crm' ); ?></strong><small><?php esc_html_e( 'Captures approved website interactions without third-party advertising trackers.', 'neo-crm' ); ?></small></span></label>
				<label><strong><?php esc_html_e( 'Consent mode', 'neo-crm' ); ?></strong><select name="neocrm_settings[consent_mode]"><option value="required" <?php selected( $settings['consent_mode'], 'required' ); ?>><?php esc_html_e( 'Show NeoCRM consent banner', 'neo-crm' ); ?></option><option value="external" <?php selected( $settings['consent_mode'], 'external' ); ?>><?php esc_html_e( 'Use an external consent manager', 'neo-crm' ); ?></option><option value="disabled" <?php selected( $settings['consent_mode'], 'disabled' ); ?>><?php esc_html_e( 'Analytics allowed without NeoCRM banner', 'neo-crm' ); ?></option></select><small><?php esc_html_e( 'Choose the mode that matches your legal basis and site privacy setup.', 'neo-crm' ); ?></small></label>
				<label><strong><?php esc_html_e( 'Raw event retention', 'neo-crm' ); ?></strong><input type="number" min="7" max="730" name="neocrm_settings[event_retention_days]" value="<?php echo absint( $settings['event_retention_days'] ); ?>"> <span><?php esc_html_e( 'days', 'neo-crm' ); ?></span></label>
				<hr>
				<h2><?php esc_html_e( 'NeoCRM Cloud', 'neo-crm' ); ?></h2>
				<label class="neocrm-toggle"><input type="checkbox" name="neocrm_settings[cloud_enabled]" value="1" <?php checked( $settings['cloud_enabled'] ); ?>><span><strong><?php esc_html_e( 'Send visitor events to NeoCRM Cloud', 'neo-crm' ); ?></strong><small><?php esc_html_e( 'The WordPress database remains a fallback if the cloud cannot be reached.', 'neo-crm' ); ?></small></span></label>
				<label><strong><?php esc_html_e( 'Cloud endpoint', 'neo-crm' ); ?></strong><input class="regular-text" type="url" name="neocrm_settings[cloud_endpoint]" value="<?php echo esc_attr( $settings['cloud_endpoint'] ); ?>" placeholder="https://your-project.vercel.app/api/v1/events"></label>
				<label><strong><?php esc_html_e( 'Site ID', 'neo-crm' ); ?></strong><input class="regular-text" type="text" name="neocrm_settings[cloud_site_id]" value="<?php echo esc_attr( $settings['cloud_site_id'] ); ?>" autocomplete="off"></label>
				<label><strong><?php esc_html_e( 'Site token', 'neo-crm' ); ?></strong><input class="regular-text" type="password" name="neocrm_settings[cloud_site_token_plain]" value="" autocomplete="new-password" placeholder="<?php echo $settings['cloud_site_token'] ? esc_attr__( 'Saved — leave blank to keep it', 'neo-crm' ) : esc_attr__( 'Enter the site token', 'neo-crm' ); ?>"><small><?php esc_html_e( 'Stored encrypted using this WordPress installation’s security salts.', 'neo-crm' ); ?></small></label>
				<hr>
				<label class="neocrm-toggle"><input type="checkbox" name="neocrm_settings[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?>><span><strong><?php esc_html_e( 'Delete all NeoCRM data on uninstall', 'neo-crm' ); ?></strong><small><?php esc_html_e( 'This is irreversible. Deactivation alone never deletes CRM data.', 'neo-crm' ); ?></small></span></label>
				<?php submit_button( __( 'Save settings', 'neo-crm' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function update_contact() {
		if ( ! current_user_can( 'manage_neocrm' ) ) {
			wp_die( esc_html__( 'You do not have permission to update contacts.', 'neo-crm' ) );
		}
		$id = absint( $_POST['contact_id'] ?? 0 );
		check_admin_referer( 'neocrm_update_contact_' . $id );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		if ( in_array( $status, array( 'new', 'contacted', 'qualified', 'customer', 'lost' ), true ) ) {
			global $wpdb;
			$wpdb->update( NeoCRM_DB::table( 'contacts' ), array( 'status' => $status, 'updated_at' => NeoCRM_DB::now() ), array( 'id' => $id ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=neocrm-contacts' ) );
		exit;
	}

	private function visitor_table( $rows ) {
		?>
		<div class="neocrm-table-wrap"><table class="widefat striped neocrm-table"><thead><tr><th><?php esc_html_e( 'Visitor', 'neo-crm' ); ?></th><th><?php esc_html_e( 'First touch', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Sessions', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Views', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Score', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Last active', 'neo-crm' ); ?></th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6" class="neocrm-empty"><?php esc_html_e( 'No consented visitor activity has been recorded yet.', 'neo-crm' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $rows as $row ) : $identified = ! empty( $row->contact_id ); ?>
			<tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-visitors&visitor_id=' . absint( $row->id ) ) ); ?>"><strong><?php echo $identified ? esc_html( trim( $row->first_name . ' ' . $row->last_name ) ) : esc_html( 'Visitor ' . strtoupper( substr( $row->visitor_uuid, 0, 6 ) ) ); ?></strong></a><br><span class="neocrm-pill <?php echo $identified ? 'is-positive' : ''; ?>"><?php echo $identified ? esc_html__( 'Identified', 'neo-crm' ) : esc_html__( 'Anonymous', 'neo-crm' ); ?></span></td><td><?php echo esc_html( $this->source_label( $row->first_referrer ) ); ?><br><span class="neocrm-muted"><?php echo esc_html( $row->locale ?: '—' ); ?> · <?php echo esc_html( ucfirst( $row->device_type ?: 'unknown' ) ); ?></span></td><td><?php echo absint( $row->sessions_count ); ?></td><td><?php echo absint( $row->pageviews_count ); ?></td><td><span class="neocrm-score <?php echo $row->engagement_score >= 50 ? 'is-hot' : ''; ?>"><?php echo absint( $row->engagement_score ); ?></span></td><td><?php echo esc_html( $this->relative_time( $row->last_seen ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
		<?php
	}

	private function header( $title, $subtitle ) {
		?><header class="neocrm-header"><div><span class="neocrm-brand">NEOCRM</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div><span class="neocrm-version">v<?php echo esc_html( NEOCRM_VERSION ); ?></span></header><?php
	}

	private function stat( $label, $value, $context ) {
		?><div class="neocrm-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong><small><?php echo esc_html( $context ); ?></small></div><?php
	}

	private function source_label( $referrer ) {
		if ( ! $referrer ) {
			return __( 'Direct', 'neo-crm' );
		}
		$host = wp_parse_url( $referrer, PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', $host ) : __( 'Referral', 'neo-crm' );
	}

	private function event_icon( $event_type ) {
		$icons = array( 'page_view' => 'dashicons-visibility', 'click' => 'dashicons-admin-links', 'scroll' => 'dashicons-arrow-down-alt', 'form_started' => 'dashicons-feedback', 'download' => 'dashicons-download', 'voice_intent' => 'dashicons-microphone' );
		return $icons[ $event_type ] ?? 'dashicons-marker';
	}

	private function relative_time( $date ) {
		$timestamp = strtotime( $date . ' UTC' );
		return $timestamp ? sprintf( __( '%s ago', 'neo-crm' ), human_time_diff( $timestamp, time() ) ) : '—';
	}

	private function guard() {
		if ( ! current_user_can( 'view_neocrm' ) ) {
			wp_die( esc_html__( 'You do not have permission to view NeoCRM.', 'neo-crm' ) );
		}
	}
}
