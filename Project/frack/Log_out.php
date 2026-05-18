<?php
session_start();

$conn = new mysqli("localhost", "root", "", "dbView");
if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}


$currentUser = $_SESSION['Username'] ?? 'Unknown';
$currentRole = $_SESSION['Role'] ?? 'Unknown';


$activity = "User '$currentUser' has logged out";


$audit = $conn->prepare("
    INSERT INTO tbl_audit 
    (User, Role, Module, Activity, Status, Date_Time) 
    VALUES (?, ?, 'Authentication', ?, 'Success', NOW())
");

$audit->bind_param("sss", $currentUser, $currentRole, $activity);
$audit->execute();
$audit->close();


$_SESSION = [];
session_unset();
session_destroy();


header("Location: ../frack/LOGIN.php");
exit;
?>