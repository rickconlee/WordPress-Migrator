<?php
// Function to restore the backup
function restore_backup(WP_REST_Request $request)
{
    wordpress_migrator_log('Starting restore process.');

    $uploads_dir = wp_upload_dir()['basedir'] . '/wordpress-migrator/';
    $backup_file = $request->get_file_params()['backup_file'];
    $db_file = $request->get_file_params()['db_file'];

    if (!$backup_file || !$db_file) {
        wordpress_migrator_log('Backup file or database file missing.');
        return new WP_Error('backup_restore_failed', __('Backup file or database file missing.'), array('status' => 400));
    }

    $backup_file_path = $uploads_dir . basename($backup_file['name']);
    $db_file_path = $uploads_dir . basename($db_file['name']);

    if (!move_uploaded_file($backup_file['tmp_name'], $backup_file_path)) {
        wordpress_migrator_log('Failed to move backup file.');
        return new WP_Error('backup_restore_failed', __('Failed to move backup file.'), array('status' => 500));
    }

    if (!move_uploaded_file($db_file['tmp_name'], $db_file_path)) {
        wordpress_migrator_log('Failed to move database file.');
        return new WP_Error('backup_restore_failed', __('Failed to move database file.'), array('status' => 500));
    }

    // Extract the backup zip file
    $zip = new ZipArchive();
    if ($zip->open($backup_file_path) === TRUE) {
        if ($zip->extractTo(ABSPATH)) {
            wordpress_migrator_log('Backup zip file extracted successfully.');
        } else {
            wordpress_migrator_log('Failed to extract backup zip file to ABSPATH.');
            return new WP_Error('backup_restore_failed', __('Failed to extract backup zip file.'), array('status' => 500));
        }
        $zip->close();
    } else {
        wordpress_migrator_log('Failed to open backup zip file.');
        return new WP_Error('backup_restore_failed', __('Failed to open backup zip file.'), array('status' => 500));
    }

    // Import the database dump
    global $wpdb;
    $db_host = DB_HOST;
    $db_name = DB_NAME;
    $db_user = DB_USER;
    $db_password = DB_PASSWORD;

    $command = sprintf('mysql -h%s -u%s -p%s %s < %s', escapeshellarg($db_host), escapeshellarg($db_user), escapeshellarg($db_password), escapeshellarg($db_name), escapeshellarg($db_file_path));
    exec($command, $output, $return_var);

    // Log command execution result
    wordpress_migrator_log('Database import command: ' . $command);
    wordpress_migrator_log('Command output: ' . print_r($output, true));
    wordpress_migrator_log('Return var: ' . $return_var);

    if ($return_var !== 0) {
        wordpress_migrator_log('Failed to restore database dump.');
        return new WP_Error('db_restore_failed', __('Failed to restore database dump.'), array('status' => 500));
    }

    wordpress_migrator_log('Restore process completed successfully.');
    return new WP_REST_Response(array('status' => 'success'), 200);
}
?>
