<?php
$conn = new mysqli("localhost", "root", "", "dbView");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── CHART FILTER API ──────────────────────────────────────────
if (isset($_GET['chart_filter'])) {
    header('Content-Type: application/json');
    $period = $_GET['chart_filter'];

    // tbl_products uses sold_quantity as a running total — no date column.
    // For period filtering, use tbl_audit or tbl_sales if available.
    // Fallback: always return sold_quantity (all-time) since no date field exists on products.
    $dateFilter = '';
    switch ($period) {
        case 'weekly':    $dateFilter = "AND Date_Time >= DATE_SUB(NOW(), INTERVAL 7 DAY)";    break;
        case 'monthly':   $dateFilter = "AND Date_Time >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";  break;
        case 'quarterly': $dateFilter = "AND Date_Time >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";  break;
        case 'yearly':    $dateFilter = "AND Date_Time >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";   break;
        default:          $dateFilter = ''; break; // all time
    }

    // Try tbl_audit for sales activity counts as proxy; fall back to tbl_products
    $rows = [];
    $res  = $conn->query("
        SELECT p.product_name, p.sold_quantity
        FROM tbl_products p
        WHERE p.status != 'disabled' AND p.sold_quantity > 0
        ORDER BY p.sold_quantity DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $rows[] = ['product_name' => $r['product_name'], 'sold_quantity' => (int)$r['sold_quantity']];
    echo json_encode($rows);
    $conn->close();
    exit;
}


// Top 3 frequently ordered
$topResult = $conn->query("
    SELECT product_name, price, stock_quantity, sold_quantity, minimum_stock
    FROM tbl_products
    WHERE status != 'disabled'
    ORDER BY sold_quantity DESC
    LIMIT 3
");

// All products for chart + summary — sorted highest to lowest
$graphResult = $conn->query("
    SELECT product_name, sold_quantity, stock_quantity, minimum_stock
    FROM tbl_products
    WHERE status != 'disabled'
    ORDER BY sold_quantity DESC
");

$graphData  = [];
$totalSales = 0;
while ($row = $graphResult->fetch_assoc()) {
    $graphData[] = $row;
    if ($row['sold_quantity'] > 0) $totalSales += $row['sold_quantity'];
}

function stringToColor($string) {
    $hash = md5($string);
    $hue  = hexdec(substr($hash, 0, 4)) % 360;
    return "hsl($hue, 55%, 72%)";
}

$gradientParts      = [];
$currentPercentage  = 0;
$legendColors       = [];

foreach ($graphData as $data) {
    $color = stringToColor($data['product_name']);
    $legendColors[] = $color; // keep index aligned with graphData
    if ($data['sold_quantity'] <= 0) {
        $gradientParts[] = null; // placeholder to keep index
        continue;
    }
    $percent        = ($totalSales > 0) ? ($data['sold_quantity'] / $totalSales) * 100 : 0;
    $nextPercentage = $currentPercentage + $percent;
    $gradientParts[] = "$color {$currentPercentage}% {$nextPercentage}%";
    $currentPercentage = $nextPercentage;
}
$gradientParts = array_filter($gradientParts); // remove nulls
$conicGradient = implode(', ', $gradientParts);
$totalStock    = array_sum(array_column($graphData, 'stock_quantity'));

// All active products for full inventory table
$allResult = $conn->query("
    SELECT product_name, price, stock_quantity, sold_quantity, minimum_stock, status
    FROM tbl_products
    WHERE status != 'disabled'
    ORDER BY stock_quantity ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
            --t:        0.2s ease;
        }

        .st-wrap {
            font-family: 'Noto Sans', sans-serif;
            color: var(--text);
            padding: 4px 0;
        }

        /* ── SECTION LABEL ── */
        .section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--muted);
            margin-bottom: 12px;
        }

        /* ── TOP 3 CARDS ── */
        .top-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .rank-card {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: transform var(--t);
            animation: cardIn .35s ease both;
        }
        .rank-card:nth-child(2) { animation-delay: .06s; }
        .rank-card:nth-child(3) { animation-delay: .12s; }
        .rank-card:hover { transform: translateY(-3px); }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .rank-badge {
            position: absolute;
            top: 14px; right: 14px;
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 800; color: #fff;
        }
        .rank-1 { background: #d69e2e; }
        .rank-2 { background: #718096; }
        .rank-3 { background: #c05621; }

        .rank-card h3 {
            font-size: 16px; font-weight: 700;
            color: var(--text); margin-bottom: 12px;
            padding-right: 40px;
        }
        .rank-meta {
            display: flex; flex-direction: column; gap: 6px;
        }
        .rank-meta-row {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 13px; color: var(--muted);
            padding: 6px 10px;
            background: var(--bg);
            border-radius: 6px;
        }
        .rank-meta-row strong { color: var(--text); font-weight: 700; }

        .sold-bar-wrap {
            margin-top: 12px;
        }
        .sold-bar-label {
            display: flex; justify-content: space-between;
            font-size: 11px; color: var(--muted); margin-bottom: 5px;
        }
        .sold-bar-track {
            height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden;
        }
        .sold-bar-fill {
            height: 100%; background: var(--sage);
            border-radius: 10px;
            transition: width 1s ease;
        }

        /* ── BOTTOM GRID ── */
        .bottom-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
            align-items: stretch;
        }

        .panel {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 22px 24px;
            box-shadow: var(--shadow);
            animation: cardIn .35s ease both;
            animation-delay: .18s;
        }
        .panel-title {
            font-size: 14px; font-weight: 700;
            color: var(--text); margin-bottom: 18px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* ── PIE CHART ── */
        .chart-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px; flex-wrap: wrap; gap: 10px;
        }
        .filter-tabs {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .filter-tab {
            padding: 5px 13px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
            border: 1.5px solid #ccc; background: #f0f0ec;
            color: var(--muted); cursor: pointer;
            transition: all .15s ease;
        }
        .filter-tab:hover  { border-color: var(--sage); color: var(--sage-dk); }
        .filter-tab.active { background: var(--sage); border-color: var(--sage-dk); color: #fff; }

        .chart-wrap {
            display: flex; align-items: center; gap: 0; width: 100%;
        }
        .pie-container {
            flex: 1; display: flex; align-items: center; justify-content: center; min-height: 200px;
        }
        .pie {
            width: 200px; height: 200px;
            border-radius: 50%;
            background: conic-gradient(<?= $totalSales > 0 ? $conicGradient : '#ccc 0% 100%' ?>);
            box-shadow: 0 4px 16px rgba(0,0,0,.12);
            flex-shrink: 0;
            position: relative;
            transition: background 0.4s ease;
        }
        .pie-hole {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 90px; height: 90px;
            background: var(--surface);
            border-radius: 50%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
        }
        .pie-hole span:first-child { font-size: 9px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .pie-hole span:last-child  { font-size: 18px; font-weight: 800; color: var(--text); }

        .legend-wrap { flex: 1; padding-left: 24px; }
        .legend { list-style: none; display: flex; flex-direction: column; gap: 10px; }
        .legend-item {
            display: flex; align-items: center; gap: 10px; font-size: 13px;
            padding: 8px 10px; border-radius: 8px; background: var(--bg);
            transition: background .15s;
        }
        .legend-item:hover { background: var(--sage-lt); }
        .legend-dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
        .legend-name { flex: 1; color: var(--text); font-weight: 500; }
        .legend-qty  { font-size: 12px; color: var(--muted); }
        .legend-pct  { font-weight: 800; color: var(--text); font-size: 13px; min-width: 36px; text-align: right; }

        /* ── INVENTORY SUMMARY ── */
        .summary-stat {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 14px;
            background: var(--bg);
            border-radius: 8px;
            margin-bottom: 10px;
        }
        .summary-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .summary-stat .label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
        .summary-stat .val   { font-size: 18px; font-weight: 800; color: var(--text); margin-top: 1px; }

        .attention-wrap { margin-top: 14px; }
        .attention-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 8px; }
        .attention-chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chip {
            padding: 5px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
        }
        .chip-red    { background: var(--red-lt);   color: var(--red); }
        .chip-amber  { background: var(--amber-lt); color: var(--amber); }
        .chip-green  { background: var(--green-lt); color: var(--green); font-size: 13px; }

        /* ── FULL INVENTORY TABLE ── */
        .table-panel {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            animation: cardIn .35s ease both;
            animation-delay: .24s;
        }
        .table-panel-header {
            padding: 14px 20px;
            border-bottom: 2px solid #ccc;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px;
            color: var(--muted); background: #f0f0ec;
            display: flex; align-items: center; justify-content: space-between;
        }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 11px 16px; text-align: left;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.7px;
            color: var(--muted); background: #f7f7f4;
            border-bottom: 1px solid #e2e8f0;
        }
        tbody tr { border-bottom: 1px solid #f0f0ec; transition: background var(--t); }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--sage-lt); }
        tbody td { padding: 11px 16px; font-size: 13px; color: var(--text); }

        .status-pill {
            display: inline-block; padding: 3px 10px;
            border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .pill-ok    { background: var(--green-lt); color: var(--green); }
        .pill-low   { background: var(--amber-lt); color: var(--amber); }
        .pill-out   { background: var(--red-lt);   color: var(--red); }

        .stock-mini-bar {
            display: flex; align-items: center; gap: 8px;
        }
        .mini-track {
            flex: 1; height: 5px; background: #e2e8f0;
            border-radius: 10px; overflow: hidden;
        }
        .mini-fill { height: 100%; border-radius: 10px; }
        .fill-ok  { background: var(--green); }
        .fill-low { background: var(--amber); }
        .fill-out { background: var(--red); }
    </style>
</head>

<div class="st-wrap">

    <!-- Top 3 -->
    <div class="section-label">Frequently Ordered Flavors</div>
    <div class="top-grid">
        <?php
        $rank = 1;
        $maxSold = null;
        $topResult->data_seek(0);
        $topRows = [];
        while ($p = $topResult->fetch_assoc()) $topRows[] = $p;
        if (!empty($topRows)) $maxSold = $topRows[0]['sold_quantity'];

        foreach ($topRows as $product):
            $pct = ($maxSold > 0) ? round(($product['sold_quantity'] / $maxSold) * 100) : 0;
        ?>
        <div class="rank-card">
            <span class="rank-badge rank-<?= $rank ?>"><?= $rank ?></span>
            <h3><?= htmlspecialchars($product['product_name']) ?></h3>
            <div class="rank-meta">
                <div class="rank-meta-row">
                    <span>Price</span>
                    <strong>₱<?= number_format($product['price'], 2) ?></strong>
                </div>
                <div class="rank-meta-row">
                    <span>Stock Left</span>
                    <strong><?= number_format($product['stock_quantity']) ?> packs</strong>
                </div>
                <div class="rank-meta-row">
                    <span>Total Sold</span>
                    <strong><?= number_format($product['sold_quantity']) ?></strong>
                </div>
            </div>
            <div class="sold-bar-wrap">
                <div class="sold-bar-label">
                    <span>Sales Share</span>
                    <span><?= $pct ?>%</span>
                </div>
                <div class="sold-bar-track">
                    <div class="sold-bar-fill" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
        </div>
        <?php $rank++; endforeach; ?>
    </div>

    <!-- Bottom: Chart + Summary -->
    <div class="bottom-grid">

        <!-- Pie Chart -->
        <div class="panel">
            <div class="chart-panel-header">
                <div class="panel-title" style="margin-bottom:0;">Sales Distribution</div>
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="setFilter(this,'weekly')">Weekly</button>
                    <button class="filter-tab" onclick="setFilter(this,'monthly')">Monthly</button>
                    <button class="filter-tab" onclick="setFilter(this,'quarterly')">Quarterly</button>
                    <button class="filter-tab" onclick="setFilter(this,'yearly')">Yearly</button>
                    <button class="filter-tab" onclick="setFilter(this,'all')">All Time</button>
                </div>
            </div>

            <div id="chartArea">
            <?php if ($totalSales > 0): ?>
            <div class="chart-wrap">
                <div class="pie-container">
                    <div class="pie" id="pieChart">
                        <div class="pie-hole">
                            <span>Total</span>
                            <span id="pieTotalLabel"><?= number_format($totalSales) ?></span>
                        </div>
                    </div>
                </div>
                <div class="legend-wrap">
                    <ul class="legend" id="chartLegend">
                        <?php foreach ($graphData as $i => $data): ?>
                        <li class="legend-item">
                            <span class="legend-dot" style="background:<?= $legendColors[$i] ?>"></span>
                            <span class="legend-name"><?= htmlspecialchars($data['product_name']) ?></span>
                            <span class="legend-qty"><?= number_format($data['sold_quantity']) ?> sold</span>
                            <span class="legend-pct"><?= round(($data['sold_quantity'] / $totalSales) * 100) ?>%</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php else: ?>
                <p style="color:var(--muted);font-size:13px;padding:20px 0;">No sales data recorded yet.</p>
            <?php endif; ?>
            </div>
        </div>

        <!-- Inventory Summary -->
        <div class="panel">
            <div class="panel-title">Inventory Summary</div>

            <div class="summary-stat">
                <div class="summary-icon" style="background:#e2e8f0;">📦</div>
                <div style="flex:1;">
                    <div class="label">Total Units in Stock</div>
                    <div class="val"><?= number_format($totalStock) ?></div>
                </div>
            </div>

            <?php
            $outCount  = 0; $lowCount  = 0; $okCount  = 0;
            $outItems  = []; $lowItems  = []; $okItems  = [];
            foreach ($graphData as $item) {
                if ($item['stock_quantity'] == 0)                                    { $outCount++; $outItems[] = $item['product_name']; }
                elseif ($item['stock_quantity'] < $item['minimum_stock'])            { $lowCount++; $lowItems[] = $item['product_name'] . ' (' . $item['stock_quantity'] . ')'; }
                else                                                                  { $okCount++;  $okItems[]  = $item['product_name']; }
            }
            ?>

            <!-- Healthy -->
            <div class="summary-stat">
                <div class="summary-icon" style="background:var(--green-lt);">✅</div>
                <div style="flex:1;">
                    <div class="label">Healthy Stock</div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:3px;">
                        <span class="val" style="color:var(--green)"><?= $okCount ?></span>
                        <?php foreach ($okItems as $n): ?>
                            <span style="background:var(--green-lt);color:var(--green);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;"><?= htmlspecialchars($n) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Low -->
            <div class="summary-stat">
                <div class="summary-icon" style="background:var(--amber-lt);">⚠️</div>
                <div style="flex:1;">
                    <div class="label">Low Stock</div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:3px;">
                        <span class="val" style="color:var(--amber)"><?= $lowCount ?></span>
                        <?php foreach ($lowItems as $n): ?>
                            <span style="background:var(--amber-lt);color:var(--amber);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;"><?= htmlspecialchars($n) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Out of stock -->
            <div class="summary-stat">
                <div class="summary-icon" style="background:var(--red-lt);">🚫</div>
                <div style="flex:1;">
                    <div class="label">Out of Stock</div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:3px;">
                        <span class="val" style="color:var(--red)"><?= $outCount ?></span>
                        <?php foreach ($outItems as $n): ?>
                            <span style="background:var(--red-lt);color:var(--red);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;"><?= htmlspecialchars($n) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Full Inventory Table -->
    <div class="table-panel">
        <div class="table-panel-header">
            <span>Full Inventory</span>
            <span><?= $allResult->num_rows ?> products</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Stock Level</th>
                    <th>Sold</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $allResult->fetch_assoc()):
                    $qty     = (int)$row['stock_quantity'];
                    $min     = (int)$row['minimum_stock'];
                    $maxDisp = max($qty, $min * 2, 100);
                    $fillPct = min(100, round(($qty / $maxDisp) * 100));
                    if ($qty === 0)       { $sc = 'out'; $pill = 'pill-out'; $label = 'Out of Stock'; $fc = 'fill-out'; }
                    elseif ($qty <= $min) { $sc = 'low'; $pill = 'pill-low'; $label = 'Low Stock';    $fc = 'fill-low'; }
                    else                  { $sc = 'ok';  $pill = 'pill-ok';  $label = 'In Stock';     $fc = 'fill-ok';  }
                ?>
                <tr>
                    <td style="font-weight:600;"><?= htmlspecialchars($row['product_name']) ?></td>
                    <td>₱<?= number_format($row['price'], 2) ?></td>
                    <td><?= number_format($qty) ?> packs</td>
                    <td>
                        <div class="stock-mini-bar">
                            <div class="mini-track">
                                <div class="mini-fill <?= $fc ?>" style="width:<?= $fillPct ?>%"></div>
                            </div>
                            <span style="font-size:11px;color:var(--muted);width:30px;text-align:right;"><?= $fillPct ?>%</span>
                        </div>
                    </td>
                    <td><?= number_format($row['sold_quantity']) ?></td>
                    <td><span class="status-pill <?= $pill ?>"><?= $label ?></span></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

<script>
    const ST_URL = window.location.pathname;

    function stringToHsl(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
        return `hsl(${Math.abs(hash) % 360}, 55%, 72%)`;
    }

    function setFilter(btn, period) {
        document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        loadChart(period);
    }

    function loadChart(period) {
        const chartArea = document.getElementById('chartArea');
        chartArea.style.transition = 'opacity .2s';
        chartArea.style.opacity    = '0.35';

        fetch(ST_URL + '?chart_filter=' + period)
            .then(r => r.json())
            .then(data => {
                chartArea.style.opacity = '1';

                if (!data.length) {
                    document.getElementById('pieChart').style.background = '#e2e8f0';
                    document.getElementById('pieTotalLabel').textContent  = '0';
                    document.getElementById('chartLegend').innerHTML =
                        '<li style="color:#999;font-size:13px;padding:8px 0;">No data for this period.</li>';
                    return;
                }

                const total = data.reduce((s, d) => s + d.sold_quantity, 0);
                let cur = 0;
                const parts = data.map(d => {
                    const color = stringToHsl(d.product_name);
                    const pct   = (d.sold_quantity / total) * 100;
                    const part  = `${color} ${cur}% ${cur + pct}%`;
                    cur += pct;
                    return { color, part, ...d };
                });

                document.getElementById('pieChart').style.background =
                    `conic-gradient(${parts.map(p => p.part).join(', ')})`;
                document.getElementById('pieTotalLabel').textContent = total.toLocaleString();
                document.getElementById('chartLegend').innerHTML = parts
                    .sort((a, b) => b.sold_quantity - a.sold_quantity)
                    .map(p => `
                    <li class="legend-item">
                        <span class="legend-dot" style="background:${p.color}"></span>
                        <span class="legend-name">${p.product_name}</span>
                        <span class="legend-qty">${p.sold_quantity.toLocaleString()} sold</span>
                        <span class="legend-pct">${Math.round((p.sold_quantity / total) * 100)}%</span>
                    </li>
                `).join('');
            })
            .catch(() => { chartArea.style.opacity = '1'; });
    }

    document.addEventListener('DOMContentLoaded', () => loadChart('weekly'));
</script>

</div>