<?php
define('ALLOW_CONFIG_INCLUDE', true);
require_once('/YOUR/SECURE/PATH/HERE/TO/config.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['api_key']) || $_POST['api_key'] !== API_KEY) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

$debug_file = '/YOUR/WEBPATH/TO/MONITOR/HERE/debug_output.txt';

if (!file_exists($debug_file)) {
    file_put_contents($debug_file, "Debug output file created.\n", FILE_APPEND);
}

function debug_log($message) {
    global $debug_file;
    file_put_contents($debug_file, $message . "\n", FILE_APPEND);
}

function fetch_avatar_keys(mysqli $conn, int $limit, int $stale_days, int $verify_days): array {
    $sql = "
        SELECT avatar_key
        FROM (
            SELECT
                avatar_key,
                0 AS priority,
                COALESCE(last_seen, '1970-01-01 00:00:00') AS sort_time
            FROM avatar_visits
            WHERE avatar_key IS NOT NULL
              AND TRIM(avatar_key) <> ''
              AND (avatar_name IS NULL OR TRIM(avatar_name) = '')

            UNION

            SELECT
                avatar_key,
                1 AS priority,
                COALESCE(last_name_verified_at, '1970-01-01 00:00:00') AS sort_time
            FROM avatar_visits
            WHERE avatar_key IS NOT NULL
              AND TRIM(avatar_key) <> ''
              AND avatar_name IS NOT NULL
              AND TRIM(avatar_name) <> ''
              AND last_seen IS NOT NULL
              AND last_seen < (NOW() - INTERVAL ? DAY)
              AND (
                    last_name_verified_at IS NULL
                 OR last_name_verified_at < (NOW() - INTERVAL ? DAY)
              )
        ) AS candidates
        ORDER BY priority ASC, sort_time ASC
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param("iii", $stale_days, $verify_days, $limit);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Query execution failed: ' . $error);
    }

    $result = $stmt->get_result();
    $keys = [];

    while ($row = $result->fetch_assoc()) {
        $keys[] = $row['avatar_key'];
    }

    $stmt->close();
    return $keys;
}

debug_log("Full POST data received: " . json_encode($_POST));

$action = $_POST['action'] ?? '';
debug_log("Action received: " . $action);

if ($action === 'check_empty_names' || $action === 'check_name_candidates') {
    $limit = isset($_POST['limit']) ? max(1, (int)$_POST['limit']) : 5;
    $stale_days = isset($_POST['stale_days']) ? max(1, (int)$_POST['stale_days']) : 30;
    $verify_days = isset($_POST['verify_days']) ? max(1, (int)$_POST['verify_days']) : 90;

    try {
        $keys = fetch_avatar_keys($conn, $limit, $stale_days, $verify_days);

        if (empty($keys)) {
            debug_log("No empty or stale avatar names found.");
        } else {
            debug_log(
                "Avatar keys retrieved for refresh (stale_days={$stale_days}, verify_days={$verify_days}): "
                . implode(", ", $keys)
            );
        }

        echo json_encode([
            'empty_avatar_keys' => $keys,
            'stale_days' => $stale_days,
            'verify_days' => $verify_days
        ]);
    } catch (RuntimeException $e) {
        debug_log($e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }

} elseif ($action === 'update_avatar_name') {
    $avatar_key = trim($_POST['avatar_key'] ?? '');
    $avatar_name = trim($_POST['avatar_name'] ?? '');
    $verify_days = isset($_POST['verify_days']) ? max(1, (int)$_POST['verify_days']) : 90;

    debug_log("Attempting update for avatar_key: $avatar_key with avatar_name: $avatar_name");

    $stmt = $conn->prepare("
        UPDATE avatar_visits
        SET avatar_name = ?,
            last_name_verified_at = NOW()
        WHERE avatar_key = ?
          AND (
                avatar_name IS NULL
             OR TRIM(avatar_name) = ''
             OR avatar_name <> ?
             OR last_name_verified_at IS NULL
             OR last_name_verified_at < (NOW() - INTERVAL ? DAY)
          )
    ");

    if (!$stmt) {
        debug_log("Prepare failed for update: " . $conn->error);
        echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    } else {
        $stmt->bind_param("sssi", $avatar_name, $avatar_key, $avatar_name, $verify_days);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                debug_log("Successfully updated avatar name and verification timestamp for key: $avatar_key");
                echo json_encode(['success' => 'Avatar name updated successfully']);
            } else {
                debug_log("No change needed for avatar_key: $avatar_key");
                echo json_encode(['success' => 'Avatar name already current']);
            }
        } else {
            debug_log("Update failed for key: $avatar_key - Error: " . $stmt->error);
            echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
        }

        $stmt->close();
    }
} else {
    debug_log("Invalid action specified.");
    echo json_encode(['error' => 'Invalid action specified.']);
}

$conn->close();
?>
