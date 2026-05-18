<?php
$conn = new mysqli("localhost", "root", "", "dbView");
    if ($conn->connect_error) {
        die("Connection Failed: " . $conn->connect_error);
    }

    $search = $_GET['search'] ?? '';
    $like = "%$search%";

    
    $sql = "
        SELECT * FROM test_admin
        WHERE Status = 'Active'
        AND Username LIKE ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<tr onclick=\"selectUser('{$row['Username']}')\">";
            echo "<td>{$row['Username']}</td>";
            /* echo "<td>{$row['Password']}</td>"; */
            echo "<td>" . ($row['Admin'] ? "Yes" : "No") . "</td>";
            echo "<td>" . ($row['PowerUser'] ? "Yes" : "No") . "</td>";
            echo "<td>" . ($row['Blocked'] ? "Yes" : "No") . "</td>";
            echo "<td>{$row['Status']}</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5'>No active users found</td></tr>";
    }

    $conn->close();
?>