<?php
session_start();

$conn = new mysqli("localhost", "root", "", "dbView");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$currentUser = $_SESSION['Username'] ?? 'Unknown';
$currentRole = $_SESSION['Role']     ?? 'Staff';

// ── SUBMIT BULK ORDER ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_order') {
    header('Content-Type: application/json');

    $customerName = trim($_POST['customer_name'] ?? '');
    $contact      = trim($_POST['contact']       ?? '');
    $orderDate    = $_POST['order_date']          ?? date('Y-m-d');
    $deliveryDate = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
    $notes        = trim($_POST['notes']          ?? '');
    $names        = $_POST['p_name']  ?? [];
    $qtys         = $_POST['qty']     ?? [];
    $prices       = $_POST['price']   ?? [];

    if (empty($customerName)) { echo json_encode(['success' => false, 'message' => 'Customer name is required.']); exit; }
    if (empty($names))        { echo json_encode(['success' => false, 'message' => 'Add at least one item.']);     exit; }

    $totalAmount = 0;
    foreach ($qtys as $i => $qty) {
        $totalAmount += ((float)($prices[$i] ?? 0)) * ((int)$qty);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO tbl_bulk_orders (customer_name, contact, order_date, delivery_date, ordered_by, role, notes, total_amount, status) VALUES (?,?,?,?,?,?,?,?,'Pending')");
        $stmt->bind_param("sssssssd", $customerName, $contact, $orderDate, $deliveryDate, $currentUser, $currentRole, $notes, $totalAmount);
        $stmt->execute();
        $orderId = $conn->insert_id;
        $stmt->close();

        foreach ($names as $i => $name) {
            $name  = trim($name);
            $qty   = max(0, (int)($qtys[$i]   ?? 0));
            $price = max(0, (float)($prices[$i] ?? 0));
            $sub   = $qty * $price;
            if (!$name || $qty <= 0) continue;

            $si = $conn->prepare("INSERT INTO tbl_bulk_order_items (order_id, product_name, quantity, unit_price, subtotal) VALUES (?,?,?,?,?)");
            $si->bind_param("isidd", $orderId, $name, $qty, $price, $sub);
            $si->execute(); $si->close();

            $sd = $conn->prepare("UPDATE tbl_products SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE product_name = ? AND status != 'disabled'");
            $sd->bind_param("is", $qty, $name);
            $sd->execute(); $sd->close();
        }

        $activity = "Bulk order #$orderId placed for '$customerName' — ₱" . number_format($totalAmount, 2);
        $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?,?,'Bulk Order',?,'Success')");
        $audit->bind_param("sss", $currentUser, $currentRole, $activity);
        $audit->execute(); $audit->close();

        $conn->commit();
        echo json_encode(['success' => true, 'order_id' => $orderId, 'total' => number_format($totalAmount, 2)]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── GET PRODUCTS FOR AUTOCOMPLETE ────────────────────────────
if (($_GET['action'] ?? '') === 'getProducts') {
    header('Content-Type: application/json');
    $res = $conn->query("SELECT product_name, price, stock_quantity FROM tbl_products WHERE status != 'disabled' ORDER BY product_name");
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = $r;
    echo json_encode($out);
    exit;
}

// ── GET ORDER ITEMS FOR RECEIPT ───────────────────────────────
if (($_GET['action'] ?? '') === 'getOrderItems') {
    header('Content-Type: application/json');
    $orderId = (int)($_GET['order_id'] ?? 0);
    if (!$orderId) { echo json_encode([]); exit; }

    $stmt = $conn->prepare("
        SELECT i.product_name, i.quantity, i.unit_price, i.subtotal,
               o.customer_name, o.contact, o.order_date, o.delivery_date,
               o.ordered_by, o.total_amount, o.status, o.notes
        FROM tbl_bulk_order_items i
        JOIN tbl_bulk_orders o ON o.order_id = i.order_id
        WHERE i.order_id = ?
    ");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    echo json_encode($rows);
    exit;
}

// ── MARK ORDER AS DONE ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_done') {
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid order.']); exit; }

    $conn->begin_transaction();
    try {
        // Mark order as Completed (only if currently Pending)
        $stmt = $conn->prepare("UPDATE tbl_bulk_orders SET status = 'Completed' WHERE order_id = ? AND status = 'Pending'");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();

        if ($stmt->affected_rows <= 0) {
            $stmt->close();
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Order not found or already completed.']);
            exit;
        }
        $stmt->close();

        // Update sold_quantity on tbl_products for each item in this order
        // This is what makes Total Sales, Top Sellers chart & detail update on the Dashboard
        $items = $conn->prepare("SELECT product_name, quantity FROM tbl_bulk_order_items WHERE order_id = ?");
        $items->bind_param("i", $orderId);
        $items->execute();
        $itemRows = $items->get_result();
        while ($item = $itemRows->fetch_assoc()) {
            $upd = $conn->prepare("UPDATE tbl_products SET sold_quantity = sold_quantity + ? WHERE product_name = ? AND status != 'disabled'");
            $upd->bind_param("is", $item['quantity'], $item['product_name']);
            $upd->execute(); $upd->close();
        }
        $items->close();

        $activity = "Bulk order #$orderId marked as Completed";
        $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?,?,'Bulk Order',?,'Success')");
        $audit->bind_param("sss", $currentUser, $currentRole, $activity);
        $audit->execute(); $audit->close();

        $conn->commit();
        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── CANCEL ORDER ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_order') {
    header('Content-Type: application/json');
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid order.']); exit; }

    // Only allow cancelling Pending orders
    $check = $conn->prepare("SELECT status FROM tbl_bulk_orders WHERE order_id = ?");
    $check->bind_param("i", $orderId);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Order not found.']); exit; }
    if ($row['status'] !== 'Pending') { echo json_encode(['success' => false, 'message' => 'Only Pending orders can be cancelled.']); exit; }

    // Restore stock
    $items = $conn->prepare("SELECT product_name, quantity FROM tbl_bulk_order_items WHERE order_id = ?");
    $items->bind_param("i", $orderId);
    $items->execute();
    $itemRows = $items->get_result();
    while ($item = $itemRows->fetch_assoc()) {
        $restore = $conn->prepare("UPDATE tbl_products SET stock_quantity = stock_quantity + ? WHERE product_name = ?");
        $restore->bind_param("is", $item['quantity'], $item['product_name']);
        $restore->execute(); $restore->close();
    }
    $items->close();

    // Mark as Cancelled
    $stmt = $conn->prepare("UPDATE tbl_bulk_orders SET status = 'Cancelled' WHERE order_id = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute(); $stmt->close();

    $activity = "Bulk order #$orderId was cancelled — stock restored";
    $audit = $conn->prepare("INSERT INTO tbl_audit (User, Role, Module, Activity, Status) VALUES (?,?,'Bulk Order',?,'Success')");
    $audit->bind_param("sss", $currentUser, $currentRole, $activity);
    $audit->execute(); $audit->close();

    echo json_encode(['success' => true]);
    exit;
}

// ── RECENT ORDERS ─────────────────────────────────────────────
$recentOrders = $conn->query("
    SELECT o.order_id, o.customer_name, o.order_date, o.delivery_date,
           o.total_amount, o.status, o.ordered_by,
           COUNT(i.item_id) AS item_count
    FROM tbl_bulk_orders o
    LEFT JOIN tbl_bulk_order_items i ON o.order_id = i.order_id
    GROUP BY o.order_id
    ORDER BY o.created_at DESC
    LIMIT 5
");
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --bg:       #f4f5f0;
        --surface:  #ffffff;
        --border:   #555;
        --muted:    #777;
        --text:     #333;
        --sage:     #A2AF9B;
        --sage-dk:  #899582;
        --sage-lt:  #e8ede6;
        --amber:    #d69e2e;
        --amber-lt: #fefcbf;
        --red:      #e53e3e;
        --red-lt:   #fed7d7;
        --green:    #38a169;
        --green-lt: #c6f6d5;
        --shadow:   0 4px 0 #999;
        --radius:   12px;
        --t:        0.18s ease;
    }

    .bo-wrap {
        font-family: 'Noto Sans', sans-serif;
        color: var(--text);
        padding: 4px 0;
        animation: fadeUp .3s ease both;
    }
    @keyframes fadeUp {
        from { opacity:0; transform:translateY(10px); }
        to   { opacity:1; transform:translateY(0); }
    }

    .bo-grid { display:grid; grid-template-columns: 1fr 320px; gap:16px; align-items:start; }

    .bo-card {
        background: var(--surface);
        border: 2px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 16px;
    }
    .bo-card-header {
        background: #f0f0ec;
        border-bottom: 2px solid #ccc;
        padding: 12px 20px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 1px;
        color: var(--muted);
        display: flex; align-items: center; justify-content: space-between;
    }
    .bo-card-body { padding: 18px 20px; }

    .field-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .field-grid-4 { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    .fg { display:flex; flex-direction:column; gap:5px; }
    .fg label {
        font-size:11px; font-weight:700;
        text-transform:uppercase; letter-spacing:0.6px; color:var(--muted);
    }
    .fg input, .fg textarea {
        padding:9px 12px; border:1.5px solid #d0d0cc; border-radius:8px;
        font-size:13px; font-family:inherit; color:var(--text);
        background:var(--bg); outline:none;
        transition:border-color var(--t), box-shadow var(--t);
        -webkit-user-select:text; user-select:text;
    }
    .fg input:focus, .fg textarea:focus {
        border-color:var(--sage);
        box-shadow:0 0 0 2.5px rgba(162,175,155,0.35);
        background:#fff;
    }
    .fg input[readonly] { background:#f7f7f4; color:var(--muted); cursor:default; border-color:transparent; }

    .bo-table { width:100%; border-collapse:collapse; }
    .bo-table thead th {
        background:var(--sage); color:#fff;
        padding:10px 12px; text-align:left;
        font-size:11px; font-weight:700;
        text-transform:uppercase; letter-spacing:0.5px;
        border-bottom:2px solid var(--sage-dk);
    }
    .bo-table tbody tr { border-bottom:1px solid #eee; transition:background var(--t); }
    .bo-table tbody tr:hover { background:var(--sage-lt); }
    .bo-table tbody td { padding:7px 8px; font-size:13px; vertical-align:middle; }
    .row-num { width:36px; text-align:center; font-weight:700; color:var(--muted); font-size:12px; }

    .ti {
        width:100%; padding:7px 9px;
        border:1.5px solid #e2e8f0; border-radius:6px;
        font-size:13px; font-family:inherit; color:var(--text);
        background:var(--bg); outline:none;
        transition:border-color var(--t), box-shadow var(--t);
        -webkit-user-select:text; user-select:text;
    }
    .ti:focus { border-color:var(--sage); box-shadow:0 0 0 2px rgba(162,175,155,0.3); background:#fff; }
    .ti[readonly] { background:#f7f7f4; font-weight:700; color:var(--sage-dk); border-color:transparent; cursor:default; }

    select.ti {
        cursor: pointer;
        appearance: auto;
    }
    select.ti:focus { border-color:var(--sage); box-shadow:0 0 0 2px rgba(162,175,155,0.3); background:#fff; }

    .del-btn {
        width:26px; height:26px; border-radius:50%; border:none;
        background:var(--red-lt); color:var(--red);
        font-size:15px; font-weight:700; cursor:pointer;
        display:flex; align-items:center; justify-content:center;
        transition:background var(--t), transform var(--t); line-height:1;
    }
    .del-btn:hover { background:var(--red); color:#fff; transform:scale(1.1); }

    .total-strip {
        display:flex; justify-content:flex-end; align-items:center;
        gap:16px; padding:12px 20px;
        border-top:2px solid #eee; background:#f7f7f4;
    }
    .total-strip .tl { font-size:12px; font-weight:600; color:var(--muted); text-transform:uppercase; }
    .total-strip .tv { font-size:22px; font-weight:800; color:var(--green); }

    .btn-bo {
        width:100%; padding:11px; border:2px solid var(--border);
        border-radius:var(--radius); font-size:14px; font-weight:700;
        font-family:inherit; cursor:pointer; text-align:center;
        box-shadow:var(--shadow); transition:background var(--t), color var(--t), transform .1s;
        margin-bottom:8px;
    }
    .btn-bo:last-child { margin-bottom:0; }
    .btn-bo:active { transform:translateY(3px); box-shadow:0 1px 0 #999; }
    .btn-submit { background:var(--sage);    color:#000; }
    .btn-submit:hover { background:var(--sage-dk); color:#fff; }
    .btn-addrow { background:var(--sage-lt); color:var(--sage-dk); }
    .btn-addrow:hover { background:var(--sage); color:#fff; }
    .btn-clr    { background:var(--red-lt);  color:var(--red); }
    .btn-clr:hover { background:var(--red); color:#fff; }

    .summary-box {
        background:var(--bg); border-radius:10px;
        padding:14px 16px; border:1px solid #ddd;
    }
    .srow {
        display:flex; justify-content:space-between; align-items:center;
        font-size:13px; padding:5px 0; border-bottom:1px solid #eee;
    }
    .srow:last-child { border-bottom:none; }
    .skey { color:var(--muted); }
    .sval { font-weight:700; color:var(--text); }

    .recent-row {
        display:flex; align-items:center; gap:10px;
        padding:10px 16px; border-bottom:1px solid #f0f0ec;
        font-size:13px; transition:background var(--t);
    }
    .recent-row:last-child { border-bottom:none; }
    .recent-row:hover { background:var(--sage-lt); }
    .o-badge {
        padding:2px 8px; border-radius:12px;
        font-size:10px; font-weight:700; text-transform:uppercase;
    }
    .o-pending  { background:var(--amber-lt); color:var(--amber); }
    .o-complete { background:var(--green-lt); color:var(--green); }

    .btn-done {
        padding: 3px 10px; border-radius: 20px;
        font-size: 11px; font-weight: 700;
        border: 1.5px solid var(--green); background: var(--green-lt);
        color: var(--green); cursor: pointer;
        transition: background var(--t), color var(--t);
        font-family: inherit;
    }
    .btn-done:hover  { background: var(--green); color: #fff; }
    .btn-done:disabled { opacity: 0.5; cursor: not-allowed; }

    .btn-receipt {
        padding: 3px 10px; border-radius: 20px;
        font-size: 11px; font-weight: 700;
        border: 1.5px solid #718096; background: #edf2f7;
        color: #4a5568; cursor: pointer;
        transition: background var(--t), color var(--t);
        font-family: inherit;
    }
    .btn-receipt:hover { background: #4a5568; color: #fff; }

    .btn-cancel {
        padding: 3px 10px; border-radius: 20px;
        font-size: 11px; font-weight: 700;
        border: 1.5px solid var(--red); background: var(--red-lt);
        color: var(--red); cursor: pointer;
        transition: background var(--t), color var(--t);
        font-family: inherit;
    }
    .btn-cancel:hover    { background: var(--red); color: #fff; }
    .btn-cancel:disabled { opacity: 0.5; cursor: not-allowed; }

    .o-cancelled { background: #e2e8f0; color: #718096; }

    #bo-toast {
        position:fixed; bottom:28px; right:28px; z-index:99999;
        padding:13px 20px; border-radius:10px;
        font-family:'Noto Sans',sans-serif; font-size:14px; font-weight:600;
        color:#fff; border:2px solid #333; box-shadow:0 4px 0 #666;
        display:none;
    }
    #bo-toast.success { background:var(--sage); }
    #bo-toast.error   { background:var(--red); }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div id="bo-toast"></div>

<div class="bo-wrap">
    <div class="bo-grid">

        <!-- LEFT -->
        <div>
            <!-- Customer Info -->
            <div class="bo-card">
                <div class="bo-card-header">
                    <span>📦 Bulk Order Form</span>
                    <span style="color:var(--green);font-size:12px;">Stock deducted on submit</span>
                </div>
                <div class="bo-card-body">
                    <div class="field-grid-2" style="margin-bottom:12px;">
                        <div class="fg">
                            <label>Customer Name <span style="color:var(--red)">*</span></label>
                            <input type="text" id="customerName" placeholder="e.g. Juan dela Cruz" oninput="updateSummary()">
                        </div>
                        <div class="fg">
                            <label>Contact No.</label>
                            <input type="text" id="contactNo" placeholder="e.g. 09XX-XXX-XXXX">
                        </div>
                    </div>
                    <div class="field-grid-4">
                        <div class="fg">
                            <label>Ordered By</label>
                            <input type="text" value="<?= htmlspecialchars($currentUser) ?>" readonly>
                        </div>
                        <div class="fg">
                            <label>Role</label>
                            <input type="text" value="<?= htmlspecialchars($currentRole) ?>" readonly>
                        </div>
                        <div class="fg">
                            <label>Order Date</label>
                            <input type="date" id="orderDate" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="fg">
                            <label>Delivery Date</label>
                            <input type="date" id="deliveryDate" min="<?= date('Y-m-d') ?>" onchange="updateSummary()">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="bo-card">
                <div class="bo-card-header">
                    <span>Order Items</span>
                    <span id="rowCount">0 rows</span>
                </div>
                <div style="overflow-x:auto;">
                    <table class="bo-table">
                        <thead>
                            <tr>
                                <th style="width:36px;">#</th>
                                <th>Product</th>
                                <th style="width:90px;">Qty</th>
                                <th style="width:130px;">Unit Price (₱)</th>
                                <th style="width:130px;">Subtotal (₱)</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody id="orderBody"></tbody>
                    </table>
                </div>
                <div class="total-strip">
                    <span class="tl">Grand Total</span>
                    <span class="tv" id="grandTotal">₱0.00</span>
                </div>
            </div>

            <!-- Notes -->
            <div class="bo-card">
                <div class="bo-card-header">Notes</div>
                <div class="bo-card-body">
                    <div class="fg">
                        <textarea id="orderNotes" rows="3" placeholder="Special instructions, delivery details, etc."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT -->
        <div>
            <div class="bo-card">
                <div class="bo-card-header">Actions</div>
                <div class="bo-card-body">
                    <button class="btn-bo btn-addrow" onclick="addRow()">+ Add Item Row</button>
                    <button class="btn-bo btn-submit" id="submitBtn" onclick="submitOrder()">⬆ Submit Bulk Order</button>
                    <button class="btn-bo btn-clr"    onclick="clearAll()">✕ Clear Form</button>
                </div>
            </div>

            <div class="bo-card">
                <div class="bo-card-header">Order Summary</div>
                <div class="bo-card-body" style="padding:14px 16px;">
                    <div class="summary-box">
                        <div class="srow"><span class="skey">Customer</span>    <span class="sval" id="sum-customer">—</span></div>
                        <div class="srow"><span class="skey">Delivery</span>    <span class="sval" id="sum-delivery">—</span></div>
                        <div class="srow"><span class="skey">Items</span>        <span class="sval" id="sum-items">0</span></div>
                        <div class="srow"><span class="skey">Total Qty</span>    <span class="sval" id="sum-qty">0</span></div>
                        <div class="srow"><span class="skey">Grand Total</span>  <span class="sval" id="sum-total" style="color:var(--green)">₱0.00</span></div>
                    </div>
                </div>
            </div>

            <div class="bo-card">
                <div class="bo-card-header">Recent Orders</div>
                <?php if ($recentOrders && $recentOrders->num_rows > 0): ?>
                    <?php while ($o = $recentOrders->fetch_assoc()):
                        $isPending = strtolower($o['status']) === 'pending';
                    ?>
                    <div class="recent-row" id="order-row-<?= $o['order_id'] ?>">
                        <div style="flex:1;">
                            <div style="font-weight:600;"><?= htmlspecialchars($o['customer_name']) ?></div>
                            <div style="font-size:11px;color:var(--muted);">#<?= $o['order_id'] ?> · <?= $o['item_count'] ?> items · <?= date('M d', strtotime($o['order_date'])) ?></div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                            <div style="font-weight:700;color:var(--green);font-size:13px;">₱<?= number_format($o['total_amount'], 2) ?></div>
                            <span class="o-badge <?= $isPending ? 'o-pending' : 'o-complete' ?>" id="badge-<?= $o['order_id'] ?>"><?= htmlspecialchars($o['status']) ?></span>
                            <div style="display:flex;gap:5px;">
                                <?php if ($isPending): ?>
                                <button class="btn-done"   onclick="markDone(<?= $o['order_id'] ?>, this)" title="Mark as Completed">✔ Done</button>
                                <button class="btn-cancel" onclick="cancelOrder(<?= $o['order_id'] ?>, this)" title="Cancel Order">✕ Cancel</button>
                                <?php endif; ?>
                                <button class="btn-receipt" onclick="printReceipt(<?= $o['order_id'] ?>, '<?= addslashes($o['customer_name']) ?>', '<?= $o['order_date'] ?>', '<?= number_format($o['total_amount'], 2) ?>')" title="Print Receipt">🧾 Receipt</button>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding:20px;text-align:center;color:var(--muted);font-size:13px;">No orders yet.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
const BO_URL = (typeof currentPanelUrl !== 'undefined' && currentPanelUrl)
    ? (currentPanelUrl.startsWith('/') ? currentPanelUrl : window.location.pathname.replace(/\/[^/]*$/, '/') + currentPanelUrl.replace('./', ''))
    : window.location.pathname;
let   products = [];

fetch(BO_URL + '?action=getProducts')
    .then(r => r.json())
    .then(data => {
        products = data;
        console.log('Products loaded:', products.length, products);
        if (!products.length) boToast('No products with stock available.', 'error');
        // Rebuild selects already in the table with the loaded options
        document.querySelectorAll('.product-select').forEach(sel => {
            const current = sel.value;
            sel.innerHTML = buildOpts();
            if (current) sel.value = current;
        });
    })
    .catch(err => { console.error('getProducts failed:', err, 'URL:', BO_URL); });

function buildOpts() {
    return '<option value="">— Select Product —</option>' +
        products
            .filter(p => p.stock_quantity > 0)
            .map(p => `<option value="${escAttr(p.product_name)}" data-price="${p.price}" data-stock="${p.stock_quantity}">${p.product_name} (${p.stock_quantity} left)</option>`)
            .join('');
}

function addRow() {
    const tbody = document.getElementById('orderBody');
    const tr    = document.createElement('tr');
    tr.innerHTML = `
        <td class="row-num">${tbody.rows.length + 1}</td>
        <td>
            <select class="ti product-select" name="p_name[]" onchange="onProductSelect(this)">
                ${buildOpts()}
            </select>
        </td>
        <td><input class="ti qty"      type="number" name="qty[]"   min="1"     oninput="calcRow(this)" placeholder="0"></td>
        <td><input class="ti price"    type="number" name="price[]" step="0.01" oninput="calcRow(this)" placeholder="0.00" readonly></td>
        <td><input class="ti subtotal" type="number" name="amount[]" readonly value="0.00"></td>
        <td><button type="button" class="del-btn" onclick="delRow(this)">×</button></td>
    `;
    tbody.appendChild(tr);
    reindex();
    tr.querySelector('.product-select').focus();
}

function escAttr(s) { return s.replace(/"/g, '&quot;'); }

function onProductSelect(sel) {
    const opt   = sel.selectedOptions[0];
    const row   = sel.closest('tr');
    const price = opt?.dataset.price || '';
    const stock = parseInt(opt?.dataset.stock) || 0;
    row.querySelector('.price').value = price ? parseFloat(price).toFixed(2) : '';
    calcRow(row.querySelector('.qty'));
    if (stock > 0 && stock < 20) boToast(`⚠️ Only ${stock} units left for "${opt.value}"`, 'error');
}

function calcRow(input) {
    const row = input.closest('tr');
    const qty = parseFloat(row.querySelector('.qty').value)   || 0;
    const prc = parseFloat(row.querySelector('.price').value) || 0;
    row.querySelector('.subtotal').value = (qty * prc).toFixed(2);
    updateGrandTotal();
}

function updateGrandTotal() {
    let total = 0;
    document.querySelectorAll('#orderBody .subtotal').forEach(s => total += parseFloat(s.value) || 0);
    const fmt = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });
    document.getElementById('grandTotal').textContent = fmt;
    document.getElementById('sum-total').textContent  = fmt;
    updateSummary();
}

function updateSummary() {
    document.getElementById('sum-customer').textContent = document.getElementById('customerName').value.trim() || '—';
    const dd = document.getElementById('deliveryDate').value;
    document.getElementById('sum-delivery').textContent = dd
        ? new Date(dd + 'T00:00:00').toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' })
        : '—';
    const rows = document.querySelectorAll('#orderBody tr');
    document.getElementById('sum-items').textContent = rows.length;
    let tq = 0;
    rows.forEach(r => tq += parseInt(r.querySelector('.qty')?.value) || 0);
    document.getElementById('sum-qty').textContent = tq;
}

function delRow(btn) {
    if (document.querySelectorAll('#orderBody tr').length <= 1) {
        boToast('At least one item row is required.', 'error'); return;
    }
    btn.closest('tr').remove();
    reindex(); updateGrandTotal();
}

function reindex() {
    document.querySelectorAll('#orderBody tr').forEach((tr, i) => {
        tr.querySelector('.row-num').textContent = i + 1;
    });
    const n = document.querySelectorAll('#orderBody tr').length;
    document.getElementById('rowCount').textContent = n + (n === 1 ? ' row' : ' rows');
}

function submitOrder() {
    const customer = document.getElementById('customerName').value.trim();
    if (!customer) { boToast('Customer name is required.', 'error'); document.getElementById('customerName').focus(); return; }

    const rows = document.querySelectorAll('#orderBody tr');
    let valid  = true;
    rows.forEach(row => {
        if (!row.querySelector('.product-select').value ||
            !row.querySelector('.qty').value ||
            !row.querySelector('.price').value) valid = false;
    });
    if (!valid) { boToast('Fill in all product, quantity and price fields.', 'error'); return; }

    const fd = new FormData();
    fd.append('action',        'bulk_order');
    fd.append('customer_name', customer);
    fd.append('contact',       document.getElementById('contactNo').value.trim());
    fd.append('order_date',    document.getElementById('orderDate').value);
    fd.append('delivery_date', document.getElementById('deliveryDate').value);
    fd.append('notes',         document.getElementById('orderNotes').value.trim());
    rows.forEach(row => {
        fd.append('p_name[]', row.querySelector('.product-select').value);
        fd.append('qty[]',    row.querySelector('.qty').value);
        fd.append('price[]',  row.querySelector('.price').value);
    });

    const btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.textContent = 'Submitting…';

    fetch(BO_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false; btn.textContent = '⬆ Submit Bulk Order';
            if (data.success) {
                boToast(`✅ Order #${data.order_id} saved! Total: ₱${data.total}`, 'success');
                clearAll(true);
                prependRecentOrder(data.order_id, customer, fd.get('order_date'), data.total, fd.get('contact') || '');
            } else {
                boToast('Error: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(() => {
            btn.disabled = false; btn.textContent = '⬆ Submit Bulk Order';
            boToast('Network error. Please try again.', 'error');
        });
}

function prependRecentOrder(orderId, customerName, orderDate, total, contact) {
    const container = document.querySelector('.bo-card:last-child .bo-card-header');
    if (!container) return;
    const card = container.closest('.bo-card');

    // Remove "No orders yet" placeholder if present
    const placeholder = card.querySelector('[style*="No orders yet"]');
    if (placeholder) placeholder.remove();

    const dateFormatted = new Date(orderDate + 'T00:00:00').toLocaleDateString('en-PH', { month:'short', day:'numeric' });
    const row = document.createElement('div');
    row.className = 'recent-row';
    row.id        = 'order-row-' + orderId;
    row.style.animation = 'fadeUp .3s ease both';
    row.innerHTML = `
        <div style="flex:1;">
            <div style="font-weight:600;">${customerName}</div>
            <div style="font-size:11px;color:var(--muted);">#${orderId} · ${dateFormatted}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
            <div style="font-weight:700;color:var(--green);font-size:13px;">₱${total}</div>
            <span class="o-badge o-pending" id="badge-${orderId}">Pending</span>
            <div style="display:flex;gap:5px;">
                <button class="btn-done"    onclick="markDone(${orderId}, this)"   title="Mark as Completed">✔ Done</button>
                <button class="btn-cancel"  onclick="cancelOrder(${orderId}, this)" title="Cancel Order">✕ Cancel</button>
                <button class="btn-receipt" onclick="printReceipt(${orderId}, '${escStr(customerName)}', '${orderDate}', '${total}')" title="Print Receipt">🧾 Receipt</button>
            </div>
        </div>
    `;

    // Insert after header
    container.insertAdjacentElement('afterend', row);
}

function escStr(s) { return s.replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }

function clearAll(skipConfirm = false) {
    if (!skipConfirm && !confirm('Clear the entire form?')) return;
    document.getElementById('customerName').value = '';
    document.getElementById('contactNo').value    = '';
    document.getElementById('deliveryDate').value = '';
    document.getElementById('orderNotes').value   = '';
    document.getElementById('orderDate').value    = new Date().toISOString().split('T')[0];
    document.getElementById('orderBody').innerHTML = '';
    addRow();
    updateSummary();
}

function boToast(msg, type = 'success') {
    const t = document.getElementById('bo-toast');
    t.textContent = msg; t.className = type; t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.display = 'none'; }, 3500);
}

function printReceipt(orderId, customerName, orderDate, totalAmount) {
    boToast('Generating receipt…', 'success');

    fetch(BO_URL + '?action=getOrderItems&order_id=' + orderId)
        .then(r => r.json())
        .then(items => {
            if (!items.length) { boToast('No items found for this order.', 'error'); return; }

            const order      = items[0];
            const receiptNo  = 'BO-' + String(orderId).padStart(6, '0');
            const now        = new Date();
            const dateStr    = new Date(orderDate).toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' });
            const timeStr    = now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
            const total      = items.reduce((s, i) => s + parseFloat(i.subtotal), 0);
            const totalQty   = items.reduce((s, i) => s + parseInt(i.quantity), 0);

            function fmtNum(n) {
                return parseFloat(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            const { jsPDF }  = window.jspdf;
            const doc        = new jsPDF({ unit:'mm', format:'a4' });
            const pW         = 210;
            let   y          = 15;

            function cText(text, size, bold = false) {
                doc.setFontSize(size); doc.setFont('helvetica', bold ? 'bold' : 'normal');
                doc.text(text, pW / 2, y, { align:'center' }); y += size * 0.5;
            }
            function lrText(left, right, size = 10, boldR = false) {
                doc.setFontSize(size); doc.setFont('helvetica', 'normal');
                doc.text(String(left), 20, y);
                doc.setFont('helvetica', boldR ? 'bold' : 'normal');
                doc.text(String(right), pW - 20, y, { align:'right' }); y += size * 0.5 + 1;
            }
            function solidLine()  { y += 2; doc.setLineWidth(0.5); doc.setLineDashPattern([], 0);     doc.line(20, y, pW-20, y); y += 5; }
            function dashedLine() { y += 2; doc.setLineWidth(0.3); doc.setLineDashPattern([2,2], 0); doc.line(20, y, pW-20, y); doc.setLineDashPattern([], 0); y += 5; }

            // ── HEADER ──
            cText("DENVER'S SIOMAI", 20, true); y += 2;
            cText('Fresh & Delicious Siomai', 10); y += 1;
            cText('Tel: 0900-000-0000', 9);
            solidLine();

            // ── ORDER INFO ──
            lrText('Order No:',      receiptNo,          9);
            lrText('Date:',          dateStr,            9);
            lrText('Time:',          timeStr,            9);
            lrText('Customer:',      customerName,       9);
            if (order.contact) lrText('Contact:', order.contact, 9);
            if (order.delivery_date) lrText('Delivery Date:', new Date(order.delivery_date).toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' }), 9);
            lrText('Processed By:',  order.ordered_by,   9);
            dashedLine();

            // ── TABLE HEADER ──
            doc.setFontSize(9); doc.setFont('helvetica', 'bold');
            doc.text('ITEM',     20,        y);
            doc.text('QTY',      115,       y, { align:'center' });
            doc.text('PRICE',    148,       y, { align:'center' });
            doc.text('SUBTOTAL', pW - 20,   y, { align:'right' });
            y += 5; dashedLine();

            // ── ITEMS ──
            doc.setFont('helvetica', 'normal'); doc.setFontSize(9);
            items.forEach(item => {
                const lines = doc.splitTextToSize(String(item.product_name), 85);
                doc.text(lines,                                      20,      y);
                doc.text(fmtNum(item.quantity).replace(/\.00$/, ''), 115,     y, { align:'center' });
                doc.text('P' + fmtNum(item.unit_price),              148,     y, { align:'center' });
                doc.text('P' + fmtNum(item.subtotal),                pW-20,   y, { align:'right' });
                y += lines.length > 1 ? lines.length * 5 : 7;
            });
            solidLine();

            // ── TOTALS ──
            lrText('Subtotal:',  'P' + fmtNum(total), 10);
            lrText('Discount:',  'P0.00', 10);
            lrText('VAT (0%):',  'P0.00', 10); y += 2;

            doc.setFillColor(230, 230, 230);
            doc.roundedRect(20, y - 2, pW - 40, 12, 2, 2, 'F');
            doc.setFontSize(14); doc.setFont('helvetica', 'bold');
            doc.text('TOTAL:',                    25,      y + 6);
            doc.text('P' + fmtNum(total),         pW - 25, y + 6, { align:'right' });
            y += 16; dashedLine();

            // ── STATUS ──
            lrText('Order Status:', order.status, 10, true); y += 2;

            if (order.notes) {
                dashedLine();
                doc.setFontSize(9); doc.setFont('helvetica', 'italic');
                const noteLines = doc.splitTextToSize('Notes: ' + order.notes, pW - 40);
                doc.text(noteLines, 20, y);
                y += noteLines.length * 5;
            }
            solidLine();

            // ── SUMMARY ──
            lrText('Total Items Sold:',     totalQty.toLocaleString('en-US') + ' pc(s)',    9);
            lrText('Total Product Lines:',  items.length + ' item(s)', 9);
            dashedLine(); y += 2;

            // ── FOOTER ──
            cText('Thank you for your bulk order!', 12, true); y += 3;
            cText('We appreciate your business!', 9); y += 6;
            cText('*** BULK ORDER RECEIPT ***', 8); y += 2;
            cText('This is a system-generated receipt only.', 7);

            doc.save('BulkOrder_' + receiptNo + '.pdf');
            boToast('Receipt downloaded!', 'success');
        })
        .catch(err => {
            console.error('Receipt error:', err);
            boToast('Failed to generate receipt.', 'error');
        });
}

function markDone(orderId, btn) {
    if (!confirm('Mark Order #' + orderId + ' as Completed?')) return;
    btn.disabled    = true;
    btn.textContent = '…';

    const fd = new FormData();
    fd.append('action',   'mark_done');
    fd.append('order_id', orderId);

    fetch(BO_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('badge-' + orderId);
                if (badge) {
                    badge.textContent = 'Completed';
                    badge.className   = 'o-badge o-complete';
                }
                const row = document.getElementById('order-row-' + orderId);
                if (row) {
                    row.querySelectorAll('.btn-done, .btn-cancel').forEach(b => b.remove());
                }
                boToast('Order #' + orderId + ' marked as Completed!', 'success');
            } else {
                btn.disabled    = false;
                btn.textContent = '✔ Done';
                boToast('Error: ' + (data.message || 'Could not update order.'), 'error');
            }
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = '✔ Done';
            boToast('Network error. Please try again.', 'error');
        });
}

function cancelOrder(orderId, btn) {
    if (!confirm('Cancel Order #' + orderId + '? Stock will be restored.')) return;
    btn.disabled    = true;
    btn.textContent = '…';

    const fd = new FormData();
    fd.append('action',   'cancel_order');
    fd.append('order_id', orderId);

    fetch(BO_URL, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('badge-' + orderId);
                if (badge) {
                    badge.textContent = 'Cancelled';
                    badge.className   = 'o-badge o-cancelled';
                }
                // Remove Done and Cancel buttons, keep Receipt
                const row = document.getElementById('order-row-' + orderId);
                if (row) {
                    row.querySelectorAll('.btn-done, .btn-cancel').forEach(b => b.remove());
                }
                boToast('Order #' + orderId + ' cancelled. Stock restored.', 'success');
            } else {
                btn.disabled    = false;
                btn.textContent = '✕ Cancel';
                boToast('Error: ' + (data.message || 'Could not cancel order.'), 'error');
            }
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = '✕ Cancel';
            boToast('Network error. Please try again.', 'error');
        });
}
document.getElementById('deliveryDate').addEventListener('change', updateSummary);

// Init
addRow();
</script>