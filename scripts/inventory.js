// Inventory page enhancements
let productSearchTimeout;
let productSearchCache = {};
const API_KEY = window.APP_CONFIG && window.APP_CONFIG.apiKey ? window.APP_CONFIG.apiKey : '';

function openAddStockModal(productId = null) {
    const modal = document.getElementById('addStockModal');
    modal.classList.add('show');
    generateBatchLot();
    if (productId) {
        loadProductDetails(productId);
    } else {
        clearProductSelection();
    }
}

function closeAddStockModal() {
    document.getElementById('addStockModal').classList.remove('show');
}

function openRemoveStockModal(productId, productName) {
    document.getElementById('remove-product-id').value = productId;
    document.getElementById('remove-product-name').textContent = productName;
    document.getElementById('removeStockModal').classList.add('show');
}

function closeRemoveStockModal() {
    document.getElementById('removeStockModal').classList.remove('show');
}

function openMoveStockModal(item) {
    document.getElementById('move-inventory-id').value = item.id;
    document.getElementById('move-product-id').value = item.product_id;
    document.getElementById('move-from-location-id').value = item.location_id;
    document.getElementById('move-product-name').textContent = item.product_name;
    document.getElementById('available-quantity').textContent = parseInt(item.quantity).toLocaleString();
    document.getElementById('move-quantity').max = item.quantity;
    document.getElementById('moveStockModal').classList.add('show');
}

function closeMoveStockModal() {
    document.getElementById('moveStockModal').classList.remove('show');
}

function addStockForProduct(productId) {
    openAddStockModal(productId);
}

window.onclick = function(event) {
    ['addStockModal','removeStockModal','moveStockModal'].forEach(id => {
        const modal = document.getElementById(id);
        if (event.target === modal) modal.classList.remove('show');
    });
};

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAddStockModal();
        closeRemoveStockModal();
        closeMoveStockModal();
    }
});

// -------- Product search ---------
function searchProducts(query) {
    clearTimeout(productSearchTimeout);
    productSearchTimeout = setTimeout(async () => {
        if (query.length < 2) { hideProductResults(); return; }
        if (productSearchCache[query]) { displayProductResults(productSearchCache[query]); return; }
        try {
            const resp = await fetch(`api/products.php?search=${encodeURIComponent(query)}&limit=10`);
            const data = await resp.json();
            // API might return an object with a `data` key in addition to a raw array
            const products = Array.isArray(data) ? data : data.data;
            if (resp.ok && Array.isArray(products)) {
                productSearchCache[query] = products;
                displayProductResults(products);
            } else {
                hideProductResults();
            }
        } catch (e) {
            console.error(e);
            hideProductResults();
        }
    }, 300);
}

function displayProductResults(products) {
    const container = document.getElementById('add-product-results');
    if (!container) return;
    if (products.length === 0) {
        container.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
    } else {
        container.innerHTML = products.map(p => `
            <div class="search-result-item" onclick="selectProduct(${p.id}, '${escapeHtml(p.name)}', '${p.code}')">
                <div class="product-name">${escapeHtml(p.name)}</div>
                <div class="product-details">${escapeHtml(p.code)} - ${escapeHtml(p.category)}</div>
            </div>`).join('');
    }
    container.classList.add('show');
}

function selectProduct(id, name, sku) {
    document.getElementById('add-product').value = id;
    const input = document.getElementById('add-product-search');
    input.value = name;
    input.dataset.sku = sku;
    hideProductResults();
    updateStockCounter(sku);
    updateSubdivisionOptions();
}

function showProductResults() {
    const input = document.getElementById('add-product-search');
    if (input.value.length < 2) return;
    const container = document.getElementById('add-product-results');
    container.classList.add('show');
}

function hideProductResults() {
    const container = document.getElementById('add-product-results');
    if (container) {
        container.innerHTML = '';
        container.classList.remove('show');
    }
}

async function loadProductDetails(id) {
    try {
        const resp = await fetch(`api/products.php?id=${id}`);
        const data = await resp.json();
        if (resp.ok && Array.isArray(data) && data.length) {
            const p = data[0];
            selectProduct(p.id, p.name, p.code);
        }
    } catch (e) { console.error(e); }
}

function clearProductSelection() {
    document.getElementById('add-product').value = '';
    document.getElementById('add-product-search').value = '';
    document.getElementById('add-product-search').dataset.sku = '';
    document.getElementById('total-articles').textContent = '';
}

async function updateStockCounter(sku) {
    const span = document.getElementById('total-articles');
    if (!sku) { span.textContent = ''; return; }
    try {
        const resp = await fetch(`api/index.php?endpoint=inventory/check&skus=${encodeURIComponent(sku)}`);
        const data = await resp.json();
        if (resp.ok && data.success && data.inventory && data.inventory[sku]) {
            span.textContent = `(Total: ${data.inventory[sku].available_quantity})`;
        } else {
            span.textContent = '(Total: 0)';
        }
    } catch (e) {
        span.textContent = '';
    }
}

// -------- Location Levels ---------
async function loadLocationLevels(locationId) {
    const levelSelect = document.getElementById('shelf_level');
    levelSelect.innerHTML = '<option value="">--</option>';
    document.getElementById('subdivision-container').style.display = 'none';
    document.getElementById('subdivision_number').innerHTML = '';
    if (!locationId) return;
    try {
        const resp = await fetch(`api/location_info.php?id=${locationId}`);
        const data = await resp.json();
        if (resp.ok && data.levels) {
            data.levels.forEach(l => {
                const opt = document.createElement('option');
                opt.value = l.number;
                let label = l.name;
                if ((l.current_stock > 0) || l.dedicated_product_id) {
                    const info = l.capacity ? `${l.current_stock}/${l.capacity} articole - ${l.occupancy_percentage}%` : `${l.current_stock} articole`;
                    const name = l.product_name ? l.product_name + ' - ' : '';
                    label += ` (${name}${info})`;
                }
                opt.textContent = label;
                opt.dataset.subdivisionCount = l.subdivision_count;
                levelSelect.appendChild(opt);
            });
        }
    } catch (e) { console.error(e); }
}

async function updateSubdivisionOptions() {
    const levelSelect = document.getElementById('shelf_level');
    const locId = document.getElementById('add-location').value;
    const productId = document.getElementById('add-product').value;
    const subContainer = document.getElementById('subdivision-container');
    const subSelect = document.getElementById('subdivision_number');
    const levelNumber = levelSelect.value;

    subSelect.innerHTML = '';

    if (!locId || !levelNumber) { subContainer.style.display = 'none'; return; }

    try {
        const resp = await fetch(`api/subdivision_info.php?location_id=${locId}&level=${levelNumber}&product_id=${productId}`);
        const data = await resp.json();
        if (resp.ok && Array.isArray(data.subdivisions) && data.subdivisions.length) {
            data.subdivisions.forEach(sd => {
                const opt = document.createElement('option');
                const info = sd.capacity ? `${sd.current_stock}/${sd.capacity} articole - ${sd.occupancy_percentage}%` : `${sd.current_stock} articole`;
                const name = sd.product_name ? sd.product_name + ' - ' : '';
                const prefix = sd.recommended ? '⭐ ' : (sd.compatible ? '' : '❌ ');
                opt.value = sd.subdivision_number;
                opt.textContent = `${prefix}Subdiviziunea ${sd.subdivision_number} (${name}${info})`;
                if (!sd.compatible) opt.disabled = true;
                subSelect.appendChild(opt);
            });
            subContainer.style.display = 'block';
        } else {
            subContainer.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
        subContainer.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const locSelect = document.getElementById('add-location');
    if (locSelect) {
        locSelect.addEventListener('change', () => loadLocationLevels(locSelect.value));
    }
});

function generateBatchLot() {
    const batch = document.getElementById('add-batch');
    const lot = document.getElementById('add-lot');
    const now = Date.now();
    if (batch) batch.value = 'B' + now.toString().slice(-6);
    if (lot) lot.value = 'L' + Math.floor(Math.random()*900000 + 100000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.searchProducts = searchProducts;
window.showProductResults = showProductResults;
window.addStockForProduct = addStockForProduct;
window.updateSubdivisionOptions = updateSubdivisionOptions;

function setDateRange(period) {
    const from = document.getElementById('date_from');
    const to = document.getElementById('date_to');
    if (!from || !to) return;
    const now = new Date();
    let start = new Date();
    if (period === 'today') {
        // start of today
    } else if (period === 'week') {
        start.setDate(now.getDate() - 7);
    } else if (period === 'month') {
        start.setDate(now.getDate() - 30);
    }
    from.value = start.toISOString().slice(0,10);
    to.value = now.toISOString().slice(0,10);
}

function openTransactionModal(data) {
    const modal = document.getElementById('transactionDetailsModal');
    const content = document.getElementById('transaction-details-content');
    if (!modal || !content) return;
    content.innerHTML = `
        <p><strong>Tip:</strong> ${data.transaction_type}</p>
        <p><strong>Produs:</strong> ${escapeHtml(data.product_name || '')} (${escapeHtml(data.sku || '')})</p>
        <p><strong>Cantitate:</strong> ${data.quantity_change} (${data.quantity_before} → ${data.quantity_after})</p>
        <p><strong>Locație:</strong> ${escapeHtml(data.source_location_code || '-') } → ${escapeHtml(data.location_code || '-')}</p>
        <p><strong>Operator:</strong> ${escapeHtml(data.full_name || data.username || '')}</p>
        <p><strong>Motiv:</strong> ${escapeHtml(data.reason || '')}</p>
        <p><strong>Notă:</strong> ${escapeHtml(data.notes || '')}</p>
        <p><strong>Data:</strong> ${data.created_at}</p>
        <p><strong>Sesiune:</strong> ${escapeHtml(data.session_id || '')}</p>
    `;
    modal.classList.add('show');
}

function closeTransactionModal() {
    const modal = document.getElementById('transactionDetailsModal');
    if (modal) modal.classList.remove('show');
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('stock-movements-table')) {
        document.querySelectorAll('.view-transaction').forEach(btn => {
            btn.addEventListener('click', () => {
                const data = JSON.parse(btn.dataset.details);
                openTransactionModal(data);
            });
        });
        setInterval(() => {
            const form = document.getElementById('movements-filter-form');
            if (form) form.submit();
        }, 30000);
    }
});

window.setDateRange = setDateRange;
window.closeTransactionModal = closeTransactionModal;

