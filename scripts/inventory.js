// Inventory page enhancements
let productSearchTimeout;
let productSearchCache = {};
const API_KEY = window.APP_CONFIG && window.APP_CONFIG.apiKey ? window.APP_CONFIG.apiKey : '';

function openAddStockModal(productId = null) {
    const modal = document.getElementById('addStockModal');
    modal.classList.add('show');
    generateBatchLot();

    // Reset location and subdivision fields each time the modal opens
    const locSelect = document.getElementById('add-location');
    const levelSelect = document.getElementById('shelf_level');
    const subContainer = document.getElementById('subdivision-container');
    const subSelect = document.getElementById('subdivision_number');
    if (locSelect) locSelect.value = '';
    if (levelSelect) levelSelect.innerHTML = '<option value="">--</option>';
    if (subSelect) subSelect.innerHTML = '<option value="">--</option>';
    if (subContainer) subContainer.style.display = 'none';

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

async function selectProduct(id, name, sku) {
    document.getElementById('add-product').value = id;
    const input = document.getElementById('add-product-search');
    input.value = name;
    input.dataset.sku = sku;
    hideProductResults();
    updateStockCounter(sku);
    const autoLocated = await fetchProductLocation(id);
    if (!autoLocated) {
        updateSubdivisionOptions();
    }
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

async function fetchProductLocation(productId) {
    try {
        const resp = await fetch(`api/product_location.php?product_id=${productId}`);
        const data = await resp.json();
        if (resp.ok && data && data.location_id) {
            const locSelect = document.getElementById('add-location');
            locSelect.value = data.location_id;
            await loadLocationLevels(data.location_id);
            if (data.level_number) {
                const levelSelect = document.getElementById('shelf_level');
                levelSelect.value = data.level_number;
                await updateSubdivisionOptions();
                if (data.subdivision_number) {
                    const subContainer = document.getElementById('subdivision-container');
                    const subSelect = document.getElementById('subdivision_number');
                    subSelect.value = data.subdivision_number;
                    if (subContainer) subContainer.style.display = 'block';
                }
            }
            return true;
        }
    } catch (e) {
        console.error(e);
    }
    return false;
}

// -------- Location Levels ---------
async function loadLocationLevels(locationId) {
    const levelSelect = document.getElementById('shelf_level');
    levelSelect.innerHTML = '<option value="">--</option>';
    document.getElementById('subdivision-container').style.display = 'none';
    document.getElementById('subdivision_number').innerHTML = '<option value="">--</option>';
    if (!locationId) return;
    try {
        const resp = await fetch(`api/location_info.php?id=${locationId}`);
        const data = await resp.json();
        if (resp.ok && data.levels) {
            data.levels.forEach(l => {
                const opt = document.createElement('option');
                opt.value = l.number;
                let label = l.name;
                if (!(l.subdivision_count && l.subdivision_count > 0)) {
                    if ((l.current_stock > 0) || l.dedicated_product_id) {
                        const info = l.capacity ? `${l.current_stock}/${l.capacity} articole - ${l.occupancy_percentage}%` : `${l.current_stock} articole`;
                        const name = l.product_name ? l.product_name + ' - ' : '';
                        label += ` (${name}${info})`;
                    }
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

    subSelect.innerHTML = '<option value="">--</option>';

    if (!locId || !levelNumber) { 
        subContainer.style.display = 'none'; 
        return; 
    }

    try {
        // Build URL parameters with proper authentication
        const params = new URLSearchParams({ 
            location_id: locId, 
            level: levelNumber, 
            product_id: productId 
        });
        
        // Add API key if available
        if (API_KEY) {
            params.append('api_key', API_KEY);
        }
        
        // Make request with credentials for session authentication
        const resp = await fetch(`api/subdivision_info.php?${params.toString()}`, {
            credentials: 'same-origin'  // This ensures cookies/session are sent
        });
        
        const data = await resp.json();
        
        if (!resp.ok) {
            console.error('API Error:', data);
            if (subContainer) subContainer.style.display = 'none';
            return;
        }
        
        if (Array.isArray(data.subdivisions) && data.subdivisions.length) {
            let autoSelectedSubdivision = null;
            
            data.subdivisions.forEach(sd => {
                const opt = document.createElement('option');
                const info = sd.capacity ? `${sd.current_stock}/${sd.capacity} articole - ${sd.occupancy_percentage}%` : `${sd.current_stock} articole`;
                const name = sd.product_name ? sd.product_name + ' - ' : '';
                const prefix = sd.recommended ? '⭐ ' : (sd.compatible ? '' : '❌ ');
                
                opt.value = sd.subdivision_number;
                opt.textContent = `${prefix}Subdiviziunea ${sd.subdivision_number} (${name}${info})`;
                
                if (!sd.compatible) {
                    opt.disabled = true;
                }
                
                // Check if this subdivision is dedicated to the selected product
                if (productId && sd.dedicated_product_id && 
                    parseInt(sd.dedicated_product_id) === parseInt(productId)) {
                    autoSelectedSubdivision = sd.subdivision_number;
                    opt.selected = true;
                }
                
                subSelect.appendChild(opt);
            });
            
            // If we found a matching subdivision, select it programmatically
            if (autoSelectedSubdivision) {
                subSelect.value = autoSelectedSubdivision;
                console.log(`Auto-selected subdivision ${autoSelectedSubdivision} for product ${productId}`);
            }
            
            if (subContainer) subContainer.style.display = 'block';
        } else {
            if (subContainer) subContainer.style.display = 'none';
        }
    } catch (e) {
        console.error('Error updating subdivision options:', e);
        if (subContainer) subContainer.style.display = 'none';
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

function setExpiry(option) {
    const input = document.getElementById('add-expiry');
    if (!input) return;
    const date = new Date();
    switch (option) {
        case 'none':
            input.value = '';
            break;
        case '6m':
            date.setMonth(date.getMonth() + 6);
            input.value = date.toISOString().slice(0, 10);
            break;
        case '1y':
            date.setFullYear(date.getFullYear() + 1);
            input.value = date.toISOString().slice(0, 10);
            break;
        case '2y':
            date.setFullYear(date.getFullYear() + 2);
            input.value = date.toISOString().slice(0, 10);
            break;
        case '3y':
            date.setFullYear(date.getFullYear() + 3);
            input.value = date.toISOString().slice(0, 10);
            break;
    }
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
window.setExpiry = setExpiry;

document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('.product-search-container[data-product-search]');
    if (!container) return;

    const searchInput = container.querySelector('#product-search-input');
    const hiddenInput = container.querySelector('#product-search-id');
    const resultsContainer = container.querySelector('#product-search-results');
    if (!searchInput || !hiddenInput || !resultsContainer) return;

    let products = [];
    try {
        const raw = container.dataset.productSearch || '[]';
        const parsed = JSON.parse(raw);
        products = Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        console.error('Nu s-a putut încărca lista de produse pentru căutare:', error);
    }

    const normalizedProducts = products
        .map((product) => ({
            id: String(product.id ?? product.product_id ?? ''),
            name: product.name || '',
            sku: product.sku || ''
        }))
        .filter((product) => product.id);

    if (searchInput.dataset.selectedLabel) {
        searchInput.dataset.selectedLabel = searchInput.dataset.selectedLabel.trim().toLowerCase();
    }

    if (searchInput.dataset.selectedId && !hiddenInput.value) {
        hiddenInput.value = searchInput.dataset.selectedId;
    }

    const clearSelection = () => {
        hiddenInput.value = '';
        searchInput.dataset.selectedId = '';
        searchInput.dataset.selectedLabel = '';
    };

    const hideResults = () => {
        resultsContainer.innerHTML = '';
        resultsContainer.classList.remove('show');
    };

    const renderResults = (items) => {
        if (!items.length) {
            resultsContainer.innerHTML = '<div class="search-result-item no-results">Nu s-au găsit produse</div>';
        } else {
            resultsContainer.innerHTML = items
                .map((item) => `
                    <div class="search-result-item" data-product-id="${item.id}" data-product-name="${escapeHtml(item.name)}">
                        <div class="product-name">${escapeHtml(item.name)}</div>
                        <div class="product-details">${escapeHtml(item.sku)}</div>
                    </div>
                `)
                .join('');
        }
        resultsContainer.classList.add('show');
    };

    const updateResults = () => {
        const term = searchInput.value.trim().toLowerCase();
        const selectedLabel = (searchInput.dataset.selectedLabel || '').toLowerCase();
        const hasSelection = Boolean(searchInput.dataset.selectedId);

        if (hasSelection && term !== selectedLabel) {
            clearSelection();
        } else if (!hasSelection && hiddenInput.value && term) {
            hiddenInput.value = '';
        }

        if (!term) {
            hideResults();
            return;
        }

        const matches = normalizedProducts
            .filter((product) =>
                product.name.toLowerCase().includes(term) ||
                product.sku.toLowerCase().includes(term)
            )
            .slice(0, 10);

        renderResults(matches);
    };

    const selectProductFromList = (productId) => {
        const product = normalizedProducts.find((item) => item.id === productId);
        if (!product) {
            clearSelection();
            return;
        }
        hiddenInput.value = product.id;
        searchInput.value = product.name;
        searchInput.dataset.selectedId = product.id;
        searchInput.dataset.selectedLabel = product.name.trim().toLowerCase();
        hideResults();
    };

    if (hiddenInput.value) {
        const existing = normalizedProducts.find((item) => item.id === hiddenInput.value);
        if (existing && !searchInput.value) {
            searchInput.value = existing.name;
            searchInput.dataset.selectedId = existing.id;
            searchInput.dataset.selectedLabel = existing.name.trim().toLowerCase();
        }
    }

    searchInput.addEventListener('input', updateResults);
    searchInput.addEventListener('focus', () => {
        if (searchInput.value.trim()) {
            updateResults();
        }
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && resultsContainer.classList.contains('show')) {
            const firstItem = resultsContainer.querySelector('.search-result-item');
            if (firstItem && !firstItem.classList.contains('no-results')) {
                event.preventDefault();
                selectProductFromList(firstItem.dataset.productId);
            }
        }
    });

    resultsContainer.addEventListener('mousedown', (event) => {
        // Prevent input from losing focus when clicking results
        event.preventDefault();
    });

    resultsContainer.addEventListener('click', (event) => {
        const item = event.target.closest('.search-result-item');
        if (!item || item.classList.contains('no-results')) {
            return;
        }
        selectProductFromList(item.dataset.productId);
    });

    document.addEventListener('click', (event) => {
        if (!container.contains(event.target)) {
            hideResults();
        }
    });
});

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

