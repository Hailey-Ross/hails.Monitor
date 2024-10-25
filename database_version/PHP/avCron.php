<?php
// Load database credentials from an external configuration file
require_once('/usr/path/to/priv/config.php');

// Check for API key
if (!isset($_POST['api_key']) || $_POST['api_key'] !== API_KEY) {
    die(json_encode(['error' => 'Unauthorized access']));
}

// Create a connection to the database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check for connection errors
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// File to write debug output
$debug_file = '/usr/path/to/debug_output.txt';

// Create the debug file if it doesn't exist
if (!file_exists($debug_file)) {
    file_put_contents($debug_file, "Debug output file created.\n", FILE_APPEND);
}

// Log the entire POST data received
file_put_contents($debug_file, "Full POST data received: " . json_encode($_POST) . "\n", FILE_APPEND);

// Determine the action (check_empty_names or update_avatar_name)
$action = $_POST['action'] ?? '';
file_put_contents($debug_file, "Action received: " . $action . "\n", FILE_APPEND);

// Handle checking for empty avatar names
if ($action === 'check_empty_names') {
    // Set limit to prevent large responses; default to 5 if not provided
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 5;
    
    $stmt = $conn->prepare("SELECT avatar_key FROM avatar_visits WHERE avatar_name = '' LIMIT ?");
    $stmt->bind_param("i", $limit);

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $empty_names = [];

        while ($row = $result->fetch_assoc()) {
            $empty_names[] = $row['avatar_key'];
        }

        // Log the keys being retrieved
        if (empty($empty_names)) {
            file_put_contents($debug_file, "No empty avatar names found.\n", FILE_APPEND);
        } else {
            file_put_contents($debug_file, "Empty avatar keys retrieved: " . implode(", ", $empty_names) . "\n", FILE_APPEND);
        }

        echo json_encode(['empty_avatar_keys' => $empty_names]);
    } else {
        file_put_contents($debug_file, "Query execution failed: " . $stmt->error . "\n", FILE_APPEND);
        echo json_encode(['error' => 'Query execution failed: ' . $stmt->error]);
    }

    $stmt->close();

// Handle updating avatar names
} elseif ($action === 'update_avatar_name') {
    $avatar_key = $conn->real_escape_string($_POST['avatar_key'] ?? '');
    $avatar_name = $conn->real_escape_string($_POST['avatar_name'] ?? '');

    // Log the incoming data for debugging
    file_put_contents($debug_file, "Attempting update for avatar_key: $avatar_key with avatar_name: $avatar_name\n", FILE_APPEND);

    $stmt = $conn->prepare("UPDATE avatar_visits SET avatar_name = ? WHERE avatar_key = ?");
    $stmt->bind_param("ss", $avatar_name, $avatar_key);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            file_put_contents($debug_file, "Successfully updated avatar name for key: $avatar_key\n", FILE_APPEND);
            echo json_encode(['success' => 'Avatar name updated successfully']);
        } else {
            file_put_contents($debug_file, "No matching entry found for avatar_key: $avatar_key. Update aborted.\n", FILE_APPEND);
            echo json_encode(['error' => 'No rows affected for key: ' . $avatar_key]);
        }
    } else {
        file_put_contents($debug_file, "Update failed for key: $avatar_key - Error: " . $stmt->error . "\n", FILE_APPEND);
        echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
    }

    $stmt->close();
} else {
    file_put_contents($debug_file, "Invalid action specified.\n", FILE_APPEND);
    echo json_encode(['error' => 'Invalid action specified.']);
}

// Close the connection
$conn->close();
?>
