<?php
define('ALLOW_CONFIG_INCLUDE', true);
require_once('/path/to/secure/folder/containing/config.php');

if (!isset($_POST['api_key']) || $_POST['api_key'] !== API_KEY) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

header('Content-Type: application/json');

function json_response(array $data) {
    echo json_encode($data);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'store_batch') {
    $data = isset($_POST['data']) ? $_POST['data'] : '';
    
    $avatars = explode(',', $data);
    
    $stmt = $conn->prepare("INSERT INTO avatar_visits (avatar_name, avatar_key, region_name, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE avatar_name=?, region_name=?, last_seen=?");

    if ($stmt === false) {
        die(json_encode(['error' => 'SQL statement preparation failed: ' . $conn->error]));
    }

    for ($i = 0; $i < count($avatars); $i += 5) {
        if (isset($avatars[$i], $avatars[$i + 1], $avatars[$i + 2], $avatars[$i + 3], $avatars[$i + 4])) {
            $avatar_name = $conn->real_escape_string($avatars[$i]);
            $avatar_key = $conn->real_escape_string($avatars[$i + 1]);
            $region_name = $conn->real_escape_string($avatars[$i + 2]);
            $first_seen = $conn->real_escape_string($avatars[$i + 3]);
            $last_seen = $conn->real_escape_string($avatars[$i + 4]);

            $stmt->bind_param("ssssssss", 
                $avatar_name, 
                $avatar_key, 
                $region_name, 
                $first_seen, 
                $last_seen,
                $avatar_name, 
                $region_name, 
                $last_seen
            );

            if (!$stmt->execute()) {
                echo json_encode(['error' => $stmt->error]);
            }
        }
    }

    $stmt->close();
    echo json_encode(['success' => 'Batch update completed successfully']);

} elseif ($action === 'query') {
    $avatar_key = $conn->real_escape_string($_POST['avatar_key'] ?? '');

    $stmt = $conn->prepare("SELECT avatar_name, region_name, first_seen, last_seen FROM avatar_visits WHERE avatar_key = ?");

    if ($stmt === false) {
        die(json_encode(['error' => 'SQL statement preparation failed: ' . $conn->error]));
    }

    $stmt->bind_param("s", $avatar_key);

    if ($stmt->execute()) {
        $stmt->bind_result($avatar_name, $region_name, $first_seen, $last_seen);

        if ($stmt->fetch()) {
            echo json_encode([
                'name' => $avatar_name,
                'region' => $region_name,
                'first_seen' => $first_seen,
                'last_seen' => $last_seen
            ]);
        } else {
            echo json_encode(['error' => 'No data found for the provided avatar key.']);
        }
    } else {
        echo json_encode(['error' => 'Query execution failed: ' . $stmt->error]);
    }
	} elseif ($action === 'scanner_checkin') {
    $region_name = trim($_POST['region_name'] ?? '');
    $scanner_key = trim($_POST['scanner_key'] ?? '');
    $owner_key = trim($_POST['owner_key'] ?? '');
    $object_name = trim($_POST['object_name'] ?? '');
    $timeout_seconds = (int)($_POST['timeout_seconds'] ?? 90);

    if ($region_name === '' || $scanner_key === '') {
        json_response(['error' => 'Missing region_name or scanner_key.']);
    }

    if ($timeout_seconds < 15) {
        $timeout_seconds = 15;
    }

    $conn->begin_transaction();

    try {
        $select = $conn->prepare("
            SELECT region_name, scanner_key, owner_key, object_name, started_at, last_checkin, is_active
            FROM region_scanners
            WHERE region_name = ?
            FOR UPDATE
        ");

        if ($select === false) {
            throw new Exception('SQL statement preparation failed: ' . $conn->error);
        }

        $select->bind_param("s", $region_name);
        $select->execute();
        $result = $select->get_result();
        $existing = $result->fetch_assoc();
        $select->close();

        $now = date('Y-m-d H:i:s');
        $is_active = 0;
        $reason = 'inactive';
        $active_scanner_key = null;

        if (!$existing) {
            $insert = $conn->prepare("
                INSERT INTO region_scanners
                (region_name, scanner_key, owner_key, object_name, started_at, last_checkin, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");

            if ($insert === false) {
                throw new Exception('SQL statement preparation failed: ' . $conn->error);
            }

            $insert->bind_param(
                "ssssss",
                $region_name,
                $scanner_key,
                $owner_key,
                $object_name,
                $now,
                $now
            );
            $insert->execute();
            $insert->close();

            $is_active = 1;
            $reason = 'claimed_empty_region';
            $active_scanner_key = $scanner_key;

        } else {
            $existing_last_checkin = strtotime($existing['last_checkin']);
            $is_stale = ($existing_last_checkin === false)
                ? true
                : ((time() - $existing_last_checkin) > $timeout_seconds);

            if ($existing['scanner_key'] === $scanner_key) {
                $update = $conn->prepare("
                    UPDATE region_scanners
                    SET owner_key = ?, object_name = ?, last_checkin = ?, is_active = 1
                    WHERE region_name = ? AND scanner_key = ?
                ");

                if ($update === false) {
                    throw new Exception('SQL statement preparation failed: ' . $conn->error);
                }

                $update->bind_param(
                    "sssss",
                    $owner_key,
                    $object_name,
                    $now,
                    $region_name,
                    $scanner_key
                );
                $update->execute();
                $update->close();

                $is_active = 1;
                $reason = 'renewed_own_lease';
                $active_scanner_key = $scanner_key;

            } elseif ($is_stale) {
                $takeover = $conn->prepare("
                    UPDATE region_scanners
                    SET scanner_key = ?, owner_key = ?, object_name = ?, started_at = ?, last_checkin = ?, is_active = 1
                    WHERE region_name = ?
                ");

                if ($takeover === false) {
                    throw new Exception('SQL statement preparation failed: ' . $conn->error);
                }

                $takeover->bind_param(
                    "ssssss",
                    $scanner_key,
                    $owner_key,
                    $object_name,
                    $now,
                    $now,
                    $region_name
                );
                $takeover->execute();
                $takeover->close();

                $is_active = 1;
                $reason = 'took_over_stale_scanner';
                $active_scanner_key = $scanner_key;

            } else {
                $is_active = 0;
                $reason = 'another_scanner_active';
                $active_scanner_key = $existing['scanner_key'];
            }
        }

        $conn->commit();

        json_response([
            'success' => true,
            'region_name' => $region_name,
            'scanner_key' => $scanner_key,
            'is_active' => $is_active,
            'reason' => $reason,
            'active_scanner_key' => $active_scanner_key,
            'server_time' => $now
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        json_response(['error' => $e->getMessage()]);
    }

} elseif ($action === 'scanner_release') {
    $region_name = trim($_POST['region_name'] ?? '');
    $scanner_key = trim($_POST['scanner_key'] ?? '');

    if ($region_name === '' || $scanner_key === '') {
        json_response(['error' => 'Missing region_name or scanner_key.']);
    }

    $stmt = $conn->prepare("
        DELETE FROM region_scanners
        WHERE region_name = ? AND scanner_key = ?
    ");

    if ($stmt === false) {
        json_response(['error' => 'SQL statement preparation failed: ' . $conn->error]);
    }

    $stmt->bind_param("ss", $region_name, $scanner_key);

    if ($stmt->execute()) {
        json_response([
            'success' => true,
            'released' => ($stmt->affected_rows > 0) ? 1 : 0,
            'region_name' => $region_name,
            'scanner_key' => $scanner_key
        ]);
    } else {
        json_response(['error' => 'Release failed: ' . $stmt->error]);
    }

    $stmt->close();

} else {
    echo json_encode(['error' => 'Invalid action specified.']);
}

$conn->close();
?>
