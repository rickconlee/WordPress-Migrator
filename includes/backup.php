<?php
// Function to perform the backup
function perform_full_backup(WP_REST_Request $request)
{
    wordpress_migrator_log('Starting backup process.');

    $uploads_dir = wp_upload_dir()['basedir'];
    $site_url = parse_url(get_site_url(), PHP_URL_HOST);
    $timestamp = date('Ymd_His');
    $backup_dir = $uploads_dir . '/wordpress-migrator';
    $backup_file = $backup_dir . '/' . $site_url . '_backup_' . $timestamp . '.zip';
    $db_dump_file = $backup_dir . '/' . $site_url . '_database_' . $timestamp . '.sql';

    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
        wordpress_migrator_log('Created backup directory: ' . $backup_dir);
    }

    // Initialize progress status
    set_transient('wordpress_migrator_backup_progress', array('status' => 'starting', 'progress' => 0), 3600);

    // Create database dump using PHP
    global $wpdb;
    $db_host = DB_HOST;
    $db_name = DB_NAME;
    $db_user = DB_USER;
    $db_password = DB_PASSWORD;

    $mysqli = new mysqli($db_host, $db_user, $db_password, $db_name);
    if ($mysqli->connect_error) {
        wordpress_migrator_log('Database connection failed: ' . $mysqli->connect_error);
        return new WP_Error('db_connection_failed', __('Database connection failed: ' . $mysqli->connect_error), array('status' => 500));
    }

    $tables = $mysqli->query("SHOW TABLES");
    if (!$tables) {
        wordpress_migrator_log('Failed to retrieve tables: ' . $mysqli->error);
        return new WP_Error('db_query_failed', __('Failed to retrieve tables: ' . $mysqli->error), array('status' => 500));
    }

    $total_tables = $tables->num_rows;
    $table_count = 0;
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

        // Update progress
        $table_count++;
        $progress = round(($table_count / $total_tables) * 50); // 50% progress for DB dump
        set_transient('wordpress_migrator_backup_progress', array('status' => 'database dump', 'progress' => $progress), 3600);
    }

    $mysqli->close();

    if (!file_exists($db_dump_file) || filesize($db_dump_file) === 0) {
        wordpress_migrator_log('Database dump failed.');
        return new WP_Error('db_dump_failed', __('Database dump failed.'), array('status' => 500));
    }

    // Create filesystem dump using ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        wordpress_migrator_log('Could not create zip archive.');
        return new WP_Error('zip_creation_failed', __('Could not create zip archive.'), array('status' => 500));
    }

    $root_path = realpath(ABSPATH);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root_path),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $total_files = iterator_count($files);
    $file_count = 0;

    foreach ($files as $name => $file) {
        if (!$file->isDir() && strpos($file->getRealPath(), $backup_dir) === false) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($root_path) + 1);
            $zip->addFile($file_path, $relative_path);
        }

        // Update progress
        $file_count++;
        $progress = 50 + round(($file_count / $total_files) * 50); // Remaining 50% for filesystem dump
        set_transient('wordpress_migrator_backup_progress', array('status' => 'filesystem dump', 'progress' => $progress), 3600);
    }

    $zip->close();

    if (!file_exists($backup_file) || filesize($backup_file) === 0) {
        wordpress_migrator_log('Filesystem backup failed.');
        return new WP_Error('backup_failed', __('Filesystem backup failed.'), array('status' => 500));
    }

    // Mark progress as complete
    set_transient('wordpress_migrator_backup_progress', array('status' => 'complete', 'progress' => 100), 3600);

    wordpress_migrator_log('Backup process completed successfully.');

    return array(
        'backup_url' => wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($backup_file),
        'db_dump_url' => wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($db_dump_file)
    );
}

function get_backup_progress(WP_REST_Request $request)
{
    $progress = get_transient('wordpress_migrator_backup_progress');
    if (!$progress) {
        $progress = array('status' => 'unknown', 'progress' => 0);
    }
    return new WP_REST_Response($progress, 200);
}

function delete_backups(WP_REST_Request $request)
{
    $files = $request->get_param('files');
    $uploads_dir = wp_upload_dir()['basedir'] . '/wordpress-migrator/';

    foreach ($files as $file) {
        $file_path = $uploads_dir . basename($file);
        if (file_exists($file_path)) {
            unlink($file_path);
            wordpress_migrator_log('Deleted file: ' . $file_path);
        }
    }

    return new WP_REST_Response(array('status' => 'success'), 200);
}
?>
