<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

use phpseclib\Crypt;
use Michelf\Markdown;

// PclZip only calls functions; hence this lives out here
function udmmanager_pclzip_name_as($p_event, &$p_header) {
	global $updraft_manager;
	$p_header['stored_filename'] = $updraft_manager->plugin->slug.'/'.$updraft_manager->plugin->pluginfile;
	return 1;
}

class Updraft_Manager_Plugin {

	public $slug;
	public $author;
	public $uid;
	public $version;
	public $homepage;
	public $free;

	public $pluginfile;

	public $plugin_name;
	public $plugin_descrip;
	
	// @var Array
	public $plugin;

	// @var String
	public $addonsdir;

	/**
	 * Whether base plugin is downloadable or can be downloaded
	 *
	 * @var Boolean
	 */
	protected $downloadable_base_plugin;

	/**
	 * Plugin constructor
	 *
	 * @param String $slug - the plugin slug
	 * @param Integer $uid - the WP user ID that the plugin belongs to
	 */
	public function __construct($slug, $uid) {

		if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
			throw new Exception(__('The plugin slug should contain lower-case letters, numerals and hyphens only.', 'simba-plugin-updates-manager').' ('.$slug.')');
		}
	
		$this->uid = $uid;

		$plugins = UpdraftManager_Options::get_options($uid);
		if (empty($plugins[$slug]) || !is_array($plugins[$slug])) { throw new Exception("No such slug ($slug/$uid)"); }
		$plugin = $plugins[$slug];
		
		$this->plugin = $plugin;

		$this->slug = $slug;

		$this->plugin_name = $plugin['name'];
		$this->plugin_descrip = $plugin['description'];
		$this->author = empty($this->plugin['author']) ? '' : $this->plugin['author'];
		$this->addonsdir = empty($this->plugin['addonsdir']) ? '' : $this->plugin['addonsdir'];

		// Absent the Premium child class, all plugins are 'free' (i.e. no licenses required)
		$this->free = true;
		$this->downloadable_base_plugin = true;
		$this->homepage = $plugin['homepage'];

	}

	// Returns false, or the (array)information about the zip
	// It allows a caller to pass in their own alternative values for $plugin and make a calculation based on something not in the database/in the instance.
	public function calculate_download($info = array(), $plugin = false) {
		// When no parameter is sent, do not allow any random rules to match.
		if (empty($info['random_percent'])) $info['random_percent'] = 100;
		// Potential keys in info: wp, php, installed, username, siteurl, random_percent
		$manager_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid);
		
		if (false === $plugin) $plugin = $this->plugin;
		
		$rules = (!empty($plugin['rules']) && is_array($plugin['rules'])) ? $plugin['rules'] : array();
		ksort($rules);
		foreach ($rules as $rule) {
			$combination = (!empty($rule['combination'])) ? $rule['combination'] : 'and';
			$ruleset = (!empty($rule['rules']) && is_array($rule['rules'])) ? $rule['rules'] : array();
			ksort($ruleset);
			$matches = false;
			$mismatches = false;
			foreach ($ruleset as $r) {
				$criteria = (!empty($r['criteria'])) ? $r['criteria'] : '';
				$relationship = (!empty($r['relationship'])) ? $r['relationship'] : '';
				if ($criteria != 'always' && (!isset($info[$criteria]) || !isset($r['value']) || '' == $relationship)) continue;
				if ('always' == $criteria) {
					$matches = true;
				} elseif ('eq' == $relationship && $info[$criteria] == $r['value']) {
					$matches = true;
				} elseif ('lt' == $relationship && version_compare($info[$criteria], $r['value'], '<=')) {
					$matches = true;
				} elseif (version_compare($info[$criteria], $r['value'], '>=') && 'gt' == $relationship) {
					$matches = true;
				} elseif ('range' == $relationship && preg_match('/^([^,]+),(.*)$/', $r['value'], $matches) && version_compare($info[$criteria], $matches[1], '>=') && version_compare($info[$criteria], $matches[2], '<=')) {
					$matches = true;
				} else {
					$mismatches = true;
				}
			}

			# Debug : rules matched - was the file correctly found?
// 			if ((('and' == $combination && $matches && !$mismatches) || ('or' == $combination && $matches))) {
// 				error_log("FN: ".$rule['filename']);
// 				error_log("PATH: ".$manager_dir.'/'.$rule['filename']);
// 				error_log("ZIP: ".serialize($this->plugin['zips'][$rule['filename']]));
// 			}

			if ((('and' == $combination && $matches && !$mismatches) || ('or' == $combination && $matches)) && !empty($rule['filename']) && is_file($manager_dir.'/'.$rule['filename']) && isset($plugin['zips'][$rule['filename']])) return $plugin['zips'][$rule['filename']];
		}
		return false;
	}

	public function get_available_addons($unpacked_dir, $download = null) {
		$udmanager_addons = apply_filters('updraftmanager_defaultaddons', array(), $this, $download);
		$scan_addons = $this->scan_addons($unpacked_dir);
		return array_merge($udmanager_addons, $scan_addons);
	}

	// Returns an array of addons found in the managed plugin's 'addons' sub-directory
	public function scan_addons($unpacked_dir) {

		if (empty($this->addonsdir)) return array();

		$usedir = $unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir;

		$stat = stat($usedir);

		// Use transient if it exists, and if the directory was not modified in the last hour
		if ($stat['mtime'] < time()-3600) {
			$tmp = get_transient('udmanager_scanaddons_'.$this->uid.'_'.$this->slug);
			if ($tmp != false) return $tmp;
		}

		$scan_addons = array();
		if (is_dir($usedir) && $dir_handle = opendir($usedir)) {
			while ($e = readdir($dir_handle)) {
				if (is_file("$usedir/$e") && preg_match('/^(.*)\.php$/i', $e, $matches)) {
					$potential_addon = $this->get_addon_info("$usedir/$e");
					if (is_array($potential_addon) && isset($potential_addon['key'])) {
						$key = $potential_addon['key'];
						$scan_addons[$key] = $potential_addon;
					}
				}
			}
		}

		set_transient('udmanager_scanaddons_'.$this->uid.'_'.$this->slug, $scan_addons, 3600);

		return $scan_addons;
	}
	
	/**
	 * Get the 'changelog' section of the readme file
	 *
	 * @param Integer $maximum_sections
	 *
	 * @return String
	 */
	public function get_changelog($maximum_sections = 4) {
	
		$download = $this->calculate_download();
		$filename = isset($download['filename']) ? $download['filename'] : '';
	
		$sections = $this->get_sections_from_readme($filename, array('maximum_changelog_sections' => $maximum_sections));
	
		return empty($sections['changelog']) ? '' : $sections['changelog'];
	
	}

	// This function, if ever changed, should be kept in sync with the same function in updraftplus-addons.php
	// Returns either false or an array
	protected function get_addon_info($file) {
		if ($f = fopen($file, 'r')) {
			$key = "";
			$name = "";
			$description = "";
			$version = "";
			$shopurl = "";
			$latestchange = null;
			$lines_read = 0;
			$include = "";
			while (!feof($f) && $lines_read<10) {
				$line = @fgets($f);
				if ($key == "" && preg_match('/Addon: ([^:]+):(.*)$/i', $line, $lmatch)) {
					$key = $lmatch[1]; $name = $lmatch[2];
				} elseif ($description == "" && preg_match('/Description: (.*)$/i', $line, $lmatch)) {
					$description = $lmatch[1];
				} elseif ($version == "" && preg_match('/Version: (.*)$/i', $line, $lmatch)) {
					$version = $lmatch[1];
				} elseif ($shopurl == "" && preg_match('/Shop: (.*)$/i', $line, $lmatch)) {
					$shopurl = $lmatch[1];
				} elseif ("" == $latestchange && preg_match('/Latest Change: (.*)$/i', $line, $lmatch)) {
					$latestchange = $lmatch[1];
				} elseif ("" == $include && preg_match('/Include: (.*)$/i', $line, $lmatch)) {
					$include = $lmatch[1];
				}
				$lines_read++;
			}
			fclose($f);
			if ($key && $name && $description && $version) {
				return array('key' => $key, 'name' => $name, 'description' => $description, 'latestversion' => $version, 'shopurl' => $shopurl, 'latestchange' => $latestchange, 'include' => $include);
			}
		}
		return false;
	}

	// Valid types: OK, ERR, BADAUTH, INVALID
	public function send_response($type, $data = null, $msg = null) {
		switch ($type) {
			case 'OK':
			case 'ERR':
			case 'INVALID':
			case 'BADAUTH':
			$rcode = $type;
			break;
			default:
			throw new Exception('Unknown response type: '.$type);
			break;
		}
		$response = array(
			'version' => 1,
			'code' => $rcode
		);
		if ($data !== null) $response['data'] = $data;
		if ($msg !== null) $response['msg'] = $msg;
		// Allow legacy installations to send the data in a different format

		echo apply_filters('updraftmanager_send_response', json_encode($response), $type, $data, $msg, $this);
	}

	public function pinfo_claimaddon() {
		echo $this->send_response('OK');
		return;
	}

	public function pinfo_releaseaddon() {
		echo $this->send_response('OK');
		return;
	}

	/**
	 * @uses: (legacy) $_GET['token'], $_GET['etoken']
	 */
	public function pinfo_download() {

		if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

		if (!empty($_GET['token']) && is_string($_GET['token'])) {
			// Legacy
			$download_info = get_transient('uddownld_'.$_GET['token']);
		} elseif (!empty($_GET['etoken']) && is_string($_GET['etoken'])) {
			$download_info = json_decode($this->decrypt($_GET['etoken']), true);
			// Allow some time for legacy tokens to expire
			if (time() > 1448536726 && empty($download_info['ctime'])) wp_die('Invalid token', 401);
			if ($download_info['ctime'] < time() - 7 * 86400) wp_die('Expired token', 401);
		} else {
			wp_die('Invalid token', 401);
		}

		if (!is_array($download_info) || empty($download_info['download'])) wp_die('Invalid token', 401);
		if ($this->uid != $download_info['uid'] || $this->slug != $download_info['slug']) wp_die('Invalid token', 401);

		// This flag is only used for front-end shortcodes
		if (!empty($download_info['mustbeloggedin']) && !is_user_logged_in()) wp_die('Invalid token', 401);

		$download_info = apply_filters('updraftmanager_received_download_data', $download_info);

		// Find out what they are entitled to
		$entitlements = $this->get_user_addon_entitlements($download_info['id'], $download_info['sid'], true);
		if ((empty($entitlements) || 'expired' === $entitlements) && !$this->downloadable_base_plugin) die;

		$ent_keys = array();
		if (is_array($entitlements)) {
			foreach ($entitlements as $titlement) {
				if (isset($titlement['key'])) {
					if (!in_array($titlement['key'], $ent_keys)) $ent_keys[] = $titlement['key'];
				}
			}
		}

		$deliver = $this->deliver_zip($download_info['download'], $ent_keys, $download_info['pluginfile']);

		if (is_wp_error($deliver)) {
			error_log("Zip delivery failed: ".$deliver->get_error_message());
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		} elseif (!$deliver) {
			error_log("Zip delivery failed (".json_encode($download_info).")");
			header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		}

		// TODO: Check this out, see if it's still true. The number of transients being stored can get high, so if we can get rid of them, it would be good.
		// We don't delete or change the expiration time of the transient, because the tokens are not unique (it may be re-used)
		// delete_transient('uddownld_'.$_GET['token']);

		die;

	}

	// This is over-ridden in the Premium class - stored in the licences table instead
	protected function db_set_last_checkin($sid, $user_id, $time, $site_url = '') {
	
		throw new Exception("Dead code: this should never be called");
		
		$last_checkins = get_user_meta($user_id, 'udmanager_lastcheckins', true);
		if (!is_array($last_checkins)) $last_checkins = array();
		if (empty($last_checkins[$this->uid][$this->slug][$sid])) $last_checkins[$this->uid][$this->slug][$sid] = array();
		$last_checkins[$this->uid][$this->slug][$sid]['time'] = $time;
		if ($site_url) $last_checkins[$this->uid][$this->slug][$sid]['site_url'] = $site_url;
		update_user_meta($user_id, 'udmanager_lastcheckins', $last_checkins);
	}

	/**
	 * Get the list of last check-ins for this plugin. This is also over-ridden in the Premium class.
	 *
	 * @param Integer $user_id - the user whose check-ins are being looked up
	 * @param String|Null $site_id - if set, then is not guaranteeed to return more than this site
	 *
	 * @return Array - list of results
	 */
	public function db_get_last_checkins($user_id, $site_id = null) {
		$last_checkins = maybe_unserialize(get_user_meta($user_id, 'udmanager_lastcheckins', true));
		if (!is_array($last_checkins)) $last_checkins = array();
		return isset($last_checkins[$this->uid][$this->slug]) ? $last_checkins[$this->uid][$this->slug] : array();
	}

	// If there is nothing yet in the table for today, returns false, to allow this to be distinguished from a zero-result
	public function db_get_downloads($date_key, $filename) {
		global $wpdb;
		$downloads = $wpdb->get_row($wpdb->prepare("SELECT downloads FROM ".Updraft_Manager::get_download_history_table()." WHERE slug=%s AND owner_user_id=%d AND daybegin=%d AND filename=%s", $this->slug, $this->uid, $date_key, $filename));
		return empty($downloads->downloads) ? false : $downloads->downloads;
	}

	// $prior_value should be set, unless you know that an UPDATE command is appropriate (i.e. you know that the row already exists)
	public function db_set_downloads($date_key, $filename, $downloads, $prior_value = null) {
		global $wpdb;
		
		// If the row does not yet exist
		if (false === $prior_value) {
			return $wpdb->insert(
				Updraft_Manager::get_download_history_table(),
				array(
					'slug' => $this->slug,
					'owner_user_id' => $this->uid,
					'daybegin' => $date_key,
					'filename' => $filename,
					'downloads' => $downloads
				),
				array(
					'%s',
					'%d',
					'%d',
					'%s',
					'%d'
				)
			);
		} else {
			return $wpdb->update(
				Updraft_Manager::get_download_history_table(),
				array(
					'downloads' => $downloads
				),
				array(
					'slug' => $this->slug,
					'owner_user_id' => $this->uid,
					'filename' => $filename,
					'daybegin' => $date_key
				)
			);
		}
	}

	// Create the zip (or find a pre-made cached one) from their entitlement
	// Feed the zip to them
	public function deliver_zip($filename, $keys = array(), $pluginfile = '') {

		$this->pluginfile = $pluginfile;

		global $updraft_manager;

		$manager_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid);
		if (false === $manager_dir || !$updraft_manager->manager_dir_exists($manager_dir)) return false;

		$version = empty($this->plugin['zips'][$filename]['version']) ? '' : $this->plugin['zips'][$filename]['version'];
		$versionsuffix = $version;

		$cache_file = apply_filters('updraftmanager_plugin_deliverzip_cachefile', $manager_dir.'/'.$filename, $keys, $manager_dir, $version, $versionsuffix);

		if (is_wp_error($cache_file)) return $cache_file;

		if (!file_exists($cache_file)) return false;

		// Increment download counter for this zip
		$nowtime = time();
		$date_key = $nowtime-($nowtime%86400);

		$downloads = $this->db_get_downloads($date_key, $filename);

		$this->db_set_downloads($date_key, $filename, $downloads+1, $downloads);

		// Allow over-ride of delivery mechanism (e.g. redirect to some third-party storage)
		if (apply_filters('updraftmanager_plugin_deliverzip_delivered', false, $this, $cache_file, $versionsuffix)) return true;
		
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename='.$this->slug.'.'.$versionsuffix.'.zip');
		header('Content-Length: '.filesize($cache_file));
		readfile($cache_file);
		
		return true;
	}

	/**
	 * Send back (via printing) an array of available add-ons. Will also output a Content-Type header.
	 */
	public function pinfo_listaddons() {
		$download = $this->calculate_download();
		$addons = is_array($download) ? $this->get_available_addons(UpdraftManager_Options::get_manager_dir(false, $this->uid.'/_up_'.$download['filename']), $download) : array();
		// Set this header to prevent content compressors mangling the contents (e.g. assuming it is HTML and stripping double spaces)
		@header('Content-Type: application/octet-stream');
		// We wrap inside another array to allow for future changes
		$response_format = empty($_REQUEST['response_format']) ? 'php-serialized' : $_REQUEST['response_format'];
		$response = array('addons' => $addons);
		if ('php-serialized' === $response_format) {
			// Legacy, deprecated.
			print serialize($response);
		} else {
			// 'json'
			print json_encode($response);
		}
	}

	// Send false to get the current user
	// Returns the stored arrays
	// Values for $prune_expired: true = both, 1 = addons only, 2 = support only, false = neither
	public function get_user_entitlements($id = false, $sid = false, $prune_expired = false) {
		return array('addons' => array(), 'support' => array());
	}

	public function renew_user_support_entitlement($support_type, $response_time, $renewal_items, $months = 12, $id = false) {
		return false;
	}

	public function get_user_addon_entitlements($id = false, $sid = false, $prune_expired = false) {
		return array();
	}

	/**
	 * Get the user's support entitlements
	 *
	 * @param Integer|Boolean $user_id		  - the WP user ID; if false, then use the logged-in user
	 * @param Boolean		  $prune_expired  - whether to prune expired entitlements from the results by running them through self::prune_expired_entitlements()
	 *
	 * @return Array|String
	 */
	public function get_user_support_entitlements($id = false, $prune_expired = false) {
		return array();
	}

	public function parse_id($id = false) {
		if ($id) return $id;
		if (!is_user_logged_in()) return false;
		return UpdraftManager_Options::get_user_id_for_licences();
	}

	// This is similar, but far from identical, to the procedure in updraftplus-addons.php
	private function addonbox($name, $shopurl, $description, $latestversion, $in_use_on_sites, $key = false, $user_id = false) {

		$urlbase = UDMANAGER_URL;

		# TODO: Get real URL
		$mother = home_url('');
		$full_url = (0 === strpos($shopurl, 'http:/') || 0 === strpos($shopurl, 'https:/')) ? $shopurl : $mother.$shopurl;
		$blurb = '';

		if ($in_use_on_sites) {
			$preblurb="<img style=\"float:right; margin-left: 24px;\" src=\"$urlbase/images/yes.png\" width=\"85\" height=\"98\" alt=\"".__("You've got it", 'updraftmanager')."\">";

			$blurb .= "<p>$in_use_on_sites</p>";

		} else {
			$preblurb='<span style="float:right; margin-left: 24px;">';
			if ($shopurl) $preblurb .= '<a href="'.$full_url.'">';
			$preblurb .= '<img src="'.$urlbase.'/images/shopcart.png" width="120" height="98" alt="'.esc_attr(__('Buy It', 'simba-plugin-updates-manager')).'">';
			if ($shopurl) $preblurb .= '</a>';
			$preblurb .= '</span>';
			$blurb = '<p><em>'.__('No purchases.', 'simba-plugin-updates-manager').'</em></p>';
		}

		$blurb.='';

		if ($shopurl) {
			$blurb .= apply_filters('updraftmanager_plugin_addonbox_shopurl', '<p><a href="'.$full_url.'">'.__("Free plugin: Go to the plugin's homepage", 'updraftmanager').'</a></p>', $shopurl);
		}

		if ($this->plugin_name != $name) { $name = $this->plugin_name.' : '.$name; }

		$ret = '<div class="udmanager-addonbox"'.((!empty($user_id)) ? ' data-userid="'.$user_id.'"' : '').' style="border: 1px solid; border-radius: 4px; padding: 0px 12px 0px; min-height: 164px; width: 95%; margin-bottom: 16px;"';
		$ret .= ' data-entitlementslug="'.esc_attr($this->slug).'"';
		if (is_string($key)) $ret .= ' data-addonkey="'.esc_attr($key).'"';
		$ret .=">";
		$ret .= <<<ENDHERE
			<div style="width: 100%;">
			<h2 style="margin: 4px;">$name</h2>
			$preblurb
			$description<br>
			$blurb
ENDHERE;

		$ret = apply_filters('updraftmanager_inuseonsites_final', $ret, $this->free, $this->user_can_manage, $key, $this);

		return $ret.'</div></div>';
	}

	/**
	 * Perform the 'updateinfo' action. Data is obtained from $_GET (keys: e, sid, ssl, su, si2 (legacy: si))
	 * 
	 * This method is basically a layer onto self::get_plugin_info(), handling the HTTP layer input/output
	 */
	public function pinfo_updateinfo() {

		// The plugin that we are managing

		// If it passes, then store a short-lived transient (as long-lived as the checkupdate interval)

		$email = isset($_GET['e']) ? strtolower(trim($_GET['e'])) : '';
		$sid = isset($_GET['sid']) ? $_GET['sid'] : '';
		$ssl = (!empty($_GET['ssl']) || (!isset($_GET['ssl']) && is_ssl())) ? true : false;
		$site_url = isset($_GET['su']) ? @base64_decode($_GET['su']) : '';
		
		$si = array();
		if (isset($_GET['si2'])) {
			$si = (array)json_decode(@base64_decode($_GET['si2']), true);
		} elseif (isset($_GET['si']) && defined('PHP_MAJOR_VERSION') && PHP_MAJOR_VERSION >= 7) {
			// Legacy format. Will later be removed entirely.
			if (apply_filters('updraftmanager_process_old_siteinfo_format', false)) {
				$si = isset($_GET['si']) ? unserialize(@base64_decode($_GET['si']), array('allowed_classes' => false)) : '';
			}
		}

		$installed_version = isset($_GET['installed_version']) ? $_GET['installed_version'] : null;
		
		$plugin_info = $this->get_plugin_info($sid, $email, $ssl, $site_url, $si, $installed_version);

		@header('Content-Type: application/json');
		echo json_encode($plugin_info);
	}
	
	/**
	 * Get a key to be used by phpseclib
	 *
	 * @return String
	 */
	private function get_encryption_key() {
		return mb_substr(wp_salt('secure_auth'), 0, 32);
	}

	/**
	 * Perform a decryption
	 *
	 * @param String $cipher_text - the text to be decrypted
	 *
	 * @return String - the decrypted text
	 */
	private function decrypt($cipher_text) {
		require_once(UDMANAGER_DIR.'/vendor/autoload.php');

		$cipher_text = base64_decode($cipher_text);
		$iv = substr($cipher_text, 0, 16);
		$cipher_text = substr($cipher_text, 16);

		$rijndael = new phpseclib\Crypt\Rijndael();
		$rijndael->setIV($iv);
		$rijndael->setKey($this->get_encryption_key());

		$compressed = $rijndael->decrypt($cipher_text);

		return gzinflate($compressed);
	}

	/**
	 * Perform encryption
	 *
	 * @param String $plaintext - the text to be encrypted
	 *
	 * @return String - the encrypted text
	 */
	private function encrypt($plaintext) {
		require_once(UDMANAGER_DIR.'/vendor/autoload.php');
		$iv = phpseclib\Crypt\Random::string(16);
		$rijndael = new phpseclib\Crypt\Rijndael();

		$compressed = gzdeflate($plaintext, 9);

		// The key needs to be exactly 32 bytes long
		$rijndael->setIV($iv);
		$rijndael->setKey($this->get_encryption_key());
		$cipher_text = $rijndael->encrypt($compressed);
		
		return base64_encode($iv . $cipher_text);
	}

	/**
	 * Get a response to a request for updates info
	 *
	 * @param String|Boolean $sid
	 * @param String		 $email
	 * @param Boolean		 $ssl
	 * @param String		 $site_url
	 * @param Array			 $si - keys used in this method: php, wp (and others might be used by a filter)
	 * @param String|Null	 $installed_version - currently-installed version
	 *
	 * @return Array - plugin information
	 */
	public function get_plugin_info($sid = false, $email = '', $ssl = false, $site_url = '', $si = array(), $installed_version = null) {
		$description = $this->plugin_descrip;

		$plugin_info = array(
			'name' => $this->plugin_name,
			'slug' => $this->slug,
			'author' => $this->author,
			'homepage' => $this->homepage,
			'sections' => array()
		);
		
		$spm_meta_info = array();

		// No need to authenticate them - the SID is sufficient authentication. Also, if they changed their p/w, then authentication would fail

		$download = false;
		// Used to have get_user_by(); this gets all usermeta, which causes an unnecessary SQL query
		$user = empty($email) ? null : WP_User::get_data_by('email', $email);
		if ($this->free || !$user) { $user = new stdClass; $user->ID = false; }
		
		if ($this->free || (!empty($sid) && $sid != 'all' && $sid != 'unclaimed')) {

			// What is the user entitled to? Calculate entitlements now
			// The meaning of false as the third parameter is that expired entitlements should not be omitted.
			$entitlements = $this->get_user_entitlements($user->ID, $sid, false);

			// Second parameter is null for backwards compatibility. It always sent null; it just used to be an uninitialised variable
			$entitlements = apply_filters('updraftmanager_get_plugin_info_entitlements_pre_processing', $entitlements, null, $user, $sid);

			$user_addons = $entitlements['addons'];
			$user_support = $entitlements['support'];
			
			// Now that we've given filters a chance to do their own pre-pruning, prune out the expired support (but not expired add-on) entitlements; these should not be included in the result
			$user_support = $this->prune_expired_entitlements($user_support);
			
			if (is_array($user_addons) && !empty($sid) && false != $user->ID) {
				// Update list of last check-ins, unless we are in lock-down (i.e. save resources) mode
				if (!defined('SIMBA_PLUGINS_MANAGER_LOCKDOWN') || !SIMBA_PLUGINS_MANAGER_LOCKDOWN) {
				
					foreach ($user_addons as $entitlement_id => $entitlement) {
					
						if (!empty($entitlement['url_normalised']) && (empty($_POST['home_url']) || $entitlement['url_normalised'] !== Updraft_Manager::normalise_url(base64_decode($_POST['home_url']))) && $entitlement['url_normalised'] != Updraft_Manager::normalise_url($site_url)) {
						
							// Purpose: allow the gathering of data on the scope of the problem
							if (defined('SIMBA_PLUGINS_LOG_DUPLICATES') && SIMBA_PLUGINS_LOG_DUPLICATES) error_log("get_plugin_info(sid=$sid, entitlement_id=$entitlement_id): possibly duplicate licence (".$entitlement['url_normalised'].", ".Updraft_Manager::normalise_url($site_url));
						
							// This can be picked up by the client 
							$plugin_info['x-spm-duplicate-of'] = $site_url;
						
						}
					
					}
				
					$this->db_set_last_checkin($sid, $user->ID, time(), $site_url);
				}
			}

			$keyhash = '';

			$extra_descrip = '';

			$dinfo = array();
			if (!$this->free && $user->ID) $dinfo['username'] = $email;
			if ($installed_version) $dinfo['installed'] = $installed_version;
			if (!empty($si['wp'])) $dinfo['wp'] = $si['wp'];
			if (!empty($si['php'])) $dinfo['php'] = $si['php'];
			if (!empty($site_url)) $dinfo['siteurl'] = $site_url;
			// The assigned "random" value. This will be (if any such rules exist) tested against a rule described in the form "match X % of the time", with the actual test being "provided value <= X"
			$dinfo['random_percent'] = rand(0, 100000)/1000;

			// Give an opportunity to filter the list of add-ons prior to any further processing.
			$user_addons = apply_filters('updraftmanager_get_plugin_info_user_addons_pre_processing', $user_addons, $dinfo, $user, $sid);
			
			// N.B. This will be decremented for expired add-ons further down
			if (!$this->free && !empty($this->addonsdir)) $pver = (int)count($user_addons);
			
			// Unless something altered $user_addons (with the above filter, or one inside get_user_entitlements()), we only expect direct entitlements, i.e. ones specifically allocated to this site. But we also support 'indirect' ones that may have been added by filters - ones not specifically allocated to this site. Passing this information back on the updates check can help the client site to display suitable UI information.
			$direct_entitlement_found = false;
			$indirect_entitlement_found = false;
			if ($sid) {
				foreach ($user_addons as $ind => $addon) {
					if (!empty($addon['site'])) {
						if ($sid == $addon['site']) {
							$direct_entitlement_found = true;
						} else {
							$indirect_entitlement_found = true;
						}
					}
				}
			}
			
			if (!$direct_entitlement_found && $indirect_entitlement_found) {
				$spm_meta_info['indirect'] = true;
			}
			
			// Prune the expired ones from $user_addons
			$pre_prune_num = count($user_addons);
			foreach ($user_addons as $ind => $addon) {
				if (is_array($addon) && !empty($addon['expires']) && time() >= $addon['expires'] && $addon['expires'] > 0) {
					unset($user_addons[$ind]);
					if (isset($pver) && $pver > 0) $pver--;
				}
			}
			
			if ($pre_prune_num > 0 && empty($user_addons)) {
				// All purchased add-ons are now expired
				$user_addons = 'expired';
			} elseif ($pre_prune_num > 0 && count($user_addons) < $pre_prune_num) {
				// More than zero, but not all, purchased add-ons are now expired
				$plugin_info['x-spm-expiry'] = 'expired_'.($pre_prune_num - count($user_addons));
			}

			// Now we have only the un-expired addons in $user_addons (if it is still an array)
			if ('expired' === $user_addons) {
				$plugin_info['x-spm-expiry'] = 'expired';
			} else {

				// First parse - do they have 'all' ?
				$have_all = false;
				foreach ($user_addons as $ind => $addon) {
					if (!empty($addon['key']) && 'all' == $addon['key']) {
						$have_all = true;
						// If there are expired add-ons, then since user has 'all', they will be considering those expired ones to now be irrelevant
						unset($plugin_info['x-spm-expiry']);
					}
				}

				// Next parse - find out how many add-ons are expiring soon
				$how_many_expiring_soon = 0;
				foreach ($user_addons as $ind => $addon) {
					if (!empty($addon['key']) && $have_all && 'all' != $addon['key']) {
						// This add-on is irrelevant, because they have got 'all'
						continue;
					}
					if (is_array($addon) && !empty($addon['expires']) && $addon['expires'] > 0) {
						// 'Soon' - here defined as 'within the next 28 days'
						if (time()+28*86400 >= $addon['expires']) {
							$how_many_expiring_soon++;
						}
					}
				}

				if ($how_many_expiring_soon >0) {
					$plugin_info['x-spm-expiry'] = empty($plugin_info['x-spm-expiry']) ? '' : $plugin_info['x-spm-expiry'].',';
					if ($how_many_expiring_soon == count($user_addons)) {
						// All non-expired add-ons are expiring soon
						$plugin_info['x-spm-expiry'] .= 'soon';
					} else {
						// Not all non-expired add-ons are expiring soon
						$plugin_info['x-spm-expiry'] .= 'soonpartial_'.$how_many_expiring_soon.'_'.count($user_addons);
					}
				}
			}

			$download = $this->calculate_download($dinfo);
			
			if (!empty($download['filename'])) {
				$unpacked_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid).'/_up_'.$download['filename'];
			}

			if (null !== ($subscription_active = apply_filters('updraftmanager_subscriptions_active', null, $user->ID, $this->slug))) $plugin_info['x-spm-subscription-active'] = $subscription_active;

			if (is_string($user_support) && 'expired' == $user_support) {
				$plugin_info['x-spm-support-expiry'] = 'expired';
			} else {
				$support_expires_soon = false;
				foreach ($user_support as $ind => $support) {
					if (is_array($support) && !empty($support['expire_date']) && $support['expire_date'] > 0) {
						if (time() + 28*86400 >= $support['expire_date']) {
							if (false === $support_expires_soon) $support_expires_soon = true;
						} else {
							$support_expires_soon = 0;
						}
					}
				}
				if ($support_expires_soon) $plugin_info['x-spm-support-expiry'] = 'soon';
			}

			// An optimisation to use one transient instead of two; leading to two less SELECT calls
			$save_double_transient = false;
			$filename = isset($download['filename']) ? $download['filename'] : '';

			$transient_key = 'udm_aands_'.$this->uid.'_'.$this->slug.'_'.substr(md5($filename), 0, 12);
			
			if (!$this->free && !empty($this->addonsdir) && isset($unpacked_dir) && !empty($download['version']) && is_array($user_addons)) {
				// $all_addons = false;
				foreach ($user_addons as $addon) {
				
					if (!isset($addon['key'])) continue;
					$keyhash .= ('' == $keyhash) ? $addon['key'] : ','.$addon['key'];
					if ('all' != $addon['key']) continue;
					
					// $all_addons = true;
					$extra_descrip .= "<li>".'All add-ons'."</li>";
					
					$addons_and_sections = get_transient($transient_key);
					
					if (!empty($addons_and_sections['sections'])) $readme_sections = $addons_and_sections['sections'];
					
					if (!empty($addons_and_sections['pver'])) {
						$pver = $addons_and_sections['pver'];
					} else {
						// Force upgrade: one higher than all available add-ons
						$pver = count($this->scan_addons($unpacked_dir))+1;
						$save_double_transient = true;
					}

				}
			}

			if (!isset($readme_sections)) $readme_sections = $this->get_sections_from_readme($filename);
			if ($save_double_transient) {
				set_transient($transient_key, array('pver' => $pver, 'sections' => $readme_sections), 3600);
			}

			$plugin_info['sections'] = $readme_sections;
			
			/* To prevent the accumulation of squillions of transients, we issue a token which is:
			
			- Broadly deterministic: the same token will always be generated if the variables giving rise to it are the same
			- Time-based: based on day (since transient lasts 24 hours)
			- Based on user ID (can drop this later if needed - they provide us some verification for present
			- Based on what is in their version (can vary between sites)
			- Also based on $pass, to prevent valid tokens being externally deterministic
			- The token is hashed, so there's no way to reverse engineer it into its components
			*/

			// Only if entitled do we bother passing a non-default version
			// Authorise for 7 days (needs to match the maximum update checking interval)
			$now_time = time();
			$token_time = $now_time - ($now_time % (7*86400));

			if (is_array($download) && isset($download['filename'])) {

				$plugin_info['requires'] = $download['minwpver'];
				$plugin_info['tested'] = $download['testedwpver'];

				if ($user->ID || $this->free || $this->downloadable_base_plugin) {
					$download_data = array(
						'id' => $user->ID,
						'sid' => $sid,
						'download' => $download['filename'],
						'uid' => $this->uid,
						'slug' => $this->slug,
						'pluginfile' => $download['pluginfile']
					);

	/*
					// Don't store it as a transient and send back the database key; instead, encrypt it and then decrypt it when it gets sent back. Transients cause two MySQL UPDATEs.
					$token = md5($user->ID.'-'.$this->uid.'-'.$this->slug.'-'.$sid.'-'.$token_time.'-'.$keyhash);
					set_transient('uddownld_'.$token, $download_data, 7*86400);
	*/

					$download_data['ctime'] = time();

					$download_data = apply_filters('updraftmanager_download_data', $download_data, $user, $si, $ssl);

					$etoken = urlencode($this->encrypt(json_encode($download_data)));

					// The final parameter is because WordPress uses the URL to construct a temporary directory using basename($url), which can be long and risk overflowing 256-character limits (e.g. on WAMP)
					$plugin_download_url = apply_filters('updraftmanager_downloadbase', home_url('', ($ssl) ? 'https' : 'http'), $ssl).'/?udm_action=download&slug='.$this->slug.'&muid='.$this->uid;
	
					$plugin_download_url .= empty($etoken) ? '' : '&etoken='.$etoken;
	
					$plugin_download_url .= '&ig=/'.substr(md5(time()),0,8);
				}

				if (!$this->free && !empty($this->addonsdir) && isset($unpacked_dir)) {
					$plugin_version = isset($pver) ? $download['version'].'.'.$pver : $download['version'];

					if (is_array($user_addons)) {
						foreach ($user_addons as $addon) {
							$file = $unpacked_dir.'/'.$this->slug.'/'.$this->addonsdir.'/'.$addon['key'].'.php';

							if (is_file($file)) {
								$info = $this->get_addon_info($file);
								if ($info) $extra_descrip .= "<li>".htmlspecialchars($info['name'])." - ".htmlspecialchars($info['description']).'</li>';
							}
						}
					}
				} else {
					$plugin_version = $download['version'];
				}
				if ($extra_descrip) $description.='<p><strong>'.__('Add-ons for this site:', 'simba-plugin-updates-manager').'</strong></p><ul>'.$extra_descrip.'</ul>';

			}

		}

		$plugin_info['sections']['description'] = $description;

		if (empty($plugin_version) && $installed_version) $plugin_version = $installed_version;

		if (isset($plugin_version)) $plugin_info['version'] = $plugin_version;

		if (isset($plugin_download_url)) $plugin_info['download_url'] = $plugin_download_url;

		/* Finally - send back info on what WP versions their *current* install has been tested on */
		if ($installed_version && is_array($this->plugin['zips'])) {
			if (!empty($this->addonsdir) && preg_match('/^(.+)\.(\d+)$/', $installed_version, $matches)) $installed_version = $matches[1];
			$yourversion_tested = false;
			foreach ($this->plugin['zips'] as $zip) {
				if (!empty($zip['version']) && $zip['version'] == $installed_version) {
					if (empty($zip['testedwpver'])) continue;
					if ($yourversion_tested === false) {
						$yourversion_tested = $zip['testedwpver'];
					} elseif ($yourversion_tested != $zip['testedwpver']) {
						$yourversion_tested = -1;
					}
				}
			}
			if ($yourversion_tested > 0) $plugin_info['x-spm-yourversion-tested'] = $yourversion_tested;
		}
		
		if (!empty($spm_meta_info)) $plugin_info['x-spm-meta'] = json_encode($spm_meta_info);

		return apply_filters('updraftmanager_plugin_info', $plugin_info, $this->plugin, $download, $si);
	}

	/**
	 * @param Array $filename - in the format returned from calculate_download() in the 'filename' key
	 * @param Array $params - options. Valid keys: maximum_changelog_sections
	 *
	 * @return Array
	 */
	public function get_sections_from_readme($filename, $params = array('maximum_changelog_sections' => 4)) {
	
		$maximum_changelog_sections = isset($params['maximum_changelog_sections']) ? $params['maximum_changelog_sections'] : 4;

		if ($filename) {
			$unpacked_dir = UpdraftManager_Options::get_manager_dir(false, $this->uid).'/_up_'.$filename;
			$transient_name = 'spm_readme_secs_'.substr(md5($unpacked_dir.':'.$maximum_changelog_sections), 0, 12);
			$sections_from_readme = get_transient($transient_name);
		}
		
		if (!empty($sections_from_readme) && is_array($sections_from_readme)) return $sections_from_readme;

		$sections_from_readme = array();
		
// 		$sections_grokked = array('changelog' => '', 'frequently asked questions' => '', 'installation' => '', 'screenshots' => '');
		$sections_grokked = array('changelog' => '', 'frequently asked questions' => '');
		
		if (isset($unpacked_dir)) {
			if (is_readable($unpacked_dir.'/'.$this->slug.'/readme.txt')) {
				$readme_lines = file($unpacked_dir.'/'.$this->slug.'/readme.txt');
				$current_section = false;
				$how_many_divisions = 0;
				foreach ($readme_lines as $cl) {
					
					$cl = preg_replace('#_#', '\_', $cl);
					$cl = htmlspecialchars($cl);
					
					if (preg_match('/^==(.*)==\s+$/', $cl, $matches)) {
						$current_section = strtolower(trim($matches[1]));~
						$how_many_divisions = 0;
					} elseif (isset($sections_grokked[$current_section])) {
						if ('changelog' == $current_section && ($how_many_divisions > $maximum_changelog_sections || $sections_grokked[$current_section] > 10240)) continue;
						if (preg_match('/^=(.*)=\s+$/', $cl, $matches)) {
							$how_many_divisions++;
							$sections_grokked[$current_section] .= '<h4><strong>'.$matches[1].'</strong></h4>';
							if ($how_many_divisions > $maximum_changelog_sections && 'changelog' == $current_section) $sections_grokked[$current_section] .= "\n...";
						} else {
							$sections_grokked[$current_section] .= $cl;
						}
					}
				}
			}
			if (empty($sections_grokked['changelog']) && is_readable($unpacked_dir.'/'.$this->slug.'/changelog.txt')) {
				$sections_grokked['changelog'] = file_get_contents($unpacked_dir.'/'.$this->slug.'/changelog.txt');
			}
			//$plugin_info['sections']['changelog'] = ...
		}

		$sections_grokked['faq'] = $sections_grokked['frequently asked questions'];
		unset($sections_grokked['frequently asked questions']);

		foreach ($sections_grokked as $section => $content) {
			if (empty($content)) continue;
			if (!method_exists('Markdown', 'defaultTransform')) require_once(UDMANAGER_DIR.'/vendor/michelf/php-markdown/Michelf/Markdown.inc.php');
			$sections_from_readme[$section] = Markdown::defaultTransform($content);
		}

		if (isset($transient_name)) set_transient($transient_name, $sections_from_readme, 86400);

		return $sections_from_readme;
		
	}
	
	// Show the logged-in user's support entitlements
	public function home_support() {

		$ret="";

		if (!is_user_logged_in()) return __("You need to be logged in to see this information", 'updraftmanager');

		$user_support = $this->get_user_support_entitlements();

		$ret .= '<ul>';
		if (count($user_support) == 0) {
			$ret .= "<li><em>".__('None yet purchased', 'simba-plugin-updates-manager')."</em></li>";
		}
		foreach ($user_support as $support) {
		// keys: status (unused / used / expired), purchasedate, expire_date, response time, method

			if ($support['expire_date'] && $support['expire_date'] < time()) {
				$statm = __('Has expired', 'simba-plugin-updates-manager');
			} else {
				if (empty($support['status'])) continue;
				switch ($support['status']) {
					case 'used':
						$statm = __('Has been used', 'simba-plugin-updates-manager');
						break;
					case 'unused':
						$statm = __('Not yet used', 'simba-plugin-updates-manager');
						break;
					case 'active':
						$statm = __('Active ongoing subscription', 'simba-plugin-updates-manager');
						if (is_numeric($support['expire_date'])) $statm .= ' '.sprintf(__("(expires: %s)", 'updraftmanager'), date("Y-m-d", $support['expire_date']));
						break;
					default:
						$statm = __("Unrecognised status code", 'updraftmanager')." (".$support['status'].")";
						break;
				}
			}

			global $wpdb;

			// support_type values:
			$support_type = $wpdb->get_row("SELECT name FROM $wpdb->terms WHERE slug='".esc_sql($support['support_type'])."'");
			if (isset($support_type->name)) $statm .= " (".$support_type->name.")";

			// Response time
			$response_time = $wpdb->get_row("SELECT name FROM $wpdb->terms WHERE slug='".esc_sql($support['response_time'])."'");
			if (isset($response_time->name)) $statm .= " (".$response_time->name.")";

			$pdate = date('d F Y', $support['purchase_date']);
			$ret .= "<li><strong>".__('Purchased', 'simba-plugin-updates-manager')." $pdate:</strong> $statm</li>\n";

		}

		$ret .= '</ul>';
		
		return $ret;

	}

	public function addons_sort_purchased_first($a, $b) {
		if (in_array($a, $this->user_addons_present) == in_array($b, $this->user_addons_present)) {
			if ($a == 'all' && $b == 'all') {
				return 0;
			} elseif ($a == 'all') {
				return -1;
			} elseif ($b == 'all') {
				return 1;
			} else {
				return 0;
			}
		} elseif (in_array($a, $this->user_addons_present)) {
			return -1;
		} else {
			return 1;
		}
	}
	
	/**
	 * Given an entitlement and the last-checkin, say whether this entitlement can be reset by the current user, or not
	 *
	 * @param Array $entitlement - the entitlement
	 * @param Integer $last_checkin - the last check-in, in epoch time
	 *
	 * @return Boolean|Integer - false if the entitlement is not reset-able (e.g. is unclaimed); otherwise, a time from whence it can be reset (any time in the past will suffice if you want to make it reset-able). N.B. Remember to distinguish between false and (int)0 if parsing the results.
	 */
	public function entitlement_when_can_be_reset($entitlement, $last_checkin) {
	
		// By default, an entitlement can be reset if it is assigned, and by a user who is a manager (always), or by an ordinary user if a sufficient amount of time has passed since the last check-in
		
		$when_can_be_reset = false;
		
		if ('unclaimed' != $entitlement['site'] && !empty($entitlement['expires']) && $entitlement['expires'] > 0 && 'unlimited' != $entitlement['site']) {
		
			if (current_user_can(UpdraftManager_Options::manage_permission('licences'))) {
				$when_can_be_reset = -1;
			} elseif ($last_checkin > 0) {
				// Default: you can reset the licence 30 days after the last check-in (over-ride with the filter below)
				$when_can_be_reset = $last_checkin + 86400 * 30;
			}
			
		}
		
		return apply_filters('updraftmanager_entitlement_when_can_be_reset', $when_can_be_reset, $last_checkin, $entitlement, $this);
	}

	// Show the logged-in user's add-on entitlements; also used for the admin's management of user entitlements
	// Valid values for $show_unpurchased : all, free, none
	// $show_link: show download link
	// $group_available: At the time of writing, no consumers adjust this option - I can't see the advantages of not using it.
	public function home_addons($show_link, $show_unpurchased, $show_addons, $user_id = false, $group_available = true) {

		// This used to call UpdraftManager_Options::get_user_id_for_licences() - but that's wrong; on the front-end, we only want to see the current logged-in user's stuff
		if (false === $user_id) $user_id = apply_filters('updraftmanager_home_addons_user_id', get_current_user_id());

		$ret = '<div class="udmanager_show_addons_'.$this->uid.'_'.$this->slug.'">';

		$this->user_can_manage = current_user_can(UpdraftManager_Options::manage_permission('licences'));

		$user_addons = $this->get_user_addon_entitlements($user_id);

		// Get the default download
		$download = $this->calculate_download();

		// Returns an array of arrays
		// Keys: name (short), description (longer), shopurl (stub)

		$addons = array();
		if ($show_addons>0) $addons = (is_array($download)) ? $this->get_available_addons(UpdraftManager_Options::get_manager_dir(false, $this->uid.'/_up_'.$download['filename']), $download) : array();
		# For plugins with no add-ons, or if not showing addons
		if ($this->free || empty($addons)) {
			$addons = array(array('key' => 'all', 'name' => $this->plugin_name, 'description' => $this->plugin_descrip, 'latestversion' => ((!empty($download['version'])) ? $download['version'] : ''), 'shopurl' => $this->homepage, 'latestchange' => null, 'include' => null));
		} elseif ((2 == $show_addons || 0 == $show_addons) && !isset($addons['all'])) {
			array_unshift($addons, array('key' => 'all', 'name' => $this->plugin_name, 'description' => $this->plugin_descrip, 'latestversion' => ((!empty($download['version'])) ? $download['version'] : ''), 'shopurl' => $this->homepage, 'latestchange' => null, 'include' => null));
		}

		$ret .= '<div class="udmanager-addonstable">';

		if ($this->user_can_manage) {
			// is_admin() - only on the admin area (back-end) - not for any particular reason beyond that that suffices for now.
			if (is_admin()) {
				$ret .= '<div style="margin: 10px 0;" data-slug="'.$this->slug.'"><span class="udmplugin_set_all_expiries" data-slug="'.$this->slug.'">'.__('Reset expiry date for all licences', 'simba-plugin-updates-manager').':</span> <input style="width: 100px;" type="text" placeholder="YYYY-MM-DD" id="udmplugin_expiry_result_'.$this->slug.'" value=""> ';
				$ret .= '<button class="button button-primary udmplugin_expiry_reset">'.__('Reset', 'updraftplus').'</button></div>';
			}
		}

		$last_checkins = $this->db_get_last_checkins($user_id);

		$index = 0;

		$user_addons_present = array();
		foreach ($user_addons as $addon) {
			if (!is_array($addon) || empty($addon['key'])) continue;
			$key = $addon['key'];
			if (!in_array($key, $user_addons_present)) $user_addons_present[] = $key;
		}
		$this->user_addons_present = $user_addons_present;
		uksort($addons, array($this, 'addons_sort_purchased_first'));

		$time_now = time();
		
		foreach ($addons as $key => $addon) {
			$index++;

			// Keys: name, description, shopurl

			// Has the user bought it? Inspect user_addons to find out.
			// We then need to pass in the information on those purchases - remember, there may be multiple.

			// TODO: Automatically release unused licences (e.g. unaccessed for >= 1 month, or the oldest of duplicate URLs unaccessed for >1 day)

			$show_it = false;
			$can_download = false;
			$available_count = 0;
			$total_unexpired_count = 0;

			$in_use_on_sites = apply_filters('updraftmanager_addonbox_start', '', $key, $user_id, $addon, $user_addons, $this);
			
			// Key: (string)expiry-related message. Value: (int)how many
			$available_groups = array();

			$any_unexpired = false;
			
			foreach ($user_addons as $uid => $useradd) {
			
				// keys are site, sitedescription, key, status, expires

				$last_checkin = false;
				
				if (($addon['key'] != $useradd['key']) || $useradd['status'] != 'active') continue;
				
				// If we expired more than a month ago, then show nothing
				//if (isset($useradd['expires']) && $useradd['expires'] > 0 && time() > $useradd['expires'] + 86400*30) continue;
				if (!empty($useradd['expires'])) {
					if ($useradd['expires'] < 0) {
						$expires = __('never expires', 'simba-plugin-updates-manager');
					} else {
						$expires = date_i18n('Y-m-d', $useradd['expires']);
					}
				} else {
					$expires = '';
				}
				
				// If possible, access the more up-to-date array of site URLs, rather than the original one.
				if (!empty($last_checkins) && is_array($last_checkins) && !empty($useradd['site']) && !empty($last_checkins[$useradd['site']]['site_url'])) {
					$sitedescription = $last_checkins[$useradd['site']]['site_url'];
					if (!empty($last_checkins[$useradd['site']]['time'])) {
						$last_checkin = $last_checkins[$useradd['site']]['time'];
					}
				} else {
					$sitedescription = empty($useradd['sitedescription']) ? '' : $useradd['sitedescription'];
				}
				
				if (false == apply_filters('updraftmanager_showaddon', true, $useradd, $last_checkin, $this, $user_id, $download, $show_unpurchased, $show_addons, $group_available)) continue;
				
				$show_it = true;
				$can_download = true;
				$expired = false;
				
				$when_can_be_reset = $this->entitlement_when_can_be_reset($useradd, $last_checkin);

				if (!empty($useradd['expires']) && $useradd['expires'] > 0 && $time_now >= $useradd['expires']) {
					$expired = true;
					if ($sitedescription) {
						$in_use_on_sites .= "<strong>".sprintf(__('Expired updates subscription (%s) on:', 'simba-plugin-updates-manager'), $expires)."</strong> ".htmlspecialchars($sitedescription);
					} else {
						$message = "<strong>".sprintf(__('Expired updates subscription (%s)', 'simba-plugin-updates-manager'), $expires)."</strong>";
						if ($group_available) {
							$available_groups[$message] = isset($available_groups[$message]) ? $available_groups[$message] + 1 : 1;
						} else {
							$in_use_on_sites .= $message;
						}
					}
				} else {
				
					$any_unexpired = true;
					$total_unexpired_count++;
					
					if ('unclaimed' == $useradd['site']) {
						$available_count++;
						if (!$group_available) {
							$in_use_on_sites .= apply_filters('updraftmanager_unactivatedpurchase', "<strong>".__('You have an available licence', 'simba-plugin-updates-manager')."</strong>", $useradd, false);
						}
					} elseif ('unlimited' == $useradd['site']) {
						$in_use_on_sites .= "<strong>".__('Active:', 'simba-plugin-updates-manager').'</strong> '.__('unlimited entitlement.','updraftmanager');
					} else {
						$in_use_on_sites .= "<strong>".__('Assigned:', 'simba-plugin-updates-manager')."</strong> ".htmlspecialchars($sitedescription);
						
						$user_can_delete_assignment = apply_filters('updraftmanager_usercandeleteassignment', false, $useradd, $uid, $expired, $this->free,  $this->user_can_manage, $user_addons);
						
						if ($user_can_delete_assignment) {
							if (!is_admin()) $this->enqueue_usermanage_script($this->uid);
							$in_use_on_sites .= ' - <a href="#" data-entitlementid="'.esc_attr($uid).'" class="udmanager_entitlement_delete">'.__('Delete assignment', 'simba-plugin-updates-manager').'</a>';
						}
						
						$in_use_on_sites = apply_filters('updraftmanager_after_assigned', $in_use_on_sites, $useradd, $uid, $expired, $this->free, $this->user_can_manage, $user_addons, $this);
					}
				}
				
				// Don't show a reset link if they can just delete it (makes for a confusing UX to have both)
				if (empty($user_can_delete_assignment) && false !== $when_can_be_reset && !$expired) {
					if ($time_now >= $when_can_be_reset) {
						// This method and the enqueued JS is in the 'premium' sub-directory (from the old division). It would be neater to combine it at some point.
						if (!is_admin()) $this->enqueue_usermanage_script($this->uid);
						$in_use_on_sites .= ' - <a href="#" data-userid="'.esc_attr($user_id).'" data-entitlementid="'.esc_attr($uid).'" class="udmanager_entitlement_reset">'.__('Reset (i.e. release from site)', 'simba-plugin-updates-manager').'</a>';
					} else {
						// If the site is still checking in, don't cause confusion with an unnecessary message
						$show_message = ($time_now - $last_checkin > 5 * 86400);
						
						if (apply_filters('updraftmanager_show_can_release_after_message', $show_message, $last_checkin, $this, $when_can_be_reset, $useradd)) {
							$days_until_reset = round(($when_can_be_reset - $time_now) / 86400, 1);
							$in_use_on_sites .= ' - '.sprintf(_n('If de-installed, can be released here after another %s day', 'If de-installed, can be released here after another %s days', $days_until_reset, 'updraftmanager'), $days_until_reset);
						}
					}
				}
				
				if ($this->user_can_manage) $in_use_on_sites = '<span title="'.esc_attr($uid).'">'.$in_use_on_sites.'</span>';

				if ($expires && !$expired) {

					$when_expires_message = '<br><span style="font-size:85%"><em>'.sprintf(__('Update subscription expires: %s', 'simba-plugin-updates-manager'), $expires).'.</em></span>';
				
					if ($last_checkin || !$group_available || 'unclaimed' != $useradd['site']) {
						$in_use_on_sites .= $when_expires_message;
					}
				
					if ($last_checkin > 0) {

						$in_use_on_sites .= ' <span style="font-size: 85%;"><em>'.sprintf(__('Last check-in: %s', 'simba-plugin-updates-manager'), date_i18n('Y-m-d H:i:s', $last_checkin)).'.</em></span>';

					} elseif ($group_available && 'unclaimed' == $useradd['site']) {
					
						if ($group_available && 'unclaimed' == $useradd['site']) {
							$available_groups[$when_expires_message] = isset($available_groups[$when_expires_message]) ? $available_groups[$when_expires_message] + 1 : 1;
						}
					
					}

				}

				if ('<br>' != substr($in_use_on_sites, -4)) $in_use_on_sites .= '<br>';

				if (!$this->free) {
				
					if ($this->user_can_manage) {
						$in_use_on_sites .= '<a href="#" data-entitlementid="'.esc_attr($uid).'" class="udmanager_entitlement_delete">'.__('Delete entitlement', 'simba-plugin-updates-manager').'</a>'
						.(($useradd['expires'] > 0) ? ' | <a href="#" data-entitlementid="'.esc_attr($uid).'" class="udmanager_entitlement_extend"> '.__('Extend entitlement', 'simba-plugin-updates-manager').'</a>' : '');
						$in_use_on_sites .= '<br>&nbsp;<br>';
					}
					
				}
				
				$in_use_on_sites = apply_filters('updraftmanager_inuseonsites', $in_use_on_sites, $this->free, $this->user_can_manage, $useradd, $uid, $user_addons);

			}
			
			if ($group_available && $available_count > 0) {
				// N.B. The filter is subtly different to updraftmanager_unactivatedpurchases
				$in_use_on_sites .= apply_filters('updraftmanager_unactivatedpurchases', '<strong>'.sprintf(_n('You have %d available licence', 'You have %d available licences', $available_count, 'updraftmanager'), $available_count).' ('.sprintf(__('total unexpired licences: %d', 'simba-plugin-updates-manager'), $total_unexpired_count).')</strong>', $available_count, $user_addons, $available_groups, $key, $addon);
				foreach ($available_groups as $message => $how_many) {
					$in_use_on_sites .= (1 == $how_many) ? $message : $message . ' <span style="font-size: 85%;">('.sprintf(__('%d times', 'simba-plugin-updates-manager').')</span>', $how_many);
				}
			} else {
				$in_use_on_sites .= sprintf(__('Total unexpired licences: %d', 'simba-plugin-updates-manager'), $total_unexpired_count);
			}
			
			if ('all' == $show_unpurchased || ($this->free && 'free' == $show_unpurchased)) $show_it = true;
			if ($this->free) $can_download = true;

			// Not yet supported. What is available for download may vary between sites (and the previous call to calculate_download() will have returned an empty result). But, we provide filters for flexibility.
			if ($this->addonsdir) $can_download = false;

			if ($show_it) {

				if ($show_link && $can_download && !empty($download['filename']) && !empty($download['version'])) {

					$now_time = time();
					$token_time = $now_time - ($now_time % (7*86400));

					$token = md5($user_id.'-'.$this->uid.'-'.$this->slug.'-0-'.$token_time.'-'.rand());

					set_transient('uddownld_'.$token, array(
						'id' => $user_id,
						'sid' => false,
						'download' => $download['filename'],
						'uid' => $this->uid,
						'slug' => $this->slug,
						'pluginfile' => $download['pluginfile'],
						'mustbeloggedin' => $this->free ? false : true
					), 7*86400);

					$ssl = is_ssl();

					// The final parameter is because WordPress uses the URL to construct a temporary directory using basename($url), which can be long and risk overflowing 256-character limits (e.g. on WAMP)
					// TODO: Replace token with etoken??
					$plugin_download_url = apply_filters('updraftmanager_downloadbase', home_url('', ($ssl) ? 'https' : 'http'), $ssl).'/?udm_action=download&slug='.$this->slug.'&muid='.$this->uid.'&token='.$token.'&ig=/'.substr(md5(time()),0,8);
					
					if (!empty($any_unexpired)) {
						$in_use_on_sites .= '<p><a class="updraftmanager-download-link" href="'.esc_attr($plugin_download_url).'">'.sprintf(__('Download %s version %s', 'simba-plugin-updates-manager'), '<span class="updraftmanager-download-link--plugin-name">'.$this->plugin_name.'</span>', '<span class="updraftmanager-download-link--plugin-version">'.$download['version'].'</span>').'</a></p>';
					}
				}

				$any_unexpired = empty($any_unexpired) ? false : true;
				
				$in_use_on_sites = apply_filters('updraftmanager_homeaddons_addon_description', $in_use_on_sites, $key, $user_id, $addon, $any_unexpired, $user_addons, $this);
				
				if (apply_filters('updraftmanager_show_addon_box', true, $this, $user_id, $addon, $in_use_on_sites, $any_unexpired, $download)) {
					$ret .= $this->addonbox($addon['name'], apply_filters('updraftmanager_shopurl', $addon['shopurl']), $addon['description'], $addon['latestversion'], $in_use_on_sites, $key, $user_id);
				}

			}

		}

		$ret .= '</div>';

		$ret .= '</div>';

		return $ret;

	}

}
