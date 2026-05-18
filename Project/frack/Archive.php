<?php
session_start();

$con = new mysqli("localhost", "root", "", "dbView");
if ($con->connect_error) {
    die("Connection Failed: " . $con->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $username = $_POST['username'] ?? '';

    if ($action === 'unArchive' && $username) {
        $stmt = $con->prepare(
            "UPDATE test_admin SET Status='Active' WHERE Username=?"
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();   
        $stmt->close();   
        
        $activity = "'$username' Has Been Unarchived";


        $audit = $con->prepare("INSERT INTO tbl_audit 
                                (User, Role, Module, Activity, Status) 
                                VALUES (?, ?, 'Archives', ?, 'Success')");
        
        $currentUser = $_SESSION['Username'];
        $currentRole = $_SESSION['Role'];
        
        $audit->bind_param("sss", $currentUser, $currentRole, $activity);
        $audit->execute();
        $audit->close();        

        header("Location: ../frack/Archive.php");
        exit;
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Panel</title>
    <link rel="stylesheet" href="../styles/archive.css">
</head>
<body>
    <div class="container">
        <h1>Archive Panel</h1>

<form method="POST">
        <div class="archive-table">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
						<th>Admin</th>
                        <th>Power User</th>
                        <th>Blocked</th>
                    </tr>
                </thead>
                <tbody id="userTable">
                    <?php 
                        $con = new mysqli("localhost", "root", "", "dbView");

                        if($con -> connect_error){
                            die("Connection Failed: " + $con->connect_error);
                        }

                        $sql = "SELECT * FROM test_admin WHERE Status='Archived'";
    
                        $results = $con->query($sql);

                        if($results->num_rows > 0){
                            while ($row = $results->fetch_assoc()){
                                echo "<tr onclick=\"selectUser('{$row['Username']}')\">";
                                echo "<td>{$row['Username']}</td>";
                                echo "<td>" . ($row['Admin'] ? "Yes" : "No") . "</td>";
                                echo "<td>" . ($row['PowerUser'] ? "Yes" : "No") . "</td>";
                                echo "<td>" . ($row['Blocked'] ? "Yes" : "No") . "</td>";
                                echo "</tr>";
                            }
                        } else{
                            echo "<tr><td colspan='5'>No users found</td></tr>";
                        }

                        
                    ?>
                </tbody>
            </table>

        </div>

        <div class="users-controls">
            <div class="search-sort">
                <input type="text" id="searchInput" placeholder="Search username...">
                <button type="button" onclick="sortUsername()" id="sortBtn" class="SortUser">Sort Username ▲</button>
            </div>
            <div class="user-actions">
                <input type="text" id="selectedUser" placeholder="Selected user" readonly>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="unArchive">
                    <input type="hidden" name="username" id="hiddenUsername">
                    <button type="submit" class="unArchive" onclick="return confirmUnarchive()">UnArchive</button>
                </form>
                <button type="button" onclick="window.location.href='Admin.php'" class="admin">← Admin Panel</button>
            </div>
        </div>

            <script>
                let asc = true;

                function sortUsername() {
                    const table = document.getElementById("userTable");
                    const rows = Array.from(table.rows);

                    rows.sort((a, b) => {
                        const x = a.cells[0].innerText.toLowerCase();
                        const y = b.cells[0].innerText.toLowerCase();

                        return asc ? x.localeCompare(y) : y.localeCompare(x);
                    });

                    rows.forEach(row => table.appendChild(row));

                    asc = !asc;

                    document.getElementById("sortBtn").innerText =
                        asc ? "Sort Username ▲" : "Sort Username ▼";
                }
                </script>

    <script src="../script/Archive.js"></script>

</body>
</html>