<?php
session_start();

$conn_early = new mysqli('127.0.0.1', 'root', '', 'dbview');

$action = $_GET['action'] ?? '';

if ($action === 'get_active_buttons' || $action === 'get_buttons') {
    if (!$conn_early->connect_error) {
        $res = $conn_early->query("SELECT * FROM tbl_products WHERE status != 'disabled'");
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $jsName = addslashes($row['product_name']);
                $price  = (float)$row['price'];
                $stock  = (int)$row['stock_quantity'];
                echo "<button type='button' class='flavor-btn' onclick=\"addProduct('" . $jsName . "', " . $price . ", " . $stock . ")\">"
                    . htmlspecialchars($row['product_name']) . " &#8369;" . number_format($price, 2)
                    . "</button>";
            }
        } else {
            echo "<p id='no-flavors-msg'>No active flavors available.</p>";
        }
    }
    $conn_early->close(); exit;
}

if ($action === 'getProducts') {
    header('Content-Type: application/json');
    if (!$conn_early->connect_error) {
        $res      = $conn_early->query("SELECT product_id AS id, product_name AS name FROM tbl_products WHERE status != 'disabled'");
        $products = [];
        while ($row = $res->fetch_assoc()) $products[] = $row;
        echo json_encode($products);
    } else {
        echo json_encode([]);
    }
    $conn_early->close(); exit;
}

if ($action === 'live_inventory') {
    header('Content-Type: application/json');
    $isAdmin = false;
    foreach ($_SESSION as $v) {
        if (is_string($v) && strtolower($v) === 'admin') { $isAdmin = true; break; }
    }
    $val      = $conn_early->query("SELECT SUM(stock_quantity * price) as v FROM tbl_products WHERE status!='disabled'");
    $totalVal = $val ? ($val->fetch_assoc()['v'] ?? 0) : 0;
    $rows     = [];
    $res      = $conn_early->query("SELECT product_name, stock_quantity, minimum_stock FROM tbl_products WHERE status!='disabled' ORDER BY stock_quantity DESC");
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;
    $fc          = $conn_early->query("SELECT COUNT(*) as c FROM tbl_products WHERE status!='disabled'");
    $flavorCount = $fc ? $fc->fetch_assoc()['c'] : 0;
    echo json_encode([
        'flavor_count' => $flavorCount,
        'total_val'    => $isAdmin ? '&#8369;' . number_format($totalVal, 2) : preg_replace('/[0-9]/', '#', '&#8369;' . number_format($totalVal, 2)),
        'rows'         => $rows,
        'is_admin'     => $isAdmin,
    ]);
    $conn_early->close(); exit;
}

if ($action === 'top_sellers') {
    header('Content-Type: application/json');
    $period  = $_GET['period'] ?? 'monthly';
    $isAdmin = false;
    foreach ($_SESSION as $v) {
        if (is_string($v) && strtolower($v) === 'admin') { $isAdmin = true; break; }
    }
    $res = $conn_early->query("
        SELECT product_name,
               sold_quantity,
               (price * sold_quantity) AS total_earned
        FROM tbl_products
        WHERE sold_quantity > 0
          AND status != 'disabled'
        ORDER BY sold_quantity DESC
        LIMIT 5
    ");
    $rows = [];
    if ($res && !$conn_early->connect_error) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'product_name'  => $r['product_name'],
                'sold_quantity' => (int)$r['sold_quantity'],
                'total_earned'  => $isAdmin
                    ? '&#8369;' . number_format($r['total_earned'], 2)
                    : preg_replace('/[0-9]/', '#', '&#8369;' . number_format($r['total_earned'], 2)),
            ];
        }
    }
    echo json_encode($rows);
    $conn_early->close(); exit;
}

// ── TOP SELLERS CHART (real-time AJAX endpoint) ───────────────
if ($action === 'top_sellers_chart') {
    header('Content-Type: application/json');
    $isAdmin = false;
    foreach ($_SESSION as $v) {
        if (is_string($v) && strtolower($v) === 'admin') { $isAdmin = true; break; }
    }
    // Total revenue for Total Sales card
    $rev_res     = $conn_early->query("SELECT SUM(price * sold_quantity) as revenue FROM tbl_products");
    $totalRev    = $rev_res ? ((float)($rev_res->fetch_assoc()['revenue'] ?? 0)) : 0;
    $total_sales = $isAdmin
        ? '&#8369;' . number_format($totalRev, 2)
        : preg_replace('/[0-9]/', '#', '&#8369;' . number_format($totalRev, 2));
    // Chart data
    $total_res   = $conn_early->query("SELECT SUM(sold_quantity) as grand_total FROM tbl_products");
    $grand_total = (int)($total_res->fetch_assoc()['grand_total'] ?? 0);
    $divisor     = $grand_total ?: 1;
    $chart_query = $conn_early->query("SELECT product_name, sold_quantity FROM tbl_products WHERE sold_quantity > 0 ORDER BY sold_quantity DESC");
    $pastel = ['#AEC6CF','#FFB7B2','#B2E2F2','#FFDAC1','#E2F0CB','#B5EAD7','#C5B3E3','#FDFD96','#E5E5E5'];
    $parts = []; $legend = []; $i = 0; $cur = 0;
    if ($chart_query) {
        while ($row = $chart_query->fetch_assoc()) {
            $pct   = ($row['sold_quantity'] / $divisor) * 100;
            $color = $pastel[$i % count($pastel)];
            $next  = $cur + $pct;
            $parts[]  = "{$color} {$cur}% " . ($next - 0.1) . "%, #fff " . ($next - 0.1) . "% {$next}%";
            $legend[] = ['name' => $row['product_name'], 'pct' => round($pct, 1), 'color' => $color];
            $cur = $next; $i++;
        }
    }
    echo json_encode([
        'grand_total' => number_format($grand_total),
        'gradient'    => implode(', ', $parts),
        'legend'      => $legend,
        'total_sales' => $total_sales,
    ]);
    $conn_early->close(); exit;
}

$conn_early->close();

// ── AUTH ──────────────────────────────────────────────────────
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: LOGIN.php"); exit;
}

$conn = new mysqli('127.0.0.1', 'root', '', 'dbview');
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$totalProducts = 0;
$r = $conn->query("SELECT COUNT(*) as total FROM tbl_products");
if ($r) $totalProducts = $r->fetch_assoc()['total'];

$lowStock = 0;
$r = $conn->query("SELECT COUNT(*) as low FROM tbl_products WHERE stock_quantity <= minimum_stock AND status != 'disabled'");
if ($r) $lowStock = $r->fetch_assoc()['low'];

$low_stock_list = $conn->query("SELECT product_name, stock_quantity, minimum_stock FROM tbl_products WHERE stock_quantity <= minimum_stock AND status != 'disabled' ORDER BY stock_quantity ASC");

$expiringCount = 0;
$r = $conn->query("SELECT COUNT(*) as expiring FROM tbl_products WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE()");
if ($r) $expiringCount = $r->fetch_assoc()['expiring'];

$totalRevenue = 0;
$r = $conn->query("SELECT SUM(price * sold_quantity) as revenue FROM tbl_products");
if ($r) $totalRevenue = $r->fetch_assoc()['revenue'] ?? 0;

$isAdmin = false;
foreach ($_SESSION as $v) {
    if (is_string($v) && strtolower($v) === 'admin') { $isAdmin = true; break; }
}

function maskedAmount(string $formatted, bool $isAdmin): string {
    return $isAdmin ? $formatted : preg_replace('/[0-9]/', '#', $formatted);
}

function getPastelColor(int $i): string {
    $p = ['#AEC6CF','#FFB7B2','#B2E2F2','#FFDAC1','#E2F0CB','#B5EAD7','#C5B3E3','#FDFD96','#E5E5E5'];
    return $p[$i % count($p)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="../styles/Dash.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>

    <style>
        :root {
            --clr-bg    : #f4f5f7;
            --clr-surf  : #ffffff;
            --clr-border: #555555;
            --clr-muted : #777777;
            --clr-text  : #333333;
            --clr-green : #48bb78;
            --clr-orange: #ed8936;
            --clr-red   : #e53e3e;
            --clr-blue  : #3182ce;
            --shadow    : 0 4px 0 #999;
            --radius    : 12px;
            --t         : 0.2s ease;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; background: var(--clr-bg); color: var(--clr-text); }

        .dashboard-wrapper  { padding: 10px; }
        .stats-container    { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }

        .stat-card {
            background: var(--clr-surf); padding: 20px; border-radius: var(--radius);
            border: 2px solid var(--clr-border); box-shadow: var(--shadow);
            text-align: center; transition: transform var(--t);
        }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card[onclick]:hover { transform: translateY(-3px); box-shadow: 0 6px 0 #888, 0 0 0 2px var(--clr-green); }
        .stat-card h3 { font-size: 13px; color: var(--clr-muted); text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 28px; font-weight: 900; margin: 10px 0; color: var(--clr-text); }

        .inventory-preview {
            max-height: 200px; overflow-y: auto;
            border-top: 1px solid #eee;
            margin-top: 10px; padding-top: 12px;
            font-size: 12px; text-align: left;
        }

        .low-stock-banner {
            background: #fed7d7; color: #c53030; padding: 15px;
            border: 2px solid #c53030; border-radius: 8px;
            margin-bottom: 20px; font-weight: bold; text-align: center;
        }

        .bell-btn {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            padding: 8px 12px; font-size: 18px; border-radius: 10px;
            border: 2px solid var(--clr-border); background-color: #e0e0e0;
            cursor: pointer; box-shadow: var(--shadow); font-family: sans-serif; flex-shrink: 0;
        }
        .bell-btn .badge {
            position: absolute; top: -5px; right: -5px;
            background: var(--clr-red); color: white; font-size: 11px; padding: 2px 6px;
            border-radius: 50%; border: 1px solid white; pointer-events: none;
        }

        .pos-btn {
            position: relative; display: inline-flex; align-items: center; justify-content: center;
            padding: 8px 18px; font-size: 15px; font-weight: bold; border-radius: 10px;
            border: 2px solid var(--clr-border); background-color: #e0e0e0; color: #000;
            cursor: pointer; box-shadow: var(--shadow); font-family: sans-serif; flex-shrink: 0;
            transition: background-color var(--t), transform 0.1s;
        }
        .pos-btn:hover  { background-color: #c8c8c8; }
        .pos-btn:active { transform: translateY(3px); box-shadow: 0 2px 0 #777; }

        /* ── AVATAR DROPDOWN ── */
        .avatar-dropdown { position: relative; flex-shrink: 0; }
        .avatar-btn {
            background: none; border: none; padding: 0; margin: 0;
            cursor: pointer; display: block; line-height: 0; outline: none;
            -webkit-appearance: none; appearance: none;
        }
        .avatar-btn img {
            width: 38px; height: 38px; border-radius: 50%; object-fit: cover; display: block;
            border: 2px solid var(--clr-border); transition: opacity 0.15s;
        }
        .avatar-btn:hover img { opacity: 0.85; }
        .avatar-menu {
            display: none; position: fixed; background: #ffffff; border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18); border: 1px solid #ddd;
            min-width: 150px; z-index: 999999; padding: 4px 0;
        }
        .avatar-menu.open { display: block; }
        .avatar-menu li { list-style: none; }
        .avatar-menu li a {
            display: block; padding: 11px 16px; color: #333; text-decoration: none;
            font-size: 14px; font-family: sans-serif; transition: background 0.15s;
        }
        .avatar-menu li a:hover { background: #f4f5f7; }
        .avatar-menu li.divider { border-top: 1px solid #eee; margin: 3px 0; }

        #lowStockPanel {
            position: fixed; top: 0; right: -400px;
            width: min(350px, 92vw); height: 100%;
            background: white; border-left: 3px solid var(--clr-border);
            z-index: 100001; box-shadow: -5px 0 10px rgba(0,0,0,0.2);
            transition: right 0.3s ease; padding: 20px; overflow-y: auto;
        }
        #lowStockPanel.active { right: 0; }

        .chart-wrapper { display: flex; align-items: center; justify-content: center; gap: 20px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; flex-wrap: wrap; }
        .legend { list-style: none; padding: 0; margin: 0; font-size: 12px; text-align: left; }
        .legend-item { display: flex; align-items: center; margin-bottom: 4px; }
        .dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 8px; border: 1px solid #555; }

        .pos-modal {
            position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none;
            justify-content: center; align-items: flex-start; z-index: 10000;
            overflow-y: auto; padding: 16px; backdrop-filter: blur(2px);
        }
        .pos-modal.open { display: flex; }

        .pos-container {
            width: 100%; max-width: 960px; margin: auto; background: #ffffff;
            border: 3px solid var(--clr-border); border-radius: 15px;
            box-shadow: 0 8px 0 #999; padding: clamp(14px, 3vw, 25px);
            position: relative; transition: 0.2s ease;
        }
        .pos-container * { user-select: none; }
        .pos-drag-handle { cursor: move; font-size: clamp(18px, 3vw, 24px); font-weight: bold; margin-bottom: 15px; text-align: center; font-family: sans-serif; }

        .pos-container .products {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 8px; margin-bottom: 15px;
        }
        .pos-container .products button,
        .pos-container .products .flavor-btn {
            padding: 8px; font-size: 13px; font-weight: bold; border-radius: 8px;
            border: 2px solid var(--clr-border); background-color: #e0e0e0;
            cursor: pointer; box-shadow: 0 3px 0 #999; transition: 0.2s ease;
            text-align: center; word-break: break-word; min-height: 46px; font-family: sans-serif;
        }
        .pos-container .products button:hover  { background: #d0d0d0; transform: translateY(-1px); }
        .pos-container .products button:active { transform: translateY(2px); box-shadow: 0 1px 0 #999; }

        .pos-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 15px; }
        .pos-stock-info { flex: 1 1 180px; font-size: 12px; color: #333; line-height: 1.6; border-left: 3px solid var(--clr-border); padding-left: 10px; text-align: left; }

        .pos-table-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .pos-container .pos-table { width: 100%; min-width: 480px; border-collapse: collapse; margin-top: 15px; }
        .pos-container .pos-table th,
        .pos-container .pos-table td { border: 2px solid var(--clr-border); padding: 8px; text-align: center; font-size: 14px; }
        .pos-container .pos-table td input { width: 60px; text-align: center; padding: 5px; border: 1px solid #ccc; border-radius: 4px; }

        .pos-container .pos-footer { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 15px; font-size: 18px; font-weight: bold; }
        .pos-container .pos-footer strong { flex: 1 1 auto; white-space: nowrap; }

        .pos-checkout-btn {
            padding: 10px 22px; font-size: 15px; font-weight: bold; cursor: pointer;
            border-radius: 10px; border: 2px solid var(--clr-border); background: #90ee90; color: black;
            box-shadow: 0 4px 0 #999; transition: background-color 0.2s, transform 0.1s; font-family: sans-serif;
        }
        .pos-checkout-btn:hover  { background: #28a745; color: white; }
        .pos-checkout-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #999; }
        .pos-checkout-btn:disabled { opacity: .5; cursor: not-allowed; }

        .pos-container .pos-keypad { margin-top: 20px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
        .pos-container #qtyInput { width: 100%; max-width: 260px; font-size: 18px; padding: 12px; text-align: center; border: 2px solid var(--clr-border); border-radius: 10px; font-family: sans-serif; }
        .pos-container .calc-buttons { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; width: 100%; max-width: 260px; }
        .pos-container .calc-buttons button { padding: 14px 0; font-size: 18px; border-radius: 10px; cursor: pointer; background: #e0e0e0; border: 2px solid var(--clr-border); font-weight: bold; box-shadow: 0 4px 0 #999; transition: 0.2s ease; font-family: sans-serif; width: 100%; }
        .pos-container .calc-buttons button:active { transform: translateY(3px); box-shadow: 0 1px 0 #999; }

        .pos-close-btn {
            background-color: #cc0000; color: white; border: 2px solid var(--clr-border);
            font-size: 18px; font-weight: bold; width: 34px; height: 34px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            position: absolute; top: 14px; right: 14px; transition: background-color 0.2s, transform 0.1s;
        }
        .pos-close-btn:hover  { background-color: #ff4d4d; }
        .pos-close-btn:active { transform: scale(0.9); }

        .pos-clear-btn {
            background-color: #ffcccb; color: black; padding: 10px 15px; font-size: 15px; font-weight: bold;
            border: 2px solid var(--clr-border); border-radius: 10px; box-shadow: 0 4px 0 #999;
            cursor: pointer; transition: background-color 0.2s, transform 0.1s; white-space: nowrap;
            font-family: sans-serif; flex-shrink: 0;
        }
        .pos-clear-btn:hover  { background-color: #ff4d4d; color: white; }
        .pos-clear-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #999; }

        .pos-warning-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; justify-content: center; align-items: center; z-index: 20000; }
        .pos-warning-container { background: white; padding: 24px 28px; border-radius: 15px; border: 3px solid var(--clr-border); box-shadow: 0 6px 0 #999; text-align: center; font-family: sans-serif; max-width: 90vw; }
        .pos-warning-container p { margin-bottom: 16px; font-size: 15px; }
        .pos-warn-btn { padding: 8px 20px; font-size: 14px; font-weight: bold; cursor: pointer; border-radius: 8px; border: 2px solid var(--clr-border); background: #e0e0e0; box-shadow: 0 3px 0 #999; transition: 0.2s ease; margin: 0 4px; font-family: sans-serif; }
        .pos-warn-btn:active { transform: translateY(2px); }

        @media (max-width: 768px) { .stats-container { grid-template-columns: 1fr; } .pos-modal { padding: 8px; } }
        @media (max-width: 600px) { .pos-container { padding: 14px; border-radius: 10px; } .pos-container .products { grid-template-columns: repeat(auto-fill, minmax(85px, 1fr)); gap: 6px; } .pos-container .products button { font-size: 12px; min-height: 42px; } .pos-drag-handle { font-size: 17px; } .pos-container .pos-footer { font-size: 15px; } }
        @media (max-width: 400px) { .pos-container .calc-buttons { max-width: 100%; } .pos-container #qtyInput { max-width: 100%; } .bell-btn { padding: 6px 10px; font-size: 16px; } .pos-btn { padding: 6px 12px; font-size: 13px; } }
    </style>
</head>
<body>

<?php if (isset($_SESSION['ForceChange']) && $_SESSION['ForceChange'] == 1): ?>
    <div id="Force" class="Modal">
        <div class="modal_container">
            <h2>Password Change Required</h2>
            <p>You must change your password to continue</p>
            <form method="POST" action="Change_Pass.php">
                <div class="passwordWrap">
                    <input type="password" id="new_pass" class="ne" placeholder="Enter New Password..." name="new_pass" required>
                    <span class="toggle-password" onclick="togglePassword('new_pass')">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
                <div class="passwordWrap">
                    <input type="password" id="confirm_pass" class="con" placeholder="Confirm New Password..." name="con_pass" required>
                    <span class="toggle-passwords" onclick="togglePassword('confirm_pass')">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
                <button type="submit" class="change">Done!</button>                
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="container">
    <div class="sidebar" style="position:fixed; top:0; left:0; height:100vh; overflow-y:auto; z-index:100; width:180px;">
        <style>
            .sidebar button { transition: background-color 0.2s ease, transform 0.15s ease, box-shadow 0.15s ease; }
            .sidebar button:hover { transform: translateX(4px); box-shadow: 2px 2px 6px rgba(0,0,0,0.15); }
            .sidebar button:active { transform: translateX(2px) scale(0.98); }
            .center-top-box { transition: opacity .18s ease, transform .18s ease; }
        </style>
        <button type="button" onclick="gohome();">Home</button>
        <button type="button" onclick="showPanel('flavor')">Flavor Management</button>
        <button type="button" onclick="showPanel('stock')">Stock Tracking</button>
        <button type="button" onclick="showPanel('sales')">Bulk Sales Recording</button>
        <button type="button" onclick="showPanel('report')">Reports</button>
        <button type="button" onclick="showPanel('supplier')">Manufacturing</button>
        <button type="button" onclick="showPanel('expiry')">Expiry Date Monitoring</button>
    </div>

    <div class="main" style="margin-left:180px; min-height:100vh;">
        <div class="header" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:0 24px; height:70px; position:sticky; top:0; z-index:1000;">
            <span id="greeting-text" style="font-weight:700;"></span>
            <div style="position:absolute; left:50%; transform:translateX(-50%); background:rgba(255,255,255,0.15); border:2px solid rgba(255,255,255,0.4); border-radius:10px; padding:6px 18px; pointer-events:none; white-space:nowrap; backdrop-filter:blur(4px);">
                <span id="live-clock" style="font-size:20px; font-weight:800; color:white; letter-spacing:2px;"></span>
            </div>
            <div style="display:flex; align-items:center; gap:8px; flex-shrink:0; font-size:14px;">
                <button type="button" class="bell-btn" onclick="toggleLowStock()">
                    🔔<?php if ($lowStock > 0) echo "<span class='badge'>$lowStock</span>"; ?>
                </button>
                <button type="button" class="pos-btn" onclick="openPOS()">POS</button>
                <div class="avatar-dropdown" id="avatarDropdown">
                    <button class="avatar-btn" type="button" onclick="toggleAvatarMenu(event)" aria-label="Account menu">
                        <img src="../image/boy.png" alt="User">
                    </button>
                    <ul class="avatar-menu" id="avatarMenu">
                        <li><a href="#" onclick="openAccountModal(event)">Account</a></li>
                        <li class="divider"></li>
                        <?php if (isset($_SESSION['Role']) && in_array($_SESSION['Role'], ['Owner', 'Admin'])): ?>
                            <li><a href="../frack/Admin.php">Admin Panel</a></li>
                            <li class="divider"></li>
                        <?php endif; ?>
                        <li><a href="../frack/LogBook.php">Log Book</a></li>
                        <li class="divider"></li>
                        <li><a href="../frack/Log_out.php">Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if ($lowStock > 0): ?>
            <div class="low-stock-banner">
                ⚠️ Warning: There are <?php echo $lowStock; ?> items running low on stock!
            </div>
        <?php endif; ?>

        <div class="center-top-box">
            <div class="dashboard-wrapper">
                <div class="stats-container">

                    <!-- CARD 1: Inventory Assets -->
                    <div class="stat-card">
                        <h3>Inventory Assets</h3>
                        <div class="value">
                            <span id="flavor-count"><?php
                                $fc = $conn->query("SELECT COUNT(*) FROM tbl_products WHERE status != 'disabled'");
                                echo $fc ? $fc->fetch_row()[0] : 0;
                            ?></span>
                            <span style="font-size:14px; color:#999; font-weight:400;">Flavors</span>
                        </div>
                        <div class="inventory-preview">
                            <div id="live-inventory-container">
                                <?php
                                $val_res  = $conn->query("SELECT SUM(stock_quantity * price) as total_val FROM tbl_products WHERE status != 'disabled'");
                                $totalVal = $val_res ? ($val_res->fetch_assoc()['total_val'] ?? 0) : 0;
                                $stock_details = $conn->query("SELECT product_name, stock_quantity, minimum_stock FROM tbl_products WHERE status != 'disabled' ORDER BY stock_quantity DESC");
                                ?>
                                <?php if ($stock_details && $stock_details->num_rows > 0): ?>
                                    <div style="background:#f7fafc; padding:8px; border-radius:6px; margin-bottom:12px; border:1px solid #edf2f7; text-align:center;">
                                        <span style="color:#718096; font-size:10px; text-transform:uppercase;">Stock Valuation</span><br>
                                        <strong style="color:#2d3748; font-size:15px;">
                                            <?php echo maskedAmount('₱' . number_format($totalVal, 2), $isAdmin); ?>
                                        </strong>
                                    </div>
                                    <?php while ($inv = $stock_details->fetch_assoc()):
                                        $qty          = (int)$inv['stock_quantity'];
                                        $isOutOfStock = ($qty === 0);
                                        $isLow        = !$isOutOfStock && ($qty <= $inv['minimum_stock']);
                                        if ($isOutOfStock)  { $statusColor = '#4a5568'; $statusIcon = '🚫'; $statusText = 'Out of Stock'; $rowBg = 'background:#f8f8f8;'; }
                                        elseif ($isLow)     { $statusColor = '#e53e3e'; $statusIcon = '⚠️'; $statusText = 'Low Stock';    $rowBg = ''; }
                                        else                { $statusColor = '#48bb78'; $statusIcon = '✅'; $statusText = 'In Stock';      $rowBg = ''; }
                                    ?>
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; padding:6px 4px 6px 8px; border-bottom:1px solid #f9f9f9; border-radius:4px; <?php echo $rowBg; ?>">
                                            <div style="display:flex; flex-direction:column;">
                                                <span style="font-weight:600; color:<?php echo $isOutOfStock ? '#a0aec0' : '#333'; ?>;">
                                                    <?php echo htmlspecialchars($inv['product_name']); ?>
                                                </span>
                                                <span style="font-size:10px; color:<?php echo $statusColor; ?>;">
                                                    <?php echo $statusIcon . ' ' . $statusText; ?>
                                                </span>
                                            </div>
                                            <div style="text-align:right;">
                                                <strong style="font-size:14px; color:<?php echo $isOutOfStock ? '#a0aec0' : '#333'; ?>;"><?php echo number_format($qty); ?></strong>
                                                <span style="color:#999; font-size:10px;"> packs</span>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div style="text-align:center; color:#999; padding-top:10px;">No inventory found</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 2: Low Stock Details -->
                    <div class="stat-card" style="border-color:#ed8936;">
                        <h3>Low Stock Details</h3>
                        <div class="value" style="color:#ed8936;"><?php echo $lowStock; ?></div>
                        <div class="inventory-preview" style="max-height:180px;">
                            <?php
                            $low_stock_list->data_seek(0);
                            if ($lowStock > 0):
                                while ($low = $low_stock_list->fetch_assoc()):
                                    $needed       = $low['minimum_stock'] - $low['stock_quantity'];
                                    $isOutOfStock = ((int)$low['stock_quantity'] === 0);
                                    $isCritical   = !$isOutOfStock && ($low['stock_quantity'] <= ($low['minimum_stock'] * 0.3));
                                    if ($isOutOfStock)   { $borderColor = '#4a5568'; $labelColor = '#4a5568'; $label = 'Out of Stock'; }
                                    elseif ($isCritical) { $borderColor = '#e53e3e'; $labelColor = '#e53e3e'; $label = 'Critical'; }
                                    else                 { $borderColor = '#ed8936'; $labelColor = '#ed8936'; $label = 'Low'; }
                            ?>
                                <div style="padding:10px; margin-bottom:8px; background:<?php echo $isOutOfStock ? '#f7fafc' : '#fffaf0'; ?>; border-left:4px solid <?php echo $borderColor; ?>; border-radius:4px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <span style="font-weight:800; font-size:13px;"><?php echo htmlspecialchars($low['product_name']); ?></span>
                                        <span style="text-transform:uppercase; font-size:10px; font-weight:bold; color:<?php echo $labelColor; ?>;"><?php echo $label; ?></span>
                                    </div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:5px; color:#555;">
                                        <div>
                                            <strong>Current:</strong> <?php echo $low['stock_quantity']; ?><br>
                                            <strong>Minimum:</strong> <?php echo $low['minimum_stock']; ?>
                                        </div>
                                        <div style="text-align:right; border-left:1px solid #ddd; padding-left:10px;">
                                            <?php if ($isOutOfStock): ?>
                                                <span style="color:#e53e3e; font-weight:700; font-size:12px;">No stock available</span><br>
                                                <strong style="font-size:14px; color:#4a5568;">+<?php echo $low['minimum_stock']; ?> units needed</strong>
                                            <?php else: ?>
                                                <span style="color:#999;">Restock Needed:</span><br>
                                                <strong style="font-size:14px;">+<?php echo max(0, $needed); ?> units</strong>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile;
                            else: ?>
                                <div style="text-align:center; color:#999; padding-top:10px;">All stock levels are normal</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CARD 3: Expiring Soon -->
                    <div class="stat-card" style="border-color:#e53e3e;">
                        <h3>Expiring Soon</h3>
                        <div class="value" style="color:#e53e3e;"><?php echo $expiringCount; ?></div>
                        <div class="inventory-preview" style="max-height:120px;">
                            <?php
                            $expiring_details = $conn->query("SELECT product_name, expiry_date FROM tbl_products WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date >= CURDATE() ORDER BY expiry_date ASC");
                            if ($expiring_details && $expiring_details->num_rows > 0):
                                while ($exp = $expiring_details->fetch_assoc()):
                                    $dateFmt = date("M d", strtotime($exp['expiry_date']));
                            ?>
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px; padding:2px 0;">
                                    <span style="font-weight:600;"><?php echo htmlspecialchars($exp['product_name']); ?></span>
                                    <span style="color:#e53e3e; font-weight:bold;">Expires: <?php echo $dateFmt; ?></span>
                                </div>
                            <?php endwhile;
                            else: ?>
                                <div style="text-align:center; color:#999; padding-top:5px;">No upcoming expiries</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CARD 4: Total Sales -->
                    <div class="stat-card" style="border-color:#48bb78;">
                        <h3>Total Sales</h3>
                        <div id="totalSalesValue" class="value" style="color:#48bb78;">
                            <?php echo maskedAmount('₱' . number_format($totalRevenue, 2), $isAdmin); ?>
                        </div>
                    </div>

                    <!-- CARD 5: Top Sellers Chart -->
                    <div class="stat-card" onclick="showPanel('stock')" style="cursor:pointer;" title="View Stock Tracking">
                        <h3 style="margin-bottom:15px;">Top Sellers Chart</h3>
                        <?php
                        $total_res   = $conn->query("SELECT SUM(sold_quantity) as grand_total FROM tbl_products");
                        $total_data  = $total_res->fetch_assoc();
                        $grand_total = ($total_data['grand_total'] > 0) ? $total_data['grand_total'] : 1;
                        $chart_query = $conn->query("SELECT product_name, sold_quantity FROM tbl_products WHERE sold_quantity > 0 ORDER BY sold_quantity DESC");
                        $i = 0; $cur = 0; $parts = []; $legend = [];
                        while ($row = $chart_query->fetch_assoc()) {
                            $pct   = ($row['sold_quantity'] / $grand_total) * 100;
                            $color = getPastelColor($i);
                            $next  = $cur + $pct;
                            $parts[]  = "{$color} {$cur}% " . ($next - 0.1) . "%, #fff " . ($next - 0.1) . "% {$next}%";
                            $legend[] = ['name' => $row['product_name'], 'pct' => round($pct, 1), 'color' => $color];
                            $cur = $next; $i++;
                        }
                        $grad = implode(', ', $parts);
                        ?>
                        <div class="chart-wrapper">
                            <div style="position:relative; width:150px; height:150px; flex-shrink:0;">
                                <div id="chartDonut" style="width:100%; height:100%; border-radius:50%; border:2px solid #555; background:conic-gradient(<?php echo $grad; ?>); box-shadow:0 2px 5px rgba(0,0,0,.05);"></div>
                                <div style="position:absolute; top:25%; left:25%; width:50%; height:50%; background:#fff; border-radius:50%; border:2px solid #555; display:flex; align-items:center; justify-content:center; flex-direction:column;">
                                    <span style="font-size:10px; color:#999; text-transform:uppercase;">Total</span>
                                    <span id="chartTotal" style="font-size:15px; font-weight:bold;"><?php echo number_format($total_data['grand_total']); ?></span>
                                </div>
                            </div>
                            <div style="max-height:180px; overflow-y:auto; padding:0 0 0 16px; border-left:1px solid #f0f0f0;">
                                <ul class="legend" id="chartLegend">
                                    <?php foreach ($legend as $item): ?>
                                        <li class="legend-item" style="margin-bottom:10px;">
                                            <span class="dot" style="background:<?php echo $item['color']; ?>; width:12px; height:12px; flex-shrink:0;"></span>
                                            <div style="display:flex; flex-direction:column;">
                                                <span style="font-weight:600; color:#444; font-size:13px; line-height:1.1;"><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span style="color:#999; font-size:11px; margin-top:2px;"><?php echo $item['pct']; ?>%</span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- CARD 6: Top Sellers Detail -->
                    <div class="stat-card" onclick="showPanel('stock')" style="cursor:pointer;" title="View Stock Tracking">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                            <h3>Top Sellers Detail</h3>
                            <select id="topSellersPeriod" onchange="loadTopSellers(this.value)" onclick="event.stopPropagation()"
                                style="font-size:12px; padding:5px 10px; border:1px solid #e2e8f0; border-radius:6px; font-family:sans-serif; color:#4a5568; background:#f7fafc; cursor:pointer;">
                                <option value="weekly">This Week</option>
                                <option value="monthly" selected>This Month</option>
                                <option value="quarterly">This Quarter</option>
                                <option value="yearly">This Year</option>
                                <option value="all">All Time</option>
                            </select>
                        </div>
                        <table style="width:100%; border-collapse:collapse;">
                            <tbody id="topSellersTable">
                                <?php
                                $top = $conn->query("SELECT product_name, sold_quantity, (price * sold_quantity) as total_earned FROM tbl_products WHERE sold_quantity > 0 AND status != 'disabled' ORDER BY sold_quantity DESC LIMIT 5");
                                $rankColors = ['#d69e2e','#718096','#c05621'];
                                $rank = 1;
                                if ($top && $top->num_rows > 0):
                                    while ($row = $top->fetch_assoc()):
                                        $rankColor = $rankColors[$rank - 1] ?? '#a0aec0';
                                ?>
                                    <tr style="border-bottom:1px solid #eee;">
                                        <td style="padding:10px 0; text-align:left; font-size:14px;">
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:<?php echo $rankColor; ?>;color:#fff;font-size:11px;font-weight:700;font-family:sans-serif;flex-shrink:0;">
                                                    <?php echo $rank; ?>
                                                </span>
                                                <div>
                                                    <div style="font-weight:bold; font-family:sans-serif;"><?php echo htmlspecialchars($row['product_name']); ?></div>
                                                    <div style="font-size:11px; color:#888;"><?php echo number_format($row['sold_quantity']); ?> units sold</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding:10px 0; text-align:right; vertical-align:middle;">
                                            <span style="color:#48bb78; font-weight:700; font-size:14px; font-family:sans-serif;">
                                                &#8369;<?php echo maskedAmount(number_format($row['total_earned'], 2), $isAdmin); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php $rank++; endwhile;
                                else: ?>
                                    <tr><td colspan="2" style="padding:12px 0; color:#999; font-size:13px; text-align:center;">No sales data recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- ACCOUNT MODAL -->
<div id="accountModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:999999; justify-content:center; align-items:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:18px; width:min(780px, 92vw); max-height:90vh; overflow-y:auto; position:relative; box-shadow:0 20px 60px rgba(0,0,0,0.2); font-family:sans-serif;">
        <div style="background:linear-gradient(135deg, #b7b6ae 0%, #8a8980 100%); padding:28px 28px 80px; text-align:center;">
            <h2 style="color:white; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:2px; margin:0; opacity:0.85;">Account Profile</h2>
        </div>
        <div style="text-align:center; margin-top:-60px; padding:0 28px; position:relative; z-index:2;">
            <div style="position:relative; display:inline-block;">
                <img id="profilePicDisplay" src="../image/boy.png" alt="User"
                    style="width:110px; height:110px; border-radius:50%; object-fit:cover; border:5px solid #fff; box-shadow:0 4px 16px rgba(0,0,0,0.15); display:block; background:#e2e8f0;">
            </div>
            <div style="margin-top:10px; font-size:24px; font-weight:800; color:#1a202c;">
                <?php echo htmlspecialchars($_SESSION['Username'] ?? $_SESSION['user'] ?? 'User'); ?>
            </div>
            <div style="display:inline-block; margin-top:6px; padding:4px 14px; background:#edf2f7; border-radius:20px; font-size:12px; font-weight:700; color:#4a5568; text-transform:uppercase; letter-spacing:1px;">
                <?php echo htmlspecialchars($_SESSION['Role'] ?? 'Staff'); ?>
            </div>
            <div style="margin-top:8px; display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#38a169; font-weight:600;">
                <span style="width:8px; height:8px; background:#38a169; border-radius:50%; display:inline-block;"></span> Active Session
            </div>
        </div>
        <div style="padding:24px 28px 28px;">
            <div style="font-size:11px; font-weight:700; color:#a0aec0; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:14px;">Account Details</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div style="display:flex; align-items:center; gap:14px; padding:16px 18px; background:#f7fafc; border-radius:10px; border:1px solid #edf2f7;">
                    <div style="width:40px; height:40px; background:#e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">👤</div>
                    <div>
                        <div style="font-size:11px; color:#a0aec0; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Username</div>
                        <div style="font-size:15px; color:#1a202c; font-weight:700; margin-top:3px;"><?php echo htmlspecialchars($_SESSION['Username'] ?? $_SESSION['user'] ?? 'User'); ?></div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:14px; padding:16px 18px; background:#f7fafc; border-radius:10px; border:1px solid #edf2f7;">
                    <div style="width:40px; height:40px; background:#e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">🛡️</div>
                    <div>
                        <div style="font-size:11px; color:#a0aec0; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Role</div>
                        <div style="font-size:15px; color:#1a202c; font-weight:700; margin-top:3px;"><?php echo htmlspecialchars($_SESSION['Role'] ?? 'Staff'); ?></div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:14px; padding:16px 18px; background:#f7fafc; border-radius:10px; border:1px solid #edf2f7;">
                    <div style="width:40px; height:40px; background:#e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">🕐</div>
                    <div>
                        <div style="font-size:11px; color:#a0aec0; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Last Login</div>
                        <div style="font-size:15px; color:#1a202c; font-weight:700; margin-top:3px;">
                            <?php
                            $loginTime = $_SESSION['login_time'] ?? $_SESSION['LoginTime'] ?? $_SESSION['last_login'] ?? null;
                            echo $loginTime
                                ? date('M d, Y  h:i A', is_numeric($loginTime) ? $loginTime : strtotime($loginTime))
                                : date('M d, Y  h:i A');
                            ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:14px; padding:16px 18px; background:#f7fafc; border-radius:10px; border:1px solid #edf2f7;">
                    <div style="width:40px; height:40px; background:#e2e8f0; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">📅</div>
                    <div>
                        <div style="font-size:11px; color:#a0aec0; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Account Created</div>
                        <div style="font-size:15px; color:#1a202c; font-weight:700; margin-top:3px;">
                            <?php
                            $created = $_SESSION['created_at'] ?? $_SESSION['CreatedAt'] ?? $_SESSION['date_created'] ?? null;
                            echo $created
                                ? date('M d, Y', is_numeric($created) ? $created : strtotime($created))
                                : 'Not available';
                            ?>
                        </div>
                    </div>
                </div>
                <div style="grid-column:1/-1; display:flex; align-items:center; gap:14px; padding:16px 18px; background:#f0fff4; border-radius:10px; border:1px solid #c6f6d5;">
                    <div style="width:40px; height:40px; background:#c6f6d5; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0;">✅</div>
                    <div>
                        <div style="font-size:11px; color:#a0aec0; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Status</div>
                        <div style="font-size:15px; color:#38a169; font-weight:700; margin-top:3px;">Active</div>
                    </div>
                </div>
            </div>
            <button onclick="closeAccountModal()" style="margin-top:16px; width:100%; padding:13px; background:#555; color:white; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; font-family:sans-serif;"
                onmouseover="this.style.background='#333'" onmouseout="this.style.background='#555'">Close</button>
        </div>
    </div>
</div>

<!-- LOW STOCK SIDE PANEL -->
<div id="lowStockPanel">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3 style="font-family:sans-serif;">Low Stock Alerts</h3>
        <button onclick="toggleLowStock()" style="cursor:pointer; border:none; background:#555; color:white; padding:5px 10px; border-radius:5px; font-family:sans-serif;">✕</button>
    </div>
    <hr style="margin:12px 0;">
    <?php
    $low_stock_list->data_seek(0);
    while ($item = $low_stock_list->fetch_assoc()): ?>
        <div style="padding:10px; border-bottom:1px solid #eee;">
            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong><br>
            <span style="color:<?php echo (int)$item['stock_quantity'] === 0 ? '#4a5568' : 'red'; ?>; font-weight:bold;">
                <?php echo (int)$item['stock_quantity'] === 0 ? 'Out of Stock' : $item['stock_quantity'] . ' items left'; ?>
            </span>
        </div>
    <?php endwhile; ?>
</div>

<!-- POS LIMIT ERROR MODAL -->
<div id="posLimitModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:18px;overflow:hidden;width:360px;max-width:95vw;box-shadow:0 12px 40px rgba(0,0,0,.2);font-family:sans-serif;animation:posLimitIn .2s ease;">
        <div style="background:#c0392b;padding:20px 22px;display:flex;align-items:center;gap:10px;">
            <div style="width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;">🛒</div>
            <div style="color:#fff;font-size:17px;font-weight:700;">Order Limit Reached</div>
        </div>
        <div style="padding:20px 22px;">
            <p id="posLimitMsg" style="font-size:13px;color:#555;line-height:1.6;margin-bottom:6px;"></p>
            <p style="font-size:12px;color:#999;margin-top:8px;">For orders exceeding 10 packs, please use the <strong style="color:#7a9068;">Bulk Sales Recording</strong> section.</p>
        </div>
        <div style="display:flex;gap:10px;padding:0 22px 20px;">
            <button onclick="closePOSLimitModal()" style="flex:1;padding:10px;border-radius:9px;border:1.5px solid #e0e0e0;background:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:sans-serif;">Stay in POS</button>
            <button onclick="goToBulkOrder()" style="flex:2;padding:10px;border-radius:9px;border:none;background:#7a9068;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:sans-serif;">Go to Bulk Orders →</button>
        </div>
    </div>
</div>
<style>
@keyframes posLimitIn { from{opacity:0;transform:translateY(12px) scale(.96);}to{opacity:1;transform:translateY(0) scale(1);} }
</style>

<!-- POS MODAL -->
<div id="posModal" class="pos-modal">
    <div class="pos-container">
        <button class="pos-close-btn" onclick="confirmClosePOS()" aria-label="Close POS">✕</button>
        <h2 class="pos-drag-handle">Point of Sale</h2>
        <div class="products" id="pos-product-grid">
            <?php
            $pl = $conn->query("SELECT * FROM tbl_products WHERE status != 'disabled'");
            if ($pl && $pl->num_rows > 0):
                while ($row = $pl->fetch_assoc()):
                    $jsName = addslashes($row['product_name']);
                    $price  = (float)$row['price'];
                    $stock  = (int)$row['stock_quantity'];
            ?>
                <button type="button" class="flavor-btn"
                        onclick="addProduct('<?php echo $jsName; ?>', <?php echo $price; ?>, <?php echo $stock; ?>)">
                    <?php echo htmlspecialchars($row['product_name']); ?> &#8369;<?php echo number_format($price, 2); ?>
                </button>
            <?php endwhile;
            else: ?>
                <p id="no-flavors-msg" style="color:#999; font-size:13px;">No active flavors available.</p>
            <?php endif; ?>
        </div>
        <div class="pos-top-row">
            <div class="pos-stock-info">
                <strong style="text-transform:uppercase; font-size:10px; color:#555; display:block; margin-bottom:4px;">Stock Availability:</strong>
                <?php
                if ($pl) {
                    $pl->data_seek(0);
                    while ($row = $pl->fetch_assoc()):
                        $s   = (int)$row['stock_quantity'];
                        $tag = '';
                        if      ($s === 0) $tag = "<span style='background:#4a5568;color:white;font-size:9px;padding:2px 5px;border-radius:4px;margin-left:6px;font-weight:bold;'>OUT OF STOCK</span>";
                        elseif  ($s < 20)  $tag = "<span style='background:#8b0000;color:white;font-size:9px;padding:2px 5px;border-radius:4px;margin-left:6px;font-weight:bold;'>CRITICAL</span>";
                        elseif  ($s < 50)  $tag = "<span style='background:#ff4500;color:white;font-size:9px;padding:2px 5px;border-radius:4px;margin-left:6px;font-weight:bold;'>VERY LOW</span>";
                        elseif  ($s < 100) $tag = "<span style='background:#ffaa00;color:black;font-size:9px;padding:2px 5px;border-radius:4px;margin-left:6px;font-weight:bold;'>LOW</span>";
                        echo htmlspecialchars($row['product_name']) . ": <strong>" . number_format($s) . "</strong>" . $tag . "<br>";
                    endwhile;
                }
                ?>
            </div>
            <button class="pos-clear-btn" onclick="clearAllProducts()">Clear All</button>
        </div>
        <div class="pos-table-wrap">
            <table class="pos-table" id="posTable">
                <thead>
                    <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th></tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="pos-footer">
            <strong id="grandTotal">Grand Total: &#8369;0.00</strong>
            <button class="pos-checkout-btn" id="checkoutBtn" onclick="checkoutPOS()">Checkout</button>
        </div>
        <div class="pos-keypad">
            <input type="text" id="qtyInput" placeholder="Quantity" readonly>
            <div class="calc-buttons">
                <button onclick="numPress(7)">7</button><button onclick="numPress(8)">8</button><button onclick="numPress(9)">9</button>
                <button onclick="numPress(4)">4</button><button onclick="numPress(5)">5</button><button onclick="numPress(6)">6</button>
                <button onclick="numPress(1)">1</button><button onclick="numPress(2)">2</button><button onclick="numPress(3)">3</button>
                <button onclick="numPress(0)" style="grid-column:span 2;">0</button>
                <button onclick="clearQty()">C</button>
            </div>
        </div>
    </div>
</div>

<!-- POS CLOSE WARNING -->
<div id="posWarningModal" class="pos-warning-modal" style="display:none;">
    <div class="pos-warning-container">
        <p>POS is still running.<br>Do you want to close and clear the cart?</p>
        <button class="pos-warn-btn" onclick="closePOSConfirmed()">Yes</button>
        <button class="pos-warn-btn" onclick="cancelClosePOS()">No</button>
    </div>
</div>

<!-- PAYMENT MODAL -->
<div id="paymentModal" class="pos-warning-modal" style="display:none;">
    <div class="pos-warning-container" style="width:320px;">
        <h3 style="font-family:sans-serif;margin-bottom:16px;font-size:18px;">Cash Payment</h3>
        <div style="text-align:left;margin-bottom:12px;">
            <p style="font-size:13px;color:#555;margin-bottom:6px;">Order Total:</p>
            <p id="pm_total_display" style="font-size:22px;font-weight:800;font-family:sans-serif;color:#38a169;"></p>
        </div>
        <div style="margin-bottom:16px;">
            <label style="font-size:13px;font-weight:600;color:#555;display:block;margin-bottom:6px;">Amount Received (₱):</label>
            <input type="number" id="pm_cash_input" min="0" step="0.01" placeholder="0.00"
                style="width:100%;padding:10px 12px;border:2px solid #555;border-radius:8px;font-size:18px;font-family:sans-serif;font-weight:700;text-align:center;">
        </div>
        <div id="pm_change_display" style="display:none;background:#f0fff4;border:2px solid #38a169;border-radius:8px;padding:10px;margin-bottom:16px;text-align:center;">
            <p style="font-size:12px;color:#555;">Change:</p>
            <p id="pm_change_amount" style="font-size:22px;font-weight:800;font-family:sans-serif;color:#38a169;"></p>
        </div>
        <div id="pm_insufficient" style="display:none;background:#fff5f5;border:2px solid #e53e3e;border-radius:8px;padding:8px;margin-bottom:12px;text-align:center;">
            <p style="font-size:12px;color:#e53e3e;font-weight:600;">⚠️ Insufficient payment amount</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="pos-warn-btn" onclick="cancelPayment()" style="flex:1;">Cancel</button>
            <button id="pm_confirm_btn" class="pos-warn-btn" onclick="confirmPayment()" style="flex:2;background:#38a169;color:white;border-color:#38a169;">Confirm & Print</button>
        </div>
    </div>
</div>

<script>
const PAGE_URL = window.location.pathname;

// ── AVATAR DROPDOWN ───────────────────────────────────────────
function toggleAvatarMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('avatarMenu');
    const btn  = document.getElementById('avatarDropdown').querySelector('.avatar-btn');
    const rect = btn.getBoundingClientRect();
    menu.style.top   = (rect.bottom + 6) + 'px';
    menu.style.left  = 'auto';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.classList.toggle('open');
}
document.addEventListener('click', function () { document.getElementById('avatarMenu').classList.remove('open'); });
document.getElementById('avatarMenu').addEventListener('click', function (e) { e.stopPropagation(); });

// ── ACCOUNT MODAL ─────────────────────────────────────────────
function openAccountModal(e) {
    if (e) e.preventDefault();
    document.getElementById('avatarMenu')?.classList.remove('open');
    document.getElementById('accountModal').style.display = 'flex';
}
function closeAccountModal() { document.getElementById('accountModal').style.display = 'none'; }
document.addEventListener('click', function(e) {
    const modal = document.getElementById('accountModal');
    if (modal && e.target === modal) modal.style.display = 'none';
});

// ── LIVE INVENTORY ────────────────────────────────────────────
function refreshInventory() {
    fetch(PAGE_URL + '?action=live_inventory')
        .then(r => r.json())
        .then(data => {
            const fc = document.getElementById('flavor-count');
            if (fc) fc.textContent = data.flavor_count;
            const container = document.getElementById('live-inventory-container');
            if (!container || !data.rows) return;
            let html = `<div style="background:#f7fafc;padding:8px;border-radius:6px;margin-bottom:12px;border:1px solid #edf2f7;text-align:center;">
                <span style="color:#718096;font-size:10px;text-transform:uppercase;">Stock Valuation</span><br>
                <strong style="color:#2d3748;font-size:15px;">${data.total_val}</strong>
            </div>`;
            data.rows.forEach(inv => {
                const qty = parseInt(inv.stock_quantity);
                const isOut = qty === 0;
                const isLow = !isOut && qty <= inv.minimum_stock;
                const statusColor = isOut ? '#4a5568' : (isLow ? '#e53e3e' : '#48bb78');
                const statusText  = isOut ? '🚫 Out of Stock' : (isLow ? '⚠️ Low Stock' : '✅ In Stock');
                const rowBg    = isOut ? 'background:#f8f8f8;' : '';
                const txtColor = isOut ? '#a0aec0' : '#333';
                html += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding:6px 4px 6px 8px;border-bottom:1px solid #f9f9f9;border-radius:4px;${rowBg}">
                    <div style="display:flex;flex-direction:column;">
                        <span style="font-weight:600;color:${txtColor};">${inv.product_name}</span>
                        <span style="font-size:10px;color:${statusColor};">${statusText}</span>
                    </div>
                    <div style="text-align:right;">
                        <strong style="font-size:14px;color:${txtColor};">${qty.toLocaleString('en-US')}</strong>
                        <span style="color:#999;font-size:10px;"> packs</span>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        })
        .catch(err => console.warn('Inventory refresh error:', err));
}

// ── TOP SELLERS DETAIL (real-time) ───────────────────────────
function loadTopSellers(period, silent = false) {
    const tbody = document.getElementById('topSellersTable');
    if (!silent) tbody.innerHTML = `<tr><td colspan="2" style="padding:16px;text-align:center;color:#999;font-size:13px;">Loading...</td></tr>`;
    fetch(PAGE_URL + '?action=top_sellers&period=' + period)
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                tbody.innerHTML = `<tr><td colspan="2" style="padding:12px 0;color:#999;font-size:13px;text-align:center;">No sales data for this period.</td></tr>`;
                return;
            }
            const rankColors = ['#d69e2e','#718096','#c05621'];
            tbody.innerHTML = data.map((row, i) => {
                const rankColor = rankColors[i] ?? '#a0aec0';
                return `<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 0;text-align:left;font-size:14px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:${rankColor};color:#fff;font-size:11px;font-weight:700;flex-shrink:0;">${i + 1}</span>
                            <div>
                                <div style="font-weight:bold;">${row.product_name}</div>
                                <div style="font-size:11px;color:#888;">${row.sold_quantity.toLocaleString()} units sold</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding:12px 0;text-align:right;vertical-align:middle;">
                        <span style="color:#48bb78;font-weight:600;font-size:14px;">${row.total_earned}</span>
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(() => {
            tbody.innerHTML = `<tr><td colspan="2" style="padding:12px 0;color:#e53e3e;font-size:13px;text-align:center;">Failed to load data.</td></tr>`;
        });
}

// ── TOP SELLERS CHART + TOTAL SALES (real-time) ─────────────
function refreshTopSellersChart() {
    fetch(PAGE_URL + '?action=top_sellers_chart')
        .then(r => r.json())
        .then(data => {
            // Update Total Sales card
            const salesEl = document.getElementById('totalSalesValue');
            if (salesEl && data.total_sales) salesEl.innerHTML = data.total_sales;
            // Update donut chart
            const donut  = document.getElementById('chartDonut');
            const total  = document.getElementById('chartTotal');
            const legend = document.getElementById('chartLegend');
            if (donut && data.gradient) {
                donut.style.background = 'conic-gradient(' + data.gradient + ')';
            }
            if (total) total.textContent = data.grand_total;
            if (legend && data.legend) {
                legend.innerHTML = data.legend.map(item => `
                    <li class="legend-item" style="margin-bottom:10px;">
                        <span class="dot" style="background:${item.color};width:12px;height:12px;flex-shrink:0;border:1px solid #555;"></span>
                        <div style="display:flex;flex-direction:column;">
                            <span style="font-weight:600;color:#444;font-size:13px;line-height:1.1;">${item.name}</span>
                            <span style="color:#999;font-size:11px;margin-top:2px;">${item.pct}%</span>
                        </div>
                    </li>`).join('');
            }
        })
        .catch(err => console.warn('Chart refresh error:', err));
}

// ── UNIFIED 5-SECOND REFRESH LOOP ────────────────────────────
function runAllRefreshes(silent) {
    refreshInventory();
    loadTopSellers(document.getElementById('topSellersPeriod')?.value || 'monthly', silent);
    refreshTopSellersChart();
}
// Run immediately on page load (not silent so initial data shows)
runAllRefreshes(false);
// Then poll every 5 seconds silently (no loading flicker)
setInterval(() => runAllRefreshes(true), 5000);

// ── LOW STOCK PANEL ───────────────────────────────────────────
function toggleLowStock() { document.getElementById('lowStockPanel').classList.toggle('active'); }

// ── POS HELPERS ───────────────────────────────────────────────
let qty = '';
function numPress(n) { if (qty.length >= 6) return; qty += n; document.getElementById('qtyInput').value = qty; }
function clearQty()  { qty = ''; document.getElementById('qtyInput').value = ''; }

function showPOSLimitError(productName) {
    const modal = document.getElementById('posLimitModal');
    const msg   = document.getElementById('posLimitMsg');
    if (productName) msg.textContent = '"' + productName + '" is limited to 10 packs per customer in POS.';
    else             msg.textContent = 'Each item is limited to 10 packs per customer in POS.';
    modal.style.display = 'flex';
}
function closePOSLimitModal() {
    document.getElementById('posLimitModal').style.display = 'none';
}
function goToBulkOrder() {
    closePOSLimitModal();
    closePOS();
    if (typeof showPanel === 'function') showPanel('sales');
}

function openPOS() { document.getElementById('posModal').classList.add('open'); calculateGrandTotal(); refreshPOSButtons(); }
function closePOS() { document.getElementById('posModal').classList.remove('open'); clearQty(); clearAllProducts(); }

function addProduct(name, price, stock) {
    const tbody     = document.querySelector('#posTable tbody');
    const keypadQty = parseInt(document.getElementById('qtyInput').value) || 1;
    const MAX_QTY   = 10;
    let existing    = null;
    tbody.querySelectorAll('tr').forEach(r => { if (r.dataset.productName === name) existing = r; });
    if (existing) {
        const qi = existing.querySelector('.row-qty');
        const nq = parseInt(qi.value) + keypadQty;
        if (nq > stock) { alert('⚠️ Only ' + stock + ' pack(s) available for ' + name); clearQty(); return; }
        if (nq > MAX_QTY) {
            showPOSLimitError(name);
            clearQty(); return;
        }
        qi.value = nq;
        existing.querySelector('.row-total').innerText = '₱' + formatMoney(nq * price);
    } else {
        if (keypadQty > stock) { alert('⚠️ Only ' + stock + ' pack(s) available for ' + name); clearQty(); return; }
        if (keypadQty > MAX_QTY) {
            showPOSLimitError(name);
            clearQty(); return;
        }
        const row = tbody.insertRow();
        row.dataset.productName = name;
        row.innerHTML =
            '<td>' + name + '</td>' +
            '<td><input type="number" class="row-qty" value="' + keypadQty + '" min="1" max="' + Math.min(stock, MAX_QTY) + '" ' +
            'oninput="updateRowTotal(this,' + price + ',' + stock + ')" style="width:54px;border:1px solid #ccc;border-radius:4px;text-align:center;padding:4px;"></td>' +
            '<td>&#8369;' + formatMoney(price) + '</td>' +
            '<td class="row-total">&#8369;' + formatMoney(keypadQty * price) + '</td>' +
            '<td><button onclick="removeRow(this)" style="background:#ff6b6b;color:white;border:none;padding:5px 10px;border-radius:5px;cursor:pointer;font-weight:bold;">✕</button></td>';
    }
    clearQty(); calculateGrandTotal();
}

function updateRowTotal(input, price, stock) {
    const MAX_QTY = 10;
    let q = parseInt(input.value) || 0;
    if (q > stock) { input.value = stock; q = stock; }
    if (q > MAX_QTY) {
        input.value = MAX_QTY; q = MAX_QTY;
        showPOSLimitError(input.closest('tr').dataset.productName || '');
    }
    if (q < 1) { input.value = 1; q = 1; }
    input.closest('tr').querySelector('.row-total').innerText = '₱' + formatMoney(q * price);
    calculateGrandTotal();
}

function calculateGrandTotal() {
    let total = 0;
    document.querySelectorAll('.row-total').forEach(c => { total += parseFloat(c.innerText.replace(/[₱,]/g, '')) || 0; });
    document.getElementById('grandTotal').innerText = 'Grand Total: ₱' + formatMoney(total);
}

function removeRow(btn)     { btn.closest('tr').remove(); calculateGrandTotal(); }
function clearAllProducts() { document.querySelector('#posTable tbody').innerHTML = ''; calculateGrandTotal(); }

// ── CHECKOUT ──────────────────────────────────────────────────
let _checkoutTotal = 0;

function formatMoney(n) {
    return parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function checkoutPOS() {
    const tbody = document.querySelector('#posTable tbody');
    if (!tbody.children.length) { alert('Your cart is empty!'); return; }
    let total = 0;
    document.querySelectorAll('.row-total').forEach(c => { total += parseFloat(c.innerText.replace(/[₱,]/g, '')) || 0; });
    _checkoutTotal = total;
    document.getElementById('pm_total_display').textContent = '₱' + formatMoney(total);
    document.getElementById('pm_cash_input').value = '';
    document.getElementById('pm_change_display').style.display = 'none';
    document.getElementById('pm_insufficient').style.display  = 'none';
    document.getElementById('paymentModal').style.display = 'flex';
    setTimeout(() => document.getElementById('pm_cash_input').focus(), 100);
}

document.addEventListener('DOMContentLoaded', function () {
    const cashInput = document.getElementById('pm_cash_input');
    if (!cashInput) return;
    cashInput.addEventListener('input', function () {
        const paid   = parseFloat(this.value) || 0;
        const change = paid - _checkoutTotal;
        const changeDiv  = document.getElementById('pm_change_display');
        const insuffDiv  = document.getElementById('pm_insufficient');
        const confirmBtn = document.getElementById('pm_confirm_btn');
        if (this.value === '') { changeDiv.style.display = 'none'; insuffDiv.style.display = 'none'; confirmBtn.disabled = false; return; }
        if (paid >= _checkoutTotal) {
            document.getElementById('pm_change_amount').textContent = '₱' + formatMoney(change);
            changeDiv.style.display = 'block'; insuffDiv.style.display = 'none'; confirmBtn.disabled = false;
        } else {
            changeDiv.style.display = 'none'; insuffDiv.style.display = 'block'; confirmBtn.disabled = true;
        }
    });
});

function cancelPayment() { document.getElementById('paymentModal').style.display = 'none'; }

function confirmPayment() {
    const payment = parseFloat(document.getElementById('pm_cash_input').value) || 0;
    if (payment < _checkoutTotal) { document.getElementById('pm_insufficient').style.display = 'block'; return; }
    const change = payment - _checkoutTotal;
    document.getElementById('paymentModal').style.display = 'none';
    const btn = document.getElementById('checkoutBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }
    const tbody = document.querySelector('#posTable tbody');
    const cartItems = [], tableData = [];
    tbody.querySelectorAll('tr').forEach(row => {
        const name     = row.dataset.productName || row.children[0].innerText.trim();
        const itemQty  = parseInt(row.querySelector('.row-qty').value) || 1;
        const price    = parseFloat(row.children[2].innerText.replace(/[₱,]/g, '').trim()) || 0;
        const subtotal = parseFloat(row.children[3].innerText.replace(/[₱,]/g, '').trim()) || 0;
        cartItems.push({ name, qty: itemQty });
        tableData.push({ name, qty: itemQty, price, subtotal });
    });

    const receiptNo  = 'RCP-' + Date.now().toString().slice(-8);
    const now        = new Date();
    const dateStr    = now.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
    const timeStr    = now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
    const totalItems = tableData.reduce((s, i) => s + i.qty, 0);

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    const pW  = 210; let y = 15;

    function cText(text, size, bold = false) {
        doc.setFontSize(size); doc.setFont('helvetica', bold ? 'bold' : 'normal');
        doc.text(text, pW / 2, y, { align: 'center' }); y += size * 0.5;
    }
    function lrText(left, right, size = 10, boldR = false) {
        doc.setFontSize(size); doc.setFont('helvetica', 'normal');
        doc.text(String(left), 20, y);
        doc.setFont('helvetica', boldR ? 'bold' : 'normal');
        doc.text(String(right), pW - 20, y, { align: 'right' }); y += size * 0.5 + 1;
    }
    function solidLine()  { y += 2; doc.setLineWidth(0.5); doc.setLineDashPattern([], 0);    doc.line(20, y, pW-20, y); y += 5; }
    function dashedLine() { y += 2; doc.setLineWidth(0.3); doc.setLineDashPattern([2,2], 0); doc.line(20, y, pW-20, y); doc.setLineDashPattern([], 0); y += 5; }

    cText("DENVER'S SIOMAI", 20, true); y += 2;
    cText('Fresh & Delicious Siomai', 10); y += 1;
    cText('Tel: 0900-000-0000', 9);
    solidLine();
    lrText('Receipt No:', receiptNo, 9);
    lrText('Date:', dateStr, 9);
    lrText('Time:', timeStr, 9);
    dashedLine();

    doc.setFontSize(9); doc.setFont('helvetica', 'bold');
    doc.text('ITEM', 20, y); doc.text('QTY', 115, y, { align:'center' });
    doc.text('PRICE', 148, y, { align:'center' }); doc.text('SUBTOTAL', pW-20, y, { align:'right' });
    y += 5; dashedLine();

    doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
    tableData.forEach(item => {
        const lines = doc.splitTextToSize(String(item.name), 85);
        doc.text(lines, 20, y);
        doc.text(String(item.qty),                 115,   y, { align:'center' });
        doc.text('P' + formatMoney(item.price),    148,   y, { align:'center' });
        doc.text('P' + formatMoney(item.subtotal), pW-20, y, { align:'right' });
        y += lines.length > 1 ? lines.length * 5 : 7;
    });
    solidLine();

    lrText('Subtotal:', 'P' + formatMoney(_checkoutTotal), 10);
    lrText('Discount:', 'P0.00', 10);
    lrText('VAT (0%):', 'P0.00', 10); y += 2;

    doc.setFillColor(230,230,230); doc.roundedRect(20, y-2, pW-40, 12, 2, 2, 'F');
    doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('TOTAL:', 25, y+6); doc.text('P' + formatMoney(_checkoutTotal), pW-25, y+6, { align:'right' }); y += 16;
    dashedLine();

    lrText('Cash Payment:', 'P' + formatMoney(payment), 11, true); y += 2;

    doc.setFillColor(198,246,213); doc.roundedRect(20, y-2, pW-40, 12, 2, 2, 'F');
    doc.setFontSize(14); doc.setFont('helvetica','bold');
    doc.text('CHANGE:', 25, y+6); doc.text('P' + formatMoney(change), pW-25, y+6, { align:'right' }); y += 16;
    solidLine();

    lrText('Total Items Sold:',    totalItems.toLocaleString('en-US') + ' pc(s)', 9);
    lrText('Total Product Lines:', tableData.length + ' item(s)', 9);
    dashedLine(); y += 2;

    cText('Thank you for your purchase!', 12, true); y += 3;
    cText('Please come back again!', 9); y += 6;
    cText('*** NOT AN OFFICIAL RECEIPT ***', 8); y += 2;
    cText('This is a system-generated receipt only.', 7);

    try {
        doc.save('Denver_Receipt_' + receiptNo + '.pdf');
    } catch (pdfErr) {
        alert('PDF could not be saved: ' + pdfErr.message);
        if (btn) { btn.disabled = false; btn.textContent = 'Checkout'; }
        return;
    }

    fetch('process_pos.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(cartItems) })
    .then(r => r.text().then(text => { try { return JSON.parse(text); } catch(e) { return { success:true }; } }))
    .then(data => {
        if (data.success) {
            alert('Checkout Successful!\n\nChange: P' + formatMoney(change));
        } else {
            alert('Receipt saved!\n\nServer warning: ' + (data.message || 'Unknown') + '\n\nChange: P' + change.toFixed(2));
        }
        refreshLowStockUI();
        location.reload();
    })
    .catch(() => {
        alert('Receipt saved!\n\nServer sync failed. Check stock manually.\n\nChange: P' + change.toFixed(2));
        refreshLowStockUI();
        location.reload();
    });
}

// ── LOW STOCK UI REFRESH AFTER SALE ──────────────────────────
function refreshLowStockUI() {
    fetch(PAGE_URL + '?action=live_inventory')
        .then(r => r.json())
        .then(data => {
            if (!data.rows) return;
            const lowItems = data.rows.filter(inv => parseInt(inv.stock_quantity) <= parseInt(inv.minimum_stock));
            const lowCount = lowItems.length;

            const bellBtn = document.querySelector('.bell-btn');
            if (bellBtn) {
                let badge = bellBtn.querySelector('.badge');
                if (lowCount > 0) {
                    if (!badge) { badge = document.createElement('span'); badge.className = 'badge'; bellBtn.appendChild(badge); }
                    badge.textContent = lowCount;
                } else { if (badge) badge.remove(); }
            }

            const main = document.querySelector('.main');
            let banner = document.querySelector('.low-stock-banner');
            if (lowCount > 0) {
                if (!banner) {
                    banner = document.createElement('div'); banner.className = 'low-stock-banner';
                    const header = document.querySelector('.header');
                    if (header && header.nextSibling) main.insertBefore(banner, header.nextSibling);
                }
                banner.innerHTML = `⚠️ Warning: There are ${lowCount} items running low on stock!`;
            } else { if (banner) banner.remove(); }

            const panel = document.getElementById('lowStockPanel');
            if (panel) {
                const hr = panel.querySelector('hr');
                while (hr && hr.nextSibling) hr.nextSibling.remove();
                if (lowItems.length > 0) {
                    lowItems.forEach(inv => {
                        const qty = parseInt(inv.stock_quantity); const isOut = qty === 0;
                        const div = document.createElement('div');
                        div.style.cssText = 'padding:10px;border-bottom:1px solid #eee;';
                        div.innerHTML = `<strong>${inv.product_name}</strong><br>
                            <span style="color:${isOut ? '#4a5568' : 'red'};font-weight:bold;">${isOut ? 'Out of Stock' : qty + ' items left'}</span>`;
                        panel.appendChild(div);
                    });
                } else {
                    const div = document.createElement('div');
                    div.style.cssText = 'padding:10px;color:#999;text-align:center;';
                    div.textContent = 'All stock levels are normal.';
                    panel.appendChild(div);
                }
            }
        })
        .catch(err => console.warn('refreshLowStockUI error:', err));
}

// ── POS MODAL CONTROLS ────────────────────────────────────────
function confirmClosePOS() {
    const tbody = document.querySelector('#posTable tbody');
    tbody.children.length > 0 ? (document.getElementById('posWarningModal').style.display = 'flex') : closePOS();
}
function closePOSConfirmed() { document.getElementById('posWarningModal').style.display = 'none'; closePOS(); }
function cancelClosePOS()    { document.getElementById('posWarningModal').style.display = 'none'; }

function refreshPOSButtons() {
    fetch(PAGE_URL + '?action=get_active_buttons')
        .then(r => r.text())
        .then(html => { const g = document.getElementById('pos-product-grid'); if (g) g.innerHTML = html; })
        .catch(err => console.warn('POS button refresh failed:', err));
}

// ── POS DRAG ──────────────────────────────────────────────────
(function () {
    const container = document.querySelector('.pos-container');
    const handle    = document.querySelector('.pos-drag-handle');
    if (!container || !handle) return;
    let dragging = false, sx, sy, ox = 0, oy = 0;
    const isMobile = () => window.innerWidth < 768;
    function getTranslate() {
        try { const m = new DOMMatrixReadOnly(window.getComputedStyle(container).transform); return { x: m.m41, y: m.m42 }; }
        catch (e) { return { x: 0, y: 0 }; }
    }
    handle.addEventListener('mousedown', e => {
        if (isMobile()) return;
        dragging = true; sx = e.clientX; sy = e.clientY;
        const t = getTranslate(); ox = t.x; oy = t.y; e.preventDefault();
    });
    document.addEventListener('mousemove', e => { if (!dragging) return; container.style.transform = `translate(${ox + e.clientX - sx}px, ${oy + e.clientY - sy}px)`; });
    document.addEventListener('mouseup', () => { dragging = false; });
    window.addEventListener('resize', () => { if (isMobile()) { container.style.transform = ''; ox = 0; oy = 0; } });
})();
</script>

<script src="../script/categories.js"></script>

<script>
    (function() {
        const h = new Date().getHours();
        let greet;
        if      (h >= 5  && h < 12) greet = 'Good Morning';
        else if (h >= 12 && h < 17) greet = 'Good Afternoon';
        else if (h >= 17 && h < 21) greet = 'Good Evening';
        else                         greet = 'Good Night';
        document.getElementById('greeting-text').textContent = greet + '!';
    })();

    function updateClock() {
        const now  = new Date();
        let   hh   = now.getHours();
        const mm   = String(now.getMinutes()).padStart(2, '0');
        const ss   = String(now.getSeconds()).padStart(2, '0');
        const ampm = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12 || 12;
        hh = String(hh).padStart(2, '0');
        document.getElementById('live-clock').textContent = hh + ':' + mm + ':' + ss + ' ' + ampm;
    }
    updateClock();
    setInterval(updateClock, 1000);

    function togglePassword(fieldId) {
        const passwordField = document.getElementById(fieldId);
        const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordField.setAttribute('type', type);
    }
</script>
</body>
</html>