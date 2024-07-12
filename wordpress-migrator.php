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
    register_rest_route( 'wordpress-migrator/v1', '/backup', array(
        'methods' => 'POST',
        'callback' => 'perform_full_backup',
        'permission_callback' => function () {
            return current_user_can( 'manage_options' );
        }
    ));
});

function perform_full_backup(WP_REST_Request $request) {
    $key = $request->get_param('key');
    if (empty($key)) {
        return new WP_Error( 'no_encryption_key', __( 'Encryption key is required.' ), array( 'status' => 400 ) );
    }

    $uploads_dir = wp_upload_dir()['basedir'];
    $backup_dir = $uploads_dir . '/wordpress-migrator';
    $backup_file = $backup_dir . '/backup_' . date('Ymd_His') . '.zip';
    $db_dump_file = $backup_dir . '/database_' . date('Ymd_His') . '.sql';
    $encrypted_db_dump_file = $db_dump_file . '.enc';
    $encrypted_backup_file = $backup_file . '.enc';

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
            file_put_contents($db_dump_file, $sql_dump, FILE_APPEND | LOCK_EX);

            $rows = $mysqli->query("SELECT * FROM `$table`");
            while ($data = $rows->fetch_assoc()) {
                $sql_dump = "INSERT INTO `$table` VALUES(";
                foreach ($data as $value) {
                    $sql_dump .= "'" . $mysqli->real_escape_string($value !== null ? (string)$value : 'NULL') . "', ";
                }
                $sql_dump = rtrim($sql_dump, ', ');
                $sql_dump .= ");\n";
                file_put_contents($db_dump_file, $sql_dump, FILE_APPEND | LOCK_EX);
            }
            file_put_contents($db_dump_file, "\n\n", FILE_APPEND | LOCK_EX);
        }
    }

    $mysqli->close();

    if (!file_exists($db_dump_file) || filesize($db_dump_file) === 0) {
        return new WP_Error( 'db_dump_failed', __( 'Database dump failed.' ), array( 'status' => 500 ) );
    }

    // Encrypt the database dump
    encrypt_file($db_dump_file, $encrypted_db_dump_file, $key);
    unlink($db_dump_file); // Delete the unencrypted dump file

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

    // Encrypt the backup zip file
    encrypt_file($backup_file, $encrypted_backup_file, $key);
    unlink($backup_file); // Delete the unencrypted backup file

    return array(
        'backup_url' => wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($encrypted_backup_file),
        'db_dump_url' => wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($encrypted_db_dump_file)
    );
}

function encrypt_file($input_file, $output_file, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $input_handle = fopen($input_file, 'rb');
    $output_handle = fopen($output_file, 'wb');
    fwrite($output_handle, base64_encode($iv));

    while (!feof($input_handle)) {
        $chunk = fread($input_handle, 8192);
        $encrypted_chunk = openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        fwrite($output_handle, base64_encode($encrypted_chunk));
    }

    fclose($input_handle);
    fclose($output_handle);
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
    $backup_dir = $uploads_dir . '/wordpress-migrator';
    $backup_files = array_merge(
        glob($backup_dir . '/*.zip.enc'),
        glob($backup_dir . '/*.sql.enc')
    );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'WordPress Migrator', 'wordpress-migrator' ); ?></h1>
        <input type="password" id="encryption-key" placeholder="<?php esc_html_e( 'Enter Encryption Key', 'wordpress-migrator' ); ?>" />
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
                            <td><a href="<?php echo esc_url( wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($file) ); ?>" target="_blank"><?php esc_html_e( 'Download', 'wordpress-migrator' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script type="text/javascript">
        document.getElementById('trigger-backup').addEventListener('click', function() {
            var statusDiv = document.getElementById('backup-status');
            var encryptionKey = document.getElementById('encryption-key').value;
            statusDiv.innerHTML = '<?php esc_html_e( 'Backup in progress...', 'wordpress-migrator' ); ?>';

            fetch('<?php echo esc_url( rest_url( 'wordpress-migrator/v1/backup' ) ); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                },
                body: JSON.stringify({ key: encryptionKey })
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
?>
