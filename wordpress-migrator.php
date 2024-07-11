<?php
/**
 * Plugin Name: WordPress Migrator
 * Plugin URI: https://rickconlee.com/wordpress-migrator
 * Description: This plugin allows you to migrate your site between two different WordPress installs. It is intended for migrating your wordpress site to a new server. 
 * Version: 1.1
 * Author: Rick Conlee
 * Author URI: https://rickconlee.com
 * License: GPLv2 or later
 * Text Domain: wordpress-migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Register a custom REST API endpoint.
add_action( 'rest_api_init', function () {
    register_rest_route( 'full-site-backup/v1', '/backup', array(
        'methods' => 'POST',
        'callback' => 'perform_full_backup',
        'permission_callback' => 'full_site_backup_permission_check'
    ));
});

function full_site_backup_permission_check( $request ) {
    $headers = $request->get_headers();
    if ( isset( $headers['authorization'] ) ) {
        $auth = $headers['authorization'][0];
        list( $user, $pass ) = explode( ':', base64_decode( substr( $auth, 6 ) ) );

        if ( $user && $pass ) {
            $user = wp_authenticate( $user, $pass );
            if ( ! is_wp_error( $user ) && user_can( $user, 'manage_options' ) ) {
                return true;
            }
        }
    }
    return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.' ), array( 'status' => 401 ) );
}

function perform_full_backup() {
    $uploads_dir = wp_upload_dir()['basedir'];
    $backup_dir = $uploads_dir . '/full-site-backup';
    $backup_file = $backup_dir . '/backup.zip';
    $db_dump_file = $backup_dir . '/database.sql';

    if ( ! file_exists( $backup_dir ) ) {
        mkdir( $backup_dir, 0755, true );
    }

    // Create database dump using PHP
    global $wpdb;
    $db_host = DB_HOST;
    $db_name = DB_NAME;
    $db_user = DB_USER;
    $db_password = DB_PASSWORD;

    $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($mysqli->connect_error) {
        return new WP_Error( 'db_connection_failed', __( 'Database connection failed: ' . $mysqli->connect_error ), array( 'status' => 500 ) );
    }

    $tables = $mysqli->query("SHOW TABLES");
    if (!$tables) {
        return new WP_Error( 'db_query_failed', __( 'Failed to retrieve tables: ' . $mysqli->error ), array( 'status' => 500 ) );
    }

    $sql_dump = "";
    while ($row = $tables->fetch_row()) {
        $table = $row[0];
        $create_table = $mysqli->query("SHOW CREATE TABLE `$table`");
        if ($create_table) {
            $create_row = $create_table->fetch_row();
            $sql_dump .= "\n\n" . $create_row[1] . ";\n\n";

            $rows = $mysqli->query("SELECT * FROM `$table`");
            while ($data = $rows->fetch_assoc()) {
                $sql_dump .= "INSERT INTO `$table` VALUES(";
                foreach ($data as $value) {
                    $sql_dump .= "'" . $mysqli->real_escape_string($value) . "', ";
                }
                $sql_dump = rtrim($sql_dump, ', ');
                $sql_dump .= ");\n";
            }
            $sql_dump .= "\n\n";
        }
    }

    file_put_contents($db_dump_file, $sql_dump);
    $mysqli->close();

    if (!file_exists($db_dump_file) || filesize($db_dump_file) === 0) {
        return new WP_Error( 'db_dump_failed', __( 'Database dump failed.' ), array( 'status' => 500 ) );
    }

    // Create filesystem dump using ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return new WP_Error( 'zip_creation_failed', __( 'Could not create zip archive.' ), array( 'status' => 500 ) );
    }

    $root_path = realpath(ABSPATH);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root_path),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir() && strpos($file->getRealPath(), $backup_dir) === false) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($root_path) + 1);
            $zip->addFile($file_path, $relative_path);
        }
    }

    $zip->close();

    if (!file_exists($backup_file) || filesize($backup_file) === 0) {
        return new WP_Error( 'backup_failed', __( 'Filesystem backup failed.' ), array( 'status' => 500 ) );
    }

    return array(
        'backup_url' => wp_upload_dir()['baseurl'] . '/full-site-backup/backup.zip',
        'db_dump_url' => wp_upload_dir()['baseurl'] . '/full-site-backup/database.sql'
    );
}

