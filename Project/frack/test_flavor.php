<?php
/**
 * AUDIT LOG CONTROLLER
 * Handles systematic logging of user activities across all modules.
 */

// 1. Environment & Headers
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable in production for security
header('Content-Type: application/json');
session_start();

// 2. Helper: Standardized JSON Response
function terminate($success, $message, $extra = []) {
    echo json_encode(array_merge([
        'status'  => $success ? 'success' : 'error',
        'message' => $message,
        'ts'      => date('Y-m-d H:i:s')
    ], $extra));
    exit;
}

// 3. Security Check: Authenticated Access Only
if (!isset($_SESSION['Username'], $_SESSION['Role'])) {
    terminate(false, 'Unauthorized access. Please re-log.');
}

// 4. Input Validation
$action = $_POST['action'] ?? null;
$module = $_POST['module'] ?? 'Flavor Management'; // Default if not specified

if (!$action) {
    terminate(false, 'Action parameter is missing.');
}

// 5. Database Connection
$db_config = ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'dbView'];
$conn = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['db']);

if ($conn->connect_error) {
    terminate(false, 'Database connection failed.');
}

// 6. Action Mapping (Centralized Activity Descriptions)
$activities = [
    'add'     => "New entry created in $module",
    'disable' => "Item status set to 'Disabled' in $module",
    'price'   => "Unit price updated in $module",
    'delete'  => "Record removed from $module",
    'update'  => "Information modified in $module"
];

$current_activity = $activities[$action] ?? "Undefined action ($action) performed in $module";

// 7. Prepared Execution
try {
    $sql = "INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    $user   = $_SESSION['Username'];
    $role   = $_SESSION['Role'];
    $status = "Success";

    $stmt->bind_param("sssss", $user, $role, $module, $current_activity, $status);

    if ($stmt->execute()) {
        terminate(true, "Activity logged: $current_activity", ['id' => $stmt->insert_id]);
    } else {
        throw new Exception($stmt->error);
    }

} catch (Exception $e) {
    // Log error to a local file for the dev, don't show raw SQL to the user
    error_log("Audit Error: " . $e->getMessage());
    terminate(false, 'Failed to write audit log.');
} finally {
    $stmt->close();
    $conn->close();
}