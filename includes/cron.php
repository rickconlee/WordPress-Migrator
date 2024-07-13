<?php
// Schedule a cron job for automatic deletion
if (!wp_next_scheduled('delete_old_backups')) {
    wp_schedule_event(time(), 'hourly', 'delete_old_backups');
}

add_action('delete_old_backups', 'delete_old_backups');

function delete_old_backups()
{
    $uploads_dir = wp_upload_dir()['basedir'] . '/wordpress-migrator/';
    $files = glob($uploads_dir . '*');

    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < time() - 48 * 3600) {
            unlink($file);
            wordpress_migrator_log('Deleted old file: ' . $file);
        }
    }
}
?>
