<?php

// Convert download data from old to new format; this is useful for upgrading from the free to the Premium version

// To run this, 1) remove the die() line below, 2) put it in the same directory as WordPress, 3) visit it, 4) wait for it to finish, and 5) then remove the script

// Data is first DELETEd, and then freshly INSERT-ed

die('You need to remove this line first');

require('wp-load.php');

global $wpdb;

$wpdb->query("DELETE FROM ".$wpdb->prefix."udmanager_download_history");

$downloads_result = $wpdb->get_results("SELECT umeta_id, user_id, meta_value FROM ".$wpdb->prefix."usermeta WHERE (meta_key LIKE 'udmanager_downloads')", OBJECT);

foreach ($downloads_result as $blob) {

	$processed++;
	if ($processed % 100  == 0) error_log("PROCESSED: $processed");

	$user_id = $blob->user_id;
/*
	+---------------+------------+------+-----+---------+-------+
	| Field         | Type       | Null | Key | Default | Extra |
	+---------------+------------+------+-----+---------+-------+
	| slug          | tinytext   | NO   | MUL | NULL    |       |
	| owner_user_id | bigint(20) | NO   | MUL | NULL    |       |
	| daybegin      | bigint(20) | NO   | MUL | NULL    |       |
	| downloads     | bigint(20) | YES  |     | NULL    |       |
	+---------------+------------+------+-----+---------+-------+
*/

	if (false === ($downloads = unserialize($blob->meta_value))) {
		error_log("Unserializable data for umeta_id=".$blob->umeta_id);
		continue;
	}

	foreach ($downloads as $slug => $dl) {
		foreach ($dl as $zip => $days) {
			foreach ($days as $daybegin => $downloads) {

				$values = array(
					'slug' => $slug,
					'owner_user_id' => $user_id,
					'daybegin' => $daybegin,
					'filename' => $zip,
					'downloads' => $downloads
				);

		$wpdb->insert($wpdb->prefix."udmanager_download_history", $values);
// 		print_r($values);


			}
		}

	}
}