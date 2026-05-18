<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: ../frack/LOGINM.php");
    exit;
}

$con = new mysqli("localhost", "root", "", "dbView");
if ($con->connect_error) {
    die("Connection Failed: " . $con->connect_error);
}

$currentUser = $_SESSION['Username'];
$sql  = "SELECT * FROM tbl_audit WHERE User = ? ORDER BY Date_Time DESC";
$stmt = $con->prepare($sql);
$stmt->bind_param("s", $currentUser);
$stmt->execute();
$results = $stmt->get_result();

$homepage = ($_SESSION['Role'] === 'Owner') ? '../frack/Dashboard.php' : '../frack/Dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" href="../styles/Logbook.css">
</head>

<body>
    <div class="container">
        <h1 class="log">Log Book</h1>

        <div class="table-container">
            <table id="Sorting">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Module</th>
                        <th>Activity</th>
                        <th>Status</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody id="table_log">
                    <?php
                        $con2        = new mysqli("localhost", "root", "", "dbView");
                        $currentUser = $_SESSION['Username'];
                        $currentRole = $_SESSION['Role'];

                        if ($currentRole === 'Admin' || $currentRole === 'Owner') {
                            $sql2  = "SELECT * FROM tbl_audit ORDER BY Date_Time DESC";
                            $stmt2 = $con2->prepare($sql2);
                        } else {
                            $sql2  = "SELECT * FROM tbl_audit WHERE User = ? ORDER BY Date_Time DESC";
                            $stmt2 = $con2->prepare($sql2);
                            $stmt2->bind_param("s", $currentUser);
                        }

                        $stmt2->execute();
                        $results2 = $stmt2->get_result();

                        if ($results2->num_rows > 0) {
                            while ($row = $results2->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['User'])      . "</td>";
                                echo "<td>" . htmlspecialchars($row['Role'])      . "</td>";
                                echo "<td>" . htmlspecialchars($row['Module'])    . "</td>";
                                echo "<td>" . htmlspecialchars($row['Activity'])  . "</td>";
                                echo "<td>" . htmlspecialchars($row['Status'])    . "</td>";
                                echo "<td>" . htmlspecialchars($row['Date_Time']) . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6'>No Activities Found</td></tr>";
                        }
                    ?>
                </tbody>
            </table>
        </div>

        <button id="Ret_Admin" onclick="window.location.href='<?= $homepage ?>'">Return Home</button>

        <input type="text" id="searchInput" placeholder="Search Username..." onkeyup="updateTable()">

        
        <button type="button" onclick="toggleSortMenu(event)" id="sortBtn" class="SortUser">Sort ▼</button>

        <div id="sortMenu" style="display:none; position:fixed; background:#EEEEEE; border:2px solid #333; border-radius:6px; box-shadow:0 4px 0 #888; z-index:9999; min-width:200px;">
            <div class="sort-group-label">— Sort —</div>
            <div class="sort-opt" onclick="sortBy(0, 'username')">By Username</div>
            <div class="sort-opt" onclick="sortBy(1, 'role')">By Role</div>
            <div class="sort-opt" onclick="sortBy(2, 'module')">By Module</div>
            <div class="sort-opt" onclick="sortBy(4, 'status')">By Status</div>
            <div class="sort-opt" onclick="sortBy(5, 'datetime')">By Date &amp; Time (Newest)</div>
            <div class="sort-opt" onclick="sortBy(5, 'datetimeOld')">By Date &amp; Time (Oldest)</div>
            <div class="sort-group-label">— Filter by Role —</div>
            <div class="sort-opt" onclick="filterByCol(1, 'Owner')">Owner only</div>
            <div class="sort-opt" onclick="filterByCol(1, 'Manager')">Manager only</div>
            <div class="sort-opt" onclick="filterByCol(1, 'Staff')">Staff only</div>
            <div class="sort-group-label">— Filter by Module —</div>
            <div class="sort-opt" onclick="filterByCol(2, 'Login')">Login</div>
            <div class="sort-opt" onclick="filterByCol(2, 'Stock Management')">Stock Management</div>
            <div class="sort-opt" onclick="filterByCol(2, 'Admin Panel')">Admin Panel</div>
            <div class="sort-opt" onclick="filterByCol(2, 'User Management')">User Management</div>
            <div class="sort-group-label">— Filter by Status —</div>
            <div class="sort-opt" onclick="filterByCol(4, 'Success')">Success</div>
            <div class="sort-opt" onclick="filterByCol(4, 'Failed')">Failed</div>
            <div class="sort-opt sort-opt-clear" onclick="clearFilter()">✕ Clear Filter</div>
        </div>

        <style>
            .sort-group-label {
                padding: 6px 14px 4px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: #888;
                background: #e4e4e4;
                border-bottom: 1px solid #ccc;
            }
            .sort-opt {
                padding: 9px 16px;
                cursor: pointer;
                font-size: 14px;
                color: #333;
                border-bottom: 1px solid #ddd;
                font-family: inherit;
            }
            .sort-opt:last-child { border-bottom: none; }
            .sort-opt:hover { background: #d4d4cc; }
            .sort-opt-clear { color: #c0392b; font-weight: 600; }
            .sort-opt-clear:hover { background: #fde8e8; }
        </style>

        <script>
            const sortState  = {};
            const allRows    = () => Array.from(document.getElementById('table_log').rows);

            
            function toggleSortMenu(e) {
                const menu = document.getElementById('sortMenu');
                const btn  = document.getElementById('sortBtn');
                const rect = btn.getBoundingClientRect();
                menu.style.top  = (rect.bottom + 4) + 'px';
                menu.style.left = rect.left + 'px';
                menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
                e.stopPropagation();
            }
            document.addEventListener('click', () => {
                document.getElementById('sortMenu').style.display = 'none';
            });
            document.getElementById('sortMenu').addEventListener('click', e => e.stopPropagation());

           
            function sortBy(col, key) {
                const tbody = document.getElementById('table_log');
                const rows  = allRows();
                sortState[key] = !sortState[key];
                const asc = sortState[key];

                rows.sort((a, b) => {
                    const x = a.cells[col].innerText.toLowerCase();
                    const y = b.cells[col].innerText.toLowerCase();

                    
                    if (key === 'datetime' || key === 'datetimeOld') {
                        const dx = new Date(a.cells[col].innerText);
                        const dy = new Date(b.cells[col].innerText);
                        return key === 'datetime' ? dy - dx : dx - dy;
                    }
                    return asc ? x.localeCompare(y) : y.localeCompare(x);
                });

                rows.forEach(row => tbody.appendChild(row));

                const labels = {
                    username:    'Username',
                    role:        'Role',
                    module:      'Module',
                    status:      'Status',
                    datetime:    'Date (Newest)',
                    datetimeOld: 'Date (Oldest)',
                };
                document.getElementById('sortBtn').innerText = 'Sort: ' + labels[key] + (asc ? ' ▲' : ' ▼');
                document.getElementById('sortMenu').style.display = 'none';
            }

            
            function filterByCol(col, value) {
                allRows().forEach(row => {
                    const cell = row.cells[col]?.innerText || '';
                    row.style.display = cell.toLowerCase() === value.toLowerCase() ? '' : 'none';
                });
                document.getElementById('sortBtn').innerText = 'Filter: ' + value + ' ✕';
                document.getElementById('sortMenu').style.display = 'none';
            }

           
            function clearFilter() {
                allRows().forEach(row => row.style.display = '');
                document.getElementById('sortBtn').innerText = 'Sort ▼';
                document.getElementById('sortMenu').style.display = 'none';
            }

            
            function updateTable() {
                const filter = document.getElementById('searchInput').value.toLowerCase();
                allRows().forEach(row => {
                    const cell = row.cells[0];
                    if (cell) {
                        const text = (cell.textContent || cell.innerText).toLowerCase();
                        row.style.display = text.includes(filter) ? '' : 'none';
                    }
                });
            }
        </script>

    <script src="../script/Admin.js"></script>
</body>
</html>