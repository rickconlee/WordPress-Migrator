<?php
/**
 * Plugin Name: WordPress Migrator
 * Plugin URI: https://rickconlee.com/wordpress-migrator
 * Description: This plugin allows you to migrate your site between two different WordPress installs. It is intended for migrating your wordpress site to a new server. 
 * Version: 1.1-dev
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
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ));
});

function perform_full_backup() {
    $uploads_dir = wp_upload_dir()['basedir'];
    $backup_dir = $uploads_dir . '/full-site-backup';
    $backup_file = $backup_dir . '/backup_' . date('Ymd_His') . '.zip';
    $db_dump_file = $backup_dir . '/database_' . date('Ymd_His') . '.sql';

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
        'backup_url' => wp_upload_dir()['baseurl'] . '/full-site-backup/' . basename($backup_file),
        'db_dump_url' => wp_upload_dir()['baseurl'] . '/full-site-backup/' . basename($db_dump_file)
    );
}

// Admin page to trigger backup and display backup files
add_action('admin_menu', 'wordpress_migrator_admin_menu');

function wordpress_migrator_admin_menu() {
    add_menu_page(
        'WordPress Migrator',
        'WP Migrator',
        'manage_options',
        'wordpress-migrator',
        'wordpress_migrator_admin_page',
        'dashicons-migrate',
        100
    );
}

function wordpress_migrator_admin_page() {
    $uploads_dir = wp_upload_dir()['basedir'];
    $backup_dir = $uploads_dir . '/full-site-backup';
    $backup_files = glob($backup_dir . '/*.{zip,sql}', GLOB_BRACE);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'WordPress Migrator', 'wordpress-migrator' ); ?></h1>
        <button id="trigger-backup" class="button button-primary"><?php esc_html_e( 'Trigger Backup', 'wordpress-migrator' ); ?></button>
        <div id="backup-status"></div>

        <h2><?php esc_html_e( 'Previous Backups', 'wordpress-migrator' ); ?></h2>
        <table class="widefat">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'File Name', 'wordpress-migrator' ); ?></th>
                    <th><?php esc_html_e( 'Date Created', 'wordpress-migrator' ); ?></th>
                    <th><?php esc_html_e( 'Download', 'wordpress-migrator' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backup_files)): ?>
                    <tr>
                        <td colspan="3"><?php esc_html_e( 'No backups found.', 'wordpress-migrator' ); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backup_files as $file): ?>
                        <tr>
                            <td><?php echo esc_html( basename($file) ); ?></td>
                            <td><?php echo esc_html( date('Y-m-d H:i:s', filemtime($file)) ); ?></td>
                            <td><a href="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/full-site-backup/' . basename($file) ); ?>" target="_blank"><?php esc_html_e( 'Download', 'wordpress-migrator' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script type="text/javascript">
        document.getElementById('trigger-backup').addEventListener('click', function() {
            var statusDiv = document.getElementById('backup-status');
            statusDiv.innerHTML = '<?php esc_html_e( 'Backup in progress...', 'wordpress-migrator' ); ?>';

            fetch('<?php echo esc_url( rest_url( 'full-site-backup/v1/backup' ) ); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.backup_url && data.db_dump_url) {
                    statusDiv.innerHTML = '<p><?php esc_html_e( 'Backup completed successfully.', 'wordpress-migrator' ); ?></p>' +
                                          '<p><a href="' + data.backup_url + '" target="_blank"><?php esc_html_e( 'Download Backup', 'wordpress-migrator' ); ?></a></p>' +
                                          '<p><a href="' + data.db_dump_url + '" target="_blank"><?php esc_html_e( 'Download Database Dump', 'wordpress-migrator' ); ?></a></p>';
                    location.reload(); // Reload the page to update the backup files table
                } else {
                    statusDiv.innerHTML = '<?php esc_html_e( 'Backup failed.', 'wordpress-migrator' ); ?>';
                }
            })
            .catch(error => {
                statusDiv.innerHTML = '<?php esc_html_e( 'Backup failed.', 'wordpress-migrator' ); ?>';
                console.error('Error:', error);
            });
        });
    </script>
    <?php
}
