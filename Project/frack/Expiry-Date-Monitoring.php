<?php
session_start();
date_default_timezone_set('Asia/Manila');

$conn = new mysqli("localhost", "root", "", "dbview");
if ($conn->connect_error) die("DB error: " . $conn->connect_error);

$currentUser = $_SESSION['Username'] ?? 'Unknown';
$currentRole = $_SESSION['Role'] ?? 'Unknown';

// ── POST HANDLERS ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add') {
        $name    = trim($_POST['item_name'] ?? '');
        $qty     = (int)($_POST['qty'] ?? 0);
        $expiry  = trim($_POST['expiry_date'] ?? '');
        $product = (int)($_POST['product_id'] ?? 0);
        if (!$name || $qty <= 0 || !$expiry) { echo json_encode(['success'=>false,'msg'=>'Invalid input']); exit; }
        $stmt = $conn->prepare("INSERT INTO tbl_expiry_batches (item_name, product_id, qty, expiry_date, date_added) VALUES (?,?,?,?,NOW())");
        if (!$stmt) { echo json_encode(['success'=>false,'msg'=>$conn->error]); exit; }
        $stmt->bind_param("siis", $name, $product, $qty, $expiry);
        $stmt->execute();
        // If linked to a flavor, update its stock too
        if ($product > 0) {
            $upd = $conn->prepare("UPDATE tbl_products SET stock_quantity = stock_quantity + ? WHERE product_id = ?");
            $upd->bind_param("ii", $qty, $product);
            $upd->execute(); $upd->close();
        }
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM tbl_expiry_batches WHERE id = $id");
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'edit') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['item_name'] ?? '');
        $qty    = (int)($_POST['qty'] ?? 0);
        if (!$id || !$name || $qty <= 0) { echo json_encode(['success'=>false,'msg'=>'Invalid input']); exit; }
        // Keep existing expiry date — do not overwrite it on edit
        $stmt = $conn->prepare("UPDATE tbl_expiry_batches SET item_name=?, qty=? WHERE id=?");
        $stmt->bind_param("sii", $name, $qty, $id);
        $stmt->execute();
        echo json_encode(['success'=>true]);
        exit;
    }
}

// ── AUTO-CREATE TABLE IF NOT EXISTS ─────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS tbl_expiry_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    product_id INT DEFAULT 0,
    qty INT NOT NULL,
    expiry_date DATE NOT NULL,
    date_added DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ── FETCH ROWS ────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$filter  = $_GET['filter'] ?? 'all';
$sort    = $_GET['sort'] ?? 'newest';

$where = "WHERE 1=1";
if ($search) $where .= " AND item_name LIKE '%" . $conn->real_escape_string($search) . "%'";
if ($filter === 'expired')  $where .= " AND expiry_date < CURDATE()";
if ($filter === 'expiring') $where .= " AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)";
if ($filter === 'safe')     $where .= " AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 2 DAY)";

$orderMap = ['newest'=>'date_added DESC','oldest'=>'date_added ASC','expiry_asc'=>'expiry_date ASC','expiry_desc'=>'expiry_date DESC'];
$order = $orderMap[$sort] ?? 'date_added DESC';

$batches = [];
$res = $conn->query("SELECT *, DATEDIFF(expiry_date, CURDATE()) as days_left FROM tbl_expiry_batches $where ORDER BY $order");
if ($res) while ($r = $res->fetch_assoc()) $batches[] = $r;

$totalBatches  = count($batches);
$expiredCount  = count(array_filter($batches, fn($r) => $r['days_left'] < 0));
$expiringCount = count(array_filter($batches, fn($r) => $r['days_left'] >= 0 && $r['days_left'] <= 2));
$safeCount     = count(array_filter($batches, fn($r) => $r['days_left'] > 2));

// ── GET PRODUCTS FOR DROPDOWN ────────────────────────────────
$products = [];
$rp = $conn->query("SELECT product_id, product_name FROM tbl_products WHERE status != 'disabled' ORDER BY product_name");
if ($rp) while ($row = $rp->fetch_assoc()) $products[] = $row;

// ── AUTO-BATCH: pull recent restock events not yet in expiry ─
// When stock is added via Stock.php, we can auto-add a batch here too.
// The trigger: if product_id is passed as GET ?auto=1&pid=X&qty=Y, auto-insert
if (isset($_GET['auto']) && $_GET['auto'] == '1') {
    $pid = (int)($_GET['pid'] ?? 0);
    $qty = (int)($_GET['qty'] ?? 0);
    if ($pid > 0 && $qty > 0) {
        $pname = '';
        $pr = $conn->prepare("SELECT product_name FROM tbl_products WHERE product_id=?");
        $pr->bind_param("i", $pid); $pr->execute();
        $pr->bind_result($pname); $pr->fetch(); $pr->close();
        if ($pname) {
            $expiry = date('Y-m-d', strtotime('+7 days'));
            $stmt = $conn->prepare("INSERT INTO tbl_expiry_batches (item_name, product_id, qty, expiry_date, date_added) VALUES (?,?,?,?,NOW())");
            $stmt->bind_param("siis", $pname, $pid, $qty, $expiry);
            $stmt->execute();
        }
    }
    header("Location: Expiry-Monitoring.php");
    exit;
}

$batchNum = $totalBatches + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Expiry Date Monitoring</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --ink:          #1e1e1e;
    --ink-mid:      #3a3a3a;
    --ink-soft:     #6b6b6b;
    --cream:        #e8e5df;
    --cream-deep:   #dedad3;
    --surface:      #f5f3ef;
    --surface-white:#ffffff;
    --sage:         #7a9068;
    --sage-lt:      #8fa882;
    --sage-pale:    #eaefe6;
    --sage-mid:     #bfcfb8;
    --green:        #3a9e6f;
    --green-pale:   #e6f5ee;
    --green-mid:    #a8d9c0;
    --gold:         #b87833;
    --gold-pale:    #fdf2e4;
    --gold-mid:     #e8c99a;
    --red:          #b33a2a;
    --red-pale:     #fdf0ee;
    --red-mid:      #f0c5c0;
    --charcoal:     #2d2d2d;
    --border:       #d6d2cb;
    --border-lt:    #e4e1db;
    --muted:        #8e8c88;
    --sh-sm: 0 1px 4px rgba(30,30,30,.06);
    --sh-md: 0 4px 16px rgba(30,30,30,.09);
    --sh-lg: 0 12px 40px rgba(30,30,30,.14);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#ffffff;color:var(--ink);min-height:100vh;}

/* ── HEADER ── */
.page-header{
    background:var(--sage);
    padding:22px 32px;
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    position:relative;overflow:hidden;
}
.page-header::before{
    content:'';position:absolute;top:-40px;right:-40px;width:180px;height:180px;
    background:radial-gradient(circle,rgba(255,255,255,.12) 0%,transparent 70%);
    pointer-events:none;
}
.hdr-left{display:flex;align-items:center;gap:14px;}
.hdr-title{font-family:'Lora',serif;font-size:20px;color:#fff;letter-spacing:-.2px;line-height:1.2;}
.hdr-sub{font-size:11.5px;color:rgba(255,255,255,.5);margin-top:2px;font-weight:300;}
.btn-primary{
    display:flex;align-items:center;gap:7px;
    background:rgba(255,255,255,.2);color:#fff;border:1.5px solid rgba(255,255,255,.35);
    padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;
    font-family:'DM Sans',sans-serif;cursor:pointer;
    transition:background .18s,transform .1s;white-space:nowrap;
}
.btn-primary:hover{background:rgba(255,255,255,.3);}
.btn-primary:active{transform:scale(.96);}

/* ── KPI STRIP ── */
.kpi-strip{
    display:flex;background:var(--surface-white);
    border-bottom:1px solid var(--border);padding:0 32px;
}
.kpi-card{
    padding:15px 24px 15px 0;margin-right:24px;
    border-right:1px solid var(--border-lt);
    display:flex;align-items:center;gap:12px;
}
.kpi-card:last-child{border-right:none;margin-right:0;}
.kpi-dot{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.kpi-dot.sage  {background:var(--sage-pale);}
.kpi-dot.red   {background:var(--red-pale);}
.kpi-dot.gold  {background:var(--gold-pale);}
.kpi-dot.green {background:var(--green-pale);}
.kpi-label{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;}
.kpi-val{font-size:19px;font-weight:600;color:var(--ink);margin-top:1px;line-height:1;}
.kpi-val.sage {color:var(--sage);}
.kpi-val.red  {color:var(--red);}
.kpi-val.gold {color:var(--gold);}
.kpi-val.green{color:var(--green);}

/* ── BODY ── */
.body-wrap{padding:24px 32px;}

/* ── TOOLBAR ── */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap;}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:300px;}
.search-wrap .ico{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;display:flex;align-items:center;}
.search-wrap input{
    width:100%;padding:9px 12px 9px 34px;
    border:1.5px solid var(--border);border-radius:9px;
    font-size:13px;font-family:'DM Sans',sans-serif;
    background:var(--surface-white);color:var(--ink);
    outline:none;transition:border-color .15s,box-shadow .15s;
}
.search-wrap input:focus{border-color:var(--sage);box-shadow:0 0 0 3px rgba(122,144,104,.12);}
.toolbar select{
    padding:9px 32px 9px 12px;
    border:1.5px solid var(--border);border-radius:9px;
    font-size:13px;font-family:'DM Sans',sans-serif;
    background:var(--surface-white);color:var(--ink);
    outline:none;cursor:pointer;appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238e8c88' stroke-width='2.5' stroke-linecap='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 10px center;
    transition:border-color .15s;
}
.toolbar select:focus{border-color:var(--sage);}
.result-count{margin-left:auto;font-size:12px;color:var(--muted);font-weight:500;white-space:nowrap;}

/* ── FILTER PILLS ── */
.filter-pills{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.pill{
    padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600;
    border:1.5px solid var(--border);background:var(--surface-white);
    color:var(--ink-soft);cursor:pointer;transition:all .14s;
}
.pill:hover{border-color:var(--sage);color:var(--sage);}
.pill.active{background:var(--sage);border-color:var(--sage);color:#fff;}
.pill.red.active{background:var(--red);border-color:var(--red);color:#fff;}
.pill.gold.active{background:var(--gold);border-color:var(--gold);color:#fff;}
.pill.green.active{background:var(--green);border-color:var(--green);color:#fff;}

/* ── TABLE CARD ── */
.table-card{
    background:var(--surface-white);border:1px solid var(--border);
    border-radius:14px;overflow:hidden;box-shadow:var(--sh-sm);
}
table{width:100%;border-collapse:collapse;}
thead th{
    background:var(--sage);color:rgba(255,255,255,.92);
    padding:12px 16px;font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.8px;text-align:left;white-space:nowrap;
}
thead th:last-child{text-align:right;}
tbody tr{border-bottom:1px solid var(--border-lt);transition:background .12s;animation:rowIn .3s ease both;}
@keyframes rowIn{from{opacity:0;transform:translateX(-6px);}to{opacity:1;transform:translateX(0);}}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--sage-pale);}
tbody td{padding:13px 16px;font-size:13.5px;color:var(--ink-mid);vertical-align:middle;}

.row-num{
    font-size:11px;color:var(--muted);font-weight:500;
    background:var(--cream-deep);width:26px;height:26px;
    border-radius:6px;display:inline-flex;align-items:center;justify-content:center;
}
.item-name{font-weight:600;color:var(--ink);font-size:14px;}
.batch-tag{
    display:inline-block;font-size:10px;font-weight:700;color:var(--muted);
    background:var(--cream-deep);padding:2px 7px;border-radius:4px;margin-left:6px;
    letter-spacing:.4px;
}
.date-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--ink-soft);}
.qty-pill{
    display:inline-flex;align-items:center;gap:4px;
    font-weight:700;font-size:13px;color:var(--ink-mid);
}

/* ── STATUS BADGES ── */
.status-badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 11px;border-radius:7px;font-size:12px;font-weight:700;
}
.status-badge.safe    {background:var(--green-pale);color:var(--green);border:1px solid var(--green-mid);}
.status-badge.warning {background:var(--gold-pale);color:var(--gold);border:1px solid var(--gold-mid);}
.status-badge.expired {background:var(--red-pale);color:var(--red);border:1px solid var(--red-mid);}
.status-badge .dot{width:6px;height:6px;border-radius:50%;background:currentColor;}

/* ── PROGRESS BAR (days left) ── */
.days-wrap{display:flex;flex-direction:column;gap:4px;min-width:100px;}
.days-bar{height:5px;border-radius:3px;background:var(--border);overflow:hidden;}
.days-bar-fill{height:100%;border-radius:3px;transition:width .3s;}
.days-text{font-size:11px;font-weight:600;}

/* ── ROW ACTIONS ── */
.row-actions{display:flex;gap:6px;justify-content:flex-end;}
.btn-row{padding:5px 13px;border-radius:7px;font-size:12px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all .14s;border:1.5px solid transparent;}
.btn-edit{color:var(--sage);background:var(--sage-pale);border-color:var(--sage-mid);}
.btn-edit:hover{background:var(--sage-mid);}
.btn-del{color:var(--red);background:var(--red-pale);border-color:var(--red-mid);}
.btn-del:hover{background:#f9dbd8;}

.empty-state{text-align:center;padding:60px 20px;}
.empty-title{font-family:'Lora',serif;font-size:17px;color:var(--ink-mid);margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--muted);}

.tbl-footer{
    padding:11px 16px;border-top:1px solid var(--border-lt);
    background:var(--surface);font-size:12px;color:var(--muted);
    display:flex;align-items:center;justify-content:space-between;
}
.tbl-footer strong{color:var(--ink-soft);}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:9999;background:rgba(30,30,30,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-card{background:var(--surface-white);border-radius:20px;width:420px;max-width:95vw;box-shadow:var(--sh-lg);overflow:hidden;animation:modalIn .22s cubic-bezier(.34,1.2,.64,1);}
@keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.96);}to{opacity:1;transform:translateY(0) scale(1);}}
.modal-top{background:var(--sage);padding:22px 26px 20px;display:flex;align-items:center;justify-content:space-between;}
.modal-top-left{display:flex;align-items:center;gap:12px;}
.modal-top-icon{width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;}
.modal-title{font-family:'Lora',serif;font-size:17px;color:#fff;}
.modal-sub{font-size:11px;color:rgba(255,255,255,.5);margin-top:2px;}
.modal-close{background:none;border:none;color:rgba(255,255,255,.5);font-size:20px;cursor:pointer;padding:0;line-height:1;transition:color .13s;}
.modal-close:hover{color:#fff;}
.modal-body{padding:24px 26px;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:7px;}
.form-input{
    width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;
    font-size:14px;font-family:'DM Sans',sans-serif;color:var(--ink);background:var(--surface);
    outline:none;transition:border-color .15s,box-shadow .15s,background .15s;
}
.form-input:focus{border-color:var(--sage);background:var(--surface-white);box-shadow:0 0 0 3px rgba(122,144,104,.12);}
.form-hint{font-size:11px;color:var(--muted);margin-top:5px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}

/* ── AUTO BATCH NOTICE ── */
.auto-notice{
    background:var(--sage-pale);border:1px solid var(--sage-mid);border-radius:10px;
    padding:10px 14px;margin-bottom:18px;font-size:12px;color:var(--sage);
    display:flex;align-items:center;gap:8px;font-weight:500;
}

.modal-actions{display:flex;gap:10px;padding:0 26px 24px;}
.btn-cancel{flex:1;padding:11px;border-radius:10px;border:1.5px solid var(--border);background:var(--surface-white);color:var(--ink-soft);font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .14s;}
.btn-cancel:hover{background:var(--surface);}
.btn-save{flex:2;padding:11px;border-radius:10px;border:none;background:var(--sage);color:#fff;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .14s,transform .1s,box-shadow .14s;box-shadow:0 2px 8px rgba(122,144,104,.3);}
.btn-save:hover{background:var(--sage-lt);box-shadow:0 4px 14px rgba(122,144,104,.4);}
.btn-save:active{transform:scale(.97);}

/* ── CONFIRM ── */
#confirmModal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(30,30,30,.55);backdrop-filter:blur(5px);align-items:center;justify-content:center;}
#confirmModal.open{display:flex;}
.confirm-card{background:var(--surface-white);border-radius:18px;overflow:hidden;width:340px;max-width:95vw;box-shadow:var(--sh-lg);animation:modalIn .2s ease;}
.confirm-top{background:var(--red);padding:20px 22px;display:flex;align-items:center;gap:10px;}
.confirm-top-icon{width:34px;height:34px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:17px;}
.confirm-top h3{font-family:'Lora',serif;font-size:17px;color:#fff;}
.confirm-body{padding:18px 22px;}
.confirm-body p{font-size:13px;color:var(--ink-soft);line-height:1.55;}
.confirm-btns{display:flex;gap:10px;padding:0 22px 20px;}
.btn-cf-cancel{flex:1;padding:10px;border-radius:9px;border:1.5px solid var(--border);background:var(--surface-white);font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;cursor:pointer;}
.btn-cf-del{flex:2;padding:10px;border-radius:9px;border:none;background:var(--red);color:#fff;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .14s;}
.btn-cf-del:hover{background:#9c2e20;}

/* ── TOAST ── */
#toast{position:fixed;bottom:22px;right:22px;z-index:999999;display:flex;align-items:center;gap:10px;background:var(--charcoal);color:#fff;padding:11px 18px;border-radius:11px;font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;box-shadow:var(--sh-lg);opacity:0;transform:translateY(10px);transition:opacity .22s,transform .22s;pointer-events:none;max-width:300px;}
#toast.show{opacity:1;transform:translateY(0);}
#toast.t-success{background:var(--sage);}
#toast.t-error{background:var(--red);}
.toast-icon{font-size:15px;flex-shrink:0;}

::-webkit-scrollbar{width:6px;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
</style>
</head>
<body>

<div id="toast"><span class="toast-icon"></span><span id="toastMsg"></span></div>

<!-- CONFIRM DELETE -->
<div id="confirmModal">
    <div class="confirm-card">
        <div class="confirm-top">
            <div class="confirm-top-icon">🗑️</div>
            <h3>Remove Batch?</h3>
        </div>
        <div class="confirm-body">
            <p id="confirmText">This batch record will be permanently removed.</p>
        </div>
        <div class="confirm-btns">
            <button class="btn-cf-cancel" onclick="closeConfirm()">Cancel</button>
            <button class="btn-cf-del" id="confirmDelBtn">Yes, Delete</button>
        </div>
    </div>
</div>

<!-- ADD / EDIT MODAL -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-card">
        <div class="modal-top">
            <div class="modal-top-left">
                <div class="modal-top-icon" id="modalIcon"></div>
                <div>
                    <div class="modal-title" id="modalTitle">Track New Batch</div>
                    <div class="modal-sub" id="modalSub">Add an item to monitor</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <input type="hidden" id="editId">
        <div class="modal-body">
            <div class="auto-notice" id="autoNotice">
                ⚡ Expiry is automatically set to <strong>+7 days from today</strong> when you save.
            </div>
            <div class="form-group">
                <label class="form-label" for="itemProduct">Link to Flavor (optional)</label>
                <select class="form-input" id="itemProduct" onchange="onProductSelect(this)">
                    <option value="0">— Manual Entry —</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['product_id'] ?>" data-name="<?= htmlspecialchars($p['product_name']) ?>"><?= htmlspecialchars($p['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">Selecting a flavor auto-fills the name and adds quantity to its stock</div>
            </div>
            <div class="form-group">
                <label class="form-label" for="itemName">Item / Batch Description</label>
                <input class="form-input" type="text" id="itemName" placeholder="e.g. Pork Siomai Batch A">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="itemQty">Quantity (packs)</label>
                    <input class="form-input" type="number" id="itemQty" placeholder="0" min="1">
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" id="saveBtn">Save Batch</button>
        </div>
    </div>
</div>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="hdr-left">
        <div>
            <div class="hdr-title">Expiry Date Monitoring</div>
            <div class="hdr-sub">Inventory Quality &amp; Freshness Control · Denver's Siomai</div>
        </div>
    </div>
    <button class="btn-primary" onclick="openModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add New Batch
    </button>
</div>

<!-- KPI STRIP -->
<div class="kpi-strip">
    <div class="kpi-card">
        <div class="kpi-dot sage">📦</div>
        <div>
            <div class="kpi-label">Total Batches</div>
            <div class="kpi-val sage"><?= $totalBatches ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-dot red">⚠️</div>
        <div>
            <div class="kpi-label">Expired</div>
            <div class="kpi-val red"><?= $expiredCount ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-dot gold">⏰</div>
        <div>
            <div class="kpi-label">Expiring Soon</div>
            <div class="kpi-val gold"><?= $expiringCount ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-dot green">✅</div>
        <div>
            <div class="kpi-label">Safe</div>
            <div class="kpi-val green"><?= $safeCount ?></div>
        </div>
    </div>
</div>

<!-- BODY -->
<div class="body-wrap">
    <form method="GET" id="filterForm" onsubmit="return false;">
        <div class="toolbar">
            <div class="search-wrap">
                <span class="ico">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <input type="text" id="searchInput" placeholder="Search batches…" value="<?= htmlspecialchars($search) ?>" oninput="debounce(filterTable, 400)">
            </div>
            <select id="sortSelect" onchange="filterTable()">
                <option value="newest"     <?= $sort==='newest'?'selected':'' ?>>Newest First</option>
                <option value="oldest"     <?= $sort==='oldest'?'selected':'' ?>>Oldest First</option>
                <option value="expiry_asc" <?= $sort==='expiry_asc'?'selected':'' ?>>Expiry: Soonest</option>
                <option value="expiry_desc"<?= $sort==='expiry_desc'?'selected':'' ?>>Expiry: Latest</option>
            </select>
            <span class="result-count" id="resultCount"><?= $totalBatches ?> batch<?= $totalBatches!==1?'es':'' ?></span>
        </div>

        <div class="filter-pills">
            <span class="pill active" data-filter="all"     onclick="setPill(this,'all')">All Batches</span>
            <span class="pill red"    data-filter="expired" onclick="setPill(this,'expired')">Expired</span>
            <span class="pill gold"   data-filter="expiring"onclick="setPill(this,'expiring')">Expiring Soon</span>
            <span class="pill green"  data-filter="safe"    onclick="setPill(this,'safe')">Safe</span>
        </div>
    </form>

    <div class="table-card" id="tableCard">
        <table>
            <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Item / Batch</th>
                    <th>Quantity</th>
                    <th>Date Added</th>
                    <th>Expiry Date</th>
                    <th>Days Left</th>
                    <th>Status</th>
                    <th style="width:130px">Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php if(empty($batches)): ?>
                <tr><td colspan="8" style="padding:0">
                    <div class="empty-state">
                        <div class="empty-title">No batches recorded yet</div>
                        <div class="empty-sub">Click "Add New Batch" or restock a flavor to auto-create a batch.</div>
                    </div>
                </td></tr>
            <?php else: foreach($batches as $i=>$b):
                $days = (int)$b['days_left'];
                if ($days < 0)      { $sc='expired'; $sl='Expired';      $barColor='var(--red)';   $barW=100; }
                elseif($days<=2)    { $sc='warning'; $sl='Expiring Soon';$barColor='var(--gold)';  $barW=max(5,min(100,($days/7)*100)); }
                else                { $sc='safe';    $sl='Safe';         $barColor='var(--green)'; $barW=min(100,($days/14)*100); }
                $daysLabel = $days < 0 ? abs($days).' days ago' : ($days===0 ? 'Today' : $days.' days');
            ?>
            <tr>
                <td><span class="row-num"><?= $i+1 ?></span></td>
                <td>
                    <span class="item-name"><?= htmlspecialchars($b['item_name']) ?></span>
                    <span class="batch-tag">BATCH #<?= str_pad($b['id'],3,'0',STR_PAD_LEFT) ?></span>
                </td>
                <td><span class="qty-pill"><?= number_format($b['qty']) ?> packs</span></td>
                <td><span class="date-chip">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <?= date('M d, Y', strtotime($b['date_added'])) ?>
                </span></td>
                <td><span class="date-chip">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?= date('M d, Y', strtotime($b['expiry_date'])) ?>
                </span></td>
                <td>
                    <div class="days-wrap">
                        <span class="days-text" style="color:<?= $barColor ?>;"><?= $daysLabel ?></span>
                        <div class="days-bar"><div class="days-bar-fill" style="width:<?= $barW ?>%;background:<?= $barColor ?>;"></div></div>
                    </div>
                </td>
                <td><span class="status-badge <?= $sc ?>"><span class="dot"></span><?= $sl ?></span></td>
                <td>
                    <div class="row-actions">
                        <button class="btn-row btn-edit"
                            data-id="<?= $b['id'] ?>"
                            data-name="<?= htmlspecialchars($b['item_name'], ENT_QUOTES) ?>"
                            data-qty="<?= $b['qty'] ?>"
                            data-date="<?= $b['expiry_date'] ?>"
                            onclick="openEditBtn(this)">Edit</button>
                        <button class="btn-row btn-del"
                            data-id="<?= $b['id'] ?>"
                            data-name="<?= htmlspecialchars($b['item_name'], ENT_QUOTES) ?>"
                            onclick="askDeleteBtn(this)">Delete</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php if(!empty($batches)): ?>
        <div class="tbl-footer">
            <span>Showing <strong><?= count($batches) ?></strong> batch<?= count($batches)!==1?'es':'' ?></span>
            <span><?= $expiredCount ?> expired · <?= $expiringCount ?> expiring soon · <?= $safeCount ?> safe</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const URL = typeof currentPanelUrl !== 'undefined' ? currentPanelUrl : 'Expiry-Date-Monitoring.php';
    let _dbt, _delId = null, _currentFilter = 'all';

    function toast(msg, type) { if (typeof showToast === 'function') showToast(msg, type); }
    function debounce(fn, ms) { clearTimeout(_dbt); _dbt = setTimeout(fn, ms); }

    function reload() {
        if (typeof reloadCurrentPanel === 'function') reloadCurrentPanel();
        else filterTable();
    }

    function filterTable() {
        const q    = document.getElementById('searchInput').value;
        const sort = document.getElementById('sortSelect').value;
        fetch(`${URL}?q=${encodeURIComponent(q)}&sort=${sort}&filter=${_currentFilter}&ajax=1`)
        .then(r => r.text()).then(html => {
            const doc    = new DOMParser().parseFromString(html, 'text/html');
            const tbody  = doc.getElementById('tableBody');
            const footer = doc.querySelector('.tbl-footer');
            const kpi    = doc.querySelector('.kpi-strip');
            const rc     = doc.getElementById('resultCount');
            if (tbody)  document.getElementById('tableBody').innerHTML = tbody.innerHTML;
            if (footer && document.querySelector('.tbl-footer')) document.querySelector('.tbl-footer').innerHTML = footer.innerHTML;
            if (kpi)    document.querySelector('.kpi-strip').innerHTML = kpi.innerHTML;
            if (rc)     document.getElementById('resultCount').innerHTML = rc.innerHTML;
        }).catch(() => toast('Filter error.', 'error'));
    }

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'Track New Batch';
        document.getElementById('modalSub').textContent = 'Add an item to monitor';
        document.getElementById('modalIcon').textContent = '📦';
        document.getElementById('editId').value = '';
        document.getElementById('itemProduct').value = '0';
        document.getElementById('itemName').value = '';
        document.getElementById('itemQty').value = '';
        document.getElementById('autoNotice').style.display = 'flex';
        document.getElementById('saveBtn').textContent = 'Save Batch';
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('modalOverlay').classList.add('open');
        setTimeout(() => document.getElementById('itemName').focus(), 120);
    }

    function openEditModal(btn) {
        document.getElementById('modalTitle').textContent = 'Edit Batch';
        document.getElementById('modalSub').textContent = 'Update batch information';
        document.getElementById('modalIcon').textContent = '✏️';
        document.getElementById('editId').value = btn.dataset.id;
        document.getElementById('itemProduct').value = '0';
        document.getElementById('itemName').value = btn.dataset.name;
        document.getElementById('itemQty').value = btn.dataset.qty;
        document.getElementById('autoNotice').style.display = 'none';
        document.getElementById('saveBtn').textContent = 'Save Changes';
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('modalOverlay').classList.add('open');
        setTimeout(() => document.getElementById('itemName').focus(), 120);
    }

    function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); }
    function closeConfirm() { _delId = null; document.getElementById('confirmModal').classList.remove('open'); }

    document.getElementById('saveBtn').addEventListener('click', function() {
        const id      = document.getElementById('editId').value;
        const name    = document.getElementById('itemName').value.trim();
        const qty     = document.getElementById('itemQty').value;
        const product = document.getElementById('itemProduct').value;
        if (!name)           { toast('Please enter an item name.', 'error'); return; }
        if (!qty || qty < 1) { toast('Please enter a valid quantity.', 'error'); return; }
        let params;
        if (id) {
            params = new URLSearchParams({ action: 'edit', id, item_name: name, qty });
        } else {
            const d = new Date(); d.setDate(d.getDate() + 7);
            const date = d.toISOString().slice(0, 10);
            params = new URLSearchParams({ action: 'add', item_name: name, qty, expiry_date: date, product_id: product });
        }
        this.disabled = true; this.textContent = 'Saving…';
        const btn = this;
        fetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json()).then(res => {
            if (res.success) { closeModal(); toast(id ? 'Batch updated!' : 'Batch added!', 'success'); reload(); }
            else { toast(res.msg || 'Something went wrong.', 'error'); btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Save Batch'; }
        }).catch(() => { toast('Network error.', 'error'); btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Save Batch'; });
    });

    document.getElementById('confirmDelBtn').addEventListener('click', function() {
        if (!_delId) return;
        this.disabled = true; this.textContent = 'Deleting…';
        const btn = this;
        fetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'delete', id: _delId }).toString() })
        .then(r => r.json()).then(res => {
            closeConfirm();
            if (res.success) { toast('Batch removed.', 'success'); reload(); }
            else { toast('Delete failed.', 'error'); btn.disabled = false; btn.textContent = 'Yes, Delete'; }
        }).catch(() => { toast('Network error.', 'error'); btn.disabled = false; btn.textContent = 'Yes, Delete'; });
    });

    document.getElementById('modalOverlay').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
    document.getElementById('confirmModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeConfirm(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeConfirm(); } });


    window.openModal      = openAddModal;
    window.openEditBtn    = openEditModal;
    window.askDeleteBtn   = btn => {
        _delId = btn.dataset.id;
        document.getElementById('confirmText').textContent = `"${btn.dataset.name}" (Batch #${String(btn.dataset.id).padStart(3,'0')}) will be permanently removed.`;
        document.getElementById('confirmModal').classList.add('open');
    };
    window.setPill        = (el, filter) => {
        document.querySelectorAll('.pill').forEach(p => p.classList.remove('active'));
        el.classList.add('active');
        _currentFilter = filter;
        filterTable();
    };
    window.onProductSelect = sel => {
        if (sel.value === '0') return;
        const opt = sel.options[sel.selectedIndex];
        document.getElementById('itemName').value = opt.dataset.name || opt.text;
    };
    window.closeModal     = closeModal;
    window.closeConfirm   = closeConfirm;
    window.filterTable    = filterTable;
    window.debounce       = debounce;
})();
</script>
</body>
</html>