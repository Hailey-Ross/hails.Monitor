<?php

if (php_sapi_name() !== 'cli' && !defined('ALLOW_CONFIG_INCLUDE')) {
    http_response_code(403);
    exit('Forbidden');
}

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'mtnbound');
define('DB_PASSWORD', 'YOUR-PASSWORD-HERE');
define('DB_NAME', 'hailsmonitor');
define('API_KEY', 'YOUR-API-KEY-HERE'); // Add the API key here

/**
 * Monitor dashboard login
 */
define('MONITOR_SUPERADMIN', 'YOUR-DASHBOARD-USERNAME-HERE');
?>
