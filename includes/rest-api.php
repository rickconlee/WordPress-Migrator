<?php
// Register custom REST API endpoints.
add_action('rest_api_init', function () {
    register_rest_route('wordpress-migrator/v1', '/backup', array(
        'methods' => 'POST',
        'callback' => 'perform_full_backup',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ));
    register_rest_route('wordpress-migrator/v1', '/restore', array(
        'methods' => 'POST',
        'callback' => 'restore_backup',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        ),
        'args' => array(
            'backup_file' => array(
                'required' => true,
                'type' => 'file'
            ),
            'db_file' => array(
                'required' => true,
                'type' => 'file'
            )
        )
    ));
    register_rest_route('wordpress-migrator/v1', '/progress', array(
        'methods' => 'GET',
        'callback' => 'get_backup_progress',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ));
    register_rest_route('wordpress-migrator/v1', '/logs', array(
        'methods' => 'GET',
        'callback' => 'get_logs',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ));
    register_rest_route('wordpress-migrator/v1', '/delete-backups', array(
        'methods' => 'POST',
        'callback' => 'delete_backups',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ));
});
?>
