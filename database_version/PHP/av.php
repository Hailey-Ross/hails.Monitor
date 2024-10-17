<?php
// Load database credentials from an external configuration file
// MAKE SURE TO SECURE THIS DIRECTORY
require_once __DIR__ . '/priv/config.php';

// Log incoming requests (for debugging purposes)
file_put_contents('request_log.txt', print_r($_POST, true), FILE_APPEND);

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

// Determine the action (store or query)
$action = $_POST['action'] ?? '';

// Handle avatar data storage
if ($action === 'store') {
    // Retrieve and sanitize input data
    $avatar_name = $conn->real_escape_string($_POST['avatar_name'] ?? '');
    $avatar_key = $conn->real_escape_string($_POST['avatar_key'] ?? '');
    $region_name = $conn->real_escape_string($_POST['region_name'] ?? '');
    $first_seen = $conn->real_escape_string($_POST['first_seen'] ?? '');
    $last_seen = $conn->real_escape_string($_POST['last_seen'] ?? '');

    // Log the data (optional, for debugging)
    file_put_contents('data_log.txt', "Storing: $avatar_name, $avatar_key, $region_name, $first_seen, $last_seen\n", FILE_APPEND);

    // Prepare SQL statement to insert or update avatar data
    $stmt = $conn->prepare("INSERT INTO avatar_visits (avatar_name, avatar_key, region_name, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE last_seen=?");
    
    if ($stmt === false) {
        die(json_encode(['error' => 'SQL statement preparation failed: ' . $conn->error]));
    }

    $stmt->bind_param("ssssss", $avatar_name, $avatar_key, $region_name, $first_seen, $last_seen, $last_seen);

    // Execute the statement and handle errors
    if ($stmt->execute()) {
        echo json_encode(['success' => 'Record updated successfully']);
    } else {
        echo json_encode(['error' => $stmt->error]);
    }

    // Close the statement
    $stmt->close();

// Handle avatar data querying
} elseif ($action === 'query') {
    $avatar_key = $conn->real_escape_string($_POST['avatar_key'] ?? '');

    // Prepare SQL statement to query avatar data
    $stmt = $conn->prepare("SELECT avatar_name, region_name, first_seen, last_seen FROM avatar_visits WHERE avatar_key = ?");
    
    if ($stmt === false) {
        die(json_encode(['error' => 'SQL statement preparation failed: ' . $conn->error]));
    }

    $stmt->bind_param("s", $avatar_key);

    // Execute the query and bind the result
    if ($stmt->execute()) {
        $stmt->bind_result($avatar_name, $region_name, $first_seen, $last_seen);

        // Fetch the result and return it as JSON
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

    // Close the statement
    $stmt->close();

// If no valid action is provided
} else {
    echo json_encode(['error' => 'Invalid action specified.']);
}

// Close the connection
$conn->close();
?>
