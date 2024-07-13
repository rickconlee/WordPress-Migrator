<?php
// Function to log messages to a transient for display on the admin page
function wordpress_migrator_log($message)
{
    $logs = get_transient('wordpress_migrator_logs');
    if (!$logs) {
        $logs = [];
    }
    $logs[] = date('[Y-m-d H:i:s] ') . $message;
    set_transient('wordpress_migrator_logs', $logs, 3600);
}

function get_logs(WP_REST_Request $request)
{
    $logs = get_transient('wordpress_migrator_logs');
    if (!$logs) {
        $logs = [];
    }
    return new WP_REST_Response($logs, 200);
}
?>
