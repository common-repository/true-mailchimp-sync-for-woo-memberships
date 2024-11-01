<?php
class Misha_Mailchimp_Options extends Misha_Mailchimp_API {

	function __construct() {

		parent::__construct();

		add_action( 'admin_menu', array( $this, 'options_add' ) );
		add_action( 'admin_menu', array( $this, 'save' ) ); // yes, yes, I know
		add_action( 'misha_cron_mailchimp_resync_hook', array( $this, 'do_resync' ) );
		add_action( 'admin_post_misha_start_resync', array( $this, 'resync_start' ) );
		add_action( 'admin_post_misha_cancel_resync', array( $this, 'resync_stop' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'admin_head', array( $this, 'css' ) );
		// wc memberships
		add_filter( 'wc_membership_plan_data_tabs', array( $this, 'plantabs' ) );
		add_action( 'wc_membership_plan_data_panels', array( $this, 'plantabs_content' ) );
		add_action( 'save_post', array( $this, 'plantabs_save' ), 10, 2 );
		// link to settings
		add_filter( 'plugin_action_links_true-mailchimp-sync-for-woo-memberships/true-mailchimp-sync-for-woo-memberships.php', array( $this, 'link_to_settings' ) );

	}

	/*
	 * Admin JS
	 */
	function scripts() {
		wp_enqueue_script(
			'mishamch',
			plugin_dir_url( __FILE__ ) . 'script.js',
			array( 'jquery' ),
			filemtime( dirname( __FILE__ ) . '/script.js' )
		);
	}

	/**
	 * CSS
	 */
	function css() {
		?><style>
		.misha_mch_rules_table{
			max-width: 800px;
		}
		.misha_mch_rules_table tbody tr td:first-child, .misha_mch_rules_table tbody tr td:first-child +td{
			vertical-align: top;
		}
		.misha_mch_rules_table tbody tr td:first-child +td{
			line-height: 30px;
		}
		.misha_mch_rules_table tbody .misha_mch_checkbox{
			margin:7px 2px;
		}
		.misha_mch_rules_table .misha_mch_remove_rule{
			float:right;
		}
		.mchhiddenfields {
			display: block;
			padding: 5px 0 0 5px;
			clear: both;
			font-size:12px;
		}
		.mchinterests {
			display: inline-block;
			line-height: 26px;
			font-size: 14px;
		}
		.misha_mch_debug_log .logentry.alt{
			background-color:#eee
		}
		.misha_mch_debug_log .logentry{
			border:1px solid #ccc
		}
		.misha_mch_list_select {
			max-width: 350px;
		}
		.woocommerce_options_panel .misha_mch_list_select {
			max-width: 300px;
		}
		</style>
		<?php
	}

	/**
	 * Reset lists settings once API key is changed
	 */
	function reset_settings() {

		// these settings are going to be removed in any ways once api key is changed
		// delete_option( '_misha_mailchimp_roles' );

		if ( $this->is_memberships() ) {

			$membership_plans = get_posts(
				array(
					'post_type' => 'wc_membership_plan',
					'posts_per_page' => -1,
					'fields' => 'ids',
				)
			);

			if( $membership_plans ) {
				foreach ( $membership_plans as $plan_id ) {
					delete_post_meta( $plan_id, '_misha_mailchimp_plan_statuses' );
				}
			}

		}

		delete_transient( 'mishmch_lists1' ); // clear list caches
	}

	/**
	 * Simplify notices
	 */
	function notice( $text, $type = 'warning', $echo = true ) {
		$notice = '<div class="notice notice-' . $type . '"><p>' . $text . ' </p></div>';
		echo $notice;
	}
	/*
	 * Link to plugin settings from the Plugins page
	 */
	function link_to_settings( $links ) {
		return array_merge( array( '<a href="' . add_query_arg( 'page', $this->page, admin_url( 'options-general.php' ) ) . '">' . __( 'Settings' ) . '</a>' ), $links );
	}

	/**
	 * Just add new options page here
	 * We use default WordPress add_options_page()
	 */
	function options_add() {

		add_options_page(
			__( 'MailChimp Sync for WooCommerce Memberships Settings', 'true-mailchimp-sync-for-woo-memberships' ),
			__( 'MailChimp Sync', 'true-mailchimp-sync-for-woo-memberships' ),
			'manage_options',
			$this->page,
			array( $this, 'options_fields' )
		);

	}

	function options_fields() {
		?>
		<div class="wrap">
			<h1>
				<?php
					_e( 'MailChimp Sync for WooCommerce Memberships Settings', 'true-mailchimp-sync-for-woo-memberships' )
				?>
			</h1>
		<?php

		$lists = $this->lists();

		$resync_in_process = get_option( '_resync_in_process' );

		if ( is_wp_error( $lists ) ) {

			$this->notice( $lists->get_error_message(), 'error' );

		}
		/**
		 * Notices
		 */
		if ( isset( $_GET['saved'] ) ) {
			switch ( $_GET['saved'] ) :
				case 'roles':{
					$this->notice( sprintf( __('<strong>Settings saved.</strong><br /> If you have been already using my plugin for a while, please make full resync on <a href="%s">this page</a>. It is required every time you change settings here!', 'true-mailchimp-sync-for-woo-memberships' ), add_query_arg( 'page', $this->page, admin_url( 'options-general.php' ) ) ), 'warning');
					break;
				}
				case 'resync_started':{
					$this->notice( __('<span class="dashicons dashicons-clock"></span>&nbsp;&nbsp;Full resync with Mailchimp lists has been started. It will be performed as a background process, so you can safely leave or refresh this page.', 'true-mailchimp-sync-for-woo-memberships' ), 'warning');
					break;
				}
				case 'resync_stopped':{
					$this->notice( __('Resync has been cancelled.', 'true-mailchimp-sync-for-woo-memberships' ), 'success');
					break;
				}
				default : {
					$this->notice( '<strong>' . __('Settings saved.') . '</strong>', 'success');
					break;
				}
			endswitch;
		}

		// resync in process message
		if ( $resync_in_process && ( empty( $_GET['saved'] ) || isset( $_GET['saved'] ) && 'resync_started' !== $_GET['saved'] ) ) {
			$total = count_users(); // $total['total_users']
			$offset = ( $offset = get_option('_misha_mailchimp_resync_users_offset') ) ? $offset : 1;
			$percentage = round( $offset / $total['total_users'] * 100, 1 );
			if ( $percentage > 99 ) $percentage = 99;
			$this->notice( sprintf( __('<span class="dashicons dashicons-clock"></span>&nbsp;&nbsp;Resync is already in process. Please wait until it will be finished. %1$s&#37;... <a href="%2$s" class="misha_mch_stop_resync">Cancel</a>', 'true-mailchimp-sync-for-woo-memberships' ), $percentage, add_query_arg( array( 'action' => 'misha_cancel_resync', '_wpnonce' => wp_create_nonce( 'resync_' . get_current_user_id() ) ), admin_url('admin-post.php') ) ), 'warning' );
		}


		?><form method="post" action=""><?php wp_nonce_field( 'update' . get_current_user_id(), '_mch_settings_nonce' ); ?>
		<table class="form-table">
			<tbody>
				<?php
				/**
				 * API key
				 */
				?>
				<tr>
					<th scope="row">
						<label for="api_key"><?php _e('API Key', 'true-mailchimp-sync-for-woo-memberships' ) ?></label>
					</th>
					<td>
						<input class="regular-text" spellcheck="false" autocomplete="off" type="text" id="api_key" name="mishmch_api_key" value="<?php echo esc_attr( $this->api_key ) ? esc_attr( substr_replace( $this->api_key, '***************', - ( strlen($this->api_key) - 10 ))) : ''; ?>" />
						<p class="description"><?php echo sprintf( __("The API key is required to connect with MailChimp. <a target=\"_blank\" href=\"%s\">Get your API key here.</a>", 'true-mailchimp-sync-for-woo-memberships'), 'https://admin.mailchimp.com/account/api' ); ?></p>
					</td>
				</tr>
				<?php if ( $this->api_key && !is_wp_error( $lists ) ) : ?>
					<?php
					/*
					 * Double Opt In
					 */
					$optin = get_option( '_mishmch_2optin' ) ? 'yes' : 'no';
					?>
					<tr>
						<th scope="row">
							<label><?php _e('Double Opt In', 'true-mailchimp-sync-for-woo-memberships' ) ?></label>
						</th>
						<td>
							<p class="description"><?php _e( 'Should your website users receive a confirmation email when they first time subscribe to every list?', 'true-mailchimp-sync-for-woo-memberships' ) ?></p>
							<fieldset>
								<label style="margin:10px 20px 10px 0 !important"><input type="radio" name="mishmch_2optin" value="no" <?php checked( $optin, 'no' ) ?>> No</label><label style="margin:10px 0px 10px 0 !important"><input type="radio" name="mishmch_2optin" <?php checked( $optin, 'yes' ) ?> value="yes"> Yes</label>
							</fieldset>
						</td>
					</tr>
					<?php
					/**
					 * Resync
					 */
					?>
					<tr>
						<th scope="row">
							<label><?php _e('Sync Users and Memberships', 'true-mailchimp-sync-for-woo-memberships' ) ?></label>
						</th>
						<td>
							<?php if ( $resync_in_process ) : ?><button class="button" disabled="disabled"><?php _e( 'Resync', 'true-mailchimp-sync-for-woo-memberships' ) ?></button><?php else : ?><a href="<?php echo wp_nonce_url( add_query_arg( array( 'action' => 'misha_start_resync' ), admin_url('admin-post.php') ), 'resync_start_now' ) ?>" class="button misha_mch_start_resync_button"><?php _e( 'Resync', 'true-mailchimp-sync-for-woo-memberships' ) ?></a><?php endif ?>
							<p class="description"><?php _e( 'Depending on the number of your website users and MailChimp API status this may take a considerable amount of time. The synchronization will be performed as a background process.', 'true-mailchimp-sync-for-woo-memberships' ) ?></p>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<p class="submit">
			<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
		</p>
		</form>
	</div><?php

	}

	/**
	 * Save settings method
	 */
	function save() {

		if ( empty( $_GET['page'] ) || $_GET['page'] !== $this->page ) // skip inappripriate pages
			return;

		if ( ! isset( $_POST['_mch_settings_nonce'] ) || ! wp_verify_nonce( $_POST['_mch_settings_nonce'], 'update' . get_current_user_id() ) )
			return;

		$api_updated = false;


		// API key
		if ( ! preg_match('/\*\*$/', $_POST['mishmch_api_key'] ) && $this->api_key !== $_POST['mishmch_api_key'] ) {

			update_option( '_mishmch_api_key', sanitize_text_field( $_POST['mishmch_api_key'] ) );
			$this->reset_settings(); // reset settings if changed
			$api_updated = true;

		}

		// Double Opt In
		if ( ! empty( $_POST['mishmch_2optin'] ) && 'yes' == $_POST['mishmch_2optin'] ) {
			update_option( '_mishmch_2optin', 1 );
			$this->webhooks_create(); // ok, let's create MailChimp webhook in that case
		} else {
			delete_option( '_mishmch_2optin' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => $this->page,
					'saved' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);

		exit;

	}


	/*
	 * WC Memberships tabs
	 */
	function plantabs( $tabs ) {

		$tabs['truemailchimp'] = array(
			'label'  => __( 'MailChimp', 'true-mailchimp-sync-for-woo-memberships' ),
			'target' => 'truemailchimp',
		);
		return $tabs;

	}

 	/**
 	 * WC Memberships tab content
 	 */
 	function plantabs_content() {
 		global $post;

		$lists = $this->lists();
		$statuses = wc_memberships_get_user_membership_statuses(); // prefixes are not stripped!!
		$statuses_from_postmeta = get_post_meta( $post->ID, '_misha_mailchimp_plan_statuses', true );
		$statuses_from_postmeta_keys = is_array( $statuses_from_postmeta ) ? array_keys( $statuses_from_postmeta ) : array();

		?><div id="truemailchimp" class="panel woocommerce_options_panel"><?php

 		if ( ! is_wp_error( $lists ) ) :

			wp_nonce_field( basename( __FILE__ ), 'plan_tab' );
			?>
				<div class="table-wrap">
					<div class="options_group">
						<script>
						var dropdown_lists = '<option value=""><?php _e('Select List ...', 'true-mailchimp-sync-for-woo-memberships' ) ?></option><?php foreach ( $lists as $list ) echo '<option value="' . esc_attr( $list->id ) . '">' . esc_js($list->name) . '</option>'; ?>';
						</script>
						<table class="widefat misha_mch_rules_table">
							<thead>
								<tr>
									<td class="check-column" style="width: 5%;"><input type="checkbox" class="misha_mch_select_all misha_mch_checkbox"></td>
									<td style="width: 35%;"><?php _e('Membership status', 'true-mailchimp-sync-for-woo-memberships' ) ?></td>
									<td style="width: 60%;"><?php _e('List', 'true-mailchimp-sync-for-woo-memberships' ) ?></td>
								</tr>
							</thead>
							<tbody>
								<?php

								// debug
								// $userdata = get_user_by( 'id', 34 );
								// $this->add_rules( $this->generate_rules( $userdata ) );
								// $scheduled_rules = get_option( '_misha_mailchimp_scheduled_rules' );
								// echo '<pre>';print_r( $scheduled_rules ); echo '</pre>';

								if ( $statuses && $statuses_from_postmeta ) : ?>
									<tr class="misha_mch_no_rules" style="display:none"><td colspan="3"><?php _e('Please select WooCommerce Membership Plan statuses you want to sync with MailChimp lists.', 'true-mailchimp-sync-for-woo-memberships' ) ?></td></td>
									<?php foreach ( $statuses as $status => $status_info):

										if ( empty( $statuses_from_postmeta[$status]['list_id'] ) ) continue; // if not set, skip

										?><tr data-for-role="<?php echo $status ?>">
											<td><input type="checkbox" class="misha_mch_checkbox"></td>
											<td><?php echo esc_html( $status_info['label'] ) ?></td>
											<td>
												<select id="misha_mch_select_<?php echo esc_attr( $status ) ?>" class="misha_mch_list_select" name="misha_mch_list_for_[<?php echo esc_attr( $status ) ?>]"><option value=""><?php _e('Select List ...', 'true-mailchimp-sync-for-woo-memberships' ) ?></option>
												<?php
													foreach ( $lists as $list ):
														?><option value="<?php echo esc_attr( $list->id ) ?>" <?php selected( $list->id, $statuses_from_postmeta[$status]['list_id'] ) ?>><?php echo esc_html( $list->name ) ?></option><?php
													endforeach;
												?>
												</select>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else: ?>
									<tr class="misha_mch_no_rules"><td colspan="3"><?php _e('Please select WooCommerce Membership Plan statuses you want to sync with MailChimp lists.', 'true-mailchimp-sync-for-woo-memberships' ) ?></td></td>
								<?php endif; ?>

							</tbody>
							<tfoot>
								<tr>
									<td colspan="3">
										<select class="misha_mch_roles_dd" style="margin-right:5px"><option value=""><?php _e('Select status ...', 'true-mailchimp-sync-for-woo-memberships' ) ?></option><?php
											foreach ( $statuses as $status => $status_info ) {
												$disabled = ( in_array( $status, $statuses_from_postmeta_keys ) && !empty( $statuses_from_postmeta[$status]['list_id'] ) ) ? ' class="misha_mailchimp_closed_option" disabled="disabled"' : '';
												echo '<option value="' . esc_attr( $status ) . '" data-role-label="' . esc_attr( $status_info['label'] ) . '"' . $disabled . '>' . esc_html( $status_info['label'] ) . '</option>';
											}
										?></select>
										<button type="button" class="button button-primary misha_mch_add_rule" disabled><?php _e('Add New Rule', 'true-mailchimp-sync-for-woo-memberships' ) ?></button>
										<button type="button" class="button button-secondary misha_mch_remove_rule" disabled><?php _e('Delete Selected', 'true-mailchimp-sync-for-woo-memberships' ) ?></button>
									</td>
								</tr>
							</tfoot>
						</table>
					</div><!-- .options_group -->
				</div><!-- .table-wrap -->
			<?php
		else :

			echo '<p>' . sprintf( __( 'Please check <a href="%s">the plugin settings</a>. It looks like your API key is incorrect.', 'true-mailchimp-sync-for-woo-memberships' ), add_query_arg('page', $this->page, admin_url( 'options-general.php' ) ) ) . '</p>';

		endif;
		?></div><!-- #truemailchimp --><?php

 	}



	/*
	 * Save plan statuses settings
	 */

	function plantabs_save( $post_id, $post ) {

 		if ( !isset( $_POST['plan_tab'] ) || !wp_verify_nonce( $_POST['plan_tab'], basename( __FILE__ ) ) ) return $post_id;
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return $post_id;
		if ( !current_user_can( 'edit_post', $post_id ) ) return $post_id;
		if ( $post->post_type !== 'wc_membership_plan') return $post_id;


		$statuses_array_to_save = array(); // the array is similar to roles saving

 		if ( !empty( $_POST['misha_mch_list_for_'] ) && is_array( $_POST['misha_mch_list_for_'] ) ) {

			foreach ( $_POST['misha_mch_list_for_'] as $status => $list_id) {

				if ( empty( $list_id ) )
					continue;

 				$statuses_array_to_save[$status]['list_id'] = sanitize_text_field( $list_id );

 			}

 		}
		//echo '<pre>';print_r($statuses_array_to_save);exit;
		update_post_meta( $post_id, '_misha_mailchimp_plan_statuses', $statuses_array_to_save );

		// create MailChimp webhooks if Double Opt In is turned on
		if ( 1 == get_option('_mishmch_2optin') ) {
			$this->webhooks_create();
		}

 		return $post_id;

 	}



	/**
	 * Schedule our mass resync task
	 */

	function resync_start() {

		if ( !isset( $_REQUEST['_wpnonce'] ) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'resync_start_now' ) ) {
			 wp_die( 'You are not allowed to do that.' );
		}

		if ( !wp_next_scheduled('misha_cron_mailchimp_resync_hook') ) {
			wp_schedule_event( time(), 'truemch_5min', 'misha_cron_mailchimp_resync_hook');
		}

		update_option('_resync_in_process', 1);

		wp_safe_redirect( add_query_arg( array(
			'page' => $this->page,
			'saved' => 'resync_started'
		), 'options-general.php' ));

		exit;

	}



	/**
	 * Go resync, keep in mind that MailChimp supports no more than 500 batches
	 */

	function do_resync() {

		$offset = ( $offset = get_option('_misha_mailchimp_resync_users_offset') ) ? $offset : 0; // start with
		$rules = array();

		$users_to_sync = get_users( array(
			'offset' => $offset,
			'number' => 200, // actually, it is not certain, that we loop through all 200, depends on amount of rules per user
			'orderby' => 'registered',
			'order' => 'ASC'
		) );

		if ( $users_to_sync ) {

			foreach ( $users_to_sync as $userdata ) {

				$rules = array_merge( $rules, $this->generate_rules( $userdata ) );
				$offset++;
				if ( count( $rules ) > 50 ) break; // not much at all, but let's keep it simple, because I want to send a request more often
			}

			update_option( '_misha_mailchimp_resync_users_offset', $offset );

			$this->do_subscribe( $rules );

		} else {
			wp_clear_scheduled_hook( 'misha_cron_mailchimp_resync_hook' );
			delete_option('_resync_in_process');
			delete_option('_misha_mailchimp_resync_users_offset');

		}

	}



	/**
	 * In case we would like to stop the resync manually
	 */
	function resync_stop() {

		if ( !isset( $_REQUEST['_wpnonce'] ) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'resync_' . get_current_user_id() ) ) {
			 wp_die( 'You are not allowed to do that.' );
		}

		wp_clear_scheduled_hook( 'misha_cron_mailchimp_resync_hook' );
		delete_option( '_resync_in_process' );
		delete_option( '_misha_mailchimp_resync_users_offset' );

		wp_safe_redirect( add_query_arg( array(
			'page' => $this->page,
			'saved' => 'resync_stopped'
		), 'options-general.php' ));

		exit;

	}


}

new Misha_Mailchimp_Options();
