<?php

if (!defined('UDMANAGER_DIR')) die('Security check');

class Updraft_Manager_Activation {

	private static $db_updates = array(
		'1.6.5' => array(
			'update_165_add_normalised_url_column_to_sites',
		),
	);

	public static function install() {
		self::create_tables();
	}

	public static function check_updates() {
	
		$our_version = UDMANAGER_VERSION;
		
		$db_version = get_site_option('updraftmanager_last_version');
		
		if (!$db_version || version_compare($our_version, $db_version, '>')) {

			if (!class_exists('UpdraftManager_Semaphore_Logger')) require_once(UDMANAGER_DIR.'/classes/updraftmanager-semaphore-logger.php');
			if (!class_exists('Updraft_Semaphore_3_0')) require(UDMANAGER_DIR.'/classes/class-updraft-semaphore.php');
			
			$logger = new UpdraftManager_Semaphore_Logger();
			
			$semaphore = new Updraft_Semaphore_3_0('udmanager_update', 600, array($logger));

			error_log("UpdraftManager: database update: requesting semaphore lock");

			if (!$semaphore->lock()) {

				error_log('Failed to gain semaphore lock - another database update process is apparently already active - aborting (if this is wrong - i.e. if the other update process crashed without removing the lock, then another can be started after 10 minutes)');
				return;
				
			}

			foreach (self::$db_updates as $version => $updates) {
				if (version_compare($version, $db_version, '>')) {
					foreach ($updates as $update) {
						call_user_func(array(__CLASS__, $update));
					}
				}
			}
			
			self::update_last_version();
			
			$semaphore->release();
			
		}
	}

	/**
	 * Add the 'url_normalised' column to the entitlements table
	 */
	public static function update_165_add_normalised_url_column_to_sites() {
	
		$entitlements_table = Updraft_Manager::get_entitlements_table();

		$entitlements_updated = 0;
		$processed = 0;
	
		global $wpdb;
		$wpdb->query('ALTER TABLE '.$entitlements_table.' ADD url_normalised varchar(120) AFTER url');

		$page = 0;
		$page_size = 5000;

		$entitlements = array();

		while ($page < 1 || count($entitlements) > 0) {

// 			$offset = $page * $page_size;

			$sql = "SELECT entitlement_id, type, user_id, url FROM $entitlements_table WHERE url_normalised IS NULL AND url != '' LIMIT $page_size";

			$entitlements = $wpdb->get_results($sql, OBJECT);

// 			error_log("Synchronising support entitlements: finished SQL call to get all relevant support entitlements: page=$page, count=".count($entitlements));

			foreach ($entitlements as $ent) {
			
				$processed++;
				
				if ($processed % 1000  == 0) error_log("Updraft_Manager_Activation::update_165_add_normalised_url_column_to_sites: processed=$processed, entitlements_updated=$entitlements_updated, page=$page (memory_usage: ".round(memory_get_usage()/1048576, 1)." MB)");
			
				$rows_updated = $wpdb->update($entitlements_table,
					array(
						'url_normalised' => Updraft_Manager::normalise_url($ent->url),
					),
					array(
						'entitlement_id' => $ent->entitlement_id,
						'type' => $ent->type,
						'user_id' => $ent->user_id
					)
				);
			
				if ($rows_updated) {
					$entitlements_updated += $rows_updated;
				}
			
			}
			
			$page++;
			
		}
		
		
	}

	public static function update_last_version() {
		if (is_multisite()) {
			update_site_option('updraftmanager_last_version', UDMANAGER_VERSION);
		} else {
			// Explicitly set autoload to be on
			update_option('updraftmanager_last_version', UDMANAGER_VERSION, true);
		}
	}
	
	public static function create_tables() {
		global $wpdb;

	//	$wpdb->hide_errors();

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty($wpdb->charset ) ) {
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty($wpdb->collate ) ) {
				$collate .= " COLLATE $wpdb->collate";
			}
		}

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$create_table = "CREATE TABLE IF NOT EXISTS ".Updraft_Manager::get_entitlements_table()." (
			entitlement_id varchar(26) NOT NULL,
			owner_user_id bigint(20) NOT NULL,
			user_id bigint(20) NOT NULL,
			slug tinytext NOT NULL,
			type varchar(20) NOT NULL,
			`key` tinytext,
			site varchar(32) NOT NULL,
			sitedescription varchar(120) NOT NULL,
			status varchar(20) NOT NULL,
			expires bigint,
			url varchar(120),
			url_normalised varchar(120),
			lastcheckin bigint,
			meta longtext,
			KEY entitlement_id (entitlement_id),
			KEY owner_user_id (owner_user_id),
			KEY user_id (user_id),
			KEY slug (slug(20)),
			KEY `key` (`key`(20)),
			KEY site (site),
			KEY type (type)
			) $collate;
		";

// 		dbDelta($create_table);
		$wpdb->query($create_table);

		$create_table2 = "CREATE TABLE IF NOT EXISTS ".Updraft_Manager::get_download_history_table()." (
			slug tinytext NOT NULL,
			owner_user_id bigint(20) NOT NULL,
			daybegin bigint NOT NULL,
			filename text NOT NULL,
			downloads bigint DEFAULT 0,
			KEY owner_user_id (owner_user_id),
			KEY slug (slug(20)),
			KEY daybegin (daybegin)
			) $collate;
		";

// 		dbDelta($create_table2);
		$wpdb->query($create_table2);

		$create_table3 = "CREATE TABLE IF NOT EXISTS ".Updraft_Manager::get_plugins_table()." (
			owner_user_id bigint(20) NOT NULL,
			slug tinytext NOT NULL,
			name text,
			description text,
			author text,
			zips longtext,
			addonsdir tinytext,
			active bool,
			rules longtext,
			homepage text,
			freeplugin bool,
			meta longtext,
			KEY owner_user_id (owner_user_id),
			KEY slug (slug(20))
			) $collate;
		";

// 		dbDelta($create_table3);
		$wpdb->query($create_table3);

		self::update_last_version();
	}
}
