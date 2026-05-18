<?php
session_start();
$conn = new mysqli('127.0.0.1', 'root', '', 'dbview');
if ($conn->connect_error) { die('error:Connection failed: ' . $conn->connect_error); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['sup_name'] ?? '');
        $cost = floatval($_POST['sup_cost'] ?? 0);
        if ($name && $cost > 0) {
            $stmt = $conn->prepare("INSERT INTO tbl_suppliers (supplier_name, sup_cost, date_created) VALUES (?, ?, NOW())");
            if (!$stmt) { echo 'error:Prepare failed: ' . $conn->error; exit; }
            $stmt->bind_param('sd', $name, $cost);
            if ($stmt->execute()) { echo 'success'; } else { echo 'error:Execute failed: ' . $stmt->error; }
            exit;
        }
        echo 'error:Invalid input'; exit;
    }
    if ($_POST['action'] === 'delete') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM tbl_suppliers WHERE id = $id");
        echo 'success'; exit;
    }
    if ($_POST['action'] === 'edit') {
        $id   = intval($_POST['id']);
        $name = trim($_POST['sup_name'] ?? '');
        $cost = floatval($_POST['sup_cost'] ?? 0);
        if ($id && $name && $cost > 0) {
            $stmt = $conn->prepare("UPDATE tbl_suppliers SET supplier_name=?, sup_cost=? WHERE id=?");
            $stmt->bind_param('sdi', $name, $cost, $id);
            $stmt->execute();
            echo 'success';
        } else { echo 'error:Invalid input'; }
        exit;
    }
}

$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'newest';
$where  = $search ? "WHERE supplier_name LIKE '%" . $conn->real_escape_string($search) . "%'" : '';
$orderMap = ['oldest'=>'id ASC','cost_high'=>'sup_cost DESC','cost_low'=>'sup_cost ASC'];
$order  = $orderMap[$sort] ?? 'id DESC';

$suppliers = [];
$res = $conn->query("SELECT * FROM tbl_suppliers $where ORDER BY $order");
if ($res) while ($r = $res->fetch_assoc()) $suppliers[] = $r;

$totalCost  = array_sum(array_column($suppliers, 'sup_cost'));
$totalCount = count($suppliers);
$avgCost    = $totalCount ? $totalCost / $totalCount : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Materials</title>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<style>
:root {
    /* ── Matched to Denver's Siomai dashboard palette ── */
    --ink:          #1e1e1e;
    --ink-mid:      #3a3a3a;
    --ink-soft:     #6b6b6b;

    --cream:        #e8e5df;   /* dashboard page background */
    --cream-deep:   #dedad3;
    --surface:      #f5f3ef;   /* card / panel background */
    --surface-white:#ffffff;

    --sage:         #7a9068;   /* primary accent — sidebar active, table headers */
    --sage-lt:      #8fa882;   /* lighter sage (hover) */
    --sage-pale:    #eaefe6;   /* very light sage tint */
    --sage-mid:     #bfcfb8;   /* sage border/divider */

    --green:        #3a9e6f;   /* success / completed / grand total */
    --green-pale:   #e6f5ee;
    --green-mid:    #a8d9c0;

    --red:          #b33a2a;
    --red-pale:     #fdf0ee;

    --charcoal:     #2d2d2d;   /* sidebar background */
    --charcoal-lt:  #3c3c3c;

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
    content:'';position:absolute;top:-40px;right:-40px;
    width:180px;height:180px;
    background:radial-gradient(circle,rgba(255,255,255,.12) 0%,transparent 70%);
    pointer-events:none;
}
.hdr-left{display:flex;align-items:center;gap:14px;}
.hdr-icon{
    width:44px;height:44px;
    background:rgba(255,255,255,.2);
    border-radius:12px;display:flex;align-items:center;justify-content:center;
    font-size:21px;box-shadow:0 2px 8px rgba(0,0,0,.15);flex-shrink:0;
}
.hdr-title{font-family:'Lora',serif;font-size:20px;color:#fff;letter-spacing:-.2px;line-height:1.2;}
.hdr-sub{font-size:11.5px;color:rgba(255,255,255,.4);margin-top:2px;font-weight:300;}

.btn-primary{
    display:flex;align-items:center;gap:7px;
    background:var(--sage);color:#fff;border:none;
    padding:10px 18px;border-radius:10px;
    font-size:13px;font-weight:600;font-family:'DM Sans',sans-serif;
    cursor:pointer;transition:background .18s,box-shadow .18s,transform .1s;
    white-space:nowrap;box-shadow:0 2px 8px rgba(122,144,104,.35);
}
.btn-primary:hover{background:var(--sage-lt);box-shadow:0 4px 14px rgba(122,144,104,.45);}
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
.kpi-dot.green {background:var(--sage-pale);}
.kpi-dot.amber {background:var(--green-pale);}
.kpi-dot.gray  {background:var(--cream-deep);}
.kpi-label{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;}
.kpi-val{font-size:19px;font-weight:600;color:var(--ink);margin-top:1px;line-height:1;}
.kpi-val.green{color:var(--sage);}
.kpi-val.amber{color:var(--green);}

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

/* ── TABLE CARD ── */
.table-card{
    background:var(--surface-white);border:1px solid var(--border);
    border-radius:14px;overflow:hidden;box-shadow:var(--sh-sm);
}
table{width:100%;border-collapse:collapse;}
thead th{
    background:var(--sage);         /* sage green — matches dashboard table header */
    color:rgba(255,255,255,.92);
    padding:12px 16px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
    text-align:left;white-space:nowrap;
}
thead th:last-child{text-align:right;}
tbody tr{border-bottom:1px solid var(--border-lt);transition:background .12s;animation:rowIn .3s ease both;}
@keyframes rowIn{from{opacity:0;transform:translateX(-6px);}to{opacity:1;transform:translateX(0);}}
tbody tr:nth-child(1){animation-delay:.03s;}tbody tr:nth-child(2){animation-delay:.06s;}
tbody tr:nth-child(3){animation-delay:.09s;}tbody tr:nth-child(4){animation-delay:.12s;}
tbody tr:nth-child(5){animation-delay:.15s;}tbody tr:nth-child(6){animation-delay:.18s;}
tbody tr:nth-child(7){animation-delay:.21s;}tbody tr:nth-child(8){animation-delay:.24s;}
tbody tr:nth-child(9){animation-delay:.27s;}tbody tr:nth-child(10){animation-delay:.30s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--sage-pale);}
tbody td{padding:13px 16px;font-size:13.5px;color:var(--ink-mid);vertical-align:middle;}

.row-num{
    font-size:11px;color:var(--muted);font-weight:500;
    background:var(--cream-deep);width:26px;height:26px;
    border-radius:6px;display:inline-flex;align-items:center;justify-content:center;
}
.sup-name-wrap{display:flex;align-items:center;gap:10px;}
.sup-avatar{
    width:36px;height:36px;border-radius:9px;
    background:linear-gradient(135deg,var(--sage-pale) 0%,var(--sage-mid) 100%);
    display:flex;align-items:center;justify-content:center;
    font-size:14px;flex-shrink:0;font-weight:700;
    color:var(--sage);font-family:'Lora',serif;
}
.sup-name{font-weight:600;color:var(--ink);font-size:14px;}
.sup-id{font-size:11px;color:var(--muted);margin-top:1px;}
.date-chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--ink-soft);}

/* Cost pill — uses grand-total green from dashboard */
.cost-pill{
    display:inline-flex;align-items:center;gap:4px;
    background:var(--green-pale);border:1px solid var(--green-mid);
    color:var(--green);font-weight:700;font-size:13px;
    padding:4px 11px;border-radius:7px;
}

.row-actions{display:flex;gap:6px;justify-content:flex-end;}
.btn-row{
    padding:5px 13px;border-radius:7px;font-size:12px;font-weight:600;
    font-family:'DM Sans',sans-serif;cursor:pointer;
    transition:all .14s;border:1.5px solid transparent;
}
.btn-edit{color:var(--sage);background:var(--sage-pale);border-color:var(--sage-mid);}
.btn-edit:hover{background:var(--sage-mid);}
.btn-del{color:var(--red);background:var(--red-pale);border-color:#f0c5c0;}
.btn-del:hover{background:#f9dbd8;}

.empty-state{text-align:center;padding:60px 20px;}
.empty-icon{font-size:48px;margin-bottom:12px;opacity:.55;}
.empty-title{font-family:'Lora',serif;font-size:17px;color:var(--ink-mid);margin-bottom:6px;}
.empty-sub{font-size:13px;color:var(--muted);}

.tbl-footer{
    padding:11px 16px;border-top:1px solid var(--border-lt);
    background:var(--surface);font-size:12px;color:var(--muted);
    display:flex;align-items:center;justify-content:space-between;
}
.tbl-footer strong{color:var(--ink-soft);}

/* ── MODAL ── */
.modal-overlay{
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(30,30,30,.5);backdrop-filter:blur(5px);
    align-items:center;justify-content:center;
}
.modal-overlay.open{display:flex;}
.modal-card{
    background:var(--surface-white);border-radius:20px;
    width:400px;max-width:95vw;
    box-shadow:var(--sh-lg);overflow:hidden;
    animation:modalIn .22s cubic-bezier(.34,1.2,.64,1);
}
@keyframes modalIn{from{opacity:0;transform:translateY(16px) scale(.96);}to{opacity:1;transform:translateY(0) scale(1);}}
.modal-top{
    background:var(--sage);   /* matches page header */
    padding:22px 26px 20px;
    display:flex;align-items:center;justify-content:space-between;
}
.modal-top-left{display:flex;align-items:center;gap:12px;}
.modal-top-icon{
    width:36px;height:36px;
    background:rgba(122,144,104,.3);
    border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px;
}
.modal-title{font-family:'Lora',serif;font-size:17px;color:#fff;}
.modal-sub{font-size:11px;color:rgba(255,255,255,.4);margin-top:2px;}
.modal-close{background:none;border:none;color:rgba(255,255,255,.4);font-size:20px;cursor:pointer;padding:0;line-height:1;transition:color .13s;}
.modal-close:hover{color:#fff;}
.modal-body{padding:24px 26px;}
.form-group{margin-bottom:18px;}
.form-label{
    display:block;font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.6px;
    color:var(--muted);margin-bottom:7px;
}
.form-input{
    width:100%;padding:10px 14px;
    border:1.5px solid var(--border);border-radius:10px;
    font-size:14px;font-family:'DM Sans',sans-serif;
    color:var(--ink);background:var(--surface);
    outline:none;transition:border-color .15s,box-shadow .15s,background .15s;
}
.form-input:focus{
    border-color:var(--sage);background:var(--surface-white);
    box-shadow:0 0 0 3px rgba(122,144,104,.12);
}
.form-hint{font-size:11px;color:var(--muted);margin-top:5px;}
.modal-actions{display:flex;gap:10px;padding:0 26px 24px;}
.btn-cancel{
    flex:1;padding:11px;border-radius:10px;
    border:1.5px solid var(--border);background:var(--surface-white);
    color:var(--ink-soft);font-size:13px;font-weight:600;
    font-family:'DM Sans',sans-serif;cursor:pointer;transition:background .14s;
}
.btn-cancel:hover{background:var(--cream);}
.btn-save{
    flex:2;padding:11px;border-radius:10px;border:none;
    background:var(--sage);color:#fff;
    font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;
    cursor:pointer;transition:background .14s,transform .1s,box-shadow .14s;
    box-shadow:0 2px 8px rgba(122,144,104,.3);
}
.btn-save:hover{background:var(--sage-lt);box-shadow:0 4px 14px rgba(122,144,104,.4);}
.btn-save:active{transform:scale(.97);}

/* ── CONFIRM ── */
#confirmModal{
    display:none;position:fixed;inset:0;z-index:99999;
    background:rgba(30,30,30,.5);backdrop-filter:blur(5px);
    align-items:center;justify-content:center;
}
#confirmModal.open{display:flex;}
.confirm-card{
    background:var(--surface-white);border-radius:18px;overflow:hidden;
    width:340px;max-width:95vw;
    box-shadow:var(--sh-lg);animation:modalIn .2s ease;
}
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
#toast{
    position:fixed;bottom:22px;right:22px;z-index:999999;
    display:flex;align-items:center;gap:10px;
    background:var(--charcoal);color:#fff;
    padding:11px 18px;border-radius:11px;
    font-size:13px;font-weight:500;font-family:'DM Sans',sans-serif;
    box-shadow:var(--sh-lg);opacity:0;transform:translateY(10px);
    transition:opacity .22s,transform .22s;pointer-events:none;max-width:300px;
}
#toast.show{opacity:1;transform:translateY(0);}
#toast.t-success{background:var(--sage);}
#toast.t-error{background:var(--red);}
.toast-icon{font-size:15px;flex-shrink:0;}

::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}

@media(max-width:640px){
    .page-header,.body-wrap{padding:16px;}
    .kpi-strip{padding:0 16px;overflow-x:auto;}
    .kpi-card{min-width:140px;}
    thead th:nth-child(3),tbody td:nth-child(3){display:none;}
}
</style>
</head>
<body>

<div id="toast"><span class="toast-icon"></span><span id="toastMsg"></span></div>

<!-- CONFIRM DELETE -->
<div id="confirmModal">
    <div class="confirm-card">
        <div class="confirm-top">
            <div class="confirm-top-icon">🗑️</div>
            <h3>Remove Supplier?</h3>
        </div>
        <div class="confirm-body">
            <p id="confirmText">This supplier will be permanently removed from your records.</p>
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
                    <div class="modal-title" id="modalTitle">New Supplier</div>
                    <div class="modal-sub" id="modalSub">Fill in the supplier details</div>
                </div>
            </div>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <input type="hidden" id="editId">
        <div class="modal-body">
            <div class="form-group">
                <label class="form-label" for="supName">Supplier / Material Name</label>
                <input class="form-input" type="text" id="supName" placeholder="e.g. Pork Supplier Co.">
                <div class="form-hint">Business name or material description</div>
            </div>
            <div class="form-group">
                <label class="form-label" for="supCost">Manufacturing Cost (₱)</label>
                <input class="form-input" type="number" id="supCost" placeholder="0.00" min="0" step="0.01">
                <div class="form-hint">Total cost amount for this supplier</div>
            </div>

        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save" id="saveBtn">Save Entry</button>
        </div>
    </div>
</div>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="hdr-left">

        <div>
            <div class="hdr-title">Production Materials</div>
            <div class="hdr-sub">Raw material costs · Denver's Siomai Manufacturing</div>
        </div>
    </div>
    <button class="btn-primary" onclick="openModal()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Supplier
    </button>
</div>

<!-- KPI STRIP -->
<div class="kpi-strip">
    <div class="kpi-card">
        <div class="kpi-dot green">📦</div>
        <div>
            <div class="kpi-label">Total Suppliers</div>
            <div class="kpi-val green"><?php echo $totalCount; ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-dot amber">💰</div>
        <div>
            <div class="kpi-label">Total Manufacturing Cost</div>
            <div class="kpi-val amber">₱<?php echo number_format($totalCost, 2); ?></div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-dot gray">📊</div>
        <div>
            <div class="kpi-label">Avg Cost / Supplier</div>
            <div class="kpi-val"><?php echo $totalCount ? '₱'.number_format($avgCost,2) : '—'; ?></div>
        </div>
    </div>
</div>

<!-- BODY -->
<div class="body-wrap">
    <form method="GET" action="Supplier-Restocking.php" class="toolbar" id="filterForm" onsubmit="return false;">
        <div class="search-wrap">
            <span class="ico">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
            <input type="text" name="q" placeholder="Search suppliers…"
                value="<?php echo htmlspecialchars($search); ?>"
                oninput="debounce(()=>filterTable(),400)" id="searchInput">
        </div>
        <select name="sort" id="sortSelect" onchange="filterTable()">
            <option value="newest"    <?php if($sort==='newest')    echo 'selected';?>>Newest First</option>
            <option value="oldest"    <?php if($sort==='oldest')    echo 'selected';?>>Oldest First</option>
            <option value="cost_high" <?php if($sort==='cost_high') echo 'selected';?>>Cost: High → Low</option>
            <option value="cost_low"  <?php if($sort==='cost_low')  echo 'selected';?>>Cost: Low → High</option>
        </select>
        <span class="result-count">
            <?php echo $totalCount; ?> supplier<?php echo $totalCount!==1?'s':'';?>
            <?php if($search):?> for "<strong><?php echo htmlspecialchars($search);?></strong>"<?php endif;?>
        </span>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Supplier / Material</th>
                    <th>Date Added</th>
                    <th>Cost</th>
                    <th style="width:130px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($suppliers)):?>
                <tr><td colspan="5" style="padding:0">
                    <div class="empty-state">
                        <div class="empty-title"><?php echo $search?'No results found':'No suppliers yet';?></div>
                        <div class="empty-sub"><?php echo $search?'Try a different search term.':'Click "Add Supplier" to record your first material cost.';?></div>
                    </div>
                </td></tr>
            <?php else: foreach($suppliers as $i=>$s):?>
                <tr>
                    <td><span class="row-num"><?php echo $i+1;?></span></td>
                    <td>
                        <div class="sup-name-wrap">
                            <div class="sup-avatar"><?php echo mb_strtoupper(mb_substr($s['supplier_name'],0,1));?></div>
                            <div>
                                <div class="sup-name"><?php echo htmlspecialchars($s['supplier_name']);?></div>
                                <div class="sup-id">ID #<?php echo $s['id'];?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="date-chip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <?php echo date('M d, Y',strtotime($s['date_created']));?>
                        </span>
                    </td>
                    <td><span class="cost-pill">₱<?php echo number_format($s['sup_cost'],2);?></span></td>
                    <td>
                        <div class="row-actions">
                            <button class="btn-row btn-edit"
                                data-id="<?php echo $s['id'];?>"
                                data-name="<?php echo htmlspecialchars($s['supplier_name'], ENT_QUOTES);?>"
                                data-cost="<?php echo $s['sup_cost'];?>"
                                data-date="<?php echo date('Y-m-d', strtotime($s['date_created']));?>"
                                onclick="openEditBtn(this)">Edit</button>
                            <button class="btn-row btn-del"
                                data-id="<?php echo $s['id'];?>"
                                data-name="<?php echo htmlspecialchars($s['supplier_name'], ENT_QUOTES);?>"
                                onclick="askDeleteBtn(this)">Delete</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif;?>
            </tbody>
        </table>
        <?php if(!empty($suppliers)):?>
        <div class="tbl-footer">
            <span>Showing <strong><?php echo count($suppliers);?></strong> supplier<?php echo count($suppliers)!==1?'s':'';?></span>
            <span>Total: <strong>₱<?php echo number_format($totalCost,2);?></strong></span>
        </div>
        <?php endif;?>
    </div>
</div>

<script>
(function() {
    const URL = typeof currentPanelUrl !== 'undefined' ? currentPanelUrl : 'Supplier-Restocking.php';
    let _dbt, _delId = null;

    function toast(msg, type) { if (typeof showToast === 'function') showToast(msg, type); }
    function debounce(fn, ms) { clearTimeout(_dbt); _dbt = setTimeout(fn, ms); }

    function openAddModal() {
        document.getElementById('modalTitle').textContent = 'New Supplier';
        document.getElementById('modalSub').textContent = 'Fill in the supplier details';
        document.getElementById('modalIcon').textContent = '';
        document.getElementById('editId').value = '';
        document.getElementById('supName').value = '';
        document.getElementById('supCost').value = '';
        document.getElementById('saveBtn').textContent = 'Save Entry';
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('modalOverlay').classList.add('open');
        setTimeout(() => document.getElementById('supName').focus(), 120);
    }

    function openEditModal(id, name, cost) {
        document.getElementById('modalTitle').textContent = 'Edit Supplier';
        document.getElementById('modalSub').textContent = 'Update supplier information';
        document.getElementById('modalIcon').textContent = '✏️';
        document.getElementById('editId').value = id;
        document.getElementById('supName').value = name;
        document.getElementById('supCost').value = cost;
        document.getElementById('saveBtn').textContent = 'Save Changes';
        document.getElementById('saveBtn').disabled = false;
        document.getElementById('modalOverlay').classList.add('open');
        setTimeout(() => document.getElementById('supName').focus(), 120);
    }

    function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); }
    function closeConfirm() { _delId = null; document.getElementById('confirmModal').classList.remove('open'); }

    function reload() {
        if (typeof reloadCurrentPanel === 'function') reloadCurrentPanel();
        else filterTable();
    }

    function filterTable() {
        const q = document.getElementById('searchInput').value;
        const sort = document.getElementById('sortSelect').value;
        fetch(URL + '?q=' + encodeURIComponent(q) + '&sort=' + sort + '&ajax=1')
        .then(r => r.text()).then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const tc = doc.querySelector('.table-card');
            const ks = doc.querySelector('.kpi-strip');
            const rc = doc.querySelector('.result-count');
            if (tc) document.querySelector('.table-card').innerHTML = tc.innerHTML;
            if (ks) document.querySelector('.kpi-strip').innerHTML = ks.innerHTML;
            if (rc) document.querySelector('.result-count').innerHTML = rc.innerHTML;
        }).catch(() => toast('Filter error.', 'error'));
    }

    document.getElementById('saveBtn').addEventListener('click', function() {
        const id   = document.getElementById('editId').value;
        const name = document.getElementById('supName').value.trim();
        const cost = document.getElementById('supCost').value;
        if (!name) { toast('Please enter a supplier name.', 'error'); return; }
        if (!cost || parseFloat(cost) <= 0) { toast('Please enter a valid cost amount.', 'error'); return; }
        const params = new URLSearchParams({ action: id ? 'edit' : 'add', sup_name: name, sup_cost: cost });
        if (id) params.append('id', id);
        this.disabled = true; this.textContent = 'Saving…';
        const btn = this;
        fetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.text()).then(res => {
            if (res.trim() === 'success') {
                closeModal();
                toast(id ? 'Supplier updated!' : 'Supplier added!', 'success');
                reload();
            } else {
                toast(res.replace('error:', '') || 'Something went wrong.', 'error');
                btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Save Entry';
            }
        }).catch(() => { toast('Network error.', 'error'); btn.disabled = false; btn.textContent = id ? 'Save Changes' : 'Save Entry'; });
    });

    document.getElementById('confirmDelBtn').addEventListener('click', function() {
        if (!_delId) return;
        this.disabled = true; this.textContent = 'Deleting…';
        const btn = this;
        fetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'delete', id: _delId }).toString() })
        .then(r => r.text()).then(res => {
            closeConfirm();
            if (res.trim() === 'success') { toast('Supplier removed.', 'success'); reload(); }
            else { toast('Delete failed.', 'error'); btn.disabled = false; btn.textContent = 'Yes, Delete'; }
        }).catch(() => { toast('Network error.', 'error'); btn.disabled = false; btn.textContent = 'Yes, Delete'; });
    });

    document.getElementById('modalOverlay').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
    document.getElementById('confirmModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeConfirm(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeConfirm(); } });


    window.openModal      = openAddModal;
    window.openEditBtn    = btn => openEditModal(btn.dataset.id, btn.dataset.name, btn.dataset.cost);
    window.askDeleteBtn   = btn => {
        _delId = btn.dataset.id;
        document.getElementById('confirmText').textContent = '"' + btn.dataset.name + '" will be permanently removed and cannot be recovered.';
        document.getElementById('confirmModal').classList.add('open');
    };
    window.closeModal     = closeModal;
    window.closeConfirm   = closeConfirm;
    window.filterTable    = filterTable;
    window.debounce       = debounce;
})();
</script>
</body>
</html>