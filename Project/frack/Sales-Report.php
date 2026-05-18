<?php
session_start();
date_default_timezone_set('Asia/Manila');

$conn = new mysqli("localhost", "root", "", "dbview");
if ($conn->connect_error) die("DB error: " . $conn->connect_error);

$rawRole        = isset($_SESSION['Role']) ? $_SESSION['Role'] : 'guest';
$cleanRole      = strtolower(trim($rawRole));
$canSeeFinances = in_array($cleanRole, ['admin', 'owner']);
$displayName    = $_SESSION['Username'] ?? $_SESSION['user'] ?? 'User';

function mask($val) { return preg_replace('/[0-9]/', '#', $val); }
function mfmt($num, $can) { $s = '₱' . number_format($num, 2); return $can ? $s : mask($s); }

$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-d');
$start = $from . ' 00:00:00';
$end   = $to   . ' 23:59:59';

$r = $conn->query("SELECT SUM(price * sold_quantity) as rev, SUM(sold_quantity) as units FROM tbl_products WHERE status != 'disabled'");
$kpi = $r ? $r->fetch_assoc() : ['rev'=>0,'units'=>0];
$totalRevenue = (float)($kpi['rev'] ?? 0);
$totalUnits   = (int)($kpi['units'] ?? 0);

$bulkCount = 0; $bulkRevenue = 0; $bulkPending = 0;
$rb = $conn->query("SELECT COUNT(*) as cnt, SUM(total_amount) as rev, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pend FROM tbl_bulk_orders WHERE order_date BETWEEN '$from' AND '$to'");
if ($rb) { $d = $rb->fetch_assoc(); $bulkCount = (int)($d['cnt']??0); $bulkRevenue = (float)($d['rev']??0); $bulkPending = (int)($d['pend']??0); }

$totalExpenses = 0;
$re = $conn->query("SELECT SUM(sup_cost) as exp FROM tbl_suppliers WHERE date_created BETWEEN '$start' AND '$end'");
if ($re) { $d = $re->fetch_assoc(); $totalExpenses = (float)($d['exp']??0); }

$expiringCount = 0; $expiredCount = 0;
$rx = $conn->query("SELECT COUNT(*) as c FROM tbl_products WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
if ($rx) { $expiringCount = (int)($rx->fetch_assoc()['c']??0); }
$rx2 = $conn->query("SELECT COUNT(*) as c FROM tbl_products WHERE expiry_date < CURDATE()");
if ($rx2) { $expiredCount = (int)($rx2->fetch_assoc()['c']??0); }

$netProfit = $totalRevenue - $totalExpenses;

$productRows = [];
$rpr = $conn->query("SELECT product_name, sold_quantity, price, (price*sold_quantity) as revenue FROM tbl_products WHERE sold_quantity > 0 AND status != 'disabled' ORDER BY sold_quantity DESC");
if ($rpr) while ($row = $rpr->fetch_assoc()) $productRows[] = $row;

$bulkRows = [];
$rbr = @$conn->query("SELECT o.order_id, o.customer_name, o.order_date, o.delivery_date, o.total_amount, o.status, o.ordered_by, COUNT(i.item_id) as items FROM tbl_bulk_orders o LEFT JOIN tbl_bulk_order_items i ON o.order_id = i.order_id WHERE o.order_date BETWEEN '$from' AND '$to' GROUP BY o.order_id ORDER BY o.order_date DESC");
if ($rbr) while ($row = $rbr->fetch_assoc()) $bulkRows[] = $row;

$expenseRows = [];
$conn->query("SET SESSION sql_mode = ''");
$rer = @$conn->query("SELECT supplier_name, sup_cost, date_created FROM tbl_suppliers WHERE date_created BETWEEN '$start' AND '$end' ORDER BY date_created DESC");
if ($rer) while ($row = $rer->fetch_assoc()) $expenseRows[] = $row;

$expiryRows = [];
$rxr = $conn->query("SELECT product_name, stock_quantity, expiry_date, DATEDIFF(expiry_date, CURDATE()) as days_left FROM tbl_products WHERE expiry_date IS NOT NULL ORDER BY expiry_date ASC LIMIT 30");
if ($rxr) while ($row = $rxr->fetch_assoc()) $expiryRows[] = $row;

$auditRows = [];
$rar = @$conn->query("SELECT User, Role, Module, Activity, Status, Date_Time AS Timestamp FROM tbl_audit WHERE Date_Time BETWEEN '$start' AND '$end' ORDER BY Timestamp DESC LIMIT 200");
if ($rar) while ($row = $rar->fetch_assoc()) $auditRows[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Denver's Siomai</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --ink:#1a1a2e; --ink-light:#4a4a6a; --ink-muted:#8888aa;
    --cream:#faf9f6; --surface:#ffffff; --border:#e8e6f0;
    --sage:#8fa08a; --sage-dark:#6b7d66;
    --gold:#c9943a; --red:#c0392b; --blue:#2471a3; --green:#1e8449;
    --shadow-sm:0 2px 8px rgba(26,26,46,.06); --shadow-md:0 4px 24px rgba(26,26,46,.10);
    --radius:14px;
}
body { font-family:'DM Sans',sans-serif; background:var(--cream); color:var(--ink); min-height:100vh; }

/* ── FULL WIDTH — no centering cap ── */
.page-wrap { max-width:100%; margin:0; padding:24px 20px 60px; }

.rpt-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:20px; margin-bottom:32px; padding-bottom:24px; border-bottom:2px solid var(--border); }
.rpt-title-block h1 { font-family:'DM Serif Display',serif; font-size:38px; line-height:1.1; color:var(--ink); letter-spacing:-.5px; }
.rpt-title-block h1 span { color:var(--sage-dark); font-style:italic; }
.rpt-title-block p { margin-top:6px; font-size:13px; color:var(--ink-muted); }
.toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.btn-pdf { display:flex; align-items:center; gap:7px; padding:10px 20px; border-radius:20px; border:none; background:var(--ink); color:#fff; font-size:13px; font-weight:700; font-family:'DM Sans',sans-serif; cursor:pointer; transition:background .18s; box-shadow:var(--shadow-sm); }
.btn-pdf:hover { background:#2d2d50; }

.kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin-bottom:32px; }
.kpi-card { background:var(--surface); border-radius:var(--radius); padding:22px 24px; border:1.5px solid var(--border); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .18s,box-shadow .18s; }
.kpi-card:hover { transform:translateY(-2px); box-shadow:var(--shadow-md); }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--kpi-accent,var(--sage)); }
.kpi-card .kpi-icon { font-size:22px; margin-bottom:10px; display:block; }
.kpi-card .kpi-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:var(--ink-muted); margin-bottom:6px; }
.kpi-card .kpi-value { font-size:26px; font-weight:700; color:var(--ink); line-height:1; }
.kpi-card .kpi-sub { font-size:11px; color:var(--ink-muted); margin-top:6px; }

.tab-nav { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:24px; overflow-x:auto; }
.tab-btn { padding:11px 20px; border:none; background:transparent; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; color:var(--ink-muted); cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; white-space:nowrap; display:flex; align-items:center; gap:7px; }
.tab-btn .tab-count { background:var(--border); color:var(--ink-light); font-size:10px; padding:2px 7px; border-radius:10px; font-weight:700; }
.tab-btn:hover { color:var(--ink); }
.tab-btn.active { color:var(--ink); border-bottom-color:var(--ink); }
.tab-btn.active .tab-count { background:var(--ink); color:#fff; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

.section-card { background:var(--surface); border-radius:var(--radius); border:1.5px solid var(--border); box-shadow:var(--shadow-sm); overflow:hidden; margin-bottom:20px; }
.section-card-head { padding:18px 24px; border-bottom:1.5px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.section-card-head h3 { font-size:14px; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:8px; }
.section-card-head .section-total { font-size:13px; font-weight:700; color:var(--green); }

.tbl-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead tr { background:#f7f6f2; }
th { padding:11px 18px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--ink-muted); border-bottom:1.5px solid var(--border); white-space:nowrap; }
td { padding:13px 18px; font-size:13px; color:var(--ink-light); border-bottom:1px solid #f0eef8; }
tr:last-child td { border-bottom:none; }
tbody tr:hover { background:#f9f8fd; }
td.bold { font-weight:700; color:var(--ink); }
td.mono { font-feature-settings:"tnum"; }

.badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-green  { background:#e8f8ee; color:var(--green); }
.badge-orange { background:#fef3e2; color:#b7660a; }
.badge-red    { background:#fde8e8; color:var(--red); }
.badge-blue   { background:#e3f0fb; color:var(--blue); }
.badge-gray   { background:#f0eef8; color:var(--ink-muted); }

.empty-state { padding:52px 24px; text-align:center; color:var(--ink-muted); }
.empty-state .empty-icon { font-size:36px; margin-bottom:10px; }
.empty-state p { font-size:13px; }

.summary-row { display:flex; gap:20px; flex-wrap:wrap; padding:20px 24px; border-top:2px solid var(--border); background:#f9f8fd; }
.summary-item { font-size:13px; color:var(--ink-light); }
.summary-item strong { color:var(--ink); font-size:14px; }

#pdf-loading { display:none; position:fixed; inset:0; background:rgba(26,26,46,.5); z-index:9999; justify-content:center; align-items:center; flex-direction:column; gap:16px; }
#pdf-loading.show { display:flex; }
#pdf-loading .spinner { width:44px; height:44px; border:4px solid rgba(255,255,255,.3); border-top-color:#fff; border-radius:50%; animation:spin .7s linear infinite; }
#pdf-loading p { color:#fff; font-size:14px; font-weight:600; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>
</head>
<body>
<input type="hidden" id="reportSelfUrl" value="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
<div id="pdf-loading"><div class="spinner"></div><p>Generating PDF report…</p></div>

<div class="page-wrap">

    <div class="rpt-header">
        <div class="rpt-title-block">
            <h1>Sales &amp; <span>Operations</span> Report</h1>
            <p>Denver's Siomai &nbsp;·&nbsp; Generated <?php echo date('F d, Y \a\t h:i A'); ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($displayName); ?></p>
        </div>
        <div class="toolbar">
            <button class="btn-pdf" onclick="openPdfDateModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                Download PDF
            </button>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card" style="--kpi-accent:var(--green);">
            <span class="kpi-icon">💰</span><div class="kpi-label">Total Revenue</div>
            <div class="kpi-value"><?php echo mfmt($totalRevenue,$canSeeFinances); ?></div>
            <div class="kpi-sub"><?php echo number_format($totalUnits); ?> units sold</div>
        </div>
        <div class="kpi-card" style="--kpi-accent:var(--red);">
            <span class="kpi-icon">📦</span><div class="kpi-label">Expenses</div>
            <div class="kpi-value"><?php echo mfmt($totalExpenses,$canSeeFinances); ?></div>
            <div class="kpi-sub">Manufacturing costs</div>
        </div>
        <div class="kpi-card" style="--kpi-accent:var(--sage);">
            <span class="kpi-icon">📈</span><div class="kpi-label">Net Profit</div>
            <div class="kpi-value" style="color:<?php echo $netProfit>=0?'var(--green)':'var(--red)'; ?>"><?php echo mfmt($netProfit,$canSeeFinances); ?></div>
            <div class="kpi-sub"><?php echo $canSeeFinances?($totalRevenue>0?round(($netProfit/$totalRevenue)*100,1).'% margin':'—'):'###%'; ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:var(--blue);">
            <span class="kpi-icon">🧾</span><div class="kpi-label">Bulk Orders</div>
            <div class="kpi-value"><?php echo $bulkCount; ?></div>
            <div class="kpi-sub"><?php echo $bulkPending; ?> pending &nbsp;·&nbsp; <?php echo mfmt($bulkRevenue,$canSeeFinances); ?></div>
        </div>
        <div class="kpi-card" style="--kpi-accent:var(--gold);">
            <span class="kpi-icon">⏰</span><div class="kpi-label">Expiring Soon</div>
            <div class="kpi-value" style="color:var(--gold);"><?php echo $expiringCount; ?></div>
            <div class="kpi-sub"><?php echo $expiredCount; ?> already expired</div>
        </div>
        <div class="kpi-card" style="--kpi-accent:#8b5cf6;">
            <span class="kpi-icon">🗂️</span><div class="kpi-label">Audit Entries</div>
            <div class="kpi-value"><?php echo count($auditRows); ?>+</div>
            <div class="kpi-sub">Recent activity log</div>
        </div>
    </div>

    <div class="tabs-wrap">
        <div class="tab-nav">
            <button class="tab-btn active" data-tab="sales"><span>📊</span> Product Sales <span class="tab-count"><?php echo count($productRows); ?></span></button>
            <button class="tab-btn" data-tab="bulk"><span>🧾</span> Bulk Orders <span class="tab-count"><?php echo count($bulkRows); ?></span></button>
            <button class="tab-btn" data-tab="expenses"><span>💸</span> Expenses <span class="tab-count"><?php echo count($expenseRows); ?></span></button>
            <button class="tab-btn" data-tab="expiry"><span>⏰</span> Expiry Monitor <span class="tab-count"><?php echo count($expiryRows); ?></span></button>
            <button class="tab-btn" data-tab="audit"><span>🗂️</span> Audit Log <span class="tab-count"><?php echo count($auditRows); ?></span></button>
        </div>

        <!-- PRODUCT SALES -->
        <div class="tab-panel active" id="tab-sales">
            <div class="section-card">
                <div class="section-card-head">
                    <h3><span class="section-icon">📊</span> Product Sales Breakdown</h3>
                    <span class="section-total">Total: <?php echo mfmt($totalRevenue,$canSeeFinances); ?></span>
                </div>
                <div class="tbl-wrap"><table id="tbl-sales">
                    <thead><tr><th>#</th><th>Product Name</th><th>Units Sold</th><th>Unit Price</th><th>Total Revenue</th><th>Share</th></tr></thead>
                    <tbody>
                    <?php if($productRows): $rank=1; foreach($productRows as $row):
                        $share=$totalUnits>0?round(($row['sold_quantity']/$totalUnits)*100,1):0; ?>
                    <tr>
                        <td class="bold"><?php echo $rank++; ?></td>
                        <td class="bold"><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td class="mono"><?php echo number_format($row['sold_quantity']); ?></td>
                        <td class="mono"><?php echo mfmt($row['price'],$canSeeFinances); ?></td>
                        <td class="mono bold"><?php echo mfmt($row['revenue'],$canSeeFinances); ?></td>
                        <td><div style="display:flex;align-items:center;gap:8px;"><div style="background:#e8e6f0;border-radius:4px;height:6px;width:80px;overflow:hidden;"><div style="background:var(--sage);height:100%;width:<?php echo $share; ?>%;"></div></div><span style="font-size:12px;color:var(--ink-muted);"><?php echo $share; ?>%</span></div></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">📭</div><p>No product sales data available.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
                <?php if($productRows): ?>
                <div class="summary-row">
                    <div class="summary-item">Total Products: <strong><?php echo count($productRows); ?></strong></div>
                    <div class="summary-item">Total Units: <strong><?php echo number_format($totalUnits); ?></strong></div>
                    <div class="summary-item">Total Revenue: <strong><?php echo mfmt($totalRevenue,$canSeeFinances); ?></strong></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- BULK ORDERS -->
        <div class="tab-panel" id="tab-bulk">
            <div class="section-card">
                <div class="section-card-head">
                    <h3><span class="section-icon">🧾</span> Bulk Order Transactions</h3>
                    <span class="section-total"><?php echo $bulkCount; ?> orders &nbsp;·&nbsp; <?php echo mfmt($bulkRevenue,$canSeeFinances); ?></span>
                </div>
                <div class="tbl-wrap"><table id="tbl-bulk">
                    <thead><tr><th>Order #</th><th>Customer</th><th>Order Date</th><th>Delivery Date</th><th>Items</th><th>Amount</th><th>Placed By</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if($bulkRows): foreach($bulkRows as $row):
                        if($row['status']==='Completed') $bc='badge-green';
                        elseif($row['status']==='Pending') $bc='badge-orange';
                        elseif($row['status']==='Cancelled') $bc='badge-red';
                        else $bc='badge-gray'; ?>
                    <tr>
                        <td class="bold">#<?php echo $row['order_id']; ?></td>
                        <td class="bold"><?php echo htmlspecialchars($row['customer_name']); ?></td>
                        <td><?php echo date('M d, Y',strtotime($row['order_date'])); ?></td>
                        <td><?php echo $row['delivery_date']?date('M d, Y',strtotime($row['delivery_date'])):'<span style="color:var(--ink-muted)">—</span>'; ?></td>
                        <td><?php echo $row['items']; ?> items</td>
                        <td class="mono bold"><?php echo mfmt($row['total_amount'],$canSeeFinances); ?></td>
                        <td><?php echo htmlspecialchars($row['ordered_by']??'—'); ?></td>
                        <td><span class="badge <?php echo $bc; ?>"><?php echo $row['status']; ?></span></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="8"><div class="empty-state"><div class="empty-icon">📭</div><p>No bulk orders for the selected period.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
                <?php if($bulkRows): ?>
                <div class="summary-row">
                    <div class="summary-item">Total Orders: <strong><?php echo $bulkCount; ?></strong></div>
                    <div class="summary-item">Pending: <strong style="color:var(--gold);"><?php echo $bulkPending; ?></strong></div>
                    <div class="summary-item">Completed: <strong style="color:var(--green);"><?php echo count(array_filter($bulkRows,function($r){return $r['status']==='Completed';})); ?></strong></div>
                    <div class="summary-item">Total Value: <strong><?php echo mfmt($bulkRevenue,$canSeeFinances); ?></strong></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- EXPENSES -->
        <div class="tab-panel" id="tab-expenses">
            <div class="section-card">
                <div class="section-card-head">
                    <h3><span class="section-icon">💸</span> Manufacturing &amp; Supply Expenses</h3>
                    <span class="section-total" style="color:var(--red);">Total: <?php echo mfmt($totalExpenses,$canSeeFinances); ?></span>
                </div>
                <div class="tbl-wrap"><table id="tbl-expenses">
                    <thead><tr><th>#</th><th>Supplier / Source</th><th>Description</th><th>Amount</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php if($expenseRows): $n=1; foreach($expenseRows as $row): ?>
                    <tr>
                        <td><?php echo $n++; ?></td>
                        <td class="bold"><?php echo htmlspecialchars($row['supplier_name']??'—'); ?></td>
                        <td>—</td>
                        <td class="mono bold" style="color:var(--red);"><?php echo mfmt($row['sup_cost'],$canSeeFinances); ?></td>
                        <td><?php echo date('M d, Y',strtotime($row['date_created'])); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">📭</div><p>No expense records for the selected period.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
                <?php if($expenseRows): ?>
                <div class="summary-row">
                    <div class="summary-item">Expense Entries: <strong><?php echo count($expenseRows); ?></strong></div>
                    <div class="summary-item">Total Spent: <strong style="color:var(--red);"><?php echo mfmt($totalExpenses,$canSeeFinances); ?></strong></div>
                    <div class="summary-item">Net Profit: <strong style="color:<?php echo $netProfit>=0?'var(--green)':'var(--red)';?>;"><?php echo mfmt($netProfit,$canSeeFinances); ?></strong></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- EXPIRY -->
        <div class="tab-panel" id="tab-expiry">
            <div class="section-card">
                <div class="section-card-head">
                    <h3><span class="section-icon">⏰</span> Expiry Date Monitor</h3>
                    <span class="section-total" style="color:var(--gold);"><?php echo $expiringCount; ?> expiring in 30 days &nbsp;·&nbsp; <?php echo $expiredCount; ?> expired</span>
                </div>
                <div class="tbl-wrap"><table id="tbl-expiry">
                    <thead><tr><th>Product</th><th>Stock (pcs)</th><th>Expiry Date</th><th>Days Left</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if($expiryRows): foreach($expiryRows as $row):
                        $days=(int)$row['days_left'];
                        if($days<0){$badge='badge-red';$label='Expired';}
                        elseif($days<=7){$badge='badge-red';$label='Critical';}
                        elseif($days<=30){$badge='badge-orange';$label='Expiring Soon';}
                        else{$badge='badge-green';$label='OK';} ?>
                    <tr>
                        <td class="bold"><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td class="mono"><?php echo number_format($row['stock_quantity']); ?></td>
                        <td><?php echo date('M d, Y',strtotime($row['expiry_date'])); ?></td>
                        <td class="mono" style="color:<?php echo $days<0?'var(--red)':($days<=30?'var(--gold)':'var(--green)'); ?>;font-weight:700;"><?php echo $days<0?abs($days).' days ago':$days.' days'; ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo $label; ?></span></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">✅</div><p>No expiry issues found.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- AUDIT LOG -->
        <div class="tab-panel" id="tab-audit">
            <div class="section-card">
                <div class="section-card-head">
                    <h3><span class="section-icon">🗂️</span> System Audit Log</h3>
                    <span style="font-size:12px;color:var(--ink-muted);">Last 200 entries</span>
                </div>
                <div class="tbl-wrap"><table id="tbl-audit">
                    <thead><tr><th>Timestamp</th><th>User</th><th>Role</th><th>Module</th><th>Activity</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if($auditRows): foreach($auditRows as $row):
                        $sBadge=strtolower($row['Status']??'')==='success'?'badge-green':'badge-red'; ?>
                    <tr>
                        <td style="font-size:12px;white-space:nowrap;"><?php echo isset($row['Timestamp'])?date('M d, Y h:i A',strtotime($row['Timestamp'])):'—'; ?></td>
                        <td class="bold"><?php echo htmlspecialchars($row['User']??'—'); ?></td>
                        <td><span class="badge badge-blue"><?php echo htmlspecialchars($row['Role']??'—'); ?></span></td>
                        <td><?php echo htmlspecialchars($row['Module']??'—'); ?></td>
                        <td style="font-size:12px;max-width:320px;"><?php echo htmlspecialchars($row['Activity']??'—'); ?></td>
                        <td><span class="badge <?php echo $sBadge; ?>"><?php echo $row['Status']??'—'; ?></span></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">📭</div><p>No audit records found.</p></div></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

function openPdfDateModal() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('pdfFrom').value = today;
    document.getElementById('pdfTo').value   = today;
    document.getElementById('pdfDateModal').style.display = 'flex';
}
function closePdfDateModal() { document.getElementById('pdfDateModal').style.display = 'none'; }
function pdfSelectAll(checked) {
    document.querySelectorAll('#pdfDateModal input[type=checkbox]').forEach(cb => {
        cb.checked = checked;
        cb.closest('label').style.borderColor = checked ? '#4a90d9' : '#e8e8e8';
    });
}
function confirmPdfDownload() {
    const from = document.getElementById('pdfFrom').value;
    const to   = document.getElementById('pdfTo').value;
    if (!from || !to) { alert('Please select both dates.'); return; }
    if (from > to)    { alert('Start date must be before end date.'); return; }
    const sections = {};
    ['cover','sales','bulk','expenses','expiry','audit'].forEach(s => { sections[s] = document.getElementById('pdfSec_'+s)?.checked ?? true; });
    if (!Object.values(sections).some(Boolean)) { alert('Please select at least one section.'); return; }
    closePdfDateModal();
    document.getElementById('pdf-loading').classList.add('show');
    const url = (document.getElementById('reportSelfUrl')?.value || '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>') + '?from='+from+'&to='+to;
    fetch(url).then(r=>r.text()).then(html=>{
        const tempDoc = new DOMParser().parseFromString(html,'text/html');
        setTimeout(()=>_buildPDF(from,to,tempDoc,sections),80);
    }).catch(()=>{ document.getElementById('pdf-loading').classList.remove('show'); alert('Failed to fetch report data.'); });
}
document.getElementById('pdfDateModal')?.addEventListener('click',function(e){ if(e.target===this) closePdfDateModal(); });

const FROM_DATE='<?php echo date('M d, Y',strtotime($from)); ?>';
const TO_DATE='<?php echo date('M d, Y',strtotime($to)); ?>';
const CAN_SEE=<?php echo $canSeeFinances?'true':'false'; ?>;

function _buildPDF(from,to,srcDoc,sections){
    const sec=sections||{cover:true,sales:true,bulk:true,expenses:true,expiry:true,audit:true};
    const _doc=srcDoc||document;
    const{jsPDF}=window.jspdf;
    const doc=new jsPDF({unit:'mm',format:'a4'});
    const pW=210,pH=297; let y=0;
    const SAGE=[143,160,138],INK=[26,26,46],GREEN=[30,132,73],RED=[192,57,43],GOLD=[201,148,58],BLUE=[36,113,163],PURP=[90,50,140],LGRAY=[245,244,250],MGRAY=[220,218,230],WHITE=[255,255,255];
    function newPage(){doc.addPage();y=22;doc.setFontSize(7);doc.setTextColor(...MGRAY);doc.text("Denver's Siomai — Confidential Report",pW/2,pH-8,{align:'center'});doc.text('Page '+doc.getCurrentPageInfo().pageNumber,pW-14,pH-8,{align:'right'});}
    function checkY(n){if(y+n>pH-16)newPage();}
    function section(title,color){checkY(12);doc.setFillColor(...color);doc.roundedRect(12,y,pW-24,9,2,2,'F');doc.setFontSize(9);doc.setFont('helvetica','bold');doc.setTextColor(...WHITE);doc.text(title,18,y+6);y+=13;}
    function getRows(tableId,colCount,srcDoc){const rows=[];const qd=srcDoc||document;qd.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{const tds=tr.querySelectorAll('td');if(tds.length>=colCount&&!tr.querySelector('.empty-state'))rows.push(Array.from(tds).slice(0,colCount).map(td=>td.textContent.trim().replace(/₱/g,'P')));});return rows;}
    function makeTable(head,rows,headColor,colStyles){if(!rows.length){checkY(8);doc.setFontSize(8);doc.setTextColor(...MGRAY);doc.text('No records for this period.',16,y);y+=10;return;}doc.autoTable({startY:y,head:[head],body:rows,theme:'grid',headStyles:{fillColor:headColor,textColor:WHITE,fontSize:8,fontStyle:'bold',cellPadding:3},bodyStyles:{fontSize:8,textColor:INK,cellPadding:2.5},alternateRowStyles:{fillColor:LGRAY},columnStyles:colStyles||{},margin:{left:12,right:12},tableLineColor:MGRAY,tableLineWidth:0.1});y=doc.lastAutoTable.finalY+8;}

    if(sec.cover){
        doc.setFillColor(...SAGE);doc.rect(0,0,pW,48,'F');
        doc.setFontSize(24);doc.setFont('helvetica','bold');doc.setTextColor(...WHITE);doc.text("DENVER'S SIOMAI",pW/2,20,{align:'center'});
        doc.setFontSize(11);doc.setFont('helvetica','normal');doc.text('Sales & Operations Report',pW/2,30,{align:'center'});
        doc.setFontSize(9);
        const _fd=d=>{if(!d)return'';const[yr,mo,dy]=d.split('-');return['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][+mo-1]+' '+dy+', '+yr;};
        const hF=from?_fd(from):FROM_DATE,hT=to?_fd(to):TO_DATE;
        doc.text(hF===hT?hF:hF+'  —  '+hT,pW/2,40,{align:'center'});
        const tRev=(_doc.querySelector('#tab-sales .section-total')?.textContent?.replace('Total: ','')||'—').replace(/₱/g,'P');
        const tExp=(_doc.querySelector('#tab-expenses .section-total')?.textContent?.replace('Total: ','')||'—').replace(/₱/g,'P');
        const kpis=[{label:'TOTAL REVENUE',val:tRev,color:GREEN},{label:'TOTAL EXPENSES',val:tExp,color:RED},{label:'BULK ORDERS',val:getRows('tbl-bulk',5,_doc).length+' orders',color:BLUE},{label:'EXPIRING SOON',val:getRows('tbl-expiry',3,_doc).length+' items',color:GOLD},{label:'AUDIT ENTRIES',val:getRows('tbl-audit',6,_doc).length+' entries',color:PURP}];
        const cW=(pW-24-8)/5,cH=24,cTop=56;
        kpis.forEach((k,i)=>{const cx=12+i*(cW+2);doc.setFillColor(...WHITE);doc.roundedRect(cx,cTop,cW,cH,1.5,1.5,'F');doc.setFillColor(...k.color);doc.roundedRect(cx,cTop,cW,2,0,0,'F');doc.setFontSize(6);doc.setFont('helvetica','bold');doc.setTextColor(...MGRAY);doc.text(k.label,cx+cW/2,cTop+9,{align:'center'});doc.setFontSize(9);doc.setFont('helvetica','bold');doc.setTextColor(...INK);doc.text(String(k.val),cx+cW/2,cTop+18,{align:'center'});});
        y=cTop+cH+8;doc.setDrawColor(...MGRAY);doc.setLineWidth(0.2);doc.line(12,y,pW-12,y);y+=6;
        const colL=12,colR=pW/2+4,colW=pW/2-16;
        doc.setFontSize(8);doc.setFont('helvetica','bold');doc.setTextColor(...INK);doc.text('TOP SELLING PRODUCTS',colL,y);doc.setDrawColor(...GREEN);doc.setLineWidth(0.5);doc.line(colL,y+1.5,colL+44,y+1.5);y+=5;
        const sR=getRows('tbl-sales',5,_doc).slice(0,6);
        if(sR.length){doc.autoTable({startY:y,head:[['Product','Units','Unit Price','Total Revenue']],body:sR.map(r=>[r[1],r[2],r[3],r[4]]).filter(r=>r[0]),theme:'plain',headStyles:{fillColor:[240,245,240],textColor:MGRAY,fontSize:6.5,fontStyle:'bold',cellPadding:2},bodyStyles:{fontSize:7,textColor:INK,cellPadding:2},margin:{left:colL,right:pW/2+2},tableWidth:colW,columnStyles:{0:{cellWidth:colW*0.38},1:{cellWidth:colW*0.15,halign:'center'},2:{cellWidth:colW*0.22,halign:'right'},3:{cellWidth:colW*0.25,halign:'right'}}});}
        const leftY=sR.length?doc.lastAutoTable.finalY:y+10;
        const rSY=y;doc.setFontSize(8);doc.setFont('helvetica','bold');doc.setTextColor(...INK);doc.text('BULK ORDERS SUMMARY',colR,rSY);doc.setDrawColor(...BLUE);doc.setLineWidth(0.5);doc.line(colR,rSY+1.5,colR+42,rSY+1.5);
        const bR=getRows('tbl-bulk',5,_doc).slice(0,6);
        if(bR.length){doc.autoTable({startY:rSY+5,head:[['Customer','Date','Amount','Status']],body:bR.map(r=>[r[1],r[2],r[4],r[5]]),theme:'plain',headStyles:{fillColor:[240,243,250],textColor:MGRAY,fontSize:6.5,fontStyle:'bold',cellPadding:2},bodyStyles:{fontSize:7,textColor:INK,cellPadding:2},margin:{left:colR,right:12},tableWidth:colW,columnStyles:{0:{cellWidth:colW*0.35},1:{cellWidth:colW*0.22},2:{cellWidth:colW*0.23,halign:'right'},3:{cellWidth:colW*0.20,halign:'center'}}});}
        const rightY=bR.length?doc.lastAutoTable.finalY:rSY+15;
        y=Math.max(leftY,rightY)+6;doc.setDrawColor(...MGRAY);doc.setLineWidth(0.2);doc.line(12,y,pW-12,y);
        doc.setFontSize(7);doc.setFont('helvetica','normal');doc.setTextColor(...MGRAY);doc.text('Generated: <?php echo date('M d, Y \a\t h:i A'); ?>   |   Prepared by: <?php echo htmlspecialchars($displayName); ?>',pW/2,pH-14,{align:'center'});
        doc.setDrawColor(...MGRAY);doc.setLineWidth(0.2);doc.rect(8,8,pW-16,pH-16);
        doc.setFontSize(7);doc.setTextColor(...MGRAY);doc.text("Denver's Siomai — Confidential Report",pW/2,pH-4,{align:'center'});doc.text('Page 1',pW-14,pH-4,{align:'right'});
    }

    const hasData=sec.sales||sec.bulk||sec.expenses||sec.expiry||sec.audit;
    if(hasData){
        if(sec.cover){newPage();}else{y=22;}
        if(sec.sales){section('PRODUCT SALES BREAKDOWN',SAGE);makeTable(['#','Product','Units Sold','Unit Price','Revenue'],getRows('tbl-sales',5,_doc),INK,{2:{halign:'right'},3:{halign:'right'},4:{halign:'right',fontStyle:'bold'}});}
        if(sec.bulk){checkY(20);section('BULK ORDER TRANSACTIONS',BLUE);const bRaw=getRows('tbl-bulk',8,_doc);makeTable(['Order#','Customer','Order Date','Delivery','Amount','By','Status'],bRaw.map(r=>[r[0],r[1],r[2],r[3],r[5],r[6],r[7]]),BLUE,{4:{halign:'right',fontStyle:'bold'}});}
        if(sec.expenses){checkY(20);section('EXPENSES & MANUFACTURING COSTS',RED);makeTable(['#','Supplier','Amount','Date'],getRows('tbl-expenses',5,_doc).map(r=>[r[0],r[1],r[3],r[4]]),RED,{2:{halign:'right',fontStyle:'bold',textColor:RED}});}
        if(sec.expiry){checkY(20);section('EXPIRY DATE MONITOR',GOLD);makeTable(['Product','Stock','Expiry Date','Days Left','Status'],getRows('tbl-expiry',5,_doc),GOLD,{});}
        if(sec.audit){checkY(20);section('SYSTEM AUDIT LOG',PURP);makeTable(['Timestamp','User','Role','Module','Activity','Status'],getRows('tbl-audit',6,_doc),PURP,{4:{cellWidth:50},0:{cellWidth:28}});}
    }

    const pF=(from||FROM_DATE).replace(/-/g,''),pT=(to||TO_DATE).replace(/-/g,'');
    doc.save('DenversSiomai_Report_'+pF+'_to_'+pT+'.pdf');
    document.getElementById('pdf-loading').classList.remove('show');
}
</script>

<!-- PDF Modal -->
<div id="pdfDateModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:18px;padding:28px;box-shadow:0 24px 64px rgba(0,0,0,.25);width:420px;max-width:95vw;font-family:'DM Sans',sans-serif;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <div><h3 style="margin:0;font-size:17px;color:#1a1a1a;font-weight:700;">Download PDF Report</h3><p style="margin:4px 0 0;font-size:12px;color:#999;">Configure what to include</p></div>
            <button onclick="closePdfDateModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#aaa;line-height:1;">✕</button>
        </div>
        <div style="background:#f8f9fa;border-radius:10px;padding:14px;margin-bottom:16px;">
            <p style="margin:0 0 10px;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.5px;">Date Range</p>
            <div style="display:flex;gap:10px;align-items:center;">
                <div style="flex:1;"><label style="font-size:11px;color:#888;display:block;margin-bottom:3px;">FROM</label><input type="date" id="pdfFrom" style="width:100%;padding:8px 10px;border:1.5px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:'DM Sans',sans-serif;box-sizing:border-box;"></div>
                <span style="color:#bbb;font-size:16px;margin-top:16px;">→</span>
                <div style="flex:1;"><label style="font-size:11px;color:#888;display:block;margin-bottom:3px;">TO</label><input type="date" id="pdfTo" style="width:100%;padding:8px 10px;border:1.5px solid #e0e0e0;border-radius:7px;font-size:13px;font-family:'DM Sans',sans-serif;box-sizing:border-box;"></div>
            </div>
        </div>
        <div style="background:#f8f9fa;border-radius:10px;padding:14px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <p style="margin:0;font-size:11px;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.5px;">Sections to Include</p>
                <div style="display:flex;gap:10px;">
                    <button onclick="pdfSelectAll(true)" style="background:none;border:none;font-size:11px;color:#4a90d9;cursor:pointer;font-family:'DM Sans',sans-serif;">Select All</button>
                    <span style="color:#ddd;">|</span>
                    <button onclick="pdfSelectAll(false)" style="background:none;border:none;font-size:11px;color:#e57373;cursor:pointer;font-family:'DM Sans',sans-serif;">Clear</button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <?php foreach([['cover','📊','Cover Page','Summary & KPIs'],['sales','🛒','Product Sales','Revenue breakdown'],['bulk','📦','Bulk Orders','Order transactions'],['expenses','💰','Expenses','Manufacturing costs'],['expiry','⏰','Expiry Monitor','Expiring products'],['audit','📋','Audit Log','Activity history']] as $s): ?>
                <label style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e8e8e8;border-radius:8px;padding:9px 10px;cursor:pointer;" onmouseover="this.style.borderColor='#4a90d9'" onmouseout="this.querySelector('input').checked?this.style.borderColor='#4a90d9':this.style.borderColor='#e8e8e8'">
                    <input type="checkbox" id="pdfSec_<?php echo $s[0]; ?>" checked style="width:15px;height:15px;accent-color:#1a1a1a;cursor:pointer;flex-shrink:0;" onchange="this.closest('label').style.borderColor=this.checked?'#4a90d9':'#e8e8e8'">
                    <span style="font-size:14px;"><?php echo $s[1]; ?></span>
                    <div><div style="font-size:12px;font-weight:600;color:#1a1a1a;"><?php echo $s[2]; ?></div><div style="font-size:10px;color:#aaa;"><?php echo $s[3]; ?></div></div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:10px;">
            <button onclick="closePdfDateModal()" style="flex:1;padding:11px;border-radius:9px;border:1.5px solid #e0e0e0;background:#fff;font-size:13px;font-weight:600;cursor:pointer;font-family:'DM Sans',sans-serif;color:#666;">Cancel</button>
            <button onclick="confirmPdfDownload()" style="flex:2;padding:11px;border-radius:9px;border:none;background:#1a1a1a;color:#fff;font-size:13px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;">📄 Download PDF</button>
        </div>
    </div>
</div>

</body>
</html>