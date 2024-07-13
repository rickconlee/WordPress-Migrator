<?php
// Admin page to trigger backup, restore, and display backup files
add_action('admin_menu', 'wordpress_migrator_admin_menu');

function wordpress_migrator_admin_menu()
{
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

function wordpress_migrator_admin_page()
{
    $uploads_dir = wp_upload_dir()['basedir'];
    $backup_dir = $uploads_dir . '/wordpress-migrator';
    $backup_files = array_merge(
        glob($backup_dir . '/*.zip'),
        glob($backup_dir . '/*.sql')
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('WordPress Migrator', 'wordpress-migrator'); ?></h1>
        <button id="trigger-backup" class="button button-primary"><?php esc_html_e('Trigger Backup', 'wordpress-migrator'); ?></button>
        <div id="backup-status">
            <div id="progress-bar-container" style="width: 100%; height: 20px; background-color: #f3f3f3; border: 1px solid #ccc;">
                <div id="progress-bar" style="width: 0; height: 100%; background-color: green;"></div>
            </div>
        </div>

        <h2><?php esc_html_e('Previous Backups', 'wordpress-migrator'); ?></h2>
        <form id="backup-list-form">
            <table class="widefat">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th><?php esc_html_e('File Name', 'wordpress-migrator'); ?></th>
                        <th><?php esc_html_e('Date Created', 'wordpress-migrator'); ?></th>
                        <th><?php esc_html_e('Download', 'wordpress-migrator'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($backup_files)): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No backups found.', 'wordpress-migrator'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($backup_files as $file): ?>
                            <tr>
                                <td><input type="checkbox" name="files[]" value="<?php echo esc_attr(basename($file)); ?>"></td>
                                <td><?php echo esc_html(basename($file)); ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i:s', filemtime($file))); ?></td>
                                <td><a href="<?php echo esc_url(wp_upload_dir()['baseurl'] . '/wordpress-migrator/' . basename($file)); ?>" target="_blank"><?php esc_html_e('Download', 'wordpress-migrator'); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <button type="button" id="delete-backups" class="button button-secondary"><?php esc_html_e('Delete Selected', 'wordpress-migrator'); ?></button>
        </form>

        <h2><?php esc_html_e('Restore Backup', 'wordpress-migrator'); ?></h2>
        <form id="restore-backup-form" enctype="multipart/form-data">
            <input type="file" name="backup_file" accept=".zip" required>
            <input type="file" name="db_file" accept=".sql" required>
            <button type="submit" class="button button-primary"><?php esc_html_e('Restore Backup', 'wordpress-migrator'); ?></button>
        </form>
        <div id="restore-logs"></div>
        <div id="error-messages"></div>
    </div>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            var triggerBackupBtn = document.getElementById('trigger-backup');
            var backupStatusDiv = document.getElementById('backup-status');
            var progressBar = document.getElementById('progress-bar');
            var restoreForm = document.getElementById('restore-backup-form');
            var logsDiv = document.getElementById('restore-logs');
            var errorMessagesDiv = document.getElementById('error-messages');

            triggerBackupBtn.addEventListener('click', function() {
                progressBar.style.width = '0';
                backupStatusDiv.innerHTML = '<p><?php esc_html_e('Backup in progress...', 'wordpress-migrator'); ?></p>';

                fetch('<?php echo esc_url(rest_url('wordpress-migrator/v1/backup')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.backup_url && data.db_dump_url) {
                        backupStatusDiv.innerHTML = '<p><?php esc_html_e('Backup completed successfully.', 'wordpress-migrator'); ?></p>' +
                            '<p><a href="' + data.backup_url + '" target="_blank"><?php esc_html_e('Download Backup', 'wordpress-migrator'); ?></a></p>' +
                            '<p><a href="' + data.db_dump_url + '" target="_blank"><?php esc_html_e('Download Database Dump', 'wordpress-migrator'); ?></a></p>';
                        progressBar.style.width = '100%';
                    } else {
                        errorMessagesDiv.innerHTML += '<p><?php esc_html_e('Backup failed.', 'wordpress-migrator'); ?></p>';
                        progressBar.style.width = '0';
                    }
                })
                .catch(error => {
                    errorMessagesDiv.innerHTML += '<p><?php esc_html_e('Backup failed.', 'wordpress-migrator'); ?></p>';
                    console.error('Error:', error);
                    progressBar.style.width = '0';
                });

                function pollProgress() {
                    fetch('<?php echo esc_url(rest_url('wordpress-migrator/v1/progress')); ?>', {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        progressBar.style.width = data.progress + '%';
                        if (data.status !== 'complete') {
                            setTimeout(pollProgress, 1000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                }

                pollProgress();
            });

            restoreForm.addEventListener('submit', function(event) {
                event.preventDefault();
                var formData = new FormData(restoreForm);
                formData.append('backup_file', document.querySelector('input[name="backup_file"]').files[0]);
                formData.append('db_file', document.querySelector('input[name="db_file"]').files[0]);

                fetch('<?php echo esc_url(rest_url('wordpress-migrator/v1/restore')); ?>', {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('<?php esc_html_e('Backup restored successfully.', 'wordpress-migrator'); ?>');
                    } else {
                        errorMessagesDiv.innerHTML += '<p><?php esc_html_e('Failed to restore backup.', 'wordpress-migrator'); ?> ' + JSON.stringify(data) + '</p>';
                        logsDiv.innerHTML += '<p>' + JSON.stringify(data) + '</p>';
                    }
                    return fetchLogs();
                })
                .catch(error => {
                    errorMessagesDiv.innerHTML += '<p>Error restoring backup: ' + error.message + '</p>';
                    console.error('Error:', error);
                    logsDiv.innerHTML += '<p>Error restoring backup: ' + error.message + '</p>';
                });

                function fetchLogs() {
                    fetch('<?php echo esc_url(rest_url('wordpress-migrator/v1/logs')); ?>', {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                        }
                    })
                    .then(response => response.json())
                    .then(logs => {
                        logsDiv.innerHTML = logs.map(log => '<p>' + log + '</p>').join('');
                        setTimeout(fetchLogs, 2000);
                    })
                    .catch(error => {
                        errorMessagesDiv.innerHTML += '<p>Error fetching logs: ' + error.message + '</p>';
                        console.error('Error:', error);
                        logsDiv.innerHTML += '<p>Error fetching logs: ' + error.message + '</p>';
                    });
                }

                fetchLogs();
            });

            document.getElementById('select-all').addEventListener('click', function(event) {
                var checkboxes = document.querySelectorAll('input[name="files[]"]');
                checkboxes.forEach(function(checkbox) {
                    checkbox.checked = event.target.checked;
                });
            });

            document.getElementById('delete-backups').addEventListener('click', function() {
                var form = document.getElementById('backup-list-form');
                var formData = new FormData(form);
                var selectedFiles = formData.getAll('files[]');

                fetch('<?php echo esc_url(rest_url('wordpress-migrator/v1/delete-backups')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ files: selectedFiles })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert('<?php esc_html_e('Selected backups deleted successfully.', 'wordpress-migrator'); ?>');
                        location.reload();
                    } else {
                        errorMessagesDiv.innerHTML += '<p><?php esc_html_e('Failed to delete selected backups.', 'wordpress-migrator'); ?></p>';
                    }
                })
                .catch(error => {
                    errorMessagesDiv.innerHTML += '<p><?php esc_html_e('An error occurred while deleting backups.', 'wordpress-migrator'); ?></p>';
                    console.error('Error:', error);
                });
            });
        });
    </script>
    <?php
}
?>
