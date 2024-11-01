<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

class UpdraftManager_Options_Extended extends UpdraftManager_Options {

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_filter('user_row_actions', array($this, 'user_row_actions'), 10, 2);
		//add_filter('updraftmanager_shopurl', array($this, 'updraftmanager_shopurl'));
		add_filter('updraftmanager_inuseonsites_final', array($this, 'updraftmanager_inuseonsites_final'), 10, 5);
		add_filter('updraftmanager_newplugin_freeplugin', array($this, 'newplugin_freeplugin'), 10, 2);
		add_filter('updraftmanager_newplugin_addonsdir', array($this, 'newplugin_addonsdir'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('udmanager_ajax_event', array($this, 'ajax_handler2'));
		add_action('udmanager_ajax_nonmanager_event', array($this, 'ajax_handler2_nonmanager'));
		// Run (to add our sub-menu item) after the parent has added the menu
		add_action('admin_menu', array($this, 'admin_menu_premium'), 11);
		parent::__construct();
	}

	/**
	 * Runs upon the WP action admin_menu
	 */
	public function admin_menu_premium() {
		$perm = $this->manage_permission();
		add_submenu_page('updraftmanager', __('Plugins Manager', 'simba-plugin-updates-manager').' - '.__('Renewal Reminders', 'simba-plugin-updates-manager'), __('Renewal Reminders', 'simba-plugin-updates-manager'), $perm, 'updraftmanager_renewal_reminders', array($this, 'options_renewal_emails_printpage'));
	}

	/**
	 * Callback for painting the renewal reminders options page
	 */
	public function options_renewal_emails_printpage() {
		if (!current_user_can($this->manage_permission())) wp_die( __('You do not have sufficient permissions to access this page.') );

		echo '<div style="clear: left;width:950px; float: left; margin-right:20px;">
		<h1>'.__('Simba Plugins Manager', 'simba-plugin-updates-manager').' - '.__('Renewal Reminders', 'simba-plugin-updates-manager').'</h1>';

		// Warnings, if any, go here

		echo '<div class="wrap">';

		$action = (isset($_REQUEST['action'])) ? $_REQUEST['action'] : '';
		if (empty($action)) {
			global $plugin_page;
			if ('updraftmanager_' == substr($plugin_page, 0, 15)) $action = substr($plugin_page, 15);
		}

		switch ($action) {
			case 'activate':
				echo 'TODO1';
				break;
			case 'show_options';
			default:
				$this->renewal_options_print();
		}

		echo '</div>';

	}

	public static function get_settings($uid = false) {
		if (false === $uid) $uid = self::get_user_id_for_licences();
		$settings = get_user_meta($uid, 'updraftmanager_settings', true);
		return is_array($settings) ? $settings : array();
	}

	public static function update_settings($settings, $uid = false) {
		if (false === $uid) $uid = self::get_user_id_for_licences();
		// Unfortunate, $ret does not give a success/failure state - (bool)false is used both for failure, and for "value has not changed".
		$ret = update_user_meta($uid, 'updraftmanager_settings', $settings);
		return true;
	}

	public function renewal_options_print() {

		$settings = $this->get_settings();

		$sendrenewalreminders = empty($settings['sendrenewalreminders']) ? false : true;
		$sendrenewalsat = empty($settings['sendrenewalsat']) ? '' : $settings['sendrenewalsat'];
		$debug_this_customer = empty($settings['debug_this_customer']) ? '' : $settings['debug_this_customer'];
		$sendlogto = empty($settings['sendlogto']) ? '' : $settings['sendlogto'];
		$sendemailsfrom = empty($settings['sendemailsfrom']) ? '' : $settings['sendemailsfrom'];
		$sendemailfromname = empty($settings['sendemailfromname']) ? '' : $settings['sendemailfromname'];
		$subject = empty($settings['subject']) ? '' : $settings['subject'];
		$mininterval = empty($settings['mininterval']) ? 7 : absint($settings['mininterval']);
// 		$stopremindersat = empty($settings['stopremindersat']) ? 7 : absint($settings['stopremindersat']);
		$debugmode = empty($settings['debugmode']) ? false : true;
		$content = empty($settings['renewal_email_contents']) ? '' : $settings['renewal_email_contents'];
		$autolinklogin = empty($settings['autolinklogin']) ? false : true;

		$user = get_user_by('id', UpdraftManager_Options::get_user_id_for_licences());
		$sendemailsfrom_default = $user->user_email;

		UpdraftManager_Options::plugin_notices();

		?>
		<div id="updraftmanager_form" class="udm_renewalsettings">

		<h2 style="padding-bottom: 6px;"><?php _e('Renewal reminder emails', 'simba-plugin-updates-manager');?></h2>

			<label for="udmanager_sendrenewalreminders"><?php _e('Send email reminders', 'simba-plugin-updates-manager');?></label>
			<input type="checkbox" id="udmanager_sendrenewalreminders" name="sendrenewalreminders" <?php if ($sendrenewalreminders) echo 'checked="checked"';?>>
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Send renewal reminder e-mails for customers with soon-expiring licences.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udmanager_debugmode"><?php _e('Debug mode', 'simba-plugin-updates-manager');?></label>
			<input type="checkbox" id="udmanager_debugmode" name="debugmode" <?php if ($debugmode) echo 'checked="checked"';?>>
			<span class="udm_description"><em><?php echo htmlspecialchars(__('In debug mode, no emails are sent to customers (but the first customer email is redirected to you, and the log will tell you what else would have been sent).', 'simba-plugin-updates-manager'));?></em></span>

			<div class="show_in_debug_mode_only">
				<label for="udmanager_debug_this_customer"><?php _e('This customer only', 'simba-plugin-updates-manager'); ?></label>
				<input type="text" id="udmanager_debug_this_customer" name="debug_this_customer" size="42" value="<?php echo esc_attr($debug_this_customer); ?>">
				<span class="udm_description"><em><?php echo htmlspecialchars(__("If you enter an email address here then in debug mode, a renewal email will only be sent for this customer (and checks on whether one has been sent recently will be skipped).", 'updraftmanager'));?></em></span>
			</div>
			
			<label for="udmanager_sendrenewalsat"><?php _e('Send this many days before expiry', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_sendrenewalsat" name="sendrenewalsat" size="42" value="<?php echo esc_attr($sendrenewalsat); ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter a comma-separated list of the number of days out (from licence expiry) to send renewal reminders at. Negative numbers are allowed.', 'simba-plugin-updates-manager'));?></em></span>

			<?php
			/*
			<label for="udmanager_stopremindersat"><?php _e('Ignore expired licences after', 'simba-plugin-updates-manager'); ?></label>
			<input type="number" min="0" step="1" id="udmanager_stopremindersat" name="stopremindersat" size="3" value="<?php echo $stopremindersat; ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Do not send any more renewal reminders once this number of days after expiry have passed.', 'simba-plugin-updates-manager'));?></em></span>
			*/
			?>

			<label for="udmanager_sendemailsfrom"><?php _e('Send emails from (address)', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_sendemailsfrom" name="sendemailsfrom" size="42" value="<?php echo esc_attr($sendemailsfrom); ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter a single email address.', 'simba-plugin-updates-manager').' '.sprintf(__("If left empty, your user's email address (%s) will be used.", 'updraftmanager'), $sendemailsfrom_default));?></em></span>

			<label for="udmanager_sendemailfromname"><?php _e('Send emails from (name)', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_sendemailfromname" name="sendemailfromname" size="42" value="<?php echo esc_attr($sendemailfromname); ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter the name of the sender.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udmanager_subject"><?php _e('Email subject', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_subject" name="subject" size="42" value="<?php echo esc_attr($subject); ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter a subject line for the email.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udmanager_autolinklogin"><?php _e('Automatic login', 'simba-plugin-updates-manager');?></label>
			<input type="checkbox" id="udmanager_autolinklogin" name="autolinklogin" <?php if ($autolinklogin) echo 'checked="checked"';?>>
			<span class="udm_description"><em><?php echo htmlspecialchars(sprintf(__('If checked, then any cart/shop links (with the help of an integration plugin) you use in your email will also automatically log the user in (if clicked within %d days).', 'simba-plugin-updates-manager'), apply_filters('udmanager_autologinexpirydays', 14, $user)).' '.__("This means that access to the renewal email provides login access to the site; however, if someone can read the user's email then by default they could also send a password reset email from WordPress there; so, you should use your own judgment as to whether you consider this option to reduce security (and whether your email needs to inform the user about the need to not share its contents). Login links will not work for any privileged users (e.g. editors, admins).", 'updraftmanager'));?></em></span>

			<label for="udmanager_sendlogto"><?php _e('Email logs to', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_sendlogto" name="sendlogto" size="42" value="<?php echo esc_attr($sendlogto); ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter a comma-separated list of email addresses to send a report to.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udmanager_mininterval"><?php _e('Minimum days between reminders', 'simba-plugin-updates-manager'); ?></label>
			<input type="number" min="0" step="1" id="udmanager_mininterval" name="mininterval" size="3" value="<?php echo $mininterval; ?>">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('The minimum number of days that must pass in between a customer receiving reminder emails (for any product).', 'simba-plugin-updates-manager'));?></em></span>

			<div style="padding: 10px 0px;">
			<?php
				wp_editor($content, 'renewal_email_contents', array());
			?>
			</div>

			<p>
				<?php echo sprintf(__('You can use the following codes in the above editor, and they will be replaced with appropriate values: %s', 'simba-plugin-updates-manager'), '[subject], [username], [useremail], [cartlink], [shoplink], [urlparameters], [unsubscribe]').' '.sprintf(__('N.B. Some of the codes depend upon a relevant shop connector plugin being active (e.g. %s).', 'simba-plugin-updates-manager'), '<a href="https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/">'.__('the connector plugin for WooCommerce', 'simba-plugin-updates-manager').'</a>'); ?>
			<p>

			<label for="udmanager_sendtestemail"><?php _e('Send test email', 'simba-plugin-updates-manager'); ?></label>
			<input type="text" id="udmanager_sendtestemail" size="42" value="">
 			<button class="button button-primary" style="float:left; margin-left: 10px;" id="updraftmanager_sendtestemail_go"><?php _e('Send', 'simba-plugin-updates-manager');?></button>

			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter comma-separated email addresses to send a test message to.', 'simba-plugin-updates-manager').' '.__('The settings entered on this page will be used (whether they have been saved or not).', 'simba-plugin-updates-manager'));?></em></span>

			<label><?php _e('Send reminder emails now', 'simba-plugin-updates-manager'); ?></label>
 			<button class="button button-primary" style="float:left;" id="udmanager_sendremindersnow_go"><?php _e('Process reminder emails now', 'simba-plugin-updates-manager');?></button>

			<span class="udm_description"><em><?php echo htmlspecialchars(__('This will look for, and potentially send, reminder emails now.', 'simba-plugin-updates-manager').' '.__('The settings entered on this page will be used (whether they have been saved or not).', 'simba-plugin-updates-manager'));?></em></span>

			<button class="button button-primary ud_leftgap" id="updraftmanager_saverenewalsettings"><?php _e('Save Settings', 'simba-plugin-updates-manager');?></button>

		</div>
		<?php
	}

	public function admin_head() {
		echo "<script>var updraftmanager_freeversion = 0;</script>\n";
	}

	protected function set_free_plugin_status($x) {
		return $x;
	}

	public function newplugin_addonsdir($x, $use_values, $hid) {
		return '<input '.$hid.'class="udm_newform_addonsrow" id="udm_newform_addonsdir" type="text" name="addonsdir" value="'.((isset($use_values['addonsdir'])) ? htmlspecialchars($use_values['addonsdir']) : '').'" size="20"><span '.$hid.'class="udm_description udm_newform_addonsrow"><em>'.htmlspecialchars(__('This is optional - only for plugins with individual add-ons. Enter the directory name only, with no path components (e.g. "addons")', 'simba-plugin-updates-manager')).htmlspecialchars(__('Note that specifying an addons directory means that the base (no add-ons) plugin becomes free and downloadable (you can over-ride that with a filter).', 'simba-plugin-updates-manager')).'</em></span>';

	}

	public function newplugin_freeplugin($x, $use_values) {
		return '<input id="udm_newform_freeplugin" type="checkbox" name="freeplugin" value="yes" '.((!empty($use_values['freeplugin'])) ? 'checked="checked"' : '').'>';
	}

	public function admin_enqueue_scripts($hook) {

		parent::admin_enqueue_scripts($hook);

		if (strpos($hook, 'udmanager_manage_user') === false && strpos($hook, 'updraftmanager_renewal_reminders') === false) return;

		if (!current_user_can($this->manage_permission('licences')) && !current_user_can($this->manage_permission())) return;

		$use_version = (defined('WP_DEBUG') && WP_DEBUG) ? time() : UDMANAGER_VERSION;

		wp_enqueue_script('updraftmanager-admin-js-prem', UDMANAGER_URL.'/premium/admin.js', array('jquery-ui-datepicker', 'jquery'), $use_version);
		wp_enqueue_style('jquery-ui-css', UDMANAGER_URL.'/css/jquery-ui.css' );

		wp_enqueue_script('jquery-blockui', UDMANAGER_URL.'/js/jquery.blockui.js', array('jquery'), '2.66.0');

		$this->localize_updraftmanagerlionp();

	}

	public static function localize_updraftmanagerlionp($user_id = false, $which_script = 'updraftmanager-admin-js-prem') {
		if (!$user_id) {
			$user_id = (!empty($_GET['id']) && is_numeric($_GET['id'])) ? $_GET['id'] : '';
		}
		wp_localize_script($which_script, 'updraftmanagerlionp', array(
			'ajaxnonce' => wp_create_nonce('updraftmanager-ajax-nonce'),
			'processing' => __('Processing...', 'simba-plugin-updates-manager'),
			'userid' => $user_id,
			'reallydelete' => __('Do you really want to delete this entitlement?', 'udmanager'),
			'reallyreset' => __('Do you really want to release this assignment?', 'udmanager'),
			'reallyresetall' => __('Do you really want to reset all entitlements for this plugin?', 'udmanager'),
			'howmanymonths' => __('How many months do you want to extend this entitlement by?', 'udmanager'),
			'howmanymonthsnew' => __('How many months do you want this entitlement to be for?', 'udmanager'),
			'unsavedsettings' => __('You have unsaved settings.', 'udmanager'),
			'saving' => __('Saving...', 'udmanager'),
			'sendingtest' => __('Sending test email...', 'udmanager'),
			'sendremindersnow' => __('Sending reminders...', 'udmanager'),
			'ajaxurl' => admin_url('admin-ajax.php'),
			'response' => __('Response:', 'udmanager')
		));
	}

	public function ajax_handler2_nonmanager() {

		if (current_user_can($this->manage_permission('licences'))) return;

		global $updraft_manager;
		switch ($_REQUEST['subaction']) {

			case 'entitlement_delete':
				if (empty($_POST['slug']) || empty($_POST['entitlement']) || empty($_POST['userid']) || !is_numeric($_POST['userid'])) return;

				$current_user_id = get_current_user_id();

				$slug = $_POST['slug'];

				$updraft_manager->get_plugin($slug, $_POST['userid']);
				$plugin = $updraft_manager->plugin;

				$addon_entitlements = $plugin->get_user_addon_entitlements($current_user_id);
				$entitlement=$_POST['entitlement'];
				if (isset($addon_entitlements[$entitlement])) {
					$useradd = $addon_entitlements[$entitlement];

					$user_can_manage = false;
					$expired = false;

					if (!empty($useradd['expires']) && time() >= $useradd['expires'] && $useradd['expires'] >0 ) {
						$expired = true;
					}

					if (apply_filters('updraftmanager_usercandeleteassignment', false, $useradd, $entitlement, $expired, $plugin->free, $user_can_manage, $addon_entitlements)) {

						unset($addon_entitlements[$entitlement]);
						$plugin->db_delete_user_entitlement($entitlement, $current_user_id);
// 						$plugin->save_user_entitlements($current_user_id, $addon_entitlements);

						// Slight bug: the shortcode options aren't passed in/back here.
						$show_link = apply_filters('updraftmanager_showlinkdefault', false, $slug);
						$showaddons = apply_filters('updraftmanager_account_showaddons', true, $slug);
						$showunpurchased = 'all';

						echo json_encode(array(
							'success' => true,
// 							'entitlementsnow' => $this->return_user_plugin_entitlements($current_user_id, $_POST['userid'])
							'entitlementsnow' => $plugin->home_addons($show_link, $showunpurchased, $showaddons, $current_user_id)
						));
					} else {
						echo json_encode(array(
							'success' => false,
							'msg' => __('Permission denied', 'simba-plugin-updates-manager')
						));
					}
				} else {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Entitlement not found', 'simba-plugin-updates-manager')
					));
				}
				die;
			break;
			
			case 'entitlement_reset':
				if (empty($_POST['slug']) || empty($_POST['entitlement']) || empty($_POST['owner_userid']) || !is_numeric($_POST['owner_userid'])) return;
				
				$current_user_id = get_current_user_id();
				
				$slug = $_POST['slug'];
				
				$updraft_manager->get_plugin($slug, $_POST['owner_userid']);
				$addon_entitlements = $updraft_manager->plugin->get_user_addon_entitlements($current_user_id);
				$entitlement_id = $_POST['entitlement'];
				if (isset($addon_entitlements[$entitlement_id])) {
					
					$last_checkins = $updraft_manager->plugin->db_get_last_checkins($current_user_id, $entitlement_id);

					$site_id = empty($addon_entitlements[$entitlement_id]['site']) ? false : $addon_entitlements[$entitlement_id]['site'];

					$last_checkin = ($site_id && isset($last_checkins[$site_id]['time'])) ? $last_checkins[$site_id]['time'] : 0;
					
					$when_can_be_reset = $updraft_manager->plugin->entitlement_when_can_be_reset($addon_entitlements[$entitlement_id], $last_checkin);
					
					if (false === $when_can_be_reset || time() < $when_can_be_reset) {
					
						echo json_encode(array(
							'success' => false,
							'msg' => __('Permission to reset this assignment was not granted.', 'simba-plugin-updates-manager'),
							'data' => array('when_can_be_reset' => $when_can_be_reset, 'time_now' => time())
						));
					
					} else {
					
						// Slight bug: the shortcode options aren't passed in/back here.
						$show_link = apply_filters('updraftmanager_showlinkdefault', false, $slug);
						$showaddons = apply_filters('updraftmanager_account_showaddons', true, $slug);
						$showunpurchased = 'all';
					
						$updraft_manager->plugin->db_reset_user_entitlement($entitlement_id, $current_user_id);
						echo json_encode(array(
							'success' => true,
							'entitlementsnow' => $updraft_manager->plugin->home_addons($show_link, $showunpurchased, $showaddons, $current_user_id)
						));
					
					}
				} else {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Entitlement not found', 'simba-plugin-updates-manager')
					));
				}
				die;
			break;
			
			default:
				$event_not_found = true;
			break;
		}
		if (empty($event_not_found)) die;
	}

	private function get_posted_settings($settings) {
		parse_str($settings, $posted_settings);

		$any_found = false;
		$parsed_settings = array();

		// Save settings
		if (!is_array($posted_settings)) $posted_settings = array();

		foreach ($posted_settings as $key => $setting) {

// 				if (0 !== strpos($key, 'udmanager_')) continue;

			$setting = stripslashes_deep($setting);

			$value = null;

			$type = 'text';
			if ($key == 'renewal_email_contents') $type = 'textarea';

			switch ($type) {
				case 'text';
// 				case 'radio';
				case 'select';
				$value = $setting;
				break;
				case 'textarea';
					if (is_string($setting)) {
						$value = wp_kses_post( trim( $setting ) );
					} elseif (is_array($setting)) {
						$value = array();
						foreach ($setting as $k => $v) {
							$value[$k] = stripslashes(wp_kses_post( trim( $v ) ));
						}
					}
				break;
				case 'checkbox';
				$value = empty($setting) ? 'no' : 'yes';
				break;
			}

			if (!is_null($value)) {
// 				$any_found = true;
				// Remove udmanager_
// 					$save_key = substr($key, 10);
				$save_key = $key;
				$parsed_settings[$save_key] = $value;
			}

		}
		return $parsed_settings;
	}

	/**
	 * Called by the WP action udmanager_ajax_event
	 */
	public function ajax_handler2() {
		if (!current_user_can($this->manage_permission('licences'))) return;

		global $updraft_manager;

		switch ($_REQUEST['subaction']) {
			case 'saverenewalsettings':

				if (empty($_POST['settings']) || !is_string($_POST['settings'])) die;
				$parsed_settings = $this->get_posted_settings($_POST['settings']);
				if (empty($parsed_settings)) {
					echo json_encode(array('result' => 'no options found'));
					die;
				}

				if ($this->update_settings($parsed_settings)) {
					echo json_encode(array('result' => 'ok'));
					if (empty($parsed_settings['sendrenewalreminders'])) {
						Updraft_Manager_Premium::schedule_renewal_run(false);
					} else {
						Updraft_Manager_Premium::schedule_renewal_run(true);
					}
				} else {
					echo json_encode(array('result' => 'notok', 'message' => __('Save settings failed.', 'udmanager')));
				}

				die;

			break;
			case 'sendreminders':

				if (empty($_POST['settings']) || !is_string($_POST['settings'])) die;
				$parsed_settings = $this->get_posted_settings($_POST['settings']);
				if (empty($parsed_settings)) {
					echo json_encode(array('result' => 'no options found'));
					die;
				}

				global $updraft_manager_premium;
				$result_array = $updraft_manager_premium->send_reminders($parsed_settings);

				echo json_encode($result_array);

				die;

			break;
			case 'sendtestemail':

				if (empty($_POST['settings']) || !is_string($_POST['settings'])) die;
				$parsed_settings = $this->get_posted_settings($_POST['settings']);
				if (empty($parsed_settings)) {
					echo json_encode(array('result' => 'no options found'));
					die;
				}

				global $updraft_manager_premium;
				$result_array = $updraft_manager_premium->send_test_email($parsed_settings);

				echo json_encode($result_array);

				die;
			break;
			case 'entitlements_reset_all':
				if (empty($_POST['slug']) || empty($_POST['date']) || empty($_POST['userid']) || !is_numeric($_POST['userid'])) return;
				
				$updraft_manager->get_plugin($_POST['slug'], UpdraftManager_Options::get_user_id_for_licences());
				$addon_entitlements = $updraft_manager->plugin->get_user_addon_entitlements($_POST['userid']);

				$expiry = strtotime($_POST['date']);
				if (!$expiry) {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Could not understand date', 'simba-plugin-updates-manager')
					));
					die;
				}

				// Middle of the day, in the absence of a better idea
				$expiry += 43200;

				$any_changed = false;
				foreach ($addon_entitlements as $entitlement_id => $titlement) {
					if (is_array($titlement) && isset($titlement['expires'])) {
						$any_changed = true;
						$addon_entitlements[$entitlement_id]['expires'] = $expiry;
						$updraft_manager->plugin->db_set_user_entitlement($entitlement_id, $_POST['userid'], $addon_entitlements[$entitlement_id], 'update');
					}
				}

				if ($any_changed) {
// 					$updraft_manager->plugin->save_user_entitlements($_POST['userid'], $addon_entitlements);
				}

				echo json_encode(array(
					'success' => true,
					'entitlementsnow' => $this->return_user_plugin_entitlements($_POST['userid'], self::get_user_id_for_licences())
				));
				
			break;
			case 'entitlement_delete':
				if (empty($_POST['slug']) || empty($_POST['entitlement']) || empty($_POST['userid']) || !is_numeric($_POST['userid'])) return;
				$updraft_manager->get_plugin($_POST['slug'], UpdraftManager_Options::get_user_id_for_licences());
				$addon_entitlements = $updraft_manager->plugin->get_user_addon_entitlements($_POST['userid']);
				$entitlement=$_POST['entitlement'];
				if (isset($addon_entitlements[$entitlement])) {
					unset($addon_entitlements[$entitlement]);
					$updraft_manager->plugin->db_delete_user_entitlement($entitlement, $_POST['userid']);
// 					$updraft_manager->plugin->save_user_entitlements($_POST['userid'], $addon_entitlements);
					echo json_encode(array(
						'success' => true,
						'entitlementsnow' => $this->return_user_plugin_entitlements($_POST['userid'], self::get_user_id_for_licences())
					));
				} else {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Entitlement not found', 'simba-plugin-updates-manager')
					));
				}
				die;
			break;
			case 'entitlement_extend':
				if (empty($_POST['slug']) || empty($_POST['entitlement']) || empty($_POST['userid']) || !is_numeric($_POST['userid']) || empty($_POST['howmany']) || !is_numeric($_POST['howmany'])) {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Invalid request', 'simba-plugin-updates-manager')
					));
					die;
				}
				$updraft_manager->get_plugin($_POST['slug'], self::get_user_id_for_licences());
				$addon_entitlements = $updraft_manager->plugin->get_user_addon_entitlements($_POST['userid']);
				$entitlement=$_POST['entitlement'];
				if (isset($addon_entitlements[$entitlement]) && !empty($addon_entitlements[$entitlement]['expires'])) {
					
					$current_expiry = $addon_entitlements[$entitlement]['expires'];
					if ($current_expiry >= 0) {
						$sign = ($_POST['howmany'] >= 0) ? '+' : '';
						$new_expiry = strtotime("@$current_expiry $sign ".(int)$_POST['howmany']." months");
					} else {
						$new_expiry = -1;
					}
					$addon_entitlements[$entitlement]['expires'] = $new_expiry;
					$updraft_manager->plugin->db_set_user_entitlement($entitlement, $_POST['userid'], $addon_entitlements[$entitlement], 'update');
// 					$updraft_manager->plugin->save_user_entitlements($_POST['userid'], $addon_entitlements);
					echo json_encode(array(
						'success' => true,
						'entitlementsnow' => $this->return_user_plugin_entitlements($_POST['userid'], self::get_user_id_for_licences())
					));
				} else {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Entitlement not found', 'simba-plugin-updates-manager')
					));
				}
				die;

			break;
			case 'entitlement_reset':
				if (empty($_POST['slug']) || empty($_POST['entitlement']) || empty($_POST['userid']) || !is_numeric($_POST['userid'])) return;
				$updraft_manager->get_plugin($_POST['slug'], isset($_POST['owner_userid']) ? intval($_POST['owner_userid']) : self::get_user_id_for_licences());
				$addon_entitlements = $updraft_manager->plugin->get_user_addon_entitlements($_POST['userid']);
				$entitlement=$_POST['entitlement'];
				if (isset($addon_entitlements[$entitlement])) {
					$addon_entitlements[$entitlement]['site'] = 'unclaimed';
					$addon_entitlements[$entitlement]['sitedescription'] = __('Unused entitlement', 'simba-plugin-updates-manager');
					$updraft_manager->plugin->db_reset_user_entitlement($entitlement, $_POST['userid']);
					echo json_encode(array(
						'success' => true,
						'entitlementsnow' => $this->return_user_plugin_entitlements($_POST['userid'], self::get_user_id_for_licences())
					));
				} else {
					echo json_encode(array(
						'success' => false,
						'msg' => __('Entitlement not found', 'simba-plugin-updates-manager')
					));
				}
				die;
			break;
			
			case 'download':

				if (empty($_GET['slug']) || empty($_GET['filename']) || false !== strpos($_GET['filename'], '..') || false !== strpos($_GET['filename'], '/') || !preg_match('/\.zip$/i', $_GET['filename'])) die('Security check.');
			
				$manager_dir = UpdraftManager_Options::get_manager_dir();
				
				$filepath = $manager_dir.'/'.basename($_GET['filename']);
				
				if (!file_exists($filepath)) die('File not found.');
				
				$updraft_manager->get_plugin($_GET['slug'], self::get_user_id_for_licences());
				
				// Prevent the file being read into memory
				while (ob_get_level()) @ob_end_clean();
				
				header("Content-Length: ".filesize($filepath));
				header("Content-type: application/zip");
				header("Content-Disposition: attachment; filename=\"".basename($filepath)."\";");
				readfile($filepath);
				break;
			
			case 'entitlement_add':

				if (empty($_POST['howmany']) || !is_numeric($_POST['howmany']) || empty($_POST['slug']) || empty($_POST['userid']) || !is_numeric($_POST['userid'])) return;
				$expires = ($_POST['howmany'] < 0) ? -1 : strtotime("+ ".(int)$_POST['howmany']." months");

					$updraft_manager->get_plugin($_POST['slug'], self::get_user_id_for_licences());

	// 			$order_id="9900000000";
	// 			$itemnum=1;
	// 			$variation_id=0;
	// 			$i=0;

				// Need to make the 'unique' ID actually unique, if a second on is added...
	// 			$uniqid = sprintf("%010d", $order_id). sprintf("%03d",$itemnum).sprintf("%04d", $variation_id).sprintf("%03d", $i);

				$uniqid = "90".sprintf("%013d", time()).'00'.sprintf("%03d", rand(0, 999));

				// The filter can get all the information from _POST - no need to pass it
				$unlimited = apply_filters('updraftmanager_entitlement_add_unlimited', !empty($_POST['unlimited']));
				$site = $unlimited ? 'unlimited' : 'unclaimed';
				$desc = $unlimited ? __('Unlimited entitlement', 'simba-plugin-updates-manager') : __('Unused entitlement', 'simba-plugin-updates-manager');

				$key = empty($_POST['key']) ? 'all' : $_POST['key'];

				$user_id = (int) $_POST['userid'];
				
				$titlement = $updraft_manager->plugin->grant_user_addon_entitlement($uniqid."01", $key, $site, $desc, $user_id, $expires);

				if (is_array($titlement)) {
					echo json_encode(array(
						'success' => true,
						'entitlementsnow' => $this->return_user_plugin_entitlements($user_id, self::get_user_id_for_licences())
					));
				} else {
					echo json_encode(array(
						'success' => false,
					));
				}

			break;
			default:
				$event_not_found = true;
			break;
		}
		if (empty($event_not_found)) die;
	}

	/**
	 * Used by the WP filter updraftmanager_inuseonsites_final
	 *
	 * @param String  $in_use_on_sites
	 * @param Boolean $free
	 * @param Boolean $user_can_manage
	 * @param String  $key
	 * @param Object  $plugin
	 *
	 * @return String - filtered value
	 */
	public function updraftmanager_inuseonsites_final($in_use_on_sites, $free, $user_can_manage, $key, $plugin) {
		if (!$free && !empty($user_can_manage)) {
			$in_use_on_sites .= '<p><a href="" class="udmanager_entitlement_add">'.__('Add single entitlement', 'simba-plugin-updates-manager').'</a>';
			if (apply_filters('updraftmanager_show_add_unlimited_link', true, $plugin, $key, $user_can_manage)) {
				$in_use_on_sites .= ' | <a href="" class="udmanager_entitlement_add_unlimited">'.__('Add unlimited entitlement', 'simba-plugin-updates-manager').'</a></p>';
			}
		}
		return $in_use_on_sites;
	}

	/**
	 * WP filter user_row_actions
	 *
	 * @param $actions Array - list of actions
	 * @param $user	   WP_User - WordPress user whom the row is for
	 *
	 * @return Array - filtered value
	 */
	public function user_row_actions($actions, $user) {
		if (current_user_can($this->manage_permission('licences')))
		$actions[] = '<a href="'.admin_url('users.php?page=udmanager_manage_user&id='.$user->ID).'">'.apply_filters('udmanager_user_row_action_title', __('Plugin Entitlements', 'simba-plugin-updates-manager'), $user).'</a>';
		return $actions;
	}

	/**
	 * Runs upon the WP action admin_menu
	 */
	public function admin_menu() {
		$perm = $this->manage_permission('licences');
		add_submenu_page('users.php', __('', 'simba-plugin-updates-manager'), __('', 'simba-plugin-updates-manager'), $perm, 'udmanager_manage_user', array($this, 'manage_user_page'));
		parent::admin_menu();
	}

	public function manage_user_page() {
		if (empty($_GET['id']) || !is_numeric($_GET['id'])) return;

		$user_id = $_GET['id'];

		if (!current_user_can($this->manage_permission('licences'))) return;

		echo '<div style="clear: left;width:950px; float: left; margin-right:20px;">
		<h1>'.__('Simba Plugins Manager - User Entitlements', 'simba-plugin-updates-manager').'</h1>';

		$orders = apply_filters('udmanager_get_orders', array(), $user_id);

		$owner_user_id = self::get_user_id_for_licences();
		if (empty($owner_user_id)) return;

		$user = new WP_User($user_id);
		
		$user_link = current_user_can('edit_users') ? '<a href="'.admin_url('user-edit.php?user_id='.$user_id).'">'.$user_id.'</a>' : $user_id;

		echo '<h2>'.sprintf(__('User: %s (%s, id=%s)', 'simba-plugin-updates-manager'), htmlspecialchars($user->user_login), htmlspecialchars($user->user_email), $user_link).'</h2>';

		echo '<div class="wrap">';

		if (count($orders) > 0) {
			ksort($orders);
			echo "<p><strong>".__('Orders:', 'simba-plugin-updates-manager').'</strong> ';
			$first = true;
			foreach ($orders as $id => $info) {
				if (is_array($info)) {
					$url = $info['url'];
					$date = $info['date'];
				} else {
					// Old format was just the URL as a string
					$url = $info;
					$date = '';
				}
				if ($first) { $first = false; } else { echo " | "; }
				echo '<a href="'.$url.'">'.$id.'</a>';
				if ($date) echo ' ('.htmlspecialchars($date).')';
			}
			echo '</p>';
		}
		
		do_action('updraftmanager_manage_user_page_before_entitlements', $user_id, apply_filters('updraftmanager_manage_user_page_before_entitlements_prepare_additional_data', array(), $user_id));
		
		echo $this->return_user_plugin_entitlements($user_id, $owner_user_id);

		# TODO: Also display a link to the user's orders

		echo '</div></div>';

	}

	private function return_user_plugin_entitlements($user_id, $owner_user_id) {
		global $updraft_manager;
		# Loop over the plugins
		$plugins = $this->get_options();
		if (!is_array($plugins)) $plugins = array();

		# Get the user's entitlements

		$ret = '<div id="updraftmanager_user_plugin_entitlements" class="updraftmanager_user_plugin_entitlements">';

		$output_addons = '';
		$output_no_addons = '';
		
		foreach ($plugins as $plug) {
		
			if (empty($plug['slug'])) continue;
			$updraft_manager->get_plugin($plug['slug'], $owner_user_id);
			$p = $updraft_manager->plugin;
			$output = '<h3>'.__('Plugin:', 'udmanager').' '.htmlspecialchars($p->plugin_name).' - '.htmlspecialchars($p->plugin_descrip).'</h3>';

			$output .= '<p>';
			if ($p->free) {
				$output .= __('Free plugin - everyone has an automatic unlimited entitlement.', 'udmanager').'</p><hr>';
				continue;
			}
			
			$user_entitlements = $p->get_user_entitlements($user_id);
			
			$addons = $user_entitlements['addons'];
			$support = $user_entitlements['support'];
			
			$output .= $p->home_addons(false, 'all', true, $user_id);

			$output .= '</p><hr>';
			
			if (count($addons) > 0) {
				$output_addons .= $output;
			} else {
				$output_no_addons .= $output;
			}
		}

		$ret .= $output_addons.$output_no_addons.'</div>';

		return $ret;
	}

}
