=== WordPress Migrator ===
Contributors: rickconlee
Donate link: https://rickconlee.com
Tags: migration, backup, restore
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This plugin allows you to migrate your site between two different WordPress installs. It is intended for migrating your WordPress site to a new server.

== Description ==

The WordPress Migrator plugin provides a simple way to backup and migrate your WordPress site to a new server. It includes the following features:
- Full site backup (database and files)
- Easy to use admin interface
- REST API endpoint for integration with tools like Ansible or CURL if you want to orchestrate this with a pipeline.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wordpress-migrator` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the 'WP Migrator' menu in the WordPress admin to access the backup functionality.

== Changelog ==

= 1.1 =
* Added admin page to trigger backups and display previous backups.
* Removed password prompt and used logged-in user permissions for backups.

= 1.0 =
* Initial release of the plugin.

== Frequently Asked Questions ==

= How do I trigger a backup? =
Navigate to the 'WP Migrator' menu in the WordPress admin and click on 'Trigger Backup'.

= How do I download previous backups? =
Previous backups are listed in the 'WP Migrator' admin page with links to download the backup and database dump files.

== Upgrade Notice ==

= 1.1 =
- Added admin page for easier backup management.
