<?php
session_start();
$conn = new mysqli("localhost", "root", "", "dbView");

// Get the actual field names from the form
$new = $_POST['new_pass'] ?? '';
$confirm = $_POST['con_pass'] ?? '';

// Check if passwords match
if ($new !== $confirm) {
    $_SESSION['error'] = "Password did not match";
    header("Location: ../frack/dashboard.php");
    exit;
}

// Prevent empty password (optional but recommended)
if (empty($new)) {
    $_SESSION['error'] = "Password cannot be empty";
    header("Location: ../frack/dashboard.php");
    exit;
}

$hash = password_hash($new, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    UPDATE test_admin 
    SET Password = ?, ForceChange = 0 
    WHERE Username = ?
");
$stmt->bind_param("ss", $hash, $_SESSION['Username']);
$stmt->execute();

$_SESSION['ForceChange'] = 0;

header("Location: ../frack/dashboard.php");
exit;
?>