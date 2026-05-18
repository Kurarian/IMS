<?php
$con = new mysqli("localhost", "root", "", "dbView");

$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM test_admin WHERE Status='Archived'";
if ($search) {
    $sql .= " AND Username LIKE ?";
    $stmt = $con->prepare($sql);
    $like = "%$search%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $con->query($sql);
}

while ($row = $result->fetch_assoc()) {
    echo "<tr onclick=\"selectUser('{$row['Username']}')\">";
    echo "<td>{$row['Username']}</td>";
    echo "<td>" . ($row['Admin'] ? "Yes" : "No") . "</td>";
    echo "<td>" . ($row['PowerUser'] ? "Yes" : "No") . "</td>";
    echo "<td>" . ($row['Blocked'] ? "Yes" : "No") . "</td>";
    echo "</tr>";
}