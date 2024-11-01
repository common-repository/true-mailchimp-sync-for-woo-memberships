<?php
/*
 * Main class
 * by Misha Rudrastyh
 * all MailChimp API methods and interactions are here
 */
class Misha_Mailchimp_API {

	public $api_key = '';
	public $page = 'mailchimpbymisha';
	public $log_option_key = '_misha_mch_log4';
	public $scheduled_hook = 'misha_mailchimp_hook';


	function __construct() {

		$api_key = get_option( '_mishmch_api_key' );

		if ( ! empty( $api_key ) ) {
			$this->api_key = $api_key;
		} else {
			add_action( 'admin_notices', array( $this, 'notice__no_api' ) );
		}

		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'template_redirect', array( $this, 'maybe_parse_webhook_data' ) );

	}



	/**
	 * plugins_loaded
	 */
	function init() {

		add_action( 'wc_memberships_grant_membership_access_from_purchase', array( $this, 'membership_grant_access' ), 20, 2 );
		add_action( 'post_updated', array( $this, 'membership_created_manually' ), 20, 3 );
		add_action( 'wc_memberships_user_membership_status_changed', array( $this, 'membership_status_changed' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'membership_delete' ), 5 );

		load_plugin_textdomain( 'true-mailchimp-sync-for-woo-memberships', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		// cron
		add_action( $this->scheduled_hook, array( $this, 'do_work' ) );
		add_filter( 'cron_schedules', array( $this, 'interval' ) );

	}



	/**
	 * Check if WooCommerce Memberships is installed on the website
	 */

	function is_memberships() {
		if ( function_exists( 'wc_memberships' ) ) { // I think it is better than is_plugin_active()
			return true;
		} else {
			return false;
		}
	}



	/**
	 * This notice will be displayed on every admin page except MailChimp API settings
	 */

	function notice__no_api() {

		if ( isset( $_GET['page'] ) && $_GET['page'] == $this->page ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . sprintf( __( 'Mailchimp API key is required.<br />Please, <a href="%s">continue to settings</a> and configure your API key, it will take just 2 minutes of your time.', 'true-mailchimp-sync-for-woo-memberships' ), add_query_arg( 'page', $this->page, admin_url( 'options-general.php' ) ) ) . ' </p></div>';

	}



	/**
	 * Possibility to hook merge fields
	 */

	function merge_fields( $userdata ) {

		return apply_filters(
			'misha_mailchimp_merge_fields',
			array(
				'FNAME' => ( ! empty( $userdata->first_name ) ? $userdata->first_name : '' ),
				'LNAME' => ( ! empty( $userdata->last_name ) ? $userdata->last_name : '' ),
			),
			$userdata
		);

	}



	/*
	 * Get MailChimp lists with cache
	 */
	function lists() {

		if ( ! $this->api_key ) {
			return;
		}

		$lists = get_transient( 'mishmch_lists1' );
		if ( false == $lists ) {

			$response = wp_remote_get(
				'https://' . substr( $this->api_key, strpos( $this->api_key, '-' ) + 1 ) . '.api.mailchimp.com/3.0/lists/?offset=0&count=100',
				array(
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
					),
				)
			);

			if ( is_wp_error( $response ) ) { // it happens, when API key is incorrect (without -usX), maybe curl problems
				return new WP_Error( 'connection', __( 'Please check that you\'re using a correct API key. If you are 100% sure that it is correct, maybe it is something wrong with your server, please contact your hosting support.', 'true-mailchimp-sync-for-woo-memberships' ) );
			}

			if ( wp_remote_retrieve_response_code( $response ) !== 200 ) { // incorrect API key
				return new WP_Error( 'apikey', __( 'Oops, I\'m trying to connect to MailChimp but it looks like your API key is not correct. Please, double check it.', 'true-mailchimp-sync-for-woo-memberships' ) );
			}

			$lists = wp_remote_retrieve_body( $response );

			set_transient( 'mishmch_lists1', $lists, 1800 ); // 30 min cache, I think it is enough

		}

		$body = json_decode( $lists );
		return $body->lists;

	}



	/**
	 * Double Opt In Stuff
	 */

	// check if localhost, there is no way to create MailChimp webhooks unless the plugin is used on locahost
	function is_localhost() {
		$whitelist = array( '127.0.0.1', '::1' );
		if ( in_array( $_SERVER['REMOTE_ADDR'], $whitelist ) ) {
			return true; // localhost
		}
		return false;
	}

	// create webhooks
	function webhooks_create_lt() {

		if ( $this->is_localhost() ) {
			return;
		}

		if ( ! $this->api_key ) {
			return;
		}

		$lists_with_webhooks = get_option( '_misha_mailchimp_lists_with_webhooks' ); // get an array of list IDs whick are alreade have webhooks
		if ( ! $lists_with_webhooks ) {
			$lists_with_webhooks = array();
		}

		$lists_without_webhooks = array();

		// memberships
		if ( $this->is_memberships() && ( $membership_plans = wc_memberships_get_membership_plans() ) ) {

			foreach ( $membership_plans as $membership_plan ) {

				if ( ! $rules_from_plan_statuses = get_post_meta( $membership_plan->get_id(), '_misha_mailchimp_plan_statuses', true ) ) {
					continue;
				}

				foreach ( $rules_from_plan_statuses as $status => $rule ) {

					if ( empty( $rule['list_id'] ) ) {
						continue;
					}
					if ( in_array( $rule['list_id'], $lists_with_webhooks ) ) {
						continue; // already created
					}
					if ( in_array( $rule['list_id'], $lists_without_webhooks ) ) {
						continue; // already added
					}

					$lists_without_webhooks[] = $rule['list_id'];

				}
			}
		}

		if ( empty( $lists_without_webhooks ) ) {
			return;
		}

		$dc = substr( $this->api_key, strpos( $this->api_key, '-' ) + 1 );

		// batch subscribe

		if ( count( $lists_without_webhooks ) > 1 ) {

			$args = new stdClass();
			$args->operations = array();

			foreach ( $lists_without_webhooks as $list_id ) {

				$batch = new stdClass();
				$batch->method = 'POST';
				$batch->path = '/lists/' . $list_id . '/webhooks';
				$batch->body = json_encode(
					array(
						'url' => add_query_arg( 'misha_mailchimp_process_webhook', 1, site_url() ),
						'events' => array( 'subscribe' ),
						'sources' => array( 'user', 'api', 'admin' ),
					)
				);

				$args->operations[] = $batch;

			}

			// batch
			$response = wp_remote_post(
				'https://' . $dc . '.api.mailchimp.com/3.0/batches',
				array(
					'method' => 'POST',
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
					),
					'body' => json_encode( $args ),
				)
			);

		} else { // single subscribe

			$list_id = $lists_without_webhooks[0];

			$response = wp_remote_post(
				'https://' . $dc . '.api.mailchimp.com/3.0/lists/' . $list_id . '/webhooks',
				array(
					'method' => 'POST',
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
					),
					'body' => json_encode(
						array(
							'url' => add_query_arg( 'misha_mailchimp_process_webhook', 1, site_url() ),
							'events' => array( 'subscribe' ),
							'sources' => array( 'user', 'api', 'admin' ),
						)
					),
				)
			);

		}

		// work is done, let's process error messages
		// WP_Error
		if ( is_wp_error( $response ) ) {

			$this->add_log( 'WP_Error', $response->get_error_message() );

		} else {

			// we got a response
			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( wp_remote_retrieve_response_message( $response ) == 'OK' ) {

				$this->add_log( $response, 'It seems like the webhooks have been created successfully.' );

			} else {
				$this->add_log( $response, $body->detail . ' <pre>' . print_r( $body->errors, true ) . '</pre>' );
			}
		}

		$lists_with_webhooks = array_merge( $lists_with_webhooks, $lists_without_webhooks );
		update_option( '_misha_mailchimp_lists_with_webhooks', $lists_with_webhooks );

	}

	// if we want to subscribe but user never confirmed his subscription via email conformation
	function maybe_double_opt_in( $status, $list_id, $user_id ) {
		if ( 1 == get_option( '_mishmch_2optin' ) && 'subscribed' == $status && 'yes' != get_user_meta( $user_id, 'list' . $list_id . 'approved', true ) ) {
				return 'pending';
		}
		return $status;
	}

	// do the stuff once received webhook data
	function maybe_parse_webhook_data() {

		if ( ! empty( $_GET['misha_mailchimp_process_webhook'] ) && 1 == $_GET['misha_mailchimp_process_webhook'] && isset( $_POST['type'] ) && 'subscribe' == $_POST['type'] && isset( $_POST['data'] ) && is_array( $_POST['data'] ) ) {

				$email = sanitize_email( $_POST['data']['email'] );
				$list_id = sanitize_text_field( $_POST['data']['list_id'] );

				$user = get_user_by( 'email', $email );
				update_user_meta( $user->ID, 'list' . $list_id . 'approved', 'yes' );
				$this->add_log( 'Webhook OK', $email . ' &mdash; subscribed &mdash; ' . $list_id );
				echo 'ok';
				die;

		}

	}




	/**
	 * Can subscribe anything
	 */

	function do_subscribe( $rules ) {

		if ( ! $this->api_key ) {
			return;
		}

		if ( empty( $rules ) ) {
			return;
		}

		$dc = substr( $this->api_key, strpos( $this->api_key, '-' ) + 1 );

		// batch subscribe

		if ( count( $rules ) > 1 ) {

			$args = new stdClass();
			$args->operations = array();
			$log = array();
			$is_batch = true;

			// Array(
			// 	Array(
			//		'list_id' =>
			//    'email' => ,
			//		'interests' => ,
			//		'status' => '',
			//	)
			// );
			foreach ( $rules as $rule ) {

				$batch = new stdClass();
				$batch->method = 'PUT';
				$batch->path = 'lists/' . $rule['list_id'] . '/members/' . md5( strtolower( $rule['email'] ) );

				$batch_args = array(
					'email_address' => $rule['email'],
					'status'        => $this->maybe_double_opt_in( $rule['status'], $rule['list_id'], $rule['id'] ),
					'merge_fields'  => $rule['merge_fields'],
				);

				if ( ! empty( $rule['interests'] ) ) {
					$batch_args['interests'] = $rule['interests'];
				}

				$batch->body = json_encode( $batch_args );

				$args->operations[] = $batch;

				$batch_log[] = $batch_args['email_address'] . ' &mdash; ' . $batch_args['status'] . ' &mdash; ' . $rule['list_id'];
			}

			// batch
			$response = wp_remote_post(
				'https://' . $dc . '.api.mailchimp.com/3.0/batches',
				array(
					'method' => 'POST',
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
					),
					'body' => json_encode( $args ),
				)
			);

		} else { // single subscribe

			$rule = $rules[0];
			$is_batch = false;

			$args = array(
				'email_address' => $rule['email'],
				'status'        => $this->maybe_double_opt_in( $rule['status'], $rule['list_id'], $rule['id'] ),
				'merge_fields'  => $rule['merge_fields'],
			);

			if ( ! empty( $rule['interests'] ) ) {
				$args['interests'] = $rule['interests'];
			}

			$response = wp_remote_post(
				'https://' . $dc . '.api.mailchimp.com/3.0/lists/' . $list_id . '/members/' . md5( strtolower( $rule['email'] ) ),
				array(
					'method' => 'PUT',
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( 'user:' . $this->api_key ),
					),
					'body' => json_encode( $args ),
				)
			);

		}

		// work is done, let's process error messages
		// WP_Error
		if ( is_wp_error( $response ) ) {

			$this->add_log( 'WP_Error', $response->get_error_message() );

		} else {

			// we got a response
			$body = json_decode( wp_remote_retrieve_body( $response ) );

			if ( wp_remote_retrieve_response_message( $response ) == 'OK' ) {
				if ( $is_batch ) {
					$this->add_log( $response, 'The batch ' . $body->id . ' for<pre>' . join( '<br>', $batch_log ) . '</pre>has been set successfully. <a href="#" data-batch-id="' . $body->id . '" class="misha_mch_check_batch">Check status</a>' );
				} else {
					$this->add_log( $response, $rule['email'] . ' &mdash; ' . $body->status . ' &mdash; ' . $rule['list_id'] );
				}
			} else {
				$this->add_log( $response, $body->detail . ' <pre>' . print_r( $body->errors, true ) . '</pre>' );
			}
		}

	}


	/**
	 * Scheduler
	 */

	function schedule() {

		if ( wp_next_scheduled( $this->scheduled_hook ) ) {
			return;
		}

		// 2 min later + every 10 min
		wp_schedule_event( time() + 30, 'truemch_5min', $this->scheduled_hook );

		$this->add_log( 'WP_Cron', 'Cron job started, the first run in 1 minute.' );

	}



	/**
	 * Schedule interval
	 */

	function interval( $intervals ) {

		$intervals['truemch_5min'] = array(
			'interval' => 300,
			'display' => 'Every 5 minutes (True MailChimp)',
		);
		return $intervals;

	}



	/**
	 * Unschedule once done
	 */

	function unschedule() {

		if ( $timestamp = wp_next_scheduled( $this->scheduled_hook ) ) {
			wp_unschedule_event( $timestamp, $this->scheduled_hook );
		}

		$this->add_log( 'WP_Cron', 'Cron job finished.' );

	}



	/**
	 * Scheduler work
	 */

	function do_work() {

		if ( $rules_to_sync = get_option( '_misha_mailchimp_scheduled_rules' ) ) {
			$this->add_log( 'Ready to connect', 'I am going to sync the following rules: <pre>' . print_r( $rules_to_sync, true ) . '</pre>' );
			$this->do_subscribe( $rules_to_sync );
			delete_option( '_misha_mailchimp_scheduled_rules' );
		}

		$this->unschedule(); // stop doing the work

	}




	/**
	 * Generate rules
	 */

	function generate_rules_lt( $userdata, $allow_subscribe = true, $skip_membership_id = false ) {

		// $allow_subscribe allows us to use this function for complete unsubscription

		$subscribed_lists = array();
		$rules = array();
		$user_id = $userdata->ID;
		$user_email = $userdata->user_email;
		$merge_fields = $this->merge_fields( $userdata );

		// memberships
		if ( $this->is_memberships() && ( $memberships = wc_memberships_get_user_memberships( $user_id ) ) ) {

			foreach ( $memberships as $membership ) {

				if ( ! empty( $skip_membership_id ) && $skip_membership_id == $membership->get_id() ) {
					continue;
				}

				if ( $rules_from_plan_statuses = get_post_meta( $membership->get_plan_id(), '_misha_mailchimp_plan_statuses', true ) ) {

					foreach ( $rules_from_plan_statuses as $status => $rule ) {

						if ( empty( $rule['list_id'] ) ) {
							continue;
						}

						$new_rule = array(
							'list_id' => $rule['list_id'],
							'status' => 'unsubscribed',
							'id' => $user_id,
							'email' => $user_email,
							'merge_fields' => $merge_fields,
						);

						if ( true == $allow_subscribe && 'wcm-' . $membership->get_status() == $status ) { // current user memberships status

							$new_rule['status'] = 'subscribed';

							if ( ! empty( $rule['interests'] ) ) {
								$new_rule['interests'] = $rule['interests'];
							} elseif ( $interests_default = get_option( '_nointerests_for_' . $rule['list_id'] ) ) {
								$new_rule['interests'] = $interests_default;
							}

							$subscribed_lists[] = $rule['list_id'];

						}

						$rules[] = $new_rule;

					}
				}
			}
		}

		// cleaning from some unsubscribed lists
		foreach ( $rules as $key => $rule ) {
			if ( in_array( $rule['list_id'], $subscribed_lists ) && 'unsubscribed' == $rule['status'] ) {
				unset( $rules[ $key ] );
			}
		}

		// align interests
		$rules = $this->interests_alignment( $rules );
		$this->add_log( 'Rules prepared', '<pre>' . print_r( $rules, true ) . '</pre>' );
		return $rules;

	} // end generate_rules_lt



	/**
	 * Kind of interests merging function
	 * This function also removes two similar unsubscription rules
	 */

	function interests_alignment( $lists ) {

		$lists_assoc = array(); // Array( 'list_id' => 'interests' ) // interests can be empty

		foreach ( $lists as $key => &$list ) {

			// is this list already in our assoc array?
			if ( array_key_exists( $list['list_id'], $lists_assoc ) ) {
				// if the new iteration has interests, let's add them
				if ( ! empty( $list['interests'] ) ) {
					$lists_assoc[ $list['list_id'] ] = $this->interests_plus_interests( $lists_assoc[ $list['list_id'] ], $list['interests'] );
				}
				// and then, no matter what, we remove this key, because it is already in array, and because we removed unsubscribe rules, it is the duplicate rule
				unset( $lists[ $key ] );
			} else {
				// not in assoc array? add it there
				$lists_assoc[ $list['list_id'] ] = $list['interests'];
			}
		}

		// now $lists contain only unique list IDs, but no interests, let's add them
		foreach ( $lists as &$list ) {
			if ( array_key_exists( $list['list_id'], $lists_assoc ) ) {
				$list['interests'] = $lists_assoc[ $list['list_id'] ];
			}
		}

		return array_values( $lists );

	}

	// interests + interests stuff, both must be arrays
	function interests_plus_interests( $interests1, $interests2 ) {

		foreach ( $interests1 as $key => &$value_true_or_false ) {  // loop and save
			if ( true == $value_true_or_false ) {
				continue; // already true, skip
			}
			if ( isset( $interests2[ $key ] ) && true == $interests2[ $key ] ) {
				$value_true_or_false = true; // if not true but in a second interest list true, make it true
			}
		}

		return $interests1;
	}



	/**
	 * Adds new rules to scheduled rules from options
	 */

	function add_rules( $rules ) {

		if ( $scheduled_rules = get_option( '_misha_mailchimp_scheduled_rules' ) ) {

			//before merging two arrays, let's clean the first one a little bit
			$scheduled_rules_assoc = array();
			foreach ( $scheduled_rules as $scheduled_rule ) {
				// keep in mind, that $scheduled_rules are already cleaned and we do not have let's say both subscribe OR unsubscribe rules and  both subscribe (unsubscribe) ones
				$scheduled_rules_assoc[ $scheduled_rule['list_id'] ] = $scheduled_rule; // content is completely the same
			}

			// now we have an assoc array which we can easily clean, to do it, let's loop through the $rules array and
			// if status in the 2nd one doesn't match the 1st, we remove the 1st, because the second has more priority
			foreach ( $rules as $rule ) {
				$current_list_id = $rule['list_id'];
				$current_list_status = $rule['status'];
				if ( $current_list_status != $scheduled_rules_assoc[ $current_list_id ]['status'] ) {
					unset( $scheduled_rules_assoc[ $current_list_id ] );
				}
			}

			$scheduled_rules = array_values( $scheduled_rules_assoc ); // we need only values from the cleaned array
			$rules = array_merge( $scheduled_rules, $rules );
			$rules = $this->interests_alignment( $rules );

		}
		update_option( '_misha_mailchimp_scheduled_rules', $rules );

	}



	/**
	 * Sync user hook
	 */

	function sync_user( $user_id, $old_userdata = null ) {

		$userdata = get_user_by( 'id', $user_id );

		$this->add_rules( $this->generate_rules_lt( $userdata ) );

		// keep in mind that we have to unsubscribe old email everywhere
		if ( ! empty( $old_userdata->user_email ) && $userdata->user_email !== $old_userdata->user_email ) {
			$this->add_rules( $this->generate_rules_lt( $old_userdata, false ) );
		}

		$this->schedule();

	}



	/**
	 * Sync any amount of users
	 */

	function sync_users( $user_ids = array() ) {

		if ( empty( $user_ids ) && ! is_array( $user_ids ) ) {
			return;
		}

		if ( count( $user_ids ) > 1 ) {

			$rules = array();
			foreach ( $user_ids as $user_id ) {
				$userdata = get_user_by( 'id', $user_id );
				$rules = array_merge( $rules, $this->generate_rules_lt( $userdata ) );
			}

			$this->add_rules( $rules );
			$this->schedule();

		} else {
			$this->sync_user( $user_ids[0] );
		}

	}



	/**
	 * Delete user hook
	 */

	function delete_user( $user_id ) {

		$userdata = get_user_by( 'id', $user_id );

		$this->add_rules( $this->generate_rules_lt( $userdata, false ) ); // $allow_subscribe set to false, so we skip any subscription rules
		$this->schedule();

	}



	/**
	 * Delete memberships function
	 */

	function membership_delete( $post_id ) {

		if ( 'wc_user_membership' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ! $membership = wc_memberships_get_user_membership( $post_id ) ) {
			return;
		}

		if ( ! $rules_from_plan_statuses = get_post_meta( $membership->get_plan_id(), '_misha_mailchimp_plan_statuses', true ) ) {
			return; // if this membership does not have settings, lets skip
		}

		$this->add_rules( $this->generate_rules_lt( $membership->get_user(), true, $membership->get_id() ) );
		$this->schedule();

	}



	/**
	 * Membership status change
	 */

	function membership_status_changed( $membership ) {

		if ( empty( $membership->user_id ) ) {
			return; // better safe than sorry
		}

		$this->add_log( 'wc_memberships_user_membership_status_changed', 'success' );

		$this->sync_user( $membership->user_id );

	}



	/**
	 * In case a membership has been created via admin
	 */

	function membership_created_manually( $post_ID, $post_after, $post_before ) {

		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'update-post_' . $post_ID ) ) {
			return;
		}
		if ( 'wc_user_membership' !== $post_before->post_type || 'wc_user_membership' !== $post_after->post_type ) {
			return;
		}
		if ( 'auto-draft' !== $post_before->post_status ) {
			return;
		}

		$this->add_log( 'post_updated', 'success' );
		$this->sync_user( $post_after->post_author );

	}



	/**
	 * Membership grant access after purchase
	 */

	function membership_grant_access( $membership_plan, $args ) {

		$this->add_log( 'wc_memberships_grant_membership_access_from_purchase', 'success' );

		$this->sync_user( $args['user_id'] );

	}


}
