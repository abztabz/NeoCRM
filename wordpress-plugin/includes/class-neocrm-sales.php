<?php
/**
 * Human sales workflow for NeoCRM.
 *
 * @package NeoCRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoCRM_Sales {
	public function __construct() {
		add_action( 'admin_post_neocrm_save_record', array( $this, 'save_record' ) );
		add_action( 'admin_post_neocrm_convert_lead', array( $this, 'convert_lead' ) );
		add_action( 'admin_post_neocrm_move_deal', array( $this, 'move_deal' ) );
		add_action( 'admin_post_neocrm_complete_task', array( $this, 'complete_task' ) );
	}

	public static function render_leads() {
		self::guard();
		$lead_id = absint( $_GET['lead_id'] ?? 0 );
		if ( $lead_id ) {
			self::render_lead_detail( $lead_id );
			return;
		}
		global $wpdb;
		$table  = NeoCRM_DB::table( 'leads' );
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$where  = array( '1=1' );
		$args   = array();
		if ( in_array( $status, array( 'new', 'working', 'qualified', 'disqualified', 'converted' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( $search ) {
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR company LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			array_push( $args, $like, $like, $like, $like );
		}
		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY score DESC, updated_at DESC LIMIT 200'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::open_page( __( 'Leads', 'neo-crm' ), __( 'Website enquiries and team-added prospects at any point in the sales journey.', 'neo-crm' ) );
		NeoCRM_Funnel::render_lead_intake();
		?>
		<form class="neocrm-toolbar" method="get"><input type="hidden" name="page" value="neocrm-leads"><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, company', 'neo-crm' ); ?>"><select name="status"><option value=""><?php esc_html_e( 'All statuses', 'neo-crm' ); ?></option><?php foreach ( self::lead_statuses() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'Filter', 'neo-crm' ); ?></button></form>
		<section class="neocrm-panel neocrm-table-wrap"><table class="widefat striped neocrm-table"><thead><tr><th><?php esc_html_e( 'Lead', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Company', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Status', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Score', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Source', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Updated', 'neo-crm' ); ?></th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="6" class="neocrm-empty"><?php esc_html_e( 'No leads match this view.', 'neo-crm' ); ?></td></tr><?php endif; ?>
		<?php foreach ( $rows as $lead ) : ?><tr><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-leads&lead_id=' . absint( $lead->id ) ) ); ?>"><strong><?php echo esc_html( trim( $lead->first_name . ' ' . $lead->last_name ) ); ?></strong></a><br><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></td><td><?php echo esc_html( $lead->company ?: '—' ); ?></td><td><span class="neocrm-pill neocrm-status-<?php echo esc_attr( $lead->status ); ?>"><?php echo esc_html( self::lead_statuses()[ $lead->status ] ?? $lead->status ); ?></span></td><td><span class="neocrm-score <?php echo $lead->score >= 50 ? 'is-hot' : ''; ?>"><?php echo absint( $lead->score ); ?></span></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $lead->source ) ) ); ?></td><td><?php echo esc_html( self::relative_time( $lead->updated_at ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table></section></div>
		<?php
	}

	private static function render_lead_detail( $lead_id ) {
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE id = %d', $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $lead ) {
			wp_die( esc_html__( 'Lead not found.', 'neo-crm' ) );
		}
		$activities = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'activities' ) . ' WHERE lead_id = %d ORDER BY created_at DESC LIMIT 100', $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$events = $lead->visitor_id ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'events' ) . ' WHERE visitor_id = %d ORDER BY occurred_at DESC LIMIT 100', $lead->visitor_id ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap neocrm-wrap"><a class="neocrm-back" href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-leads' ) ); ?>">&larr; <?php esc_html_e( 'All leads', 'neo-crm' ); ?></a><?php self::header( trim( $lead->first_name . ' ' . $lead->last_name ), __( 'Qualification workspace', 'neo-crm' ) ); ?>
		<div class="neocrm-workspace-grid"><main>
			<section class="neocrm-panel"><div class="neocrm-panel-head"><div><h2><?php esc_html_e( 'Lead record', 'neo-crm' ); ?></h2><p><?php esc_html_e( 'A public enquiry is unverified until your team qualifies it.', 'neo-crm' ); ?></p></div><span class="neocrm-score <?php echo $lead->score >= 50 ? 'is-hot' : ''; ?>"><?php echo absint( $lead->score ); ?></span></div><dl class="neocrm-record-dl"><dt><?php esc_html_e( 'Email', 'neo-crm' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></dd><dt><?php esc_html_e( 'Phone', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $lead->phone ?: '—' ); ?></dd><dt><?php esc_html_e( 'Company', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $lead->company ?: '—' ); ?></dd><dt><?php esc_html_e( 'Marketing request', 'neo-crm' ); ?></dt><dd><?php echo $lead->marketing_opt_in_requested ? esc_html__( 'Requested — verification still required', 'neo-crm' ) : esc_html__( 'No', 'neo-crm' ); ?></dd></dl></section>
			<section class="neocrm-panel"><h2><?php esc_html_e( 'Relationship timeline', 'neo-crm' ); ?></h2><div class="neocrm-timeline"><?php if ( ! $activities && ! $events ) : ?><p class="neocrm-empty"><?php esc_html_e( 'No activity yet.', 'neo-crm' ); ?></p><?php endif; ?><?php foreach ( $activities as $activity ) : ?><article class="neocrm-event"><span class="dashicons dashicons-feedback"></span><div><strong><?php echo esc_html( $activity->subject ); ?></strong><?php if ( $activity->body ) : ?><p><?php echo nl2br( esc_html( $activity->body ) ); ?></p><?php endif; ?></div><time><?php echo esc_html( self::relative_time( $activity->created_at ) ); ?></time></article><?php endforeach; ?><?php foreach ( $events as $event ) : ?><article class="neocrm-event"><span class="dashicons dashicons-visibility"></span><div><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $event->event_type ) ) ); ?></strong><p><?php echo esc_html( $event->page_title ?: __( 'Website activity', 'neo-crm' ) ); ?></p></div><time><?php echo esc_html( self::relative_time( $event->occurred_at ) ); ?></time></article><?php endforeach; ?></div></section>
		</main><aside>
			<section class="neocrm-panel"><h2><?php esc_html_e( 'Qualification', 'neo-crm' ); ?></h2><form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="lead"><input type="hidden" name="record_id" value="<?php echo absint( $lead->id ); ?>"><?php wp_nonce_field( 'neocrm_save_lead' ); ?><label><?php esc_html_e( 'Status', 'neo-crm' ); ?><select name="status"><?php foreach ( self::lead_statuses() as $value => $label ) : if ( 'converted' === $value ) { continue; } ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $lead->status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button class="button button-primary"><?php esc_html_e( 'Update lead', 'neo-crm' ); ?></button></form></section>
			<?php if ( 'qualified' === $lead->status ) : ?><section class="neocrm-panel"><h2><?php esc_html_e( 'Convert lead', 'neo-crm' ); ?></h2><p><?php esc_html_e( 'Creates the contact and company only after your team approves the enquiry.', 'neo-crm' ); ?></p><form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_convert_lead"><input type="hidden" name="lead_id" value="<?php echo absint( $lead->id ); ?>"><?php wp_nonce_field( 'neocrm_convert_lead_' . $lead->id ); ?><label class="neocrm-toggle"><input type="checkbox" name="create_deal" value="1"><span><strong><?php esc_html_e( 'Create an opportunity', 'neo-crm' ); ?></strong></span></label><label><?php esc_html_e( 'Deal name', 'neo-crm' ); ?><input name="deal_name" value="<?php echo esc_attr( ( $lead->company ?: $lead->first_name ) . ' opportunity' ); ?>"></label><label><?php esc_html_e( 'Value (AED)', 'neo-crm' ); ?><input type="number" min="0" step="0.01" name="amount" value="0"></label><button class="button button-primary"><?php esc_html_e( 'Convert to contact', 'neo-crm' ); ?></button></form></section><?php elseif ( ! in_array( $lead->status, array( 'converted', 'disqualified' ), true ) ) : ?><section class="neocrm-panel"><h2><?php esc_html_e( 'Conversion locked', 'neo-crm' ); ?></h2><p><?php esc_html_e( 'Mark this Lead as Qualified before conversion.', 'neo-crm' ); ?></p></section><?php endif; ?>
		</aside></div></div>
		<?php
	}

	public static function render_companies() {
		self::guard();
		global $wpdb;
		$companies = $wpdb->get_results( 'SELECT c.*, (SELECT COUNT(*) FROM ' . NeoCRM_DB::table( 'contacts' ) . ' ct WHERE ct.company_id=c.id) contacts_count, (SELECT COUNT(*) FROM ' . NeoCRM_DB::table( 'deals' ) . ' d WHERE d.company_id=c.id) deals_count FROM ' . NeoCRM_DB::table( 'companies' ) . ' c ORDER BY c.updated_at DESC LIMIT 200' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::open_page( __( 'Companies', 'neo-crm' ), __( 'Organizations connected to contacts and opportunities.', 'neo-crm' ) );
		?>
		<section class="neocrm-panel neocrm-quick-create"><h2><?php esc_html_e( 'Add company', 'neo-crm' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="company"><?php wp_nonce_field( 'neocrm_save_company' ); ?><input name="name" required placeholder="<?php esc_attr_e( 'Company name', 'neo-crm' ); ?>"><input name="domain" placeholder="<?php esc_attr_e( 'Domain', 'neo-crm' ); ?>"><input name="phone" placeholder="<?php esc_attr_e( 'Phone', 'neo-crm' ); ?>"><button class="button button-primary"><?php esc_html_e( 'Add company', 'neo-crm' ); ?></button></form></section>
		<section class="neocrm-panel neocrm-table-wrap"><table class="widefat striped neocrm-table"><thead><tr><th><?php esc_html_e( 'Company', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Domain', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Contacts', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Deals', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Updated', 'neo-crm' ); ?></th></tr></thead><tbody><?php if ( ! $companies ) : ?><tr><td colspan="5" class="neocrm-empty"><?php esc_html_e( 'No companies yet.', 'neo-crm' ); ?></td></tr><?php endif; ?><?php foreach ( $companies as $company ) : ?><tr><td><strong><?php echo esc_html( $company->name ); ?></strong><?php if ( $company->phone ) : ?><br><span class="neocrm-muted"><?php echo esc_html( $company->phone ); ?></span><?php endif; ?></td><td><?php echo esc_html( $company->domain ?: '—' ); ?></td><td><?php echo absint( $company->contacts_count ); ?></td><td><?php echo absint( $company->deals_count ); ?></td><td><?php echo esc_html( self::relative_time( $company->updated_at ) ); ?></td></tr><?php endforeach; ?></tbody></table></section></div>
		<?php
	}

	public static function render_deals() {
		self::guard();
		global $wpdb;
		$pipeline = $wpdb->get_row( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipelines' ) . ' ORDER BY is_default DESC, id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$stages   = $pipeline ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' WHERE pipeline_id=%d ORDER BY position', $pipeline->id ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deals    = $pipeline ? $wpdb->get_results( $wpdb->prepare( 'SELECT d.*, c.first_name, c.last_name, co.name company_name FROM ' . NeoCRM_DB::table( 'deals' ) . ' d LEFT JOIN ' . NeoCRM_DB::table( 'contacts' ) . ' c ON c.id=d.contact_id LEFT JOIN ' . NeoCRM_DB::table( 'companies' ) . ' co ON co.id=d.company_id WHERE d.pipeline_id=%d ORDER BY d.updated_at DESC', $pipeline->id ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$contacts = $wpdb->get_results( 'SELECT id, first_name, last_name FROM ' . NeoCRM_DB::table( 'contacts' ) . ' ORDER BY first_name, last_name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$companies= $wpdb->get_results( 'SELECT id, name FROM ' . NeoCRM_DB::table( 'companies' ) . ' ORDER BY name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::open_page( __( 'Deals', 'neo-crm' ), __( 'A visible pipeline from qualified opportunity to won business.', 'neo-crm' ) );
		if ( ! $pipeline ) : ?><section class="neocrm-panel neocrm-empty"><?php esc_html_e( 'The sales pipeline could not be initialized. Reactivate NeoCRM to repair it.', 'neo-crm' ); ?></section></div><?php return; endif;
		?>
		<section class="neocrm-panel neocrm-quick-create"><h2><?php esc_html_e( 'Add deal', 'neo-crm' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="deal"><input type="hidden" name="pipeline_id" value="<?php echo absint( $pipeline->id ); ?>"><input type="hidden" name="currency" value="AED"><?php wp_nonce_field( 'neocrm_save_deal' ); ?><input name="name" required placeholder="<?php esc_attr_e( 'Opportunity name', 'neo-crm' ); ?>"><input type="number" min="0" step="0.01" name="amount" value="0" aria-label="<?php esc_attr_e( 'Amount in AED', 'neo-crm' ); ?>"><select name="stage_id"><?php foreach ( $stages as $stage ) : if ( $stage->is_closed ) { continue; } ?><option value="<?php echo absint( $stage->id ); ?>"><?php echo esc_html( $stage->name ); ?></option><?php endforeach; ?></select><select name="contact_id"><option value="0"><?php esc_html_e( 'No contact', 'neo-crm' ); ?></option><?php foreach ( $contacts as $contact ) : ?><option value="<?php echo absint( $contact->id ); ?>"><?php echo esc_html( trim( $contact->first_name . ' ' . $contact->last_name ) ); ?></option><?php endforeach; ?></select><select name="company_id"><option value="0"><?php esc_html_e( 'No company', 'neo-crm' ); ?></option><?php foreach ( $companies as $company ) : ?><option value="<?php echo absint( $company->id ); ?>"><?php echo esc_html( $company->name ); ?></option><?php endforeach; ?></select><button class="button button-primary"><?php esc_html_e( 'Add deal', 'neo-crm' ); ?></button></form></section>
		<div class="neocrm-pipeline"><?php foreach ( $stages as $stage ) : $stage_deals = array_filter( $deals, static fn( $deal ) => (int) $deal->stage_id === (int) $stage->id ); ?><section class="neocrm-stage" style="--stage-color:<?php echo esc_attr( $stage->color ); ?>"><header><div><h2><?php echo esc_html( $stage->name ); ?></h2><span><?php echo count( $stage_deals ); ?></span></div><strong><?php echo esc_html( number_format_i18n( array_sum( array_map( static fn( $deal ) => (float) $deal->amount, $stage_deals ) ), 2 ) ); ?> AED</strong></header><?php if ( ! $stage_deals ) : ?><p class="neocrm-stage-empty"><?php esc_html_e( 'No deals', 'neo-crm' ); ?></p><?php endif; ?><?php foreach ( $stage_deals as $deal ) : ?><article class="neocrm-deal-card"><h3><?php echo esc_html( $deal->name ); ?></h3><strong><?php echo esc_html( $deal->currency . ' ' . number_format_i18n( $deal->amount, 2 ) ); ?></strong><p><?php echo esc_html( $deal->company_name ?: trim( $deal->first_name . ' ' . $deal->last_name ) ?: __( 'Unlinked opportunity', 'neo-crm' ) ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_move_deal"><input type="hidden" name="deal_id" value="<?php echo absint( $deal->id ); ?>"><?php wp_nonce_field( 'neocrm_move_deal_' . $deal->id ); ?><select name="stage_id" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Move deal', 'neo-crm' ); ?>"><?php foreach ( $stages as $target ) : ?><option value="<?php echo absint( $target->id ); ?>" <?php selected( $deal->stage_id, $target->id ); ?>><?php echo esc_html( $target->name ); ?></option><?php endforeach; ?></select></form></article><?php endforeach; ?></section><?php endforeach; ?></div></div>
		<?php
	}

	public static function render_tasks() {
		self::guard();
		global $wpdb;
		$tasks    = $wpdb->get_results( "SELECT t.*, c.first_name, c.last_name, d.name deal_name FROM " . NeoCRM_DB::table( 'tasks' ) . " t LEFT JOIN " . NeoCRM_DB::table( 'contacts' ) . " c ON c.id=t.contact_id LEFT JOIN " . NeoCRM_DB::table( 'deals' ) . " d ON d.id=t.deal_id ORDER BY (t.status='open') DESC, t.due_at IS NULL, t.due_at ASC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$contacts = $wpdb->get_results( 'SELECT id, first_name, last_name FROM ' . NeoCRM_DB::table( 'contacts' ) . ' ORDER BY first_name, last_name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deals    = $wpdb->get_results( "SELECT id, name FROM " . NeoCRM_DB::table( 'deals' ) . " WHERE status='open' ORDER BY name" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::open_page( __( 'Tasks', 'neo-crm' ), __( 'Follow-ups with an owner, priority, and due date.', 'neo-crm' ) );
		?>
		<section class="neocrm-panel neocrm-quick-create"><h2><?php esc_html_e( 'Add task', 'neo-crm' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="task"><?php wp_nonce_field( 'neocrm_save_task' ); ?><input name="title" required placeholder="<?php esc_attr_e( 'Follow-up task', 'neo-crm' ); ?>"><input type="datetime-local" name="due_at" aria-label="<?php esc_attr_e( 'Due date', 'neo-crm' ); ?>"><select name="priority"><option value="normal"><?php esc_html_e( 'Normal', 'neo-crm' ); ?></option><option value="high"><?php esc_html_e( 'High', 'neo-crm' ); ?></option><option value="low"><?php esc_html_e( 'Low', 'neo-crm' ); ?></option></select><select name="contact_id"><option value="0"><?php esc_html_e( 'No contact', 'neo-crm' ); ?></option><?php foreach ( $contacts as $contact ) : ?><option value="<?php echo absint( $contact->id ); ?>"><?php echo esc_html( trim( $contact->first_name . ' ' . $contact->last_name ) ); ?></option><?php endforeach; ?></select><select name="deal_id"><option value="0"><?php esc_html_e( 'No deal', 'neo-crm' ); ?></option><?php foreach ( $deals as $deal ) : ?><option value="<?php echo absint( $deal->id ); ?>"><?php echo esc_html( $deal->name ); ?></option><?php endforeach; ?></select><button class="button button-primary"><?php esc_html_e( 'Add task', 'neo-crm' ); ?></button></form></section>
		<section class="neocrm-panel neocrm-table-wrap"><table class="widefat striped neocrm-table"><thead><tr><th><?php esc_html_e( 'Task', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Related to', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Priority', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Due', 'neo-crm' ); ?></th><th><?php esc_html_e( 'Status', 'neo-crm' ); ?></th></tr></thead><tbody><?php if ( ! $tasks ) : ?><tr><td colspan="5" class="neocrm-empty"><?php esc_html_e( 'No tasks yet.', 'neo-crm' ); ?></td></tr><?php endif; ?><?php foreach ( $tasks as $task ) : $overdue = 'open' === $task->status && $task->due_at && strtotime( $task->due_at . ' UTC' ) < time(); ?><tr><td><strong><?php echo esc_html( $task->title ); ?></strong></td><td><?php echo esc_html( $task->deal_name ?: trim( $task->first_name . ' ' . $task->last_name ) ?: '—' ); ?></td><td><span class="neocrm-pill neocrm-priority-<?php echo esc_attr( $task->priority ); ?>"><?php echo esc_html( ucfirst( $task->priority ) ); ?></span></td><td class="<?php echo $overdue ? 'neocrm-overdue' : ''; ?>"><?php echo $task->due_at ? esc_html( get_date_from_gmt( $task->due_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—'; ?></td><td><?php if ( 'open' === $task->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_complete_task"><input type="hidden" name="task_id" value="<?php echo absint( $task->id ); ?>"><?php wp_nonce_field( 'neocrm_complete_task_' . $task->id ); ?><button class="button button-small"><?php esc_html_e( 'Mark complete', 'neo-crm' ); ?></button></form><?php else : ?><span class="neocrm-pill is-positive"><?php esc_html_e( 'Completed', 'neo-crm' ); ?></span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section></div>
		<?php
	}

	public static function render_contact_detail( $contact_id ) {
		self::guard();
		global $wpdb;
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT c.*, co.name company_name FROM ' . NeoCRM_DB::table( 'contacts' ) . ' c LEFT JOIN ' . NeoCRM_DB::table( 'companies' ) . ' co ON co.id=c.company_id WHERE c.id=%d', $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $contact ) { wp_die( esc_html__( 'Contact not found.', 'neo-crm' ) ); }
		$activities = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'activities' ) . ' WHERE contact_id=%d ORDER BY created_at DESC LIMIT 100', $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$notes = $wpdb->get_results( $wpdb->prepare( 'SELECT n.*, u.display_name FROM ' . NeoCRM_DB::table( 'notes' ) . ' n LEFT JOIN ' . $wpdb->users . ' u ON u.ID=n.author_user_id WHERE n.contact_id=%d ORDER BY n.created_at DESC LIMIT 100', $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deals = $wpdb->get_results( $wpdb->prepare( 'SELECT d.*, s.name stage_name FROM ' . NeoCRM_DB::table( 'deals' ) . ' d LEFT JOIN ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' s ON s.id=d.stage_id WHERE d.contact_id=%d ORDER BY d.updated_at DESC', $contact_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<div class="wrap neocrm-wrap"><a class="neocrm-back" href="<?php echo esc_url( admin_url( 'admin.php?page=neocrm-contacts' ) ); ?>">&larr; <?php esc_html_e( 'All contacts', 'neo-crm' ); ?></a><?php self::header( trim( $contact->first_name . ' ' . $contact->last_name ), __( 'Complete relationship record', 'neo-crm' ) ); ?><div class="neocrm-workspace-grid"><main><section class="neocrm-panel"><h2><?php esc_html_e( 'Contact profile', 'neo-crm' ); ?></h2><dl class="neocrm-record-dl"><dt><?php esc_html_e( 'Email', 'neo-crm' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $contact->email ); ?>"><?php echo esc_html( $contact->email ); ?></a></dd><dt><?php esc_html_e( 'Phone', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $contact->phone ?: '—' ); ?></dd><dt><?php esc_html_e( 'Company', 'neo-crm' ); ?></dt><dd><?php echo esc_html( $contact->company_name ?: $contact->company ?: '—' ); ?></dd><dt><?php esc_html_e( 'Status', 'neo-crm' ); ?></dt><dd><?php echo esc_html( ucfirst( $contact->status ) ); ?></dd></dl></section><section class="neocrm-panel"><h2><?php esc_html_e( 'Activity and notes', 'neo-crm' ); ?></h2><div class="neocrm-timeline"><?php if ( ! $notes && ! $activities ) : ?><p class="neocrm-empty"><?php esc_html_e( 'No activity yet.', 'neo-crm' ); ?></p><?php endif; ?><?php foreach ( $notes as $note ) : ?><article class="neocrm-event"><span class="dashicons dashicons-edit"></span><div><strong><?php esc_html_e( 'Note', 'neo-crm' ); ?></strong><p><?php echo nl2br( esc_html( $note->body ) ); ?></p><small><?php echo esc_html( $note->display_name ?: __( 'Team member', 'neo-crm' ) ); ?></small></div><time><?php echo esc_html( self::relative_time( $note->created_at ) ); ?></time></article><?php endforeach; ?><?php foreach ( $activities as $activity ) : ?><article class="neocrm-event"><span class="dashicons dashicons-marker"></span><div><strong><?php echo esc_html( $activity->subject ); ?></strong></div><time><?php echo esc_html( self::relative_time( $activity->created_at ) ); ?></time></article><?php endforeach; ?></div></section></main><aside><section class="neocrm-panel"><h2><?php esc_html_e( 'Add note', 'neo-crm' ); ?></h2><form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="note"><input type="hidden" name="contact_id" value="<?php echo absint( $contact->id ); ?>"><?php wp_nonce_field( 'neocrm_save_note' ); ?><textarea name="body" rows="5" required></textarea><button class="button button-primary"><?php esc_html_e( 'Save note', 'neo-crm' ); ?></button></form></section><section class="neocrm-panel"><h2><?php esc_html_e( 'Create follow-up', 'neo-crm' ); ?></h2><form class="neocrm-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="neocrm_save_record"><input type="hidden" name="record_type" value="task"><input type="hidden" name="contact_id" value="<?php echo absint( $contact->id ); ?>"><input type="hidden" name="return_to" value="contact"><?php wp_nonce_field( 'neocrm_save_task' ); ?><label><?php esc_html_e( 'Task', 'neo-crm' ); ?><input name="title" required></label><label><?php esc_html_e( 'Due', 'neo-crm' ); ?><input type="datetime-local" name="due_at"></label><input type="hidden" name="priority" value="normal"><button class="button"><?php esc_html_e( 'Add task', 'neo-crm' ); ?></button></form></section><section class="neocrm-panel"><h2><?php esc_html_e( 'Deals', 'neo-crm' ); ?></h2><?php if ( ! $deals ) : ?><p><?php esc_html_e( 'No linked deals.', 'neo-crm' ); ?></p><?php endif; ?><?php foreach ( $deals as $deal ) : ?><div class="neocrm-related-record"><strong><?php echo esc_html( $deal->name ); ?></strong><span><?php echo esc_html( $deal->stage_name . ' · ' . $deal->currency . ' ' . number_format_i18n( $deal->amount, 2 ) ); ?></span></div><?php endforeach; ?></section></aside></div></div>
		<?php
	}

	public function save_record() {
		$this->guard_manage();
		global $wpdb;
		$type = sanitize_key( wp_unslash( $_POST['record_type'] ?? '' ) );
		check_admin_referer( 'neocrm_save_' . $type );
		$now = NeoCRM_DB::now();
		if ( 'lead' === $type ) {
			$id = absint( $_POST['record_id'] ?? 0 );
			$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
			if ( $id && in_array( $status, array( 'new', 'working', 'qualified', 'disqualified' ), true ) ) {
				$wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'status' => $status, 'updated_at' => $now ), array( 'id' => $id ) );
				NeoCRM_Funnel::sync_lead_status( $id, $status );
			}
			$this->redirect( 'neocrm-leads', array( 'lead_id' => $id ) );
		}
		if ( 'contact' === $type ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$first_name = substr( sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ), 0, 100 );
			if ( $first_name && is_email( $email ) && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email=%s', $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->insert( NeoCRM_DB::table( 'contacts' ), array( 'first_name' => $first_name, 'last_name' => substr( sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ), 0, 100 ), 'email' => $email, 'phone' => substr( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), 0, 50 ), 'company' => substr( sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ), 0, 190 ), 'status' => 'new', 'source' => 'manual', 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			}
			$this->redirect( 'neocrm-contacts' );
		}
		if ( 'company' === $type ) {
			$name = substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 190 );
			if ( $name ) {
				$wpdb->insert( NeoCRM_DB::table( 'companies' ), array( 'name' => $name, 'domain' => substr( sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ), 0, 190 ), 'phone' => substr( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ), 0, 50 ), 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			}
			$this->redirect( 'neocrm-companies' );
		}
		if ( 'deal' === $type ) {
			$pipeline_id = absint( $_POST['pipeline_id'] ?? 0 );
			$stage_id = absint( $_POST['stage_id'] ?? 0 );
			$stage = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' WHERE id=%d AND pipeline_id=%d', $stage_id, $pipeline_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$name = substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 190 );
			$currency = sanitize_key( wp_unslash( $_POST['currency'] ?? 'aed' ) );
			$currency = in_array( strtoupper( $currency ), array( 'AED', 'USD', 'EUR', 'GBP' ), true ) ? strtoupper( $currency ) : 'AED';
			if ( $stage && $name ) {
				$wpdb->insert( NeoCRM_DB::table( 'deals' ), array( 'pipeline_id' => $pipeline_id, 'stage_id' => $stage_id, 'contact_id' => absint( $_POST['contact_id'] ?? 0 ) ?: null, 'company_id' => absint( $_POST['company_id'] ?? 0 ) ?: null, 'name' => $name, 'amount' => max( 0, (float) ( $_POST['amount'] ?? 0 ) ), 'currency' => $currency, 'probability' => absint( $stage->probability ), 'status' => $stage->is_closed ? ( $stage->is_won ? 'won' : 'lost' ) : 'open', 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			}
			$this->redirect( 'neocrm-deals' );
		}
		if ( 'task' === $type ) {
			$title = substr( sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), 0, 190 );
			$priority = sanitize_key( wp_unslash( $_POST['priority'] ?? 'normal' ) );
			$priority = in_array( $priority, array( 'low', 'normal', 'high' ), true ) ? $priority : 'normal';
			$due_input = sanitize_text_field( wp_unslash( $_POST['due_at'] ?? '' ) );
			$due_at = $due_input ? get_gmt_from_date( str_replace( 'T', ' ', $due_input ) ) : null;
			if ( $title ) {
				$wpdb->insert( NeoCRM_DB::table( 'tasks' ), array( 'title' => $title, 'status' => 'open', 'priority' => $priority, 'due_at' => $due_at, 'contact_id' => absint( $_POST['contact_id'] ?? 0 ) ?: null, 'deal_id' => absint( $_POST['deal_id'] ?? 0 ) ?: null, 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
			}
			if ( 'contact' === sanitize_key( wp_unslash( $_POST['return_to'] ?? '' ) ) && ! empty( $_POST['contact_id'] ) ) {
				$this->redirect( 'neocrm-contacts', array( 'contact_id' => absint( $_POST['contact_id'] ) ) );
			}
			$this->redirect( 'neocrm-tasks' );
		}
		if ( 'note' === $type ) {
			$contact_id = absint( $_POST['contact_id'] ?? 0 );
			$body = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
			if ( $contact_id && $body ) {
				$wpdb->insert( NeoCRM_DB::table( 'notes' ), array( 'contact_id' => $contact_id, 'body' => $body, 'author_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
				NeoCRM_DB::log_activity( 'note_added', __( 'Note added', 'neo-crm' ), $contact_id );
			}
			$this->redirect( 'neocrm-contacts', array( 'contact_id' => $contact_id ) );
		}
		wp_die( esc_html__( 'Unsupported CRM record type.', 'neo-crm' ) );
	}

	public function convert_lead() {
		$this->guard_manage();
		global $wpdb;
		$lead_id = absint( $_POST['lead_id'] ?? 0 );
		check_admin_referer( 'neocrm_convert_lead_' . $lead_id );
		$lead = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'leads' ) . ' WHERE id=%d', $lead_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $lead || 'qualified' !== $lead->status ) { $this->redirect( 'neocrm-leads', array( 'lead_id' => $lead_id ) ); }
		$now = NeoCRM_DB::now();
		$wpdb->query( 'START TRANSACTION' );
		$company_id = null;
		if ( $lead->company ) {
			$company_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'companies' ) . ' WHERE name=%s', $lead->company ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $company_id ) { $wpdb->insert( NeoCRM_DB::table( 'companies' ), array( 'name' => $lead->company, 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) ); $company_id = (int) $wpdb->insert_id; }
		}
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NeoCRM_DB::table( 'contacts' ) . ' WHERE email=%s', $lead->email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $contact_id ) {
			$wpdb->insert( NeoCRM_DB::table( 'contacts' ), array( 'company_id' => $company_id, 'first_name' => $lead->first_name, 'last_name' => $lead->last_name, 'email' => $lead->email, 'phone' => $lead->phone, 'company' => $lead->company, 'status' => 'qualified', 'source' => $lead->source, 'owner_user_id' => get_current_user_id(), 'marketing_consent' => 0, 'created_at' => $now, 'updated_at' => $now ) );
			$contact_id = (int) $wpdb->insert_id;
		}
		if ( $company_id ) {
			$wpdb->update( NeoCRM_DB::table( 'contacts' ), array( 'company_id' => $company_id, 'company' => $lead->company, 'updated_at' => $now ), array( 'id' => $contact_id ) );
		}
		if ( ! $contact_id ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The lead could not be converted. No CRM records were changed.', 'neo-crm' ) );
		}
		$deal_id = null;
		if ( ! empty( $_POST['create_deal'] ) ) {
			$pipeline = $wpdb->get_row( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipelines' ) . ' ORDER BY is_default DESC, id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$stage = $pipeline ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' WHERE pipeline_id=%d AND is_closed=0 ORDER BY position LIMIT 1', $pipeline->id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $pipeline && $stage ) {
				$deal_name = substr( sanitize_text_field( wp_unslash( $_POST['deal_name'] ?? '' ) ), 0, 190 );
				$wpdb->insert( NeoCRM_DB::table( 'deals' ), array( 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'contact_id' => $contact_id, 'company_id' => $company_id, 'name' => $deal_name ?: $lead->first_name . ' opportunity', 'amount' => max( 0, (float) ( $_POST['amount'] ?? 0 ) ), 'currency' => 'AED', 'probability' => $stage->probability, 'status' => 'open', 'owner_user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ) );
				$deal_id = (int) $wpdb->insert_id;
				if ( ! $deal_id ) {
					$wpdb->query( 'ROLLBACK' );
					wp_die( esc_html__( 'The opportunity could not be created. No CRM records were changed.', 'neo-crm' ) );
				}
			}
		}
		$updated = $wpdb->update( NeoCRM_DB::table( 'leads' ), array( 'status' => 'converted', 'converted_contact_id' => $contact_id, 'converted_company_id' => $company_id, 'converted_deal_id' => $deal_id, 'converted_at' => $now, 'updated_at' => $now ), array( 'id' => $lead_id ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			wp_die( esc_html__( 'The lead could not be finalized. No CRM records were changed.', 'neo-crm' ) );
		}
		if ( $lead->visitor_id ) { $wpdb->update( NeoCRM_DB::table( 'visitors' ), array( 'contact_id' => $contact_id ), array( 'id' => $lead->visitor_id ) ); }
		$wpdb->update( NeoCRM_DB::table( 'activities' ), array( 'contact_id' => $contact_id ), array( 'lead_id' => $lead_id ) );
		NeoCRM_Funnel::attach_conversion( $lead_id, $contact_id, $company_id, $deal_id );
		NeoCRM_DB::log_activity( 'lead_converted', __( 'Lead converted to contact', 'neo-crm' ), $contact_id, array( 'lead_id' => $lead_id, 'deal_id' => $deal_id ) );
		$wpdb->query( 'COMMIT' );
		$this->redirect( 'neocrm-contacts', array( 'contact_id' => $contact_id ) );
	}

	public function move_deal() {
		$this->guard_manage();
		global $wpdb;
		$deal_id = absint( $_POST['deal_id'] ?? 0 );
		check_admin_referer( 'neocrm_move_deal_' . $deal_id );
		$deal = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'deals' ) . ' WHERE id=%d', $deal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$stage_id = absint( $_POST['stage_id'] ?? 0 );
		$stage = $deal ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'pipeline_stages' ) . ' WHERE id=%d AND pipeline_id=%d', $stage_id, $deal->pipeline_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $deal && $stage ) {
			$status = $stage->is_closed ? ( $stage->is_won ? 'won' : 'lost' ) : 'open';
			$wpdb->update( NeoCRM_DB::table( 'deals' ), array( 'stage_id' => $stage->id, 'probability' => $stage->probability, 'status' => $status, 'updated_at' => NeoCRM_DB::now() ), array( 'id' => $deal_id ) );
			NeoCRM_Funnel::sync_deal_stage( $deal_id, $stage->name, $status );
			NeoCRM_DB::log_activity( 'deal_stage_changed', sprintf( __( 'Deal moved to %s', 'neo-crm' ), $stage->name ), $deal->contact_id, array( 'deal_id' => $deal_id, 'stage_id' => $stage_id ) );
		}
		$this->redirect( 'neocrm-deals' );
	}

	public function complete_task() {
		$this->guard_manage();
		global $wpdb;
		$task_id = absint( $_POST['task_id'] ?? 0 );
		check_admin_referer( 'neocrm_complete_task_' . $task_id );
		$task = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NeoCRM_DB::table( 'tasks' ) . ' WHERE id=%d', $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $task && 'open' === $task->status ) {
			$now = NeoCRM_DB::now();
			$wpdb->update( NeoCRM_DB::table( 'tasks' ), array( 'status' => 'completed', 'completed_at' => $now, 'updated_at' => $now ), array( 'id' => $task_id ) );
			NeoCRM_DB::log_activity( 'task_completed', sprintf( __( 'Task completed: %s', 'neo-crm' ), $task->title ), $task->contact_id, array( 'task_id' => $task_id ) );
		}
		$this->redirect( 'neocrm-tasks' );
	}

	private static function lead_statuses() { return array( 'new' => __( 'New', 'neo-crm' ), 'working' => __( 'Working', 'neo-crm' ), 'qualified' => __( 'Qualified', 'neo-crm' ), 'disqualified' => __( 'Disqualified', 'neo-crm' ), 'converted' => __( 'Converted', 'neo-crm' ) ); }
	private static function guard() { if ( ! current_user_can( 'view_neocrm' ) ) { wp_die( esc_html__( 'You do not have permission to view NeoCRM.', 'neo-crm' ) ); } }
	private function guard_manage() { if ( ! current_user_can( 'manage_neocrm' ) ) { wp_die( esc_html__( 'You do not have permission to change CRM records.', 'neo-crm' ) ); } }
	private function redirect( $page, $args = array() ) { wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) ); exit; }
	private static function open_page( $title, $subtitle ) { ?><div class="wrap neocrm-wrap"><?php self::header( $title, $subtitle ); ?><?php }
	private static function header( $title, $subtitle ) { ?><header class="neocrm-header"><div><span class="neocrm-brand">NEOCRM</span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $subtitle ); ?></p></div><span class="neocrm-version">v<?php echo esc_html( NEOCRM_VERSION ); ?></span></header><?php }
	private static function relative_time( $date ) { $timestamp = strtotime( $date . ' UTC' ); return $timestamp ? sprintf( __( '%s ago', 'neo-crm' ), human_time_diff( $timestamp, time() ) ) : '—'; }
}
