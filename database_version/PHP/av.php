<?php
// Load database credentials from an external configuration file
require_once __DIR__ . '/priv/config.php';

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

// Determine the action (store_batch or query)
$action = $_POST['action'] ?? '';

// Handle avatar data storage in batches
if ($action === 'store_batch') {
    // Retrieve and sanitize input data
    $data = isset($_POST['data']) ? $_POST['data'] : '';
    
    // Convert the incoming data into an array
    $avatars = explode(',', $data);
    
    // Prepare the SQL statement to insert or update avatar data
    $stmt = $conn->prepare("INSERT INTO avatar_visits (avatar_name, avatar_key, region_name, first_seen, last_seen) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE avatar_name=?, region_name=?, last_seen=?");

    if ($stmt === false) {
        die(json_encode(['error' => 'SQL statement preparation failed: ' . $conn->error]));
    }

    // Process each avatar entry
    for ($i = 0; $i < count($avatars); $i += 5) {
        // Ensure enough elements are present to process
        if (isset($avatars[$i], $avatars[$i + 1], $avatars[$i + 2], $avatars[$i + 3], $avatars[$i + 4])) {
            $avatar_name = $conn->real_escape_string($avatars[$i]);
            $avatar_key = $conn->real_escape_string($avatars[$i + 1]);
            $region_name = $conn->real_escape_string($avatars[$i + 2]);
            $first_seen = $conn->real_escape_string($avatars[$i + 3]);
            $last_seen = $conn->real_escape_string($avatars[$i + 4]);

            // Bind parameters to the statement
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

            // Execute the statement and handle errors
            if (!$stmt->execute()) {
                echo json_encode(['error' => $stmt->error]);
            }
        }
    }

    $stmt->close();
    echo json_encode(['success' => 'Batch update completed successfully']);

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

    $stmt->close();

// If no valid action is provided
} else {
    echo json_encode(['error' => 'Invalid action specified.']);
}

// Close the connection
$conn->close();
?>
