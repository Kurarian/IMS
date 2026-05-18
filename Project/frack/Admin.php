<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../frack/LOGINMAIN1.php");
    exit;
}

$conn = new mysqli("localhost", "root", "", "dbView");
if ($conn->connect_error) {
    die("Connection Failed: " . $conn->connect_error);
}

$action = $_POST['action'] ?? '';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';


if ($action === 'add') {

    if (!empty($username) && !empty($password)) {

        
        $check = $conn->prepare("SELECT Username FROM test_admin WHERE Username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows > 0) {
        echo "<script>alert('Error: Username already exists!'); window.history.back();</script>";
        exit;
}

       
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO test_admin 
            (Username, Password, Admin, PowerUser, Blocked, Status, ForceChange) 
            VALUES (?, ?, 0, 0, 0, 'Active', 1)
        ");
        $stmt->bind_param("ss", $username, $hashedPassword);
        $stmt->execute();

        if ($stmt->error) {
    echo "<div class='popup error'>Insert Error: " . $stmt->error . "</div>";
} else {
    echo "<div class='popup success'>'$username' added successfully!</div>";

            $activity = "New account '$username' has been created";

            $audit = $conn->prepare("
                INSERT INTO tbl_audit 
                (User, Role, Module, Activity, Status, Date_Time) 
                VALUES (?, ?, 'User Management', ?, 'Success', NOW())
            ");

            $currentUser = $_SESSION['Username'];
            $currentRole = $_SESSION['Role'];

            $audit->bind_param("sss", $currentUser, $currentRole, $activity);
            $audit->execute();
            $audit->close();
}
    }
}


if ($action === 'admin') {
    $stmt = $conn->prepare("UPDATE test_admin SET PowerUser = 0, Admin = 1 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();


    $activity = "Promoted user '$username' to Admin";


    $audit = $conn->prepare("INSERT INTO tbl_audit 
                             (User, Role, Module, Activity, Status) 
                             VALUES (?, ?, 'Admin Panel', ?, 'Success')");
    
    $currentUser = $_SESSION['Username'];
    $currentRole = $_SESSION['Role'];
    
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute();
    $audit->close();
}


if ($action === 'unAdmin') {
    $stmt = $conn->prepare("UPDATE test_admin SET Admin=0 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $activity = "Demoted user '$username' from the Admin Role";


    $audit = $conn->prepare("INSERT INTO tbl_audit 
                             (User, Role, Module, Activity, Status) 
                             VALUES (?, ?, 'Admin Panel', ?, 'Success')");
    
    $currentUser = $_SESSION['Username'];
    $currentRole = $_SESSION['Role'];
    
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute();
    $audit->close();
}


if ($action === 'Power') {
    $stmt = $conn->prepare("UPDATE test_admin SET PowerUser = 1, Admin = 0 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
}


if ($action === 'UnPower') {
    $stmt = $conn->prepare("UPDATE test_admin SET PowerUser = 0 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
}


if ($action === 'Deactivate') {
    $stmt = $conn->prepare("UPDATE test_admin SET Status='Archived' WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $activity = "Deactivated the account '$username'";


    $audit = $conn->prepare("INSERT INTO tbl_audit 
                             (User, Role, Module, Activity, Status) 
                             VALUES (?, ?, 'Admin Panel', ?, 'Success')");
    
    $currentUser = $_SESSION['Username'];
    $currentRole = $_SESSION['Role'];
    
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute();
    $audit->close();    
}


if ($action === 'block') {
    $stmt = $conn->prepare("UPDATE test_admin SET Blocked=1 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $activity = "Blocked the account '$username'";


    $audit = $conn->prepare("INSERT INTO tbl_audit 
                             (User, Role, Module, Activity, Status) 
                             VALUES (?, ?, 'Admin Panel', ?, 'Success')");
    
    $currentUser = $_SESSION['Username'];
    $currentRole = $_SESSION['Role'];
    
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute();
    $audit->close();      
}


if ($action === 'unblock') {
    $stmt = $conn->prepare("UPDATE test_admin SET Blocked=0 WHERE Username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $activity = "Unblocked the account '$username'";


    $audit = $conn->prepare("INSERT INTO tbl_audit 
                             (User, Role, Module, Activity, Status) 
                             VALUES (?, ?, 'Admin Panel', ?, 'Success')");
    
    $currentUser = $_SESSION['Username'];
    $currentRole = $_SESSION['Role'];
    
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute();
    $audit->close();    
}

?>

<script>
const popup = document.querySelector('.popup');

if (popup) {
    popup.style.opacity = 0;
    popup.style.transition = 'opacity 1s ease';
    setTimeout(() => {
        popup.style.opacity = 1;
    }, 10);
    setTimeout(() => {
        popup.style.opacity = 0;
        popup.addEventListener('transitionend', () => {
            popup.remove();
        });
    }, 3000);
}
</script>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="../styles/admin.css">
</head>
<body>
    <div class="container">
        <h1 class="admin">Admin Panel</h1>

<form method="POST">
        <div class="table-container">
            <table id="Sorting">
                <thead>
                    <tr>
                        <th>Username</th>
                        <!-- <th>Password</th> -->
                        <th>Admin</th>
                        <th>Power User</th>
                        <th>Blocked</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="userTable">

                    <?php 
                        
                        $conn = new mysqli("localhost", "root", "", "dbView");

                        if($conn -> connect_error){
                            die("Connection Failed: " + $conn->connect_error);
                        }

                        $sql = "SELECT * FROM test_admin WHERE Status!='Archived'";
    
                        $result = $conn->query($sql);

                        if($result->num_rows > 0){
                            while ($row = $result->fetch_assoc()){
                                echo "<tr onclick=\"selectUser('{$row['Username']}')\">";
                                echo "<td>{$row['Username']}</td>";
                                
                                echo "<td>" . ($row['Admin'] ? "Yes" : "No") . "</td>";
                                echo "<td>" . ($row['PowerUser'] ? "Yes" : "No") . "</td>";
                                echo "<td>" . ($row['Blocked'] ? "Yes" : "No") . "</td>";
                                echo "<td>{$row['Status']}</td>";
                                echo "</tr>";
                            }
                        } else{
                            echo "<tr><td colspan='5'>No users found</td></tr>";
                        }
                        $conn->close();
                    ?>
                </tbody>
            </table>
        </div>
        
            <div class="actions">
                <div class="input_group">
                    <input type="text" id="selectedUser" placeholder="Enter Username" name="username">
                    <input type="text" id="passwordInput" placeholder="Enter Password" name="password">
                    <input type="text" id="searchInput" placeholder="Search Username..." onkeyup="updateTable()">
                </div>

                <div class="buttons">
                    <button class="btn_add" type="submit" name="action" value="add" onclick="addUser()">Add</button>
                    <button class="btn_admin" type="submit" name="action" value="admin">Add as an Admin</button>
                    <button class="btn_unAdmin" type="submit" name="action" value="unAdmin">Remove as Admin</button>
                    <button class="btn_Archive" type="submit" name="action" value="Deactivate">Deactivate</button>
                    <button class="btn_block" type="submit" name="action" value="block">Block</button>
                    <button class="btn_unblock" type="submit" name="action" value="unblock">Unblock</button>
                    <button class="btn_Power" type="submit" name="action" value="Power">Add as Power User</button>
                    <button class="btn_UnPower" type="submit" name="action" value="UnPower">Remove as Power User</button>

                
                    <button type="button" onclick="window.location.href='../frack/Archive.php'" id="panel_Ret">
                        &laquo; Archive Panel
                    </button>
                </div>
            </div>
    </div>
</form>

            <button type="button" onclick="toggleSortMenu(event)" id="sortBtn" class="SortUser">
                Sort ▼
            </button>

            <div id="sortMenu" style="display:none; position:fixed; background:#EEEEEE; border:2px solid #333; border-radius:6px; box-shadow:0 4px 0 #888; z-index:9999; min-width:190px;">
                <div class="sort-opt" onclick="sortBy(0, 'username')">Sort by Username</div>
                <div class="sort-opt" onclick="sortBy(1, 'admin')">Sort by Admin</div>
                <div class="sort-opt" onclick="sortBy(2, 'power')">Sort by Power User</div>
                <div class="sort-opt" onclick="sortBy(3, 'blocked')">Sort by Blocked</div>
                <div class="sort-opt" onclick="sortBy(4, 'status')">Sort by Status</div>
            </div>

            <style>
                .sort-opt {
                    padding: 10px 16px;
                    cursor: pointer;
                    font-size: 14px;
                    color: #333;
                    border-bottom: 1px solid #ccc;
                    font-family: inherit;
                }
                .sort-opt:last-child { border-bottom: none; }
                .sort-opt:hover { background: #d4d4cc; }
            </style>

            <script>
                const sortState = {};

                function toggleSortMenu(e) {
                    const menu = document.getElementById('sortMenu');
                    const btn  = document.getElementById('sortBtn');
                    const rect = btn.getBoundingClientRect();
                    menu.style.top  = (rect.bottom + 4) + 'px';
                    menu.style.left = rect.left + 'px';
                    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
                    e.stopPropagation();
                }

                document.addEventListener('click', function () {
                    document.getElementById('sortMenu').style.display = 'none';
                });

                document.getElementById('sortMenu').addEventListener('click', function (e) {
                    e.stopPropagation();
                });

                function sortBy(col, key) {
                    const table = document.getElementById('userTable');
                    const rows  = Array.from(table.rows);
                    sortState[key] = !sortState[key];
                    const asc = sortState[key];

                    rows.sort((a, b) => {
                        const x = a.cells[col].innerText.toLowerCase();
                        const y = b.cells[col].innerText.toLowerCase();
                        if (x === 'yes' || x === 'no') {
                            return asc ? (x === 'yes' ? -1 : 1) : (x === 'yes' ? 1 : -1);
                        }
                        return asc ? x.localeCompare(y) : y.localeCompare(x);
                    });

                    rows.forEach(row => table.appendChild(row));

                    const labels = {
                        username: 'Username', admin: 'Admin',
                        power: 'Power User', blocked: 'Blocked', status: 'Status'
                    };
                    document.getElementById('sortBtn').innerText =
                        'Sort: ' + labels[key] + (asc ? ' ▲' : ' ▼');

                    document.getElementById('sortMenu').style.display = 'none';
                }
            </script>


<form action="../frack/Dashboard.php" method="post">
    <button type="submit" id="logout">Return</button>
</form>

    <script src="../script/Admin.js"></script>
    
</body>
</html>