<?php
session_set_cookie_params(0, '/');
session_start();

if (!isset($_SESSION['attempts'])) {
    $_SESSION['attempts'] = 0;
}

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if ($_SESSION['Admin'] == 1) {
        header("Location: Dashboard.php");
    } elseif ($_SESSION['PowerUser'] == 1) {
        header("Location: Dashboard.php");
    } else {
        header("Location: Dashboard.php");
    }
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $conn = new mysqli("localhost", "root", "", "dbView");

    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $username = trim($_POST['Username'] ?? '');
    $password = trim($_POST['Password'] ?? '');

    if (empty($username) || empty($password)) {
        echo "<script>alert('Username and Password are required');</script>";
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM test_admin WHERE TRIM(Username) = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        $_SESSION['attempts'] = 0;
        $_SESSION['error'] = "Username or Password is incorrect";
        header("Location: LOGIN.php");
        exit;
    }

    if ($row['Status'] === 'Archived') {
        $_SESSION['error'] = "Account is Deactivated";
        header("Location: LOGIN.php");
        exit;
    }

    if ($row['Blocked'] == 1 && $row['PowerUser'] != 1) {
        $_SESSION['error'] = "Account is Blocked";
        header("Location: LOGIN.php");
        exit;
    }

    if (!password_verify($password, $row['Password'])) {
        if ($row['PowerUser'] != 1) {
            $_SESSION['attempts']++;
            if ($_SESSION['attempts'] >= 3) {
                $block = $conn->prepare("UPDATE test_admin SET Blocked = 1 WHERE Username = ?");
                $block->bind_param("s", $username);
                $block->execute();
                $_SESSION['error'] = "Account has been blocked after 3 failed attempts!";
                header("Location: LOGIN.php");
                exit;
            }
        }
        $_SESSION['error'] = "Username or Password is incorrect";
        header("Location: LOGIN.php");
        exit;
    }

    $_SESSION['Username'] = $row['Username'];
    $_SESSION['Admin'] = $row['Admin'];
    $_SESSION['PowerUser'] = $row['PowerUser'];
    $_SESSION['attempts'] = 0;
    $_SESSION['loggedin'] = true;
    $_SESSION['ForceChange'] = $row['ForceChange'];
    $_SESSION['Email'] = !empty($row['Email']) ? $row['Email'] : 'Not set';
    $_SESSION['Gender'] = !empty($row['Gender']) ? $row['Gender'] : 'Not set';
    $_SESSION['Birthday'] = !empty($row['Birthday']) ? $row['Birthday'] : 'Not set';
    $_SESSION['Information'] = $row['Information'];
    $_SESSION['login_time'] = time(); 

    if ($row['Admin'] == 1) {
        $_SESSION['Role'] = "Owner";
    } elseif ($row['PowerUser'] == 1) {
        $_SESSION['Role'] = "Manager";
    } else {
        $_SESSION['Role'] = "Staff";
    }

    $audit = $conn->prepare("INSERT INTO tbl_audit 
                            (User, Role, Module, Activity, Status) 
                            VALUES (?, ?, 'Login', 'User Logged In', 'Success')");
    $audit->bind_param("ss", $_SESSION['Username'], $_SESSION['Role']);
    $audit->execute();
    $audit->close();

    if ($row['Admin'] == 1) {
        header("Location: Dashboard.php");
    } elseif ($row['PowerUser'] == 1) {
        header("Location: Dashboard.php");
    } else {
        header("Location: Dashboard.php");
    }

    exit;
}
?>

<link rel="stylesheet" href="../styles/STYLE.css">

<head>
    <title>Log In</title>
</head>

<body>


    <form method="POST">
        <div class="login">
            <h2>LOG IN</h2><br>

            <p id="error-msg"></p>

            <input type="text" id="username" placeholder="Username" name="Username" required>

            <div class="password-wrapper">
                <input type="password" id="password" placeholder="Password" name="Password" required>
                <span class="toggle-password" onclick="togglePassword()">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </span>
            </div>

            <div class="log">
                <div class="button-container">
                    <input id="button" name="action" type="submit" value="LOGIN" onclick="LogUser()">
                    <input id="reset" type="reset" value="CLEAR" onclick="Clear()">
                </div>
            </div>
    </form>

    <script src="log_script.js"></script>

    <?php if (isset($_SESSION['error'])): ?>
        <script>
            alert("<?= $_SESSION['error'] ?>");
        </script>
    <?php unset($_SESSION['error']);
    endif; ?>

    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
        }
    </script>

</body>

</html>