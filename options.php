<?php
if (!defined ('ABSPATH')) die ('No direct access allowed');

/* TODO:

Some of these tasks are obsolete or complete - needs pruning

Test - re-check for any possible leaks

Not sure if WP_List_Table sanitises HTML for us.

Downloads tracking - show the results

Need to re-write a user entitlement 

Need to show the download URL for the zip in the shortcode code - optionally (so, must also activate the options page)

With free plugins, usernames are ignored - should note this somewhere in the zip rules stuff (+ prevent use of that field)

*/

class UpdraftManager_Options {

	private $upload_dir;
	private $upload_basedir;

	public function __construct() {
		add_action('admin_head', array($this, 'admin_head'));
		add_action('admin_menu', array($this, 'admin_menu'));
		add_filter('plugin_action_links', array($this, 'action_links'), 10, 2 );
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('wp_ajax_udmanager_ajax', array($this, 'ajax_handler'));
		add_action('wp_ajax_spm_plupload_action', array($this, 'spm_plupload_action'));
	}
//spm-zip-uploader

	public function upload_dir($uploads) {
		if (!empty($this->upload_dir)) $uploads['path'] = $this->upload_dir;
		if (!empty($this->upload_basedir)) $uploads['basedir'] = $this->upload_basedir;
		return $uploads;
	}

	public function spm_plupload_action() {
		// check ajax nonce

		@set_time_limit(900);

		if (!current_user_can($this->manage_permission())) return;
		
		check_ajax_referer('spm-zip-uploader');

		$upload_dir = untrailingslashit(get_temp_dir());
		if (!is_writable($upload_dir)) exit;
		$this->upload_dir = $upload_dir;

		add_filter('upload_dir', array($this, 'upload_dir'));
		// handle file upload

		$farray = array('test_form' => true, 'action' => 'spm_plupload_action');

		$farray['test_type'] = false;
		$farray['ext'] = 'zip';
		$farray['type'] = 'application/zip';

// 		if (isset($_POST['chunks'])) {
// 
// 		} else {
// 			# Over-write - that's OK.
// 			$farray['unique_filename_callback'] = array($this, 'unique_filename_callback');
// 		}

		$status = wp_handle_upload(
			$_FILES['async-upload'],
			$farray
		);
		remove_filter('upload_dir', array($this, 'upload_dir'));

		if (isset($status['error'])) {
			echo json_encode(array('result' => false, 'message' => $status['error']));
			exit;
		}

		# Should be a no-op
		$name = basename($_POST['name']);

		// If this was the chunk, then we should instead be concatenating onto the final file
		if (isset($_POST['chunks']) && isset($_POST['chunk']) && preg_match('/^[0-9]+$/',$_POST['chunk'])) {
		
			$chunk = $_POST['chunk'];
		
			# A random element is added, because otherwise it is theoretically possible for another user to upload into a shared temporary directory in between the upload and install, and over-write
			$final_file = $name;
			rename($status['file'], $upload_dir.'/'.$final_file.'.'.$chunk.'.zip.tmp');
			$status['file'] = $upload_dir.'/'.$final_file.'.'.$chunk.'.zip.tmp';

			// Final chunk? If so, then stich it all back together
			if ($chunk == $_POST['chunks']-1) {
				if ($wh = fopen($upload_dir.'/'.$final_file, 'wb')) {
					for ($i=0 ; $i<$_POST['chunks']; $i++) {
						$rf = $upload_dir.'/'.$final_file.'.'.$i.'.zip.tmp';
						if ($rh = fopen($rf, 'rb')) {
							while ($line = fread($rh, 32768)) fwrite($wh, $line);
							fclose($rh);
							@unlink($rf);
						}
					}
					fclose($wh);
					$status['file'] = $upload_dir.'/'.$final_file;
				}
			}

		}

		if (!isset($_POST['chunks']) || (isset($_POST['chunk']) && $_POST['chunk'] == $_POST['chunks']-1)) {
			$file = basename($status['file']);
			if (!preg_match('/\.zip$/i', $file, $matches)) {
				@unlink($status['file']);
				echo json_encode(array('result' => false, 'message' => sprintf(__('Error: %s', 'udmanager'), __('This file does not appear to be a zip file.', 'udmanager'))));
				exit;
			}
		}
		
		$process_result = $this->import_local_zip_file($status['file'], true);
		
		echo json_encode($process_result);
		exit;
	}

	public function add_new_zip_go_engine_options_postunzip($options, $zip_dir, $slug, $found_plugin) {
		if (isset($options['minwpver']) && isset($options['testedwpver'])) return $options;

		$readme_file = $zip_dir.'/'.$slug.'/readme.txt';
		if (!file_exists($readme_file)) return $options;
				
		if (false == ($fh = fopen($zip_dir.'/'.$slug.'/readme.txt', 'r'))) return $options;
		
		$minwpver = false;
		$testedwpver = false;
		$line_number = 0;
		
		while ($line_number < 50 && (false === $minwpver || false === $testedwpver) && $line = fgets($fh)) {
			$line = trim($line);
			if ($minwpver === false && preg_match("/^Requires at least: ([\d\.]+)$/i", $line, $matches)) {
				$minwpver = $matches[1];
			} elseif ($testedwpver === false && preg_match("/^Tested up to: ([\d\.]+)$/i", $line, $matches)) {
				$testedwpver = $matches[1];
			}
			$line_number ++;
		}
		fclose($fh);
		
		if (false !== $minwpver) $options['minwpver'] = $minwpver;
		if (false !== $testedwpver) $options['testedwpver'] = $testedwpver;
		if (isset($_POST['addrule']) && 'true' === $_POST['addrule']) $options['addrule'] = true;
		
		return $options;
		
	}
	
	// $uid == false means 'current user'; $uid == null means "get all users"
	public static function get_options($uid = false) {
		if (false === $uid) $uid = self::get_user_id_for_licences();
		global $wpdb;

		// Allow the caller to get all plugins from all user IDs
		if (null === $uid) {
			$sql = "SELECT * FROM ".Updraft_Manager::get_plugins_table();
		} else {
			$sql = $wpdb->prepare("SELECT * FROM ".Updraft_Manager::get_plugins_table()." WHERE owner_user_id=%d", $uid);
		}

		$plugin_results = $wpdb->get_results($sql, ARRAY_A);
		// Re-key off the slug
		foreach ($plugin_results as $k => $plugin) {
			$plugin['zips'] = maybe_unserialize($plugin['zips']);
			$plugin['rules'] = maybe_unserialize($plugin['rules']);
			$plugin['meta'] = empty($plugin['meta']) ? array() : maybe_unserialize($plugin['meta']);
			$slug = $plugin['slug'];
			if (null !== $uid) unset($plugin['owner_user_id']);
			$plugin_results[$slug] = $plugin;
			unset($plugin_results[$k]);
		}
		return $plugin_results;
// 		return get_user_meta($uid, 'updraftmanager_plugins', true);
	}

// 	public static function update_options($opts, $uid = false) {
// 		if (false === $uid) $uid = self::get_user_id_for_licences();
// 		return update_user_meta($uid, 'updraftmanager_plugins', $opts);
// 		#return update_option('updraftmanager_plugins', $opts);
// 	}

	public static function delete_plugin($plugin, $uid = false) {
		if (false === $uid) $uid = self::get_user_id_for_licences();
		global $wpdb;
		return $wpdb->delete(Updraft_Manager::get_plugins_table(), array(
			'slug' => $plugin['slug'],
			'owner_user_id' => $uid
		));
	}

	public static function update_plugin($plugin, $method = 'update', $uid = false) {
		if (false === $uid) $uid = self::get_user_id_for_licences();

		global $wpdb;
		
		$plugin_original = $plugin;

		$table_columns = array('slug', 'name', 'description', 'author', 'zips', 'addonsdir', 'active', 'rules', 'homepage', 'freeplugin', 'meta');

		$update_array = array();
		foreach ($table_columns as $col) {
			if (isset($plugin[$col])) {
				if ('meta' == $col) {
					 $update_array['meta'] = empty($plugin['meta']) ? '' : serialize($plugin[$col]);
				} else {
					$update_array[$col] = ('zips' == $col || 'rules' == $col) ? serialize($plugin[$col]) : $plugin[$col];
				}
				unset($plugin[$col]);
			}
		}

		$update_array['owner_user_id'] = $uid;

		// This should not be set to begin with, unless it gets passed from self::get_options(null)
		unset($plugin['owner_user_id']);
		
		$meta = apply_filters('updraftmanager_update_plugin_meta', $plugin, $plugin_original, $method, $uid);

		$update_array = apply_filters('updraftmanager_update_plugin_data', $update_array, $plugin_original, $method, $uid);
		
		do_action('updraftmanager_update_plugin', $update_array, $plugin_original, $method, $uid);

		if ('update' == $method) {
			return $wpdb->update(Updraft_Manager::get_plugins_table(),
				$update_array,
				array(
					'owner_user_id' => $uid,
					'slug' => $update_array['slug']
				)
			);
		} else {
			return $wpdb->insert(Updraft_Manager::get_plugins_table(),
				$update_array
			);
		}
	}

	/**
	 * Gets either the filesystem directory or the URL used for storing zips
	 *
	 * @uses self::get_user_id_for_licences()
	 *
	 * @param Boolean		  $parent - if true, then will return the parent directory, prior to descending into per-user directories
	 * @param Boolean|Integer $uid	  - which user to return the directory for (only relevant if $parent is false). If false, then will use a default based on the results of self::get_user_id_for_licences()
	 * @param Boolean		  $url	  - if true, then returns a URL instead of a filesystem path
	 *
	 * @return String - the path or URL
	 */
	public static function get_manager_dir($parent = false, $uid = false, $url = false) {
		$upload_dir = wp_upload_dir();
		$dir = (($url) ? $upload_dir['baseurl'] : $upload_dir['basedir']).'/updraftmanager';
		if ($parent) return $dir;
		return ($uid === false) ? $dir.'/'.self::get_user_id_for_licences() : $dir.'/'.$uid;
	}

	public static function remove_local_directory($dir, $contents_only = false) {
		// PHP 5.3+ only
// 		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
// 			$path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
// 		}
// 		return ($contents_only) ? true : rmdir($dir);
		$d = dir($dir);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry) {
				if (is_dir($dir.'/'.$entry)) {
					self::remove_local_directory($dir.'/'.$entry, false);
				} else {
					@unlink($dir.'/'.$entry);
				}
			}
		}
		$d->close();
		return ($contents_only) ? true : rmdir($dir);
	}

	// plugins (default) | licences
	public static function manage_permission($for_what = 'plugins') {
		$capability_required = ('plugins' == $for_what) ? 'manage_options' : 'manage_options';
		return apply_filters('udmanager_manage_permission', $capability_required, $for_what);
	}

	// Can be used to give one user access to another's plugins
	public static function get_user_id_for_licences() {
		$default = get_current_user_id();
		return apply_filters('udmanager_user_id_for_licences', $default);
	}

	/**
	 * Called by the WP action wp_ajax_udmanager_ajax
	 */
	public function ajax_handler() {

		if (empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'updraftmanager-ajax-nonce') || empty($_REQUEST['subaction'])) die('Security check');

		switch ($_REQUEST['subaction']) {
			case 'reorderrules':
				if (!current_user_can($this->manage_permission())) return;
				if (empty($_POST['order']) || empty($_POST['slug'])) break;
				// Relevant keys: slug, order
				$plugins = $this->get_options();
				if (empty($plugins[$_POST['slug']])) break;
				$plugin = $plugins[$_POST['slug']];
				$rules = (empty($plugin['rules'])) ? array() : $plugin['rules'];
				// Sanity checks
				$newrules = array();
				$new_order = explode(',', $_POST['order']);
				foreach ($new_order as $nord) {
					if (!is_numeric($nord)) break(2);
					$ind = $nord-1;
					if ($ind<0) break(2);
					if (empty($rules[$ind])) break(2);
					$newrules[] = $rules[$ind];
				}
				// Sanity checks have been passed
				$plugin['rules'] = $newrules;
				$this->update_plugin($plugin, 'update');
				echo json_encode(array('r' => 'ok'));
				die;
			break;
		}
		if (!current_user_can($this->manage_permission('licences'))) {
			do_action('udmanager_ajax_nonmanager_event');
		} else {
			do_action('udmanager_ajax_event');
		}
		echo json_encode(array('r' => 'invalid'));
		die;
	}

	public function admin_head() {
		if (!current_user_can($this->manage_permission())) return;
		echo "<script>var updraftmanager_freeversion = 1;</script>\n";
	}

	public function admin_enqueue_scripts($hook) {
		if (strpos($hook, 'updraftmanager') === false) return;

		$use_version = (defined('WP_DEBUG') && WP_DEBUG) ? time() : UDMANAGER_VERSION;

		wp_enqueue_style('updraftmanager_css', UDMANAGER_URL.'/css/admin.css', array(), $use_version );
		wp_enqueue_script('updraftmanager-admin-js', UDMANAGER_URL.'/js/admin.js', array('jquery-ui-sortable', 'jquery-color'), $use_version);
		wp_enqueue_script('jquery-blockui', UDMANAGER_URL.'/js/jquery.blockui.js', array('jquery'));
		$managerurl = $this->get_manager_dir(false, false, true);
		wp_localize_script('updraftmanager-admin-js', 'updraftmanagerlion', array(
			'areyousureplugin' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'simba-plugin-updates-manager'), __('plugin', 'simba-plugin-updates-manager')),
			'areyousurezip' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'simba-plugin-updates-manager'), __('zip', 'simba-plugin-updates-manager')),
			'areyousurerule' => sprintf(__('Are you sure you wish to delete this %s? This action cannot be undone.', 'simba-plugin-updates-manager'), __('rule', 'simba-plugin-updates-manager')),
			'ajaxnonce' => wp_create_nonce('updraftmanager-ajax-nonce'),
			'rule' => __('Rule', 'simba-plugin-updates-manager'),
			'applyalways' => esc_attr(__('Apply this rule always', 'simba-plugin-updates-manager')),
			'random_percent' => __('On a percentage of updates checks', 'simba-plugin-updates-manager'),
			'alwaysmatch' => __('Always match', 'simba-plugin-updates-manager'),
			'version' => __('Apply this rule if the site checking already has a specified version installed', 'simba-plugin-updates-manager'),
			'installedversion' => __('Installed plugin version', 'simba-plugin-updates-manager'),
			'equals' => __('equals', 'simba-plugin-updates-manager'),
			'lessthan' => __('is at most (<=)', 'simba-plugin-updates-manager'),
			'greaterthan' => __('is at least (>=)', 'simba-plugin-updates-manager'),
			'range' => __('is between', 'simba-plugin-updates-manager'),
			'rangeexplain' => __('If you have chosen a range (in between), specify the (inclusive) end points using a comma; for example: 1.0,2.1', 'simba-plugin-updates-manager'),
			'ifwp' => esc_attr(__('Apply this rule if the site checking has a particular version of WordPress installed', 'simba-plugin-updates-manager')),
			'wpver' => __('WordPress version', 'simba-plugin-updates-manager'),
			'ifphp' => esc_attr(__('Apply this rule if the site checking has a particular version of PHP installed', 'simba-plugin-updates-manager')),
			'if_random' => esc_attr(__('Apply this rule if the site checking already has a specified version installed', 'simba-plugin-updates-manager')),
			'phpver' => __('PHP version', 'simba-plugin-updates-manager'),
			'ifusername' => esc_attr(__('Apply this rule if the site checking belongs to a specified user from this site', 'simba-plugin-updates-manager')),
			'username' => __('Username', 'simba-plugin-updates-manager'),
			'ifsiteurl' => esc_attr(__('Apply this rule if the site checking has a specific site URL', 'simba-plugin-updates-manager')),
			'processing' => __('Processing...', 'simba-plugin-updates-manager'),
			'httpnotblocked' => __('It is possible to access the contents of our the directory which we want to keep private via HTTP. This means that apparently the .htaccess file placed there is not working (perhaps your webserver uses a different mechanism). You should prevent access to this directory; otherwise unauthenticated users will be able to directly download your plugins. The directory is: ', 'simba-plugin-updates-manager').$managerurl,
			'siteurl' => __('Site URL', 'simba-plugin-updates-manager'),
			'delete' => __('Delete', 'simba-plugin-updates-manager'),
			'slug' => (empty($_GET['slug'])) ? '' : $_GET['slug'],
			'managerurl' => $managerurl
		));
	}

	public static function show_admin_warning($message, $class = "updated", $escape = true) {
		echo '<div class="'.$class.'">'."<p>".(($escape) ? htmlspecialchars($message) : $message)."</p></div>";
	}

	public function admin_menu() {

		$perm = $this->manage_permission();

		# http://codex.wordpress.org/Function_Reference/add_options_page
		add_menu_page('Simba Plugins Manager', __('Plugins Manager', 'simba-plugin-updates-manager'), $perm, 'updraftmanager', array($this, 'options_printpage'), '', '59.1756344');

		add_submenu_page('updraftmanager', __('Plugins Manager', 'simba-plugin-updates-manager').' - '.__('Add New Plugin', 'simba-plugin-updates-manager'), __('Add New', 'simba-plugin-updates-manager'), $perm, 'updraftmanager_add_new', array($this, 'options_printpage'));

		add_submenu_page('updraftmanager', __('Plugins Manager', 'simba-plugin-updates-manager').' - '.__('Upload Zip', 'simba-plugin-updates-manager'), __('Upload Zip', 'simba-plugin-updates-manager'), $perm, 'updraftmanager_upload_zip', array($this, 'options_upload_zip'));

		#add_submenu_page('updraftmanager', __('Plugins Manager', 'simba-plugin-updates-manager').' - '.__('Options', 'simba-plugin-updates-manager'), __('Options', 'simba-plugin-updates-manager'), $perm, 'updraftmanager_options', array($this, 'options_printpage'));
	}

	public function action_links($links, $file) {
		if ( $file == UDMANAGER_SLUG."/udmanager.php" ){
			array_unshift( $links, 
				'<a href="admin.php?page=updraftmanager">'.__('Plugins', 'simba-plugin-updates-manager').'</a>',
				'<a href="https://updraftplus.com">'.__('UpdraftPlus WordPress backups', 'simba-plugin-updates-manager').'</a>'
			);
		}
		return $links;
	}

	public function options_upload_zip() {
		if (!current_user_can($this->manage_permission())) wp_die( __('You do not have sufficient permissions to access this page.') );
		
		$use_version = (defined('WP_DEBUG') && WP_DEBUG) ? UDMANAGER_VERSION.'.'.time() : UDMANAGER_VERSION;
		
		wp_enqueue_script('spm-upload-zip-admin-ui', UDMANAGER_URL.'/js/plupload.js', array('jquery', 'plupload-all'), $use_version);
		
		// A chunk size of 1MB means slightly more processing, but works better on bad connections
		$chunk_size = min(wp_max_upload_size()-1024, 1024*1024-1024);

		# The multiple_queues argument is ignored in plupload 2.x (WP3.9+) - http://make.wordpress.org/core/2014/04/11/plupload-2-x-in-wordpress-3-9/
		# max_file_size is also in filters as of plupload 2.x, but in its default position is still supported for backwards-compatibility. Likewise, our use of filters.extensions below is supported by a backwards-compatibility option (the current way is filters.mime-types.extensions

		$plupload_init = array(
			'runtimes' => 'html5,flash,silverlight,html4',
			'browse_button' => 'plupload-browse-button',
			'container' => 'plupload-upload-ui',
			'drop_element' => 'drag-drop-area',
			'file_data_name' => 'async-upload',
			'multiple_queues' => false,
			'max_file_count' => 1,
			'max_file_size' => '100Gb',
			'chunk_size' => $chunk_size.'b',
			'url' => admin_url('admin-ajax.php'),
			'filters' => array(array('title' => __('Allowed Files'), 'extensions' => 'zip')),
			'multipart' => true,
			'multi_selection' => false,
			'urlstream_upload' => true,
			// additional post data to send to our ajax hook
			'multipart_params' => array(
				'_ajax_nonce' => wp_create_nonce('spm-zip-uploader'),
				'action' => 'spm_plupload_action'
			),
			'retries' => 2
		);

		$plupload_init['flash_swf_url'] = includes_url('js/plupload/plupload.flash.swf');
		$plupload_init['silverlight_xap_url'] = includes_url('js/plupload/plupload.silverlight.swf');

		wp_localize_script('spm-upload-zip-admin-ui', 'uploadziplion', array(
			'notarchive' => __('This file does not appear to be a zip file.', 'udmanager'),
			'notarchive2' => '<p>'.__('This file does not appear to be a zip file.', 'udmanager').'</p>',
			'uploaderror' => __('Upload error:','udmanager'),
			'makesure' => __('make sure that you were trying to upload a zip file','udmanager'),
			'uploaderr' => __('Upload error', 'udmanager'),
			'jsonnotunderstood' => __('Error: the server sent us a response (JSON) which we did not understand.', 'udmanager'),
			'error' => __('Error:','udmanager'),
			'plupload_config' => apply_filters('updraftmanager_plupload_config', $plupload_init)
		));
		
		?>
		<h1><?php _e('Simba Plugins Manager', 'simba-plugin-updates-manager');?></h1>
		<div class="wrap">
			<h2><?php _e('Upload a new zip', 'simba-plugin-updates-manager');?></h2>
			<p><em><?php echo __('This screen is for quickly uploading a new zip for a plugin that you have already set up.', 'simba-plugin-updates-manager').' '.__('It is intended as a quicker way than drilling down into the "Manage Zips" option for a specific plugin.', 'simba-plugin-updates-manager').' '.__('The plugin needs to include a readme.txt with at least "Tested up to" and "Requires at least" headers.', 'udmanager').' <a href="?page=updraftmanager_add_new">'.__('If you wish to set up new plugins, follow this link.', 'simba-plugin-updates-manager').'</a>';?></em></p>
			
			<p class="install-help" style="text-align:left; margin-bottom: 6px;">
				<?php _e('Upload a zip for an already-configured plugin, in the correct format, here.'); ?>
			</p>

			<div id="plupload-upload-ui" class="drag-drop" style="width: 70%;">
				<div id="drag-drop-area">
					<div class="drag-drop-inside">
						<p class="drag-drop-info"><?php _e('Drop plugin zip here', 'udmanager'); ?></p>
						<p><?php _ex('or', 'Uploader: Drop plugin zip here - or - Select File'); ?></p>
						<p class="drag-drop-buttons"><input id="plupload-browse-button" type="button" value="<?php esc_attr_e('Select File', 'udmanager'); ?>" class="button" /></p>
					</div>
				</div>
				<div id="upload-options">
					<input id="addrule" type="checkbox" name="addrule" value="yes" checked="checked">
					
					<label for="addrule"><?php _e('Make this the default download for all users of this plugin (creating a new download rule if needed).', 'simba-plugin-updates-manager');?></label>
				</div>
				<div id="filelist">
				</div>
			</div>
		</div>
		<?php
	}
	
	# This is the function outputing the HTML for our options page
	public function options_printpage() {
		if (!current_user_can($this->manage_permission())) wp_die( __('You do not have sufficient permissions to access this page.') );

		echo '<div style="clear: left;width:950px; float: left; margin-right:20px;">
		<h1>'.__('Simba Plugins Manager', 'simba-plugin-updates-manager').'</h1>';

		echo '<div class="wrap">';

		$nonce_not_required = array('add_new', 'add_new_zip');
		
		$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
		if (empty($action)) {
			global $plugin_page;
			if ('updraftmanager_' == substr($plugin_page, 0, 15)) $action = substr($plugin_page, 15);
		} else {
			if (!in_array($action, $nonce_not_required) || !empty($_POST)) {
				if (empty($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'updraftmanager-action-nonce')) die('Security check');
			}
		}

		switch ($action) {
			case 'activate':
			case 'deactivate':
				if (!empty($_GET['slug'])) {
					$slug = $_GET['slug'];
					$plugins = $this->get_options();
					if (isset($plugins[$slug])) {
						$plugins[$slug]['active'] = ('activate' == $action) ? true : false;
// 						$this->update_options($plugins);
						$this->update_plugin($plugins[$slug], 'update');
						$this->show_admin_warning(('activate' == $action) ? __('Plugin activated.', 'simba-plugin-updates-manager') : __('Plugin de-activated.', 'simba-plugin-updates-manager'));
					}
				}
				$this->show_plugins();
				break;
			case 'delete':
				if (!empty($_REQUEST['slug'])) {
					$slugs = (array) $_REQUEST['slug'];
					$plugins = $this->get_options();
					$manager_dir = $this->get_manager_dir();
					$deleted = false;
					foreach ($slugs as $slug) {
						if (isset($plugins[$slug])) {
							$zips = (!empty($plugins[$slug]['zips']) && is_array($plugins[$slug]['zips'])) ? $plugins[$slug]['zips'] : array();
							foreach ($zips as $zip) {
								if (!empty($zip['filename']) && is_file($manager_dir.'/'.$zip['filename'])) unlink($manager_dir.'/'.$zip['filename']);
							}
							$this->delete_plugin($plugins[$slug]);
							unset($plugins[$slug]);
	// 						$this->update_options($plugins);
							$deleted = true;
						}
					}
					if ($deleted) $this->show_admin_warning(__('The plugin(s) including their associated zip(s) files were successfully deleted.', 'simba-plugin-updates-manager'));
				}
				$this->show_plugins();
				break;
			case 'managezips':
			case 'add_new_zip':
			case 'add_new_zip_go':
			case 'edit_zip':
			case 'edit_zip_go':
			case 'delete_zip':
			case 'add_new_rule':
			case 'add_new_rule_go':
			case 'edit_rule':
			case 'edit_rule_go':
			case 'delete_rule':
				if (!empty($_REQUEST['slug'])) {
					$slug = $_REQUEST['slug'];
					$plugins = $this->get_options();
					if (isset($plugins[$slug])) {
						require_once(UDMANAGER_DIR.'/classes/updraftmanager-manage-zips.php');
						$udmanager_manage_zips = new UpdraftManager_Manage_Zips($slug, $plugins[$slug]);
						call_user_func(array($udmanager_manage_zips, $action));
					}
				}
				break;
			case 'edit';
				$plugins = $this->get_options();
				if (!empty($_GET['oldslug'])) {
					$slug = $_GET['oldslug'];
					if (isset($plugins[$slug])) {
						$this->add_edit_form($plugins[$slug], true);
					} else {
						$this->show_plugins();
					}
				} else {
					$this->show_plugins();
				}
				break;
			case 'edit_go':
				$this->add_edit_form(stripslashes_deep($_POST), true);
				break;
			case 'add_new':
			case 'add_new_go':
				$this->add_edit_form(stripslashes_deep($_POST));
				break;
			case 'options':
				$this->options_page();
				break;
			default:
				$this->show_plugins();
		}

		echo '</div>';

	}

	private function options_page() {
		# TODO
	}

	protected function set_free_plugin_status($x) {
		return true;
	}

	private function add_edit_form($use_values, $editing = false) {

		if (!empty($use_values['action']) && ('edit_go' == $use_values['action'] || 'add_new_go' == $use_values['action'])) {
			$errors = array();
			if (empty($use_values['name'])) {
				$errors[] = __('The plugin name cannot be empty.', 'simba-plugin-updates-manager');
			}
			$plugins = $this->get_options(); if (!is_array($plugins)) $plugins=array();
			if (empty($use_values['slug'])) {
				$errors[] = __('The plugin slug cannot be empty.', 'simba-plugin-updates-manager');
			} else {
				$slug = $use_values['slug'];
				if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
					$errors[] = __('The plugin slug should contain lower-case letters, numerals and hyphens only.', 'simba-plugin-updates-manager');
				} elseif (!$editing || $use_values['oldslug'] != $slug) {
					if (isset($plugins[$slug])) {
						$errors[] = __('A plugin already exists with that slug.', 'simba-plugin-updates-manager');
					}
				}
			}
			if (0 == count($errors)) {
				$update_method = ('edit_' == substr($use_values['action'], 0, 5)) ? 'update' : 'insert';
				// Place in database
				$existing_zips = (!empty($plugins[$slug]['zips'])) ? $plugins[$slug]['zips'] : array();
				$existing_rules = (!empty($plugins[$slug]['rules'])) ? $plugins[$slug]['rules'] : array();
				if (!empty($_REQUEST['oldslug']) && $slug != $_REQUEST['oldslug']) {
					$oldslug = $_REQUEST['oldslug'];
					$this->delete_plugin($plugins[$oldslug]);
					$update_method = 'insert';
					unset($plugins[$oldslug]);
				}
				$plugins[$slug] = apply_filters(
					'updraftmanager_add_or_update_values',
					array(
						'slug' => $slug,
						'name' => $use_values['name'],
						'description' => $use_values['description'],
						'author' => $use_values['author'],
						'zips' => $existing_zips,
						'addonsdir' => (isset($use_values['addonsdir'])) ? $use_values['addonsdir'] : '',
						'rules' => $existing_rules,
						'active' => (!empty($use_values['active'])) ? true : false,
						'homepage' => $use_values['homepage'],
						'freeplugin' => $this->set_free_plugin_status(empty($use_values['freeplugin']) ? false : true)
					),
					$update_method
				);
// 					'minwpver' => $use_values['minwpver'],
// 					'testedwpver' => $use_values['testedwpver'],
// 				$this->update_options($plugins);
				$this->update_plugin($plugins[$slug], $update_method);
				$message = ($editing) ? __('Plugin edited.', 'simba-plugin-updates-manager') : __('Plugin added.', 'simba-plugin-updates-manager');
				if (empty($plugins[$slug]['zips'])) $message .= ' <a href="?page=updraftmanager&amp;action=add_new_zip&amp;slug='.$slug.'">'.__('You should now add some zips for the plugin itself.', 'simba-plugin-updates-manager').'</a>';
				$this->show_admin_warning($message, 'updated', false);
				$this->show_plugins();
				return;
			}
			// Errors
			$html = '<ul style="list-style:disc; padding-left:14px;">';
			foreach ($errors as $message) {
				$html .= '<li>'.htmlspecialchars($message).'</li>';
			}
			$html .= "</ul>";
			$this->show_admin_warning('<strong>'.__('Please correct these errors and try again:', 'simba-plugin-updates-manager').'</strong>'.$html, 'error');
		}

		?><h2><?php echo ($editing) ? __('Edit Plugin', 'simba-plugin-updates-manager') : __('Add New', 'simba-plugin-updates-manager'); ?></h2>

		<?php if (!$editing) { ?>
			<p>
				<strong><?php _e('First you must add the plugin details here; and then you will be able to upload zip files for it afterwards.', 'simba-plugin-updates-manager');?></strong>
			</p>
		<?php } ?>

		<div id="updraftmanager_form">
		<form method="post">

			<input type="hidden" name="action" value="<?php echo (($editing) ? 'edit_go' : 'add_new_go'); ?>">
			<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('updraftmanager-action-nonce');?>">
			<input type="hidden" name="page" value="updraftmanager">
			<input type="hidden" name="oldslug" value="<?php
				if ($editing) {
					echo (isset($use_values['oldslug'])) ? $use_values['oldslug'] : $use_values['slug'];
				}
			?>">

			<label for="udm_newform_text"><?php _e('Plugin name (*):', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_text" type="text" name="name" value="<?php echo (isset($use_values['name'])) ? htmlspecialchars($use_values['name']) : ''; ?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('A short textual name, e.g. "Wurgleflub Super Forms".', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udm_newform_slug"><?php _e('Plugin slug (*):', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_slug" type="text" name="slug" value="<?php echo (isset($use_values['slug'])) ? htmlspecialchars($use_values['slug']) : ''; ?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter the slug used by the plugin zip, i.e. the directory name that the plugin will live in, e.g. "wurgleflub-super-forms".', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udm_newform_author"><?php _e('Plugin author:', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_author" type="text" name="author" value="<?php
				if (!$editing) {
					$user = wp_get_current_user();
					echo htmlspecialchars($user->display_name);
				} else {
					echo (isset($use_values['author'])) ? htmlspecialchars($use_values['author']) : '';
				}
			?>" size="26">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('Enter the author of the plugin.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udm_newform_description"><?php _e('Description:', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_description" type="text" name="description" value="<?php echo (isset($use_values['description'])) ? htmlspecialchars($use_values['description']) : ''; ?>" size="60">
			<span class="udm_description"><em><?php echo htmlspecialchars(__('This is shown in the WordPress dashboard when showing update information.', 'simba-plugin-updates-manager'));?></em></span>

			<label for="udm_newform_freeplugin"><?php _e('Free plugin:', 'simba-plugin-updates-manager');?></label>

			<?php echo apply_filters('updraftmanager_newplugin_freeplugin', '<span class="udm_description">'.__('Yes', 'simba-plugin-updates-manager').'</span><input type="hidden" name="freeplugin" value="yes">', $use_values); ?>

			<span class="udm_description"><em><?php echo htmlspecialchars(__("If this option is set, then no user account is needed to obtain the plugin and its updates, and no tracking of users' entitlements takes place.", 'updraftmanager'));?></em></span>

			<!--
			<label for="udm_newform_minwpver">Minimum WordPress version required:</label> <input id="udm_newform_minwpver" type="text" name="minwpver" value="<?php echo (isset($use_values['minwpver'])) ? htmlspecialchars($use_values['minwpver']) : ''; ?>" size="8" maxlength="8">
			<span class="udm_description"><em></em></span>

			<label for="udm_newform_testedwpver">Tested up to WordPress version:</label> <input id="udm_newform_testedwpver" type="text" name="testedwpver" value="<?php echo (isset($use_values['testedwpver'])) ? htmlspecialchars($use_values['testedwpver']) : ''; ?>" size="8" maxlength="8">
			<span class="udm_description"><em></em></span>
			-->

			<label for="udm_newform_homepage"><?php _e('Plugin homepage:', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_homepage" type="text" name="homepage" value="<?php echo (isset($use_values['homepage'])) ? htmlspecialchars($use_values['homepage']) : ''; ?>" size="60">
			<span class="udm_description"><em><?php echo htmlspecialchars(__("This is shown in the WordPress dashboard when showing update information. Should be a URL.", 'updraftmanager'));?></em></span>

			<?php
				$hid = (!empty($use_values['freeplugin'])) ? 'style="display:none;" ' : '';
			?>

			<label <?php echo $hid; ?>class="udm_newform_addonsrow" for="udm_newform_addonsdir"><?php _e('Add-ons directory:', 'simba-plugin-updates-manager');?></label> 

			<?php
				echo apply_filters('updraftmanager_newplugin_addonsdir', '<span '.$hid.'class="udm_description"><em>'.__('Not applicable for free plugins', 'simba-plugin-updates-manager'), $use_values, $hid).'</em></span>';
			?>
			
			<?php do_action('updraftmanager_edit_plugin_before_active', $use_values, $editing, $this); ?>

			<label for="udm_newform_active"><?php _e('Active:', 'simba-plugin-updates-manager');?></label> <input id="udm_newform_active" type="checkbox" name="active" value="yes" <?php if (!empty($use_values['active'])) echo 'checked="checked" '; ?>>
			<span class="udm_description"><em><?php echo htmlspecialchars(__("This plugin will not be live on the system unless you check this box.", 'updraftmanager'));?></em></span>

			<input type="submit" class="button-primary" value="<?php echo (($editing) ? __('Edit Plugin', 'simba-plugin-updates-manager') : __('Add Plugin', 'simba-plugin-updates-manager')); ?>">

		</form>

		</div>

		<?php
	}

	public static function plugin_notices() {
		if ( !class_exists('UpdraftManager_WooCommerce_Connector') && class_exists( 'woocommerce' ) ) {
			self::show_admin_warning('<strong>'.__('WooCommerce Integration Available', 'simba-plugin-updates-manager').'</strong><br><a href="https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/">'.__('To integrate plugin licensing/sales/renewals/reminder emails with WooCommerce, use this add-on.', 'simba-plugin-updates-manager').'</a>', 'updated is-dismissable', false);
		}
	}

	private function show_plugins() {

		echo '<p>'.sprintf(__('Version: %s', 'simba-plugin-updates-manager'), UDMANAGER_VERSION).' - '.__('Authored by', 'updraftplus').' Simba Hosting (<a href="https://updraftplus.com">'.__('UpdraftPlus Backups', 'simba-plugin-updates-manager').'</a> | <a href="https://www.simbahosting.co.uk/s3/shop/">'.__("Premium Plugins", 'updraftmanager').'</a> | <a href="https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/">'.__("WooCommerce Connector", 'updraftmanager').'</a> | <a href="http://david.dw-perspective.org.uk">'.__("Lead Developer's Homepage", 'updraftmanager').'</a>)</p>';

		?><div id="icon-plugins" class="icon32"><br></div><h2><?php _e('Managed Plugins', 'simba-plugin-updates-manager');?> <a href="?page=<?php echo htmlspecialchars($_REQUEST['page']); ?>&action=add_new" class="add-new-h2"><?php _e('Add New', 'simba-plugin-updates-manager');?></a></h2><?php

		$this->plugin_notices();

		if(!class_exists('UpdraftManager_List_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-list-table.php');

		$plug_table = new UpdraftManager_List_Table();

		$plug_table->prepare_items(); 
		?>
		<form method="post">
		<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('updraftmanager-action-nonce'); ?>">
		<?php
		$plug_table->display(); 
		echo '</form>';
	}

	/**
	 * Import zip file
	 *
	 * @param String  $file            Full path of the location of the zip file
	 * @param Boolean $delete_original Whether to use copy() function that will keep the original file or rename()/move_uploaded_file() function 
	 * @return Array An array containing associative indexes referring to the result of the import process
	 */
	public function import_local_zip_file($file, $delete_original = false) {
		$plugins = $this->get_options();
		
		foreach ($plugins as $pslug => $plug) {
			if ('' != $file && 0 === strpos(basename($file), $pslug)) {
				// If there are multiple matches, then prefer the longest match
				if (!isset($slug) || strlen($pslug) > strlen($pslug)) $slug = $pslug;
			}
		}
		
		if (empty($slug)) {
			return array('result' => false, 'message' => sprintf(__('Error: %s', 'udmanager'), __('The filename of the uploaded/imported zip does not match the slug of any plugin currently known.', 'udmanager')));
		}
		
		require_once(UDMANAGER_DIR.'/classes/updraftmanager-manage-zips.php');
		$udmanager_manage_zips = new UpdraftManager_Manage_Zips($slug, $plugins[$slug]);

		// Valid keys for $options: minwpver, testedwpver, addrule, was_uploaded(defaults to true), use_copy(defaults to false)
		// $file is an array. Keys: name (a basename), tmp_name (where the zip currently is)
		// Returns an array with keys (bool)result and, in the case of result === false, (string)message
		
		add_filter('udmanager_add_new_zip_go_engine_options_postunzip', array($this, 'add_new_zip_go_engine_options_postunzip'), 10, 4);
		
		$options = array(
			'was_uploaded' => false,
			'use_copy' => !$delete_original,
		);
		
		$zip_file = array(
			'name' => basename($file),
			'tmp_name' => $file
		);
		
		$process_result = $udmanager_manage_zips->add_new_zip_go_engine($zip_file, $options);

		remove_filter('udmanager_add_new_zip_go_engine_options_postunzip', array($this, 'add_new_zip_go_engine_options_postunzip'), 10, 4);

		return $process_result;
	}
}
