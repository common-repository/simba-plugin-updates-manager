=== Simba Plugin Updates Manager ===
Contributors: DavidAnderson
Requires at least: 5.5
Tested up to: 6.6
Stable tag: 1.11.10
Requires PHP: 7.4
Tags: plugin updates, updates server, wordpress updates, wordpress plugin updates, licences, licensing, woocommerce, renewals
License: MIT
Donate link: https://david.dw-perspective.org.uk/donate

Provides a facility for distributing updates and handling licences and renewal reminders for your own WordPress plugins

== Description ==

This plugin enables you to host updates for plugins from your own WordPress site.

i.e. It provides a service for the availability and download of WordPress plugin updates - just like the wordpress.org plugin repository. This can be for free plugins, or licensed plugins - it includes a full licence manager (and a free class for using it is available).

This is the plugin updates server that has been providing millions of plugin updates to the users of the paid versions of <a href="https://updraftplus.com">the UpdraftPlus backup/restore/clone WordPress plugin</a> since 2013 (and various other <a href="https://teamupdraft.com">Team Updraft</a> and <a href="https://www.simbahosting.co.uk/s3/shop/">Simba Hosting plugins</a>).

A paid connector for WooCommerce is also available, allowing WordPress to automatically assign and renew licences when purchases are made; plus other features for coupons and renewal emails (including pre-filled carts); <a href="https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/">follow this link for more information.</a>

The best way to get a feel for its features is to take a look at the available screenshots.

= Features =

* Manage multiple plugins, both free and paid

* Manage user licences - create, renew and delete licence entitlements for non-free plugins

* Send renewal reminder emails for licensed plugins

* Have multiple different zips (i.e. different plugin versions) available for your plugins

* Have sophisticated rules for which zip a particular user gets delivered (e.g. send them an older version if they are on an old version of WordPress or PHP)

* Counts plugin downloads, by version - calculate how many active users you have

* Shortcode provided for showing users on your website what plugins are available

* Shortcode provided for showing plugin changelogs (automatically read from the plugin zip)

* Data is included in WordPress's privacy tools' output (export / delete)

* Import a new zip via WP-CLI: wp plugins-manager import-zip --file="/path/to/file.zip" --user="your-WP-user" --add-rule

* Update plugins' supported WordPress versions via WP-CLI: 

- wp plugins-manager update-versions --user="WordPress-username-or-email-or-id" --slug="plugin-slug" --tested-version="version-number(x.y.z)"

Or

- wp plugins-manager update_versions --user="WordPress-username-or-email-or-id" --current-wp-version="version-number(x.y.z)" --tested-version="version-number(x.y.z)"

Running an updates and an licensing server are two important parts of providing plugin updates to your users. You will also need to add code in your plugin to point towards that updates server. A popular class used for this purpose with free plugins, that requires you to do nothing more than include it and tell it the updates URL, is available here: <a href="https://github.com/YahnisElsts/plugin-update-checker">https://github.com/YahnisElsts/plugin-update-checker</a> . For licenced plugins, a compatible class is available here: <a href="https://github.com/DavidAnderson684/simba-plugin-manager-updater">https://github.com/DavidAnderson684/simba-plugin-manager-updater</a> .

= Other information =

- Privacy: The plugin does not contact any remote services. It also integrates with WordPress's privacy tools (4.9.6+) for export/deletion, and removes user data when users are deleted.

- Some other plugins you may be interested in: <a href="https://www.simbahosting.co.uk/s3/shop/">https://www.simbahosting.co.uk/s3/shop/</a>, <a href="https://updraftplus.com">https://updraftplus.com</a> and <a href="https://getwpo.com">https://getwpo.com</a>

- This plugin is ready for translations, and we would welcome new translations (please use <a href="https://translate.wordpress.org/projects/wp-plugins/simba-plugin-updates-manager">the wordpress.org translation system</a>).

== Installation ==

Standard WordPress installation; either:

- Go to the Plugins -> Add New screen in your dashboard and search for this plugin; then install and activate it.

Or

- Upload this plugin's zip file into Plugins -> Add New -> Upload in your dashboard; then activate it.

After installation, you will want to configure this plugin. To find its settings, look for the "Plugins Manager" entry in your WordPress dashboard menu.

To show users on your website what plugins they can download, use this shortcode (changing the value of userid to match the ID of the WordPress user who is providing the plugins): [udmanager showunpurchased="free" userid="1"]

To show the changelog for a plugin, use the shortcode udmanager_changelog; e.g. : [udmanager_changelog slug="my-crazy-plugin" userid="1" maximum_sections="9999"] . N.B. HTML will not be filtered, so do not use this upon untrusted input. If no slug is specified, then the value will be taken from the URL parameter udmanager_changelog_slug.


== Frequently Asked Questions ==

<a href="https://www.simbahosting.co.uk/s3/simba-plugins-updates-licensing-renewals-manager-faqs/">Please go here for plugin FAQs.</a>

== Changelog ==

= 1.11.10 - 2024-08-10 =

* TWEAK: Add a semaphore lock to the renewal reminder process to prevent duplicate runs when WP cron misbehaves
* TWEAK: Resolve some PHP 8 dynamic property deprecations
* TWEAK: Now requires PHP 7.4+ (previously 7.3+)

= 1.11.9 - 2024-01-17 =

* TWEAK: Prevent an unwanted PHP notice introduced in 1.11.8 when handling expired licences
* TWEAK: Add an extra parameter to the action updraftmanager_add_new_zip_go
* TWEAK: Remove use of dynamic properties in UpdraftManager_Manage_Zips
* TWEAK: Change how markdown is parsed to prevent HTML tags written raw in the readme from being shown when using the [udmanager_changelog] shortcode

= 1.11.8 - 2023-10-23 =

* FIX: An issue was discovered that could allow a user to download a plugin that he was not entitled to; all users of this manager plugin will want to update to prevent this. To do this, the user had to have an active login on the website running the manager plugin.
* TWEAK: Add a note in the user interface clarifying how the "add-ons" setting works when the user has no add-ons (the "base" (i.e. no add-ons) plugin is always free). This intended behaviour has been the same since the first release of this manager plugin, but was not officially noted anywhere. (It can be over-ridden with the new filter updraftmanager_downloadable_base_plugin).

= 1.11.6 - 2023-09-28 =

* FIX: Add a zip comment to uploaded/created zip files was causing a fatal error
* TWEAK: Introduced a requirement for WP 5.6+ (for the site this plugin runs on - this does not affect what requirements you have for plugins you are distributing through it).

= 1.11.5 - 2023-09-25 =

* TWEAK: Prevent a PHP notice due to an undefined variable
* TWEAK: Add a zip comment to uploaded/created zip files
* TWEAK: Add the filter updraftmanager_plugin_deliverzip_delivered to make it more convenient for developers to over-ride the final delivery method
* TWEAK: Bumped required PHP version to 7.3
* TWEAK: Updated phpseclib 2.0 and soundasleep/html2text libraries to latest releases (the latter of which adds PHP 8.2 support)

= 1.11.4 - 2022-09-16 =

* TWEAK: Updated phpseclib 2.0 library to the current release
* TWEAK: Updated soundasleep/html2text library to the 2.0 series for improved PHP 8 compatibility
* TWEAK: Updated michelf/php-markdown library to the current development version to restore PHP 8 functionality (needed for the changelog shortcode)
* TWEAK: Bumped required PHP version to 7.2

= 1.11.3 - 2022-09-14 =

* FIX: autologin_user method needs to be static for third-party plugin to call it (without a fatal error on PHP 8.0+)

= 1.11.2 - 2022-02-22 =

* TWEAK: When an entitlement is claimed, update the "last check-in" field (i.e. don't wait for first updates check)

= 1.11.1 - 2022-02-14 =

* TWEAK: Daily cron now checks and removes expired SID tokens from the user meta table and all plugin's cached files that over a week old
* TWEAK: Replace unnecessarily noisy error_log() when a site authenticated via site ID with an action updraftmanager_authenticated_via_sid (in case someone did want the info).
* TWEAK: Update bundled phpseclib library
* TWEAK: Increase init priority value, so that other plugins running on the same hook will run first

= 1.11.0 - 2021-10-02 =

* FIX: Replace brace syntax that is no longer allowed in PHP 8.0 in php-markdown-extra library
* FIX: Bulk plugin deletion not working
* FEATURE: New WP-CLI command (import-zip) to import plugin's zip file locally
* FEATURE: New WP-CLI command (update-versions) to update plugins' supported WordPress versions (minimum and tested versions) for the latest version of the available zip file
* TWEAK: Port from previous semaphore classes to Updraft_Semaphore_3_0
* TWEAK: Add logic to ignore 'x-spm-duplicate' header from being issued when home URL is found to be different from network site URL
* TWEAK: Add a class and wrappers to the download link for easier styling
* TWEAK: Now that PHP 8.0 is released, bump the PHP requirement (for this plugin - doesn't affect plugins distributed through it) to PHP 7.1+
* TWEAK: Adjust a method signature that caused a deprecation notice on PHP 8.0
* TWEAK: Update bundled phpseclib library to 2.0.33

= 1.10.19 - 2020-11-05 =

* FIX: Fix fatal error in 1.10.18 when sending out emails, due to renamed class

= 1.10.18 - 2020-10-30 =

* TWEAK: Update jQuery document ready style to the one not deprecated in jQuery 3.0
* TWEAK: Updated soundasleep/html2text library so as to lose create_function() call (which is gone in PHP 8)
* TWEAK: Now requires PHP 7.0 or greater, as required by updated library

= 1.10.17 - 2020-09-28 =

* TWEAK: The public Updraft_Manager_Plugin::get_user_entitlements() method, and related updraftmanager_get_plugin_info_entitlements_pre_processing filter now return an array with keys 'addons' and 'support' (instead of 0 and 1). If you were directly hooking into those, you will need to modify your code.
* TWEAK: Add the url_normalised value to what is returned from get_user_addon_entitlements() and (by inheritance) related functions
* TWEAK: Updraft_Manager_Plugin::db_set_last_checkin() now throws an exception, as it should never be called (legacy method)
* TWEAK: Return x-spm-duplicate-of in the result if the licence appears to be deployed on multiple sites (e.g. site was cloned)
* TWEAK: Prevent a PHP notice being logged in the short-lived 1.10.16

= 1.10.15 - 2020-09-21 =

* TWEAK: Introduced a requirement for WP 5.5+ (for the site this plugin runs on - this does not affect what requirements you have for plugins you are distributing through it). We assume that plugin authors keep their installs updated, so don't want to waste time on verifying backwards compatibility for WP 5.5+ PHPMailer changes.
* TWEAK: Introduced a requirement for PHP 5.6+ (for the site this plugin runs on - this does not affect what requirements you have for plugins you are distributing through it)
* TWEAK: Changed code that interacts directly with PHPMailer for WP 5.5+ compatibility

= 1.10.14 - 2020-09-17 =

* TWEAK: When a site provides multiple plugins, the "User Entitlements" admin page will now display all those with entitlements before those that don't.
* TWEAK: Update phpseclib version to latest 2.0 series release

= 1.10.13 - 2020-09-04 =

* TWEAK: Update phpseclib version to latest 2.0 series release
* TWEAK: Do not echo back the slug name on the udmanager_changelog_slug parameter when it was not found (N.B. no security implications, as the output was properly escaped)

= 1.10.12 - 2020-06-15 =

* TWEAK: In-dashboard download was emitting an incorrect Content-Length: header

= 1.10.11 - 2020-04-30 =

* TWEAK: 'udmanager_send_reminder' filter is now includes the user id, to allow email reminders to be filtered on a per user basis

= 1.10.10 - 2020-04-21 =

* TWEAK: Prevent unwanted PHP notices due to wp_mail filter being called with unexpected parameter type when sending HTML mail
* TWEAK: Marked as supporting WP 4.8+ (nothing has changed to make it incompatible, but this is the support requirement)

= 1.10.9 - 2019-11-13 =

* FIX: Fix a regression in the introduction of database cacheing that could cause the licence display in admin screens to not update straight away

= 1.10.8 - 2019-11-05 =

* FIX: Fix a regression in 1.10.6 which could cause licences to not be correctly fetched from the database when displaying a list of licences for all plugins on sites serving multiple plugins
* TWEAK: Switch from get_user_by() to WP_User::get_data_by() to eliminate an unnecessary SELECT of usermeta on a plugin update.

= 1.10.6 - 2019-10-29 =

* TWEAK: Perform one less SQL SELECT query per update check
* TWEAK: Perform one less transient lookup (resulting in two less SQL SELECT queries if using the database as the transient store) per update check
* TWEAK: Use WPDB::query() instead of WPDB::update() to update last check-in times, resulting in suppression of an unwanted SHOW FULL COLUMNS query
* PERFORMANCE: The above tweaks, on an optimised setup, result in the total lifetime of an update check performing 8 SQL queries (if using the database for transients) or 6 (if not) instead of the previous 12 or 8.

= 1.10.5 - 2019-10-12 =

* TWEAK: Set the Return-Path: header on outgoing renewal reminder emails

= 1.10.4 - 2019-07-17 =

* TWEAK: Add filter updraftmanager_manage_user_page_before_entitlements_prepare_additional_data

= 1.10.3 - 2019-04-29 =

* TWEAK: Added an action run upon entitlement updating

= 1.10.1 - 2019-04-13 =

* FEATURE: Add the ability to download past zips from the administrative interface
* TWEAK: Marked as supporting WP 4.5+ (nothing has changed to make it incompatible, but this is the support requirement)
* TWEAK: Marked as supporting WP 5.2

= 1.9.27 - 2019-03-28 =

* TWEAK: Prevent PHP notices in some cases of checks on expired licences

= 1.9.26 - 2019-03-09 =

* FEATURE: Will now allow users with no account to see the recent version number and tested until WP version, providing an incentive to connect or renew
* TWEAK: Use version_compare(), not strcmp(), for sorting in tables.

= 1.9.25 - 2019-02-28 =

* TRANSLATIONS: Change translation domain to match plugin slug for compatibility with the wordpress.org translation system
* TWEAK: Add function visibility markers in the list table classes

= 1.9.23 - 2019-02-22 =

* FIX: The owner ID was not passed on properly on the AJAX call for a self-reset of a licence

= 1.9.22 - 2019-01-29 =

* TWEAK: Add a new "add unlimited licence" option for licence managers, controlled by the filter updraftmanager_show_add_unlimited_link

= 1.9.21 - 2019-01-28 =

* TWEAK: Suppress a PHP notice due to an uninitialised variable when calling the updraftmanager_get_plugin_info_entitlements_pre_processing filter

= 1.9.20 - 2019-01-22 =

* TWEAK: Handle richer info via the udmanager_get_orders filter

= 1.9.19 - 2019-01-07 =

* TWEAK: Prevent PHP notices when a user in the licence table no longer exists in WordPress
* TWEAK: Update the bundled phpseclib version to 2.0.13

= 1.9.18 - 2018-12-10 =

* FIX: When both expired and non-expired add-ons existed on the same site, the version number calculation failed to take the status into account
* TWEAK: Now marking as requiring WP 4.3+ (it'll still work on earlier, but this is the official support requirement)
* TWEAK: Enhance the prune_expired_entitlements() method to handle add-on as well as support entitlements

= 1.9.17 - 2018-12-05 =

* TWEAK: Supplement the filter updraftmanager_get_plugin_info_user_addons_pre_processing with the more flexible updraftmanager_get_plugin_info_entitlements_pre_processing
* TWEAK: Allow the updraftmanager_get_plugin_info_user_addons_pre_processing filter to first view details of expired support requirements

= 1.9.15 - 2018-12-04 =

* FIX: User-side 'delete assignment' link was missing the necessary ID

= 1.9.14 - 2018-12-01 =

* FIX: An uninitialised variable was causing the update description to omit individual add-on descriptions for plugins with variable add-ons
* FIX: Decoding of incoming client meta-information was failing due to WP slashing

= 1.9.13 - 2018-12-01 =

* TWEAK: Add a filter udmanager_claimaddon_response_data allowing external code to modify the response to a successful claim
* TWEAK: Some minor code-tidying

= 1.9.12 - 2018-11-30 =

* FIX: Front-end licence deletion/reset links were relying on a DOM hierarchy that was not universal, which prevented them from working on some sites

= 1.9.11 - 2018-11-28 =

* TWEAK: Cope with two nested .entry-content divs (seen in the wild)
* TWEAK: Do not show a licence reset link to a user if the licence was already deleteable.
* FIX: The licence reset link in the admin area for shop managers had stopped working

= 1.9.1 - 2018-11-27 =

* TWEAK: Only show the message about when a licence can be released if the licence is not expired, and check-ins have stopped occurring, and make the behaviour filterable (updraftmanager_show_can_release_after_message)

= 1.9.0 - 2018-11-27 =

* FEATURE: Allow users to release their own licences from their account page (i.e. the display provided by the udmanager shortcode). Previously, this could only be done from the assigned site itself. By default, this is allowed from 30 days after the site last checked-in. To adjust this, use the filter updraftmanager_entitlement_when_can_be_reset.
* FIX: Fix an issue that could cause a request for assignment to give an error the first time
* TWEAK: Various bits of re-factoring of how the HTML list of licences is generated
* TWEAK: Add the x-spm-meta header for returning miscellaneous information to the checker, and the 'indirect' sub-key for identifying that the entitlement was not bound to the site directly
* TWEAK: Minor code clean-ups (removing unused code, adding more docblocks)

= 1.8.12 - 2018-11-16 =

* TWEAK: Add new filter updraftmanager_get_plugin_info_user_addons_pre_processing
* TWEAK: Some tidying/commenting of the main plugin_info routine

= 1.8.11 - 2018-10-15 =

* TWEAK: Set the updraftmanager_last_version option to autoload

= 1.8.10 - 2018-09-12 =

* TWEAK: New filter udmanager_user_row_action_title

= 1.8.9 - 2018-08-18 =

* TWEAK: Add new parameters to the filter updraftmanager_showaddon
* TWEAK: Now marking as requiring WP 4.2+ (it'll still work on earlier, but this is the official support requirement)
* TWEAK: Add a new filter updraftmanager_show_addon_box
* FIX: A potentially incorrect parameter was sent to the updraftmanager_showaddon filter

= 1.8.8 - 2018-08-06 =

* TWEAK: Add a check after get_user_by() to make sure the result is valid

= 1.8.7 - 2018-07-28 =

* TWEAK: Add a filter updraftmanager_renewalemail_log_line

= 1.8.6 - 2018-06-07 =

* TWEAK: Added a filter that allows information on if the user has an active subscription to be sent back to the client

= 1.8.5 - 2018-05-28 =

* TWEAK: Catch a case in the debug log which email address anonymisation was skipping

= 1.8.4 - 2018-05-25 =

* TWEAK: Email addresses in the debug log are now anonymised by default. If you don't need/want this, use the filter updraftmanager_renewalemail_anonymised_email to undo it.

= 1.8.3 - 2018-05-24 =

* FEATURE: Include licence information in the WP (4.9.6+) export and privacy tools

= 1.8.2 - 2018-05-22 =

* FEATURE: Automatically delete licences that expired over 18 months ago (use the updraftmanager_delete_expired_licences_time_ago_in_sec to adjust or de-activate that). Useful for general clean-up and any requirements to not keep data longer than necessary (e.g. GDPR compliance).
* FIX: The above feature in 1.8.1 was bad and deleted all expired licences (not just expired over 18 months ago).
* TWEAK: Now marking as requiring WP 4.1+ (it'll still work on earlier, but this is the official support requirement)

= 1.7.12 - 2018-05-15 =

* FIX: Fix a bug in Updraft_Manager_Plugin_Premium::reset_allocated_but_unused_entitlements() which stopped it from taking effect
* TWEAK: Some minor code tidying

= 1.7.11 - 2018-04-07 =

* TWEAK: Correct erroneous link

= 1.7.10 - 2018-04-18 =

* TWEAK: Delete a user's licences when the user is deleted (database consistency and prevent unwanted data retention (of relevance to GDPR compliance))

= 1.7.9 - 2018-04-14 =

* TWEAK: Send back a usable response if the provided plugin slug is invalid
* TWEAK: Replace use of jQuery.parseJSON() with JSON.parse()
* TWEAK: Update phpseclib library to current version (2.0.10)

= 1.7.8 - 2018-02-06 =

* TWEAK: Swap updraftmanager_unactivatedpurchase filter for updraftmanager_unactivatedpurchases in case of grouping
* TWEAK: Updated bundled phpseclib library to current version (2.0.7)
* TWEAK: Always check the validity of the slug passed to Updraft_Manager_Plugin::__construct()
* TWEAK: Add a few docblocks
* TWEAK: Bump the supported WP version to 4.0 (there's really no reason to run a plugin like this on an obsolete site)

= 1.7.7 - 2017-10-26 =

* TWEAK: Remove an unused parameter from the displayed updates URL

= 1.7.6 - 2017-10-25 =

* FIX: Fix a syntax error in the SQL for the initial creation of the entitlement table (re-activate if you had installed a broken version)

= 1.7.5 - 2017-10-18 =

* FIX: When changing the order of zip rules, a nonce needed to be provided to prevent failure to reload the page

= 1.7.4 - 2017-09-28 =

* FIX: Fix a couple of places were performing unnecessary nonce checks on ordinary GET page load actions

= 1.7.3 - 2017-09-21 =

* TWEAK: Set the number of retries for plupload to 2 (instead of default 0) and make all plupload settings filterable.
* TWEAK: Correct reference to variable in the updraftmanager_homeaddons_addon_description filter
* TWEAK: Decrease the default chunk size on the plupload widget (better for bad connections)

= 1.7.2 - 2017-08-18 =

* FEATURE: Add shortcode: udmanager_changelog; used for displaying a plugin's changelog

= 1.6.21 - 2017-08-17 =

* TWEAK: Extra parameter for the listaddons command, allowing data to be returned in JSON format

= 1.6.20 - 2017-08-17 =

* FIX: Add missing nonce to links for adding zip rules (was being checked for, but not present)

= 1.6.19 - 2017-08-08 =

* FIX: Add missing nonce to links for editing/deleting zip rules (was being checked for, but not present)

= 1.6.18 - 2017-08-08 =

* TWEAK: Allow Updraft_Manager_Plugin::calculate_download() to be called with non-DB parameters

= 1.6.17 - 2017-08-07 =

* SECURITY: Various actions were not protected by nonces. This meant that, if a malicious actor decided to personally target you, and enticed you to visit a properly crafted page or click a link whilst you were logged in to your WP dashboard, he could cause unauthorised actions to be performed (e.g. delete download rules, delete plugins from the list of available downloads)
* TWEAK: Prevent PHP notice upon multi-stage drag+drop uploads
* TWEAK: Updated bundled phpseclib and html2text libraries to current releases

= 1.6.16 - 2017-08-05 =

* TWEAK: Sends back current update info as part of the response to a successful claim (requires the client-side updater to be version 1.4.4 or later if download rules using PHP/WP versions are present (earlier versions do not send this information))
* TWEAK: Legacy-format (which correlates to pre-April 2016 releases of the client-side updater - https://github.com/DavidAnderson684/simba-plugin-manager-updater) site information is now not processed by default. To turn it back on, use add_filter('updraftmanager_process_old_siteinfo_format', '__return_true'), and be running on PHP7+. All handling may be completely removed in future. The site information includes PHP + WP versions, which is not used unless you have download rules referencing them.

= 1.6.15 - 2017-06-01 =

* FIX: Handling of the showaddons and showlink parameters in the udmanager shortcode was incorrect
* FIX: Fix incorrect total counts shown in 1.6.14
* COMPATIBILITY: Mark as compatible with WP 4.8 (tested/supported: 3.7+)
* TWEAK: Improve the layout of the (shortcode) box showing licensing information, especially when there are many licences

= 1.6.12 - 2017-05-31 =

* FIX: Fix a wrong manipulation of registered hooks in the mailer class

= 1.6.11 - 2017-05-24 =

* FIX: Fix a wrong variable reference in the get_licences_for_url() method which resulted in wrong results

= 1.6.10 - 2017-05-10 =

* TWEAK: Add a filter updraftmanager_check_password to allow external code to confirm passwords

= 1.6.9 - 2017-05-10 =

* TWEAK: Allow the noun 'password' to be filtered (e.g. call it a licence key)

= 1.6.8 - 2017-05-09 =

* TWEAK: Replace is_site_licensed() with the more flexible get_licences_for_url()
* TWEAK: Make the $expire_window_end parameter optional in db_get_all_entitlements()

= 1.6.7 - 2017-05-08 =

* TWEAK: Add a method is_site_licensed() for checking whether a particular site is licensed
* TWEAK: Fold duplicate versions of function normalise_url() into one

= 1.6.6 - 2017-05-06 =

* TWEAK: Add url_normalised column to the entitlements table. This will perform an automatic update on the existing table.
* FIX: Add files missed from SVN commit for 1.6.5

= 1.6.4 - 2017-04-27 =

* FIX: wordpress.org download was missing a JavaScript file used by the quick-uploader

= 1.6.3 - 2017-04-11 =

* TWEAK: Log more information when zip delivery fails
* TWEAK: Previous unhooking of WP mail from filters after delivery was not taking effect
* TWEAK: Distinguish the responses for empty and unknown email addresses when disconnecting
* TWEAK: Prevent a possible PHP notice when showing add-ons on the front-end

= 1.6.2 - 2017-02-16 =

* TWEAK: Prevent a PHP notice on some updates checks from legacy clients on PHP >= 7.0
* TWEAK: Add a filter udmanager_claim_addon_entitlement_allow to allow the forbidding of a claim to an entitlement

= 1.6.1 - 2017-02-06 =

* TWEAK: Add an action updraftmanager_renewalemail_log to make it easier to process the renewal log arbitrarily
* TWEAK: When parsing an uploaded zip in the drag/drop uploader, trim the lines first, to avoid line-ending issues

= 1.6.0 - 2017-02-04 =

* FEATURE: Added a drag-drop uploader, allowing you to upload new plugin versions very quickly (no details to enter, as long as the plugin includes a readme.txt with fields for supported WP versions). If combined with <a href="https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/">the commercial WooCommerce conncetor</a>, any linked WooCommerce products will also have their downloads updated.

= 1.5.26 - 2017-01-09 =

* TWEAK: Added an extra parameter to the updraftmanager_send_response filter, which previously did not provide a way to identify which plugin it was being called for.

= 1.5.25 - 2017-01-06 =

* FIX: The fix in 1.5.21 for saving the contents of the renewal email setting if TinyMCE had not been initialised was incorrect.
* TWEAK: Bump minimum supported WP version up to 3.4 (intention is now to raise it with every new WP release)

= 1.5.24 - 2017-01-03 =

* TWEAK: Add a few more hooks for extensions to hook into
* TWEAK: Remove a few bits of legacy code
* FIX: The API for allowing extensions to save meta-data together with a plugin's data was broken
* FIX: Editing the details for an existing zip file could result in (harmless/unused) extra rows in the database
* FIX: Add missing files from 1.5.22, 1.5.23 to SVN

= 1.5.21 - 2016-12-31 =

* TWEAK: Update bundled html2text and phpseclib libraries
* FIX: Fix bug that prevented saving the contents of the renewal email setting if TinyMCE had not been initialised

= 1.5.20 - 2016-12-17 =

* FEATURE: New rule added for deciding what download to make available: allow an upgrade to be made available to a random percentage of sites checking for updates (e.g. roll out a new version more slowly than making it immediately universally available)
* TWEAK: Do not show download links (which didn't work, anyway) for expired products
* FIX: In the UI, any download rules when comparing version numbers had less than/greater than accidentally transposed

= 1.5.19 - 2016-09-12 =

* TWEAK: Make the filter adjustments when sending mails compatible with WP 4.7
* FEATURE: The "renewal reminders" dashboard screen now has a feature for debugging renewal reminders for a chosen specific customer

= 1.5.18 - 2016-08-18 =

* TWEAK: When wp_die-ing due to an invalid/expired token, return an HTTP 401 (unauthorised) (instead of the default 500)

= 1.5.17 - 2016-07-28 =

* TWEAK: Added a couple of filters for customisation
* COMPATIBILITY: Compatible with WP 4.6
* FIX: When using the shortcode to show a logged in user's plugins, the user ID was over-ridable by the wrong filter (hence, this only made a difference when a filter was being used for customisation)

= 1.5.16 - 2016-04-08 =

* FIX: Download rules based on a PHP version were not previously working (PHP version was ignored)
* SECURITY: Information sent from the client (such as WP version, PHP version) that was taken into account for calculating the download to be offered was transported using PHP's serialize() format, which is unsafe. To be exploitable, your site would need to have a second vulnerability in another PHP component, that performed unsafe actions on object creation. The transport format has now been updated to JSON. If your updates server has download rules depending on client site information (e.g. WP/PHP versions), then after updating, if your server is running an earlier version than PHP 7 (which is the first version to allow safe handling of serialized data), you will need to update the client updater class (https://github.com/DavidAnderson684/simba-plugin-manager-updater) to a version from 8th April 2016 onwards, in order to send this data in the new format. (You can also set the filter updraftmanager_process_old_siteinfo_format to be (bool)true to continue the old, unsafe behaviour, if you must and are confident that your site has no object creation vulnerabilities - which would be very hard to audit). If your site has no such download rules, then after updating this plugin, updating the client-side class does not matter, since the potentially unsafe data is ignored.

= 1.5.15 - 2016-03-11 =

* FIX: The date-picker/reset button on the admin licence management page stopped working after editing entitlements, until you refreshed the page
* TWEAK: Display the user ID directly on the licence page (instead of reading it from the URL bar)

= 1.5.14 - 2016-02-22 =

* TWEAK: Tweak format that data is sent back in upon connect (remains backwards-compatible)

= 1.5.13 - 2016-02-06 =

* TWEAK: Mark a method static

= 1.5.12 - 2016-01-27 =

* TWEAK: Add a filter to allow modification of returned plugin_info calls
* TWEAK: Send back JSON with the correct MIME type when returning plugin_info calls

= 1.5.11 - 2016-01-20 =

* TWEAK: Add an extra parameter to a public function, allowing item renewals specified in "months from now" style to be skipped if they are already later than the specified time

= 1.5.10 - 2015-12-03 =

* FIX: "Send emails from" setting was not taking effect (default was always used)
* TWEAK: Trim invalid non-numeric values entered for renewal reminder days setting
* TWEAK: Add action to allow insertion of content on the user entitlement management page

= 1.5.8 - 2015-11-26 =

* FIX: New downloads were not being recorded in the download summary
* TWEAK: Provide extra filter when checking renewals, for more flexibility

= 1.5.7 - 2015-11-20 =

* TWEAK: Log all PHP events during renewal reminder email run
* TWEAK: Added urlparameters code to renewal emails, for connection plugins to replace with URL parameters for auto-adding products to cart
* TWEAK: When auto-logging in a user, include the $user parameter on the wp_login action
* TWEAK: Add a filter for the maximum number of renewal mails sent when in debug mode (defaults to 1)
* FIX: Fix conflict with WP Better Emails when sending outgoing mail
* FIX: The user unsubscribe option, set by connectors, was not being honoured

= 1.5.5 - 2015-11-12 =

* SPEED: No longer write a transient on every updates check
* SPEED: Introduce SIMBA_PLUGINS_MANAGER_LOCKDOWN constant - define this to true to prevent updating the "last checked-in" field for a licence (useful if under heavy load)
* TWEAK: Changed one of the filters to make it more flexible
* FIX: Fix mishandling of parameters in db_get_all_entitlements method
* FIX: Fix a bug introduced in 1.5.4 that prevented plugins being deleted
* FIX: Fix bug introduced in 1.5.4 in the granting of new support entitlements

= 1.5.4 - 2015-11-09 =

* FEATURES: This is now a full version of the plugin, identical to that used on commercial sites. Instead of selling the premium plugin, we are now selling connectors for e-commerce stores; a WooCommerce connector is available: https://www.simbahosting.co.uk/s3/product/plugin-updates-licensing-and-renewals-manager-woocommerce-connector/
* FEATURE: Licence handling - add, delete, renew and display information about licences.
* FEATURE: Automatic emailing of customers whose licences are coming up for expiry - including the facility to automatically add the items to the user's cart (and optionally, log the user in).
* FEATURE: New filter + code allowing users to be allowed to entirely delete their own licences (most suitable for products with unlimited licences)
* INTERNALS: Renewal code now re-written to treat the licence as the primary object, and orders as secondary - as it ought to be (instead of vice-versa). As a result, a lot of internal things are now more straightforward.
* INTERNALS: Plugins and associated data are now stored in their own table, instead of in usermeta. To migrate old data, use the script convert-plugins.php found in this plugin's directory (or recreate manually).
* INTERNALS: Licences and associated data are now stored in their own table, instead of in usermeta
* INTERNALS: Download counts and associated data are now stored in their own table, instead of in usermeta
* TWEAK: Re-route all code that saves entitlement information through a single method, to allow for future format changes
* TESTING: Now tested up to WordPress 4.4 (beta)

= 1.4.8 - 2015-10-02 =

* TWEAK: Add extra parameter to the internal function for resetting unused licences

= 1.4.7 - 2015-08-27 =

* TWEAK: Split the capability checks into two (both with the same default value), to allow filters to cause different users to have different levels of access to admin functions (managing plugins and managing licences); the filter udmanager_user_id_for_licences is also introduced as part of this.
* FEATURE: New date widget for resetting all licence expiries at once (hence relevant to Premium only)
* TWEAK: Enqueue internal scripts with a version number, to prevent them being cached across updates

= 1.4.6 - 2015-08-22 =

* TWEAK: Trim email addresses used for authentication (saw a user who prefixed his with a space)
* TWEAK: Prevent PHP fatal error in case of an unexpectedly missing internal directory

= 1.4.5 - 2015-08-04 =

* TWEAK: Implement new token-based authentication method (paid plugins, i.e. Premium version)
* COMPATIBILITY: Tested + marked as compatible on WP 4.3

= 1.4.4 - 2015-05-13 =

* FIX: Prevent PHP notices when unregistered site checks in (Premium)
* TWEAK: Admin users get to see the internal licence ID as a tooltip
* TWEAK: Improve detection of recycled licences

= 1.4.3 - 2015-05-11 =

* FIX: Now displays correct download numbers again on WP 4.2+
* FEATURE: If the uploaded plugin contains a changelog (either within a WordPress-format readme.txt, or as changelog.txt), then this is automatically parsed and included with the plugin information. If the readme.txt contains an FAQ section, then this is included likewise.
* TWEAK: Add udmanager_manage_permission filter, allowing the capability needed to perform operations filterable.
* TWEAK: Return HTTPS urls for downloads when the original request was over HTTPS
* TWEAK: Better error handling in various places in the zip download process
* TWEAK: When zip file creation fails (Premium), set the HTTP status code to 500

= 1.4.0 - 2015-03-01 =

* RELEASE: First public release. Supports hosting + providing updates for free plugins, with multiple versions and download rules.

== Screenshots ==

1. Display of managed plugins

2. Adding a new plugin

3. Adding a new zip for a plugin

4. Managing zips for a plugin

5. Adding a download rule for a plugin

6. Managing download rules for a plugin

7. Showing users on your website the plugins that they can download, using a shortcode

8. Configuring renewal reminder emails for expiring licences

9. Setting up the renewal reminder email for customers with expiring licences

10. Setting up a licence renewal coupon in WooCommerce (via a paid extension).

11. Manging a user's licences in your WP dashboard

12. The updater class in action in one of your users' dashboards

13. Easy drag-and-drop uploading of new plugin zip versions

== License ==

The MIT License (MIT)

Copyright © 2015- David Anderson, https://www.simbahosting.co.uk

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

== Upgrade Notice ==

* 1.11.10 : Add a cron lock to prevent duplicate runs and resolve some PHP deprecations. Requires PHP 7.4+. A recommended update for all.
