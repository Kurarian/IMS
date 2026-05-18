<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("localhost", "root", "", "dbview");
} catch (Exception $e) {
    die("Connection Failed: " . $e->getMessage());
}

$currentUser = $_SESSION['Username'] ?? 'Unknown';
$currentRole = $_SESSION['Role'] ?? 'Unknown';

// Ensure expiry batches table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_expiry_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    product_id INT DEFAULT 0,
    qty INT NOT NULL,
    expiry_date DATE NOT NULL,
    date_added DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// -------------------- API: GET --------------------
$getAction = $_GET['action'] ?? '';
if ($getAction === 'getProducts') {
    header('Content-Type: application/json');
    $result = $conn->query("SELECT product_id AS id, product_name AS name FROM tbl_products WHERE status != 'disabled'");
    $products = [];
    while ($row = $result->fetch_assoc()) $products[] = $row;
    echo json_encode($products);
    exit;
}

// -------------------- API: POST --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'add_stock') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantity  = (int)($_POST['quantity']   ?? 0);
            if ($productId <= 0 || $quantity <= 0) { echo "error:invalid_input"; exit; }
            $stmt = $conn->prepare("UPDATE tbl_products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
            $stmt->bind_param("ii", $quantity, $productId);
            $stmt->execute(); $stmt->close();
            // ── AUTO-CREATE expiry batch (+7 days) ──
            $pname = '';
            $pr = $conn->prepare("SELECT product_name FROM tbl_products WHERE product_id=?");
            $pr->bind_param("i", $productId); $pr->execute();
            $pr->bind_result($pname); $pr->fetch(); $pr->close();
            if ($pname) {
                $expiry = date('Y-m-d', strtotime('+7 days'));
                $es = $conn->prepare("INSERT INTO tbl_expiry_batches (item_name, product_id, qty, expiry_date, date_added) VALUES (?,?,?,?,NOW())");
                if ($es) {
                    $es->bind_param("siis", $pname, $productId, $quantity, $expiry);
                    $es->execute(); $es->close();
                }
            }
            $activity = "Added $quantity stock to Product ID $productId";
            $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?, ?, 'Stock Management', ?, 'Success')");
            $audit->bind_param("sss", $currentUser, $currentRole, $activity);
            $audit->execute(); $audit->close();
            echo "success"; exit;
        }

        if ($action === 'add') {
            $flavorName = trim($_POST['flavorName'] ?? '');
            $price = max(0, (float)($_POST['flavorPrice'] ?? 0));
            $stock = max(0, (int)($_POST['flavorStock']   ?? 0));
            if ($flavorName === '') { echo "error:invalid_name"; exit; }
            $chk = $conn->prepare("SELECT product_id FROM tbl_products WHERE product_name = ?");
            $chk->bind_param("s", $flavorName); $chk->execute(); $chk->store_result();
            if ($chk->num_rows > 0) { echo "error:exists"; $chk->close(); exit; }
            $chk->close();
            $stmt = $conn->prepare("INSERT INTO tbl_products (product_name, category, price, stock_quantity, status) VALUES (?, 'Siomai', ?, ?, 'active')");
            $stmt->bind_param("sdi", $flavorName, $price, $stock);
            $stmt->execute();
            $newProductId = (int)$conn->insert_id;
            $stmt->close();
            // ── AUTO-CREATE expiry batch for initial stock ──
            if ($stock > 0 && $newProductId > 0) {
                $expiry = date('Y-m-d', strtotime('+7 days'));
                $es = $conn->prepare("INSERT INTO tbl_expiry_batches (item_name, product_id, qty, expiry_date, date_added) VALUES (?,?,?,?,NOW())");
                if ($es) { $es->bind_param("siis", $flavorName, $newProductId, $stock, $expiry); $es->execute(); $es->close(); }
            }
            $activity = "New flavor '$flavorName' added";
            $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?, ?, 'Stock Management', ?, 'Success')");
            $audit->bind_param("sss", $currentUser, $currentRole, $activity);
            $audit->execute(); $audit->close();
            echo "success"; exit;
        }

        if ($action === 'disable' || $action === 'enable') {
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) { echo "error:invalid_input"; exit; }
            $newStatus = ($action === 'enable') ? 'active' : 'disabled';
            $stmt = $conn->prepare("UPDATE tbl_products SET status = ? WHERE product_id = ?");
            $stmt->bind_param("si", $newStatus, $productId);
            $stmt->execute(); $stmt->close();
            $activity = "Product ID $productId has been {$action}d";
            $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?, ?, 'Stock Management', ?, 'Success')");
            $audit->bind_param("sss", $currentUser, $currentRole, $activity);
            $audit->execute(); $audit->close();
            echo "success"; exit;
        }

        if ($action === 'price') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $newPrice  = max(0, (float)($_POST['newPrice'] ?? 0));
            if ($productId <= 0) { echo "error:invalid_input"; exit; }
            $stmt = $conn->prepare("UPDATE tbl_products SET price = ? WHERE product_id = ?");
            $stmt->bind_param("di", $newPrice, $productId);
            $stmt->execute(); $stmt->close();
            $activity = "Price changed to P$newPrice for Product ID $productId";
            $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?, ?, 'Stock Management', ?, 'Success')");
            $audit->bind_param("sss", $currentUser, $currentRole, $activity);
            $audit->execute(); $audit->close();
            echo "success"; exit;
        }

    } catch (Exception $e) {
        echo "error:" . $e->getMessage();
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Flavor Management</title>
    <link rel="stylesheet" href="../styles/Flavor.css">
    <style>
        :root {
            --clr-bg:       #f4f5f7;
            --clr-surface:  #ffffff;
            --clr-border:   #e2e8f0;
            --clr-text:     #1a202c;
            --clr-muted:    #718096;
            --clr-green:    #38a169;
            --clr-green-lt: #c6f6d5;
            --clr-blue:     #3182ce;
            --clr-blue-lt:  #bee3f8;
            --clr-red:      #e53e3e;
            --clr-red-lt:   #fed7d7;
            --clr-amber:    #d69e2e;
            --clr-amber-lt: #fefcbf;
            --clr-gray:     #4a5568;
            --clr-gray-lt:  #edf2f7;
            --radius:       10px;
            --shadow-sm:    0 1px 3px rgba(0,0,0,.08);
            --shadow-md:    0 4px 12px rgba(0,0,0,.10);
            --shadow-lg:    0 10px 30px rgba(0,0,0,.12);
            --t:            0.2s ease;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; background: var(--clr-bg); color: var(--clr-text); }

        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 10px 18px; border: none; border-radius: var(--radius);
            font-family: sans-serif; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: filter var(--t), transform var(--t), box-shadow var(--t);
        }
        .btn:hover  { filter: brightness(1.08); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn:active { transform: translateY(0); filter: brightness(.96); }
        .btn-green  { background: var(--clr-green);  color: #fff; }
        .btn-blue   { background: var(--clr-blue);   color: #fff; }
        .btn-red    { background: var(--clr-red);     color: #fff; }
        .btn-gray   { background: var(--clr-gray);   color: #fff; }
        .btn-ghost  { background: var(--clr-gray-lt); color: var(--clr-gray); }
        .btn-sm     { padding: 7px 13px; font-size: 13px; }

        .action-header {
            display: flex; justify-content: flex-end; align-items: center;
            gap: 10px; margin-bottom: 24px; padding: 0 4px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 18px;
        }
        .flavor-card {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-sm);
            display: flex; flex-direction: column; gap: 10px;
            transition: box-shadow var(--t), transform var(--t);
            animation: cardIn .3s ease both;
        }
        .flavor-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .flavor-card h2 { font-family: sans-serif; font-size: 16px; font-weight: 700; color: var(--clr-text); line-height: 1.3; }
        .flavor-card .meta { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--clr-muted); font-family: sans-serif; }
        .flavor-card .meta strong { color: var(--clr-text); }
        .stock-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 20px;
            font-size: 12px; font-weight: 600; width: fit-content;
        }
        .stock-badge.ok  { background: var(--clr-green-lt); color: var(--clr-green); }
        .stock-badge.low { background: var(--clr-amber-lt); color: var(--clr-amber); }
        .stock-badge.out { background: var(--clr-red-lt);   color: var(--clr-red);   }
        .card-actions { display: flex; flex-direction: column; gap: 6px; margin-top: auto; padding-top: 10px; border-top: 1px solid var(--clr-border); }
        .card-actions .btn { justify-content: center; width: 100%; }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--clr-muted); font-size: 14px; grid-column: 1/-1; font-family: sans-serif; }
        .empty-state svg { margin-bottom: 12px; opacity: .35; display: block; margin-inline: auto; }

        .side-panel {
            position: fixed; top: 0; right: -420px; width: 380px; height: 100%;
            background: var(--clr-surface); box-shadow: -6px 0 24px rgba(0,0,0,.12);
            z-index: 99998; transition: right .3s cubic-bezier(.4,0,.2,1);
            display: flex; flex-direction: column;
        }
        .side-panel.active { right: 0; }
        .panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 22px 16px; border-bottom: 1px solid var(--clr-border);
            position: sticky; top: 0; background: var(--clr-surface); z-index: 1;
        }
        .panel-header h3 { font-family: sans-serif; font-size: 17px; font-weight: 700; }
        .panel-close {
            width: 32px; height: 32px; border: none; background: var(--clr-gray-lt);
            border-radius: 50%; cursor: pointer; font-size: 18px;
            display: flex; align-items: center; justify-content: center;
            color: var(--clr-gray); transition: background var(--t);
        }
        .panel-close:hover { background: var(--clr-border); }
        .panel-body { overflow-y: auto; flex: 1; padding: 16px 22px; }
        .panel-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none; z-index: 99997; backdrop-filter: blur(2px); }
        .panel-overlay.active { display: block; }
        .dis-card {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; padding: 12px 14px; background: var(--clr-bg);
            border: 1px solid var(--clr-border); border-radius: var(--radius);
            margin-bottom: 8px; animation: cardIn .25s ease both;
        }
        .dis-card span { font-size: 14px; font-weight: 500; color: var(--clr-text); flex: 1; font-family: sans-serif; }

        .fm-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(3px); }
        .fm-modal.open { display: flex; }
        .fm-modal-inner {
            background: var(--clr-surface); border-radius: 14px;
            padding: 28px 28px 24px; width: 360px; max-width: 95vw;
            position: relative; box-shadow: var(--shadow-lg); animation: modalIn .22s ease both;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(.94) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .fm-modal-inner h2 { font-family: sans-serif; font-size: 20px; font-weight: 700; margin-bottom: 18px; color: var(--clr-text); }
        .fm-close {
            position: absolute; top: 14px; right: 16px; background: var(--clr-gray-lt);
            border: none; border-radius: 50%; width: 30px; height: 30px; font-size: 18px;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            color: var(--clr-gray); transition: background var(--t);
        }
        .fm-close:hover { background: var(--clr-border); }
        .fm-modal .fg { margin-bottom: 14px; }
        .fm-modal label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: var(--clr-gray); font-family: sans-serif; }
        .fm-modal input,
        .fm-modal select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--clr-border);
            border-radius: 8px; font-family: sans-serif; font-size: 14px;
            color: var(--clr-text); background: var(--clr-bg);
            transition: border-color var(--t), box-shadow var(--t);
        }
        .fm-modal input:focus,
        .fm-modal select:focus { outline: none; border-color: var(--clr-blue); box-shadow: 0 0 0 3px var(--clr-blue-lt); }
        .fm-modal .btn { width: 100%; justify-content: center; margin-top: 6px; padding: 12px; font-size: 15px; }
        .fm-hint { font-size: 12px; color: var(--clr-muted); margin-top: 4px; font-family: sans-serif; }

        #flavorToast {
            position: fixed; bottom: 28px; right: 28px; color: #fff;
            padding: 13px 22px; border-radius: 10px; font-family: sans-serif;
            font-size: 14px; font-weight: 500; z-index: 999999; display: none;
            box-shadow: var(--shadow-lg); opacity: 0; transform: translateY(10px);
            transition: opacity .25s, transform .25s; max-width: 320px;
        }
        #flavorToast.show    { display: block; opacity: 1; transform: translateY(0); }
        #flavorToast.hide    { opacity: 0; transform: translateY(10px); }
        #flavorToast.success { background: var(--clr-green); }
        #flavorToast.error   { background: var(--clr-red); }
    </style>
</head>
<body>

<div id="flavorToast"></div>

<!-- MODAL: RESTOCK -->
<div id="stockModal" class="fm-modal" role="dialog" aria-modal="true">
    <div class="fm-modal-inner">
        <button class="fm-close" onclick="closeAnyModal('stockModal')" aria-label="Close">&times;</button>
        <h2>Restock Flavor</h2>
        <p style="font-size:13px;color:var(--clr-muted);margin-bottom:16px;font-family:sans-serif;">
            Adding stock for: <strong id="sm_flavor_name" style="color:var(--clr-text);"></strong>
        </p>
        <input type="hidden" id="sm_id">
        <div class="fg">
            <label>Quantity to Add</label>
            <input type="number" id="sm_qty" min="1" placeholder="e.g. 50">
        </div>
        <button class="btn btn-green" onclick="submitStockForm()">Update Inventory</button>
    </div>
</div>

<!-- MODAL: CHANGE PRICE -->
<div id="priceModal" class="fm-modal" role="dialog" aria-modal="true">
    <div class="fm-modal-inner">
        <button class="fm-close" onclick="closeAnyModal('priceModal')" aria-label="Close">&times;</button>
        <h2>Update Price</h2>
        <input type="hidden" id="pm_id">
        <div class="fg">
            <label>Flavor</label>
            <p id="pm_flavor_name" style="font-size:14px;font-weight:600;padding:6px 0;color:var(--clr-text);font-family:sans-serif;"></p>
        </div>
        <div class="fg">
            <label>Select New Price (&#8369;)</label>
            <select id="pm_select">
                <option value="">-- Choose Price --</option>
                <option value="105">&#8369; 105.00</option>
                <option value="110">&#8369; 110.00</option>
                <option value="115">&#8369; 115.00</option>
                <option value="120">&#8369; 120.00</option>
                <option value="125">&#8369; 125.00</option>
                <option value="130">&#8369; 130.00</option>
                <option value="CUSTOM" style="color:var(--clr-blue);font-weight:600;">+ Custom Price...</option>
            </select>
        </div>
        <button class="btn btn-blue" onclick="submitPriceForm()">Apply Changes</button>
    </div>
</div>

<!-- MODAL: ADD FLAVOR -->
<div id="addModal" class="fm-modal" role="dialog" aria-modal="true">
    <div class="fm-modal-inner">
        <button class="fm-close" onclick="closeAnyModal('addModal')" aria-label="Close">&times;</button>
        <h2>Add New Flavor</h2>
        <div class="fg">
            <label>Quick-fill from existing</label>
            <select id="add_flavorSelect" onchange="handleNewSelection(this)">
                <option value="">-- Choose or type new below --</option>
                <?php
                $opts = $conn->query("SELECT DISTINCT product_name FROM tbl_products WHERE status='active' ORDER BY product_name");
                while ($o = $opts->fetch_assoc()):
                ?>
                    <option value="<?= htmlspecialchars($o['product_name']) ?>"><?= htmlspecialchars($o['product_name']) ?></option>
                <?php endwhile; ?>
                <option value="NEW_OPT" style="color:var(--clr-green);font-weight:600;">+ Type New Flavor...</option>
            </select>
            <p class="fm-hint">Selecting fills the name below; you can still edit it.</p>
        </div>
        <div class="fg">
            <label>Flavor Name</label>
            <input type="text" id="add_flavorName" placeholder="e.g. Spicy Garlic" required>
        </div>
        <div class="fg">
            <label>Price (&#8369;)</label>
            <input type="number" id="add_price" step="0.01" min="0" placeholder="e.g. 110.00" required>
        </div>
        <div class="fg">
            <label>Initial Stock</label>
            <input type="number" id="add_stock" min="0" placeholder="e.g. 100" required>
        </div>
        <button class="btn btn-green" onclick="submitAddForm()">Add Flavor</button>
    </div>
</div>

<!-- MAIN -->
<div class="container">
    <div class="main">
        <div class="action-header">
            <button type="button" class="btn btn-gray" onclick="toggleDisabledPanel(true)">Manage Disabled</button>
            <button type="button" class="btn btn-green" onclick="openAnyModal('addModal')">+ Add Flavor</button>
        </div>

        <div class="product-wrapper">
            <div class="products-grid" id="productsGrid">
                <?php
                $result = $conn->query("SELECT * FROM tbl_products WHERE status != 'disabled' ORDER BY product_name");
                $rows = [];
                while ($row = $result->fetch_assoc()) $rows[] = $row;

                if (empty($rows)):
                ?>
                    <div class="empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
                        <p>No active flavors yet. Add one to get started!</p>
                    </div>
                <?php else: foreach ($rows as $row):
                    $id    = (int)$row['product_id'];
                    $name  = $row['product_name'];
                    $stock = (int)$row['stock_quantity'];
                    $sc    = $stock === 0 ? 'out' : ($stock <= 10 ? 'low' : 'ok');
                    $sl    = $stock === 0 ? 'Out of Stock' : ($stock <= 10 ? 'Low Stock' : 'In Stock');
                ?>
                <div class="flavor-card" id="product-card-<?= $id ?>">
                    <h2><?= htmlspecialchars($name) ?></h2>
                    <div class="meta">
                        <span>Price: <strong>&#8369;<span class="card-price"><?= number_format($row['price'], 2) ?></span></strong></span>
                        <span>Stock: <strong><span class="card-stock"><?= number_format($stock) ?></span> packs</strong></span>
                    </div>
                    <span class="stock-badge <?= $sc ?>"><?= $sl ?></span>
                    <div class="card-actions">
                        <button type="button" class="btn btn-green btn-sm"
                                onclick="openStockModal(<?= $id ?>, '<?= addslashes($name) ?>')">
                            + Restock
                        </button>
                        <button type="button" class="btn btn-blue btn-sm"
                                onclick="openPriceModal(<?= $id ?>, '<?= addslashes($name) ?>')">
                            Change Price
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm"
                                onclick="disableProduct(<?= $id ?>)">
                            Disable Flavor
                        </button>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- DISABLED SIDE PANEL -->
<div id="disabledPanel" class="side-panel" role="complementary">
    <div class="panel-header">
        <h3>Disabled Flavors</h3>
        <button class="panel-close" onclick="toggleDisabledPanel(false)" aria-label="Close">&times;</button>
    </div>
    <div class="panel-body" id="disabledList">
        <?php
        $disRes = $conn->query("SELECT * FROM tbl_products WHERE status = 'disabled' ORDER BY product_name");
        $disRows = [];
        while ($d = $disRes->fetch_assoc()) $disRows[] = $d;

        if (empty($disRows)):
        ?>
            <div class="empty-state" style="padding:40px 10px;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/></svg>
                <p>No disabled flavors.</p>
            </div>
        <?php else: foreach ($disRows as $d): ?>
            <div class="dis-card" id="disabled-card-<?= $d['product_id'] ?>">
                <span><?= htmlspecialchars($d['product_name']) ?></span>
                <button class="btn btn-blue btn-sm" onclick="enableProduct(<?= $d['product_id'] ?>)">Restore</button>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<div id="panelOverlay" class="panel-overlay" onclick="toggleDisabledPanel(false)"></div>

<script>
const flavorSrc = '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>';

function flavorToast(msg, type = 'success') {
    const t = document.getElementById('flavorToast');
    t.textContent = msg;
    t.className = 'show ' + type;
    clearTimeout(t._hide); clearTimeout(t._rm);
    t._hide = setTimeout(() => t.classList.add('hide'), 2700);
    t._rm   = setTimeout(() => { t.className = ''; }, 3000);
}

function refreshFlavorPanel() {
    fetch(flavorSrc)
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const g = doc.getElementById('productsGrid');
            const d = doc.getElementById('disabledList');
            if (g) document.getElementById('productsGrid').innerHTML = g.innerHTML;
            if (d) document.getElementById('disabledList').innerHTML = d.innerHTML;
        })
        .catch(err => console.error('Panel refresh error:', err));
}

function openAnyModal(id)  { const el = document.getElementById(id); if (el) el.classList.add('open');    }
function closeAnyModal(id) { const el = document.getElementById(id); if (el) el.classList.remove('open'); }

window.addEventListener('click', e => {
    ['stockModal','priceModal','addModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && e.target === m) closeAnyModal(id);
    });
});
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    ['stockModal','priceModal','addModal'].forEach(closeAnyModal);
    toggleDisabledPanel(false);
});

function openStockModal(id, name) {
    document.getElementById('sm_id').value = id;
    document.getElementById('sm_flavor_name').textContent = name;
    document.getElementById('sm_qty').value = '';
    openAnyModal('stockModal');
}
function submitStockForm() {
    const id  = document.getElementById('sm_id').value;
    const qty = parseInt(document.getElementById('sm_qty').value);
    if (!id || !qty || qty < 1) { flavorToast('Enter a valid quantity.', 'error'); return; }
    const fd = new FormData();
    fd.append('action','add_stock'); fd.append('product_id',id); fd.append('quantity',qty);
    fetch(flavorSrc, { method:'POST', body:fd })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === 'success') { closeAnyModal('stockModal'); flavorToast('Stock updated!','success'); refreshFlavorPanel(); }
            else flavorToast('Error: ' + data, 'error');
        })
        .catch(() => flavorToast('Network error.','error'));
}

function openPriceModal(id, name) {
    document.getElementById('pm_id').value = id;
    document.getElementById('pm_flavor_name').textContent = name;
    document.getElementById('pm_select').value = '';
    openAnyModal('priceModal');
}
document.getElementById('pm_select').addEventListener('change', function () {
    if (this.value !== 'CUSTOM') return;
    const p = prompt('Enter custom price (PHP):');
    if (p !== null && !isNaN(p) && parseFloat(p) > 0) {
        const val = parseFloat(p).toFixed(2);
        if (![...this.options].find(o => o.value === val)) {
            const opt = document.createElement('option');
            opt.value = val; opt.text = 'PHP ' + val + ' (custom)';
            this.add(opt, this.options[this.options.length - 1]);
        }
        this.value = val;
    } else { this.value = ''; }
});
function submitPriceForm() {
    const id = document.getElementById('pm_id').value;
    const price = document.getElementById('pm_select').value;
    if (!price || price === 'CUSTOM') { flavorToast('Select a valid price.','error'); return; }
    const fd = new FormData();
    fd.append('action','price'); fd.append('product_id',id); fd.append('newPrice',price);
    fetch(flavorSrc, { method:'POST', body:fd })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === 'success') { closeAnyModal('priceModal'); flavorToast('Price updated!','success'); refreshFlavorPanel(); }
            else flavorToast('Error: ' + data, 'error');
        });
}

function handleNewSelection(sel) {
    const ni = document.getElementById('add_flavorName');
    if (sel.value === 'NEW_OPT') { sel.value = ''; ni.value = ''; ni.focus(); }
    else if (sel.value) { ni.value = sel.value; }
}
function submitAddForm() {
    const name  = document.getElementById('add_flavorName').value.trim();
    const price = document.getElementById('add_price').value;
    const stock = document.getElementById('add_stock').value;
    if (!name)               { flavorToast('Enter a flavor name.','error');  return; }
    if (!price || price < 0) { flavorToast('Enter a valid price.','error');  return; }
    if (stock === '' || stock < 0) { flavorToast('Enter a valid stock.','error'); return; }
    const fd = new FormData();
    fd.append('action','add'); fd.append('flavorName',name);
    fd.append('flavorPrice',price); fd.append('flavorStock',stock);
    fetch(flavorSrc, { method:'POST', body:fd })
        .then(r => r.text())
        .then(data => {
            const c = data.trim();
            if (c === 'success') {
                closeAnyModal('addModal');
                ['add_flavorName','add_price','add_stock'].forEach(id => document.getElementById(id).value = '');
                document.getElementById('add_flavorSelect').value = '';
                flavorToast('Flavor added!','success');
                refreshFlavorPanel();
            } else if (c === 'error:exists')     { flavorToast('That flavor already exists!','error'); }
            else if (c === 'error:invalid_name') { flavorToast('Flavor name cannot be empty.','error'); }
            else                                 { flavorToast('Error: ' + c, 'error'); }
        });
}

function disableProduct(id) {
    if (!confirm('Disable this flavor? It will not appear in orders.')) return;
    const fd = new FormData();
    fd.append('action','disable'); fd.append('product_id',id);
    fetch(flavorSrc, { method:'POST', body:fd })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === 'success') { flavorToast('Flavor disabled.','success'); refreshFlavorPanel(); }
            else flavorToast('Error: ' + data, 'error');
        });
}
function enableProduct(id) {
    const fd = new FormData();
    fd.append('action','enable'); fd.append('product_id',id);
    fetch(flavorSrc, { method:'POST', body:fd })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === 'success') { flavorToast('Flavor restored!','success'); refreshFlavorPanel(); }
            else flavorToast('Error: ' + data, 'error');
        });
}

function toggleDisabledPanel(show) {
    document.getElementById('disabledPanel').classList.toggle('active', show);
    document.getElementById('panelOverlay').classList.toggle('active', show);
}
</script>

<script src="../script/categories.js"></script>
</body>
</html>