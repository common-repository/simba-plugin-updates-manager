<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

class Updraft_Manager {

	// The only thing requiring this to be public is udmmanager_pclzip_name_as()
	public $plugin;

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action('plugins_loaded', array($this, 'load_translations'));
		add_action('init', array($this, 'action_init'), 99999);
		add_shortcode('udmanager', array($this, 'udmanager_shortcode'));
		add_shortcode('udmanager_changelog', array($this, 'udmanager_changelog_shortcode'));
		add_action('updraftmanager_weeklycron', array($this, 'daily_cron'));
		
		$this->schedule_delete_old_expired_licences();
		add_action('updraftmanager_delete_old_expired_licences', array($this, 'delete_old_expired_licences'));
		add_action('delete_user', array($this, 'delete_user'));		
		add_filter('wp_privacy_personal_data_erasers',   array($this, 'register_data_erasers'));
		add_filter('wp_privacy_personal_data_exporters', array($this, 'plugin_register_exporters'));
	}

	/**
	 * Registers the data erasers.
	 * Multime Entries can bee added by adding a seperate $erasers[] array.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array Modified erasers.
	 */
	public function register_data_erasers($erasers) {
		$erasers[] = array(
			'eraser_friendly_name' => __('Delete user', 'simba-plugin-updates-manager'),
			'callback'             => array($this, 'wp_privacy_personal_data_exporters'),
		);

		return $erasers;
	}

	/**
	 * Register the data exporter
	 *
	 * @param array $exporters
	 * @return array modified exporters
	 */
	public function plugin_register_exporters($exporters) {
		$exporters[] = array(
			'exporter_friendly_name' => __('Export user', 'simba-plugin-updates-manager'),
			'callback'               => array($this, 'wp_privacy_personal_data_exporters'),
		);
		return $exporters;
	}

	/**
	 * Runs upon the WP action plugins_loaded
	 */
	public function load_translations() {
		load_plugin_textdomain('simba-plugin-updates-manager', false, UDMANAGER_DIR.'/languages');
	}

	public static function get_entitlements_table() {
		global $wpdb;
		return apply_filters('updraftmanager_user_entitlements_table', $wpdb->prefix.'udmanager_user_entitlements');
	}

	public static function get_download_history_table() {
		global $wpdb;
		return apply_filters('updraftmanager_download_history_table', $wpdb->prefix.'udmanager_download_history');
	}

	public static function get_plugins_table() {
		global $wpdb;
		return apply_filters('updraftmanager_plugins_table', $wpdb->prefix.'udmanager_plugins');
	}

	public function activation() {
	
		if (false === wp_next_scheduled('updraftmanager_weeklycron')) wp_schedule_event(time()+86400, 'daily', 'updraftmanager_weeklycron');
		
		$this->schedule_delete_old_expired_licences();

		include_once(UDMANAGER_DIR.'/classes/updraftmanager-activation.php');
		
		Updraft_Manager_Activation::install();

	}
	
	/**
	 * Schedule cronjob for deleting entitlements that expired a long time ago
	 */
	private function schedule_delete_old_expired_licences() {
		if (false === wp_next_scheduled('updraftmanager_delete_old_expired_licences')) wp_schedule_event(time(), 'daily', 'updraftmanager_delete_old_expired_licences');
	}

	/**
	 * Produce a normalised version of a URL, useful for comparisons. This may produce a URL that does not actually reference the same location; its purpose is only to use in comparisons of two URLs that *both* go through this function.
	 *
	 * @param String $url - the URL
	 *
	 * @return String - normalised
	 */
	public static function normalise_url($url) {
		if (preg_match('/(\S+) - /', $url, $matches)) $url = $matches[1];
		$parsed_descrip_url = parse_url($url);
		// Strings that aren't really URLs can still get parsed into an array, but in that case, the only present key in the result is 'path'
		if (is_array($parsed_descrip_url) && count($parsed_descrip_url) > 1) {
			if (!empty($parsed_descrip_url['host']) && preg_match('/^www\./i', $parsed_descrip_url['host'], $matches)) $parsed_descrip_url['host'] = substr($parsed_descrip_url['host'], 4);
			$normalised_descrip_url = 'http://'.strtolower($parsed_descrip_url['host']);
			if (!empty($parsed_descrip_url['port'])) $normalised_descrip_url .= ':'.$parsed_descrip_url['port'];
			if (!empty($parsed_descrip_url['path'])) $normalised_descrip_url .= untrailingslashit($parsed_descrip_url['path']);
		} else {
			$normalised_descrip_url = untrailingslashit($url);
		}
		return $normalised_descrip_url;
	}
	
	public static function db_get_all_downloads_by_slug_and_filename($owner_user_id) {
		global $wpdb;
		$download_results = $wpdb->get_results($wpdb->prepare("SELECT slug, filename, daybegin, downloads FROM ".Updraft_Manager::get_download_history_table()." WHERE owner_user_id=%d", $owner_user_id));
		if (!is_array($download_results)) $download_results = array();

		$downloads = array();
		foreach ($download_results as $dl) {
			if (!isset($downloads[$dl->slug][$dl->filename])) $downloads[$dl->slug][$dl->filename] = 0;
			$downloads[$dl->slug][$dl->filename] += (int)$dl->downloads;
		}
		return $downloads;
	}

	/**
	 * Unschedule all cron events, When this plugin is deactivated
	 */
	public function deactivation() {
		wp_clear_scheduled_hook('updraftmanager_weeklycron');
		wp_clear_scheduled_hook('updraftmanager_delete_old_expired_licences');
	}

	/**
	 * Remove all expired SID tokens and all cached files that over a week old
	 */
	public function daily_cron() {
		$manager_dir = UpdraftManager_Options::get_manager_dir(true);
		$d = dir($manager_dir);
		if (empty($d)) return;
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry && is_dir($manager_dir.'/'.$entry.'/cache')) {
				Updraft_Manager::remove_files_by_age($manager_dir.'/'.$entry.'/cache', 3600*24*7);
			}
		}
		$d->close();
		$this->delete_expired_sid_tokens((int) get_site_option('udmanager_authenticated_sids_meta_offset', 0), 10000, true);
		//if (!empty(!$invalid_udmanager_authenticated_sids)) 
	}

	/**
	 * Use file modified time to recursively delete files older than a given age
	 *
	 * @param String  $file_or_dir Where the file is or in what directory the files are located
	 * @param Integer $max_age     How old are they (in seconds)
	 */
	public static function remove_files_by_age($file_or_dir, $max_age) {
		if (is_dir($file_or_dir)) {
			$d = dir($file_or_dir);
			if (empty($d)) return;
			while (false !== ($entry = $d->read())) {
				if ('.' === $entry || '..' === $entry) continue;
				Updraft_Manager::remove_files_by_age(trailingslashit($file_or_dir).$entry, $max_age);
			}
			$d->close();
		} elseif (is_file($file_or_dir) && filemtime($file_or_dir) < time() - $max_age) {
			@unlink($file_or_dir);
		}
	}

	/**
	 * Delete all expired SID tokens that are found in the user meta table, which is identified by 'udmanager_authenticated_sids' meta key and limit the number of rows being fetched to avoid exhausting memory
	 *
	 * @param Integer $offset    The index number from where to start fetching the rows
	 * @param Integer $row_count The number of rows to limit the query
	 * @param Boolean $cache_offset Whether to cache the offset in the database to avoid repeating the same rows over and over due to time out or out of memory
	 */
	public function delete_expired_sid_tokens($offset = 0, $row_count = 100, $cache_offset = false) {
		global $wpdb;
		while (NULL != $res = $wpdb->get_results($wpdb->prepare("SELECT user_id,meta_value from $wpdb->usermeta where meta_key = %s LIMIT %d,%d", 'udmanager_authenticated_sids', $offset, $row_count), 'ARRAY_A')) {
			$metas_deleted = 0;
			foreach ((array) $res as $row) {
				$changes = false;
				$udmanager_authenticated_sids = maybe_unserialize($row['meta_value']);
				foreach ((array) $udmanager_authenticated_sids as $user_id => $plugins) { // $user_id here refers to the WP user ID whom connected a plugin from the client site either by using their own or someone else's entitlements
					if (is_array($plugins) && empty($plugins)) $plugins[] = '';
					foreach ((array) $plugins as $plugin => $site_ids) {
						if (is_array($plugins) && empty($site_ids)) $site_ids[] = '';
						foreach ((array) $site_ids as $site_id => $data) {
							if (!is_array($data) || !isset($data['until']) || !is_integer($data['until']) || $data['until'] < time()) {
								if (!is_array($udmanager_authenticated_sids)) {
									unset($udmanager_authenticated_sids);
								} elseif (!is_array($udmanager_authenticated_sids[$user_id]) || empty($udmanager_authenticated_sids[$user_id])) {
									unset($udmanager_authenticated_sids[$user_id]);
									$changes = true;
								} elseif (!is_array($udmanager_authenticated_sids[$user_id][$plugin]) || empty($udmanager_authenticated_sids[$user_id][$plugin])) {
									unset($udmanager_authenticated_sids[$user_id][$plugin]);
									if (!is_array($udmanager_authenticated_sids[$user_id]) || empty($udmanager_authenticated_sids[$user_id])) unset($udmanager_authenticated_sids[$user_id]);
									$changes = true;
								} else {
									unset($udmanager_authenticated_sids[$user_id][$plugin][$site_id]);
									if (!is_array($udmanager_authenticated_sids[$user_id][$plugin]) || empty($udmanager_authenticated_sids[$user_id][$plugin])) unset($udmanager_authenticated_sids[$user_id][$plugin]);
									if (!is_array($udmanager_authenticated_sids[$user_id]) || empty($udmanager_authenticated_sids[$user_id])) unset($udmanager_authenticated_sids[$user_id]);
									$changes = true;
								}
							}
						}
					}
				}
				if (empty($udmanager_authenticated_sids)) {
					delete_user_meta($row['user_id'], 'udmanager_authenticated_sids');
					$metas_deleted++;
				} elseif ($changes) {
					update_user_meta($row['user_id'], 'udmanager_authenticated_sids', $udmanager_authenticated_sids);
				}
			}
			unset($res);
			$wpdb->flush();
			$offset += $row_count;
			$offset -= $metas_deleted;
			if ($cache_offset) update_site_option('udmanager_authenticated_sids_meta_offset', $offset);
		}
		if ($cache_offset) update_site_option('udmanager_authenticated_sids_meta_offset', 0);
	}
	
	/**
	 * Delete very old expired licences. Fired by updraftmanager_delete_old_expired_licences daily cron.
	 */
	public function delete_old_expired_licences() {
		global $wpdb;
		// It only needs to be approximate
		$time_ago_in_sec = 1.5*366*86400;
		$time_ago_in_sec = apply_filters('updraftmanager_delete_expired_licences_time_ago_in_sec',$time_ago_in_sec);

		// If no expiry is wanted
		if (false === $time_ago_in_sec) return;
		
		// -1 means "never expires". We used to sell those (until August 2013). Some people still have them.
		$sql = $wpdb->prepare('DELETE FROM '.self::get_entitlements_table().' WHERE expires > -1 AND expires <= %d', (time() - $time_ago_in_sec));
		$wpdb->query($sql);
	}

	/**
	 * See if the indicate directory exists and can be used as the management directory
	 *
	 * @param String $dir - directory path
	 *
	 * @return Boolean - success or failure state
	 */
	public function manager_dir_exists($dir) {
		if (is_dir($dir) && is_dir($dir).'/cache' && is_file($dir.'/.htaccess') && is_file($dir.'/index.php')) return true;
		if (!is_dir($dir) && !mkdir($dir, 0775, true)) return false;
		if (!is_dir($dir.'/cache') && !mkdir($dir.'/cache', 0775, true)) return false;
		if (!is_file($dir.'/index.php') && !file_put_contents($dir.'/index.php',"<html><body>No access.</body></html>")) return false;
		if (!is_file($dir.'/.htaccess') && !file_put_contents($dir.'/.htaccess','deny from all')) return false;
		return true;
	}

	/**
	 * Set the class's registered plugin object
	 *
	 * @param $slug String - the plugin slug
	 * @param $user_id Integer - user ID who owns the plugin
	 */
	public function get_plugin($slug, $user_id = false) {
		if (empty($user_id)) $user_id = apply_filters('updraftmanager_pluginuserid', false);
		if (empty($user_id) || !is_numeric($user_id)) return false;
		require_once(UDMANAGER_DIR.'/classes/updraftmanager-plugin.php');
		$plugin_object_class = apply_filters('updraftmanager_pluginobjectclass', 'Updraft_Manager_Plugin');
		$this->plugin = new $plugin_object_class($slug, $user_id);
	}

	/**
	 * Runs upon the WordPress 'init' action
	 */
	public function action_init() {
	
		require_once(UDMANAGER_DIR.'/classes/updraftmanager-activation.php');
		
		Updraft_Manager_Activation::check_updates();
	
		// No magic URL is required; the presence of the GET parameters is sufficient to indicate intent
		// Slug is not sent on all commands the legacy installs (e.g. connect)
		if (empty($_GET['udm_action']) || !is_string($_GET['udm_action'])) return;
		
		$action = $_GET['udm_action'];
		$slug = isset($_REQUEST['slug']) ? $_REQUEST['slug'] : apply_filters('updraftmanager_defaultslug', false);
		
		if (empty($slug)) return;
		
		try {
			$user_id = isset($_GET['muid']) ? $_GET['muid'] : apply_filters('updraftmanager_pluginuserid', false);
			if (empty($user_id) || !is_numeric($user_id)) die();
			$this->get_plugin($slug, $user_id);
		} catch (Exception $e) {
			if (apply_filters('updraftmanager_getplugin_logexception', true, $e, $slug, $user_id)) error_log($e->getMessage());
			// Use the format of Updraft_Manager_Plugin::send_response()
			echo json_encode(array('version' => 1, 'code' => 'INVALID', 'data' => $e->getMessage()));
			die();
		}
		
		do_action('updraftmanager_pinfo_'.$action, $slug);
		
		if (method_exists($this->plugin, 'pinfo_'.$action)) call_user_func(array($this->plugin, 'pinfo_'.$action));

		die();
	}
	
	/**
	 * Implements the [udmanager_changelog] shortcode
	 *
	 * @param Array $atts
	 *
	 * @uses $_GET['udmanager_changelog_slug']
	 *
	 * @return String - the shortcode output
	 */
	public function udmanager_changelog_shortcode($atts) {
	
		$atts = shortcode_atts(array(
			'slug' => '',
			'userid' => apply_filters('updraftmanager_pluginuserid', false),
			'maximum_sections' => 9999
		), $atts);
	
		$userid = $atts['userid'];
		$slug = $atts['slug'];
		$maximum_sections = $atts['maximum_sections'];
	
		if (false === $userid) return 'udmanager_changelog: No userid parameter provided';
		
		if ('' == $slug) {
			if (!empty($_GET['udmanager_changelog_slug']) && is_string($_GET['udmanager_changelog_slug'])) $slug = stripslashes($_GET['udmanager_changelog_slug']);
			if ('' == $slug) $slug = apply_filters('updraftmanager_defaultslug', '');
			if ('' == $slug) return 'udmanager_changelog: No slug parameter provided';
		}
		
		$plugins = UpdraftManager_Options::get_options($userid);
		
		try {
			if (false === ($plugin = $this->get_plugin($slug, $userid))) return __('udmanager_changelog shortcode: plugin not found', 'simba-plugin-updates-manager');
		} catch (Exception $e) {
			return __('udmanager_changelog shortcode: plugin not found', 'simba-plugin-updates-manager');
		}
		
		return $this->plugin->get_changelog($maximum_sections);
	
	}

	public function udmanager_shortcode($atts) {
		extract(shortcode_atts(array(
			'action' => 'addons',
			'slug' => '',
			'showaddons' => false,
			'showunpurchased' => "none",
			'userid' => apply_filters('updraftmanager_pluginuserid', false),
			'showlink' => apply_filters('updraftmanager_showlinkdefault', true)
		), $atts));
#			'slug' => apply_filters('updraftmanager_defaultslug', false),

		if ('true' === $showlink) $showlink = true;
		if ('true' === $showaddons) $showaddons = true;

		# TODO: When going fully multi-user... which userid to show? All of them?
		if (false === $userid) return '';

		if (!is_user_logged_in()) return __("You need to be logged in to see this information", 'updraftmanager');

		if (empty($slug)) {
			$plugins = UpdraftManager_Options::get_options($userid);
			$slugs = array();
			if (is_array($plugins)) {
				foreach ($plugins as $slug => $plug) {
					$slugs[] = $slug;
				}
			}
		} else {
			$slugs = array($slug);
		}

		$ret = '';

		foreach ($slugs as $slug) {

			$this->get_plugin($slug, $userid);
			switch ($action) {
				case 'addons':
					$ret .= $this->plugin->home_addons(
						($showlink === true) ? apply_filters('updraftmanager_showlinkdefault', true, $slug) : $showlink, $showunpurchased,
						($showaddons === false) ? apply_filters('updraftmanager_account_showaddons', false, $slug) : $showaddons
					);
					break;
				case 'support':
					$ret .= $this->plugin->home_support();
					break;
			}

		}

		return $ret;

	}

    /**
	 * Delete a user's licences when the user is deleted when
	 * NOT part of the privacy ereasers
	 * 
	 * @param integer $user_id The Id of user which are deleted
	 * @return integer|false number of rows updated, or false on error
	 */
	public function delete_user($user_id) {
		global $wpdb;
		return $wpdb->delete(self::get_entitlements_table(), array('user_id' => $user_id));
	}

	/**
	 * This is part of the wp_privacy_personal_data_erasers
	 * As this delete's a user's licences when the WP user is deleted
	 *
	 * @param string $email_address The email of user which are deleted
	 * @return void
	 */
	public function wp_privacy_personal_data_erasers($email_address) {

		global $wpdb;

		// Return if EMail empty
		if (empty($email_address)) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Get user ID by emails
		$user = get_user_by('email', $email_address);

		// Check to make sure a user has returned
		if ($user && $user->ID) {
			// Remove user licence
			$remove_user = $wpdb->delete(self::get_entitlements_table(), array('user_id' => $user->ID));

			// Return message
			$message = sprintf(__('User %s - ID:  %s licence\'s (%s) have been deleted.', 'updraftplus'), $email_address, $user->ID, $remove_user);
			$items_removed = true;
		} else {
			$message = sprintf(__('User %s Not found.', 'updraftplus'), $email_address);
			$items_removed = false;
		}

		// Return once complete
		return array(
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => $message,
			'done'           => true,
		);
	}

	public function wp_privacy_personal_data_exporters($email_address) {
		// Get premium
		global $updraft_manager_premium;

		// Get user ID by emails
		$user = get_user_by('email', $email_address);

		$export_items = array();

		// Check to make sure a user has returned
		if ($user && $user->ID) {
			// Get user licence entitlements
			$user_licence_entitlements = $updraft_manager_premium->db_get_all_entitlements($user->ID, date('Y-M-d'));

			// need to loop around the response and create an array of data to be sent back in the response
			foreach($user_licence_entitlements as $user_licence_entitlement) {
				// Unserialize the meta before adding it to the array
				$meta_info = maybe_unserialize($user_licence_entitlement['meta']);

				// Add this group of items to the exporters data array.
				$export_items[] = array(
					'group_id'    => "user-licence-entitlements",
					'group_label' => __('User licence entitlements', 'simba-plugin-updates-manager'),
					'item_id'     => "user-licence-entitlements-{$user->ID}",
					'data'        => 				$data = array(
						array(
							'name' => __('Slug', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['slug']
						),
						array(
							'name' => __('Type', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['type']
						),
						array(
							'name' => __('Key', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['key']
						),
						array(
							'name' => __('Site', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['site']
						),
						array(
							'name' => __('Site description', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['sitedescription']
						),
						array(
							'name' => __('Status', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['status']
						),
						array(
							'name' => __('Expires (GMT)', 'simba-plugin-updates-manager'),
							'value' => gmdate('Y-m-d H:i:s', $user_licence_entitlement['expires'])
						),
						array(
							'name' => __('URL', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['url']
						),
						array(
							'name' => __('URL normalised', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['url_normalised']
						),
						array(
							'name' => __('Last check-in', 'simba-plugin-updates-manager'),
							'value' => $user_licence_entitlement['lastcheckin']
						),
						array(
							'name' => __('Other associated data', 'simba-plugin-updates-manager'),
							'value' => print_r($meta_info, true)
						)
					)
				);
			}

			$complete = true;
		} else {
			$export_items[] = array(
				'group_id'    => "user-licence-entitlements",
				'group_label' => __('User licence entitlements', 'simba-plugin-updates-manager'),
				'item_id'     => "user-licence-entitlements-{$user->ID}",
				'data'        => array(
					'name' => __('Not Found', 'simba-plugin-updates-manager'),
					'value' => 'User Not Found'
				)
			);
			$complete = false;
		}

		// Return once completed
		return array(
			'data' => $export_items,
			'done' => $complete,
		);
	}

	/**
	 * Adds a comment to a ZIP archive file using the ZipArchive class.
	 *
	 * This function checks if the ZipArchive class is available and then opens
	 * the specified ZIP archive file for writing. It sets the archive comment
	 * to the provided comment text. If the comment is an array, it is first
	 * converted to JSON format before setting as the archive comment.
	 *
	 * @param string $filename The path to the ZIP archive file.
	 * @param string|array $comment The comment text to be added to the ZIP archive.
	 *                       If it's an array, it will be converted to JSON format.
	 *
	 * @return void This function does not return a value.
	 */
	public static function add_comment_to_zip($filename, $comment) {
		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive;
			$res = $zip->open($filename, ZipArchive::CREATE);
			if (true === $res) {
				$comment = apply_filters('updraftmanager_add_comment_to_zip', $comment, $filename);
				if (is_array($comment)) $comment = json_encode($comment);
				if (null === $comment) {
					error_log("Updraft_Manager::add_comment_to_zip(): JSON conversion failed for input=".serialize($comment));
				} else {
					$zip->setArchiveComment($comment);
				}
				$zip->close();
			} else {
				error_log("Updraft_Manager::add_comment_to_zip(): ZipArchive::open() failed for file=$filename");
			}
		}
	}
}
