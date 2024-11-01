<?php
if (!defined('UDMANAGER_DIR')) die('Direct access not allowed');

if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI_Command')) return;

/**
 * UpdraftManager command line interface commands
 */
class UpdraftManager_CLI_Command extends WP_CLI_Command {

	/**
	 * Constructor
	 */
	public function __construct() {
		WP_CLI::add_wp_hook('udmanager_add_new_zip_go_engine_options_postunzip', array('UpdraftManager_CLI_Command_Helper', 'maybe_add_download_rule'), 999);
	}
	
	/**
	 * Import zip file
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path-to-file>]
	 * : The compressed (zip) file you want to import, which is located in your local machine
	 *
	 * [--user=<username-or-email-or-user-id>]
	 * : The username of the WP account that will be used to add the zip file. It must one that has an active licence
	 *
	 * [--add-rule]
	 * : Make this the default download for all users of this plugin (creating a new download rule if needed)
	 *
	 * ## EXAMPLES
	 *
	 * wp plugins-manager import-zip --file="/path/to/file" --user="your-WP-user" --add-rule
	 *
	 * @alias import-zip
	 * @when after_wp_load
	 *
	 * @param Array $args       A indexed array of command line arguments
	 * @param Array $assoc_args Key value pair of command line arguments
	 */
	public function import_zip($args, $assoc_args = array()) {
		global $updraftmanager_options;
		if ('' == $assoc_args['file']) WP_CLI::error(__("You must provide a location to the zip file; please use the --file parameter.", 'udmanager'));
		if (!file_exists(realpath($assoc_args['file']))) WP_CLI::error(__("The file you specified doesn't exist; please check the --file parameter.", 'udmanager'));
		// --user is a WP-CLI global parameter, so it's not neccessary to specify another --wp-user option for this command to work, using the global --user config saves a lot of lines
		if (empty(WP_CLI::get_runner()->config['user'])) WP_CLI::error(__("Please specify a user ID (or login or email address) for the owner of the plugin.", 'udmanager'));
		if ('zip' !== pathinfo($assoc_args['file'], PATHINFO_EXTENSION)) WP_CLI::error(__('This file does not appear to be a zip file.', 'udmanager'));
		$resp = $updraftmanager_options->import_local_zip_file($assoc_args['file']);
		if (!empty($resp['message'])) {
			WP_CLI::log($resp['message']);
		} elseif (isset($resp['result']) && $resp['result']) {
			WP_CLI::success(sprintf(__('The zip file %s was added successfully.', 'updraftplus'), basename($assoc_args['file'])));
		} else {
			WP_CLI::error(__('An unknown error happened when importing the zip file', 'udmanager'));
		}
	}

	/**
	 * Update plugin supported WordPress versions (minimum and/or tested versions) to the new specified versions for the latest available zip file
	 *
	 * ## OPTIONS
	 *
	 * [--user=<username-or-email-or-user-id>]
	 * : The username of the WP account that will be used to update the supported versions. It must one that has an active licence
	 *
	 * [--slug=<plugin-slug>]
	 * : Used to specify what plugin the supported WordPress versions need updating.
	 *
	 * [--minimum-version=<minimum-version>]
	 * : The new minimum supported WordPress version for the specified plugin. Optional.
	 *
	 * [--tested-version=<tested-version>]
	 * : The new latest supported WordPress version for the specified plugin.
	 *
	 * [--current-wp-version=<version>]
	 * : Used to lookup all plugins for which the tested WordPress version for the latest available zip matches the given value.
	 *
	 * ## EXAMPLES
	 *
	 * wp plugins-manager update-versions --user="your-WP-user" --slug="plugin-slug" --minimum-version="5.3" --tested-version="5.8"
	 * wp plugins-manager update-versions --user="your-WP-user" --current-wp-version="5.7" --tested-version="5.8"
	 *
	 * @alias update-versions
	 * @when after_wp_load
	 *
	 * @param Array $args       A indexed array of command line arguments
	 * @param Array $assoc_args Key value pair of command line arguments
	 */
	public function update_versions($args, $assoc_args = array()) {
		if (empty(WP_CLI::get_runner()->config['user'])) WP_CLI::error(__("Please specify a user ID (or login or email address) for the owner of the plugin(s).", 'udmanager'));
		if ((!isset($assoc_args['slug']) || '' == $assoc_args['slug']) && (!isset($assoc_args['current-wp-version']) || '' == $assoc_args['current-wp-version'])) WP_CLI::error(__("The --slug or --current-wp-version parameter is required to update a plugin supported WordPress versions.", 'udmanager'));
		$plugins = UpdraftManager_Options::get_options();
		if (isset($assoc_args['slug']) && !isset($plugins[$assoc_args['slug']])) WP_CLI::error(__("The given plugin slug parameter is either not valid or no longer exists.", 'udmanager'));
		if (!isset($assoc_args['tested-version']) || '' == $assoc_args['tested-version']) WP_CLI::error(__("You must provide a new supported WordPress version for the plugin(s). Please consult the --tested-version parameter for the details of its usage.", 'udmanager'));
		$invalid_version_msg = __("The %s parameter must contain a valid version number (e.g. x.y.z).", 'udmanager');
		if (!preg_match('/^(\d+\.)?(\d+\.)(\d+)$/', $assoc_args['tested-version'])) WP_CLI::error(sprintf($invalid_version_msg, '--tested-version'));
		if (isset($assoc_args['minimum-version']) && !preg_match('/^(\d+\.)?(\d+\.)(\d+)$/', $assoc_args['minimum-version'])) WP_CLI::error(sprintf($invalid_version_msg, '--minimum-version'));
		if (isset($assoc_args['current-wp-version']) && !preg_match('/^(\d+\.)?(\d+\.)(\d+)$/', $assoc_args['current-wp-version'])) WP_CLI::error(sprintf($invalid_version_msg, '--current-wp-version'));
		foreach ($plugins as $slug => $plugin_data) {
			if (!is_array($plugin_data['zips']) || 0 === count($plugin_data['zips'])) continue;
			$zips = $plugin_data['zips'];
			$zip_versions = array_column($zips, 'version');
			array_multisort($zip_versions, SORT_DESC, SORT_NATURAL, $zips);
			$tested_version = $plugin_data['zips'][array_key_first($zips)]['testedwpver'];
			if (isset($assoc_args['current-wp-version']) && substr_count($tested_version, '.') > substr_count($assoc_args['current-wp-version'], '.')) {
				$assoc_args['current-wp-version'] .= '.0';
			} elseif (isset($assoc_args['current-wp-version']) && substr_count($tested_version, '.') != substr_count($assoc_args['current-wp-version'], '.')) {
				$tested_version .= '.0';
			}
			if ((isset($assoc_args['slug']) && $assoc_args['slug'] === $slug) || (isset($assoc_args['current-wp-version']) && version_compare($tested_version, $assoc_args['current-wp-version'], '=='))) {
				$plugin_data['zips'][array_key_first($zips)]['testedwpver'] = $assoc_args['tested-version'];
				if (isset($assoc_args['minimum-version']) && '' != $assoc_args['minimum-version']) $plugin_data['zips'][array_key_first($zips)]['minwpver'] = $assoc_args['minimum-version'];
				if (false !== UpdraftManager_Options::update_plugin($plugin_data)) {
					WP_CLI::success(sprintf(__('The tested WordPress version of the latest available zip file (%s) for the %s plugin has been updated successfully.', 'updraftplus'), array_key_first($zips), $plugin_data['name']));
				} else {
					WP_CLI::error(sprintf(__('There was an error when updating the tested WordPress version of the latest available zip file (%s) for the %s plugin.', 'updraftplus'), array_key_first($zips), $plugin_data['name']));
				}
			}
		}
	}
}

WP_CLI::add_command('plugins-manager', 'UpdraftManager_CLI_Command');

/**
 * Methods in this class are separated from the UpdraftManager_CLI_Command class to prevent them being recognised as UpdraftManager CLI sub-commands
 */
class UpdraftManager_CLI_Command_Helper {

	/**
	 * Determine whether adding the zip file will also create a new download rule, according to '--add-rule' input given by the user
	 *
	 * @param Array  $options      List of import/upload options
	 * @return Array Filtered options in which the 'add_rule' value is set accordingly
	 */
	public static function maybe_add_download_rule($options) {
		if (isset(WP_CLI::get_runner()->assoc_args['add-rule'])) $options['addrule'] = true;
		return $options;
	}
}
