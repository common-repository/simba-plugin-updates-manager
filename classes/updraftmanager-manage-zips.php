<?php

if (!defined('UDMANAGER_DIR')) die('Direct access not allowed');

class UpdraftManager_Manage_Zips {

	public $slug;
	private $plugin;
	private $manager_dir;
	private $options;
	
	/**
	 * Class constructor
	 *
	 * @param String $slug
	 * @param Object $plugin
	 */
	public function __construct($slug, $plugin) {
		$this->slug = $slug;
		$this->plugin = $plugin;
		$this->manager_dir = UpdraftManager_Options::get_manager_dir();
		global $updraftmanager_options;
		$this->options = $updraftmanager_options;
	}

	public function managezips() {
		$this->show_zips();
		echo '<hr style="margin: 34px 0;">';
		$this->show_zip_rules();
	}

	public function delete_zip() {
		$zips = empty($this->plugin['zips']) ? array() : $this->plugin['zips'];

		if (empty($_REQUEST['filename'])) {
			$this->options->show_admin_warning(__('No zip file specified.', 'simba-plugin-updates-manager'));
			return;
		}

		$filenames = stripslashes_deep($_REQUEST['filename']);
		if (!is_array($filenames)) $filenames = array($filenames);

		foreach ($filenames as $filename) {
			if (empty($zips[$filename]) || !preg_match('/^[^\/\\\]+\.zip$/', $filename)) {
				$this->options->show_admin_warning(sprintf(__('The zip %s was not found.', 'simba-plugin-updates-manager'), $filename));
				continue;
			}

			# Update option
			unset($zips[$filename]);
			# Remove file
			@unlink($this->manager_dir.'/'.$filename);
			# Remove unpacked directory
			if (is_dir($this->manager_dir.'/_up_'.$filename)) UpdraftManager_Options::remove_local_directory($this->manager_dir.'/_up_'.$filename);
			$this->delete_zip_rules($filename);
		}

		$this->plugin['zips'] = $zips;
		$this->update_plugin();

		$this->options->show_admin_warning(__('The zip(s) were successfully deleted.', 'simba-plugin-updates-manager'));

		$this->managezips();

	}

	public function delete_zip_rules($filename) {
		$rules = (empty($this->plugin['rules'])) ? array() : $this->plugin['rules'];
		foreach ($rules as $ind => $rule) {
			if (!empty($rule['filename']) && $rule['filename'] == $filename) unset($rules[$ind]);
		}
		$this->plugin['rules'] = $rules;
		$this->update_plugin();
	}

	public function update_plugin($method = 'update') {
		return UpdraftManager_Options::update_plugin($this->plugin, $method);
	}

	public function edit_zip() {
		$plugins = UpdraftManager_Options::get_options();
		if (!isset($_GET['oldfilename']) || empty($plugins[$this->slug]['zips'][$_GET['oldfilename']])) return;
		$values = $plugins[$this->slug]['zips'][$_GET['oldfilename']];
		if (!is_array($values)) $values = array();
		$this->upload_form($values);
	}

	public function edit_zip_go() {

		if (!isset($_POST['filename']) || !is_string($_POST['filename'])) return;

		$filename = stripslashes($_POST['filename']);
		
		$zips = empty($this->plugin['zips']) ? array() : $this->plugin['zips'];

		if (empty($zips[$filename])) return;
		
		$data = $zips[$filename];
		$data['minwpver'] = empty($_POST['minwpver']) ? '' : stripslashes($_POST['minwpver']);
		$data['testedwpver'] = empty($_POST['minwpver']) ? '' : stripslashes($_POST['testedwpver']);

		$zips[$filename] = $data;
		$this->plugin['zips'] = $zips;

		$addrule = empty($_POST['addrule']) ? false : true;
		
		if ($addrule) $this->add_default_rule($filename, false);

		do_action('updraftmanager_edit_zip_go', $this->slug, $filename, $this->plugin, $addrule, $this);

		$this->update_plugin();

		// Success
		$this->options->show_admin_warning(sprintf(__('The zip file %s was edited successfully.', 'simba-plugin-updates-manager'), $filename));

		$this->managezips();

	}

	public function add_new_zip_go() {
		if (empty($_FILES['filename'])) {
			$this->add_new_zip();
			return;
		}
		
		$file = $_FILES['filename'];
		
		$add_new_zip_go = $this->add_new_zip_go_engine($file, $_POST);
		
		if (is_array($add_new_zip_go) && isset($add_new_zip_go['result'])) {
		
			if (!$add_new_zip_go['result']) {
		
				$this->add_new_zip($add_new_zip_go['message']);
				
			} else {
			
				$this->options->show_admin_warning($add_new_zip_go['message']);
				$this->managezips();
				
			}
			
		}
		
	}
	
	// Valid keys for $options: minwpver, testedwpver, addrule, was_uploaded(defaults to true), use_copy(defaults to false)
	// $file is an array. Keys: name (a basename), tmp_name (where the zip currently is)
	// Returns an array with keys (bool)result and, in the case of result === false, (string)message
	public function add_new_zip_go_engine($file, $options) {
		
		if (!isset($options['was_uploaded'])) $options['was_uploaded'] = true;
		if (!isset($options['use_copy'])) $options['use_copy'] = false;
		
		if (!empty($file['error'])) {
			return array('result' => false, 'message' => $file['error']);
		}
		
		$name = $file['name'];
		
		# Is it a zip?
		if (!preg_match('/\.zip$/i', $name)) {
			return array('result' => false, 'message' => __('Only .zip files are accepted.', 'simba-plugin-updates-manager'));
		}
		# No invalid elements in the file name
		if (false !== strpos($name, '/')) {
			return array('result' => false, 'message' => __('The filename contains an invalid character.', 'simba-plugin-updates-manager'));
		}
		
		# Does a zip with the same name already exist?
		if ($this->filename_exists($name)) {
			return array('result' => false, 'message' => __('A .zip with this filename already exists. Please use a unique filename.', 'simba-plugin-updates-manager'));
		}

		# Is the zip valid?
		$unpacked = $this->zip_valid($file['tmp_name']);
		if (!is_string($unpacked)) {
			if (is_wp_error($unpacked)) {
				foreach ($unpacked->get_error_messages() as $err) {
					$msg = empty($msg) ? htmlspecialchars($err) : ', '.htmlspecialchars($err);
				}
			} else {
				$msg = serialize($unpacked);
			}
			return array('result' => false, 'message' => sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'simba-plugin-updates-manager').' '.$msg, $this->slug));
		}

		# Check the zip format
		$found_slug = false;
		$d = dir($unpacked);
		while (false !== ($entry = $d->read())) {
			if ('.' !== $entry && '..' !== $entry && strtolower($this->slug) !== $entry) {
				return array('result' => false, 'message' => sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'simba-plugin-updates-manager'), $this->slug).' '.sprintf(__('The additional entry encountered was: %s', 'simba-plugin-updates-manager'), htmlspecialchars($entry), $this->slug));
			}
			if (strtolower($this->slug) === strtolower($entry) && is_dir($unpacked.'/'.$entry)) $found_slug = true;
		}
		$d->close();
		if (!$found_slug) {
			return array('result' => false, 'message' => sprintf(__('The zip file was not valid; it needs to contain a single top-level directory with name exactly equal to the plugin slug (%s).', 'simba-plugin-updates-manager'), $this->slug).' '.sprintf(__('The top-level directory was not found.', 'simba-plugin-updates-manager'), htmlspecialchars($entry), $this->slug));
		}

		$d = dir($unpacked.'/'.$this->slug);
		$found_plugin = false;
		if (!function_exists('get_plugin_data')) require(ABSPATH.'wp-admin/includes/plugin.php');
		while (false !== ($entry = $d->read())) {
			if (is_file($unpacked.'/'.$this->slug.'/'.$entry) && '.php' == substr($entry, -4)) {
				$plugdata = get_plugin_data($unpacked.'/'.$this->slug.'/'.$entry, false, false ); //Do not apply markup/translate as it'll be cached.
				if (!empty($plugdata['Name']) && !empty($plugdata['Version'])) $found_plugin = array('file' => $entry, 'data' => $plugdata);
			}
		}
		$d->close();

		if (false === $found_plugin) {
			return array('result' => false, 'message' => sprintf(__('The zip file was not valid; it needs to contain a valid .php plugin file (see: %s)', 'simba-plugin-updates-manager'), 'https://codex.wordpress.org/File_Header#Plugin_File_Header_Example'));
		}

		global $updraft_manager;
		
		if (!$updraft_manager->manager_dir_exists($this->manager_dir)) {
			return array('result' => false, 'message' => sprintf(__('Could not receive this zip file: the internal storage directory (%s) does not exist (probably WordPress lacks file permissions to create this directory).', 'simba-plugin-updates-manager'), $this->manager_dir));
		}

		if (file_exists($this->manager_dir.'/'.$name)) {
			return array('result' => false, 'message' => __('A .zip with this filename already exists. Please use a unique filename.', 'simba-plugin-updates-manager'));
		}

		$unpacked_dir = $this->manager_dir.'/_up_'.$name;
		if (file_exists($unpacked_dir)) {
			return array('result' => false, 'message' => sprintf(__('A directory in our cache matching this zip name already exists (%s) (internal inconsistency - consider resolving this via deleting it)', 'simba-plugin-updates-manager'), $unpacked_dir));
		}

		// This gives the opportunity to set some of the options from what was found in the zip - i.e. so that they don't need to pre-scan it themselves
		$options = apply_filters('udmanager_add_new_zip_go_engine_options_postunzip', $options, $unpacked, $this->slug, $found_plugin, $name, $this->manager_dir.'/'.$name, $this);
		
		if (is_wp_error($options)) return array('result' => false, 'message' => $options->get_error_message(), 'code' => $options->get_error_code());

		if (!isset($options['minwpver']) || !isset($options['testedwpver'])) return array('result' => false, 'message' => __('Either the minimum supported WP version, or tested WP version, were unknown for this plugin zip.', 'udmanager'));
		
		if (!rename($unpacked, $unpacked_dir)) {
			return array('result' => false, 'message' => sprintf(__('The unpacked zip file could not be moved (to %s) - probably the file permissions in your WordPress install are incorrect.', 'simba-plugin-updates-manager'), $unpacked_dir));
		}

		if (true == $options['use_copy']) {
			if (!copy($file['tmp_name'], $this->manager_dir.'/'.$name)) return array('result' => false, 'message' => sprintf(__('The zip file could not be copied (to %s) - probably the file permissions in your WordPress install are incorrect.', 'simba-plugin-updates-manager'), $this->manager_dir.'/'.$name));
		} elseif ((empty($options['was_uploaded']) && !rename($file['tmp_name'], $this->manager_dir.'/'.$name)) || (!empty($options['was_uploaded']) && !move_uploaded_file($file['tmp_name'], $this->manager_dir.'/'.$name))) {
			return array('result' => false, 'message' => sprintf(__('The zip file could not be moved (to %s) - probably the file permissions in your WordPress install are incorrect.', 'simba-plugin-updates-manager'), $this->manager_dir.'/'.$name));
		}

		$zips = empty($this->plugin['zips']) ? array() : $this->plugin['zips'];
		
		$zips[$name] = array (
			'filename' => $name,
			'version' => $found_plugin['data']['Version'],
			'pluginfile' => $found_plugin['file'],
			'minwpver' => empty($options['minwpver']) ? '' : $options['minwpver'],
			'testedwpver' => empty($options['testedwpver']) ? '' : $options['testedwpver']
		);
		$this->plugin['zips'] = $zips;

		$comment = array(
			'site' => home_url(),
			'name' => $found_plugin['data']['Name'],
			'file' => $found_plugin['file'],
			'slug' => $this->slug,
			'version' => $found_plugin['data']['Version'],
			'date' => current_time('mysql', true)
		);

		$updraft_manager->add_comment_to_zip($this->manager_dir.'/'.$name, $comment);

		$addrule = empty($options['addrule']) ? false : true;
		if (!empty($options['addrule'])) $this->add_default_rule($name, false);

		do_action('updraftmanager_add_new_zip_go', $this->slug, $name, $this->plugin, $addrule, $this, $found_plugin);
		
		$this->update_plugin();

		// Success
		$message = sprintf(__('The zip file %s was added successfully.', 'simba-plugin-updates-manager'), $name);
		
		return array('result' => true, 'message' => $message);

	}

	public function delete_rule() {
		if (empty($_REQUEST['ruleno'])) return $this->managezips();

		$rulenos = (!is_array($_REQUEST['ruleno'])) ? array($_REQUEST['ruleno']) : $_REQUEST['ruleno'];

		$rules = (empty($this->plugin['rules']) || !is_array($this->plugin['rules'])) ? array() : $this->plugin['rules'];

		foreach ($rulenos as $ruleno) {
			if (!is_numeric($ruleno)) continue;
			$rule_index = $ruleno-1;
			unset($rules[$rule_index]);
		}

		$this->plugin['rules'] = $rules;

		$this->update_plugin();

		$this->options->show_admin_warning(__('The rule was successfully deleted.', 'simba-plugin-updates-manager'));

		$this->managezips();

	}

	public function add_default_rule($filename, $update_plugin = true) {

		$rules = (empty($this->plugin['rules']) || !is_array($this->plugin['rules'])) ? array() : $this->plugin['rules'];

		$first_rule = array_shift($rules);
		// Then put it back
		if (is_array($first_rule)) array_unshift($rules, $first_rule);

		if (!is_array($first_rule) || empty($first_rule)) {
			$add_rule = true;
		} else {
			$first_sub_rule = array_shift($rules);
			if (empty($first_rule['filename']) || $first_rule['filename'] != $filename || empty($first_sub_rule['criteria']) || $first_sub_rule['criteria'] != 'always') {
				$add_rule = true;
			}
		}

		if (!empty($add_rule)) array_unshift($rules, array('combination' => 'and', 'filename' => $filename, 'rules' => array(array('criteria' => 'always'))));

		$this->plugin['rules'] = $rules;

		if ($update_plugin) {
			$this->update_plugin();
		}
	}

	public function zip_valid($file) {
		if (!class_exists('PclZip')) require_once(ABSPATH.'wp-admin/includes/class-pclzip.php');

		$pclzip = new PclZip($file);

		$wpcd = WP_CONTENT_DIR.'/upgrade';
		if (!is_dir($wpcd) && !mkdir($wpcd)) return new WP_Error('mkdir_failed', 'Could not mkdir: '.WP_CONTENT_DIR.'/upgrade');

		$rand = substr(md5(time().$file.rand()), 0, 12);
		while (file_exists($wpcd.'/'.$rand)) {
			$rand = substr(md5(time().$file.rand()), 0, 12);
			if (!mkdir($wpcd.'/'.$rand)) return new WP_Error('mkdir_failed', 'Could not mkdir: '.$wpcd.'/'.$rand);
		}

		$extract = $pclzip->extract(PCLZIP_OPT_PATH, $wpcd.'/'.$rand);
		#if (is_wp_error($unpacked)) return $unpacked;
		if (!is_array($extract)) return new WP_Error('unpack_failed', 'Unpack failed: '.serialize($extract));
		#return $unpacked;
		return $wpcd.'/'.$rand;
	}

	public function filename_exists($filename) {
		$plugins = UpdraftManager_Options::get_options();
		if (!array($plugins)) return false;
		foreach ($plugins as $plug) {
			if (!empty($plug['zips']) && is_array($plug['zips'])) {
				foreach ($plug['zips'] as $zip) {
					if (!empty($zip['filename']) && strtolower($zip['filename']) == strtolower($filename)) return true;
				}
			}
		}
		return false;
	}

	public function add_new_zip($error = false, $info = false) {
		echo '<h2>'.sprintf(__('%s: Upload a new zip', 'simba-plugin-updates-manager'), $this->plugin['name']).'</h2><p>';
		printf(__('Use this form to upload a new zip file for this plugin. The plugin must be in the expected format - i.e. it must contain a single directory at the top level, with the name matching the plugin slug (%s).', 'simba-plugin-updates-manager'), $this->slug);
		echo '</p>';

		if (false !== $error) {
			echo '<p><strong>';
			printf(__('The file could not be uploaded; the error code was: %s', 'simba-plugin-updates-manager'), make_clickable(htmlspecialchars($error)));
			echo '</strong> ';
			if (is_numeric($error)) echo __('See:', 'simba-plugin-updates-manager').' <a href="http://www.php.net/manual/en/features.file-upload.errors.php">http://www.php.net/manual/en/features.file-upload.errors.php</a>';
			echo '</p>';
		}

		if (false !== $info) {
			echo '<div class="updated" style="padding:6px;">'.htmlspecialchars($info).'</div>';
		}

		$this->upload_form();
	}


	public function edit_rule_go() {
		if (empty($_POST['oldruleno']) || !is_numeric($_POST['oldruleno'])) return $this->rule_form($_POST);
		$this->add_new_rule_go();
	}

	# This function also handles editing
	public function add_new_rule_go() {
		if (!isset($_POST['ud_rule_type']) || !isset($_POST['relationship']) || !isset($_POST['combination']) || empty($_POST['filename'])) return $this->rule_form($_POST);

		$filename = $_POST['filename'];

		if (empty($this->plugin['zips'][$filename])) return $this->rule_form($_POST);

		if (!is_array($_POST['filename'])) $_POST['filename'] = array();
		if (!is_array($_POST['relationship'])) $_POST['relationship'] = array();

		$rules = (!empty($this->plugin) && isset($this->plugin['rules']) && is_array($this->plugin['rules'])) ? $this->plugin['rules'] : array();

		$newrule_rules = array();

		ksort($_POST['ud_rule_type']);
		foreach ($_POST['ud_rule_type'] as $ind => $nrule) {
			if ('always' == $_POST['ud_rule_type'] || !empty($_POST['relationship'])) {
				$criteria = $_POST['ud_rule_type'][$ind];
				$ourrule = array(
					'criteria' => $criteria
				);
				if ('always' != $_POST['ud_rule_type']) {
					$use = $_POST['relationship'][$ind];
					if (('siteurl' == $criteria || 'username' == $criteria) || ('gt' != $use && 'lt' != $use && 'range' != $use)) $use = 'eq';
					$value = $_POST['ud_rule_value'][$ind];
					if ('random_percent' == $criteria) {
						$use = 'lt';
						$value = max(min(100, $value), 0);
					}
					$ourrule['relationship'] = $use;
					$ourrule['value'] = $value;
				}
				$newrule_rules[] = $ourrule;
			}
		}

		if (count($newrule_rules) > 0) {
			$newrule = array(
				'filename' => $filename,
				'combination' => ('or' == $_POST['combination']) ? 'or' : 'and',
				'rules' => $newrule_rules,
			);


			if (isset($_POST['oldruleno']) && is_numeric($_POST['oldruleno'])) {
				$ruleno = $_POST['oldruleno'] - 1;
				$rules[$ruleno] = $newrule;
			} elseif (empty($_POST['ud_rule_firstlast']) || 'last' == $_POST['ud_rule_firstlast']) {
				$rules[] = $newrule;
			} else {
				array_unshift($rules, $newrule);
			}

			$this->plugin['rules'] = $rules;
			$this->update_plugin();


			$this->show_zips();
			echo '<hr style="margin: 34px 0;">';
			if (isset($_POST['oldruleno']) && is_numeric($_POST['oldruleno'])) $this->options->show_admin_warning(sprintf(__('The zip rule %s was edited successfully.', 'simba-plugin-updates-manager'), $_POST['oldruleno']));
			$this->show_zip_rules();

		}

	}

	public function edit_rule() {

		if (empty($_GET['oldruleno']) || !is_numeric($_GET['oldruleno'])) return $this->managezips();

		echo '<h2>'.sprintf(__('%s: Edit download rule (number: %s)', 'simba-plugin-updates-manager'), $this->plugin['name'], $_GET['oldruleno']).'</h2><p> </p>';

		$rindex = $_GET['oldruleno'] - 1;

		$rule = (!empty($this->plugin['rules'][$rindex])) ? $this->plugin['rules'][$rindex] : array();

		$this->rule_form($rule);
	}

	public function add_new_rule($error = false, $info = false) {
		echo '<h2>'.sprintf(__('%s: Add a new download rule', 'simba-plugin-updates-manager'), $this->plugin['name']).'</h2><p>';
		printf(__('Use this form to add a new rule for determining which zip to offer for download to any particular WordPress site that is checking for updates.', 'simba-plugin-updates-manager'), $this->slug);
		echo '</p>';

		if (false !== $error) {
			echo '<p><strong>';
			printf(__('The entry could not be processed; the error code was: %s', 'simba-plugin-updates-manager'), htmlspecialchars($error));
			echo '</strong> ';
			echo '</p>';
		}

		if (false !== $info) {
			echo '<div class="updated" style="padding:6px;">'.htmlspecialchars($info).'</div>';
		}

		$this->rule_form();
	}

	protected function rule_form($use_values = array()) {

		?>
		<div id="updraftmanager_form">
		<form onsubmit="return updraftmanager_rule_submit();" method="POST">
		<input type="hidden" name="page" value="<?php echo htmlspecialchars($_REQUEST['page']); ?>">
		<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('updraftmanager-action-nonce');?>">
		<input type="hidden" name="action" value="<?php echo (empty($use_values)) ? 'add_new_rule_go' : 'edit_rule_go'; ?>">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		if (!empty($use_values) && isset($_GET['oldruleno'])) {
			?>
			<input type="hidden" name="oldruleno" value="<?php echo (int)$_GET['oldruleno'];?>">
			<?php
		} else {
			?>

		<div class="ud_rule_option_label"><?php _e('Where to add the rule:', 'simba-plugin-updates-manager');?></div>

		<div class="ud_rulebox">

		<input class="ud_rule_firstlast" id="ud_rule_firstlast_first" type="radio" name="ud_rule_firstlast" value="first" <?php if (empty($use_values['ud_rule_firstlast']) || 'first' == $use_values['ud_rule_firstlast']) echo 'checked="checked"'; ?>>
		<label class="ud_rule_firstlast ud_rule_firstlast_innerlabel" for="ud_rule_firstlast_first"><?php _e('Add this rule as the first rule for this zip', 'simba-plugin-updates-manager');?></label>

		<input class="ud_rule_firstlast" id="ud_rule_firstlast_last" type="radio" name="ud_rule_firstlast" value="last" <?php if (!empty($use_values['ud_rule_firstlast']) && 'last' == $use_values['ud_rule_firstlast']) echo 'checked="checked"'; ?>>
		<label class="ud_rule_firstlast ud_rule_firstlast_innerlabel" for="ud_rule_firstlast_last"><?php _e('Add this rule as the last rule for this zip', 'simba-plugin-updates-manager');?></label>
		</div>

		<?php } ?>

		<div class="ud_rule_option_label"><?php _e('Multiple rules:', 'simba-plugin-updates-manager');?></div>

		<div class="ud_rulebox">

		<input class="ud_rule_combination" id="ud_rule_combination_and" type="radio" name="combination" value="and" <?php if (empty($use_values['combination']) || 'and' == $use_values['combination']) echo 'checked="checked"'; ?>>
		<label title="<?php echo esc_attr(__('Logical AND match', 'simba-plugin-updates-manager'));?>" class="ud_rule_combination ud_rule_combination_innerlabel" for="ud_rule_combination_and"><?php _e('Require all of the rules below (if there are more than one) to match', 'simba-plugin-updates-manager');?></label>

		<input class="ud_rule_combination" id="ud_rule_combination_or" type="radio" name="combination" value="or" <?php if (!empty($use_values['combination']) && 'or' == $use_values['combination']) echo 'checked="checked"'; ?>>
		<label title="<?php echo esc_attr(__('Logical OR match', 'simba-plugin-updates-manager'));?>" class="ud_rule_combination ud_rule_combination_innerlabel" for="ud_rule_combination_or"><?php _e('Require any of the rules below (if there are more than one) to match', 'simba-plugin-updates-manager');?></label>
		</div>

		<div id="updraftmanager_rules">
		<?php
			$this->footer_js = '';
			if (!empty($use_values['rules']) && is_array($use_values['rules'])) {
				foreach ($use_values['rules'] as $ind => $rule) {
					if (!is_array($rule)) continue;
					$relationship = (empty($rule['relationship'])) ? '' : $rule['relationship'];
					$value = (empty($rule['value'])) ? '' : $rule['value'];
					$this->footer_js .= "updraftmanager_newline($ind, '".$rule['criteria']."', '$relationship', '".esc_js($value)."');\n";
				}
			}
			if (empty($this->footer_js)) $this->footer_js = "updraftmanager_newline(0);\n";
			#$this->newrule_line(0);
		?>
		</div>

		<div id="updraftmanager_newrule_div" class="ud_rulebox ud_leftgap">
		<a href="#" id="updraftmanager_newrule"><?php _e('Add another rule...', 'simba-plugin-updates-manager');?></a>
		</div>

		<label class="ud_rule_filename" for="ud_rule_filename"><?php _e('Target zip:', 'simba-plugin-updates-manager');?></label>

		<div class="ud_rulebox">

			<select name="filename" id="ud_rule_filename">
				<?php
				$zips = (!empty($this->plugin['zips'])) ? $this->plugin['zips'] : array();
				foreach ($zips as $zip) {
					if (!empty($zip['filename'])) echo '<option value="'.esc_attr($zip['filename']).'" '.((!empty($use_values['filename']) && $use_values['filename'] == $zip['filename']) ? 'selected="selected"': '').'>'.htmlspecialchars($zip['filename']).'</option>';
				}
				?>
			</select>

		</div>

		<input type="submit" class="button" value="<?php echo (empty($use_values)) ? __('Create', 'simba-plugin-updates-manager') : __('Edit', 'simba-plugin-updates-manager'); ?>">
		</form>
		</div>
		<?php
		add_action('admin_footer', array($this, 'admin_footer'));
	}

	public function admin_footer() {
		?>
		<script>
			jQuery(function($){
				<?php echo $this->footer_js; ?>
			});
		</script>
		<?php
	}

	// NOT USED
	public function newrule_line($ind) {
		$ind = (int)$ind;
		?>
		<div id="ud_rule_<?php echo $ind;?>">

			<label for="ud_rule_type[<?php echo $ind;?>]"><?php _e('Rule:', 'simba-plugin-updates-manager');?></label>
			<select class="ud_rule_type" name="ud_rule_type[<?php echo $ind;?>]" id="ud_rule_<?php echo $ind;?>">
				<option value="always" title="<?php echo esc_attr(__('Apply this rule always', 'simba-plugin-updates-manager'));?>"><?php echo __('Always match', 'simba-plugin-updates-manager'); ?></option>
				<option value="installed" title="<?php echo esc_attr(__('Apply this rule if the site checking already has a specified version installed', 'simba-plugin-updates-manager'));?>"><?php echo __('Installed plugin version', 'simba-plugin-updates-manager'); ?></option>
				<option value="wp" title="<?php echo esc_attr(__('Apply this rule if the site checking has a particular version of WordPress installed', 'simba-plugin-updates-manager'));?>"><?php echo __('WordPress version', 'simba-plugin-updates-manager'); ?></option>
				<option value="php" title="<?php echo esc_attr(__('Apply this rule if the site checking has a particular version of PHP installed', 'simba-plugin-updates-manager'));?>"><?php echo __('PHP version', 'simba-plugin-updates-manager'); ?></option>
				<option value="random_percent" title="<?php echo esc_attr(__('Apply this rule randomly, on a specified percentage of updates checks', 'simba-plugin-updates-manager'));?>"><?php echo __('Random percentage', 'simba-plugin-updates-manager'); ?></option>
				<option value="username" title="<?php echo esc_attr(__('Apply this rule if the site checking belongs to a specified user from this site', 'simba-plugin-updates-manager'));?>"><?php echo __('Username', 'simba-plugin-updates-manager'); ?></option>
				<option value="siteurl" title="<?php echo esc_attr(__('Apply this rule if the site checking has a specific site URL', 'simba-plugin-updates-manager'));?>"><?php echo __('Site URL', 'simba-plugin-updates-manager'); ?></option>
			</select>

			<select class="ud_rule_relationship" name="relationship[<?php echo $ind;?>]" id="ud_rule_relationship<?php echo $ind;?>">
				<option value="eq"><?php _e('equals', 'simba-plugin-updates-manager'); ?></option>
				<option value="lt"><?php _e('is at most', 'simba-plugin-updates-manager'); ?></option>
				<option value="gt"><?php _e('is at least', 'simba-plugin-updates-manager'); ?></option>
				<option value="range"><?php _e('is between', 'simba-plugin-updates-manager'); ?></option>
			</select>

			<input type="text" class="ud_rule_value" name="ud_rule_value[<?php echo $ind;?>]" id="ud_rule_value_<?php echo $ind;?>" value="" title="<?php _e('If you are entering a range, specify the (inclusive) end points using a comma; for example: 1.0,2.1', 'simba-plugin-updates-manager');?>">
		
		</div>
		<?php
	}

	public function upload_form($use_values = array()) {

		?>
		<div id="updraftmanager_form">
			<form enctype="multipart/form-data" method="POST">
				<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('updraftmanager-action-nonce');?>">
				<input type="hidden" name="page" value="<?php echo htmlspecialchars($_REQUEST['page']); ?>">
				<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">

				<?php if (empty($use_values['filename'])) { ?>
					<input type="hidden" name="action" value="add_new_zip_go">
					<input type="hidden" name="MAX_FILE_SIZE" value="419430400" />

					<label for="ud_filename"><?php _e('Zip file to upload:', 'simba-plugin-updates-manager');?></label>
					<input type="file" id="ud_filename" name="filename" accept="application/zip">
				<?php }  else {
					?>
					<input type="hidden" name="action" value="edit_zip_go">
					<label for="ud_filename"><?php _e('Zip file:', 'simba-plugin-updates-manager');?></label>
					<input type="hidden" id="ud_filename" name="filename" value="<?php echo $use_values['filename'];?>">
					<?php
					echo '<div class="infodiv">'.htmlspecialchars($use_values['filename']).'</div>';
				}
				?>

				<label for="minwpver"><?php _e('Minimum WordPress version required:', 'simba-plugin-updates-manager');?></label> <input id="minwpver" type="text" name="minwpver" value="<?php echo (isset($use_values['minwpver'])) ? htmlspecialchars($use_values['minwpver']) : ''; ?>" size="8" maxlength="8">
				<span class="udm_description"><em></em></span>

				<label for="testedwpver"><?php _e('Tested up to WordPress version:', 'simba-plugin-updates-manager');?></label> <input id="testedwpver" type="text" name="testedwpver" value="<?php echo (isset($use_values['testedwpver'])) ? htmlspecialchars($use_values['testedwpver']) : ''; ?>" size="8" maxlength="8">
				<span class="udm_description"><em></em></span>

				<label for="addrule"><?php _e('Make this the default download for all users', 'simba-plugin-updates-manager');?></label>
				<input id="addrule" type="checkbox" name="addrule" value="yes" checked="checked">
				<span class="udm_description"><em><?php _e('This will (if needed) create a new download rule.', 'simba-plugin-updates-manager'); do_action('updraftmanager_upload_form_after_addrule', $this->slug, $this->plugin, $use_values, $this);?></em></span>

				<input type="submit" class="button" value="<?php echo empty($use_values) ? __('Upload', 'simba-plugin-updates-manager') : __('Edit', 'simba-plugin-updates-manager'); ?>">
			</form>
		</div>
		<?php
	}

	public function show_zips() {

		UpdraftManager_Options::plugin_notices();

		echo '<p><em>'.__('Use this screen to upload zips for this plugin, and to define which one a particular user will be sent.', 'simba-plugin-updates-manager').'</em></p>';

		$plugin = $this->plugin;

		?><div id="icon-plugins" class="icon32"><br></div><h2><?php echo sprintf(__('%s: Manage zips', 'simba-plugin-updates-manager'), htmlspecialchars($plugin['name'])); ?> <a href="?page=<?php echo htmlspecialchars($_REQUEST['page'])?>&action=add_new_zip&slug=<?php echo htmlspecialchars($this->slug);?>" class="add-new-h2"><?php _e('Add New', 'simba-plugin-updates-manager');?></a></h2><?php

		if(!class_exists('UpdraftManager_Zips_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-zips-table.php');

		$zips_table = new UpdraftManager_Zips_Table($this->slug);
		$zips_table->prepare_items(); 

		?>
		<form method="post" class="updraftmanager_ziptable">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		$zips_table->display(); 
		?>
		</form>
		<p><?php echo __('Updates URL for this plugin (used inside the plugin):', 'simba-plugin-updates-manager');?> <code><?php echo sprintf('%s/?udm_action=%s&slug=%s&muid=%s', home_url(), 'updateinfo', $this->slug, UpdraftManager_Options::get_user_id_for_licences());?></code></p>

		<?php
	}

	public function show_zip_rules() {

		$plugin = $this->plugin;

		$add_new = (!empty($this->plugin['zips']) && count($this->plugin['zips']) >0);

		?><div id="icon-tools" class="icon32"><br></div><h2><?php echo sprintf(__('%s: Manage download rules', 'simba-plugin-updates-manager'), htmlspecialchars($plugin['name'])); ?> <?php
			
		if ($add_new) {

			?><a href="?page=<?php echo htmlspecialchars($_REQUEST['page'])?>&action=add_new_rule&slug=<?php echo htmlspecialchars($this->slug);?>&nonce=<?php echo wp_create_nonce('updraftmanager-action-nonce');?>" class="add-new-h2"><?php _e('Add New', 'simba-plugin-updates-manager');?></a><?php

		} else {
			?><span class="add-new-h2"><?php _e('There are no zips yet - cannot add any rules', 'simba-plugin-updates-manager');?></a><?php
		}

		?></h2>

		<p><em><?php echo htmlspecialchars(__('Rules are applied in order, and the processing stops after the first matching rule. If no matching rule is found, then no updates will be offered to the user.', 'simba-plugin-updates-manager').' '.__('To re-order rules, drag and drop them.', 'simba-plugin-updates-manager')); ?></em></p>

		<?php

		if(!class_exists('UpdraftManager_ZipRules_Table')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-ziprules-table.php');

		$zip_rules_table = new UpdraftManager_ZipRules_Table($this->slug);
		$zip_rules_table->prepare_items(); 
		?>
		<form method="post" class="updraftmanager_ruletable">
		<input type="hidden" name="slug" value="<?php echo htmlspecialchars($this->slug); ?>">
		<?php
		$zip_rules_table->display();
		echo '</form>';

	}

}

