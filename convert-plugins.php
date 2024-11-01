<?php

// Convert plugins data from old (pre-version 1.5.3) to new format

// To run this, 1) remove the die() line below, 2) put it in the same directory as WordPress, 3) visit it, 4) wait for it to finish, and 5) then remove the script

die('You need to remove this line first');

// Data is first DELETEd, and then freshly INSERT-ed

define('WP_MEMORY_LIMIT', '512M');
define('WP_MAX_MEMORY_LIMIT', '512M');

require('wp-load.php');

global $wpdb;


$wpdb->query("DELETE FROM ".$wpdb->prefix."udmanager_plugins");

$plugin_results = $wpdb->get_results("SELECT user_id, meta_key, meta_value, umeta_id FROM ".$wpdb->prefix."usermeta WHERE (meta_key LIKE 'updraftmanager_plugins')", OBJECT);

$processed = 0;

foreach ($plugin_results as $blob) {

	$processed++;
	if ($processed % 100  == 0) error_log("PROCESSED: $processed");

	$user_id = $blob->user_id;

	if (false === ($plugins = unserialize($blob->meta_value))) {
		error_log("Unserializable data for umeta_id=".$blob->umeta_id);
		continue;
	}

	foreach ($plugins as $slug => $pi) {

		$values = array(
			'owner_user_id' => $user_id,
			'slug' => $slug,
			'name' => $pi['name'],
			'description' => $pi['description'],
			'author' => $pi['author'],
			'zips' => serialize($pi['zips']),
			'addonsdir' => $pi['addonsdir'],
			'rules' => serialize($pi['rules']),
			'active' => $pi['active'],
			'homepage' => $pi['homepage'],
			'freeplugin' => $pi['freeplugin'],
			'meta' => ''
		);

		if (!empty($values['meta'])) $values['meta'] = serialize($values['meta']);

		$wpdb->insert($wpdb->prefix."udmanager_plugins", $values);

	}
}