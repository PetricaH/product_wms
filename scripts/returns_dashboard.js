// Clean Returns Dashboard Logic - No Duplicates
let returnsTable;
let returnsChart;
let currentReturnContext = null;

// Enhanced fetchSummary with loading states and error handling
async function fetchSummary() {
    try {
        // Add loading state to stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.classList.add('loading');
        });

        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=summary`);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (data && data.summary) {
            // Animate numbers counting up
            animateNumber('stat-in-progress', data.summary.in_progress || 0);
            animateNumber('stat-pending', data.summary.pending || 0);
            animateNumber('stat-completed', data.summary.completed || 0);
            animateNumber('stat-discrepancies', data.summary.discrepancies || 0);
            animateNumber('stat-auto-created', data.summary.auto_created || 0);
        } else {
            throw new Error('Invalid data structure received');
        }
    } catch (err) {
        console.error('Summary error', err);
        showErrorToast('Failed to load summary data: ' + err.message);
        
        // Set all stats to 0 as fallback
        document.getElementById('stat-in-progress').textContent = '0';
        document.getElementById('stat-pending').textContent = '0';
        document.getElementById('stat-completed').textContent = '0';
        document.getElementById('stat-discrepancies').textContent = '0';
        document.getElementById('stat-auto-created').textContent = '0';
    } finally {
        // Remove loading state
        document.querySelectorAll('.stat-card').forEach(card => {
            card.classList.remove('loading');
        });
    }
}

// Number animation function
function animateNumber(elementId, targetValue) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const startValue = parseInt(element.textContent) || 0;
    const duration = 1000;
    const startTime = performance.now();
    
    function updateNumber(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function for smooth animation
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentValue = Math.round(startValue + (targetValue - startValue) * easeOut);
        
        element.textContent = currentValue;
        
        if (progress < 1) {
            requestAnimationFrame(updateNumber);
        }
    }
    
    requestAnimationFrame(updateNumber);
}

// Enhanced table initialization
function initTable() {
    // Destroy existing DataTable if it exists
    if ($.fn.DataTable.isDataTable('#returns-table')) {
        $('#returns-table').DataTable().destroy();
        $('#returns-table').empty(); // Clear the table content
    }
    
    returnsTable = $('#returns-table').DataTable({
        ajax: {
            url: `${WMS_CONFIG.apiBase}/returns/admin.php?action=list`,
            dataSrc: function(json) { 
                console.log('DataTables received:', json);
                if (json && json.returns) {
                    return json.returns;
                }
                console.warn('No returns data received:', json);
                return []; 
            },
            error: function(xhr, error, thrown) {
                console.error('DataTables AJAX error:', {xhr, error, thrown});
                showErrorToast('Failed to load returns data: ' + error);
            }
        },
        columns: [
            { data: 'id' },
            { data: 'order_number' },
            { data: 'customer_name' },
            {
                data: 'status',
                render: function(data, type, row) {
                    if (type === 'display') {
                        return `<span class="status-badge status-${data}">${data}</span>`;
                    }
                    return data;
                }
            },
            {
                data: 'return_awb',
                render: function(data) {
                    return data ? data : '—';
                }
            },
            {
                data: 'auto_created',
                render: function(data, type) {
                    if (type === 'display') {
                        return data ? '<span class="badge badge-info">Auto</span>' : '<span class="badge badge-muted">Manual</span>';
                    }
                    return data;
                }
            },
            {
                data: 'return_date',
                render: function(data) {
                    return data ? data : '—';
                }
            },
            { data: 'processed_by' },
            { data: 'created_at' },
            { data: 'verified_at' },
            {
                data: 'discrepancies',
                render: function(data, type, row) {
                    if (type === 'display') {
                        const count = parseInt(data) || 0;
                        if (count > 0) {
                            return `<span class="badge badge-warning">${count}</span>`;
                        }
                        return '<span class="badge badge-success">0</span>';
                    }
                    return data;
                }
            }
        ],
        language: {
            "lengthMenu": "Show _MENU_ returns",
            "zeroRecords": "No returns found",
            "info": "Showing _START_ to _END_ of _TOTAL_ returns",
            "infoEmpty": "Showing 0 to 0 of 0 returns",
            "infoFiltered": "(filtered from _MAX_ total returns)",
            "search": "Search returns:",
            "paginate": {
                "first": "First",
                "last": "Last",
                "next": "Next",
                "previous": "Previous"
            },
            "processing": "Loading returns..."
        },
        processing: true,
        pageLength: 25,
        responsive: true,
        order: [[0, 'desc']],
        drawCallback: function() {
            // Re-attach row click handlers after redraw
            attachRowClickHandlers();
        }
    });
}

// Separate function for row click handlers
function attachRowClickHandlers() {
    $('#returns-table tbody tr').off('click').on('click', function(){
        const data = returnsTable.row(this).data();
        if (data && data.id) {
            // Add loading state to clicked row
            $(this).addClass('loading');
            loadReturnDetail(data.id).finally(() => {
                $(this).removeClass('loading');
            });
        }
    });
}

const locationOptionCache = {
    options: null
};

function escapeHtml(value) {
    if (value === null || typeof value === 'undefined') {
        return '';
    }
    return String(value).replace(/[&<>"']/g, (match) => {
        const escapeMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return escapeMap[match] || match;
    });
}

function buildLocationOptionCache() {
    if (locationOptionCache.options) {
        return;
    }

    const baseLocations = Array.isArray(window.WMS_CONFIG?.locations)
        ? window.WMS_CONFIG.locations
        : [];
    const levelDescriptions = new Map();

    if (Array.isArray(window.WMS_CONFIG?.locationLevels)) {
        window.WMS_CONFIG.locationLevels.forEach((level) => {
            const locationId = Number(level.location_id);
            if (!Number.isFinite(locationId) || locationId <= 0) {
                return;
            }
            if (levelDescriptions.has(locationId)) {
                return;
            }
            const display = level.display_code || level.level_name || '';
            if (display) {
                levelDescriptions.set(locationId, display);
            }
        });
    }

    const options = baseLocations
        .map((loc) => {
            const id = Number(loc.id);
            if (!Number.isFinite(id) || id <= 0) {
                return null;
            }
            const code = loc.location_code || `Locație #${id}`;
            const zone = loc.zone ? `Zona ${loc.zone}` : '';
            const extra = levelDescriptions.get(id) || zone;
            const label = extra && !extra.includes(code)
                ? `${code} - ${extra}`
                : code;
            return { id, label };
        })
        .filter(Boolean);

    options.sort((a, b) => a.label.localeCompare(b.label, 'ro-RO'));
    locationOptionCache.options = options;
}

function getLocationOptions(selectedId = null, fallbackCode = '') {
    buildLocationOptionCache();
    const options = Array.isArray(locationOptionCache.options)
        ? [...locationOptionCache.options]
        : [];

    const numericSelected = Number(selectedId);
    if (Number.isFinite(numericSelected) && numericSelected > 0 && !options.some((opt) => Number(opt.id) === numericSelected)) {
        const label = fallbackCode ? fallbackCode : `Locație #${numericSelected}`;
        options.push({ id: numericSelected, label });
    }

    options.sort((a, b) => a.label.localeCompare(b.label, 'ro-RO'));
    return options;
}

function buildLocationOptionsMarkup(selectedId = null, fallbackCode = '') {
    return getLocationOptions(selectedId, fallbackCode)
        .map((option) => {
            const isSelected = Number(option.id) === Number(selectedId);
            return `<option value="${option.id}" ${isSelected ? 'selected' : ''}>${escapeHtml(option.label)}</option>`;
        })
        .join('');
}

function renderConditionOptions(selected) {
    const options = [
        { value: 'good', label: 'Bun' },
        { value: 'damaged', label: 'Deteriorat' },
        { value: 'defective', label: 'Defect' },
        { value: 'opened', label: 'Deschis' }
    ];

    const normalized = options.some((opt) => opt.value === selected) ? selected : 'good';

    return options
        .map((opt) => `<option value="${opt.value}" ${opt.value === normalized ? 'selected' : ''}>${escapeHtml(opt.label)}</option>`)
        .join('');
}

function renderStatusBadge(status) {
    if (!status) {
        return '';
    }
    const slug = String(status).toLowerCase().replace(/[^a-z0-9_-]+/g, '-');
    return `<span class="status-badge status-${escapeHtml(slug)}">${escapeHtml(status)}</span>`;
}

function toNumber(value, fallback = 0) {
    const num = Number(value);
    return Number.isFinite(num) ? num : fallback;
}

function computeProcessingState(items, processingInfo = {}, totals = {}) {
    const totalItems = Number.isFinite(Number(processingInfo.total_items))
        ? Number(processingInfo.total_items)
        : (Array.isArray(items) ? items.length : 0);
    const processedItems = Number.isFinite(Number(processingInfo.processed_items))
        ? Number(processingInfo.processed_items)
        : (Array.isArray(items) ? items.filter((item) => item && (item.is_processed || item.return_item_id)).length : 0);
    const expectedUnits = Number.isFinite(Number(totals.expected_quantity))
        ? Number(totals.expected_quantity)
        : (Array.isArray(items) ? items.reduce((sum, item) => sum + toNumber(item?.expected_quantity), 0) : 0);
    const processedUnits = Number.isFinite(Number(totals.processed_quantity))
        ? Number(totals.processed_quantity)
        : (Array.isArray(items) ? items.reduce((sum, item) => sum + toNumber(item?.processed_quantity ?? item?.quantity_returned), 0) : 0);

    const allProcessed = typeof processingInfo.all_processed !== 'undefined'
        ? Boolean(processingInfo.all_processed)
        : (totalItems > 0 && processedItems === totalItems);

    return {
        allProcessed,
        processedItems,
        totalItems,
        expectedUnits,
        processedUnits
    };
}

async function fetchReturnContext(id) {
    const returnId = Number(id);
    if (!Number.isFinite(returnId) || returnId <= 0) {
        throw new Error('ID retur invalid');
    }

    const detailRes = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=detail&id=${returnId}`);
    if (!detailRes.ok) {
        throw new Error(`HTTP ${detailRes.status}: ${detailRes.statusText}`);
    }
    const detailJson = await detailRes.json();
    if (!detailJson || !detailJson.success) {
        throw new Error(detailJson?.message || 'Nu am putut încărca detaliile returului.');
    }

    const returnInfo = detailJson.return || {};
    const orderId = Number(returnInfo.order_id);
    if (!Number.isFinite(orderId) || orderId <= 0) {
        throw new Error('Comanda asociată returului nu a fost găsită.');
    }

    const params = new URLSearchParams({
        order_id: String(orderId),
        return_id: String(returnId)
    });
    const orderRes = await fetch(`${WMS_CONFIG.apiBase}/warehouse/return_order_details.php?${params.toString()}`);
    if (!orderRes.ok) {
        throw new Error(`HTTP ${orderRes.status}: ${orderRes.statusText}`);
    }
    const orderJson = await orderRes.json();
    if (!orderJson || !orderJson.success) {
        throw new Error(orderJson?.message || 'Nu am putut încărca produsele din retur.');
    }

    return {
        returnId,
        orderId,
        detail: detailJson,
        order: orderJson
    };
}

async function loadReturnDetail(id) {
    try {
        const context = await fetchReturnContext(id);
        currentReturnContext = context;
        renderReturnModal(context);
        showModal('return-modal');
    } catch (err) {
        console.error('Detail error', err);
        showErrorToast(err.message || 'Failed to load return details.');
    }
}

function renderReturnModal(context) {
    const container = document.getElementById('return-details');
    if (!container) {
        return;
    }

    const detail = context.detail || {};
    const returnInfo = detail.return || {};
    const orderData = context.order || {};
    const orderInfo = orderData.order || {};
    const items = Array.isArray(orderData.items) ? orderData.items : [];
    const totals = orderData.totals || {};
    const processingInfo = orderData.processing || {};
    const discrepancies = Array.isArray(detail.discrepancies) ? detail.discrepancies : [];
    const extraItems = Array.isArray(detail.items) ? detail.items.filter((item) => item && item.is_extra) : [];

    const statusSlug = String(returnInfo.status || '').toLowerCase();
    const isFinalized = ['completed', 'cancelled', 'rejected'].includes(statusSlug);
    const processingState = computeProcessingState(items, processingInfo, totals);

    currentReturnContext.processingState = processingState;

    const summarySection = `
        <section class="return-summary-card">
            <header class="return-summary-header">
                <div>
                    <h3>Retur #${escapeHtml(returnInfo.id ?? context.returnId)}</h3>
                    <div class="return-summary-subtitle">
                        <span>${escapeHtml(orderInfo.order_number || 'Comandă necunoscută')}</span>
                        ${renderStatusBadge(returnInfo.status)}
                    </div>
                </div>
            </header>
            <div class="return-summary-grid">
                <div>
                    <span class="label">Client</span>
                    <span class="value">${escapeHtml(orderInfo.customer_name || '—')}</span>
                </div>
                <div>
                    <span class="label">AWB retur</span>
                    <span class="value">${escapeHtml(returnInfo.return_awb || '—')}</span>
                </div>
                <div>
                    <span class="label">Return automat</span>
                    <span class="value">${returnInfo.auto_created ? 'Da' : 'Nu'}</span>
                </div>
                <div>
                    <span class="label">Data retur</span>
                    <span class="value">${escapeHtml(returnInfo.return_date || '—')}</span>
                </div>
                <div>
                    <span class="label">Creat</span>
                    <span class="value">${escapeHtml(returnInfo.created_at || '—')}</span>
                </div>
                <div>
                    <span class="label">Verificat</span>
                    <span class="value">${escapeHtml(returnInfo.verified_at || '—')}</span>
                </div>
            </div>
            ${returnInfo.notes ? `<div class="return-notes"><span class="label">Notă</span><p>${escapeHtml(returnInfo.notes)}</p></div>` : ''}
        </section>
    `;

    const actionHint = isFinalized
        ? 'Returul este finalizat - modificările sunt blocate.'
        : (processingState.allProcessed
            ? 'Toate produsele sunt înregistrate. Poți readăuga în stoc.'
            : 'Înregistrează toate produsele pentru a activa readăugarea în stoc.');

    const actionsSection = `
        <section class="return-actions-card">
            <div class="actions-header">
                <h4><span class="material-symbols-outlined">playlist_add_check</span> Procesare retur</h4>
                <div class="processing-chips">
                    <span class="processing-chip"><span class="material-symbols-outlined">inventory</span>${processingState.processedItems}/${processingState.totalItems} produse</span>
                    <span class="processing-chip"><span class="material-symbols-outlined">countertops</span>${processingState.processedUnits}/${processingState.expectedUnits} buc</span>
                </div>
            </div>
            <button type="button" class="btn btn-success" id="restock-return-btn" data-return-id="${context.returnId}" data-order-id="${context.orderId}" ${(!processingState.allProcessed || isFinalized) ? 'disabled' : ''}>
                <span class="material-symbols-outlined">inventory_2</span>
                Readaugă în stoc
            </button>
            <p class="action-hint ${processingState.allProcessed && !isFinalized ? 'success' : ''}">${escapeHtml(actionHint)}</p>
        </section>
    `;

    const itemsSection = renderReturnItemsSection(items, { isFinalized });
    const extraSection = renderExtraItemsSection(extraItems);
    const discrepanciesSection = renderDiscrepanciesSection(discrepancies);

    container.innerHTML = `
        <div class="return-modal-sections">
            ${summarySection}
            ${actionsSection}
            ${itemsSection}
            ${extraSection}
            ${discrepanciesSection}
        </div>
    `;

    setupReturnModalInteractions({ isFinalized });
}

function renderReturnItemsSection(items, { isFinalized }) {
    if (!Array.isArray(items) || items.length === 0) {
        return `
            <section class="return-section">
                <h4><span class="material-symbols-outlined">inventory</span> Produse din retur</h4>
                <div class="empty-state">
                    <span class="material-symbols-outlined">checklist_rtl</span>
                    <p>Nu există produse de înregistrat pentru acest retur.</p>
                </div>
            </section>
        `;
    }

    const rows = items.map((item) => {
        const orderItemId = Number(item.order_item_id);
        const productId = Number(item.product_id);
        const processed = Boolean(item.is_processed || item.return_item_id);
        const expectedQty = toNumber(item.expected_quantity || item.picked_quantity || item.quantity_ordered, 0);
        const processedQty = processed ? toNumber(item.processed_quantity ?? item.quantity_returned, 0) : 0;
        const quantityValue = processed ? processedQty : expectedQty;
        const selectedCondition = item.processed_condition || item.item_condition || 'good';
        const selectedLocationId = item.processed_location_id || item.default_location_id || item.location_id || null;
        const fallbackLocationCode = item.processed_location_code || item.default_location_code || item.location_code || '';
        const notesValue = item.processed_notes || item.notes || '';
        const canEdit = !isFinalized;
        const statusBadge = processed
            ? '<span class="badge badge-success">Înregistrat</span>'
            : '<span class="badge badge-warning">În așteptare</span>';

        return `
            <tr class="${processed ? 'processed' : 'pending'}" data-order-item-id="${orderItemId}" data-product-id="${productId}">
                <td class="product-cell">
                    <div class="product-sku">${escapeHtml(item.sku || '')}</div>
                    <div class="product-name">${escapeHtml(item.product_name || '')}</div>
                    ${statusBadge}
                </td>
                <td class="metric-cell">
                    <div><span>Comandă:</span> ${toNumber(item.quantity_ordered)}</div>
                    <div><span>Ridicat:</span> ${toNumber(item.picked_quantity ?? item.quantity_ordered)}</div>
                </td>
                <td class="metric-cell">${expectedQty}</td>
                <td class="input-cell">
                    <input type="number" min="0" class="form-input item-qty" value="${quantityValue}" ${canEdit ? '' : 'disabled'}>
                    <div class="input-hint">${processed ? `Înregistrat: ${processedQty} buc` : `Sugerat: ${expectedQty} buc`}</div>
                </td>
                <td class="input-cell">
                    <select class="form-select item-condition" ${canEdit ? '' : 'disabled'}>
                        ${renderConditionOptions(selectedCondition)}
                    </select>
                </td>
                <td class="input-cell">
                    <select class="form-select item-location" ${canEdit ? '' : 'disabled'} ${canEdit ? '' : 'data-locked="true"'}>
                        <option value="">Selectează locația</option>
                        ${buildLocationOptionsMarkup(selectedLocationId, fallbackLocationCode)}
                    </select>
                    <div class="location-hint">Locația nu este necesară când cantitatea este 0.</div>
                </td>
                <td class="notes-cell">
                    <textarea class="form-textarea item-notes" rows="2" ${canEdit ? '' : 'readonly'}>${escapeHtml(notesValue)}</textarea>
                </td>
                <td class="action-cell">
                    <button type="button" class="btn btn-primary btn-compact" data-action="save-item" ${canEdit ? '' : 'disabled'}>
                        <span class="material-symbols-outlined">save</span>
                        Salvează
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    return `
        <section class="return-section">
            <h4><span class="material-symbols-outlined">inventory</span> Produse din retur</h4>
            <div class="return-items-table-wrapper">
                <table class="return-items-table">
                    <thead>
                        <tr>
                            <th>Produs</th>
                            <th>Comandă</th>
                            <th>Așteptat</th>
                            <th>Cantitate</th>
                            <th>Stare</th>
                            <th>Locație</th>
                            <th>Notă</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        </section>
    `;
}

function renderExtraItemsSection(extraItems) {
    if (!Array.isArray(extraItems) || extraItems.length === 0) {
        return '';
    }

    const itemsHtml = extraItems.map((item) => `
        <li>
            <strong>${escapeHtml(item.sku || '')}</strong>
            <span>${escapeHtml(item.name || '')}</span>
            <small>${toNumber(item.quantity_returned)} buc · ${escapeHtml(item.item_condition || 'N/A')}</small>
        </li>
    `).join('');

    return `
        <section class="return-section">
            <h4><span class="material-symbols-outlined">add_circle</span> Produse suplimentare</h4>
            <ul class="simple-list">${itemsHtml}</ul>
        </section>
    `;
}

function renderDiscrepanciesSection(discrepancies) {
    if (!Array.isArray(discrepancies) || discrepancies.length === 0) {
        return `
            <section class="return-section">
                <h4><span class="material-symbols-outlined">check_circle</span> Discrepanțe</h4>
                <div class="empty-state success">
                    <span class="material-symbols-outlined">verified</span>
                    <p>Nu există discrepanțe active pentru acest retur.</p>
                </div>
            </section>
        `;
    }

    const itemsHtml = discrepancies.map((d) => `
        <li class="discrepancy-item ${escapeHtml(d.resolution_status || 'pending')}">
            <div>
                <strong>${escapeHtml(d.sku || '')}</strong> – ${escapeHtml(d.discrepancy_type || '')}
            </div>
            <div class="discrepancy-meta">
                <span>${toNumber(d.expected_quantity)} așteptat</span>
                <span>${toNumber(d.actual_quantity)} înregistrat</span>
                <span>Stare: ${escapeHtml(d.resolution_status || 'pending')}</span>
            </div>
        </li>
    `).join('');

    return `
        <section class="return-section">
            <h4><span class="material-symbols-outlined">warning</span> Discrepanțe</h4>
            <ul class="simple-list discrepancies">${itemsHtml}</ul>
        </section>
    `;
}

function setupReturnModalInteractions({ isFinalized }) {
    const container = document.getElementById('return-details');
    if (!container) {
        return;
    }

    container.querySelectorAll('.item-qty').forEach((input) => {
        const handler = () => updateLocationRequirement(input);
        input.addEventListener('input', handler);
        input.addEventListener('change', handler);
        updateLocationRequirement(input);
    });

    if (!isFinalized) {
        container.querySelectorAll('.item-notes').forEach((textarea) => {
            textarea.addEventListener('input', () => {
                textarea.style.height = 'auto';
                textarea.style.height = `${Math.min(160, textarea.scrollHeight)}px`;
            });
        });
    }
}

function updateLocationRequirement(input) {
    if (!input) {
        return;
    }
    const row = input.closest('tr');
    if (!row) {
        return;
    }
    const select = row.querySelector('.item-location');
    const hint = row.querySelector('.location-hint');
    const quantity = Number(input.value);
    const requiresLocation = Number.isFinite(quantity) && quantity > 0;

    if (select && !select.hasAttribute('data-locked')) {
        select.disabled = !requiresLocation;
    }
    if (hint) {
        hint.style.display = requiresLocation ? 'none' : 'block';
    }
}

async function refreshReturnContext() {
    if (!currentReturnContext || !currentReturnContext.returnId) {
        return;
    }
    try {
        const context = await fetchReturnContext(currentReturnContext.returnId);
        currentReturnContext = context;
        renderReturnModal(context);
    } catch (error) {
        console.error('Refresh return context failed', error);
        showErrorToast(error.message || 'Nu am putut actualiza returul.');
    }
}

async function handleSaveItem(row, button) {
    if (!row || !currentReturnContext) {
        return;
    }

    const orderItemId = Number(row.getAttribute('data-order-item-id'));
    const productId = Number(row.getAttribute('data-product-id'));
    const quantityInput = row.querySelector('.item-qty');
    const conditionSelect = row.querySelector('.item-condition');
    const locationSelect = row.querySelector('.item-location');
    const notesField = row.querySelector('.item-notes');

    const quantity = Number(quantityInput?.value ?? 0);
    const condition = conditionSelect?.value || 'good';
    const locationValue = locationSelect?.value || '';
    const notes = notesField?.value?.trim() || '';
    const requiresLocation = quantity > 0;

    if (!Number.isFinite(quantity) || quantity < 0) {
        showErrorToast('Introduceți o cantitate validă pentru produs.');
        quantityInput?.focus();
        return;
    }

    if (requiresLocation && !locationValue) {
        showErrorToast('Selectați o locație pentru produs.');
        locationSelect?.focus();
        return;
    }

    const payload = {
        return_id: currentReturnContext.returnId,
        order_item_id: orderItemId,
        product_id: productId,
        quantity_received: quantity,
        condition,
        location_id: requiresLocation ? Number(locationValue) : null,
        notes
    };

    const originalLabel = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="material-symbols-outlined spin">autorenew</span>';

    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/warehouse/add_return_item.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': WMS_CONFIG.csrfToken
            },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json?.message || 'Nu am putut salva produsul.');
        }

        showSuccessToast(json.message || 'Produsul a fost salvat.');
        await refreshReturnContext();
    } catch (error) {
        console.error('Save return item error', error);
        showErrorToast(error.message || 'Nu am putut salva produsul.');
    } finally {
        button.disabled = false;
        button.innerHTML = originalLabel;
    }
}

async function handleRestockClick(button) {
    if (!currentReturnContext) {
        showErrorToast('Selectați un retur înainte de a readăuga produsele în stoc.');
        return;
    }

    if (!currentReturnContext.processingState?.allProcessed) {
        showErrorToast('Înregistrați toate produsele înainte de a readăuga în stoc.');
        return;
    }

    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="material-symbols-outlined spin">autorenew</span> Se procesează';

    try {
        const payload = {
            order_id: currentReturnContext.orderId,
            return_id: currentReturnContext.returnId
        };

        const res = await fetch(`${WMS_CONFIG.apiBase}/warehouse/process_return_restock.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': WMS_CONFIG.csrfToken
            },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json?.message || 'Nu am putut readăuga produsele în stoc.');
        }

        showSuccessToast(json.message || 'Produsele au fost readăugate în stoc.');
        await refreshReturnContext();
        if (returnsTable) {
            returnsTable.ajax.reload(null, false);
        }
    } catch (error) {
        console.error('Return restock error', error);
        showErrorToast(error.message || 'Nu am putut readăuga produsele în stoc.');
    } finally {
        button.disabled = false;
        button.innerHTML = originalHtml;
    }
}

// Enhanced modal functions
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Focus trap
        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (focusableElements.length > 0) {
            focusableElements[0].focus();
        }
    }
}

function closeReturnModal() {
    const modal = document.getElementById('return-modal');
    if (modal) {
        modal.classList.remove('show');

        setTimeout(() => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }, 300);
    }
    currentReturnContext = null;
}

// Enhanced chart loading
async function loadChart() {
    try {
        const res = await fetch(`${WMS_CONFIG.apiBase}/returns/admin.php?action=stats`);
        
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}: ${res.statusText}`);
        }
        
        const data = await res.json();
        
        if (!data || !data.stats) {
            console.warn('No chart data received:', data);
            return;
        }
        
        const labels = data.stats.map(r => {
            const date = new Date(r.day);
            return date.toLocaleDateString('ro-RO', { month: 'short', day: 'numeric' });
        });
        const values = data.stats.map(r => parseInt(r.total) || 0);
        
        // Destroy existing chart before creating new one
        if (returnsChart) {
            returnsChart.destroy();
        }
        
        const ctx = document.getElementById('returns-chart');
        if (!ctx) {
            console.error('Chart canvas not found');
            return;
        }
        
        returnsChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: { 
                labels, 
                datasets: [{
                    label: 'Returns',
                    data: values,
                    borderColor: '#1a73e8',
                    backgroundColor: 'rgba(26, 115, 232, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3,
                    pointBackgroundColor: '#1a73e8',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: false,
                scales: { 
                    y: { 
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true
                    }
                }
            }
        });
    } catch (err) { 
        console.error('Chart error', err);
        showErrorToast('Failed to load chart data: ' + err.message);
    }
}

// Toast notification system
function showErrorToast(message) {
    showToast(message, 'error');
}

function showSuccessToast(message) {
    showToast(message, 'success');
}

function showToast(message, type = 'info') {
    // Remove existing toasts
    document.querySelectorAll('.toast').forEach(t => t.remove());
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <span class="material-symbols-outlined">${getToastIcon(type)}</span>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => toast.classList.add('show'), 100);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) {
                document.body.removeChild(toast);
            }
        }, 300);
    }, 5000);
}

function getToastIcon(type) {
    const icons = {
        success: 'check_circle',
        error: 'error',
        warning: 'warning',
        info: 'info'
    };
    return icons[type] || 'info';
}

// Enhanced initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('🚀 Returns Dashboard initializing...');
    
    // Check if WMS_CONFIG is available
    if (typeof WMS_CONFIG === 'undefined') {
        console.error('WMS_CONFIG not available');
        showErrorToast('Configuration error. Please refresh the page.');
        return;
    }
    
    console.log('✅ WMS_CONFIG available:', WMS_CONFIG);
    console.log('🔗 API calls will go to:', WMS_CONFIG.apiBase);
    
    // Initialize components with delay to ensure DOM is ready
    setTimeout(() => {
        fetchSummary();
        initTable();
        loadChart();
    }, 100);

    // Better event handling for filter form
    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', e => {
            e.preventDefault();
            if (returnsTable) {
                const params = new URLSearchParams(new FormData(e.target));
                const url = `${WMS_CONFIG.apiBase}/returns/admin.php?action=list&${params.toString()}`;
                console.log('Applying filters with URL:', url);
                returnsTable.ajax.url(url).load();
                showSuccessToast('Filters applied successfully');
            }
        });
    }

    // Export button
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            const filterForm = document.getElementById('filter-form');
            const params = new URLSearchParams(new FormData(filterForm));
            const url = `${WMS_CONFIG.apiBase}/returns/admin.php?action=list&export=csv&${params.toString()}`;
            console.log('Exporting with URL:', url);
            showSuccessToast('Export started...');
            window.open(url);
        });
    }

    const returnDetailsContainer = document.getElementById('return-details');
    if (returnDetailsContainer) {
        returnDetailsContainer.addEventListener('click', async (event) => {
            const saveButton = event.target.closest('[data-action="save-item"]');
            if (saveButton) {
                event.preventDefault();
                const row = saveButton.closest('tr');
                if (row) {
                    await handleSaveItem(row, saveButton);
                }
                return;
            }

            const restockButton = event.target.closest('#restock-return-btn');
            if (restockButton) {
                event.preventDefault();
                await handleRestockClick(restockButton);
            }
        });
    }

    // Modal close on backdrop click
    const modal = document.getElementById('return-modal');
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                closeReturnModal();
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeReturnModal();
        }
    });

    // Auto-refresh with better error handling
    setInterval(() => {
        console.log('🔄 Auto-refreshing data...');
        fetchSummary();
        loadChart();
        if (returnsTable) {
            returnsTable.ajax.reload(null, false);
        }
    }, 60000);
});

// Add CSS styles for enhanced features
const additionalStyles = `
<style>
.loading {
    opacity: 0.6;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.status-in_progress { 
    background: rgba(13, 202, 240, 0.2); 
    color: #0dcaf0; 
}

.status-pending { 
    background: rgba(255, 193, 7, 0.2); 
    color: #ffc107; 
}

.status-completed { 
    background: rgba(25, 135, 84, 0.2); 
    color: #198754; 
}

.badge {
    padding: 0.25rem 0.4rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-warning { 
    background: #fff3cd; 
    color: #856404; 
}

.badge-success { 
    background: #d1e7dd; 
    color: #0f5132; 
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    background: var(--container-background, #fff);
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    z-index: 1060;
    transform: translateX(400px);
    opacity: 0;
    transition: all 0.3s ease;
    min-width: 300px;
    max-width: 500px;
}

.toast.show {
    transform: translateX(0);
    opacity: 1;
}

.toast-content {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem;
    color: var(--text-primary, #333);
}

.toast-error { 
    border-left: 4px solid #dc3545; 
}

.toast-success { 
    border-left: 4px solid #198754; 
}

.toast-warning { 
    border-left: 4px solid #ffc107; 
}

.toast-info { 
    border-left: 4px solid #0dcaf0; 
}

.return-header {
    border-bottom: 1px solid var(--border-color, #ddd);
    padding-bottom: 1rem;
    margin-bottom: 1.5rem;
}

.return-meta p {
    margin: 0.5rem 0;
}

.return-section {
    margin: 1.5rem 0;
}

.return-section h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--primary-color, #007bff);
    margin-bottom: 1rem;
}

.products-list,
.discrepancies-list {
    list-style: none;
    padding: 0;
}

.product-item,
.discrepancy-item {
    background: var(--surface-background, #f8f9fa);
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.5rem;
}

.no-discrepancies {
    background: rgba(25, 135, 84, 0.1);
    color: #198754;
    border-color: #198754;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
</style>
`;

// Inject additional styles
if (document.head) {
    document.head.insertAdjacentHTML('beforeend', additionalStyles);
}