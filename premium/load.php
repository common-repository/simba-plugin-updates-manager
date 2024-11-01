<?php

if (!defined('UDMANAGER_DIR')) die('No direct access.');

require_once(UDMANAGER_DIR.'/premium/options.php');

add_filter('updraftmanager_pluginobjectclass', 'updraftmanager_premium_setpluginobjectclass');
function updraftmanager_premium_setpluginobjectclass($x) {
	require_once(UDMANAGER_DIR.'/premium/class-plugin.php');
	return 'Updraft_Manager_Plugin_Premium';
}

$updraftmanager_options = new UpdraftManager_Options_Extended;

require_once(UDMANAGER_DIR.'/premium/premium.php');
