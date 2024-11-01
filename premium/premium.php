<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

class Updraft_Manager_Premium {

	private $logged = array();
	private $run_began = 0;
	private $email_info = array();
	private $from_email;
	private $from_email_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action('udmanager_dorenewalreminders', array($this, 'udmanager_dorenewalreminders'));
	}
	
	/**
	 * Find out whether a specified site is licensed in the database for a specified plugin; and if so, return the matching entitlements
	 * 
	 * @param Integer $owner_user_id  - the user who provides the plugin
	 * @param String $filter_slug	  - the plugin to check for
	 * @param String|Null $filter_key - the key to check for
	 * @param String $home_url		  - the site to check for
	 * 
	 * @return Array - the matching entitlements (empty if none found)
	 */
	public static function get_licences_for_url($home_url, $owner_user_id, $filter_slug, $filter_key = null) {
	
		global $wpdb;
		
		$url_normalised = Updraft_Manager::normalise_url($home_url);
	
		$prepare_this = "SELECT * FROM ".Updraft_Manager::get_entitlements_table()." WHERE status='active'";
		$prepare_this .= " AND type = 'addons'";
		$prepare_this .= " AND owner_user_id=%d AND expires >= %d";

		$expire_window_begin = time();
		
		if ($filter_slug) $prepare_this .= " AND slug = '".esc_sql($filter_slug)."'";
		if ($filter_key) $prepare_this .= " AND `key` = '".esc_sql($filter_key)."'";

		$prepare_this .= " AND url_normalised = %s";

		$sql = $wpdb->prepare($prepare_this, $owner_user_id, $expire_window_begin, $url_normalised);

		$entitlement_results = $wpdb->get_results($sql, ARRAY_A);
	
		if (!is_array($entitlement_results)) {
			error_log("Updraft_Manager_Premium::is_site_licensed(): unexpected results returned ($sql)");
			return array();
		}
		
		return $entitlement_results;
	
	}

	// Returns results order by the soonest expiring first
	public static function db_get_all_entitlements($user_id, $expire_window_begin, $expire_window_end = false, $types = array('addons', 'support'), $filter_slug = false, $filter_key = false) {
	
		global $wpdb;

		if (false === $types) $types = array('addons', 'support');

		if ($types) $type_string = implode("', '", $types);

		$prepare_this = "SELECT * FROM ".Updraft_Manager::get_entitlements_table()." WHERE status='active'";
		if (!empty($type_string)) $prepare_this .= " AND type IN ('$type_string')";
		$prepare_this .= " AND user_id=%d AND expires >= %d";

		if ($expire_window_end) $prepare_this .= " AND expires <= ".(int)$expire_window_end;
		
		if ($filter_slug) $prepare_this .= " AND slug = '".esc_sql($filter_slug)."'";
		if ($filter_key) $prepare_this .= " AND `key` = '".esc_sql($filter_key)."'";

		$prepare_this .= ' ORDER BY expires ASC';

		$sql = $wpdb->prepare($prepare_this, $user_id, $expire_window_begin);

		$entitlement_results = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($entitlement_results)) {
			return array();
		}

		// Don't re-key - entitlement IDs may not be unique (the entitlement ID/user ID/type combination is unique)
// 		$results = array();
// 		foreach ($entitlement_results as $r) {
// 			$results[$r['entitlement_id']] = $r;
// 		}
// 
// 		return $results;

		return $entitlement_results;

	}

	public static function schedule_renewal_run($schedule_it = true, $user_id = false) {
		if (!$user_id) $user_id = UpdraftManager_Options::get_user_id_for_licences();
		$first_time = time() + 1200;
		$next_scheduled = wp_next_scheduled('udmanager_dorenewalreminders', array($user_id));
		if ($schedule_it && !$next_scheduled) {
			wp_schedule_event($first_time, 'daily', 'udmanager_dorenewalreminders', array($user_id));
		} elseif (!$schedule_it && $next_scheduled) {
			wp_clear_scheduled_hook('udmanager_dorenewalreminders', array($user_id));
		}
	}

	private function result($code, $message) {
		return json_encode(array('result' => $code, 'message' => $message));
	}

	/**
	 * Log in the indicated user, if no user is currently logged in. Do verification before calling this function.
	 *
	 * Made available as a static function for external components to call
	 *
	 * @param WP_User $user - WP user object
	 */
	public static function autologin_user($user) {
		if (is_user_logged_in()) return;
		if (!is_object($user) || !is_a($user, 'WP_User')) return;
		wp_set_current_user($user->ID, $user->user_login);
		wp_set_auth_cookie($user->ID);
		do_action('wp_login', $user->user_login, $user);
	}

	// Pass in a WP_User object
	// The caller should/must first check that the user is not privileged (i.e. shop customers only) - that check is not done here (since it is integration-specific)
	public static function get_autologin_key($user, $use_time = false) {
		if (false === $use_time) $use_time = time();
		// Start of day
		$use_time = $use_time - ($use_time % 86400);
		if (!defined('SECURE_AUTH_KEY')) return;
		$hash_it = $user->ID.'_'.$use_time.'_'.SECURE_AUTH_KEY;
		$hash = hash('sha256', $hash_it);
		return $hash;
	}

	public function send_test_email($settings) {

		if (empty($settings['sendtestemail'])) return $this->result('notok', __('No email address was entered.', 'simba-plugin-updates-manager'));

		if (filter_var($settings['sendtestemail'], FILTER_VALIDATE_EMAIL) === false) return $this->result('notok', __('An invalid email address was entered.', 'simba-plugin-updates-manager'));

		if (empty($settings['renewal_email_contents'])) return $this->result('notok', __('The email contents are empty.', 'simba-plugin-updates-manager'));

		if (empty($settings['subject'])) return $this->result('notok', __('The subject line was empty.', 'simba-plugin-updates-manager'));

		$user = get_user_by('id', UpdraftManager_Options::get_user_id_for_licences());

		if (empty($settings['sendemailsfrom'])) {
			$sendemailsfrom = $user->user_email;
		} else {
			$sendemailsfrom = $settings['sendemailsfrom'];
		}
		$sendemailfromname = empty($settings['sendemailfromname']) ? '' : $settings['sendemailfromname'];

		if (filter_var($sendemailsfrom, FILTER_VALIDATE_EMAIL) === false) return $this->result('notok', sprintf(__('An invalid email address was entered for sending from (%s).', 'simba-plugin-updates-manager'), $sendemailsfrom));

		$substituted_contents = $this->substitute_body_contents($settings['renewal_email_contents'], $settings['subject'], $settings, array(), $user, 'updraftmanager_testemail_contents');
		
		$this->send_email($settings['sendtestemail'], $sendemailsfrom, $sendemailfromname, $settings['subject'], $substituted_contents);

		return array('result' => 'ok');
	}

	private function substitute_body_contents($contents, $subject, $settings, $exp_info, $user, $filter = 'updraftmanager_renewalemail_contents') {
		$substituted_contents = str_replace(
			array(
				'[subject]',
				'[username]',
				'[useremail]',
				'[cartlink]',
				'[shoplink]',
				'[urlparameters]',
				'[unsubscribe]',
			),
			array(
				$subject,
				apply_filters('updraftmanager_renewalemail_username', $user->user_login, $exp_info, $settings, $user),
				apply_filters('updraftmanager_renewalemail_useremail', $user->user_email, $exp_info, $settings, $user),
				// This one is filtered by connector plugins, with default priority
				apply_filters('updraftmanager_renewalemail_cartlink', home_url(), $exp_info, $settings, $user),
				apply_filters('updraftmanager_renewalemail_shoplink', home_url(), $exp_info, $settings, $user),
				apply_filters('updraftmanager_renewalemail_urlparameters', home_url(), $exp_info, $settings, $user),
				apply_filters('updraftmanager_renewalemail_unsubscribe', home_url(), $exp_info, $settings, $user)
			),
			$contents
		);
		
		$this->email_info = compact('subject', 'settings', 'exp_info', 'user', 'filter');
		
		$substituted_contents = preg_replace_callback('/\[body:([A-Za-z0-9]+)\]/', array($this, 'substitute_body_contents_callback'), $substituted_contents);
		
		return apply_filters($filter, $substituted_contents, $exp_info, $settings, $user);
		
	}
	
	public function substitute_body_contents_callback($matches) {
		extract($this->email_info);
		return apply_filters('updraftmanager_renewalemail_bodytag', '', $matches[1], $exp_info, $settings, $user, $filter);
	}
	
	public function php_error_to_logline($errno, $errstr, $errfile, $errline) {
		switch ($errno) {
			case 1:		$e_type = 'E_ERROR'; break;
			case 2:		$e_type = 'E_WARNING'; break;
			case 4:		$e_type = 'E_PARSE'; break;
			case 8:		$e_type = 'E_NOTICE'; break;
			case 16:	$e_type = 'E_CORE_ERROR'; break;
			case 32:	$e_type = 'E_CORE_WARNING'; break;
			case 64:	$e_type = 'E_COMPILE_ERROR'; break;
			case 128:	$e_type = 'E_COMPILE_WARNING'; break;
			case 256:	$e_type = 'E_USER_ERROR'; break;
			case 512:	$e_type = 'E_USER_WARNING'; break;
			case 1024:	$e_type = 'E_USER_NOTICE'; break;
			case 2048:	$e_type = 'E_STRICT'; break;
			case 4096:	$e_type = 'E_RECOVERABLE_ERROR'; break;
			case 8192:	$e_type = 'E_DEPRECATED'; break;
			case 16384:	$e_type = 'E_USER_DEPRECATED'; break;
			case 30719:	$e_type = 'E_ALL'; break;
			default:		$e_type = "E_UNKNOWN ($errno)"; break;
		}

		if (!is_string($errstr)) $errstr = serialize($errstr);

		if (0 === strpos($errfile, ABSPATH)) $errfile = substr($errfile, strlen(ABSPATH));

		return "PHP event: code $e_type: $errstr (line $errline, $errfile)";

	}

	public function php_error($errno, $errstr, $errfile, $errline) {
		if (0 == error_reporting()) return true;
		$logline = $this->php_error_to_logline($errno, $errstr, $errfile, $errline);
		$this->log($logline, 'notice');
		// Pass it up the chain
		return false;
// 		return $this->error_reporting_stop_when_logged;
	}

	public function send_reminders($settings) {

		if (empty($settings['sendrenewalreminders'])) {
			return array('result' => 'notok', 'message' => __('Your options are current set to not send any email reminders.', 'simba-plugin-updates-manager'));
		}

		$result = $this->udmanager_dorenewalreminders(false, $settings);
		if ($result) {
			return array('result' => 'ok');
		} else {
			return array('result' => 'notok', 'message' => __('An error prevented the reminders from being sent.', 'simba-plugin-updates-manager'));
		}
	}

	/**
	 * @return String
	 */
	public function wp_mail_from() {
		return $this->from_email;
	}

	/**
	 * @return String
	 */
	public function wp_mail_from_name() {
		return $this->from_email_name;
	}
	
	/**
	 * Set the Return-path: header as desired; called by the WP action phpmailer_init
	 *
	 * @param Object $phpmailer - PHPMailer object
	 */
	public function wp_mail_set_return_path($phpmailer) {
		$phpmailer->Sender = $phpmailer->From;
	}

	private function send_email($to, $sender, $sender_name, $subject, $contents) {

		// https://wordpress.stackexchange.com/questions/191923/sending-multipart-text-html-emails-via-wp-mail-will-likely-get-your-domain-b

		$message['text/html'] = '<html>
		<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<title>'.htmlspecialchars($subject).'</title>
		</head>
		<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">

		'.$contents.'

		</body>
		</html>';

		require_once(UDMANAGER_DIR.'/vendor/autoload.php');
		// It can throw PHP notices and warnings, and thus break AJAX
		$message['text/plain'] = Soundasleep\Html2Text::convert($contents);

		$this->from_email = $sender;
		$this->from_email_name = $sender_name;
		// Make sure our setting is what applies, since we're only using it on our own emails
		add_filter('wp_mail_from', array($this, 'wp_mail_from'), PHP_INT_MAX);
		add_filter('wp_mail_from_name', array($this, 'wp_mail_from_name'), PHP_INT_MAX);
		add_action('phpmailer_init', array($this, 'wp_mail_set_return_path'));
		
		// send email using the modified (bug-fixed) wp_mail() function
		if (!function_exists('simbamanager_wp_mail')) require_once(UDMANAGER_DIR.'/vendor/simbamanager_wp_mail.php');
		$ret = simbamanager_wp_mail( $to, $subject, $message );

		remove_filter('wp_mail_from', array($this, 'wp_mail_from'), PHP_INT_MAX);
		remove_filter('wp_mail_from_name', array($this, 'wp_mail_from_name'), PHP_INT_MAX);
		remove_action('phpmailer_init', array($this, 'wp_mail_set_return_path'));
		
		return $ret;

	}

	public function udmanager_dorenewalreminders($user_id = false, $settings = array()) {
		if (!class_exists('UpdraftManager_Semaphore_Logger')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-semaphore-logger.php');
		if (!class_exists('Updraft_Semaphore_3_0')) require(UDMANAGER_DIR.'/classes/class-updraft-semaphore.php');
		
		$logger = new UpdraftManager_Semaphore_Logger();
		$semaphore = new Updraft_Semaphore_3_0('udmanager_dorenewalreminders', 600, array($logger));

		if (!$semaphore->lock()) return false;
		
		$this->logged = array();

		$this->run_began = microtime(true);
		$this->log(sprintf(__('Beginning renewal reminder run (%s)', 'simba-plugin-updates-manager'), home_url()));
		if (empty($user_id)) {
			$user_id = UpdraftManager_Options::get_user_id_for_licences();
			if (!$user_id) return false;
		}

		set_error_handler(array($this, 'php_error'), E_ALL & ~E_STRICT);

		if (empty($settings)) {
			$settings = UpdraftManager_Options_Extended::get_settings($user_id);
		}

		$settings['user_id'] = $user_id;
		$ret = $this->udmanager_dorenewalreminders_go($settings);
		
		if (empty($this->logged)) $this->log(__('No information was logged', 'simba-plugin-updates-manager'));

		$subject = __('Simba Plugin Updates Manager - Email Renewal Report', 'simba-plugin-updates-manager');

		$body = '';
		foreach ($this->logged as $item) {
			$body .= sprintf('%4.6f [%s] %s', $item['time'], ucfirst($item['level']), $item['msg'])."\n";
		}

		do_action('updraftmanager_renewalemail_log', $body, $subject, $settings, $this->logged);
		
		if (!empty($settings['sendlogto'])) {
			$emails = array_map('trim', explode(',', $settings['sendlogto']));
			if (!function_exists('simbamanager_wp_mail')) require_once(UDMANAGER_DIR.'/vendor/simbamanager_wp_mail.php');
			foreach ($emails as $email) {
				if ($email) simbamanager_wp_mail($email, $subject, $body);
			}
		}

		restore_error_handler();

		$semaphore->release();

		return $ret;

	}

	/**
	 * Add a line to the log
	 *
	 * @param String $msg - the message to log
	 * @param String $level - the log level
	 */
	private function log($msg, $level = 'info') {
		$time_now = microtime(true) - $this->run_began;
		do_action('updraftmanager_renewalemail_log_line', $msg, $level, $time_now);
		$this->logged[] = array('time' => $time_now, 'level' => $level, 'msg' => $msg);
	}

	private function udmanager_dorenewalreminders_go($settings) {
		if (empty($settings)) return;
		$debug = empty($settings['debugmode']) ? false : true;

		if (empty($settings['renewal_email_contents'])) {
			$this->log(__('The email contents are empty.', 'simba-plugin-updates-manager'), 'error');
			return false;
		}
		$contents = $settings['renewal_email_contents'];

		if (empty($settings['subject'])) {
			$this->log(__('The subject line was empty.', 'simba-plugin-updates-manager'), 'error');
			return false;
		}
		$subject = $settings['subject'];

		if (empty($settings['sendemailsfrom'])) {
			$user = get_user_by('id', $settings['user_id']);
			$sendemailsfrom = $user->user_email;
		} else {
			$sendemailsfrom = $settings['sendemailsfrom'];
		}
		
		$debug_this_customer = false;
		if ($debug && !empty($settings['debug_this_customer'])) {
			$debug_this_customer = trim($settings['debug_this_customer']);
			if (filter_var($debug_this_customer, FILTER_VALIDATE_EMAIL) === false) {
				$this->log(__('An invalid email address was entered for the customer to debug.', 'simba-plugin-updates-manager'), 'error');
				return false;
			}
		}

		if (filter_var($sendemailsfrom, FILTER_VALIDATE_EMAIL) === false) {
			$this->log(sprintf(__('An invalid email address was entered for sending from (%s).', 'simba-plugin-updates-manager'), $sendemailsfrom), 'error');
		}
		$sendemailfromname = empty($settings['sendemailfromname']) ? '' : $settings['sendemailfromname'];

		if (empty($settings['sendrenewalsat'])) {
			$this->log(__('No days for sending renewal emails have been entered in the settings.', 'simba-plugin-updates-manager'), 'error');
			return false;
		}

		$send_renewals_at = array_unique(array_map('trim', explode(',', $settings['sendrenewalsat'])));
		foreach ($send_renewals_at as $k => $v) {
			if (!is_numeric($v)) unset($send_renewals_at[$k]);
		}
		if (empty($send_renewals_at)) {
			$this->log(__('No valid days for sending renewal emails have been entered in the settings.', 'simba-plugin-updates-manager'), 'error');
			return false;
		}

// 		$stop_reminders_at = empty($settings['stopremindersat']) ? '' : (float)$settings['stopremindersat'];

		/* Now, actually look for the potential customers

		1. Look for licences expiring the specified number of days away
		2. Filter out any from customers who have received mails recently (according to the setting)
		3. Perform the search/replace on the email template for that customer

		*/

		// Look for licences
		$where = '';
		// Round down, to allow for the behaviour of the WP cron system - reset to the nearest hour
		$time_now = $this->get_rounded_time_now();
// 		$stop_reminders_at_time = $time_now - $stop_reminders_at*86400;
		foreach ($send_renewals_at as $days_out) {
			$time_from = $time_now+$days_out*86400;
			if ($where) $where .= ' OR ';
			$where .= "(expires >= $time_from AND expires < ".($time_from+86400).")";
		}
		global $wpdb, $updraft_manager;
		$sql = $wpdb->prepare("SELECT * FROM ".Updraft_Manager::get_entitlements_table()." WHERE status='active' AND owner_user_id = %d AND ($where)", $settings['user_id']);
		
		// AND expires >= $stop_reminders_at_time
		$this->log("SQL for fetching expiring entitlements: $sql", 'debug');

		$soon_expiring_results = $wpdb->get_results($sql);
		if (!is_array($soon_expiring_results)) {
			$this->log(__('A database error occurred when fetching the list of soon-expiring licences', 'simba-plugin-updates-manager'), 'error');
			$this->log($sql, 'debug');
			return false;
		}

		$soon_expiring = array();
		foreach ($soon_expiring_results as $r) {
			if (empty($soon_expiring[$r->user_id])) $soon_expiring[$r->user_id] = array();
			if (empty($soon_expiring[$r->user_id][$r->type])) $soon_expiring[$r->user_id][$r->type] = array();
			if (empty($soon_expiring[$r->user_id][$r->type][$r->slug])) $soon_expiring[$r->user_id][$r->type][$r->slug] = array();
			$key = $r->key;
			if (empty($soon_expiring[$r->user_id][$r->type][$r->slug][$key])) $soon_expiring[$r->user_id][$r->type][$r->slug][$key] = array();
			$site_id = $r->site;
			$meta = $r->meta;
			$soon_expiring[$r->user_id][$r->type][$r->slug][$key][$r->entitlement_id] = array('expires' => $r->expires, 'site' => $site_id, 'meta' => $meta);
		}

		$recent_send_usermeta_field = apply_filters('updraftmanager_lastsent_usermetafield', 'updraftmanager_lastrenewalemail');
		$unsubscribe_usermeta_field = apply_filters('updraftmanager_unsubscribe_usermetafield', 'udmanager_send_renewal_emails');
		
		$min_interval_days = empty($settings['mininterval']) ? 0 : absint($settings['mininterval']);
		// We add on an extra 3 hours to allow for the behaviour of the WP Cron system (this task only runs daily).
		$not_if_mailed_since = $time_now - $min_interval_days*86400 + 10800;

		$plugins = UpdraftManager_Options::get_options($settings['user_id']);
		$mails_sent = 0;

		foreach ($soon_expiring as $user_id => $exp_info) {

			$last_expiry_reminder = get_user_meta($user_id, $recent_send_usermeta_field, true);
			if (empty($last_expiry_reminder)) $last_expiry_reminder = false;
			
			// The 'last emailed' check is skipped if debugging a specific customer
			if ($last_expiry_reminder > $not_if_mailed_since && false === $debug_this_customer) {
				$this->log(sprintf(__('Customer %d was last emailed at %d - skipping (in accordance with settings)', 'simba-plugin-updates-manager'), $user_id, $last_expiry_reminder), 'debug');
				continue;
			}

			$exp_info = apply_filters('udmanager_renewal_run_soon_expiring_for_user', $exp_info, $user_id, $settings);
			
			$send_reminder = false;
			foreach ($exp_info as $type => $exp_info2) {
				if ($type == 'addons') {
					foreach ($exp_info2 as $slug => $exp_info3) {
						if (empty($plugins[$slug]) || empty($plugins[$slug]['active']) || !empty($plugins[$slug]['freeplugin'])) continue;
						$send_reminder = apply_filters('udmanager_send_reminder', true, $type, $exp_info, $settings, $user_id);
					}
				} elseif ($type == 'support') {
					$send_reminder = apply_filters('udmanager_send_reminder', true, $type, $exp_info, $settings, $user_id);
				} else {
					$send_reminder = apply_filters('udmanager_send_reminder', $send_reminder, $type, $exp_info, $settings, $user_id);
				}
			}
			if (!$send_reminder) continue;

			$user = get_user_by('id', $user_id);
			
			if (!is_a($user, 'WP_User')) {
				$this->log(sprintf(__('Customer %d does not seem to exist (get_user_by returned: %s) - skipping', 'simba-plugin-updates-manager'), $user_id, serialize($user)), 'notice');
				continue;
			}
			
			$print_send_to = $this->anonymise_email_address($user->user_email);
			
			if (false !== $debug_this_customer && isset($user->user_email) && $user->user_email != $debug_this_customer) {
				$this->log(sprintf(__('Customer %d (%s) is not the customer specified as the specific one to be debugged - skipping', 'simba-plugin-updates-manager'), $user_id, $print_send_to), 'debug');
				continue;
			}
			
			// Though it would be logical to check whether they've unsubscribed first, we wait until now in order to wait until we'd got the WP user object, to avoid doing that on users that will already be excluded for other reasons
			$unsubscribe = ('yes' == get_user_meta($user_id, $unsubscribe_usermeta_field, true));
			$unsubscribe = apply_filters('updraftmanager_user_has_unsubscribed', $unsubscribe, $user_id, $user);
			if ($unsubscribe) {
				$this->log(sprintf(__('Customer %d (%s) has unsubscribed from receiving renewal emails - skipping', 'simba-plugin-updates-manager'), $user_id, $print_send_to), 'debug');
				continue;
			}

			$send_to = apply_filters('updraftmanager_renewalemail_sendto', $user->user_email, $user);

			$send_subject = apply_filters('updraftmanager_renewalemail_subject', $subject, $exp_info, $settings, $user);
			
			$substituted_contents = $this->substitute_body_contents($contents, $send_subject, $settings, $exp_info, $user);
			
			$max_mails_in_debug_mode = apply_filters('updraftmanager_max_mails_in_debug_mode', 1);
			
			if ($debug && $mails_sent >= $max_mails_in_debug_mode) {
				$this->log(sprintf(__('Renewal reminder email suppressed because of debug mode: to %s', 'simba-plugin-updates-manager'), $send_to), 'debug');
			} elseif ($debug) {
				$use =  !empty($settings['senttestemail']) ? $settings['senttestemail'] : $settings['sendlogto'];
				$emails = array_map('trim', explode(',', $use));
				foreach ($emails as $email) {
					if (!$email) continue;
					$this->log(sprintf(__('Redirecting first customer reminder email due to debug mode, from %s to %s', 'simba-plugin-updates-manager'), $send_to, $email), 'debug');
					$this->send_email($email, $sendemailsfrom, $sendemailfromname, $send_subject, $substituted_contents);
					$mails_sent ++;
				}
			} else {
			
				$print_send_to = $this->anonymise_email_address($send_to);
				
				$this->log(sprintf(__('Sending renewal reminder email to user %d: %s', 'simba-plugin-updates-manager'), $user_id, $print_send_to));
				$this->send_email($send_to, $sendemailsfrom, $sendemailfromname, $send_subject, $substituted_contents);
				$mails_sent++;
				update_user_meta($user_id, $recent_send_usermeta_field, $time_now);
			}
		}

		$this->log(__('Number of emails sent:', 'simba-plugin-updates-manager')." $mails_sent");

		$this->log(__('Finishing renewal reminder run', 'simba-plugin-updates-manager'));

		return true;

	}

	/**
	 * Anonymise an email address
	 *
	 * @param $address String - the address
	 *
	 * @return String - the anonymised address
	 */
	private function anonymise_email_address($address) {
	
		$anonymised_address = $address;
	
		if (preg_match('/^(..)(.*)\@(.*)\.([^\.]+)$/', $anonymised_address, $matches)) {
			$anonymised_address = $matches[1].str_repeat('*', strlen($matches[2])).'@'.substr($matches[3], 0, 1).str_repeat('*', strlen($matches[3])-1).'.'.$matches[4];
		} else {
			$anonymised_address = 'anonymous-could-not-parse@invalid';
		}
		
		return apply_filters('updraftmanager_renewalemail_anonymised_email', $anonymised_address, $address);

	}
	
	public static function get_rounded_time_now() {
		$time_now = time();
		// Round down, to allow for the behaviour of the WP cron system - reset to the nearest hour
		$time_now = $time_now - ($time_now%3600);
		return $time_now;
	}

}

$updraft_manager_premium = new Updraft_Manager_Premium();
