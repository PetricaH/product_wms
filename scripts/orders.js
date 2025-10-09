/**
 * scripts/orders.js
 * JavaScript functionality for the WMS Orders page.
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Orders page successfully loaded and scripts are running.');
    initRealtimeSearch();
    initOrdersRealtimeUpdates();
});

const POLLING_INTERVAL_MS = 2000;
let pollingTimer = null;
let pollingOrderId = null;
let pollingController = null;

const ORDERS_REALTIME_INTERVAL_MS = 5000;
let ordersRealtimeTimer = null;
let ordersRealtimeController = null;
let ordersLatestTimestamp = null;
let currentOrderDetails = null;
let currentOrderId = null;

const RECIPIENT_AUTOCOMPLETE_MIN_CHARS = 2;
const RECIPIENT_AUTOCOMPLETE_DELAY_MS = 250;
const recipientAutocompleteStates = [];
let recipientAutocompleteDocumentListenerAttached = false;
const recipientAutocompleteRegistry = {
    county: null,
    locality: null
};

const MANUAL_PRODUCT_SEARCH_MIN_CHARS = 2;
const MANUAL_PRODUCT_SEARCH_DELAY_MS = 200;
const MANUAL_PRODUCT_RESULTS_LIMIT = 15;
const MANUAL_PRODUCT_SEARCH_CACHE_SIZE = 80;
const MANUAL_PRODUCT_SEARCH_CACHE_RESULT_LIMIT = 40;
const manualProductSearchTimers = new WeakMap();
const manualProductSearchCache = new Map();
const manualProductSearchControllers = new WeakMap();

let manualProductLocalRawReference = null;
let manualProductLocalNormalized = null;

function normalizeOrderStatus(status) {
    const value = (status ?? '').toString().trim().toLowerCase();
    if (value === 'cancelled') {
        return 'canceled';
    }
    return value;
}

function isOrderStatusCanceled(status) {
    return normalizeOrderStatus(status) === 'canceled';
}

function normalizeViewMode(value) {
    const normalized = (value ?? '').toString().trim().toLowerCase();
    return ['active', 'canceled', 'all'].includes(normalized) ? normalized : 'active';
}

function isOrderVisibleInView(orderOrStatus, viewMode) {
    let statusValue;
    if (typeof orderOrStatus === 'string') {
        statusValue = orderOrStatus;
    } else if (orderOrStatus && typeof orderOrStatus === 'object') {
        statusValue = orderOrStatus.status !== undefined ? orderOrStatus.status : orderOrStatus.status_raw;
    }
    const normalizedStatus = normalizeOrderStatus(statusValue);
    const isCanceled = isOrderStatusCanceled(normalizedStatus);
    const mode = normalizeViewMode(viewMode);

    if (mode === 'all') {
        return true;
    }

    if (mode === 'canceled') {
        return isCanceled;
    }

    return !isCanceled;
}

function initRealtimeSearch() {
    const searchInput = document.querySelector('.search-input');
    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase();
        document.querySelectorAll('.table tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(term) ? '' : 'none';
        });
    });
}

function initOrdersRealtimeUpdates() {
    const table = document.querySelector('.orders-table');
    const tbody = table ? table.querySelector('tbody') : null;

    if (!table || !tbody) {
        return;
    }

    ordersLatestTimestamp = table.dataset.lastUpdated || '';
    if (!ordersLatestTimestamp) {
        ordersLatestTimestamp = new Date().toISOString();
    }

    const handleVisibility = () => {
        if (document.hidden) {
            stopOrdersRealtimePolling();
        } else {
            startOrdersRealtimePolling();
        }
    };

    document.addEventListener('visibilitychange', handleVisibility);
    window.addEventListener('beforeunload', stopOrdersRealtimePolling);

    document.addEventListener('orders:awb-generated', event => {
        const detail = event.detail || {};
        if (!detail.orderId) {
            return;
        }
        const row = tbody.querySelector(`tr[data-order-id="${detail.orderId}"]`);
        if (!row) {
            return;
        }
        if (detail.awbCode) {
            row.dataset.awb = detail.awbCode;
            showOrdersToast('success', `AWB generat pentru comanda ${detail.orderNumber || detail.awbCode}.`);
        }
    });

    startOrdersRealtimePolling();
}

function startOrdersRealtimePolling() {
    if (ordersRealtimeTimer || document.hidden) {
        return;
    }

    const poll = () => {
        if (document.hidden) {
            ordersRealtimeTimer = null;
            return;
        }

        fetchOrderUpdates()
            .catch(error => {
                console.error('Order updates polling error:', error);
            })
            .finally(() => {
                ordersRealtimeTimer = setTimeout(poll, ORDERS_REALTIME_INTERVAL_MS);
            });
    };

    poll();
}

function stopOrdersRealtimePolling() {
    if (ordersRealtimeTimer) {
        clearTimeout(ordersRealtimeTimer);
        ordersRealtimeTimer = null;
    }
    if (ordersRealtimeController) {
        ordersRealtimeController.abort();
        ordersRealtimeController = null;
    }
}

function fetchOrderUpdates() {
    const table = document.querySelector('.orders-table');
    if (!table) {
        return Promise.resolve();
    }

    const tbody = table.querySelector('tbody');
    if (!tbody) {
        return Promise.resolve();
    }

    const params = new URLSearchParams();
    if (ordersLatestTimestamp) {
        params.set('since', ordersLatestTimestamp);
    }
    params.set('status', table.dataset.statusFilter || '');
    params.set('priority', table.dataset.priorityFilter || '');
    params.set('search', table.dataset.search || '');
    params.set('page', table.dataset.page || '1');
    params.set('pageSize', table.dataset.pageSize || '25');

    if (ordersRealtimeController) {
        ordersRealtimeController.abort();
    }
    ordersRealtimeController = new AbortController();

    return fetch(`api/orders/updates.php?${params.toString()}`, {
        signal: ordersRealtimeController.signal,
        credentials: 'same-origin'
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(payload => {
            if (payload.status !== 'success' || !payload.data) {
                throw new Error(payload.message || 'Răspuns invalid de la server');
            }

            const updates = Array.isArray(payload.data.orders) ? payload.data.orders : [];
            if (payload.data.latestTimestamp) {
                ordersLatestTimestamp = payload.data.latestTimestamp;
                table.dataset.lastUpdated = ordersLatestTimestamp;
            }

            if (updates.length) {
                applyOrderUpdates(updates, tbody);
            }
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                return;
            }
            throw error;
        });
}

function applyOrderUpdates(updates, tbody) {
    const table = tbody.closest('.orders-table');
    const scrollContainer = table ? table.closest('.table-responsive') : null;
    const previousScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;

    const existingRows = new Map();
    tbody.querySelectorAll('tr[data-order-id]').forEach(row => {
        const id = Number(row.getAttribute('data-order-id'));
        if (!Number.isNaN(id)) {
            existingRows.set(id, row);
        }
    });

    const newRows = [];

    const viewMode = normalizeViewMode(table && table.dataset ? table.dataset.viewMode : undefined);

    updates.forEach(order => {
        const id = Number(order.id);
        if (Number.isNaN(id)) {
            return;
        }

        const existingRow = existingRows.get(id);
        if (existingRow) {
            const changes = updateOrderRow(existingRow, order);
            const stillVisible = isOrderVisibleInView(changes.status || order, viewMode);
            if (!stillVisible) {
                existingRow.remove();
                existingRows.delete(id);
            }
            if (changes.statusChanged) {
                if (stillVisible) {
                    flashStatusChange(existingRow);
                }
                let message;
                const becameActive = !changes.isCanceled && isOrderStatusCanceled(changes.previousStatus);
                if (changes.isCanceled) {
                    message = viewMode === 'active'
                        ? `Comanda ${order.order_number || `#${id}`} a fost anulată și mutată în lista de comenzi anulate.`
                        : `Comanda ${order.order_number || `#${id}`} a fost anulată.`;
                } else if (becameActive && viewMode === 'canceled') {
                    message = `Comanda ${order.order_number || `#${id}`} a fost restaurată și mutată în lista de comenzi active.`;
                } else {
                    message = `Statusul comenzii ${order.order_number || `#${id}`} a devenit ${order.status_label || ''}.`;
                }
                showOrdersToast('info', message);
            }
            if (changes.awbUpdated) {
                showOrdersToast('success', `AWB generat pentru comanda ${order.order_number || `#${id}`}.`);
            }
        } else if (shouldRenderOrder(order, table, viewMode)) {
            const row = renderOrderRow(order);
            newRows.push({ row, order });
        }
    });

    if (newRows.length) {
        newRows.sort((a, b) => {
            const aDate = new Date(a.order.order_date || a.order.updated_at || 0).getTime();
            const bDate = new Date(b.order.order_date || b.order.updated_at || 0).getTime();
            return bDate - aDate;
        });

        newRows.forEach(({ row }) => {
            tbody.insertBefore(row, tbody.firstChild);
            flashNewOrder(row);
        });

        const firstOrder = newRows[0].order;
        const toastMessage = newRows.length === 1
            ? `A fost adăugată o comandă nouă: ${firstOrder.order_number || `#${firstOrder.id}`}.`
            : `${newRows.length} comenzi noi au fost adăugate.`;
        showOrdersToast('success', toastMessage);
    }

    if (scrollContainer) {
        scrollContainer.scrollTop = previousScrollTop;
    }
}

function shouldRenderOrder(order, table, viewMode) {
    const currentPage = Number(table.dataset.page || '1');
    if (currentPage > 1) {
        return false;
    }

    return isOrderVisibleInView(order, viewMode);
}

function updateOrderRow(row, order) {
    const status = normalizeOrderStatus(order.status);
    const statusRaw = order.status_raw || order.status || status;
    const previousStatus = normalizeOrderStatus(row.getAttribute('data-status') || '');
    const result = {
        statusChanged: false,
        awbUpdated: false,
        status,
        previousStatus,
        isCanceled: isOrderStatusCanceled(status)
    };

    if (status && previousStatus !== status) {
        result.statusChanged = true;
        row.setAttribute('data-status', status);
    }

    const isCanceled = result.isCanceled;
    row.classList.toggle('order-row--canceled', isCanceled);
    row.setAttribute('data-is-canceled', isCanceled ? '1' : '0');

    if (order.updated_at) {
        row.setAttribute('data-updated-at', order.updated_at);
    }

    const orderNumberEl = row.querySelector('.order-number');
    if (orderNumberEl && order.order_number) {
        orderNumberEl.textContent = order.order_number;
    }

    const customerInfo = row.querySelector('.customer-info');
    if (customerInfo) {
        const name = order.customer_name || '';
        const email = order.customer_email || '';
        customerInfo.innerHTML = `<strong>${escapeHtml(name)}</strong>${email ? `<br><small>${escapeHtml(email)}</small>` : ''}`;
    }

    const orderDateEl = row.querySelector('.order-date-value');
    if (orderDateEl) {
        const formattedDate = order.order_date_display || formatRomanianDate(order.order_date);
        if (formattedDate) {
            orderDateEl.textContent = formattedDate;
        }
    }

    const statusBadge = row.querySelector('.order-status-badge');
    if (statusBadge) {
        const sanitizedStatus = sanitizeStatus(status);
        statusBadge.dataset.status = status;

        const classesToRemove = Array.from(statusBadge.classList).filter((cls) =>
            cls.startsWith('status-') &&
            cls !== 'status-badge' &&
            cls !== 'status-change-flash' &&
            cls !== `status-${sanitizedStatus}`
        );

        classesToRemove.forEach((cls) => statusBadge.classList.remove(cls));
        statusBadge.classList.add('status-badge', 'order-status-badge');
        if (sanitizedStatus) {
            statusBadge.classList.add(`status-${sanitizedStatus}`);
        }

        const statusIcon = statusBadge.querySelector('.material-symbols-outlined');
        if (statusIcon) {
            statusIcon.textContent = isCanceled ? 'block' : 'flag';
        }
        const statusText = statusBadge.querySelector('.order-status-text');
        if (statusText) {
            statusText.textContent = order.status_label || capitalize(status);
        }
    }

    const canceledAtDisplay = order.canceled_at_display || (order.canceled_at ? formatRomanianDate(order.canceled_at) : '');
    const canceledBy = order.canceled_by_full_name || order.canceled_by_username || order.canceled_by_email || order.canceled_by || '';
    const statusMeta = row.querySelector('.order-status-meta');

    if (isCanceled && (canceledAtDisplay || canceledBy)) {
        const parts = [];
        if (canceledAtDisplay) {
            parts.push(`Anulat la <strong>${escapeHtml(canceledAtDisplay)}</strong>`);
        }
        if (canceledBy) {
            parts.push(`de <strong>${escapeHtml(canceledBy)}</strong>`);
        }
        const metaHtml = parts.join('<br>');
        if (statusMeta) {
            statusMeta.innerHTML = metaHtml;
        } else if (statusBadge) {
            const metaEl = document.createElement('div');
            metaEl.className = 'order-status-meta';
            metaEl.innerHTML = metaHtml;
            statusBadge.insertAdjacentElement('afterend', metaEl);
        }
    } else if (statusMeta) {
        statusMeta.remove();
    }

    const priorityBadge = row.querySelector('.priority-badge');
    if (priorityBadge) {
        const priority = (order.priority || 'normal').toLowerCase();
        priorityBadge.textContent = order.priority_label || capitalize(priority);
        priorityBadge.className = `priority-badge priority-${sanitizeStatus(priority)}`;
    }

    const totalValueEl = row.querySelector('.order-total-value');
    if (totalValueEl) {
        totalValueEl.textContent = `${formatCurrency(order.total_value)} Lei`;
    }

    const itemsCountEl = row.querySelector('.order-items-count');
    if (itemsCountEl) {
        const items = Number(order.total_items || 0);
        itemsCountEl.textContent = `${items} ${items === 1 ? 'produs' : 'produse'}`;
    }

    const weightEl = row.querySelector('.order-weight');
    if (weightEl) {
        const weight = order.weight_display ? `${order.weight_display} kg` : '0.000 kg';
        weightEl.textContent = weight;
        if (order.weight_breakdown) {
            weightEl.setAttribute('title', order.weight_breakdown);
        } else {
            weightEl.removeAttribute('title');
        }
    }

    const previousAwb = row.getAttribute('data-awb') || '';
    const newAwb = order.awb_barcode ? String(order.awb_barcode) : '';

    const awbCell = row.querySelector('.awb-cell');
    if (awbCell) {
        awbCell.innerHTML = renderAwbCell(order);
        if (previousAwb !== newAwb) {
            awbCell.classList.add('awb-update-flash');
            awbCell.addEventListener('animationend', () => awbCell.classList.remove('awb-update-flash'), { once: true });
        }
    }

    if (previousAwb !== newAwb) {
        result.awbUpdated = !!newAwb;
        row.setAttribute('data-awb', newAwb);
    }

    const actionsCell = row.querySelector('td:last-child');
    if (actionsCell) {
        actionsCell.innerHTML = renderOrderActions(order, statusRaw);
    }

    return result;
}

function renderOrderRow(order) {
    const row = document.createElement('tr');
    const status = normalizeOrderStatus(order.status);
    const sanitizedStatus = sanitizeStatus(status);
    const statusRaw = order.status_raw || order.status || status;
    const isCanceled = isOrderStatusCanceled(status);

    row.className = `order-row${isCanceled ? ' order-row--canceled' : ''}`;
    row.setAttribute('data-order-id', order.id);
    row.setAttribute('data-status', status);
    row.setAttribute('data-is-canceled', isCanceled ? '1' : '0');
    if (order.updated_at) {
        row.setAttribute('data-updated-at', order.updated_at);
    }
    row.setAttribute('data-awb', order.awb_barcode ? String(order.awb_barcode) : '');

    const priority = (order.priority || 'normal').toLowerCase();
    const orderDateDisplay = order.order_date_display || formatRomanianDate(order.order_date);
    const customerEmail = order.customer_email ? `<br><small>${escapeHtml(order.customer_email)}</small>` : '';
    const canceledAtDisplay = order.canceled_at_display || (order.canceled_at ? formatRomanianDate(order.canceled_at) : '');
    const canceledBy = order.canceled_by_full_name || order.canceled_by_username || order.canceled_by_email || order.canceled_by || '';
    const statusMetaHtml = isCanceled && (canceledAtDisplay || canceledBy)
        ? `<div class="order-status-meta">${[
                canceledAtDisplay ? `Anulat la <strong>${escapeHtml(canceledAtDisplay)}</strong>` : '',
                canceledBy ? `de <strong>${escapeHtml(canceledBy)}</strong>` : ''
            ].filter(Boolean).join('<br>')}</div>`
        : '';

    row.innerHTML = `
        <td>
            <code class="order-number">${escapeHtml(order.order_number || '')}</code>
        </td>
        <td>
            <div class="customer-info">
                <strong>${escapeHtml(order.customer_name || '')}</strong>
                ${customerEmail}
            </div>
        </td>
        <td class="order-date-cell">
            <small class="order-date-value">${escapeHtml(orderDateDisplay || '')}</small>
        </td>
        <td>
            <span class="${['status-badge', 'order-status-badge', sanitizedStatus ? `status-${sanitizedStatus}` : ''].filter(Boolean).join(' ')}" data-status="${escapeHtml(status)}">
                <span class="material-symbols-outlined">${isCanceled ? 'block' : 'flag'}</span>
                <span class="order-status-text">${escapeHtml(order.status_label || capitalize(status))}</span>
            </span>
            ${statusMetaHtml}
        </td>
        <td>
            <span class="priority-badge priority-${sanitizeStatus(priority)}">${escapeHtml(order.priority_label || capitalize(priority))}</span>
        </td>
        <td>
            <strong class="order-total-value">${formatCurrency(order.total_value)} Lei</strong>
        </td>
        <td>
            <span class="text-center order-items-count">${Number(order.total_items || 0)} ${Number(order.total_items || 0) === 1 ? 'produs' : 'produse'}</span>
        </td>
        <td>
            <span class="order-weight"${order.weight_breakdown ? ` title="${escapeHtml(order.weight_breakdown)}"` : ''}>${order.weight_display ? escapeHtml(`${order.weight_display} kg`) : '0.000 kg'}</span>
        </td>
        <td class="awb-column awb-cell">
            ${renderAwbCell(order)}
        </td>
        <td>
            ${renderOrderActions(order, statusRaw)}
        </td>
    `;

    return row;
}

function renderAwbCell(order) {
    const isCanceled = isOrderStatusCanceled(order.status);
    if (isCanceled) {
        return '<div class="text-muted small">Anulat - AWB indisponibil</div>';
    }

    const attempts = Number(order.awb_generation_attempts || 0);
    const awb = order.awb_barcode ? String(order.awb_barcode) : '';
    const hasAwb = awb !== '';
    const orderId = Number(order.id);
    const orderNumber = order.order_number || `#${orderId}`;
    const attemptMessage = !hasAwb && attempts > 0
        ? `${attempts} ${attempts === 1 ? 'încercare efectuată' : 'încercări efectuate'}`
        : '';

    const attemptHtml = attemptMessage ? `<div class="awb-attempts">${escapeHtml(attemptMessage)}</div>` : '';

    if (hasAwb) {
        const trackingUrl = `https://www.cargus.ro/personal/urmareste-coletul/?tracking_number=${encodeURIComponent(awb)}&Urm%C4%83re%C8%99te=Urm%C4%83re%C8%99te`;
        const awbDate = order.awb_created_at ? formatRomanianDate(order.awb_created_at) : '';
        const escapedOrderNumber = escapeJsString(orderNumber);
        const escapedAwb = escapeJsString(awb);
        const awbForAttr = escapeHtml(awb);
        const awbDateLabel = awbDate ? `Generat la ${escapeHtml(awbDate)}` : '';
        return `
            ${attemptHtml}
            <div class="awb-info" data-awb-toggle-container>
                <div class="awb-info-primary">
                    <button type="button"
                        class="awb-barcode awb-timeline-toggle"
                        data-order-id="${orderId}"
                        data-awb="${awbForAttr}"
                        title="Vezi istoricul expediției">
                        <span class="awb-barcode-text">${escapeHtml(awb)}</span>
                        <span class="material-symbols-outlined awb-barcode-icon" aria-hidden="true">expand_more</span>
                        <span class="visually-hidden">Istoric AWB pentru comanda ${escapeHtml(orderNumber)}</span>
                    </button>
                    ${awbDate ? `<small class="awb-barcode-meta">${awbDateLabel}</small>` : ''}
                </div>
                <div class="awb-info-actions">
                    <a href="${trackingUrl}" class="btn btn-sm btn-outline-secondary track-awb-link" target="_blank" rel="noopener noreferrer">
                        <span class="material-symbols-outlined">open_in_new</span> Urmărește AWB
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-success print-awb-btn" onclick="printAWB(${orderId}, '${escapedAwb}', '${escapedOrderNumber}')">
                        <span class="material-symbols-outlined">print</span> Printează AWB
                    </button>
                </div>
            </div>
        `;
    }

    return `
        ${attemptHtml}
        <button type="button" class="btn btn-sm btn-outline-primary generate-awb-btn" data-order-id="${orderId}" title="Generează AWB">
            <span class="material-symbols-outlined">local_shipping</span>
            Generează AWB
        </button>
    `;
}

function renderOrderActions(order, statusRawValue) {
    const id = Number(order.id);
    const orderNumber = order.order_number || `#${id}`;
    const escapedOrderNumber = escapeJsString(orderNumber);
    const normalizedStatus = normalizeOrderStatus(statusRawValue || order.status || '');
    const status = escapeJsString(normalizedStatus);
    const isCanceled = isOrderStatusCanceled(order.status);
    const table = document.querySelector('.orders-table');
    const viewMode = table ? table.dataset.viewMode || 'active' : 'active';
    const escapedViewMode = escapeHtml(viewMode);

    if (isCanceled) {
        return `
        <div class="btn-group">
            <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(${id})" title="Vezi detalii">
                <span class="material-symbols-outlined">visibility</span>
            </button>
            <form method="POST" class="inline-form" style="display: inline-block;" onsubmit="return confirm('Reactivezi această comandă?');">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="order_id" value="${id}">
                <input type="hidden" name="restore_status" value="pending">
                <input type="hidden" name="view" value="${escapedViewMode}">
                <button type="submit" class="btn btn-sm btn-outline-success" title="Restaurează comanda">
                    <span class="material-symbols-outlined">restore</span>
                </button>
            </form>
        </div>
    `;
    }

    return `
        <div class="btn-group">
            <button class="btn btn-sm btn-outline-primary" onclick="viewOrderDetails(${id})" title="Vezi detalii">
                <span class="material-symbols-outlined">visibility</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="openStatusModal(${id}, '${status}')" title="Schimbă status">
                <span class="material-symbols-outlined">edit</span>
            </button>
            <button class="btn btn-sm btn-outline-info" onclick="printInvoiceWithSelection(${id})" title="Printează Factura">
                <span class="material-symbols-outlined">print</span>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="openCancelModal(${id}, '${escapedOrderNumber}')" title="Anulează comanda">
                <span class="material-symbols-outlined">cancel</span>
            </button>
        </div>
    `;
}

function flashNewOrder(row) {
    row.classList.add('order-row-new');
    row.addEventListener('animationend', () => row.classList.remove('order-row-new'), { once: true });
}

function flashStatusChange(row) {
    row.classList.add('order-status-changed');
    row.addEventListener('animationend', () => row.classList.remove('order-status-changed'), { once: true });
    const badge = row.querySelector('.order-status-badge');
    if (badge) {
        badge.classList.add('status-change-flash');
        badge.addEventListener('animationend', () => badge.classList.remove('status-change-flash'), { once: true });
    }
}

function showOrdersToast(type, message) {
    if (!message) {
        return;
    }

    const container = document.querySelector('.orders-toast-container');
    if (!container) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `orders-toast orders-toast-${type || 'info'}`;

    const icon = document.createElement('span');
    icon.className = 'material-symbols-outlined';
    icon.textContent = type === 'success' ? 'check_circle' : type === 'warning' ? 'warning' : 'info';

    const messageEl = document.createElement('div');
    messageEl.className = 'orders-toast-message';
    messageEl.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(messageEl);

    container.appendChild(toast);

    requestAnimationFrame(() => {
        toast.classList.add('show');
    });

    setTimeout(() => {
        toast.classList.remove('show');
        toast.classList.add('hide');
        toast.addEventListener('animationend', () => toast.remove(), { once: true });
    }, 3000);
}

function sanitizeStatus(value) {
    return (value || '').toLowerCase().replace(/[^a-z0-9_-]/g, '-');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeJsString(value) {
    return String(value ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"');
}

function normalizeManualProductRecord(raw) {
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    const rawId = raw.product_id ?? raw.id ?? raw.internal_product_id ?? raw.internal_id;
    const id = Number(rawId);
    if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    const name = raw.name ?? '';
    const sku = raw.sku ?? raw.code ?? raw.product_code ?? '';
    const unit = raw.unit_of_measure ?? raw.unit ?? raw.measure ?? '';
    const priceValue = raw.price ?? raw.unit_price ?? raw.default_price;
    const price = priceValue === null || priceValue === undefined ? null : Number(priceValue);

    return {
        id,
        name,
        sku,
        unit,
        price: Number.isFinite(price) ? price : null
    };
}

function getNormalizedManualProducts() {
    const rawList = Array.isArray(window.orderProductsList) ? window.orderProductsList : [];
    if (manualProductLocalNormalized && manualProductLocalRawReference === rawList) {
        return manualProductLocalNormalized;
    }

    manualProductLocalRawReference = rawList;
    manualProductLocalNormalized = rawList
        .map(normalizeManualProductRecord)
        .filter(Boolean);

    return manualProductLocalNormalized;
}

function getManualProductResultsElement(input) {
    if (!input) {
        return null;
    }
    const wrapper = input.closest('[data-product-field]');
    return wrapper ? wrapper.querySelector('.manual-product-results') : null;
}

function showManualProductLoading(resultsEl) {
    if (!resultsEl) {
        return;
    }
    resultsEl.innerHTML = '<div class="manual-product-option manual-product-option-loading" style="padding: 0.5rem 0.75rem; color: #495057; font-size: 0.9rem;">Se caută…</div>';
    resultsEl.style.display = 'block';
    resultsEl.dataset.hasResults = '0';
    resultsEl.dataset.activeIndex = '-1';
}

function hideManualProductResults(resultsEl) {
    if (!resultsEl) {
        return;
    }
    resultsEl.style.display = 'none';
    resultsEl.dataset.activeIndex = '-1';
}

function closeAllManualProductResults(exceptEl) {
    document.querySelectorAll('.manual-product-results').forEach(resultsEl => {
        if (resultsEl !== exceptEl) {
            hideManualProductResults(resultsEl);
        }
    });
}

function mergeManualProductResults(...sources) {
    const unique = new Map();
    sources.forEach(source => {
        if (!Array.isArray(source) || !source.length) {
            return;
        }

        source.forEach(item => {
            const normalized = normalizeManualProductRecord(item);
            if (!normalized) {
                return;
            }

            if (!unique.has(normalized.id)) {
                unique.set(normalized.id, normalized);
            }
        });
    });

    return Array.from(unique.values());
}

function cacheManualProductResults(cacheKey, products) {
    if (!cacheKey) {
        return;
    }

    const normalizedKey = cacheKey.toLowerCase();
    const trimmedList = Array.isArray(products)
        ? products.slice(0, MANUAL_PRODUCT_SEARCH_CACHE_RESULT_LIMIT)
        : [];

    if (manualProductSearchCache.size >= MANUAL_PRODUCT_SEARCH_CACHE_SIZE) {
        const oldestKey = manualProductSearchCache.keys().next().value;
        if (oldestKey !== undefined) {
            manualProductSearchCache.delete(oldestKey);
        }
    }

    manualProductSearchCache.set(normalizedKey, trimmedList);
}

function getCachedManualProductMatches(query, limit = MANUAL_PRODUCT_RESULTS_LIMIT) {
    const trimmed = (query || '').trim();
    if (!trimmed || trimmed.length < MANUAL_PRODUCT_SEARCH_MIN_CHARS) {
        return [];
    }

    const normalizedQuery = trimmed.toLowerCase();
    const bufferLimit = limit * 3;
    const aggregated = [];

    for (const [cacheKey, products] of manualProductSearchCache.entries()) {
        if (!cacheKey || !products || !products.length) {
            continue;
        }

        if (!cacheKey.includes(normalizedQuery) && !normalizedQuery.includes(cacheKey)) {
            continue;
        }

        for (let index = 0; index < products.length; index += 1) {
            const normalized = normalizeManualProductRecord(products[index]);
            if (!normalized) {
                continue;
            }

            const name = (normalized.name || '').toLowerCase();
            const sku = (normalized.sku || '').toLowerCase();
            if (!name.includes(normalizedQuery) && !sku.includes(normalizedQuery)) {
                continue;
            }

            aggregated.push(normalized);

            if (aggregated.length >= bufferLimit) {
                break;
            }
        }

        if (aggregated.length >= bufferLimit) {
            break;
        }
    }

    if (!aggregated.length) {
        return [];
    }

    return mergeManualProductResults(aggregated).slice(0, limit);
}

function renderManualProductResults(input, products, query) {
    const resultsEl = getManualProductResultsElement(input);
    if (!resultsEl) {
        return;
    }

    resultsEl.dataset.currentQuery = query;
    resultsEl.dataset.activeIndex = '-1';

    const safeProducts = Array.isArray(products) ? products : [];

    if (safeProducts.length === 0) {
        const message = query && query.length
            ? `Nu s-au găsit produse pentru „${escapeHtml(query)}”`
            : 'Nu există produse disponibile';
        resultsEl.innerHTML = `<div class="manual-product-option manual-product-option-empty" style="padding: 0.5rem 0.75rem; color: #868e96; font-size: 0.9rem;">${message}</div>`;
        resultsEl.dataset.hasResults = '0';
        resultsEl.style.display = 'block';
        return;
    }

    const optionHtml = safeProducts.slice(0, MANUAL_PRODUCT_RESULTS_LIMIT).map(product => {
        const name = escapeHtml(product.name || 'Produs fără nume');
        const sku = product.sku ? escapeHtml(product.sku) : '';
        const unit = product.unit ? escapeHtml(product.unit) : '';
        const priceValue = Number(product.price);
        const hasPrice = Number.isFinite(priceValue) && priceValue > 0;
        const metaParts = [];
        if (sku) {
            metaParts.push(sku);
        }
        if (unit) {
            metaParts.push(unit);
        }
        if (hasPrice) {
            metaParts.push(`${priceValue.toFixed(2)} RON`);
        }
        const meta = metaParts.length
            ? `<div class="manual-product-option__meta" style="font-size: 0.8rem; color: #868e96;">${metaParts.join(' · ')}</div>`
            : '';

        return `
            <div class="manual-product-option" role="button" tabindex="-1"
                data-product-id="${product.id}"
                data-product-name="${name}"
                data-product-sku="${sku}"
                data-product-unit="${unit}"
                data-product-price="${hasPrice ? priceValue : ''}"
                style="padding: 0.5rem 0.75rem; cursor: pointer; display: flex; flex-direction: column; gap: 0.125rem; border-bottom: 1px solid #f1f3f5;">
                <div class="manual-product-option__title" style="font-weight: 600; color: #212529;">${name}</div>
                ${meta}
            </div>
        `;
    }).join('');

    resultsEl.innerHTML = optionHtml;
    resultsEl.dataset.hasResults = '1';
    resultsEl.style.display = 'block';
}

function getLocalManualProductMatches(query, limit = MANUAL_PRODUCT_RESULTS_LIMIT) {
    if (!query || query.length < MANUAL_PRODUCT_SEARCH_MIN_CHARS) {
        return [];
    }

    const products = getNormalizedManualProducts();
    const normalizedQuery = query.toLowerCase();
    const matches = [];

    for (let index = 0; index < products.length; index += 1) {
        const normalized = products[index];

        const nameMatch = (normalized.name || '').toLowerCase().includes(normalizedQuery);
        const skuMatch = (normalized.sku || '').toLowerCase().includes(normalizedQuery);

        if (nameMatch || skuMatch) {
            matches.push(normalized);
        }

        if (matches.length >= limit) {
            break;
        }
    }

    return matches;
}

function fetchManualProductSuggestions(query, input) {
    const trimmed = (query || '').trim();
    if (!trimmed) {
        return Promise.resolve([]);
    }

    const cacheKey = trimmed.toLowerCase();
    if (manualProductSearchCache.has(cacheKey)) {
        return Promise.resolve(manualProductSearchCache.get(cacheKey));
    }

    let controller = null;
    if (input && typeof AbortController === 'function') {
        const existing = manualProductSearchControllers.get(input);
        if (existing) {
            existing.abort();
        }

        controller = new AbortController();
        manualProductSearchControllers.set(input, controller);
    }

    const params = new URLSearchParams({
        search: trimmed,
        limit: String(Math.max(MANUAL_PRODUCT_RESULTS_LIMIT, 15)),
        fast: '1'
    });

    return fetch(`api/products.php?${params.toString()}`, {
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(payload => {
            const list = Array.isArray(payload)
                ? payload
                : (Array.isArray(payload.data) ? payload.data : []);
            const normalized = list
                .map(normalizeManualProductRecord)
                .filter(Boolean);
            cacheManualProductResults(cacheKey, normalized);
            return manualProductSearchCache.get(cacheKey) || [];
        })
        .catch(error => {
            if (error && error.name === 'AbortError') {
                return [];
            }

            console.warn('Manual product search failed:', error);
            return [];
        })
        .finally(() => {
            if (!input) {
                return;
            }

            const active = manualProductSearchControllers.get(input);
            if (active === controller) {
                manualProductSearchControllers.delete(input);
            }
        });
}

function highlightManualProductOption(resultsEl, nextIndex) {
    if (!resultsEl) {
        return;
    }

    const options = Array.from(resultsEl.querySelectorAll('.manual-product-option[role="button"]'));
    if (!options.length) {
        resultsEl.dataset.activeIndex = '-1';
        return;
    }

    let targetIndex = Number(nextIndex);
    if (!Number.isFinite(targetIndex)) {
        targetIndex = -1;
    }

    if (targetIndex < 0) {
        options.forEach(option => {
            option.classList.remove('is-active');
            option.style.backgroundColor = '';
        });
        resultsEl.dataset.activeIndex = '-1';
        return;
    }

    const normalizedIndex = Math.max(0, Math.min(options.length - 1, targetIndex));
    options.forEach((option, idx) => {
        if (idx === normalizedIndex) {
            option.classList.add('is-active');
            option.style.backgroundColor = '#f1f3f5';
            option.scrollIntoView({ block: 'nearest' });
        } else {
            option.classList.remove('is-active');
            option.style.backgroundColor = '';
        }
    });

    resultsEl.dataset.activeIndex = String(normalizedIndex);
}

function selectManualProductFromOption(optionEl) {
    if (!optionEl) {
        return;
    }

    const wrapper = optionEl.closest('[data-product-field]');
    if (!wrapper) {
        return;
    }

    const priceAttr = optionEl.dataset.productPrice;
    const product = {
        id: Number(optionEl.dataset.productId || 0),
        name: optionEl.dataset.productName || '',
        sku: optionEl.dataset.productSku || '',
        unit: optionEl.dataset.productUnit || '',
        price: priceAttr === '' || priceAttr === undefined ? null : Number(priceAttr)
    };

    selectManualProduct(wrapper, product);
}

function selectManualProduct(wrapper, product) {
    if (!wrapper || !product || !Number.isFinite(Number(product.id)) || Number(product.id) <= 0) {
        return;
    }

    const hiddenInput = wrapper.querySelector('.manual-product-id');
    if (hiddenInput) {
        hiddenInput.value = String(product.id);
    }

    const searchInput = wrapper.querySelector('.manual-product-search');
    if (searchInput) {
        const displayLabel = product.sku ? `${product.name} (${product.sku})` : product.name;
        searchInput.value = displayLabel;
        searchInput.dataset.selectedProductId = String(product.id);
        searchInput.dataset.currentQuery = displayLabel;
    }

    const resultsEl = wrapper.querySelector('.manual-product-results');
    if (resultsEl) {
        hideManualProductResults(resultsEl);
    }

    const orderItemRow = wrapper.closest('.order-item');
    if (orderItemRow) {
        const priceInput = orderItemRow.querySelector('input[name*="[unit_price]"]');
        const priceValue = Number(product.price);
        if (priceInput && (!priceInput.value || Number(priceInput.value) === 0) && Number.isFinite(priceValue) && priceValue > 0) {
            priceInput.value = priceValue.toFixed(2);
        }

        const quantityInput = orderItemRow.querySelector('input[name*="[quantity]"]');
        if (quantityInput && !quantityInput.value) {
            quantityInput.focus();
        }
    }
}

function handleManualProductSearchInput(input) {
    if (!input) {
        return;
    }

    const wrapper = input.closest('[data-product-field]');
    if (!wrapper) {
        return;
    }

    const hiddenInput = wrapper.querySelector('.manual-product-id');
    if (hiddenInput) {
        hiddenInput.value = '';
    }

    input.dataset.selectedProductId = '';

    const resultsEl = getManualProductResultsElement(input);
    if (!resultsEl) {
        return;
    }

    const query = input.value.trim();
    input.dataset.currentQuery = query;
    resultsEl.dataset.currentQuery = query;

    if (query.length < MANUAL_PRODUCT_SEARCH_MIN_CHARS) {
        resultsEl.innerHTML = '';
        hideManualProductResults(resultsEl);
        resultsEl.dataset.hasResults = '0';
        return;
    }

    const localMatches = getLocalManualProductMatches(query, MANUAL_PRODUCT_RESULTS_LIMIT);
    const cachedMatches = getCachedManualProductMatches(query, MANUAL_PRODUCT_RESULTS_LIMIT);
    const baseMatches = mergeManualProductResults(localMatches, cachedMatches).slice(0, MANUAL_PRODUCT_RESULTS_LIMIT);

    if (baseMatches.length > 0) {
        renderManualProductResults(input, baseMatches, query);
    } else {
        showManualProductLoading(resultsEl);
    }

    if (manualProductSearchTimers.has(input)) {
        clearTimeout(manualProductSearchTimers.get(input));
    }

    const timeoutId = window.setTimeout(() => {
        manualProductSearchTimers.delete(input);
        fetchManualProductSuggestions(query, input).then(remoteMatches => {
            if (input.dataset.currentQuery !== query) {
                return;
            }
            const combined = mergeManualProductResults(baseMatches, remoteMatches).slice(0, MANUAL_PRODUCT_RESULTS_LIMIT);
            renderManualProductResults(input, combined, query);
        });
    }, MANUAL_PRODUCT_SEARCH_DELAY_MS);

    manualProductSearchTimers.set(input, timeoutId);
}

function handleManualProductKeydown(event) {
    const input = event.target;
    if (!input.classList || !input.classList.contains('manual-product-search')) {
        return;
    }

    const resultsEl = getManualProductResultsElement(input);
    if (!resultsEl) {
        return;
    }

    const options = Array.from(resultsEl.querySelectorAll('.manual-product-option[role="button"]'));
    switch (event.key) {
        case 'ArrowDown': {
            if (resultsEl.style.display === 'none') {
                handleManualProductSearchInput(input);
            } else if (options.length) {
                const currentIndex = Number(resultsEl.dataset.activeIndex ?? -1);
                const nextIndex = Number.isFinite(currentIndex) && currentIndex >= 0
                    ? (currentIndex + 1) % options.length
                    : 0;
                highlightManualProductOption(resultsEl, nextIndex);
            }
            event.preventDefault();
            break;
        }
        case 'ArrowUp': {
            if (options.length) {
                const currentIndex = Number(resultsEl.dataset.activeIndex ?? -1);
                const nextIndex = Number.isFinite(currentIndex) && currentIndex >= 0
                    ? (currentIndex - 1 + options.length) % options.length
                    : options.length - 1;
                highlightManualProductOption(resultsEl, nextIndex);
            }
            event.preventDefault();
            break;
        }
        case 'Enter': {
            if (resultsEl.style.display !== 'none' && options.length) {
                const activeIndex = Number(resultsEl.dataset.activeIndex ?? -1);
                const option = options[activeIndex >= 0 ? activeIndex : 0];
                if (option) {
                    selectManualProductFromOption(option);
                    event.preventDefault();
                }
            }
            break;
        }
        case 'Escape': {
            hideManualProductResults(resultsEl);
            event.preventDefault();
            break;
        }
        default:
            break;
    }
}

function formatCurrency(value) {
    const number = Number(value || 0);
    return number.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatRomanianDate(value) {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}.${month}.${year} ${hours}:${minutes}`;
}

function capitalize(value) {
    if (!value) {
        return '';
    }
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function generateManualOrderNumber() {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const random = String(Math.floor(Math.random() * 9000) + 1000);
    return `MAN-${year}${month}${day}-${hours}${minutes}${seconds}-${random}`;
}

let itemCounter = 1;

/**
 * Opens the modal for creating a new order.
 */
function openCreateModal() {
    if (typeof closeOrderDetailsModal === 'function') {
        closeOrderDetailsModal();
    }

    const modal = document.getElementById('createOrderModal');
    if (!modal) {
        return;
    }

    const orderNumberInput = document.getElementById('order_number');
    if (orderNumberInput) {
        orderNumberInput.value = generateManualOrderNumber();
    }

    const orderDateInput = document.getElementById('order_date');
    if (orderDateInput) {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        orderDateInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    const customerNameInput = document.getElementById('customer_name');
    const contactInput = document.getElementById('recipient_contact_person');
    if (customerNameInput && contactInput && contactInput.dataset.userEdited !== '1') {
        contactInput.value = customerNameInput.value;
    }

    setupCreateOrderAutocomplete();
    modal.classList.add('show');
}

/**
 * Closes and resets the "Create Order" modal.
 */
function closeCreateModal() {
    const modal = document.getElementById('createOrderModal');
    if (modal) {
        modal.classList.remove('show');
    }
    resetCreateOrderForm();
}

/**
 * Opens the modal to change an order's status.
 * @param {number} orderId The ID of the order to update.
 * @param {string} currentStatus The current status of the order.
 */
function openStatusModal(orderId, currentStatus) {
    document.getElementById('statusOrderId').value = orderId;
    document.getElementById('updateStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('show');
}

/**
 * Closes the "Update Status" modal.
 */
function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('show');
}

/**
 * Opens the confirmation modal for canceling an order.
 * @param {number} orderId The ID of the order to cancel.
 * @param {string} orderNumber The human-readable order number for the confirmation message.
 */
function openCancelModal(orderId, orderNumber) {
    document.getElementById('cancelOrderId').value = orderId;
    document.getElementById('cancelOrderNumber').textContent = orderNumber;
    document.getElementById('cancelModal').classList.add('show');
}

/**
 * Closes the "Cancel Order" confirmation modal.
 */
function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('show');
}

/**
 * Opens the details modal and fetches the order data from the API.
 * @param {number} orderId The ID of the order to display.
 */
function viewOrderDetails(orderId) {
    currentOrderId = orderId;
    currentOrderDetails = null;
    // Create modal if it doesn't exist
    let modal = document.getElementById('orderDetailsModal');
    if (!modal) {
        modal = createOrderDetailsModal();
        document.body.appendChild(modal);
    }
    
    // Show loading state
    const content = document.getElementById('orderDetailsContent');
    content.innerHTML = '<div class="loading" style="text-align: center; padding: 2rem; color: #666;">Se încarcă detaliile comenzii...</div>';
    modal.style.display = 'block';
    
    // Fetch order details via the warehouse API
    fetch(`api/warehouse/order_details.php?id=${orderId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server returned non-JSON response');
            }
            
            return response.json();
        })
        .then(response => {
            // Debug log
            console.log('API Response:', response);
            
            if (response.status !== 'success') {
                throw new Error(response.message || 'API returned error status');
            }
            
            // Extract the actual order data from the nested structure
            const order = response.data;
            displayOrderDetails(order);
        })
        .catch(error => {
            console.error('Error loading order details:', error);

            content.innerHTML = `
                <div class="error-message" style="text-align: center; padding: 2rem;">
                    <h3 style="color: #dc3545; margin-bottom: 1rem;">Eroare la încărcarea detaliilor comenzii</h3>
                    <p style="color: #666; margin-bottom: 1.5rem;">${error.message}</p>
                    <button onclick="closeOrderDetailsModal()" class="btn btn-secondary" style="padding: 0.5rem 1rem; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Închide</button>
                </div>
            `;
        });
}


function createOrderDetailsModal() {
    const modal = document.createElement('div');
    modal.id = 'orderDetailsModal';
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Detalii Comandă</h2>
                <span class="close" onclick="closeOrderDetailsModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="orderDetailsContent"></div>
            </div>
        </div>
    `;
    return modal;
}

function displayOrderDetails(order) {
    console.log('Order data for display:', order);

    currentOrderDetails = order || null;
    if (order && order.id) {
        currentOrderId = order.id;
    }

    const safeOrder = order || {};
    const items = Array.isArray(safeOrder.items) ? safeOrder.items : [];
    const countyIdValue = safeOrder.recipient_county_id && Number(safeOrder.recipient_county_id) > 0
        ? String(safeOrder.recipient_county_id)
        : '';
    const localityIdValue = safeOrder.recipient_locality_id && Number(safeOrder.recipient_locality_id) > 0
        ? String(safeOrder.recipient_locality_id)
        : '';
    const countyIdDisplay = countyIdValue !== '' ? escapeHtml(countyIdValue) : '—';
    const localityIdDisplay = localityIdValue !== '' ? escapeHtml(localityIdValue) : '—';

    let formattedDate = safeOrder.order_date || 'N/A';
    if (safeOrder.order_date && safeOrder.order_date !== 'N/A') {
        try {
            const date = new Date(safeOrder.order_date);
            formattedDate = `${date.toLocaleDateString('ro-RO')} ${date.toLocaleTimeString('ro-RO', { hour: '2-digit', minute: '2-digit' })}`;
        } catch (error) {
            formattedDate = safeOrder.order_date;
        }
    }

    const trackingLink = safeOrder.tracking_number
        ? `https://www.cargus.ro/personal/urmareste-coletul/?tracking_number=${encodeURIComponent(safeOrder.tracking_number)}&Urm%C4%83re%C8%99te=Urm%C4%83re%C8%99te`
        : null;

    const itemsSectionHtml = renderOrderItemsSection(safeOrder, items);
    const progressSectionHtml = safeOrder.progress ? `
        <div class="progress-section" style="margin-top: 2rem;">
            <h4>Progres comandă</h4>
            <p><strong>Total articole:</strong> <span id="progress-total-items">${safeOrder.progress.total_items || 0}</span></p>
            <p><strong>Cantitate comandată:</strong> <span id="progress-total-ordered">${safeOrder.progress.total_quantity_ordered || 0}</span></p>
            <p><strong>Cantitate ridicată:</strong> <span id="progress-total-picked">${safeOrder.progress.total_quantity_picked || 0}</span></p>
            <p><strong>Rămas de ridicat:</strong> <span id="progress-total-remaining">${safeOrder.progress.total_remaining || 0}</span></p>
            <p><strong>Progres:</strong> <span id="progress-percent">${safeOrder.progress.progress_percent || 0}</span>%</p>
        </div>
    ` : '';

    const notesSectionHtml = safeOrder.notes ? `
        <div class="notes-section" style="margin-top: 2rem;">
            <h4>Observații</h4>
            <p>${safeOrder.notes}</p>
        </div>
    ` : '';

    const content = `
        <div class="order-details">
            <div class="details-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem;">
                <div class="detail-section">
                    <h4>Informații Comandă</h4>
                    <p><strong>Număr:</strong> ${escapeHtml(safeOrder.order_number || 'N/A')}</p>
                    <p><strong>Data:</strong> ${escapeHtml(formattedDate)}</p>
                    <p><strong>Status:</strong> ${escapeHtml(safeOrder.status_label || safeOrder.status || 'N/A')}</p>
                    <p><strong>Valoare:</strong> ${formatCurrency(safeOrder.total_value || 0)} RON</p>
                    ${trackingLink ? `<p><strong>AWB:</strong> <a href="${trackingLink}" target="_blank" rel="noopener noreferrer" class="order-awb-link">${escapeHtml(safeOrder.tracking_number)}</a></p>` : ''}
                </div>
                <div class="detail-section">
                    <h4>Informații Client</h4>
                    <p><strong>Nume:</strong> ${escapeHtml(safeOrder.customer_name || 'N/A')}</p>
                    <form id="orderContactForm" class="order-contact-form" style="margin-top: 1rem; display: grid; gap: 0.75rem;">
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label for="orderContactEmail" class="form-label">Email</label>
                            <input type="email" id="orderContactEmail" name="customer_email" class="form-control" value="${escapeHtml(safeOrder.customer_email || '')}" placeholder="client@example.com">
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label for="orderContactAddress" class="form-label">Adresă livrare</label>
                            <textarea id="orderContactAddress" name="shipping_address" class="form-control" rows="2" placeholder="Introduce adresa completă">${escapeHtml(safeOrder.shipping_address || '')}</textarea>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label for="orderContactAddressText" class="form-label">Adresă completă (text)</label>
                            <textarea id="orderContactAddressText" name="address_text" class="form-control" rows="3" placeholder="Textul complet trimis către Cargus">${escapeHtml(safeOrder.address_text || '')}</textarea>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label for="orderRecipientCountyName" class="form-label">Recipient County Name</label>
                            <div class="recipient-autocomplete" data-autocomplete-wrapper style="position: relative;">
                                <input type="text" id="orderRecipientCountyName" name="recipient_county_name" class="form-control" value="${escapeHtml(safeOrder.recipient_county_name || '')}" placeholder="Introduce județul" autocomplete="off" data-autocomplete-type="county">
                                <input type="hidden" id="orderRecipientCountyId" name="recipient_county_id" value="${escapeHtml(countyIdValue)}">
                                <div class="autocomplete-results" data-autocomplete-results="orderRecipientCountyName" style="position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1px solid #ced4da; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); z-index: 1050; display: none; max-height: 240px; overflow-y: auto; color: #212529;"></div>
                            </div>
                            <small style="color: #6c757d;">Cargus County ID: <span id="orderRecipientCountyIdDisplay">${countyIdDisplay}</span></small>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label for="orderRecipientLocalityName" class="form-label">Recipient Locality Name</label>
                            <div class="recipient-autocomplete" data-autocomplete-wrapper style="position: relative;">
                                <input type="text" id="orderRecipientLocalityName" name="recipient_locality_name" class="form-control" value="${escapeHtml(safeOrder.recipient_locality_name || '')}" placeholder="Introduce localitatea" autocomplete="off" data-autocomplete-type="locality" data-associated-county-input="orderRecipientCountyId">
                                <input type="hidden" id="orderRecipientLocalityId" name="recipient_locality_id" value="${escapeHtml(localityIdValue)}">
                                <div class="autocomplete-results" data-autocomplete-results="orderRecipientLocalityName" style="position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1px solid #ced4da; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); z-index: 1050; display: none; max-height: 240px; overflow-y: auto; color: #212529;"></div>
                            </div>
                            <small style="color: #6c757d;">Cargus Locality ID: <span id="orderRecipientLocalityIdDisplay">${localityIdDisplay}</span></small>
                        </div>
                        <div class="form-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <button type="submit" class="btn btn-primary">Salvează detalii</button>
                            <button type="button" class="btn btn-light" onclick="refreshOrderDetails(${safeOrder.id || 0}, { silent: true, showLoading: true })">Reîncarcă</button>
                        </div>
                    </form>
                </div>
            </div>

            ${progressSectionHtml}
            ${itemsSectionHtml}
            ${notesSectionHtml}
        </div>
    `;

    const container = document.getElementById('orderDetailsContent');
    if (container) {
        container.innerHTML = content;
    }

    bindOrderDetailsEvents(safeOrder);
    applyOrderItemsState(items);
    if (safeOrder.id) {
        startOrderPolling(safeOrder.id);
    }
}

function renderOrderItemsSection(order, items) {
    const productOptions = buildProductOptions();
    let rowsHtml = '';

    if (Array.isArray(items) && items.length) {
        rowsHtml = items.map(item => {
            const quantityOrdered = Number(item.quantity_ordered != null ? item.quantity_ordered : item.quantity) || 0;
            const pickedQuantity = Number(item.picked_quantity) || 0;
            const unitPrice = Number(item.unit_price) || 0;
            const totalValue = quantityOrdered * unitPrice;
            const productName = escapeHtml(item.product_name || 'Produs necunoscut');
            const sku = escapeHtml(item.sku || '-');
            const location = item.location_code
                ? `<div class="order-item-location" style="color: #6c757d; font-size: 0.85rem; margin-top: 0.25rem;">Locație: ${escapeHtml(item.location_code)}</div>`
                : '';

            return `
                <tr class="order-item-row${item.is_complete ? ' item-complete' : ''}" data-order-item-id="${item.order_item_id}" data-quantity-ordered="${quantityOrdered}">
                    <td style="padding: 8px; border: 1px solid #ddd;">${productName}${location}</td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${sku}</td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${quantityOrdered}</td>
                    <td class="picked-quantity-cell" style="padding: 8px; border: 1px solid #ddd;">
                        <span class="picked-quantity-value">${pickedQuantity}</span>
                    </td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${formatCurrency(unitPrice)} RON</td>
                    <td style="padding: 8px; border: 1px solid #ddd;">${formatCurrency(totalValue)} RON</td>
                    <td style="padding: 8px; border: 1px solid #ddd; width: 140px;">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-action="edit-item" data-item-id="${item.order_item_id}" title="Modifică produs">
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete-item" data-item-id="${item.order_item_id}" title="Șterge produs">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } else {
        rowsHtml = `
            <tr class="order-items-empty">
                <td colspan="7" style="text-align: center; padding: 1rem; color: #666;">Nu există produse în această comandă.</td>
            </tr>
        `;
    }

    return `
        <div class="items-section" style="margin-top: 2rem;">
            <div class="items-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <h4 style="margin: 0;">Produse comandate</h4>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="resetOrderItemForm()" title="Adaugă produs nou">
                    <span class="material-symbols-outlined">add</span> Produs nou
                </button>
            </div>
            <div class="table-responsive" style="margin-top: 1rem; overflow-x: auto;">
                <table class="order-items-table" style="width: 100%; border-collapse: collapse; min-width: 760px;">
                    <thead>
                        <tr style="background-color: #f5f5f5;">
                            <th style="padding: 8px; border: 1px solid #ddd;">Produs</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">SKU</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Cantitate</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Cantitate ridicată</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Preț unitar</th>
                            <th style="padding: 8px; border: 1px solid #ddd;">Total</th>
                            <th style="padding: 8px; border: 1px solid #ddd; width: 140px;">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>
            </div>
            <div class="order-item-form-wrapper" style="margin-top: 1.5rem; border: 1px solid #eee; border-radius: 8px; padding: 1rem;">
                <h5 id="orderItemFormTitle" data-default-title="Adaugă produs" style="margin: 0 0 1rem;">Adaugă produs</h5>
                <form id="orderItemForm" data-mode="add">
                    <input type="hidden" name="order_item_id" value="">
                    <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label class="form-label" for="orderItemProduct">Produs</label>
                            <select id="orderItemProduct" name="product_id" class="form-control" required>
                                ${productOptions}
                            </select>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label class="form-label" for="orderItemQuantity">Cantitate</label>
                            <input type="number" id="orderItemQuantity" name="quantity" class="form-control" min="1" step="1" value="1" required>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.35rem;">
                            <label class="form-label" for="orderItemPrice">Preț unitar (RON)</label>
                            <input type="number" id="orderItemPrice" name="unit_price" class="form-control" min="0" step="0.01" placeholder="Auto">
                        </div>
                    </div>
                    <div class="form-actions" style="margin-top: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary" id="orderItemFormSubmit" data-default-label="Adaugă produs">Adaugă produs</button>
                        <button type="button" class="btn btn-secondary" id="orderItemFormReset" onclick="resetOrderItemForm()">Resetează</button>
                    </div>
                </form>
            </div>
        </div>
    `;
}

function buildProductOptions(selectedId) {
    const products = Array.isArray(window.orderProductsList) ? window.orderProductsList : [];
    const selectedValue = Number(selectedId);
    const options = ['<option value="">Selectează produs</option>'];

    products.forEach(product => {
        const productId = Number(product.product_id);
        if (!productId) {
            return;
        }

        const isSelected = productId === selectedValue ? ' selected' : '';
        const numericPrice = Number(product.price);
        const priceAttr = Number.isFinite(numericPrice) ? ` data-price="${numericPrice}"` : '';
        const unit = product.unit_of_measure ? ` data-unit="${escapeHtml(product.unit_of_measure)}"` : '';
        const sku = product.sku ? ` (${product.sku})` : '';
        const label = `${product.name || `Produs #${productId}`}${sku}`;

        options.push(`<option value="${productId}"${isSelected}${priceAttr}${unit}>${escapeHtml(label)}</option>`);
    });

    return options.join('');
}

function bindOrderDetailsEvents(order) {
    const contactForm = document.getElementById('orderContactForm');
    if (contactForm) {
        contactForm.addEventListener('submit', submitOrderContactForm);
    }

    initRecipientAutocomplete(order || {});

    const orderItemForm = document.getElementById('orderItemForm');
    if (orderItemForm) {
        orderItemForm.addEventListener('submit', submitOrderItemForm);

        const productSelect = orderItemForm.querySelector('select[name="product_id"]');
        const priceInput = orderItemForm.querySelector('input[name="unit_price"]');
        if (productSelect && priceInput) {
            productSelect.addEventListener('change', () => {
                const isEditMode = (orderItemForm.dataset.mode || 'add') === 'edit';
                updateOrderItemPriceFromProduct(productSelect, priceInput, isEditMode);
            });
        }

        if (priceInput) {
            priceInput.addEventListener('input', () => {
                priceInput.dataset.userEdited = '1';
            });
        }
    }

    const itemsTable = document.querySelector('#orderDetailsContent table.order-items-table');
    if (itemsTable) {
        itemsTable.addEventListener('click', event => {
            const editButton = event.target.closest('[data-action="edit-item"]');
            if (editButton) {
                event.preventDefault();
                const itemId = parseInt(editButton.getAttribute('data-item-id'), 10);
                prefillOrderItemForm(itemId);
                return;
            }

            const deleteButton = event.target.closest('[data-action="delete-item"]');
            if (deleteButton) {
                event.preventDefault();
                const itemId = parseInt(deleteButton.getAttribute('data-item-id'), 10);
                deleteOrderItem(deleteButton, itemId);
            }
        });
    }

    resetOrderItemForm(true);
}

function initRecipientAutocomplete(order, config) {
    recipientAutocompleteStates.length = 0;
    recipientAutocompleteRegistry.county = null;
    recipientAutocompleteRegistry.locality = null;

    const settings = Object.assign({
        countyInputId: 'orderRecipientCountyName',
        countyIdInputId: 'orderRecipientCountyId',
        countyDisplayId: 'orderRecipientCountyIdDisplay',
        localityInputId: 'orderRecipientLocalityName',
        localityIdInputId: 'orderRecipientLocalityId',
        localityDisplayId: 'orderRecipientLocalityIdDisplay'
    }, config || {});

    const countyState = createRecipientAutocompleteState({
        type: 'county',
        inputId: settings.countyInputId,
        idInputId: settings.countyIdInputId,
        idDisplayId: settings.countyDisplayId
    });

    if (countyState) {
        recipientAutocompleteRegistry.county = countyState;
        recipientAutocompleteStates.push(countyState);
    }

    const localityState = createRecipientAutocompleteState({
        type: 'locality',
        inputId: settings.localityInputId,
        idInputId: settings.localityIdInputId,
        idDisplayId: settings.localityDisplayId
    });

    if (localityState) {
        recipientAutocompleteRegistry.locality = localityState;
        recipientAutocompleteStates.push(localityState);

        const initialCountyId = order && order.recipient_county_id ? String(order.recipient_county_id) : '';
        localityState.input.dataset.selectedCountyId = initialCountyId;
        localityState.idInput.dataset.countyId = initialCountyId;
        localityState.idInput.dataset.previousCountyId = initialCountyId;

        if (order && order.recipient_locality_id) {
            localityState.lastSelectedLabel = localityState.input.value.trim();
        }
    }

    if (countyState && order && order.recipient_county_id) {
        countyState.lastSelectedLabel = countyState.input.value.trim();
    }

    if (!recipientAutocompleteDocumentListenerAttached) {
        document.addEventListener('click', event => {
            recipientAutocompleteStates.forEach(state => {
                if (!state.wrapper || state.wrapper.contains(event.target)) {
                    return;
                }
                hideRecipientSuggestions(state);
            });
        });
        recipientAutocompleteDocumentListenerAttached = true;
    }
}

function createRecipientAutocompleteState(config) {
    const input = document.getElementById(config.inputId);
    const idInput = document.getElementById(config.idInputId);
    const idDisplay = document.getElementById(config.idDisplayId);
    const suggestionsEl = document.querySelector(`.autocomplete-results[data-autocomplete-results="${config.inputId}"]`);

    if (!input || !idInput || !idDisplay || !suggestionsEl) {
        return null;
    }

    const wrapper = input.closest('[data-autocomplete-wrapper]') || input.parentElement;
    const alreadyInitialized = input.dataset.autocompleteInitialized === '1';

    const state = {
        type: config.type,
        input,
        idInput,
        idDisplay,
        suggestionsEl,
        wrapper,
        timer: null,
        controller: null,
        lastSelectedLabel: input.value.trim(),
        isApplyingSelection: false,
        pendingQuery: null
    };

    if (!alreadyInitialized) {
        input.addEventListener('input', () => handleRecipientAutocompleteInput(state));
        input.addEventListener('focus', () => {
            if (state.input.value.trim().length >= RECIPIENT_AUTOCOMPLETE_MIN_CHARS) {
                handleRecipientAutocompleteInput(state, { immediate: true });
            }
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                hideRecipientSuggestions(state);
            }
        });

        suggestionsEl.addEventListener('mousedown', event => {
            event.preventDefault();
        });

        suggestionsEl.addEventListener('click', event => {
            const option = event.target.closest('.autocomplete-option');
            if (!option) {
                return;
            }
            applyRecipientSelection(state, option.dataset);
        });

        input.dataset.autocompleteInitialized = '1';
    }

    return state;
}

function handleRecipientAutocompleteInput(state, options) {
    if (!state || !state.input) {
        return;
    }

    const immediate = Boolean(options && options.immediate);
    const value = state.input.value.trim();

    if (!state.isApplyingSelection && value !== state.lastSelectedLabel) {
        setRecipientFieldId(state, '');
        state.lastSelectedLabel = '';

        if (state.type === 'county') {
            handleCountyChanged('', '', { skipClear: false });
        } else if (state.type === 'locality') {
            state.idInput.dataset.countyId = state.idInput.dataset.countyId || '';
        }
    }

    if (state.timer) {
        clearTimeout(state.timer);
        state.timer = null;
    }

    if (!immediate && value.length < RECIPIENT_AUTOCOMPLETE_MIN_CHARS) {
        hideRecipientSuggestions(state);
        state.pendingQuery = null;
        return;
    }

    if (immediate && value.length < RECIPIENT_AUTOCOMPLETE_MIN_CHARS) {
        hideRecipientSuggestions(state);
        state.pendingQuery = null;
        return;
    }

    const fetchSuggestions = () => {
        state.pendingQuery = value;
        fetchRecipientSuggestions(state, value);
    };

    if (immediate) {
        fetchSuggestions();
    } else {
        state.timer = setTimeout(fetchSuggestions, RECIPIENT_AUTOCOMPLETE_DELAY_MS);
    }
}

function fetchRecipientSuggestions(state, query) {
    if (!state || !query) {
        return;
    }

    if (state.controller) {
        state.controller.abort();
    }

    const params = new URLSearchParams();
    params.set('type', state.type);
    params.set('query', query);
    params.set('limit', state.type === 'locality' ? '40' : '25');

    if (state.type === 'locality') {
        const associated = state.input.dataset.selectedCountyId || state.idInput.dataset.countyId || '';
        let countyId = associated;
        if (!countyId && recipientAutocompleteRegistry.county && recipientAutocompleteRegistry.county.idInput.value) {
            countyId = recipientAutocompleteRegistry.county.idInput.value;
        }
        if (countyId) {
            params.set('county_id', countyId);
        }
    }

    state.controller = new AbortController();

    fetch(`api/warehouse/search_location_mappings.php?${params.toString()}`, {
        signal: state.controller.signal,
        credentials: 'same-origin'
    })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (!data || data.status !== 'success' || !Array.isArray(data.data)) {
                throw new Error('Răspuns invalid de la server.');
            }

            if (state.pendingQuery !== query) {
                return;
            }

            renderRecipientSuggestions(state, data.data);
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Autocomplete recipient search failed:', error);
        })
        .finally(() => {
            state.controller = null;
        });
}

function renderRecipientSuggestions(state, results) {
    if (!state || !state.suggestionsEl) {
        return;
    }

    if (!Array.isArray(results) || !results.length) {
        hideRecipientSuggestions(state);
        return;
    }

    const optionsHtml = results
        .map(result => {
            if (state.type === 'county') {
                return buildCountySuggestionOption(result);
            }
            return buildLocalitySuggestionOption(result);
        })
        .filter(Boolean)
        .join('');

    if (!optionsHtml) {
        hideRecipientSuggestions(state);
        return;
    }

    state.suggestionsEl.innerHTML = optionsHtml;
    state.suggestionsEl.style.backgroundColor = '#fff';
    state.suggestionsEl.style.color = '#212529';
    state.suggestionsEl.style.display = 'block';
    const options = state.suggestionsEl.querySelectorAll('.autocomplete-option');
    if (options.length) {
        options[options.length - 1].style.borderBottom = 'none';
        options.forEach(option => {
            option.addEventListener('mouseenter', () => {
                option.style.backgroundColor = '#f8f9fa';
            });
            option.addEventListener('mouseleave', () => {
                option.style.backgroundColor = '#fff';
            });
        });
    }
}

function hideRecipientSuggestions(state) {
    if (!state || !state.suggestionsEl) {
        return;
    }
    state.suggestionsEl.innerHTML = '';
    state.suggestionsEl.style.display = 'none';
    state.pendingQuery = null;
}

function setRecipientFieldId(state, value) {
    if (!state || !state.idInput || !state.idDisplay) {
        return;
    }
    const normalized = value != null && value !== '' ? String(value) : '';
    state.idInput.value = normalized;
    state.idDisplay.textContent = normalized !== '' ? normalized : '—';
}

function applyRecipientSelection(state, dataset) {
    if (!state || !dataset) {
        return;
    }

    const label = (dataset.value || '').trim();
    state.isApplyingSelection = true;
    state.input.value = label;
    state.lastSelectedLabel = label;

    if (state.type === 'county') {
        const countyId = dataset.id || dataset.countyId || '';
        setRecipientFieldId(state, countyId);
        handleCountyChanged(countyId, dataset.countyName || label, { skipClear: false });
    } else {
        const localityId = dataset.id || dataset.localityId || '';
        const countyId = dataset.countyId || '';
        setRecipientFieldId(state, localityId);
        state.idInput.dataset.countyId = countyId;
        state.input.dataset.selectedCountyId = countyId;
        handleCountyChanged(countyId, dataset.countyName || '', { skipClear: true });
        applyCountyFromLocality(dataset);
    }

    hideRecipientSuggestions(state);
    state.isApplyingSelection = false;
}

function handleCountyChanged(newCountyId, newCountyName, options) {
    const localityState = recipientAutocompleteRegistry.locality;
    if (!localityState) {
        return;
    }

    const normalizedId = newCountyId != null && newCountyId !== '' ? String(newCountyId) : '';
    const previousCountyId = localityState.idInput.dataset.previousCountyId || '';
    localityState.input.dataset.selectedCountyId = normalizedId;
    localityState.idInput.dataset.countyId = normalizedId;

    if (options && options.skipClear) {
        localityState.idInput.dataset.previousCountyId = normalizedId;
        return;
    }

    if (normalizedId === '') {
        if (localityState.input.value || localityState.idInput.value) {
            clearRecipientLocalitySelection(localityState);
        }
        localityState.idInput.dataset.previousCountyId = '';
        return;
    }

    if (previousCountyId && previousCountyId !== normalizedId) {
        clearRecipientLocalitySelection(localityState);
    }

    localityState.idInput.dataset.previousCountyId = normalizedId;
    if (newCountyName && recipientAutocompleteRegistry.county && recipientAutocompleteRegistry.county.input) {
        recipientAutocompleteRegistry.county.input.dataset.lastKnownCountyName = newCountyName;
    }
}

function clearRecipientLocalitySelection(state) {
    if (!state) {
        return;
    }
    state.input.value = '';
    state.lastSelectedLabel = '';
    setRecipientFieldId(state, '');
    state.idInput.dataset.countyId = '';
    state.idInput.dataset.previousCountyId = '';
    state.input.dataset.selectedCountyId = '';
}

function applyCountyFromLocality(dataset) {
    const countyState = recipientAutocompleteRegistry.county;
    if (!countyState) {
        return;
    }

    const countyId = dataset.countyId || '';
    if (!countyId) {
        return;
    }

    const currentCountyId = countyState.idInput.value;
    if (currentCountyId === String(countyId)) {
        handleCountyChanged(countyId, dataset.countyName || countyState.input.value || '', { skipClear: true });
        return;
    }

    countyState.isApplyingSelection = true;
    const countyName = dataset.countyName || countyState.input.dataset.lastKnownCountyName || countyState.input.value || '';
    if (countyName) {
        countyState.input.value = countyName;
        countyState.lastSelectedLabel = countyName.trim();
    }
    setRecipientFieldId(countyState, countyId);
    hideRecipientSuggestions(countyState);
    countyState.isApplyingSelection = false;
    handleCountyChanged(countyId, countyName, { skipClear: true });
}

function buildCountySuggestionOption(result) {
    if (!result) {
        return '';
    }
    const countyId = Number(result.cargus_county_id);
    const displayName = (result.cargus_county_name || result.county_name || '').trim();
    if (!countyId || !displayName) {
        return '';
    }

    const secondaryName = result.county_name && result.county_name !== displayName
        ? `<div style="font-size: 0.78rem; color: #6c757d;">Mapare: ${escapeHtml(result.county_name)}</div>`
        : '';

    return `
        <div class="autocomplete-option" role="button" tabindex="-1"
            data-type="county"
            data-id="${escapeHtml(String(countyId))}"
            data-value="${escapeHtml(displayName)}"
            data-county-id="${escapeHtml(String(countyId))}"
            data-county-name="${escapeHtml(displayName)}"
            style="padding: 0.45rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f1f3f5; background-color: #fff; color: #212529; transition: background-color 0.15s ease;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                <span>${escapeHtml(displayName)}</span>
                <span style="color: #0d6efd; font-weight: 600;">${escapeHtml(String(countyId))}</span>
            </div>
            ${secondaryName}
        </div>
    `;
}

function buildLocalitySuggestionOption(result) {
    if (!result) {
        return '';
    }
    const localityId = Number(result.cargus_locality_id);
    const localityName = (result.cargus_locality_name || result.locality_name || '').trim();
    if (!localityId || !localityName) {
        return '';
    }

    const countyId = result.cargus_county_id ? String(result.cargus_county_id) : '';
    const countyName = (result.cargus_county_name || result.county_name || '').trim();

    const countyInfo = countyName
        ? `<div style="font-size: 0.78rem; color: #6c757d;">Județ: ${escapeHtml(countyName)}</div>`
        : '';

    return `
        <div class="autocomplete-option" role="button" tabindex="-1"
            data-type="locality"
            data-id="${escapeHtml(String(localityId))}"
            data-value="${escapeHtml(localityName)}"
            data-county-id="${escapeHtml(countyId)}"
            data-county-name="${escapeHtml(countyName)}"
            style="padding: 0.45rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f1f3f5; background-color: #fff; color: #212529; transition: background-color 0.15s ease;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                <span>${escapeHtml(localityName)}</span>
                <span style="color: #0d6efd; font-weight: 600;">${escapeHtml(String(localityId))}</span>
            </div>
            ${countyInfo}
        </div>
    `;
}

function refreshOrderDetails(orderId, options) {
    const opts = options || {};
    const resolvedId = Number(orderId || currentOrderId || 0);

    if (!resolvedId) {
        return Promise.resolve();
    }

    if (opts.showLoading) {
        const container = document.getElementById('orderDetailsContent');
        if (container) {
            container.innerHTML = '<div class="loading" style="text-align: center; padding: 2rem; color: #666;">Se reîncarcă detaliile comenzii...</div>';
        }
    }

    return fetch(`api/warehouse/order_details.php?id=${resolvedId}`)
        .then(parseJsonResponse)
        .then(data => {
            if (data && data.data) {
                displayOrderDetails(data.data);
                if (opts.toast && opts.toast.message) {
                    showOrdersToast(opts.toast.type || 'success', opts.toast.message);
                }
            }
            return data;
        })
        .catch(error => {
            console.error('refreshOrderDetails error:', error);
            if (!opts.silent) {
                showOrdersToast('warning', error.message || 'Nu s-au putut reîncărca detaliile comenzii.');
            }
            throw error;
        });
}

function submitOrderContactForm(event) {
    event.preventDefault();

    if (!currentOrderDetails || !currentOrderDetails.id) {
        return;
    }

    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
    }

    const parseIdField = value => {
        const trimmed = String(value ?? '').trim();
        if (!trimmed) {
            return null;
        }
        const numeric = Number(trimmed);
        return Number.isFinite(numeric) && numeric > 0 ? numeric : null;
    };

    const payload = {
        order_id: currentOrderDetails.id,
        customer_email: form.customer_email ? form.customer_email.value.trim() : '',
        shipping_address: form.shipping_address ? form.shipping_address.value.trim() : '',
        address_text: form.address_text ? form.address_text.value.trim() : '',
        recipient_county_name: form.recipient_county_name ? form.recipient_county_name.value.trim() : '',
        recipient_county_id: parseIdField(form.recipient_county_id ? form.recipient_county_id.value : null),
        recipient_locality_name: form.recipient_locality_name ? form.recipient_locality_name.value.trim() : '',
        recipient_locality_id: parseIdField(form.recipient_locality_id ? form.recipient_locality_id.value : null)
    };

    fetch('api/warehouse/update_order_details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    })
        .then(parseJsonResponse)
        .then(response => {
            const message = response.message || 'Detaliile comenzii au fost actualizate.';
            return refreshOrderDetails(currentOrderDetails.id, {
                toast: {
                    type: 'success',
                    message
                }
            });
        })
        .catch(error => {
            console.error('submitOrderContactForm error:', error);
            showOrdersToast('warning', error.message || 'Actualizarea detaliilor a eșuat.');
        })
        .finally(() => {
            if (submitButton) {
                submitButton.disabled = false;
            }
        });
}

function submitOrderItemForm(event) {
    event.preventDefault();

    if (!currentOrderDetails || !currentOrderDetails.id) {
        return;
    }

    const form = event.target;
    const mode = (form.dataset.mode || 'add') === 'edit' ? 'update' : 'create';
    const productSelect = form.querySelector('select[name="product_id"]');
    const quantityInput = form.querySelector('input[name="quantity"]');
    const priceInput = form.querySelector('input[name="unit_price"]');
    const submitButton = form.querySelector('button[type="submit"]');

    const productId = productSelect ? parseInt(productSelect.value, 10) : 0;
    const quantity = quantityInput ? parseInt(quantityInput.value, 10) : 0;
    const unitPriceRaw = priceInput ? priceInput.value.trim() : '';

    if (!productId) {
        showOrdersToast('warning', 'Selectează un produs înainte de a salva.');
        return;
    }

    if (!quantity || quantity <= 0) {
        showOrdersToast('warning', 'Cantitatea trebuie să fie mai mare decât zero.');
        return;
    }

    if (submitButton) {
        submitButton.disabled = true;
    }

    const payload = {
        action: mode,
        order_id: currentOrderDetails.id,
        product_id: productId,
        quantity: quantity
    };

    if (mode === 'update') {
        const itemId = form.dataset.itemId ? parseInt(form.dataset.itemId, 10) : 0;
        if (itemId) {
            payload.order_item_id = itemId;
        }
    }

    if (unitPriceRaw !== '') {
        payload.unit_price = unitPriceRaw;
    }

    fetch('api/warehouse/manage_order_item.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload)
    })
        .then(parseJsonResponse)
        .then(response => {
            const message = response.message || 'Comanda a fost actualizată.';
            resetOrderItemForm(true);
            return refreshOrderDetails(currentOrderDetails.id, {
                toast: {
                    type: 'success',
                    message
                }
            });
        })
        .catch(error => {
            console.error('submitOrderItemForm error:', error);
            showOrdersToast('warning', error.message || 'Actualizarea produsului a eșuat.');
        })
        .finally(() => {
            if (submitButton) {
                submitButton.disabled = false;
            }
        });
}

function resetOrderItemForm(skipFocus) {
    const form = document.getElementById('orderItemForm');
    if (!form) {
        return;
    }

    form.dataset.mode = 'add';
    form.dataset.itemId = '';

    const hiddenInput = form.querySelector('input[name="order_item_id"]');
    if (hiddenInput) {
        hiddenInput.value = '';
    }

    const productSelect = form.querySelector('select[name="product_id"]');
    if (productSelect) {
        productSelect.value = '';
    }

    const quantityInput = form.querySelector('input[name="quantity"]');
    if (quantityInput) {
        quantityInput.value = 1;
    }

    const priceInput = form.querySelector('input[name="unit_price"]');
    if (priceInput) {
        priceInput.value = '';
        priceInput.dataset.userEdited = '0';
    }

    const title = document.getElementById('orderItemFormTitle');
    if (title) {
        title.textContent = title.dataset.defaultTitle || 'Adaugă produs';
    }

    const submitButton = document.getElementById('orderItemFormSubmit');
    if (submitButton) {
        submitButton.textContent = submitButton.dataset.defaultLabel || 'Adaugă produs';
    }

    const resetButton = document.getElementById('orderItemFormReset');
    if (resetButton) {
        resetButton.textContent = 'Resetează';
    }

    if (!skipFocus && productSelect) {
        productSelect.focus();
    }
}

function prefillOrderItemForm(itemId) {
    const form = document.getElementById('orderItemForm');
    if (!form) {
        return;
    }

    const item = getOrderItemById(itemId);
    if (!item) {
        showOrdersToast('warning', 'Produsul selectat nu a fost găsit în această comandă.');
        return;
    }

    form.dataset.mode = 'edit';
    form.dataset.itemId = String(itemId);

    const hiddenInput = form.querySelector('input[name="order_item_id"]');
    if (hiddenInput) {
        hiddenInput.value = itemId;
    }

    const productSelect = form.querySelector('select[name="product_id"]');
    if (productSelect) {
        productSelect.value = item.product_id;
    }

    const quantityInput = form.querySelector('input[name="quantity"]');
    if (quantityInput) {
        const quantityValue = Number(item.quantity_ordered != null ? item.quantity_ordered : item.quantity) || 1;
        quantityInput.value = quantityValue;
    }

    const priceInput = form.querySelector('input[name="unit_price"]');
    if (priceInput) {
        const priceValue = Number(item.unit_price);
        if (!Number.isNaN(priceValue)) {
            priceInput.value = priceValue.toFixed(2);
        } else {
            priceInput.value = '';
        }
        priceInput.dataset.userEdited = '0';
    }

    const title = document.getElementById('orderItemFormTitle');
    if (title) {
        title.textContent = 'Modifică produs';
    }

    const submitButton = document.getElementById('orderItemFormSubmit');
    if (submitButton) {
        submitButton.textContent = 'Actualizează produs';
    }

    const resetButton = document.getElementById('orderItemFormReset');
    if (resetButton) {
        resetButton.textContent = 'Renunță';
    }

    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function deleteOrderItem(triggerElement, itemId) {
    const orderId = currentOrderDetails && currentOrderDetails.id ? currentOrderDetails.id : 0;
    if (!orderId || !itemId) {
        return;
    }

    if (!window.confirm('Ești sigur că vrei să elimini acest produs din comandă?')) {
        return;
    }

    if (triggerElement) {
        triggerElement.disabled = true;
    }

    fetch('api/warehouse/manage_order_item.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
            action: 'delete',
            order_id: orderId,
            order_item_id: itemId
        })
    })
        .then(parseJsonResponse)
        .then(response => {
            const message = response.message || 'Produsul a fost eliminat din comandă.';
            return refreshOrderDetails(orderId, {
                toast: {
                    type: 'success',
                    message
                }
            });
        })
        .catch(error => {
            console.error('deleteOrderItem error:', error);
            showOrdersToast('warning', error.message || 'Ștergerea produsului a eșuat.');
        })
        .finally(() => {
            if (triggerElement) {
                triggerElement.disabled = false;
            }
        });
}

function updateOrderItemPriceFromProduct(selectEl, priceInput, isEditMode) {
    if (!selectEl || !priceInput) {
        return;
    }

    const selectedOption = selectEl.options[selectEl.selectedIndex];
    if (!selectedOption) {
        if (!isEditMode) {
            priceInput.value = '';
            priceInput.dataset.userEdited = '0';
        }
        return;
    }

    const priceAttr = selectedOption.getAttribute('data-price');
    if (priceAttr !== null && priceAttr !== '' && priceInput.dataset.userEdited !== '1') {
        const numericPrice = Number(priceAttr);
        if (!Number.isNaN(numericPrice)) {
            priceInput.value = numericPrice.toFixed(2);
            priceInput.dataset.userEdited = '0';
        }
    } else if (!isEditMode && priceInput.dataset.userEdited !== '1') {
        priceInput.value = '';
    }
}

function getOrderItemById(itemId) {
    if (!currentOrderDetails || !Array.isArray(currentOrderDetails.items)) {
        return null;
    }

    const numericId = Number(itemId);
    return currentOrderDetails.items.find(item => Number(item.order_item_id) === numericId) || null;
}

function parseJsonResponse(response) {
    return response.json().catch(() => ({})).then(data => {
        const status = data && typeof data.status === 'string' ? data.status : (response.ok ? 'success' : 'error');
        if (!response.ok || status !== 'success') {
            const message = data && data.message ? data.message : `HTTP ${response.status}`;
            throw new Error(message);
        }
        return data;
    });
}

function closeOrderDetailsModal() {
    const modal = document.getElementById('orderDetailsModal');
    if (modal) {
        modal.style.display = 'none';
    }
    currentOrderDetails = null;
    currentOrderId = null;
    stopOrderPolling();
}

function applyOrderItemsState(items) {
    if (!Array.isArray(items)) {
        return;
    }

    items.forEach(item => {
        const row = document.querySelector(`#orderDetailsContent tr[data-order-item-id="${item.order_item_id}"]`);
        if (!row) {
            return;
        }

        const pickedSpan = row.querySelector('.picked-quantity-value');
        const pickedQty = Number(item.picked_quantity) || 0;
        const orderedQty = Number(item.quantity_ordered) || 0;

        if (pickedSpan) {
            pickedSpan.textContent = pickedQty;
        }

        row.dataset.quantityOrdered = orderedQty;
        toggleCompletionState(row, pickedQty, orderedQty);
    });

    updateProgressSummary(items);
}

function toggleCompletionState(row, picked, ordered) {
    if (!row) {
        return;
    }
    if (Number(picked) >= Number(ordered) && Number(ordered) > 0) {
        row.classList.add('item-complete');
    } else {
        row.classList.remove('item-complete');
    }
}

function updateProgressSummary(items) {
    if (!Array.isArray(items) || !items.length) {
        return;
    }

    const totals = items.reduce((acc, item) => {
        acc.items += 1;
        acc.ordered += Number(item.quantity_ordered) || 0;
        acc.picked += Number(item.picked_quantity) || 0;
        return acc;
    }, { items: 0, ordered: 0, picked: 0 });

    const remaining = Math.max(totals.ordered - totals.picked, 0);
    const percent = totals.ordered > 0 ? ((totals.picked / totals.ordered) * 100) : 0;

    const totalItemsEl = document.getElementById('progress-total-items');
    const totalOrderedEl = document.getElementById('progress-total-ordered');
    const totalPickedEl = document.getElementById('progress-total-picked');
    const totalRemainingEl = document.getElementById('progress-total-remaining');
    const progressPercentEl = document.getElementById('progress-percent');

    if (totalItemsEl) totalItemsEl.textContent = totals.items;
    if (totalOrderedEl) totalOrderedEl.textContent = totals.ordered;
    if (totalPickedEl) totalPickedEl.textContent = totals.picked;
    if (totalRemainingEl) totalRemainingEl.textContent = remaining;
    if (progressPercentEl) progressPercentEl.textContent = percent.toFixed(1).replace(/\.0$/, '');
}

function startOrderPolling(orderId) {
    if (!orderId) {
        return;
    }

    stopOrderPolling();
    pollingOrderId = orderId;

    const poll = () => {
        if (pollingOrderId !== orderId) {
            return;
        }

        if (pollingController) {
            pollingController.abort();
        }
        pollingController = new AbortController();

        fetch(`api/warehouse/get_picking_status.php?order_id=${orderId}`, { signal: pollingController.signal })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.status !== 'success' || !data.data || !Array.isArray(data.data.items)) {
                    throw new Error(data.message || 'Răspuns invalid de la server');
                }
                updatePickedQuantities(data.data.items);
            })
            .catch(error => {
                if (error.name === 'AbortError') {
                    return;
                }
                console.error('Polling error:', error);
            })
            .finally(() => {
                if (pollingOrderId === orderId) {
                    pollingTimer = setTimeout(poll, POLLING_INTERVAL_MS);
                }
            });
    };

    poll();
}

function stopOrderPolling() {
    if (pollingTimer) {
        clearTimeout(pollingTimer);
        pollingTimer = null;
    }
    if (pollingController) {
        pollingController.abort();
        pollingController = null;
    }
    pollingOrderId = null;
}

function updatePickedQuantities(items) {
    if (!Array.isArray(items)) {
        return;
    }

    const totals = { items: 0, ordered: 0, picked: 0 };

    items.forEach(item => {
        const row = document.querySelector(`#orderDetailsContent tr[data-order-item-id="${item.order_item_id}"]`);
        if (!row) {
            return;
        }

        totals.items += 1;
        totals.ordered += Number(item.quantity_ordered) || 0;
        totals.picked += Number(item.picked_quantity) || 0;

        const pickedSpan = row.querySelector('.picked-quantity-value');
        if (!pickedSpan) {
            return;
        }

        const currentValue = Number(pickedSpan.textContent) || 0;
        const newValue = Number(item.picked_quantity) || 0;

        if (currentValue !== newValue) {
            pickedSpan.textContent = newValue;
            pickedSpan.classList.remove('quantity-flash');
            // Force reflow to restart animation
            void pickedSpan.offsetWidth;
            pickedSpan.classList.add('quantity-flash');
            pickedSpan.addEventListener('animationend', () => {
                pickedSpan.classList.remove('quantity-flash');
            }, { once: true });
        }

        const orderedQuantity = Number(row.dataset.quantityOrdered) || Number(item.quantity_ordered) || 0;
        row.dataset.quantityOrdered = orderedQuantity;
        toggleCompletionState(row, newValue, orderedQuantity);
    });

    const remaining = Math.max(totals.ordered - totals.picked, 0);
    const percent = totals.ordered > 0 ? ((totals.picked / totals.ordered) * 100) : 0;

    const totalItemsEl = document.getElementById('progress-total-items');
    const totalOrderedEl = document.getElementById('progress-total-ordered');
    const totalPickedEl = document.getElementById('progress-total-picked');
    const totalRemainingEl = document.getElementById('progress-total-remaining');
    const progressPercentEl = document.getElementById('progress-percent');

    if (totalItemsEl) totalItemsEl.textContent = totals.items;
    if (totalOrderedEl) totalOrderedEl.textContent = totals.ordered;
    if (totalPickedEl) totalPickedEl.textContent = totals.picked;
    if (totalRemainingEl) totalRemainingEl.textContent = remaining;
    if (progressPercentEl) progressPercentEl.textContent = percent.toFixed(1).replace(/\.0$/, '');
}

/**
 * Sends a request to the server to generate a Cargus AWB for the specified order.
 * @param {number} orderId The ID of the order.
 */
function generateAWB(orderId) {
    if (!confirm(`Sunteți sigur că doriți să generați un AWB pentru comanda #${orderId}?`)) {
        return;
    }

    console.log(`Generating AWB for order ${orderId}...`);

    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (!csrfToken) {
        alert('Eroare de securitate: CSRF token lipsește. Reîncărcați pagina și încercați din nou.');
        return;
    }

    // Use query parameters instead of path info
    const baseUrl = window.APP_CONFIG && window.APP_CONFIG.baseUrl ? window.APP_CONFIG.baseUrl : '';
    const awbUrl = `${baseUrl.replace(/\/$/, '')}/web/awb.php?order_id=${orderId}`;

    console.log(`Making request to: ${awbUrl}`);

    fetch(awbUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            order_id: orderId,
            csrf_token: csrfToken
        })
    })
    .then(response => {
        console.log(`Response status: ${response.status}`);
        
        if (!response.ok) {
            return response.text().then(errorText => {
                console.error('Error response:', errorText);
                try {
                    const errorData = JSON.parse(errorText);
                    throw new Error(errorData.error || `HTTP ${response.status}`);
                } catch (parseError) {
                    throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 200)}`);
                }
            });
        }
        return response.json();
    })
    .then(data => {
        console.log('Success response:', data);

        if (data.success) {
            const barcode = data.data?.awb_barcode || data.data?.barcode || data.barcode || data.awb_barcode || '';
            alert(`AWB ${barcode || 'necunoscut'} a fost generat cu succes! Pagina se va reîncărca.`);
            location.reload();
        } else {
            alert(`Eroare la generarea AWB: ${data.error || 'Răspuns nevalid de la server.'}`);
        }
    })
    .catch(error => {
        console.error('Error generating AWB:', error);
        
        let errorMessage = 'A apărut o eroare neașteptată în timpul comunicării cu serverul.';
        if (error.message.includes('Authentication required')) {
            errorMessage = 'Sesiunea a expirat. Vă rugăm să vă autentificați din nou.';
        } else if (error.message.includes('CSRF')) {
            errorMessage = 'Eroare de securitate. Reîncărcați pagina și încercați din nou.';
        } else if (error.message.includes('Order not found')) {
            errorMessage = 'Comanda nu a fost găsită.';
        } else if (error.message.includes('AWB already exists')) {
            errorMessage = 'AWB-ul a fost deja generat pentru această comandă.';
        } else if (error.message) {
            errorMessage = `Eroare: ${error.message}`;
        }
        
        alert(`Eroare la generarea AWB: ${errorMessage}`);
    });
}

/**
 * Send request to generate and print the invoice for a specific order.
 * @param {number} orderId Order ID
 */
function printInvoice(orderId, printerId = null) {
    if (!confirm(`Trimite factura la imprimantă pentru comanda #${orderId}?`)) {
        return;
    }

    // Show loading state
    const printBtn = document.querySelector(`button[onclick="printInvoice(${orderId})"]`);
    if (printBtn) {
        printBtn.disabled = true;
        printBtn.innerHTML = '<span class="material-symbols-outlined spinning">hourglass_empty</span>';
    }

    const formData = new FormData();
    formData.append('order_id', orderId);
    if (printerId) {
        formData.append('printer_id', printerId);
    }

    fetch('api/invoices/print_invoice_network.php', {
        method: 'POST',
        body: formData
    })
        .then(resp => resp.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.status === 'success') {
                    showNotification(
                        `Factura a fost trimisă la imprimantă: ${data.printer || 'imprimanta implicită'}`,
                        'success'
                    );
                    
                    // Update print job if available
                    if (data.job_id) {
                        console.log(`Print job created: ${data.job_id}`);
                    }
                } else {
                    showNotification(
                        `Eroare la imprimare: ${data.message}`,
                        'error'
                    );
                    
                    // Show detailed error for admins
                    if (data.job_id && window.userRole === 'admin') {
                        console.error('Print job failed:', data);
                    }
                }
            } catch (e) {
                console.error('Parse error:', text);
                showNotification('Eroare la procesarea răspunsului de la server', 'error');
            }
        })
        .catch(err => {
            console.error('Print request failed:', err);
            let errorMessage = 'A apărut o eroare neașteptată în timpul imprimării.';
            
            if (err.message.includes('503')) {
                errorMessage = 'Serverul de imprimare nu este disponibil. Verificați conexiunea cu imprimanta.';
            } else if (err.message.includes('404')) {
                errorMessage = 'Nu s-a găsit o imprimantă configurată pentru facturi.';
            } else if (err.message.includes('Authentication')) {
                errorMessage = 'Sesiunea a expirat. Vă rugăm să vă autentificați din nou.';
            }
            
            showNotification(`Eroare la imprimare: ${errorMessage}`, 'error');
        })
        .finally(() => {
            // Restore button state
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.innerHTML = '<span class="material-symbols-outlined">print</span>';
            }
        });
}

/**
 * Enhanced print invoice with printer selection
 * @param {number} orderId Order ID
 */
function printInvoiceWithSelection(orderId) {
    // First, get available printers
    fetch('api/printer_management.php?path=printers')
        .then(resp => resp.json())
        .then(printers => {
            const invoicePrinters = printers.filter(p => 
                p.is_active && 
                p.print_server && 
                p.print_server.is_active && 
                (p.printer_type === 'invoice' || p.printer_type === 'document')
            );
            
            if (invoicePrinters.length === 0) {
                showNotification('Nu sunt disponibile imprimante pentru facturi.', 'warning');
                return;
            }
            
            if (invoicePrinters.length === 1) {
                // Only one printer available, use it directly
                printInvoice(orderId, invoicePrinters[0].id);
                return;
            }
            
            // Multiple printers - show selection modal
            showPrinterSelectionModal(orderId, invoicePrinters);
        })
        .catch(err => {
            console.error('Failed to load printers:', err);
            // Fallback to default printer
            printInvoice(orderId);
        });
}

/**
 * Show printer selection modal
 * @param {number} orderId 
 * @param {Array} printers 
 */
function showPrinterSelectionModal(orderId, printers) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 400px;">
            <h3>Selectează imprimanta</h3>
            <p>Selectează imprimanta pentru factura comenzii #${orderId}:</p>
            
            <div style="margin: 1.5rem 0;">
                ${printers.map(printer => `
                    <div style="margin-bottom: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="radio" name="selected-printer" value="${printer.id}" 
                                   ${printer.is_default ? 'checked' : ''}>
                            <div>
                                <strong>${printer.name}</strong>
                                <br>
                                <small style="color: var(--text-muted);">
                                    ${printer.print_server.name} - ${printer.printer_type}
                                    ${printer.is_default ? ' (implicit)' : ''}
                                </small>
                            </div>
                        </label>
                    </div>
                `).join('')}
            </div>
            
            <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                <button class="btn btn-secondary" onclick="this.closest('.modal').remove()">
                    Anulează
                </button>
                <button class="btn btn-primary" onclick="confirmPrinterSelection(${orderId})">
                    Printează
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Auto-select default printer if available
    const defaultPrinter = modal.querySelector('input[type="radio"]:checked');
    if (!defaultPrinter && printers.length > 0) {
        modal.querySelector('input[type="radio"]').checked = true;
    }
}

/**
 * Confirm printer selection and print
 * @param {number} orderId 
 */
function confirmPrinterSelection(orderId) {
    const selectedPrinter = document.querySelector('input[name="selected-printer"]:checked');
    if (!selectedPrinter) {
        showNotification('Vă rugăm să selectați o imprimantă.', 'warning');
        return;
    }
    
    const printerId = parseInt(selectedPrinter.value);
    
    // Close modal
    document.querySelector('.modal').remove();
    
    // Print with selected printer
    printInvoice(orderId, printerId);
}

/**
 * Enhanced notification system
 * @param {string} message 
 * @param {string} type 
 */
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span class="material-symbols-outlined">
                ${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'info'}
            </span>
            <span>${message}</span>
        </div>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: inherit; cursor: pointer;">
            <span class="material-symbols-outlined">close</span>
        </button>
    `;
    
    // Add styles if not already present
    if (!document.querySelector('#notification-styles')) {
        const styles = document.createElement('style');
        styles.id = 'notification-styles';
        styles.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--container-background);
                border: 1px solid var(--border-color);
                border-radius: var(--border-radius);
                padding: 1rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                z-index: 10000;
                display: flex;
                justify-content: space-between;
                align-items: center;
                min-width: 300px;
                animation: slideInRight 0.3s ease;
            }
            
            .notification-success {
                border-left: 4px solid #22c55e;
                background: rgba(34, 197, 94, 0.05);
            }
            
            .notification-error {
                border-left: 4px solid #ef4444;
                background: rgba(239, 68, 68, 0.05);
            }
            
            .notification-warning {
                border-left: 4px solid #fbbf24;
                background: rgba(251, 191, 36, 0.05);
            }
            
            .notification-info {
                border-left: 4px solid #3b82f6;
                background: rgba(59, 130, 246, 0.05);
            }
            
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            .spinning {
                animation: spin 1s linear infinite;
            }
            
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(styles);
    }
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

/**
 * Check print server status and show indicators
 */
async function checkPrintServerStatus() {
    try {
        const response = await fetch('api/printer_management.php?path=print-servers');
        const servers = await response.json();
        
        const onlineServers = servers.filter(s => s.is_active).length;
        const totalServers = servers.length;
        
        // Update UI indicator if present
        const statusIndicator = document.getElementById('print-server-status');
        if (statusIndicator) {
            statusIndicator.innerHTML = `
                <span class="material-symbols-outlined">print</span>
                Servere imprimare: ${onlineServers}/${totalServers} online
            `;
            statusIndicator.className = `status-indicator ${onlineServers > 0 ? 'status-online' : 'status-offline'}`;
        }
        
        return { online: onlineServers, total: totalServers };
    } catch (error) {
        console.error('Failed to check print server status:', error);
        return { online: 0, total: 0 };
    }
}

// Initialize print server status checking
document.addEventListener('DOMContentLoaded', function() {
    // Check status initially
    checkPrintServerStatus();
    
    // Check every 30 seconds
    setInterval(checkPrintServerStatus, 30000);
});

// Enhanced print functions for different document types
if (typeof window.printAWB !== 'function') {
    window.printAWB = function(orderId, awbCode) {
        // Placeholder if specific implementation not loaded
        console.log(`Print AWB ${awbCode} for order ${orderId}`);
        showNotification('AWB printing not implemented yet', 'info');
    };
}

function printLabel(orderId, labelType = 'shipping') {
    // Implementation for label printing
    console.log(`Print ${labelType} label for order ${orderId}`);
    showNotification('Label printing not implemented yet', 'info');
}

function printPackingList(orderId) {
    // Implementation for packing list printing
    console.log(`Print packing list for order ${orderId}`);
    showNotification('Packing list printing not implemented yet', 'info');
}

/**
 * Dynamically adds a new product row to the "Create Order" form.
 */
function addOrderItem() {
    const container = document.getElementById('orderItems');
    const newItem = document.createElement('div');
    newItem.className = 'order-item';
    
    newItem.innerHTML = `
        <div class="row">
            <div class="form-group">
                <label class="form-label">Produs</label>
                <div class="manual-product-field" data-product-field style="position: relative;">
                    <input type="hidden" name="items[${itemCounter}][product_id]" class="manual-product-id">
                    <input type="text" name="items[${itemCounter}][product_search]" class="form-control manual-product-search" placeholder="Caută produs după nume sau SKU" autocomplete="off" data-product-index="${itemCounter}" required>
                    <div class="autocomplete-results manual-product-results" data-product-results style="position: absolute; top: calc(100% + 2px); left: 0; right: 0; background: #fff; border: 1px solid #ced4da; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); z-index: 1050; display: none; max-height: 240px; overflow-y: auto;"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Cantitate</label>
                <input type="number" name="items[${itemCounter}][quantity]" class="form-control item-quantity" placeholder="Cant." min="1" required>
            </div>
            <div class="form-group">
                <label class="form-label">Preț Unitar (opțional)</label>
                <input type="number" name="items[${itemCounter}][unit_price]" class="form-control item-price" placeholder="Preț (opțional)" step="0.01" min="0">
            </div>
            <div class="form-group form-group-sm">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeOrderItem(this)" title="Șterge produs">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
        </div>
    `;
    
    container.appendChild(newItem);
    itemCounter++;
}

/**
 * Removes a product row from the "Create Order" form.
 * @param {HTMLElement} button The remove button that was clicked.
 */
function removeOrderItem(button) {
    const container = document.getElementById('orderItems');
    if (container.children.length > 1) {
        button.closest('.order-item').remove();
    } else {
        alert('Comanda trebuie să conțină cel puțin un produs.');
    }
}

/**
 * Resets the "Create Order" form to its initial state with only one empty product row.
 */
function resetOrderItems() {
    const container = document.getElementById('orderItems');
    if (!container) {
        return;
    }

    const items = container.querySelectorAll('.order-item');
    for (let i = 1; i < items.length; i++) {
        items[i].remove();
    }

    const firstItem = container.querySelector('.order-item');
    if (firstItem) {
        firstItem.querySelectorAll('input').forEach(field => {
            if (['hidden', 'text', 'number'].includes(field.type)) {
                field.value = '';
            }
            if (field.classList.contains('manual-product-search')) {
                field.dataset.selectedProductId = '';
                field.dataset.currentQuery = '';
            }
        });

        const resultsEl = firstItem.querySelector('.manual-product-results');
        if (resultsEl) {
            resultsEl.innerHTML = '';
            hideManualProductResults(resultsEl);
            resultsEl.dataset.hasResults = '0';
        }
    }

    closeAllManualProductResults();
    itemCounter = 1;
}

function resetCreateOrderForm() {
    const form = document.getElementById('createOrderForm');
    if (form) {
        form.reset();
    }

    resetOrderItems();

    const countyInput = document.getElementById('createOrderRecipientCountyName');
    const countyIdInput = document.getElementById('createOrderRecipientCountyId');
    const countyDisplay = document.getElementById('createOrderRecipientCountyIdDisplay');
    if (countyInput) {
        countyInput.value = '';
    }
    if (countyIdInput) {
        countyIdInput.value = '';
    }
    if (countyDisplay) {
        countyDisplay.textContent = '—';
    }

    const localityInput = document.getElementById('createOrderRecipientLocalityName');
    const localityIdInput = document.getElementById('createOrderRecipientLocalityId');
    const localityDisplay = document.getElementById('createOrderRecipientLocalityIdDisplay');
    if (localityInput) {
        localityInput.value = '';
    }
    if (localityIdInput) {
        localityIdInput.value = '';
    }
    if (localityDisplay) {
        localityDisplay.textContent = '—';
    }

    const contactInput = document.getElementById('recipient_contact_person');
    if (contactInput) {
        delete contactInput.dataset.userEdited;
    }

    setupCreateOrderAutocomplete();
}

function setupCreateOrderAutocomplete() {
    const form = document.getElementById('createOrderForm');
    if (!form) {
        return;
    }

    initRecipientAutocomplete({}, {
        countyInputId: 'createOrderRecipientCountyName',
        countyIdInputId: 'createOrderRecipientCountyId',
        countyDisplayId: 'createOrderRecipientCountyIdDisplay',
        localityInputId: 'createOrderRecipientLocalityName',
        localityIdInputId: 'createOrderRecipientLocalityId',
        localityDisplayId: 'createOrderRecipientLocalityIdDisplay'
    });
}

/**
 * A security utility to prevent Cross-Site Scripting (XSS) attacks.
 * @param {string} str The string to sanitize.
 * @returns {string} The sanitized string.
 */
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return str.toString().replace(/[&<>"']/g, match => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[match]));
}

// Set up event listeners for page interactions.
function setupEventListeners() {
    // Listener for auto-filling the price when a product is selected from legacy dropdowns.
    document.addEventListener('change', function(e) {
        if (e.target.matches('select[name*="[product_id]"]')) {
            const selectedOption = e.target.options[e.target.selectedIndex];
            const priceInput = e.target.closest('.row').querySelector('input[name*="unit_price"]');
            if (selectedOption && selectedOption.dataset.price && priceInput) {
                priceInput.value = selectedOption.dataset.price;
            }
        }
    });

    document.addEventListener('input', function(event) {
        if (event.target.classList && event.target.classList.contains('manual-product-search')) {
            handleManualProductSearchInput(event.target);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.target.classList && event.target.classList.contains('manual-product-search')) {
            handleManualProductKeydown(event);
        }
    });

    document.addEventListener('focusin', function(event) {
        if (event.target.classList && event.target.classList.contains('manual-product-search')) {
            const resultsEl = getManualProductResultsElement(event.target);
            if (resultsEl && resultsEl.dataset.hasResults === '1') {
                resultsEl.style.display = 'block';
            }
        }
    });

    document.addEventListener('focusout', function(event) {
        if (event.target.classList && event.target.classList.contains('manual-product-search')) {
            const input = event.target;
            setTimeout(() => {
                const wrapper = input.closest('[data-product-field]');
                if (!wrapper) {
                    return;
                }
                const activeEl = document.activeElement;
                if (activeEl && wrapper.contains(activeEl)) {
                    return;
                }
                const resultsEl = getManualProductResultsElement(input);
                if (resultsEl) {
                    hideManualProductResults(resultsEl);
                }
            }, 120);
        }
    });

    document.addEventListener('mouseover', function(event) {
        const option = event.target.closest('.manual-product-option[role="button"]');
        if (option) {
            const resultsEl = option.closest('.manual-product-results');
            if (resultsEl) {
                const options = Array.from(resultsEl.querySelectorAll('.manual-product-option[role="button"]'));
                const index = options.indexOf(option);
                if (index >= 0) {
                    highlightManualProductOption(resultsEl, index);
                }
            }
        }
    });

    document.addEventListener('mousedown', function(event) {
        const option = event.target.closest('.manual-product-option[role="button"]');
        if (option) {
            selectManualProductFromOption(option);
            event.preventDefault();
            return;
        }

        if (!event.target.closest('.manual-product-field')) {
            closeAllManualProductResults();
        }
    });

    // Listener for closing modals.
    const allModals = document.querySelectorAll('.modal');
    window.addEventListener('click', function(event) {
        allModals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.remove('show');
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            allModals.forEach(modal => modal.classList.remove('show'));
        }
    });

    // Listener for validating the "Create Order" form on submission.
    const createOrderForm = document.getElementById('createOrderForm');
    if (createOrderForm) {
        createOrderForm.addEventListener('submit', function(event) {
            const customerName = document.getElementById('customer_name').value.trim();
            if (!customerName) {
                event.preventDefault();
                alert('Numele clientului este obligatoriu!');
                return;
            }

            const invoiceFileInput = document.getElementById('invoice_pdf');
            if (invoiceFileInput && invoiceFileInput.files && invoiceFileInput.files.length > 0) {
                const invoiceNumberInput = document.getElementById('invoice_number');
                const invoiceCuiInput = document.getElementById('invoice_cui');
                const invoiceNumberValue = invoiceNumberInput ? invoiceNumberInput.value.trim() : '';
                const invoiceCuiValue = invoiceCuiInput ? invoiceCuiInput.value.trim() : '';

                if (!invoiceNumberValue) {
                    event.preventDefault();
                    alert('Introdu numărul facturii pentru fișierul încărcat.');
                    if (invoiceNumberInput) {
                        invoiceNumberInput.focus();
                    }
                    return;
                }

                const normalizedInvoiceNumber = invoiceNumberValue
                    .replace(/[\\/.,;]+/g, '-')
                    .replace(/\s+/g, '-')
                    .replace(/-+/g, '-');
                if (!/^[0-9A-Za-z_-]+$/.test(normalizedInvoiceNumber)) {
                    event.preventDefault();
                    alert('Numărul facturii poate conține doar litere, cifre, minus sau underscore.');
                    if (invoiceNumberInput) {
                        invoiceNumberInput.focus();
                    }
                    return;
                }

                if (!invoiceCuiValue) {
                    event.preventDefault();
                    alert('Introdu CUI-ul companiei pentru fișierul facturii.');
                    if (invoiceCuiInput) {
                        invoiceCuiInput.focus();
                    }
                    return;
                }

                const normalizedInvoiceCui = invoiceCuiValue.replace(/^RO/i, '').replace(/[^0-9A-Za-z]/g, '');
                if (normalizedInvoiceCui.length === 0) {
                    event.preventDefault();
                    alert('CUI-ul introdus conține caractere invalide.');
                    if (invoiceCuiInput) {
                        invoiceCuiInput.focus();
                    }
                    return;
                }

                const invoiceFile = invoiceFileInput.files[0];
                if (invoiceFile) {
                    const fileName = invoiceFile.name ? invoiceFile.name.toLowerCase() : '';
                    const mimeType = (invoiceFile.type || '').toLowerCase();
                    if (mimeType && mimeType !== 'application/pdf' && !fileName.endsWith('.pdf')) {
                        event.preventDefault();
                        alert('Fișierul de factură trebuie să fie în format PDF.');
                        return;
                    }
                }
            }

            const items = document.querySelectorAll('#orderItems .order-item');
            let hasValidItems = false;
            let incompleteProductInput = null;

            items.forEach(item => {
                const productHidden = item.querySelector('input[name*="[product_id]"]');
                const productSelect = item.querySelector('select[name*="[product_id]"]');
                const quantityInput = item.querySelector('input[name*="[quantity]"]');

                const productValue = productHidden && productHidden.value
                    ? productHidden.value
                    : (productSelect ? productSelect.value : '');
                const quantityValue = quantityInput ? Number(quantityInput.value) : 0;

                if (!productValue) {
                    const searchInput = item.querySelector('.manual-product-search');
                    if (searchInput && searchInput.value.trim() !== '' && !incompleteProductInput) {
                        incompleteProductInput = searchInput;
                    }
                }

                if (productValue && Number.isFinite(quantityValue) && quantityValue > 0) {
                    hasValidItems = true;
                }
            });

            if (incompleteProductInput) {
                event.preventDefault();
                alert('Te rugăm să selectezi un produs din lista sugerată pentru fiecare rând.');
                incompleteProductInput.focus();
                return;
            }

            if (!hasValidItems) {
                event.preventDefault();
                alert('Comanda trebuie să conțină cel puțin un produs valid (produs și cantitate).');
            }
        });
    }

    const customerNameInput = document.getElementById('customer_name');
    const contactInput = document.getElementById('recipient_contact_person');
    if (contactInput) {
        contactInput.addEventListener('input', () => {
            contactInput.dataset.userEdited = '1';
        });
    }
    if (customerNameInput && contactInput) {
        customerNameInput.addEventListener('input', () => {
            if (contactInput.dataset.userEdited !== '1') {
                contactInput.value = customerNameInput.value;
            }
        });
    }
}

// Initialize all event listeners when the page is ready.
setupEventListeners();