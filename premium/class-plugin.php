<?php

if (!defined('UDMANAGER_DIR')) die('No direct access');

class Updraft_Manager_Plugin_Premium extends Updraft_Manager_Plugin {

	// @var String
	private $entitlements_table;
	
	private $authenticated_via_sid = false;

	/**
	 * Constructor
	 *
	 * @param String $slug
	 * @param Integer $uid
	 */
	public function __construct($slug, $uid) {
		add_filter('updraftmanager_plugin_deliverzip_cachefile', array($this, 'deliverzip_cachefile'), 10, 5);
		add_filter('updraftmanager_plugin_addonbox_shopurl', array($this, 'addonbox_shopurl'), 10, 2);
		parent::__construct($slug, $uid);
		$this->free = $this->plugin['freeplugin'];
		$downloadable_base_plugin = $this->free ? true : !empty($this->plugin['addonsdir']);
		$this->downloadable_base_plugin = apply_filters('updraftmanager_downloadable_base_plugin', $downloadable_base_plugin, $this);
		$this->entitlements_table = Updraft_Manager::get_entitlements_table();
	}
	
	/**
	 * Enqueue the user management script from premium/usermanage.js if it is not already enqueued
	 *
	 * @param Integer $user_id - the user ID to use
	 */
	protected function enqueue_usermanage_script($user_id) {
		static $enqueued_version = false;
		if ($enqueued_version) return;
		$enqueued_version = (defined('WP_DEBUG') && WP_DEBUG) ? time() : UDMANAGER_VERSION;
		wp_enqueue_script('updraftmanager-usermanage-js', UDMANAGER_URL.'/premium/usermanage.js', array('jquery'), $enqueued_version);
		wp_enqueue_script('jquery-blockui', UDMANAGER_URL.'/js/jquery.blockui.js', array('jquery'), '2.66.0');
		UpdraftManager_Options_Extended::localize_updraftmanagerlionp($user_id, 'updraftmanager-usermanage-js');
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		remove_filter('updraftmanager_plugin_deliverzip_cachefile', array($this, 'deliverzip_cachefile'), 10, 5);
		remove_filter('updraftmanager_plugin_addonbox_shopurl', array($this, 'addonbox_shopurl'), 10, 2);
	}

	/**
	 * Process the 'connect' command. Input parameters are in $_POST
	 *
	 * @uses self::rpc_connect()
	 */
	public function pinfo_connect() {
		// Set this header to prevent content compressors mangling the contents (e.g. assuming it is HTML and stripping double spaces)
		@header('Content-Type: application/octet-stream');
		// Further parameters: user, phash
		
		$format = empty($_POST['f']) ? 1 : (int)$_POST['f'];
		
		if (empty($_POST['e']) || (empty($_POST['p']) && empty($_POST['sid']))) {
			$response_array = apply_filters('updraftmanager_rpc_response', array('mothership' => 'thatsus', 'loggedin' => 'authfailed', 'message' => 'You provided invalid login details'));
			echo (2 == $format) ? json_encode($response_array) : serialize($response_array);
			return;
		}

		$sid = isset($_POST['sid']) ? $_POST['sid'] : '';
		$url = isset($_POST['su']) ? @base64_decode($_POST['su']) : false;
		$connect_blob = $this->rpc_connect(trim($_POST['e']), base64_decode($_POST['p']), $sid, $url);
		
		echo (2 == $format) ? json_encode($connect_blob) : serialize($connect_blob);

	}

	/**
	 * Process the incoming 'claimaddon' command. Uses the keys e, p, sid, key, sn, su from $_POST
	 */
	public function pinfo_claimaddon() {

		if ($this->free) { $this->send_response('OK'); return; }

		$email = isset($_POST['e']) ? strtolower(trim($_POST['e'])) : '';
		$pass = isset($_POST['p']) ? @base64_decode($_POST['p']) : '';

		if (!isset($_POST['sid']) || !isset($_POST['sn']) || !isset($_POST['su'])) {
			$this->send_response('INVALID');
			return;
		}

		$sid = (string) $_POST['sid'];

		if (empty($email) || false == ($user = get_user_by('email', $email))) {
			$this->send_response('BADAUTH', 'invaliduser', __('Your email address was not recognised.', 'simba-plugin-updates-manager'));
			return;
		}

		if (false == ($user = $this->authenticate($email, $pass, $sid))) {
			$this->send_response('BADAUTH', 'invalidpassword', sprintf(__('Your email address was valid, but your %s was incorrect.', 'simba-plugin-updates-manager'), apply_filters('updraftmanager_password_description', __('password', 'simba-plugin-updates-manager'), $this, $_POST)));
			return;
		}
		
		$key = !empty($_POST['key']) ? $_POST['key'] : 'all';
		$site_url = base64_decode($_POST['su']);
		
		$claimed = $this->claim_addon_entitlement($user->ID, $key, $sid, base64_decode($_POST['sn']), $site_url);
		
		if (true !== $claimed) {
		
			$this->send_response('ERR');
			
		} else {
		
			$ssl = !empty($_GET['ssl']) || (!isset($_GET['ssl']) && is_ssl());
			
			$si = isset($_POST['si2']) ? @json_decode(stripslashes($_POST['si2']), true) : array();
		
			$data = array();
		
			$installed_version = isset($_REQUEST['installed_version']) ? $_REQUEST['installed_version'] : null;
		
			// Add in updates information, to spare the client needing another round-trip for that
			$plugin_info = $this->get_plugin_info($sid, $email, $ssl, $site_url, $si, $installed_version);
			if (is_array($plugin_info) && !empty($plugin_info)) $data['plugin_info'] = $plugin_info;
		
			$data = apply_filters('udmanager_claimaddon_response_data', $data, $key, $user, $si, $plugin_info, $sid, $site_url);
			
			$this->send_response('OK', $data);
		}

	}

	/**
	 * Find out if there is any unused purchase matching that key
	 * If so, then claim it. Remember that some grants are unlimited, and should generate a fresh entitlement if granted.
	 *
	 * @calls self::db_set_last_checkin()
	 *
	 * @param Integer $user_id	 - the WP user a claim is being made for
	 * @param String  $key		 - the add-on being claimed (could be 'all')
	 * @param String  $sid		 - the site being claimed on
	 * @param String  $site_name - site descriptor
	 * @param String  $site_url	 - the site URL
	 *
	 * @return Boolean - whether the claim was successful or not
	 */
	public function claim_addon_entitlement($user_id, $key, $sid, $site_name, $site_url) {

		if (false == apply_filters('udmanager_claim_addon_entitlement_allow', true, $user_id, $key, $sid, $site_name, $site_url)) return false;
	
		$addon_entitlements = $this->get_user_addon_entitlements($user_id, false, true);
		if (!is_array($addon_entitlements)) return false;

		$expire_date = -1;

		// First parse - if this site already has this entitlement, then do nothing (except updating the details)
		foreach ($addon_entitlements as $ekey => $titlement) {
			// Keys: site (sid, unclaimed, unlimited), sitedescription, key, status

			$normalised_descrip_url = Updraft_Manager::normalise_url($titlement['sitedescription']);

			if ($titlement['key'] == $key && ($titlement['site'] == $sid || Updraft_Manager::normalise_url($site_url) == $normalised_descrip_url)) {
				// Update site details
				$this->grant_user_addon_entitlement($ekey, $key, $sid, "$site_url - $site_name", $user_id);
				
				$this->db_set_last_checkin($sid, $user_id, time(), $site_url);
				
				return true;
			} elseif ($titlement['key'] == $key && ( $titlement['site'] == 'unclaimed' || $titlement['site'] == 'unlimited')) {
				if ($titlement['site'] == 'unlimited') {
					if (isset($titlement['expires'])) $expire_date = $titlement['expires'];
					$i = 1;
					while (isset($addon_entitlements[$ekey.sprintf("%03d", $i)])) {
						$i++;
					}
					$slot_available = $ekey.sprintf("%03d", $i);
				} else {
					$slot_available = $ekey;
				}
			}
		}

		// Grant entitlement
		if (isset($slot_available)) {
			// Try to minimise character set issues
			// $site_description = htmlentities("$site_url - $site_name");
			$site_description = htmlentities($site_url);
			$titlement = $this->grant_user_addon_entitlement($slot_available, $key, $sid, $site_description, $user_id, $expire_date);
			do_action('updraftmanager_claimed_entitlement', $key, $titlement, $slot_available, $addon_entitlements, $user_id);
			$this->db_set_last_checkin($sid, $user_id, time(), $site_url);
			return true;
		}

		return false;

	}

	public function pinfo_releaseaddon() {

		if ($this->free) { $this->send_response('OK'); return; }

		$email = isset($_POST['e']) ? strtolower(trim($_POST['e'])) : '';

		if (empty($_POST['sid'])) {
			$this->send_response('INVALID');
			return;
		}
		
		if (empty($email)) $this->send_response('BADAUTH', 'invaliduser', __('No email address was supplied.', 'simba-plugin-updates-manager'));

		if ($user = get_user_by('email', $email)) {

			$authed = false;

			$addon_entitlements = $this->get_user_addon_entitlements($user->ID);
			if (!is_array($addon_entitlements)) $addon_entitlements = array();

			$key = !empty($_POST['key']) ? $_POST['key'] : 'all';

			$found_one = false;

			foreach ($addon_entitlements as $eid => $titlement) {

				if (empty($titlement['key']) || $titlement['key'] != $key) continue;
				if (empty($titlement['site']) || $titlement['site'] != $_POST['sid']) continue;

				$addon_entitlements[$eid]['site'] = 'unclaimed';
				$addon_entitlements[$eid]['sitedescription'] = __('Unused entitlement', 'simba-plugin-updates-manager');

				$this->db_reset_user_entitlement($eid, $user->ID);
// 				$this->save_user_entitlements($user->ID, $addon_entitlements);

				$found_one = true;

			}

// 			if ($found_one) {
				$this->send_response('OK');
// 			} else {
// 				$this->send_response('ERR');
// 			}
			die;

		} else {
			$this->send_response('BADAUTH', 'invaliduser', __('Your email address was not recognised.', 'simba-plugin-updates-manager'));
		}
	}

	public function deliverzip_cachefile($cache_file, $keys, $manager_dir, $version, $versionsuffix) {

		sort($keys);
		$key_hashb = '';
		$rev = 0;
		$have_all = false;
		foreach ($keys as $key) {
			$key_hashb .= $key.'-'; 
			$rev++;
			if ('all' == $key) $have_all = true;
		}

		if ($this->free || empty($this->addonsdir)) {
			return $cache_file;
		} else {

			$versionsuffix = $version.'.'.$rev;
			$unpacked_dir = $manager_dir.'/_up_'.basename($cache_file);
			if (!is_dir($unpacked_dir)) return false;
			if (empty($version)) return false;

			$cache_file = $manager_dir.'/cache/'.$this->slug.'-'.$version.'-'.substr(md5($key_hashb),0,20).'.zip';

			if (!file_exists($cache_file)) {

				// To cope with 'all', we enumerate what's available, not what's required
				$available_addons = $this->scan_addons($unpacked_dir);

				// Will produce the rev number based on the number of add-ons, plus 1
				if ($have_all) $rev = 1 + count($available_addons);

				$make_zip_from = $this->get_zip_files($unpacked_dir.'/'.$this->slug, $this->pluginfile);

				$tmp_file = $manager_dir.'/cache/'.md5(rand().time());

				if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');
				$zip_object = new PclZip($tmp_file);

				// Add in the plugin file itself (first), with appropriately amended version number
				$plug_contents = "";
				$version_amended = false;
				if ($fh = fopen($unpacked_dir.'/'.$this->slug.'/'.$this->pluginfile, 'r')) {
					while ($line = fgets($fh)) {
						if ($version_amended == false && preg_match("/^Version: ([\d\.]+)$/", $line, $matches)) {
							$plug_contents .= "Version: ".$matches[1].".$rev\n";
							$version_amended = true;
						} else {
							$plug_contents .= $line;
						}
					}
					fclose($fh);
				}

				file_put_contents($tmp_file.'.plug', $plug_contents);
				$zip_object->create($tmp_file.'.plug', PCLZIP_CB_PRE_ADD, 'udmmanager_pclzip_name_as');
// 				$zip_object->add($tmp_file.'.plug', PCLZIP_CB_PRE_ADD, 'udmmanager_pclzip_name_as');
				unlink($tmp_file.'.plug');

				// Now, add in the other files
// 				$zipcode = $zip_object->create($make_zip_from, PCLZIP_OPT_REMOVE_PATH, $unpacked_dir);
				$zipcode = $zip_object->add($make_zip_from, PCLZIP_OPT_REMOVE_PATH, $unpacked_dir);

				if ($zipcode == 0) return new WP_Error('pcl_zip_failed', "PclZip Error: ".$zip_object->errorName().": ".$zip_object->errorInfo());

				foreach ($available_addons as $key => $akey) {

					// Construct and save the zip

					// Add add-ons
					if ($have_all || in_array($key, $keys)) {
						$addon_info = $this->get_addon_info($unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir.'/'.$key.'.php');
						if (!empty($addon_info['include'])) {
							// Redundant, since it will be in there already (it's only WordPress.Org SVN that it's not on)
							//$zip_object->add(UPDRAFTPLUS_DIR.'/'.$addon_info['include'], PCLZIP_OPT_REMOVE_PATH, $unpacked_dir);
						}
						$ret_code = $zip_object->add($unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir.'/'.$key.'.php', PCLZIP_OPT_REMOVE_PATH, $unpacked_dir);

						if ($ret_code == 0) {
							return new WP_Error('pcl_zip_failed', "PclZip Error: ".$zip_object->errorName().": ".$zip_object->errorInfo());
						}
					}

				}

				rename($tmp_file, $cache_file);

			}

		}

		$plugin_dir = dir($unpacked_dir.'/'.$this->slug);
		$found_plugin = false;
		if (!function_exists('get_plugin_data')) require(ABSPATH.'wp-admin/includes/plugin.php');
		while (false !== ($entry = $plugin_dir->read())) {
			if (is_file($unpacked_dir.'/'.$this->slug.'/'.$entry) && '.php' == substr($entry, -4)) {
				$plugin_data = get_plugin_data($unpacked_dir.'/'.$this->slug.'/'.$entry, false, false ); //Do not apply markup/translate as it'll be cached.
				if (!empty($plugin_data['Name']) && !empty($plugin_data['Version'])) $found_plugin = array('file' => $entry, 'data' => $plugin_data);
			}
		}
		$plugin_dir->close();

		$comment = array(
			'site' => home_url(),
			'name' => $found_plugin['data']['Name'],
			'file' => $found_plugin['file'],
			'slug' => $this->slug,
			'version' => $found_plugin['data']['Version'],
			'date' => current_time('mysql', true)
		);

		Updraft_Manager::add_comment_to_zip($cache_file, $comment);

		return $cache_file;

	}

	// Returns an array of all top-level files, except for 'addons' and <plugin>.php
	protected function get_zip_files($dir, $pluginfile) {
		$file_list = array();
		if ($handle = opendir($dir)) {
			while (false !== ($entry = readdir($handle))) {
				if ('.' != $entry && '..' != $entry && $entry != $this->addonsdir && $entry != $pluginfile) {
					$file_list[] = $dir.'/'.$entry;
				}
			}
		}
		return $file_list;
	}

	/**
	 * Connection request.
	 *
	 * @param String		 $email - user's email address
	 * @param String $pass	 user's password
	 * @param String $sid	 site ID
	 * @param Boolean|String $connecting_url - if set to a string, then it is the site URL that is connecting; this allows filtering of the response to not include details about other sites
	 *
	 * @return Array - the response
	 */
	public function rpc_connect($email, $pass, $sid = '', $connecting_url = false) {

		if (!empty($email) && $user = get_user_by('email', $email)) {
			// The check with addslashes() is because of a WP bug. The thing hashed and stored is actually the addslashes-ed version normally - but WooCommerce stores the version without addslashes. There are various WP bugs in this area (check my email of 20th Oct 2014)

			$authed = false;

			if (!empty($pass)) {
				if ($pass != addslashes($pass)) {
					$also_check = addslashes($pass);
				}
				if (wp_check_password($pass, $user->data->user_pass, $user->ID) || (isset($also_check) && wp_check_password($also_check, $user->data->user_pass, $user->ID))) {
					$authed = true;
				}
			} elseif (!empty($sid)) {
				$entitlements = $this->get_user_addon_entitlements($user->ID, $sid, false);
				// See if they had the SID of an authorised site
				if (is_array($entitlements) && count($entitlements) > 0) $authed = true;
			}

			if ($authed) {

				// What is the user entitled to?
				// Only if entitled do we bother passing a non-default version

				$user_entitlements = $this->get_user_entitlements($user->ID);
				$user_addons = $user_entitlements['addons'];
				$user_support = $user_entitlements['support'];
				
				$download = $this->calculate_download();

				if ($sid && !empty($this->addonsdir)) $this->mark_sid_as_authenticated_for_user($user->ID, $sid);

				// Don't send back the info on which sites addons are deployed upon, except to allow for matching with the connecting site
				if ($connecting_url && is_array($user_addons)) {
					$normalised_connecting_url = Updraft_Manager::normalise_url($connecting_url);
					foreach ($user_addons as $akey => $addon) {
						if ($normalised_connecting_url != Updraft_Manager::normalise_url($addon['sitedescription'])) {
							$user_addons[$akey]['sitedescription'] = '(snip)';
						}
					}
				}
				
				$response = array(
					'loggedin' => 'connected',
					'message' => 'Welcome',
					'myaddons' => $user_addons,
					'availableaddons' => (!empty($this->addonsdir) && !$this->free && !empty($download['filename'])) ? $this->get_available_addons(UpdraftManager_Options::get_manager_dir(false, $this->uid).'/_up_'.$download['filename'], $download) : array(),
					'support' => $user_support
				);

			} else {
				$response = array('loggedin' => 'authfailed', 'authproblem' => 'invalidpassword');
			}

		} else {
			// New Oct 2014: provide more information (WordPress' official attitude is that confirmation of valid usernames is not a security problem - and this can be done via direct login on the website, so nothing is gained by not clearly stating the reason for login failure here).
			$response = array('loggedin' => 'authfailed', 'authproblem' => 'invaliduser');
		}
		
		if (!isset($response)) $response = array('loggedin' => 'authfailed');
		
		$response['mothership'] = 'thatsus';
		
		return apply_filters('updraftmanager_rpc_response', $response);
		
	}

	// This is only called when there's an addons dir. It basically enables the sid to be treated as an authorised token - and thus atones for the fact that the original protocol design was based upon passwords instead of tokens.
	private function mark_sid_as_authenticated_for_user($user_id, $sid) {
		$currently_authenticated = get_user_meta($user_id, 'udmanager_authenticated_sids', true);
		if (!is_array($currently_authenticated)) $currently_authenticated = array();
		// Prune sites
		// If for any reason the array format of the 'udmanager_authenticated_sids' meta is changed, please also consider looking at the Updraft_Manager::delete_expired_sid_tokens() as the method also deals with the 'udmanager_authenticated_sids' array format
		if (empty($currently_authenticated[$this->uid])) $currently_authenticated[$this->uid] = array();
		if (empty($currently_authenticated[$this->uid][$this->slug])) $currently_authenticated[$this->uid][$this->slug] = array();

		foreach ($currently_authenticated[$this->uid][$this->slug] as $k_sid => $site) {
			if (!is_array($site) || !isset($site['until']) || !is_numeric($site['until']) || time() > $site['until']) {
				unset($currently_authenticated[$this->uid][$this->slug][$k_sid]);
				continue;
			}
		}
		// Yes, the sid is there twice; allowing the possibility for future flexibility (rather than just a simple sid => time array).
		$currently_authenticated[$this->uid][$this->slug][$sid] = array('sid' => $sid, 'until' => time() + 86400*7);
		update_user_meta($user_id, 'udmanager_authenticated_sids', $currently_authenticated);
	}

	// This is an intermediate measure, to funnel all saves through here; at the next stage, we want to convert some calls to separate methods such as "delete_user_entitlement", etc. that calls it save_user_entitlements; and then later convert to licences stored in a separate table and convert the various methods to just write directly to the table.
// 	public function save_user_entitlements($user_id, $addon_entitlements, $type = 'addons') {
// 		$prefix = ('support' == $type) ? $this->entitlement_meta_support_prefix : $this->entitlement_meta_addons_prefix;
// 		update_user_meta($user_id, $prefix.$this->slug, $addon_entitlements);
// 	}

	public function db_delete_user_entitlement($entitlement_id, $user_id, $type='addons') {
		global $wpdb;
		// Returns the number of rows deleted, or false on an error
		return $wpdb->delete($this->entitlements_table, array(
			'slug' => $this->slug,
			'owner_user_id' => $this->uid,
			'user_id' => $user_id,
			'entitlement_id' => $entitlement_id,
			'type' => $type
		));
	}

	public function db_set_user_entitlement($entitlement_id, $user_id, $titlement, $method = 'insert', $type = 'addons') {

		if ('support' == $type) {
			$t = array(
				'slug' => $this->slug,
				'owner_user_id' => $this->uid,
				'user_id' => $user_id,
				'entitlement_id' => $entitlement_id,
				'type' => $type,
				'lastcheckin' => 0,
				'expires' => $titlement['expire_date'],
				'site' => '',
				'sitedescription' => $titlement['response_time'],
				'url' => '',
				'url_normalised' => '',
				'status' => empty($titlement['status']) ? '' : $titlement['status'],
				'key' => $titlement['support_type'],
				'meta' => ''
			);
		} else {
			$t = array(
				'slug' => $this->slug,
				'owner_user_id' => $this->uid,
				'user_id' => $user_id,
				'entitlement_id' => $entitlement_id,
				'type' => $type,
				'lastcheckin' => 0,
				'url' => '',
				'url_normalised' => '',
				'meta' => '',
				'site' => $titlement['site'],
				'status' => empty($titlement['status']) ? '' : $titlement['status'],
				'sitedescription' => empty($titlement['sitedescription']) ? '' : $titlement['sitedescription'],
				'key' => empty($titlement['key']) ? 'all' : $titlement['key'],
				'expires' => empty($titlement['expires']) ? -1 : $titlement['expires'],
				'meta' => ''
			);
		}

		$ignore_keys = array('site', 'status', 'sitedescription', 'key', 'expires', 'expire_date', 'url', 'url_normalised', 'lastcheckin');
		$meta = array();
		foreach ($titlement as $k => $v) {
			if (in_array($k, $ignore_keys)) continue;
			$meta[$k] = $v;
		}
		if (!empty($meta)) $t['meta'] = serialize($meta);

		global $wpdb;

		if ('insert' == $method) {
			return $wpdb->insert($this->entitlements_table, $t);
		} else {
			return $wpdb->update($this->entitlements_table, $t, array(
				'slug' => $this->slug,
				'owner_user_id' => $this->uid,
				'user_id' => $user_id,
				'entitlement_id' => $entitlement_id,
				'type' => $type
			));
		}
	}

	public function db_reset_user_entitlement($entitlement_id, $user_id) {
		global $wpdb;
		// Returns the number of rows affected, or false on an error
		return $wpdb->update($this->entitlements_table,
			array(
				'site' => 'unclaimed',
				'lastcheckin' => 0,
				'url' => '',
				'url_normalised' => '',
				'sitedescription' => __('Unused entitlement', 'simba-plugin-updates-manager')
			),
			array(
				'slug' => $this->slug,
				'owner_user_id' => $this->uid,
				'user_id' => $user_id,
				'entitlement_id' => $entitlement_id
			)
		);
	}

	/**
	 * Store the last check-in time in the database
	 *
	 * @param Integer $site_id
	 * @param Integer $user_id
	 * @param Integer $time
	 * @param String  $site_url
	 *
	 * @return Void
	 */
	protected function db_set_last_checkin($site_id, $user_id, $time, $site_url = '') {
		global $wpdb;
		
		$url_normalised = Updraft_Manager::normalise_url($site_url);
		
		$sql = $wpdb->prepare(
			'UPDATE '.$this->entitlements_table.' SET
			lastcheckin=%d,
			url=%s,
			url_normalised=%s
			WHERE
			user_id=%d
			AND owner_user_id=%d
			AND slug=%s
			AND site=%s',
			$time, $site_url, $url_normalised, $user_id, $this->uid, $this->slug, $site_id
		);
		
		$wpdb->query($sql);
		
		// WPDB::update() results in an extra "SHOW FULL COLUMNS FROM" SQL call, which we wish to avoid for better performance
		/*
		return $wpdb->update($this->entitlements_table,
			array(
				'lastcheckin' => $time,
				'url' => $site_url,
				'url_normalised' => Updraft_Manager::normalise_url($site_url)
			),
			array(
				'slug' => $this->slug,
				'owner_user_id' => $this->uid,
				'user_id' => $user_id,
				'site' => $site_id
			)
		);
		*/
	}

	/**
	 * Get the list of last check-ins for this plugin.
	 *
	 * @param Integer $user_id - the user whose check-ins are being looked up
	 * @param String|Null $entitlement_id - if set, only return this entitlement
	 *
	 * @return Array - list of results
	 */
	public function db_get_last_checkins($user_id, $entitlement_id = null) {
		global $wpdb;

		$sql = $wpdb->prepare("SELECT site, url, lastcheckin FROM ".$this->entitlements_table." WHERE owner_user_id=%d AND user_id=%d AND slug=%s", $this->uid, $user_id, $this->slug);
		
		if (null !== $entitlement_id) $sql .= " AND entitlement_id='".esc_sql($entitlement_id)."'";

		$last_checkins_result = $wpdb->get_results($sql);
		if (!is_array($last_checkins_result)) $last_checkins_result = array();

		$last_checkins = array();

		foreach ($last_checkins_result as $checkin) {
			if (empty($checkin->site) || empty($checkin->lastcheckin) || 'unlimited' == $checkin->site || 'unclaimed' == $checkin->site) continue;
			$last_checkins[$checkin->site] = array('site_url' => $checkin->url, 'time' => $checkin->lastcheckin);
		}

		return $last_checkins;
	}


	/**
	 * Get the user's addon entitlements
	 *
	 * @param Boolean|Integer $user_id		 - the WP user; if false, then use the current logged-in user
	 * @param Boolean|String  $sid			 - the site identifier
	 * @param Boolean		  $prune_expired - whether to exclude expired entitlements, or not
	 *
	 * @return Array|String - a list of entitlements, or (string)"expired" if all are expired
	 */
	public function get_user_addon_entitlements($user_id = false, $sid = false, $prune_expired = false) {

		if ($this->free) return array();

		$user_id = $this->parse_id($user_id);
		if (!$user_id) return array();

		$user_result = $this->get_user_entitlements_from_db($user_id);

		$user_addons = array();
		if (!empty($user_result)) {
			foreach ($user_result as $result) {
			
				// get_user_entitlements_from_db gets all types (for speed/minimising SQL queries); so, we ened to filter
				if ('addons' != $result->type) continue;
			
				$user_addons[$result->entitlement_id] = array(
					'site' => $result->site,
					'sitedescription' => $result->sitedescription,
					'key' => $result->key,
					'status' => $result->status,
					'expires' => $result->expires,
					'url_normalised' => $result->url_normalised
				);
				if (!empty($result->meta)) {
					$meta = unserialize($result->meta);
					if (is_array($meta) && isset($meta['renewal_orders'])) {
						$user_addons[$result->entitlement_id]['renewal_orders'] = $meta['renewal_orders'];
					}
				}
			}
		}

		if (!is_array($user_addons)) $user_addons = array();

		// If there was no sid then there is nothing more to do
		if ($sid) {
			$final_addons = array();
			foreach ($user_addons as $uid => $addon) {
				if (isset($addon['site']) && $addon['site'] == $sid && $sid != 'unclaimed' && $sid != 'all') {
					$final_addons[$uid] = $addon;
				}
			}
		} else {
			$final_addons = $user_addons;
		}

		$final_addons = apply_filters('updraftmanager_get_user_addons_pre_prune', $final_addons, $user_addons, $sid, $this->addonsdir, $user_id);

		if ($prune_expired) {
			$pre_prune_num = count($final_addons);
			foreach ($final_addons as $ind => $addon) {
				if (is_array($addon) && !empty($addon['expires']) && time() >= $addon['expires'] && $addon['expires'] > 0) unset($final_addons[$ind]);
			}
			if ($pre_prune_num > 0 && 0 == count($final_addons)) {
				$final_addons = 'expired';
			}
		}

		return $final_addons;
	}

	/**
	 * Get the user's entitlements - a cacheing function to reduce SQL calls
	 *
	 * @param Integer|Boolean $user_id - the WP user ID
	 *
	 * @return Array|String
	 */
	private function get_user_entitlements_from_db($user_id) {
	
		$key = $this->uid.'_'.$user_id.'_'.$this->slug;
	
		static $user_result = array();
		if (isset($user_result[$key])) return $user_result[$key];
	
		global $wpdb;
		$result = $wpdb->get_results($wpdb->prepare("SELECT * FROM ".$this->entitlements_table." WHERE owner_user_id=%d AND user_id=%d AND slug=%s", $this->uid, $user_id, $this->slug));
		
		// Exempt admin area; plus some slightly hacky assumptions about when cacheing is appropriate
		if (!is_admin() && (!function_exists('is_single') || !is_single()) && (!function_exists('in_the_loop') || !in_the_loop()) && (!function_exists('is_page') || !is_page())) $user_result[$key] = $result;
	
		return $result;
	
	}
	
	/**
	 * Get the user's support entitlements
	 *
	 * @param Integer|Boolean $user_id		  - the WP user ID; if false, then use the logged-in user
	 * @param Boolean		  $prune_expired  - whether to prune expired entitlements from the results by running them through self::prune_expired_entitlements()
	 *
	 * @return Array|String
	 */
	public function get_user_support_entitlements($user_id = false, $prune_expired = false) {
	
		if ($this->free || false == ($user_id = $this->parse_id($user_id))) return array();

		$user_result = $this->get_user_entitlements_from_db($user_id);

		$user_support = array();
		if (!empty($user_result)) {
			$meta_keys = array('renewal_orders', 'renewed_date', 'support_type', 'response_time', 'purchase_date');
			foreach ($user_result as $result) {
			
				// get_user_entitlements_from_db gets all types (for speed/minimising SQL queries); so, we ened to filter
				if ('support' != $result->type) continue;
				
				$user_support[$result->entitlement_id] = array(
					'status' => $result->status,
					'expire_date' => $result->expires
				);
				if (!empty($result->meta)) {
					$meta = unserialize($result->meta);
					if (!is_array($meta)) continue;
					foreach ($meta_keys as $mk) {
						if (isset($meta[$mk])) {
							$user_support[$result->entitlement_id][$mk] = $meta[$mk];
						}
					}
				}
			}
		}

		if (!is_array($user_support)) return array();

		if ($prune_expired) $user_support = $this->prune_expired_entitlements($user_support);

		return $user_support;
	}

	/**
	 * Given a set of entitlements, prune the expired ones. If there were some and they all get removed, just return 'expired'.
	 *
	 * @param Array $entitlements
	 *
	 * @return Array|String
	 */
	protected function prune_expired_entitlements($entitlements) {
	
		$pre_prune_num = count($entitlements);
		
		foreach ($entitlements as $ind => $titlement) {

			// The style used for support entitlements
			if (is_array($titlement) && !empty($titlement['expire_date']) && time() >= $titlement['expire_date'] && $titlement['expire_date'] > 0) unset($entitlements[$ind]);
			// The style used for add-on entitlements
			if (is_array($titlement) && !empty($titlement['expires']) && time() >= $titlement['expires'] && $titlement['expires'] > 0) unset($entitlements[$ind]);

		}
		
		if ($pre_prune_num > 0 && 0 == count($entitlements)) {
			$entitlements = 'expired';
		}
	
		return $entitlements;
	}
	
	/**
	 * @param Boolean|Integer $user_id - user ID, or false for the currently-logged in user
	 * @param Boolean|String  $sid - site identifier
	 * @param Boolean|Integer $prune_expired - true = both, 1 = addons only, 2 = support only, false = neither
	 *
	 * @return Array - two entries: addon entitlements, then support entitlements
	 */
	public function get_user_entitlements($user_id = false, $sid = false, $prune_expired = false) {

		$user_id = $this->parse_id($user_id);
		if (!$this->free && !$user_id) return array('addons' => array(), 'support' => array());

		$prune_addons_expired = (true === $prune_expired || 1 === $prune_expired) ? true : false;
		$prune_support_expired = (true === $prune_expired || 2 === $prune_expired) ? true : false;

		// Get their premium add-on entitlements - showing them the shop link for any they don't have
		// Get their support entitlements
		return array(
			'addons' => $this->get_user_addon_entitlements($user_id, $sid, $prune_addons_expired),
			'support' => $this->get_user_support_entitlements($user_id, $prune_support_expired)
		);
	}

	/**
	 * Reset entitlements that have been allocated but not used
	 *
	 * @param Integer $user_id - WP user ID
	 * @param Integer $not_checked_in_since_before - an epoch time, indicating the time that the user should not have checked in since for the entitlement to be reset
	 * @param Integer|Boolean $renewal_order_id - if set, then it will only reset licences that indicate that they were renewed as part of this order
	 * @param String|Boolean $this_key_only - if a string is passed, then indicate that only these entitlements should be reset
	 */
	public function reset_allocated_but_unused_entitlements($user_id, $not_checked_in_since_before = 0, $renewal_order_id = false, $this_key_only = false) {

		$user_id = $this->parse_id($user_id);
		if (!$this->free && !$user_id) return false;

		$entitlements = $this->get_user_addon_entitlements($user_id);
		if (!is_array($entitlements)) return;

		$last_checkins = $this->db_get_last_checkins($user_id);
		if (!is_array($last_checkins) || empty($last_checkins)) return;

		$time_now = time();

		// Some setups may want certain users to have all possible resets skipped (e.g. if there's another process doing the reaping)
		if (apply_filters('updraftmanager_reset_allocation_check', false, $entitlements)) return;

		$any_changes = false;

		foreach ($entitlements as $key => $titlement) {

			if (!isset($titlement['key']) || !isset($titlement['site']) || 'unclaimed' == $titlement['site']) continue;

			if (!empty($this_key_only) && $titlement['key'] != $this_key_only) continue;

			// Skip expired entitlements
			if (isset($titlement['expires']) && $titlement['expires'] >= 0 && $titlement['expires'] < $time_now) continue;

			$sid = $titlement['site'];
			if (!isset($last_checkins[$sid])) continue;

			// The site operator may wish to handle certain keys separately
			if (apply_filters('updraftmanager_reset_allocation_check_key', false, $titlement['key'], $titlement, $key)) continue;

			if ($renewal_order_id && isset($titlement['renewal_orders']) && is_array($titlement['renewal_orders']) && !in_array($renewal_order_id, $titlement['renewal_orders'])) continue;

			if ($last_checkins[$sid] < $not_checked_in_since_before) {
				$this->db_reset_user_entitlement($key, $user_id);
			}

		}
	}

	// This function not only generates a new entitlement, but can re-write an existing entitlement - which is done when an entitlement is claimed
	// At first, the entitlement is 'unclaimed', until they connect
	// The unique ID can be a purchase receipt number plus item number
	// Each entitlement has attributes 'site' (where it is in use), 'key' (what it is), 'status' (not used, but mark active for future usage)
	// The site is some comprehensible identifier for a particular site. It can be re-assigned.
	public function grant_user_addon_entitlement($uniqid, $key, $site = 'unclaimed', $sitedescription = 'Unused entitlement', $user_id = false, $expire_date = -1) {
		$user_id = $this->parse_id($user_id);
		if (!$user_id) return array();

		$entitlements = $this->get_user_addon_entitlements($user_id);

		# Preserve the expiry date if it already exists
		if (!empty($entitlements[$uniqid]['expires'])) $expire_date = $entitlements[$uniqid]['expires'];

		$titlement = array(
			'site' => $site, 'sitedescription' => $sitedescription, 'key' => $key, 'status' => 'active', 'expires' => $expire_date
		);

		if (!empty($entitlements[$uniqid]['renewal_orders'])) $titlement['renewal_orders'] = $entitlements[$uniqid]['renewal_orders'];

// 		$entitlements[$uniqid] = $titlement;
// 		$this->save_user_entitlements($user_id, $entitlements);
		$this->db_delete_user_entitlement($uniqid, $user_id);
		$this->db_set_user_entitlement($uniqid, $user_id, $titlement);

		return $titlement;
	}

	public function grant_user_support_entitlement($uniqid, $purchase_date, $support_type, $response_time, $status = 'unused', $expire_date = false, $user_id = false) {
		if (false == ($user_id = $this->parse_id($user_id))) return array();

		$entitlements = $this->get_user_support_entitlements($user_id);

		$entitlements[$uniqid] = array(
			'purchase_date' => $purchase_date,
			'expire_date' => $expire_date,
			'status' => $status,
			'response_time' => $response_time,
			'support_type' => $support_type
		);

// 		return $this->save_user_entitlements($user_id, $entitlements, 'support');
		$this->db_delete_user_entitlement($uniqid, $user_id, 'support');
		return $this->db_set_user_entitlement($uniqid, $user_id, $entitlements[$uniqid], 'insert', 'support');
	}

	public function addonbox_shopurl($blurb, $full_url) {
		if (!$this->free) {
			$blurb = '<p><a href="'.$full_url.'">'.__('Make fresh purchases in the store', 'simba-plugin-updates-manager').'</a></p>';
		}
		return $blurb;
	}

	public function authenticate($email, $pass, $sid = false) {
		// Does user exist? And active?
		// Has the user provided the correct hash?
		if (!empty($email) && $user = get_user_by('email', $email)) {

// 			if (empty($pass) && !empty($sid)) {
			if (!empty($sid) && !empty($this->addonsdir)) {
				// We allow an empty password combined with a site ID. Only the site can know its own ID.
				// Can't get the entitlements, as at that point, there may be none - we're about to claim one, after authentication succeeds
				// $entitlements = $this->get_user_addon_entitlements($user->ID, $sid, false);
				// This is an extra option; we don't currently do anything if it fails. i.e. Both methods allowed.
				$currently_authenticated = get_user_meta($user->ID, 'udmanager_authenticated_sids', true);
				if (is_array($currently_authenticated) && isset($currently_authenticated[$this->uid]) && is_array($currently_authenticated[$this->uid]) && isset($currently_authenticated[$this->uid][$this->slug])) {
					foreach ($currently_authenticated[$this->uid][$this->slug] as $k_sid => $site) {
						if ($k_sid == $sid && time() <= $site['until']) {
							$this->authenticated_via_sid = true;
							// We used to do an error_log(), but this was unnecessarily noisy; the action call is in case anyone really wants the info.
							do_action('updraftmanager_authenticated_via_sid', $sid, $email, $user);
							return $user;
						}
					}
				}
			}

			# The check with addslashes() is because of a WP bug. The thing hashed and stored is actually the addslashes-ed version normally - but WooCommerce stores the version without addslashes. There are various WP bugs in this area (check my email of 20th Oct 2014)
			if ($pass != addslashes($pass)) {
				$also_check = addslashes($pass);
			}
			
			// Allow external code to implement its own passwords
			if (apply_filters('updraftmanager_check_password', false, $pass, $user) || wp_check_password($pass, $user->data->user_pass, $user->ID) || (isset($also_check) && wp_check_password($also_check, $user->data->user_pass, $user->ID))) return $user;
		}
		return false;
	}

	# Set $site=false to match any site (one). Set $site=true to match all sites.
	# $months_or_renewal_time can be a number of months, or a renewal time. Basically, we decide that any value of $months>10000 must be a raw time
	# Set $renewal_item = false to apply to all where the key matches
	# Set $key = false to apply to all keys
	# $skip_if_already_later: indicate whether to skip changing the item's renewal time if it is already later
	public function renew_user_addon_entitlement($key, $user_id, $months_or_renewal_time, $renewal_item, $site = false, $skip_if_already_later = false) {
		if (false == ($user_id = $this->parse_id($user_id))) return array();

		$entitlements = $this->get_user_addon_entitlements($user_id);

		$soonest_expiry_key = false;
		$soonest_expiry_value = false;
		$matching_keys = array();
		
		$renewal_order_id = false;

		foreach ($entitlements as $ek => $titlement) {
			# Check that this entitlement is for the same product, and that it has a finite expiry date
			if (($key != $titlement['key'] && false !== $key) || empty($titlement['expires']) || $titlement['expires']<0) continue;

			// Is $renewal_item (if a particular one is specified) - the one we're meant to be renewing?

			if (is_array($renewal_item) && $renewal_item['entitlement_id'] != $ek) continue;

			// Get the soonest expiring of all the eligible entitlements (which, remember, might just be one single entitlement)
			if (false === $site || true === $site || $titlement['site'] == $site) {
				$matching_keys[] = $ek;
				if (true === $site) {
					$soonest_expiry_key = -1;
				} elseif ($titlement['expires'] < $soonest_expiry_value || false === $soonest_expiry_key) {
					$soonest_expiry_key = $ek;
					$soonest_expiry_value = $titlement['expires'];
				}
			}
		}

		if (false !== $soonest_expiry_key) {
			if (true !== $site) $matching_keys = array($soonest_expiry_key);
			$renewal_order_id = apply_filters('updraftmanager_get_current_order_id', false);

			// Evil kludge: we use some secret internal knowledge; but, it's harmless to mark the order as renewed by itself (it's unused meta-data), and, as noted below, this should never be possible anyway; so, it doesn't matter.
			$this_order_id = (int)substr($key, 3, 7);

			// In theory, one would never renew an order just being made. But, custom logic may sometimes do this, depending on how the products work.
			if ($renewal_order_id != $this_order_id) {
				foreach ($matching_keys as $mkey) {

					$new_expires = ($months_or_renewal_time > 10000) ? $months_or_renewal_time : date('U', strtotime("@".$entitlements[$mkey]['expires']." +$months_or_renewal_time Months"));
					if (!$skip_if_already_later || $new_expires > $entitlements[$mkey]['expires']) $entitlements[$mkey]['expires'] = $new_expires;

					if (false !== $renewal_order_id) {
						$renewal_orders = (!empty($entitlements[$mkey]['renewal_orders']) && is_array($entitlements[$mkey]['renewal_orders'])) ? $entitlements[$mkey]['renewal_orders'] : array();
						if (!in_array($renewal_order_id, $renewal_orders)) $renewal_orders[] = $renewal_order_id;
						$entitlements[$mkey]['renewal_orders'] = $renewal_orders;
					}

					$this->db_set_user_entitlement($mkey, $user_id, $entitlements[$mkey], 'update');

				}
			}

// 			$this->save_user_entitlements($user_id, $entitlements);
		}
		
		// Hook here to allow any associated downloadable product expiry date to be adjusted
		do_action('updraftmanager_post_renew_user_addon_entitlement', $key, $user_id, $months_or_renewal_time, $renewal_item, $site, $skip_if_already_later, $entitlements, $renewal_order_id, $matching_keys);
		
		return $entitlements;

	}

	// N.B. The $renewal_items parameter is not used - what is passed may currently just be garbage
	public function renew_user_support_entitlement($support_type, $response_time, $renewal_items, $months = 12, $user_id = false) {
		if (false == ($user_id = $this->parse_id($user_id))) return array();

		$entitlements = $this->get_user_support_entitlements($user_id);

		$earliest_expiry_key = false;
		$soonest_expiry_value = false;
		foreach ($entitlements as $skey => $titlement) {
			if (empty($titlement['support_type']) || $support_type != $titlement['support_type'] || empty($titlement['response_time']) || $response_time != $titlement['response_time']) continue;

			// Just renew the earliest expiring one of this type - forget about tracking orders
			$order_allowed = true;

			if (false === $earliest_expiry_key || $soonest_expiry_value > $titlement['expire_date']) {
				$earliest_expiry_key = $skey;
				$soonest_expiry_value = $titlement['expire_date'];
			}
		}

		if (false === $earliest_expiry_key) return false;

		$entitlements[$earliest_expiry_key]['expire_date'] = strtotime("@".$entitlements[$earliest_expiry_key]['expire_date']." + $months months");
		$entitlements[$earliest_expiry_key]['renewed_date'] = time();

		$renewal_order_id = apply_filters('updraftmanager_get_current_order_id', false);
		if (false !== $renewal_order_id) {
			$renewal_orders = (!empty($titlement['renewal_orders']) && is_array($titlement['renewal_orders'])) ? $titlement['renewal_orders'] : array();
			if (!in_array($renewal_order_id, $renewal_orders)) $renewal_orders[] = $renewal_order_id;
			$entitlements[$earliest_expiry_key]['renewal_orders'] = $renewal_orders;
		}

		return $this->db_set_user_entitlement($earliest_expiry_key, $user_id, $entitlements[$earliest_expiry_key], 'update', $type = 'support');
// 		return $this->save_user_entitlements($user_id, $entitlements, 'support');
		
	}


}

