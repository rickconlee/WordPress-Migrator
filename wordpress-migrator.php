<?php
/**
 * Plugin Name: WordPress Migrator
 * Plugin URI: https://rickconlee.com/wordpress-migrator
 * Description: This plugin allows you to migrate your site between two different WordPress installs. It is intended for migrating your wordpress site to a new server.
 * Version: 1.5
 * Author: Rick Conlee
 * Author URI: https://rickconlee.com
 * License: GPLv2 or later
 * Text Domain: wordpress-migrator
 */

if (!defined('ABSPATH')) {
    exit; 
}

// Include the files needed for the plugin to work. 
require_once plugin_dir_path(__FILE__) . 'includes/logging.php';
require_once plugin_dir_path(__FILE__) . 'includes/rest-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/backup.php';
require_once plugin_dir_path(__FILE__) . 'includes/restore.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
require_once plugin_dir_path(__FILE__) . 'includes/cron.php';
?>
