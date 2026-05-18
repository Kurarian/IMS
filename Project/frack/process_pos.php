<?php
header('Content-Type: application/json');
session_start();

// Database Connection
$conn = new mysqli('127.0.0.1', 'root', '', 'dbview');

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
    exit;
}

// Get the JSON data from the JavaScript fetch
$input = file_get_contents('php://input');
$cart = json_decode($input, true);

if (!$cart || empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'No items in cart']);
    exit;
}

$conn->begin_transaction();

try {
    foreach ($cart as $item) {
        $name = $conn->real_escape_string($item['name']);
        $qty = (int)$item['qty'];

        // Update the product: Decrease stock, Increase sold_quantity
        $sql = "UPDATE tbl_products 
                SET stock_quantity = stock_quantity - $qty, 
                    sold_quantity = sold_quantity + $qty 
                WHERE product_name = '$name'";
        
        if (!$conn->query($sql)) {
            throw new Exception("Failed to update product: " . $name);
        }
    }

    // Optional: Add to Audit Log
    $user = $_SESSION['Username'] ?? 'System';
    $auditSql = "INSERT INTO tbl_audit (User, Role, Module, Activity, Status) 
                 VALUES ('$user', 'Staff', 'POS', 'Completed a sale', 'Success')";
    $conn->query($auditSql);

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>