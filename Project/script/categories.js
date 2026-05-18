// ============================================================
//  categories.js  –  Enhanced Dashboard & Panel Controller
// ============================================================

if (typeof window.defaultDashboardHTML === 'undefined') {
    window.defaultDashboardHTML = document.querySelector('.center-top-box')?.innerHTML || '';
}

let currentPanelUrl = window.location.href;
let currentProductId   = null;
let currentProductName = '';

// ============================================================
//  TOAST NOTIFICATION  (replaces all alert() calls)
// ============================================================
(function buildToast() {
    if (document.getElementById('_sysToast')) return;
    const t = document.createElement('div');
    t.id = '_sysToast';
    t.style.cssText = `
        position:fixed; bottom:28px; right:28px; z-index:999999;
        display:none; padding:13px 22px; border-radius:10px;
        font-family:sans-serif; font-size:14px; font-weight:500;
        color:#fff; max-width:340px; box-shadow:0 10px 30px rgba(0,0,0,.15);
        opacity:0; transform:translateY(10px);
        transition:opacity .25s ease, transform .25s ease;
        pointer-events:none;
    `;
    document.body.appendChild(t);
})();

function showToast(msg, type = 'success') {
    const t = document.getElementById('_sysToast');
    if (!t) return;
    t.textContent = msg;
    t.style.background = type === 'success' ? '#38a169'
                       : type === 'error'   ? '#e53e3e'
                       : type === 'warning' ? '#d69e2e'
                       : '#3182ce';
    t.style.display   = 'block';
    clearTimeout(t._out);
    // slight delay so display:block has painted
    requestAnimationFrame(() => {
        t.style.opacity   = '1';
        t.style.transform = 'translateY(0)';
    });
    t._out = setTimeout(() => {
        t.style.opacity   = '0';
        t.style.transform = 'translateY(10px)';
        setTimeout(() => { t.style.display = 'none'; }, 280);
    }, 3000);
}

// ============================================================
//  LOADING OVERLAY  (shown during fetch operations)
// ============================================================
(function buildLoader() {
    if (document.getElementById('_sysLoader')) return;
    const l = document.createElement('div');
    l.id = '_sysLoader';
    l.innerHTML = `<div style="
        width:36px;height:36px;border:4px solid rgba(255,255,255,.3);
        border-top-color:#fff;border-radius:50%;
        animation:_spin .7s linear infinite;
    "></div>`;
    l.style.cssText = `
        position:fixed;inset:0;background:rgba(0,0,0,.35);
        display:none;align-items:center;justify-content:center;
        z-index:999998;backdrop-filter:blur(2px);
    `;
    const style = document.createElement('style');
    style.textContent = `@keyframes _spin{to{transform:rotate(360deg)}}`;
    document.head.appendChild(style);
    document.body.appendChild(l);
})();

function showLoader() {
    const l = document.getElementById('_sysLoader');
    if (l) l.style.display = 'flex';
}
function hideLoader() {
    const l = document.getElementById('_sysLoader');
    if (l) l.style.display = 'none';
}

// ============================================================
//  MODAL HELPERS
// ============================================================
function openModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) { m.style.display = 'block'; m.classList.add('open'); }
}
function closeModal(modalId) {
    const m = document.getElementById(modalId);
    if (m) { m.style.display = 'none'; m.classList.remove('open'); }
}

// Close modals when clicking outside
document.addEventListener('click', e => {
    ['addModal','priceModal','stockModal'].forEach(id => {
        const m = document.getElementById(id);
        if (m && e.target === m) closeModal(id);
    });
});

// Close modals with Escape key
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    ['addModal','priceModal','stockModal'].forEach(closeModal);
});

// ============================================================
//  LOAD PRODUCTS INTO PRICE MODAL DROPDOWN
// ============================================================
function loadProductsForPriceModal() {
    const select = document.getElementById('productSelect');
    if (!select) return;

    select.innerHTML = '<option value="">Loading…</option>';
    select.disabled  = true;

    fetch(currentPanelUrl + '?action=getProducts')
        .then(r => {
            if (!r.ok) throw new Error('Network response was not ok');
            return r.json();
        })
        .then(products => {
            select.innerHTML = '<option value="">-- Choose a product --</option>';
            if (!products.length) {
                select.innerHTML = '<option value="">No products found</option>';
                return;
            }
            products.forEach(p => {
                const opt = document.createElement('option');
                opt.value       = p.id;
                opt.textContent = p.name;
                if (p.id == currentProductId) opt.selected = true;
                select.appendChild(opt);
            });
        })
        .catch(err => {
            select.innerHTML = '<option value="">Failed to load</option>';
            showToast('Could not load products.', 'error');
            console.error('loadProductsForPriceModal error:', err);
        })
        .finally(() => { select.disabled = false; });
}

function openPriceModalForProduct(productId, productName) {
    currentProductId   = productId;
    currentProductName = productName;
    loadProductsForPriceModal();
    openModal('priceModal');
}

// ============================================================
//  DISABLE / ENABLE PRODUCT
// ============================================================
function disableProduct(productId) {
    if (!confirm('Disable this product? It will be hidden from orders.')) return;
    const params = new URLSearchParams({ action: 'disable', product_id: productId });
    sendRequest(params, 'Product disabled.', 'Product disable failed.');
}

function enableProduct(productId) {
    const params = new URLSearchParams({ action: 'enable', product_id: productId });
    sendRequest(params, 'Product restored!', 'Product restore failed.');
}

// ============================================================
//  FORM SUBMIT HANDLERS
// ============================================================
function submitAddForm(event) {
    if (event) event.preventDefault();
    const form = document.getElementById('addForm');
    if (!form) return;

    const name  = form.querySelector('[name="flavorName"]')?.value.trim();
    const price = form.querySelector('[name="flavorPrice"]')?.value;
    const stock = form.querySelector('[name="flavorStock"]')?.value;

    if (!name)  { showToast('Please enter a flavor name.', 'error');  return; }
    if (!price) { showToast('Please enter a price.', 'error');         return; }
    if (stock === undefined || stock === '') {
        showToast('Please enter initial stock.', 'error'); return;
    }

    const params = new URLSearchParams(new FormData(form));
    params.append('action', 'add');

    sendRequest(params, 'Flavor added successfully!', null, () => {
        closeModal('addModal');
        form.reset();
    });
}

function submitPriceForm(event) {
    if (event) event.preventDefault();
    const form = document.getElementById('priceForm');
    if (!form) return;

    const fd       = new FormData(form);
    const prodId   = fd.get('productName');
    const newPrice = fd.get('newPrice');

    if (!prodId)   { showToast('Please select a product.', 'error');  return; }
    if (!newPrice) { showToast('Please enter a new price.', 'error'); return; }

    const params = new URLSearchParams({
        action:     'price',
        product_id: prodId,
        newPrice:   newPrice,
    });

    sendRequest(params, 'Price updated!', null, () => {
        closeModal('priceModal');
        form.reset();
    });
}

// ============================================================
//  UNIFIED AJAX REQUEST SENDER
// ============================================================
function sendRequest(params, successMsg = 'Done!', errorMsg = null, onSuccess = null) {
    showLoader();

    fetch(currentPanelUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    params.toString(),
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(data => {
        const clean = data.trim();
        if (clean === 'success' || clean.includes('success')) {
            showToast(successMsg, 'success');
            if (typeof onSuccess === 'function') onSuccess();
            reloadCurrentPanel();
        } else {
            const msg = errorMsg || ('Error: ' + clean);
            showToast(msg, 'error');
            console.warn('sendRequest server error:', clean);
        }
    })
    .catch(err => {
        showToast('Network error. Please try again.', 'error');
        console.error('sendRequest fetch error:', err);
    })
    .finally(hideLoader);
}

// ============================================================
//  RELOAD CURRENT PANEL  (re-executes injected <script> tags)
// ============================================================
function reloadCurrentPanel() {
    const box = document.querySelector('.center-top-box');
    if (!box || !currentPanelUrl) return;

    showLoader();

    fetch(currentPanelUrl)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(html => {
            box.style.opacity = '0';
            box.innerHTML     = html;
            _execScripts(box);
            requestAnimationFrame(() => { box.style.opacity = '1'; });
        })
        .catch(err => {
            showToast('Failed to reload panel.', 'error');
            console.error('reloadCurrentPanel error:', err);
        })
        .finally(hideLoader);
}

// ============================================================
//  HELPER: re-execute <script> tags after innerHTML replace
// ============================================================
function _execScripts(container) {
    container.querySelectorAll('script').forEach(old => {
        const s = document.createElement('script');
        Array.from(old.attributes).forEach(a => s.setAttribute(a.name, a.value));
        s.textContent = old.textContent;
        old.parentNode.replaceChild(s, old);
    });
}

// ============================================================
//  WINDOW-LEVEL ACTION SENDER  (used by inline onclick handlers)
// ============================================================
window.sendAction = function(action, extraParams = {}) {
    const params = new URLSearchParams({ action, ...extraParams });
    sendRequest(params);
};

// ============================================================
//  GO HOME  –  Restore dashboard HTML
// ============================================================
function gohome() {
    const box = document.querySelector('.center-top-box');
    if (!box) return;

    box.style.transition = 'opacity .25s ease';
    box.style.opacity    = '0';

    setTimeout(() => {
        box.innerHTML       = defaultDashboardHTML;
        currentPanelUrl     = window.location.href;
        box.style.opacity   = '1';
        _execScripts(box);
    }, 260);
}

// ============================================================
//  SHOW PANEL  (fetch & inject panel into .center-top-box)
// ============================================================
function showPanel(type) {
    const box = document.querySelector('.center-top-box');
    if (!box) return;

    const map = {
        flavor:   './Stock.php',
        stock:    './Stock-Tracking.php',
        sales:    './Sales-Recording.php',
        report:   './Sales-Report.php',
        supplier: './Supplier-Restocking.php',
        expiry:   './Expiry-Date-Monitoring.php',
        logbook:  './logbook.php',
    };

    const file = map[type] || '';
    if (!file) { box.innerHTML = '<p style="padding:20px;color:#999;">No content available.</p>'; return; }

    // Set BEFORE fetch so injected panel scripts read the correct URL immediately
    currentPanelUrl      = file;
    box.style.transition = 'opacity .2s ease';
    box.style.opacity    = '0';

    showLoader();

    fetch(file)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(html => {
            box.innerHTML = html;
            _execScripts(box);
            requestAnimationFrame(() => { box.style.opacity = '1'; });
        })
        .catch(err => {
            box.innerHTML   = `<p style="padding:20px;color:#e53e3e;">⚠️ Failed to load panel. Please try again.</p>`;
            box.style.opacity = '1';
            showToast('Failed to load panel.', 'error');
            console.error('showPanel error:', err);
        })
        .finally(hideLoader);
}

// ============================================================
//  TABLE ROW HELPERS  (Sales Recording panel)
// ============================================================
window.addRow = function() {
    const tbody = document.querySelector('#itemsTable tbody');
    if (!tbody) return;
    const rowCount = tbody.rows.length;
    const row      = document.createElement('tr');
    row.innerHTML  = `
        <td>${rowCount + 1}</td>
        <td><input type="text"   name="p_id[]"></td>
        <td><input type="text"   name="p_name[]" required></td>
        <td><input type="number" name="qty[]"   class="qty"   oninput="window.calculateRow(this)" required></td>
        <td><input type="number" name="price[]" class="price" oninput="window.calculateRow(this)" step="0.01" required></td>
        <td><input type="number" name="amount[]" class="amount" readonly></td>
        <td><input type="text"   name="row_remarks[]"></td>
    `;
    tbody.appendChild(row);
};

window.calculateRow = function(input) {
    const row   = input.closest('tr');
    const qty   = parseFloat(row.querySelector('.qty').value)   || 0;
    const price = parseFloat(row.querySelector('.price').value) || 0;
    row.querySelector('.amount').value = (qty * price).toFixed(2);
};

window.submitSales = function(event) {
    event.preventDefault();
    if (!confirm('Confirm upload? This will update inventory.')) return;

    const form     = document.getElementById('salesForm');
    const formData = new FormData(form);
    formData.append('action', 'record_sales');

    showLoader();

    fetch(currentPanelUrl, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === 'success') {
                showToast('Sales recorded successfully!', 'success');
                reloadCurrentPanel();
            } else {
                showToast('Error: ' + data, 'error');
            }
        })
        .catch(err => {
            showToast('System error. Please try again.', 'error');
            console.error('submitSales error:', err);
        })
        .finally(hideLoader);
};

// ============================================================
//  DASHBOARD CHART / STATS  (Sales Report panel)
// ============================================================
async function updateDashboard() {
    const filter = document.getElementById('filter');
    if (!filter) return;

    showLoader();

    try {
        const r    = await fetch(`Sales_report.php?ajax=1&timeframe=${filter.value}`);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const data = await r.json();

        if (data.error) {
            showToast('Dashboard error: ' + data.error, 'error');
            console.error('Dashboard error:', data.error);
            return;
        }

        const total = data.total_revenue || 1;
        const p     = Math.round((data.categories.Pork     / total) * 100);
        const j     = Math.round((data.categories.Japanese / total) * 100);
        const s     = Math.round((data.categories.Shark    / total) * 100);

        const pEl = document.getElementById('porkVal');
        const jEl = document.getElementById('beefVal');
        const sEl = document.getElementById('sharksVal');
        if (pEl) pEl.innerText = p;
        if (jEl) jEl.innerText = j;
        if (sEl) sEl.innerText = s;

        const chart = document.getElementById('pieChart');
        if (chart) {
            chart.style.background = (p + j + s > 0)
                ? `conic-gradient(#9faa95 0% ${p}%, #8fa08a ${p}% ${p+j}%, #d1d1c4 ${p+j}% 100%)`
                : '#eee';
        }

        const sellersBody = document.getElementById('topSellersBody');
        if (sellersBody) {
            sellersBody.innerHTML = data.top_sellers.slice(0, 3).map(item => `
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:12px 0;"><strong>${item.product_name}</strong></td>
                    <td style="text-align:right;">&#8369;${parseFloat(item.revenue).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                </tr>
            `).join('');
        }

        const txBody = document.getElementById('recentTransactionsBody');
        if (txBody) {
            txBody.innerHTML = data.recent.map((item, i) => `
                <tr>
                    <td>#00${i + 1}</td>
                    <td>${item.product_name}</td>
                    <td>${item.sold_quantity}</td>
                    <td>&#8369;${parseFloat(item.price).toFixed(2)}</td>
                    <td>&#8369;${(item.sold_quantity * item.price).toFixed(2)}</td>
                </tr>
            `).join('');
        }

    } catch (err) {
        showToast('Failed to load dashboard data.', 'error');
        console.error('updateDashboard error:', err);
    } finally {
        hideLoader();
    }
}