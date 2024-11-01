<?php
/*
Plugin Name: Simba Plugins Manager
Plugin URI: https://www.simbahosting.co.uk/s3/shop/
Description: Management of plugin updates + licences
Author: David Anderson
Version: 1.11.10
License: GPLv2+ / MIT
Text Domain: simba-plugin-updates-manager
Author URI: https://david.dw-perspective.org.uk
*/

if (!defined('ABSPATH')) die('No direct access.');

define('UDMANAGER_VERSION', '1.11.10');
define('UDMANAGER_DIR', dirname(realpath(__FILE__)));
define('UDMANAGER_URL', plugins_url('', realpath(__FILE__)));
define('UDMANAGER_SLUG', basename(UDMANAGER_DIR));

if (version_compare(PHP_VERSION, '7.4', '<')) {
	add_action('admin_notices', 'admin_notices_spum_inadequate_php');
	return;
}

function admin_notices_spum_inadequate_php() {
	echo '<div class="spum-inadequate-php notice notice-warning">'."<p>".sprintf(__('Simba Plugin Updates Manager requires at least PHP version %s to function; your website is running on version %s.', 'simba-plugin-updates-manager'), '7.4', PHP_VERSION)."</p></div>";
}

include ABSPATH.WPINC.'/version.php';

if (version_compare($wp_version, '5.6', '<')) {
	add_action('admin_notices', 'admin_notices_spum_inadequate_wp');
	return;
}

function admin_notices_spum_inadequate_wp() {
	include ABSPATH.WPINC.'/version.php';
	echo '<div class="spum-inadequate-wp notice notice-warning">'."<p>".sprintf(__('Simba Plugin Updates Manager requires at least WP version %s to function; your website is running on version %s.', 'simba-plugin-updates-manager'), '5.6', $wp_version)."</p></div>";
}

require_once(UDMANAGER_DIR.'/options.php');
require_once(UDMANAGER_DIR.'/classes/updraftmanager.php');
require_once(UDMANAGER_DIR.'/classes/updraftmanager-wp-cli.php');

if (!defined('UDMANAGER_DISABLEPREMIUM') || !UDMANAGER_DISABLEPREMIUM) @include_once(UDMANAGER_DIR.'/premium/load.php');

global $updraftmanager_options; // Need to explicitly globalise the variable or WP-CLI won't recognise it https://github.com/wp-cli/wp-cli/issues/4019#issuecomment-297410839
if (empty($updraftmanager_options) || !is_object($updraftmanager_options) || !is_a($updraftmanager_options, 'UpdraftManager_Options')) $updraftmanager_options = new UpdraftManager_Options;

global $updraft_manager;
$updraft_manager = new Updraft_Manager;

register_activation_hook(__FILE__, array($updraft_manager, 'activation'));
register_deactivation_hook(__FILE__, array($updraft_manager, 'deactivation'));
