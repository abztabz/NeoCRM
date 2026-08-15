<?php
/**
 * Unified visitor-to-recurring-client lifecycle and Lead intake.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Funnel {
	const MAX_IMPORT_BYTES = 5242880;
	const MAX_IMPORT_ROWS  = 1000;

	public function __construct() {
		add_action( 'admin_post_neocrm_save_funnel_lead', array( $this, 'save_funnel_lead' ) );
		add_action( 'admin_post_neocrm_import_leads', array( $this, 'import_leads' ) );
		add_action( 'admin_post_neocrm_download_lead_template', array( $this, 'download_lead_template' ) );
		add_action( 'admin_post_neocrm_move_journey', array( $this, 'move_journey' ) );
		add_action( 'admin_post_neocrm_save_journey_details', array( $this, 'save_journey_details' ) );
	}

	/** Ordered lifecycle. Visitors enter automatically; identified records can begin at any later stage. */
	public static function stages() {
		return array(
			'visitor'          => array( 'label' => __( 'Visitors', 'neo-crm' ), 'color' => '#64748b' ),
			'lead'             => array( 'label' => __( 'Leads', 'neo-crm' ), 'color' => '#2563eb' ),
			'contacted'        => array( 'label' => __( 'Contacted', 'neo-crm' ), 'color' => '#0891b2' ),
			'qualified'        => array( 'label' => __( 'Qualified', 'neo-crm' ), 'color' => '#4f46e5' ),
			'proposal'         => array( 'label' => __( 'Proposal', 'neo-crm' ), 'color' => '#7c3aed' ),
			'negotiation'      => array( 'label' => __( 'Negotiation', 'neo-crm' ), 'color' => '#d97706' ),
			'paying_client'    => array( 'label' => __( 'Paying clients', 'neo-crm' ), 'color' => '#059669' ),
			'recurring_client' => array( 'label' => __( 'Recurring clients', 'neo-crm' ), 'color' => '#047857' ),
			'lost'             => array( 'label' => __( 'Lost', 'neo-crm' ), 'color' => '#b42318' ),
		);
	}

	public static function render() {
		self::guard_view();
		global $wpdb;
		$journeys = NeoCRM_DB::table( 'customer_journeys' );
		$visitors = NeoCRM_DB::table( 'visitors' );
		$leads    = NeoCRM_DB::table( 'leads' );
		$contacts = NeoCRM_DB::table( 'contacts' );
		$companies= NeoCRM_DB::table( 'companies' );
		$updates  = NeoCRM_DB::table( 'journey_updates' );
		$rows = $wpdb->get_results(
			"SELECT j.*, v.visitor_uuid, v.engagement_score,
				COALESCE(c.first_name,l.first_name,'') first_name,
				COALESCE(c.last_name,l.last_name,'') last_name,
				COALESCE(c.email,l.email,'') email,
				COALESCE(co.name,c.company,l.company,'') company_name
			FROM {$journeys} j
			LEFT JOIN {$visitors} v ON v.id=j.visitor_id
			LEFT JOIN {$leads} l ON l.id=j.lead_id
			LEFT JOIN {$contacts} c ON c.id=j.contact_id
			LEFT JOIN {$companies} co ON co.id=j.company_id
			ORDER BY j.updated_at DESC LIMIT 1000" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$grouped = array_fill_keys( array_keys( self::stages() ), array() );
		$updates_by_journey = array();
		foreach ( $wpdb->get_results( "SELECT u.*, usr.display_name FROM {$updates} u LEFT JOIN {$wpdb->users} usr ON usr.ID=u.author_user_id ORDER BY u.created_at DESC LIMIT 5000" ) as $update ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updates_by_journey[ (int) $update->journey_id ][] = $update;
		}
		foreach ( $rows as $row ) {
			if ( isset( $grouped[ $row->stage ] ) ) {
				$grouped[ $row->stage ][] = $row;
			}
		}
		?>
		<div class="wrap neocrm-wrap">
			<?php self::header( __( 'Sales funnel', 'neo-crm' ), __( 'One client journey from first visit to recurring revenue. Records can enter at any stage.', 'neo-crm' ) ); ?>
			<?php self::render_notice(); ?>
			<div class="neocrm-funnel-summary">
				<?php foreach ( self::stages() as $key => $stage ) : ?>
					<div style="--stage-color:<?php echo esc_attr( $stage['color'] ); ?>"><strong><?php echo absint( count( $grouped[ $key ] ) ); ?></strong><span><?php echo esc_html( $stage['label'] ); ?></span></div>
				<?php endforeach; ?>
			</div>
			<div class="neocrm-lifecycle-pipeline">
			<?php foreach ( self::stages() as $key => $stage ) : ?>
				<section class="neocrm-stage" style="--stage-color:<?php echo esc_attr( $stage['color'] ); ?>">
					<header><div><h2><?php echo esc_html( $stage['label'] ); ?></h2><span><?php echo absint( count( $grouped[ $key ] ) ); ?></span></div><?php if ( in_array( $key, array( 'proposal', 'negotiation', 'paying_client', 'recurring_client' ), true ) ) : ?><strong><?php echo esc_html( number_format_i18n( array_sum( array_map( static fn( $item ) => 'recurring_client' === $key ? (float) $item->recurring_value : (float) $item->estimated_value, $grouped[ $key ] ) ), 2 ) ); ?> AED<?php echo 'recurring_client' === $key ? esc_html__( ' recurring', 'neo-crm' ) : ''; ?></strong><?php endif; ?></header>
					<?php if ( ! $grouped[ $key ] ) : ?><p class="neocrm-stage-empty"><?php esc_html_e( 'No journeys', 'neo-crm' ); ?></p><?php endif; ?>
					<?php foreach ( $grouped[ $key ] as $journey ) :
						$name = trim( $journey->first_name . ' ' . $journey->last_name );
						if ( ! $name ) { $name = $journey->visitor_uuid ? 'Visitor ' . strtoupper( substr( $journey->visitor_uuid, 0, 6 ) ) : __( 'Unnamed relationship', 'neo-crm' ); }
						$link = $journey->contact_id ? admin_url( 'admin.php?page=neocrm-contacts&contact_id=' . absint( $journey->contact_id ) ) : ( $journey->lead_id ? admin_url( 'admin.php?page=neocrm-leads&lead_id=' . absint( $journey->lead_id ) ) : ( $journey->visitor_id ? admin_url( 'admin.php?page=neocrm-visitors&visitor_id=' . absint( $journey->visitor_id ) ) : '' ) );
						?>
						<article class="neocrm-deal-card neocrm-journey-card">
							<h3><?php if ( $link ) : ?><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $name ); ?></a><?php else : echo esc_html( $name ); endif; ?></h3>
							<?php if ( $journey->company_name ) : ?><p><?php echo esc_html( $journey->company_name ); ?></p><?php endif; ?>
							<?php if ( $journey->email ) : ?><small><?php echo esc_html( $journey->email ); ?></small><?php elseif ( $journey->visitor_id ) : ?><small><?php echo esc_html( sprintf( __( 'Intent score %d', 'neo-crm' ), absint( $journey->engagement_score ) ) ); ?></small><?php endif; ?>
							<?php if ( self::stage_rank( $key ) >= self::stage_rank( 'contacted' ) && 'lost' !== $key ) : ?><div class="neocrm-journey-facts"><span><?php echo esc_html( number_format_i18n( (float) $journey->estimated_value, 2 ) ); ?> AED</span><?php if ( $journey->next_follow_up_at ) : ?><span><?php echo esc_html( sprintf( __( 'Follow up: %s', 'neo-crm' ), get_date_from_gmt( $journey->next_follow_up_at, 'M j, Y g:i a' ) ) ); ?></span><?php endif; ?><?php if ( $journey->communication_medium ) : ?><span><?php echo esc_html( ucfirst( $journey->communication_medium ) ); ?></span><?php endif; ?></div><?php endif; ?>
							<?php if ( current_user_can( 'manage_neocrm' ) && ( $journey->lead_id || $journey->contact_id ) ) : ?>
								<?php if ( self::stage_rank( $key ) >= self::stage_rank( 'contacted' ) || 'lost' === $key ) : ?><details class="neocrm-journey-editor"><summary><?php esc_html_e( 'Update journey', 'neo-crm' ); ?></summary><form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_journey_details"><input type="hidden" name="journey_id" value="<?php echo absint( $journey->id ); ?>"><?php wp_nonce_field( 'neocrm_save_journey_details_' . $journey->id ); ?><label><?php esc_html_e( 'Stage', 'neo-crm' ); ?><select name="stage"><?php foreach ( self::stages() as $target_key => $target ) : if ( 'visitor' === $target_key ) { continue; } ?><option value="<?php echo esc_attr( $target_key ); ?>" <?php selected( $key, $target_key ); ?>><?php echo esc_html( $target['label'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Monetary value (AED)', 'neo-crm' ); ?><input type="number" name="estimated_value" min="0" step="0.01" value="<?php echo esc_attr( $journey->estimated_value ); ?>"></label><label><?php esc_html_e( 'Requirements', 'neo-crm' ); ?><textarea name="requirements" rows="4" maxlength="10000"><?php echo esc_textarea( $journey->requirements ); ?></textarea></label><label><?php esc_html_e( 'Next follow-up', 'neo-crm' ); ?><input type="datetime-local" name="next_follow_up_at" value="<?php echo esc_attr( $journey->next_follow_up_at ? get_date_from_gmt( $journey->next_follow_up_at, 'Y-m-d\\TH:i' ) : '' ); ?>"></label><label><?php esc_html_e( 'Communication medium', 'neo-crm' ); ?><select name="communication_medium"><option value=""><?php esc_html_e( 'Select', 'neo-crm' ); ?></option><?php foreach ( array( 'email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp' ) as $medium_key => $medium_label ) : ?><option value="<?php echo esc_attr( $medium_key ); ?>" <?php selected( $journey->communication_medium, $medium_key ); ?>><?php echo esc_html( $medium_label ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Team note for this stage', 'neo-crm' ); ?><textarea name="team_note" rows="4" maxlength="10000" placeholder="<?php esc_attr_e( 'Add context, decisions, objections, or next steps…', 'neo-crm' ); ?>"></textarea></label><button class="button button-primary"><?php esc_html_e( 'Save journey update', 'neo-crm' ); ?></button></form><?php $journey_updates = $updates_by_journey[ (int) $journey->id ] ?? array(); if ( $journey_updates ) : ?><div class="neocrm-stage-notes"><strong><?php esc_html_e( 'Team history', 'neo-crm' ); ?></strong><?php foreach ( array_slice( $journey_updates, 0, 5 ) as $update ) : ?><article><span><?php echo esc_html( self::stages()[ $update->stage ]['label'] ?? ucfirst( $update->stage ) ); ?> · <?php echo esc_html( $update->display_name ?: __( 'Team member', 'neo-crm' ) ); ?></span><p><?php echo nl2br( esc_html( $update->body ) ); ?></p><time><?php echo esc_html( get_date_from_gmt( $update->created_at, 'M j, Y g:i a' ) ); ?></time></article><?php endforeach; ?></div><?php endif; ?></details>
								<?php else : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_move_journey"><input type="hidden" name="journey_id" value="<?php echo absint( $journey->id ); ?>"><?php wp_nonce_field( 'neocrm_move_journey_' . $journey->id ); ?><select name="stage" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Move client journey', 'neo-crm' ); ?>"><?php foreach ( self::stages() as $target_key => $target ) : if ( 'visitor' === $target_key ) { continue; } ?><option value="<?php echo esc_attr( $target_key ); ?>" <?php selected( $key, $target_key ); ?>><?php echo esc_html( $target['label'] ); ?></option><?php endforeach; ?></select></form><?php endif; ?>
							<?php elseif ( $journey->visitor_id ) : ?><span class="neocrm-pill"><?php esc_html_e( 'Waiting for identity', 'neo-crm' ); ?></span><?php endif; ?>
						</article>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** Lead page intake: one record or a spreadsheet. */
	public static function render_lead_intake() {
		if ( ! current_user_can( 'manage_neocrm' ) ) { return; }
		$expand_on_error = isset( $_GET['error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<?php self::render_notice(); ?>
		<div class="neocrm-intake-grid">
			<details class="neocrm-panel neocrm-collapsible" <?php echo $expand_on_error ? 'open' : ''; ?>>
				<summary><span><strong><?php esc_html_e( 'Add a lead manually', 'neo-crm' ); ?></strong><small><?php esc_html_e( 'Enter one prospect at any journey stage', 'neo-crm' ); ?></small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
				<div class="neocrm-collapsible-body"><p><?php esc_html_e( 'Choose where this relationship already is. Later stages automatically create the required Contact and Deal records.', 'neo-crm' ); ?></p>
				<form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_funnel_lead"><?php wp_nonce_field( 'neocrm_save_funnel_lead' ); ?>
					<div class="neocrm-field-pair"><label><?php esc_html_e( 'First name', 'neo-crm' ); ?><input name="first_name" required maxlength="100"></label><label><?php esc_html_e( 'Last name', 'neo-crm' ); ?><input name="last_name" maxlength="100"></label></div>
					<div class="neocrm-field-pair"><label><?php esc_html_e( 'Email', 'neo-crm' ); ?><input type="email" name="email" required maxlength="190"></label><label><?php esc_html_e( 'Phone', 'neo-crm' ); ?><input name="phone" maxlength="50"></label></div>
					<div class="neocrm-field-pair"><label><?php esc_html_e( 'Company', 'neo-crm' ); ?><input name="company" maxlength="190"></label><label><?php esc_html_e( 'Source', 'neo-crm' ); ?><input name="source" value="manual" maxlength="100"></label></div>
					<div class="neocrm-field-pair"><label><?php esc_html_e( 'Current journey stage', 'neo-crm' ); ?><select name="stage"><?php foreach ( self::stages() as $key => $stage ) : if ( 'visitor' === $key ) { continue; } ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $stage['label'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Estimated value (AED)', 'neo-crm' ); ?><input type="number" name="estimated_value" min="0" step="0.01" value="0"></label></div>
					<div class="neocrm-field-pair"><label><?php esc_html_e( 'Recurring value (AED)', 'neo-crm' ); ?><input type="number" name="recurring_value" min="0" step="0.01" value="0"></label><label><?php esc_html_e( 'Billing interval', 'neo-crm' ); ?><select name="billing_interval"><option value=""><?php esc_html_e( 'Not recurring yet', 'neo-crm' ); ?></option><option value="monthly"><?php esc_html_e( 'Monthly', 'neo-crm' ); ?></option><option value="quarterly"><?php esc_html_e( 'Quarterly', 'neo-crm' ); ?></option><option value="annual"><?php esc_html_e( 'Annual', 'neo-crm' ); ?></option></select></label></div>
					<button class="button button-primary"><?php esc_html_e( 'Add to sales journey', 'neo-crm' ); ?></button>
				</form>
				</div>
			</details>
			<details class="neocrm-panel neocrm-collapsible" <?php echo $expand_on_error ? 'open' : ''; ?>>
				<summary><span><strong><?php esc_html_e( 'Import leads', 'neo-crm' ); ?></strong><small><?php esc_html_e( 'Upload Excel or CSV in bulk', 'neo-crm' ); ?></small></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
				<div class="neocrm-collapsible-body"><p><?php esc_html_e( 'Upload an Excel .xlsx file or CSV with up to 1,000 rows. Use the template for reliable column matching.', 'neo-crm' ); ?></p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=neocrm_download_lead_template' ), 'neocrm_download_lead_template' ) ); ?>"><?php esc_html_e( 'Download Excel-ready template', 'neo-crm' ); ?></a>
				<form class="neocrm-stack" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_import_leads"><?php wp_nonce_field( 'neocrm_import_leads' ); ?><label><?php esc_html_e( 'Excel or CSV file', 'neo-crm' ); ?><input type="file" name="lead_file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label><label><?php esc_html_e( 'When an email already exists', 'neo-crm' ); ?><select name="duplicate_policy"><option value="skip"><?php esc_html_e( 'Skip the row', 'neo-crm' ); ?></option><option value="update"><?php esc_html_e( 'Update the existing record', 'neo-crm' ); ?></option></select></label><button class="button button-primary"><?php esc_html_e( 'Import leads', 'neo-crm' ); ?></button></form>
				<p class="description"><?php esc_html_e( 'Columns: first_name, last_name, email, phone, company, stage, source, estimated_value, recurring_value, billing_interval.', 'neo-crm' ); ?></p>
				</div>
			</details>
		</div>
		<?php
	}

	public function save_funnel_lead() {
		self::guard_manage();
		check_admin_referer( 'neocrm_save_funnel_lead' );
		$result = self::create_or_update_from_row( wp_unslash( $_POST ), 'update', 'manual' );
		$this->redirect_with_result( $result );
	}

	public function import_leads() {
		self::guard_manage();
		check_admin_referer( 'neocrm_import_leads' );
		$file = $_FILES['lead_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) {
			$this->redirect_with_result( array( 'error' => 'upload' ) );
		}
		if ( (int) $file['size'] < 1 || (int) $file['size'] > self::MAX_IMPORT_BYTES ) {
			$this->redirect_with_result( array( 'error' => 'size' ) );
		}
		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			$this->redirect_with_result( array( 'error' => 'type' ) );
		}
		$rows = 'xlsx' === $extension ? self::read_xlsx( $file['tmp_name'] ) : self::read_csv( $file['tmp_name'] );
		if ( is_wp_error( $rows ) ) {
			$this->redirect_with_result( array( 'error' => $rows->get_error_code() ) );
		}
		$policy = 'update' === sanitize_key( wp_unslash( $_POST['duplicate_policy'] ?? '' ) ) ? 'update' : 'skip';
		$summary = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0 );
		foreach ( $rows as $row ) {
			$result = self::create_or_update_from_row( $row, $policy, 'excel_import' );
			$key = $result['result'] ?? 'invalid';
			$summary[ isset( $summary[ $key ] ) ? $key : 'invalid' ]++;
		}
		$this->redirect_with_result( $summary );
	}

	public function download_lead_template() {
		self::guard_manage();
		check_admin_referer( 'neocrm_download_lead_template' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=neocrm-lead-import-template.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'first_name', 'last_name', 'email', 'phone', 'company', 'stage', 'source', 'estimated_value', 'recurring_value', 'billing_interval' ) );
		fputcsv( $output, array( 'Asha', 'Sharma', 'asha@example.com', '+971500000000', 'Example Company', 'qualified', 'referral', '15000', '0', '' ) );
		fclose( $output );
		exit;
	}

	public function move_journey() {
		self::guard_manage();
		$id = absint( $_POST['journey_id'] ?? 0 );
		check_admin_referer( 'neocrm_move_journey_' . $id );
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? '' ) );
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$result = self::transition( $id, $stage );
		$wpdb->query( $result ? 'COMMIT' : 'ROLLBACK' );
		wp_safe_redirect( add_query_arg( $result ? 'journey_moved' : 'journey_error', 1, admin_url( 'admin.php?page=neocrm-funnel' ) ) );
		exit;
	}

	public function save_journey_details() {
		self::guard_manage();
		$id = absint( $_POST['journey_id'] ?? 0 );
		check_admin_referer( 'neocrm_save_journey_details_' . $id );
		global $wpdb;
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $journey ) { wp_die( esc_html__( 'Journey not found.', 'neo-crm' ) ); }
		$stage = sanitize_key( wp_unslash( $_POST['stage'] ?? $journey->stage ) );
		$medium = sanitize_key( wp_unslash( $_POST['communication_medium'] ?? '' ) );
		if ( ! in_array( $medium, array( '', 'email', 'phone', 'whatsapp' ), true ) ) { $medium = ''; }
		$follow_up = sanitize_text_field( wp_unslash( $_POST['next_follow_up_at'] ?? '' ) );
		$follow_up = $follow_up && preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/', $follow_up ) ? get_gmt_from_date( str_replace( 'T', ' ', $follow_up ) . ':00' ) : null;
		$data = array( 'estimated_value' => max( 0, (float) ( $_POST['estimated_value'] ?? 0 ) ), 'requirements' => sanitize_textarea_field( wp_unslash( $_POST['requirements'] ?? '' ) ), 'next_follow_up_at' => $follow_up, 'communication_medium' => $medium, 'updated_at' => NeoCRM_DB::now() );
		$wpdb->query( 'START TRANSACTION' );
		$stage_saved = $stage === $journey->stage || self::transition( $id, $stage );
		if ( false === $wpdb->update( $table, $data, array( 'id' => $id ) ) || ! $stage_saved ) { $wpdb->query( 'ROLLBACK' ); wp_safe_redirect( add_query_arg( 'journey_error', 1, admin_url( 'admin.php?page=neocrm-funnel' ) ) ); exit; }
		$note = sanitize_textarea_field( wp_unslash( $_POST['team_note'] ?? '' ) );
		if ( $note && false === $wpdb->insert( NeoCRM_DB::table( 'journey_updates' ), array( 'journey_id' => $id, 'stage' => $stage, 'update_type' => 'team_note', 'body' => $note, 'communication_medium' => $medium, 'author_user_id' => get_current_user_id(), 'created_at' => NeoCRM_DB::now() ) ) ) { $wpdb->query( 'ROLLBACK' ); wp_die( esc_html__( 'The team note could not be saved.', 'neo-crm' ) ); }
		$deal_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT deal_id FROM {$table} WHERE id=%d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $deal_id ) { $wpdb->update( NeoCRM_DB::table( 'deals' ), array( 'amount' => $data['estimated_value'], 'updated_at' => NeoCRM_DB::now() ), array( 'id' => $deal_id ) ); }
		$wpdb->query( 'COMMIT' );
		wp_safe_redirect( add_query_arg( 'journey_updated', 1, admin_url( 'admin.php?page=neocrm-funnel' ) ) );
		exit;
	}

	public static function ensure_visitor( $visitor_id, $source = 'website' ) {
		global $wpdb;
		$visitor_id = absint( $visitor_id );
		if ( ! $visitor_id ) { return 0; }
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE visitor_id=%d", $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) { return $id; }
		$now = NeoCRM_DB::now();
		$wpdb->insert( $table, array( 'visitor_id' => $visitor_id, 'stage' => 'visitor', 'source' => self::short_text( $source, 100 ), 'entry_method' => 'website', 'stage_entered_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	public static function attach_lead( $lead_id, $visitor_id = 0, $stage = 'lead', $entry_method = 'website' ) {
		global $wpdb;
		$lead_id = absint( $lead_id );
		if ( ! $lead_id ) { return 0; }
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE lead_id=%d", $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $id && $visitor_id ) { $id = self::ensure_visitor( $visitor_id ); }
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE id=%d', $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $lead ) { return 0; }
		$now = NeoCRM_DB::now();
		$data = array( 'lead_id' => $lead_id, 'stage' => isset( self::stages()[ $stage ] ) ? $stage : 'lead', 'source' => $lead->source, 'entry_method' => self::short_text( $entry_method, 30 ), 'owner_user_id' => $lead->owner_user_id, 'stage_entered_at' => $now, 'updated_at' => $now );
		if ( $id ) { $wpdb->update( $table, $data, array( 'id' => $id ) ); return $id; }
		$data['visitor_id'] = $visitor_id ?: null;
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	public static function sync_lead_status( $lead_id, $status ) {
		$map = array( 'new' => 'lead', 'working' => 'contacted', 'qualified' => 'qualified', 'disqualified' => 'lost' );
		if ( isset( $map[ $status ] ) ) {
			global $wpdb;
			$journey_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE lead_id=%d', absint( $lead_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $journey_id ) { $journey_id = self::attach_lead( $lead_id, 0, $map[ $status ], 'crm' ); }
			if ( $journey_id ) { self::transition( $journey_id, $map[ $status ] ); }
		}
	}

	public static function attach_conversion( $lead_id, $contact_id, $company_id = 0, $deal_id = 0 ) {
		global $wpdb;
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE lead_id=%d", absint( $lead_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $id ) { $id = self::attach_lead( $lead_id, 0, 'qualified', 'crm' ); }
		if ( $id ) { $wpdb->update( $table, array( 'contact_id' => absint( $contact_id ) ?: null, 'company_id' => absint( $company_id ) ?: null, 'deal_id' => absint( $deal_id ) ?: null, 'stage' => $deal_id ? 'proposal' : 'qualified', 'stage_entered_at' => NeoCRM_DB::now(), 'updated_at' => NeoCRM_DB::now() ), array( 'id' => $id ) ); }
		return $id;
	}

	public static function sync_deal_stage( $deal_id, $stage_name, $status ) {
		global $wpdb;
		$journey_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE deal_id=%d', absint( $deal_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $journey_id ) {
			$deal = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'deals' ) . ' WHERE id=%d', absint( $deal_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $deal && $deal->contact_id ) {
				$journey_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE contact_id=%d', $deal->contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $journey_id ) { $wpdb->update( NeoCRM_DB::table( 'customer_journeys' ), array( 'deal_id' => $deal_id, 'estimated_value' => $deal->amount ), array( 'id' => $journey_id ) ); }
			}
		}
		if ( ! $journey_id ) { return; }
		$normalized = sanitize_key( $stage_name );
		$target = 'won' === $status ? 'paying_client' : ( 'lost' === $status ? 'lost' : ( false !== strpos( $normalized, 'negotiat' ) ? 'negotiation' : ( false !== strpos( $normalized, 'proposal' ) ? 'proposal' : ( false !== strpos( $normalized, 'qualif' ) ? 'qualified' : 'proposal' ) ) ) );
		self::transition( $journey_id, $target );
	}

	public static function sync_contact_status( $contact_id, $status ) {
		global $wpdb;
		$map = array( 'new' => 'lead', 'contacted' => 'contacted', 'qualified' => 'qualified', 'customer' => 'paying_client', 'recurring' => 'recurring_client', 'lost' => 'lost' );
		if ( ! isset( $map[ $status ] ) ) { return; }
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE contact_id=%d", absint( $contact_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $id ) {
			$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE id=%d', absint( $contact_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $contact ) { return; }
			$now = NeoCRM_DB::now();
			$wpdb->insert( $table, array( 'contact_id' => $contact->id, 'company_id' => $contact->company_id ?: null, 'stage' => $map[ $status ], 'source' => $contact->source, 'entry_method' => 'crm', 'owner_user_id' => $contact->owner_user_id, 'stage_entered_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
			$id = (int) $wpdb->insert_id;
		}
		if ( $id ) { self::transition( $id, $map[ $status ] ); }
	}

	private static function create_or_update_from_row( $input, $duplicate_policy, $entry_method ) {
		global $wpdb;
		$row = self::normalize_row( is_array( $input ) ? $input : array() );
		$email = sanitize_email( $row['email'] ?? '' );
		$first_name = self::short_text( $row['first_name'] ?? '', 100 );
		if ( ! is_email( $email ) || ! $first_name ) { return array( 'result' => 'invalid' ); }
		$stage = sanitize_key( $row['stage'] ?? 'lead' );
		if ( 'visitor' === $stage || ! isset( self::stages()[ $stage ] ) ) { $stage = 'lead'; }
		$existing_contact_record = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email=%s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing_lead_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . NeoCRM_DB::table( 'leads' ) . " WHERE email=%s AND status NOT IN ('converted','disqualified') ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing_contact = $existing_contact_record ? (int) $existing_contact_record->id : 0;
		$existing_lead = $existing_lead_record ? (int) $existing_lead_record->id : 0;
		if ( 'skip' === $duplicate_policy && ( $existing_contact || $existing_lead ) ) { return array( 'result' => 'skipped' ); }
		$now = NeoCRM_DB::now();
		$source = self::short_text( $row['source'] ?? $entry_method, 100 );
		$lead_status = 'lost' === $stage ? 'disqualified' : ( 'contacted' === $stage ? 'working' : ( 'qualified' === $stage || self::stage_rank( $stage ) > self::stage_rank( 'qualified' ) ? 'qualified' : 'new' ) );
		$last_name = self::short_text( $row['last_name'] ?? '', 100 );
		$phone = self::short_text( $row['phone'] ?? '', 50 );
		$company = self::short_text( $row['company'] ?? '', 190 );
		$data = array(
			'first_name' => $first_name,
			'last_name' => $last_name ?: ( $existing_lead_record->last_name ?? '' ),
			'email' => $email,
			'phone' => $phone ?: ( $existing_lead_record->phone ?? '' ),
			'company' => $company ?: ( $existing_lead_record->company ?? '' ),
			'status' => $lead_status,
			'source' => $source,
			'owner_user_id' => get_current_user_id(),
			'updated_at' => $now,
		);
		$wpdb->query( 'START TRANSACTION' );
		if ( $existing_lead ) {
			if ( false === $wpdb->update( NeoCRM_DB::table( 'leads' ), $data, array( 'id' => $existing_lead ) ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
			$lead_id = $existing_lead;
			$result = 'updated';
		} elseif ( $existing_contact ) {
			$contact_update = array( 'first_name' => $first_name, 'updated_at' => $now );
			if ( $last_name ) { $contact_update['last_name'] = $last_name; }
			if ( $phone ) { $contact_update['phone'] = $phone; }
			if ( $company ) { $contact_update['company'] = $company; }
			if ( false === $wpdb->update( NeoCRM_DB::table( 'contacts' ), $contact_update, array( 'id' => $existing_contact ) ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
			self::sync_contact_status( $existing_contact, self::contact_status_for_stage( $stage ) );
			$journey_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'customer_journeys' ) . ' WHERE contact_id=%d', $existing_contact ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $journey_id ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
			self::update_journey_values( $journey_id, $row );
			if ( ! self::transition( $journey_id, $stage ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
			$wpdb->query( 'COMMIT' );
			return array( 'result' => 'updated', 'journey_id' => $journey_id );
		} else {
			$data['created_at'] = $now;
			if ( false === $wpdb->insert( NeoCRM_DB::table( 'leads' ), $data ) || ! $wpdb->insert_id ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
			$lead_id = (int) $wpdb->insert_id;
			$result = 'created';
			$wpdb->insert( NeoCRM_DB::table( 'activities' ), array( 'lead_id' => $lead_id, 'activity_type' => 'lead_created', 'subject' => __( 'Lead added manually', 'neo-crm' ), 'actor_user_id' => get_current_user_id(), 'created_at' => $now ) );
		}
		$journey_id = self::attach_lead( $lead_id, 0, $stage, $entry_method );
		if ( ! $journey_id ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
		self::update_journey_values( $journey_id, $row );
		if ( ! self::transition( $journey_id, $stage ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'result' => 'invalid' ); }
		$wpdb->query( 'COMMIT' );
		return array( 'result' => $result, 'lead_id' => $lead_id, 'journey_id' => $journey_id );
	}

	private static function update_journey_values( $journey_id, $row ) {
		global $wpdb;
		$interval = sanitize_key( $row['billing_interval'] ?? '' );
		if ( ! in_array( $interval, array( '', 'monthly', 'quarterly', 'annual' ), true ) ) { $interval = ''; }
		$wpdb->update( NeoCRM_DB::table( 'customer_journeys' ), array( 'estimated_value' => max( 0, (float) ( $row['estimated_value'] ?? 0 ) ), 'recurring_value' => max( 0, (float) ( $row['recurring_value'] ?? 0 ) ), 'billing_interval' => $interval, 'updated_at' => NeoCRM_DB::now() ), array( 'id' => absint( $journey_id ) ) );
	}

	private static function transition( $journey_id, $target_stage ) {
		global $wpdb;
		if ( ! isset( self::stages()[ $target_stage ] ) ) { return false; }
		$table = NeoCRM_DB::table( 'customer_journeys' );
		$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", absint( $journey_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $journey ) { return false; }
		if ( 'visitor' === $target_stage && ( $journey->lead_id || $journey->contact_id ) ) { return false; }
		$now = NeoCRM_DB::now();
		if ( in_array( $target_stage, array( 'proposal', 'negotiation', 'paying_client', 'recurring_client' ), true ) && ! $journey->contact_id ) {
			$contact_id = self::convert_journey_lead( $journey );
			if ( ! $contact_id ) { return false; }
			$journey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $journey_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( in_array( $target_stage, array( 'proposal', 'negotiation', 'paying_client', 'recurring_client' ), true ) ) {
			$deal_id = self::ensure_deal_for_stage( $journey, $target_stage );
			if ( ! $deal_id ) { return false; }
			$journey->deal_id = $deal_id;
		}
		if ( 'lost' === $target_stage && $journey->deal_id && ! self::ensure_deal_for_stage( $journey, 'lost' ) ) { return false; }
		if ( $journey->lead_id && ( self::stage_rank( $target_stage ) <= self::stage_rank( 'qualified' ) || 'lost' === $target_stage ) ) {
			$status = 'lost' === $target_stage ? 'disqualified' : ( 'lead' === $target_stage || 'visitor' === $target_stage ? 'new' : ( 'contacted' === $target_stage ? 'working' : 'qualified' ) );
			$wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'status' => $status, 'updated_at' => $now ), array( 'id' => $journey->lead_id ) );
		}
		if ( $journey->contact_id ) {
			$wpdb->update( NeoCRM_DB::table( 'contacts' ), array( 'status' => self::contact_status_for_stage( $target_stage ), 'updated_at' => $now ), array( 'id' => $journey->contact_id ) );
		}
		$updated = $wpdb->update( $table, array( 'stage' => $target_stage, 'deal_id' => $journey->deal_id ?: null, 'stage_entered_at' => $now, 'updated_at' => $now ), array( 'id' => $journey_id ) );
		if ( false === $updated ) { return false; }
		NeoCRM_DB::log_activity( 'journey_stage_changed', sprintf( __( 'Client journey moved to %s', 'neo-crm' ), self::stages()[ $target_stage ]['label'] ), $journey->contact_id, array( 'journey_id' => $journey_id, 'stage' => $target_stage ) );
		return true;
	}

	private static function convert_journey_lead( $journey ) {
		global $wpdb;
		if ( ! $journey->lead_id ) { return 0; }
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE id=%d', $journey->lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $lead ) { return 0; }
		$now = NeoCRM_DB::now();
		$company_id = 0;
		if ( $lead->company ) {
			$company_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'companies' ) . ' WHERE name=%s', $lead->company ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $company_id ) { $wpdb->insert( NeoCRM_DB::table( 'companies' ), array( 'name' => $lead->company, 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) ); $company_id = (int) $wpdb->insert_id; }
		}
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email=%s', $lead->email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $contact_id ) {
			$wpdb->insert( NeoCRM_DB::table( 'contacts' ), array( 'company_id' => $company_id ?: null, 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'email' => $lead->email, 'phone' => $lead->phone, 'company' => $lead->company, 'status' => 'qualified', 'source' => $lead->source, 'owner_user_id' => get_current_user_id(), 'marketing_consent' => 0, 'created_at' => $now, 'updated_at' => $now ) );
			$contact_id = (int) $wpdb->insert_id;
		}
		if ( ! $contact_id ) { return 0; }
		$wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'status' => 'converted', 'converted_contact_id' => $contact_id, 'converted_company_id' => $company_id ?: null, 'converted_at' => $now, 'updated_at' => $now ), array( 'id' => $lead->id ) );
		if ( $lead->visitor_id ) { $wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'contact_id' => $contact_id ), array( 'id' => $lead->visitor_id ) ); }
		$wpdb->update( NeoCRM_DB::table( 'activities' ), array( 'contact_id' => $contact_id ), array( 'lead_id' => $lead->id ) );
		$wpdb->update( NeoCRM_DB::table( 'customer_journeys' ), array( 'contact_id' => $contact_id, 'company_id' => $company_id ?: null, 'updated_at' => $now ), array( 'id' => $journey->id ) );
		return $contact_id;
	}

	private static function ensure_deal_for_stage( $journey, $target_stage ) {
		global $wpdb;
		$pipeline = $wpdb->get_row( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipelines' ) . ' ORDER BY is_default DESC, id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $pipeline ) { return 0; }
		$target_name = in_array( $target_stage, array( 'paying_client', 'recurring_client' ), true ) ? 'Won' : ucfirst( $target_stage );
		$stage = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' WHERE pipeline_id=%d AND name=%s LIMIT 1', $pipeline->id, $target_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $stage ) { return 0; }
		$now = NeoCRM_DB::now();
		$deal_id = absint( $journey->deal_id );
		if ( ! $deal_id ) {
			if ( 'lost' === $target_stage ) { return 0; }
			$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE id=%d', $journey->contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $contact ) { return 0; }
			$name = ( $contact->company ?: trim( $contact->first_name . ' ' . $contact->last_name ) ) . ' opportunity';
			$wpdb->insert( NeoCRM_DB::table( 'deals' ), array( 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'contact_id' => $contact->id, 'company_id' => $contact->company_id ?: null, 'name' => self::short_text( $name, 190 ), 'amount' => max( 0, (float) $journey->estimated_value ), 'currency' => 'AED', 'probability' => $stage->probability, 'status' => $stage->is_won ? 'won' : 'open', 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			$deal_id = (int) $wpdb->insert_id;
		} else {
			$status = $stage->is_closed ? ( $stage->is_won ? 'won' : 'lost' ) : 'open';
			$wpdb->update( NeoCRM_DB::table( 'deals' ), array( 'stage_id' => $stage->id, 'probability' => $stage->probability, 'status' => $status, 'amount' => max( 0, (float) $journey->estimated_value ), 'updated_at' => $now ), array( 'id' => $deal_id, 'pipeline_id' => $pipeline->id ) );
		}
		if ( $deal_id && $journey->lead_id ) { $wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'converted_deal_id' => $deal_id ), array( 'id' => $journey->lead_id ) ); }
		return $deal_id;
	}

	private static function read_csv( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) { return new WP_Error( 'read' ); }
		$sample = (string) fgets( $handle );
		$delimiters = array( ',' => substr_count( $sample, ',' ), ';' => substr_count( $sample, ';' ), "\t" => substr_count( $sample, "\t" ) );
		arsort( $delimiters );
		$delimiter = (string) array_key_first( $delimiters );
		rewind( $handle );
		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $headers ) ) { fclose( $handle ); return new WP_Error( 'headers' ); }
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		$headers = array_map( array( __CLASS__, 'normalize_header' ), $headers );
		if ( ! in_array( 'first_name', $headers, true ) || ! in_array( 'email', $headers, true ) ) { fclose( $handle ); return new WP_Error( 'headers' ); }
		$rows = array();
		while ( count( $rows ) < self::MAX_IMPORT_ROWS && false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( 1 === count( $values ) && '' === trim( (string) $values[0] ) ) { continue; }
			$values = array_pad( $values, count( $headers ), '' );
			$rows[] = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
		}
		fclose( $handle );
		return $rows;
	}

	private static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) { return new WP_Error( 'xlsx_unavailable' ); }
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) { return new WP_Error( 'xlsx_open' ); }
		$sheet_stat = $zip->statName( 'xl/worksheets/sheet1.xml' );
		$shared_stat = $zip->statName( 'xl/sharedStrings.xml' );
		if ( ! $sheet_stat || (int) $sheet_stat['size'] > 10485760 || ( $shared_stat && (int) $shared_stat['size'] > 10485760 ) ) { $zip->close(); return new WP_Error( 'xlsx_size' ); }
		$shared = array();
		if ( $shared_stat ) {
			$xml = simplexml_load_string( $zip->getFromName( 'xl/sharedStrings.xml' ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
			if ( $xml ) { foreach ( $xml->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' )->si as $item ) { $text = (string) $item->t; foreach ( $item->r as $run ) { $text .= (string) $run->t; } $shared[] = $text; } }
		}
		$sheet = simplexml_load_string( $zip->getFromName( 'xl/worksheets/sheet1.xml' ), 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		$zip->close();
		if ( ! $sheet ) { return new WP_Error( 'xlsx_sheet' ); }
		$matrix = array();
		$main = $sheet->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		foreach ( $main->sheetData->row as $xml_row ) {
			$row = array();
			foreach ( $xml_row->c as $cell ) {
				$reference = (string) $cell['r'];
				preg_match( '/^[A-Z]+/', $reference, $match );
				$index = self::column_index( $match[0] ?? 'A' );
				$type = (string) $cell['t'];
				$value = 'inlineStr' === $type ? (string) $cell->is->t : (string) $cell->v;
				if ( 's' === $type ) { $value = $shared[ (int) $value ] ?? ''; }
				$row[ $index ] = $value;
			}
			if ( $row ) { ksort( $row ); $matrix[] = $row; }
			if ( count( $matrix ) > self::MAX_IMPORT_ROWS ) { break; }
		}
		if ( ! $matrix ) { return new WP_Error( 'headers' ); }
		$header_row = array_shift( $matrix );
		$max_column = max( array_keys( $header_row ) );
		$headers = array();
		for ( $i = 0; $i <= $max_column; $i++ ) { $headers[] = self::normalize_header( $header_row[ $i ] ?? '' ); }
		if ( ! in_array( 'first_name', $headers, true ) || ! in_array( 'email', $headers, true ) ) { return new WP_Error( 'headers' ); }
		$rows = array();
		foreach ( $matrix as $values ) {
			$record = array();
			foreach ( $headers as $i => $header ) { if ( $header ) { $record[ $header ] = $values[ $i ] ?? ''; } }
			if ( array_filter( $record, static fn( $value ) => '' !== trim( (string) $value ) ) ) { $rows[] = $record; }
		}
		return $rows;
	}

	private static function normalize_row( $row ) {
		$normalized = array();
		foreach ( $row as $key => $value ) { $normalized[ self::normalize_header( $key ) ] = $value; }
		return $normalized;
	}

	public static function normalize_header( $header ) {
		$key = sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( (string) $header ) ) ) );
		$aliases = array( 'firstname' => 'first_name', 'first' => 'first_name', 'lastname' => 'last_name', 'surname' => 'last_name', 'email_address' => 'email', 'mobile' => 'phone', 'telephone' => 'phone', 'organization' => 'company', 'organisation' => 'company', 'journey_stage' => 'stage', 'status' => 'stage', 'lead_source' => 'source', 'value' => 'estimated_value', 'deal_value' => 'estimated_value', 'monthly_value' => 'recurring_value', 'billing' => 'billing_interval' );
		return $aliases[ $key ] ?? $key;
	}

	private static function column_index( $letters ) {
		$index = 0;
		foreach ( str_split( $letters ) as $letter ) { $index = ( $index * 26 ) + ( ord( $letter ) - 64 ); }
		return max( 0, $index - 1 );
	}

	private static function stage_rank( $stage ) { return array_search( $stage, array_keys( self::stages() ), true ); }
	private static function contact_status_for_stage( $stage ) { return 'recurring_client' === $stage ? 'recurring' : ( 'paying_client' === $stage ? 'customer' : ( 'lost' === $stage ? 'lost' : ( in_array( $stage, array( 'qualified', 'proposal', 'negotiation' ), true ) ? 'qualified' : ( 'contacted' === $stage ? 'contacted' : 'new' ) ) ) ); }
	private static function short_text( $value, $length ) { $value = sanitize_text_field( (string) $value ); return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length ); }
	private static function guard_view() { if ( ! current_user_can( 'view_neocrm' ) ) { wp_die( esc_html__( 'You do not have permission to view NeoCRM.', 'neo-crm' ) ); } }
	private static function guard_manage() { if ( ! current_user_can( 'manage_neocrm' ) ) { wp_die( esc_html__( 'You do not have permission to change CRM records.', 'neo-crm' ) ); } }
	private static function header( $title, $subtitle ) { ?><header class="neocrm-header"><div><span class="neocrm-brand">NEOCRM</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div><span class="neocrm-version">v<?php echo esc_html( NEOCRM_VERSION ); ?></span></header><?php }

	private static function render_notice() {
		if ( isset( $_GET['created'] ) || isset( $_GET['updated'] ) || isset( $_GET['skipped'] ) || isset( $_GET['invalid'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( __( 'Import complete: %1$d created, %2$d updated, %3$d skipped, %4$d invalid.', 'neo-crm' ), absint( $_GET['created'] ?? 0 ), absint( $_GET['updated'] ?? 0 ), absint( $_GET['skipped'] ?? 0 ), absint( $_GET['invalid'] ?? 0 ) ) ) );
		} elseif ( isset( $_GET['lead_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The relationship was added to the sales journey.', 'neo-crm' ) . '</p></div>';
		} elseif ( isset( $_GET['journey_moved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The client journey moved to its new stage.', 'neo-crm' ) . '</p></div>';
		} elseif ( isset( $_GET['journey_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The journey details and team history were updated.', 'neo-crm' ) . '</p></div>';
		} elseif ( isset( $_GET['error'] ) || isset( $_GET['journey_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'NeoCRM could not process that record. Check the required fields, file type, and server Excel support.', 'neo-crm' ) . '</p></div>';
		}
	}

	private function redirect_with_result( $result ) {
		$args = array( 'page' => 'neocrm-leads' );
		if ( isset( $result['created'] ) || isset( $result['updated'] ) || isset( $result['skipped'] ) || isset( $result['invalid'] ) ) { $args = array_merge( $args, $result ); }
		elseif ( in_array( $result['result'] ?? '', array( 'created', 'updated' ), true ) ) { $args['lead_saved'] = 1; }
		else { $args['error'] = sanitize_key( $result['error'] ?? $result['result'] ?? 'invalid' ); }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
