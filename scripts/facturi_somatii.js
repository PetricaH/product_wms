(function ($) {
    'use strict';

    let selectedFile = null;
    let facturiTable = null;

    const selectors = {
        uploadArea: '#upload-area',
        fileInput: '#invoice-file',
        cameraInput: '#invoice-camera',
        selectFileBtn: '#select-file-btn',
        cameraBtn: '#camera-btn',
        previewContainer: '#preview-container',
        previewImage: '#preview-image',
        processBtn: '#process-btn',
        resetBtn: '#reset-upload-btn',
        processingIndicator: '#processing-indicator',
        resultsDisplay: '#results-display',
        stats: {
            total: '#stat-total',
            neplatite: '#stat-neplatite',
            platite: '#stat-platite',
            suma: '#stat-suma'
        },
        filters: {
            dateFrom: '#filter-date-from',
            dateTo: '#filter-date-to',
            status: '#filter-status',
            search: '#filter-search'
        },
        applyFiltersBtn: '#apply-filters-btn',
        resetFiltersBtn: '#reset-filters-btn',
        table: '#facturi_somatii',
        modal: '#invoice-modal',
        modalBody: '#modal-body',
        closeModalBtn: '#close-modal-btn'
    };

    function resolveWebhookUrl() {
        const fromConfig = (window.WMS_CONFIG && typeof window.WMS_CONFIG.n8nWebhookUrl === 'string') ? window.WMS_CONFIG.n8nWebhookUrl.trim() : '';
        if (fromConfig) {
            return fromConfig;
        }

        const fromGlobal = typeof window.FS_WEBHOOK_URL === 'string' ? window.FS_WEBHOOK_URL.trim() : '';
        if (fromGlobal) {
            return fromGlobal;
        }

        const dataSource = document.querySelector('[data-n8n-webhook-url]');
        if (dataSource) {
            const fromDataset = (dataSource.dataset && typeof dataSource.dataset.n8nWebhookUrl === 'string') ? dataSource.dataset.n8nWebhookUrl.trim() : '';
            if (fromDataset) {
                return fromDataset;
            }

            if (typeof dataSource.value === 'string' && dataSource.value.trim()) {
                return dataSource.value.trim();
            }

            const attrValue = dataSource.getAttribute('data-n8n-webhook-url');
            if (typeof attrValue === 'string' && attrValue.trim()) {
                return attrValue.trim();
            }
        }

        return '';
    }

    function ensureResultsPanel() {
        const container = document.querySelector(selectors.resultsDisplay);
        if (!container) {
            return null;
        }

        if (!document.getElementById('fs-styles')) {
            const style = document.createElement('style');
            style.id = 'fs-styles';
            style.textContent = `
                .fs-panel { margin-top: 1.5rem; padding: 1.25rem; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(22, 24, 28, 0.6); backdrop-filter: blur(6px); }
                .fs-panel h3 { margin-top: 0; margin-bottom: 0.75rem; font-size: 1.05rem; }
                .fs-summary { font-weight: 600; margin-bottom: 0.5rem; font-size: 0.95rem; }
                .fs-files { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.75rem; }
                .fs-file { display: flex; flex-direction: column; gap: 0.35rem; padding: 0.75rem; border-radius: 10px; background: rgba(255, 255, 255, 0.04); }
                .fs-file-header { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
                .fs-label { font-weight: 500; font-size: 0.95rem; word-break: break-word; }
                .fs-file-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
                .fs-file-actions a { color: #4aa3ff; text-decoration: none; font-size: 0.875rem; font-weight: 500; }
                .fs-file-actions a:hover { text-decoration: underline; }
                .fs-print { border: none; border-radius: 6px; padding: 0.35rem 0.9rem; background: #4aa3ff; color: #fff; font-size: 0.85rem; cursor: pointer; transition: background 0.2s ease; }
                .fs-print:hover { background: #3188dd; }
                .fs-meta { font-size: 0.8rem; color: rgba(255, 255, 255, 0.65); }
                .fs-warning { margin-top: 0.5rem; color: #f6c744; font-size: 0.85rem; }
            `;
            document.head.appendChild(style);
        }

        let panel = container.querySelector('#fs-results');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'fs-results';
            panel.className = 'fs-panel';
            panel.hidden = true;
            panel.innerHTML = `
                <div class="fs-summary" id="fs-summary" hidden></div>
                <h3>Fișiere generate</h3>
                <ul id="fs-files" class="fs-files"></ul>
                <p id="fs-warning" class="fs-warning" hidden>Serviciul extern nu a returnat fișiere.</p>
            `;
            container.appendChild(panel);
        }

        return panel;
    }

    function init() {
        ensureResultsPanel();
        bindUploadEvents();
        bindCameraEvents();
        bindProcessingEvents();
        initDataTable();
        fetchStats();
        bindFilterEvents();
        bindModalEvents();
    }

    function bindUploadEvents() {
        const fileInput = $(selectors.fileInput);

        $(selectors.selectFileBtn).on('click', () => fileInput.trigger('click'));

        fileInput.on('change', function () {
            if (this.files && this.files[0]) {
                handleFileUpload(this.files[0]);
                this.value = '';
            }
        });

        const uploadArea = document.querySelector(selectors.uploadArea);
        if (!uploadArea) {
            return;
        }

        ['dragenter', 'dragover'].forEach(evt => {
            uploadArea.addEventListener(evt, event => {
                event.preventDefault();
                event.stopPropagation();
                uploadArea.classList.add('drag-over');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            uploadArea.addEventListener(evt, event => {
                event.preventDefault();
                event.stopPropagation();
                uploadArea.classList.remove('drag-over');
            });
        });

        uploadArea.addEventListener('drop', event => {
            const file = event.dataTransfer?.files?.[0];
            if (file) {
                handleFileUpload(file);
            }
        });
    }

    function bindCameraEvents() {
        const cameraInput = $(selectors.cameraInput);

        $(selectors.cameraBtn).on('click', event => {
            event.preventDefault();
            if (!cameraInput.length) {
                notify('error', 'Camera nu este disponibilă în această interfață.');
                return;
            }

            cameraInput.trigger('click');
        });

        cameraInput.on('change', function () {
            if (this.files && this.files[0]) {
                handleFileUpload(this.files[0]);
                this.value = '';
            }
        });
    }

    function bindProcessingEvents() {
        $(selectors.processBtn).on('click', () => {
            if (!selectedFile) {
                notify('warning', 'Te rugăm să selectezi un fișier înainte de procesare.');
                return;
            }
            processInvoice();
        });

        $(selectors.resetBtn).on('click', resetUpload);
    }

    function handleFileUpload(file) {
        const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
        if (!allowedTypes.includes(file.type) && !file.type.startsWith('image/')) {
            notify('error', 'Tipul de fișier nu este acceptat.');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            notify('error', 'Dimensiunea maximă este de 10MB.');
            return;
        }

        selectedFile = file;

        const previewContainer = $(selectors.previewContainer);
        const previewImage = $(selectors.previewImage);

        if (file.type === 'application/pdf') {
            previewImage.attr('src', '').attr('alt', 'Previzualizare indisponibilă pentru PDF');
            previewContainer.find('h3').text('Fișier PDF selectat');
        } else {
            const reader = new FileReader();
            reader.onload = event => {
                previewImage.attr('src', event.target?.result || '').attr('alt', 'Previzualizare factură');
                previewContainer.find('h3').text('Previzualizare');
            };
            reader.readAsDataURL(file);
        }

        previewContainer.prop('hidden', false);
        $(selectors.resultsDisplay).find('.placeholder').remove();
        notify('success', 'Fișier pregătit pentru procesare.');
    }

    function resetUpload() {
        selectedFile = null;
        $(selectors.fileInput).val('');
        $(selectors.previewContainer).prop('hidden', true);
        $(selectors.previewImage).attr('src', '');
    }

    async function processInvoice() {
        const indicator = $(selectors.processingIndicator);
        indicator.prop('hidden', false);
        toggleProcessing(true);

        const formData = new FormData();
        if (selectedFile) {
            formData.append('image', selectedFile, selectedFile.name);
        }

        const procesareSection = document.getElementById('invoice-processing');
        if (procesareSection) {
            const nrFacturaInput = procesareSection.querySelector('input[name="nr_factura"]');
            if (nrFacturaInput && nrFacturaInput.value.trim()) {
                formData.append('nr_factura', nrFacturaInput.value.trim());
            }

            procesareSection.querySelectorAll('[data-fs-field]').forEach(element => {
                const name = element.getAttribute('name') || element.getAttribute('data-fs-field');
                if (!name) {
                    return;
                }
                const value = element.value ?? element.textContent;
                if (value !== undefined && value !== null && String(value).trim() !== '') {
                    formData.append(name, String(value).trim());
                }
            });
        }

        try {
            const result = await callN8n(formData);
            
            // Extrage datele din structura imbricată
            const responseData = result.data || result;
            const rawInvoice = responseData.invoice;
            const files = responseData.files;

            // Normalizează datele facturii pentru afișare corectă
            const invoice = normalizeInvoiceData(rawInvoice);

            if (invoice) {
                displayResults(invoice, responseData);
            } else {
                const container = $(selectors.resultsDisplay);
                if (container.length) {
                    container.find('.placeholder').remove();
                }
            }

            // Pasăm datele cu structura corectă la renderFiles
            renderFiles({ 
                ...responseData,
                files: files 
            });
            notify('success', result.message || 'Factura a fost procesată.');
            resetUpload();
            fetchStats();
            if (facturiTable) {
                facturiTable.ajax.reload(null, false);
            }
        } catch (error) {
            notify('error', error.message || 'A apărut o eroare la procesare.');
        } finally {
            indicator.prop('hidden', true);
            toggleProcessing(false);
        }
    }

    function toggleProcessing(isProcessing) {
        $(selectors.processBtn).prop('disabled', isProcessing);
        $(selectors.selectFileBtn).prop('disabled', isProcessing);
        $(selectors.cameraBtn).prop('disabled', isProcessing);
    }

    async function callN8n(formData, timeoutMs = 90000) {
        const webhookUrl = resolveWebhookUrl();
        if (!webhookUrl) {
            throw new Error('Adresa webhook n8n lipsește din configurare.');
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
        let response;
        let responseText = '';

        try {
            response = await fetch(webhookUrl, {
                method: 'POST',
                body: formData,
                signal: controller.signal,
                credentials: 'omit'
            });
            responseText = await response.text();
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('Timpul de așteptare a expirat (20s).');
            }
            throw new Error(`Nu s-a putut contacta serviciul extern: ${error.message}`);
        } finally {
            clearTimeout(timeoutId);
        }

        let data;
        try {
            data = responseText ? JSON.parse(responseText) : {};
        } catch (parseError) {
            throw new Error('Răspuns non-JSON de la serviciul extern.');
        }

        if (!response.ok) {
            const message = data?.message || `Serviciul extern a returnat codul ${response.status}.`;
            throw new Error(message);
        }

        if (!data || data.success !== true) {
            const reason = data?.message || 'Răspuns invalid de la serviciul extern.';
            throw new Error(`Răspuns invalid de la serviciul extern: ${reason}`);
        }

        // Normalizează fișierele: acceptă atât array cât și obiect
        if (!data.files) {
            data.files = [];
        } else if (!Array.isArray(data.files)) {
            // Dacă este obiect cu chei invoice/somatie, convertește în array
            const filesObj = data.files;
            data.files = [];
            
            if (filesObj.invoice) {
                data.files.push({
                    type: 'factura',
                    label: 'Factură + Somație',
                    ...filesObj.invoice
                });
            }
            
            if (filesObj.somatie) {
                data.files.push({
                    type: 'somatie',
                    label: 'Somație separată',
                    ...filesObj.somatie
                });
            }
        }

        return data;
    }

    function renderFiles(result) {
        const panel = ensureResultsPanel();
        if (!panel) {
            return;
        }

        $(selectors.resultsDisplay).find('.placeholder').remove();

        const summary = panel.querySelector('#fs-summary');
        if (summary) {
            if (result?.nr_factura) {
                summary.textContent = `Factura: ${result.nr_factura}`;
                summary.hidden = false;
            } else {
                summary.textContent = '';
                summary.hidden = true;
            }
        }

        const list = panel.querySelector('#fs-files');
        const warning = panel.querySelector('#fs-warning');

        if (list) {
            list.innerHTML = '';
        }

        const files = Array.isArray(result?.files) ? result.files : [];

        if (!files.length) {
            if (warning) {
                warning.hidden = false;
            }
        } else {
            if (warning) {
                warning.hidden = true;
            }

            files.forEach(file => {
                const li = document.createElement('li');
                li.className = 'fs-file';

                const header = document.createElement('div');
                header.className = 'fs-file-header';

                const label = document.createElement('span');
                label.className = 'fs-label';
                label.textContent = file?.label || file?.filename || file?.url || 'Fișier';
                header.appendChild(label);

                const actions = document.createElement('div');
                actions.className = 'fs-file-actions';

                const hasUrl = Boolean(file?.url);

                if (hasUrl) {
                    const openLink = document.createElement('a');
                    openLink.href = file.url;
                    openLink.target = '_blank';
                    openLink.rel = 'noopener';
                    openLink.textContent = 'Deschide';
                    actions.appendChild(openLink);

                    const downloadLink = document.createElement('a');
                    downloadLink.href = file.url;
                    if (file?.filename) {
                        downloadLink.setAttribute('download', file.filename);
                    } else {
                        downloadLink.setAttribute('download', '');
                    }
                    downloadLink.textContent = 'Descarcă';
                    actions.appendChild(downloadLink);
                }

                const printButton = document.createElement('button');
                printButton.type = 'button';
                printButton.className = 'fs-print';
                printButton.textContent = 'Printează';
                printButton.disabled = !hasUrl;
                printButton.addEventListener('click', () => {
                    if (!hasUrl) {
                        return;
                    }
                    printFile(file.url, file?.mime || 'application/octet-stream');
                });
                actions.appendChild(printButton);

                header.appendChild(actions);
                li.appendChild(header);

                if (file?.type) {
                    const meta = document.createElement('span');
                    meta.className = 'fs-meta';
                    meta.textContent = `Tip: ${file.type.toUpperCase()}`;
                    li.appendChild(meta);
                }

                if (list) {
                    list.appendChild(li);
                }
            });
        }

        panel.hidden = false;
    }

    async function printFile(url, mime) {
        if (!url) {
            notify('error', 'Nu există un fișier disponibil pentru imprimare.');
            return;
        }

        let response;
        try {
            response = await fetch(url, { credentials: 'omit' });
        } catch (error) {
            notify('error', `Nu s-a putut descărca fișierul pentru imprimare: ${error.message}`);
            return;
        }

        if (!response.ok) {
            notify('error', `Descărcarea fișierului pentru imprimare a eșuat (cod ${response.status}).`);
            return;
        }

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);

        const printWindow = window.open('', '_blank');
        if (!printWindow) {
            notify('warning', 'Deblocați ferestrele pop-up pentru a imprima documentul.');
            URL.revokeObjectURL(objectUrl);
            return;
        }

        try {
            if ((mime || '').startsWith('image/')) {
                printWindow.document.write(`<!DOCTYPE html><html><head><title>Print</title></head><body style="margin:0;display:flex;align-items:center;justify-content:center;background:#fff;"><img src="${objectUrl}" style="max-width:100%;height:auto;"/></body></html>`);
                printWindow.document.close();
            } else {
                printWindow.location.href = objectUrl;
            }

            setTimeout(() => {
                try {
                    printWindow.focus();
                    printWindow.print();
                } catch (err) {
                    console.error('Print error', err);
                }
            }, 500);
        } finally {
            setTimeout(() => URL.revokeObjectURL(objectUrl), 10000);
        }
    }

    function displayResults(invoice, fullResult) {
        if (!invoice) {
            return;
        }

        const container = $(selectors.resultsDisplay);
        const somatieAvailable = Boolean(invoice.somatie_path);
        const facturaAvailable = Boolean(invoice.file_path);

        const html = `
            <div class="results-card">
                <div class="result-header">
                    <span class="material-symbols-outlined">task_alt</span>
                    <div>
                        <h3>Factura a fost procesată</h3>
                        <p>${escapeHtml(invoice.nume_firma || '')}</p>
                    </div>
                </div>
                <div class="invoice-info-grid">
                    ${buildInfoItem('Număr factură', invoice.nr_factura)}
                    ${buildInfoItem('CIF', invoice.cif || '—')}
                    ${buildInfoItem('Registrul comerțului', invoice.reg_com || '—')}
                    ${buildInfoItem('Adresă', invoice.adresa || '—')}
                    ${buildInfoItem('Data emitere', invoice.data_emitere_formatata || '—')}
                    ${buildInfoItem('Termen plată', invoice.termen_plata_formatat || '—')}
                    ${buildInfoItem('Sumă', invoice.suma_formatata || '—')}
                    ${buildInfoItem('Status', formatStatusBadge(invoice.status))}
                </div>
                <div class="download-buttons">
                    <button class="btn btn-primary" data-action="download" data-type="factura" data-id="${invoice.id}" ${facturaAvailable ? '' : 'disabled'}>
                        <span class="material-symbols-outlined">download</span>
                        Descarcă factura
                    </button>
                    <button class="btn btn-secondary" data-action="download" data-type="somatie" data-id="${invoice.id}" ${somatieAvailable ? '' : 'disabled'}>
                        <span class="material-symbols-outlined">gavel</span>
                        Descarcă somația
                    </button>
                </div>
                ${fullResult?.somatie_text ? `<div class="somatie-preview"><h4>Text somație</h4><pre>${escapeHtml(fullResult.somatie_text)}</pre></div>` : ''}
            </div>
        `;

        container.html(html);
    }

    function buildInfoItem(label, value) {
        return `
            <div class="info-item">
                <span class="info-label">${label}</span>
                <span class="info-value">${value}</span>
            </div>
        `;
    }

    function formatStatusBadge(status) {
        const label = status === 'platita' ? 'Plătită' : 'Neplătită';
        return `<span class="status-badge status-${status}">${label}</span>`;
    }

    function initDataTable() {
        if (!$.fn.DataTable) {
            return;
        }

        facturiTable = $(selectors.table).DataTable({
            responsive: true,
            ajax: {
                url: `${WMS_CONFIG.apiBase}/facturi_somatii.php?action=list`,
                data: function (data) {
                    data.date_from = $(selectors.filters.dateFrom).val();
                    data.date_to = $(selectors.filters.dateTo).val();
                    data.status = $(selectors.filters.status).val();
                    data.extra_search = $(selectors.filters.search).val();
                }
            },
            order: [[3, 'desc']],
            columns: [
                { data: 'nr_factura' },
                { data: 'nume_firma' },
                { data: 'cif', defaultContent: '—' },
                { data: 'data_emitere_formatata', defaultContent: '—' },
                { data: 'termen_plata_formatat', defaultContent: '—' },
                { data: 'suma_formatata', defaultContent: '—' },
                {
                    data: 'status',
                    render: function (data, type) {
                        if (type === 'display') {
                            return formatStatusBadge(data);
                        }
                        return data;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function (data, type, row) {
                        if (type !== 'display') {
                            return '';
                        }
                        const toggleStatus = row.status === 'platita' ? 'neplatita' : 'platita';
                        const toggleLabel = row.status === 'platita' ? 'Marchează neplătită' : 'Marchează plătită';
                        const toggleIcon = row.status === 'platita' ? 'undo' : 'done';
                        return `
                            <div class="action-buttons">
                                <button class="action-button view-invoice" data-id="${row.id}">
                                    <span class="material-symbols-outlined">visibility</span>
                                    <span class="action-label">Detalii</span>
                                </button>
                                <button class="action-button download-factura" data-id="${row.id}" data-type="factura">
                                    <span class="material-symbols-outlined">download</span>
                                    <span class="action-label">Factura</span>
                                </button>
                                <button class="action-button download-somatie" data-id="${row.id}" data-type="somatie">
                                    <span class="material-symbols-outlined">gavel</span>
                                    <span class="action-label">Somație</span>
                                </button>
                                <button class="action-button status-toggle" data-id="${row.id}" data-status="${toggleStatus}">
                                    <span class="material-symbols-outlined">${toggleIcon}</span>
                                    <span class="action-label">${toggleLabel}</span>
                                </button>
                                <button class="action-button action-danger delete-invoice" data-id="${row.id}">
                                    <span class="material-symbols-outlined">delete</span>
                                    <span class="action-label">Șterge</span>
                                </button>
                            </div>
                        `;
                    }
                }
            ],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/ro.json'
            }
        });

        setTimeout(() => {
            if (facturiTable) {
                facturiTable.columns.adjust().responsive.recalc();
            }
        }, 200);

        $(selectors.table).on('click', '.view-invoice', function () {
            const id = $(this).data('id');
            viewInvoice(id);
        });

        $(selectors.table).on('click', '.download-factura, .download-somatie', function () {
            const id = $(this).data('id');
            const type = $(this).data('type');
            downloadPDF(id, type);
        });

        $(selectors.table).on('click', '.status-toggle', function () {
            const id = $(this).data('id');
            const status = $(this).data('status');
            updateStatus(id, status);
        });

        $(selectors.table).on('click', '.delete-invoice', function () {
            const id = $(this).data('id');
            deleteInvoice(id);
        });
    }

    function bindFilterEvents() {
        $(selectors.applyFiltersBtn).on('click', applyFilters);
        $(selectors.resetFiltersBtn).on('click', () => {
            $(selectors.filters.dateFrom).val('');
            $(selectors.filters.dateTo).val('');
            $(selectors.filters.status).val('');
            $(selectors.filters.search).val('');
            applyFilters();
        });

        $(selectors.filters.search).on('keypress', function (event) {
            if (event.key === 'Enter') {
                applyFilters();
            }
        });
    }

    function applyFilters() {
        if (facturiTable) {
            facturiTable.ajax.reload();
        }
    }

    function fetchStats() {
        fetch(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=stats`, {
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    throw new Error(result.message || 'Nu s-au putut încărca statisticile.');
                }

                const stats = result.stats || {};
                $(selectors.stats.total).text(stats.total ?? 0);
                $(selectors.stats.neplatite).text(stats.neplatite ?? 0);
                $(selectors.stats.platite).text(stats.platite ?? 0);
                $(selectors.stats.suma).text(stats.suma_formatata || formatCurrency(0));
            })
            .catch(error => {
                notify('error', error.message || 'Eroare la preluarea statisticilor.');
            });
    }

    function viewInvoice(id) {
        fetch(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=view&id=${id}`, {
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    throw new Error(result.message || 'Nu s-au putut încărca detaliile.');
                }

                const invoice = result.invoice;
                const body = $(selectors.modalBody);
                const html = `
                    <div class="modal-section">
                        <h4>Informații factură</h4>
                        <ul>
                            <li><strong>Număr:</strong> ${escapeHtml(invoice.nr_factura || '')}</li>
                            <li><strong>Firmă:</strong> ${escapeHtml(invoice.nume_firma || '')}</li>
                            <li><strong>CIF:</strong> ${escapeHtml(invoice.cif || '—')}</li>
                            <li><strong>Registrul Comerțului:</strong> ${escapeHtml(invoice.reg_com || '—')}</li>
                            <li><strong>Adresă:</strong> ${escapeHtml(invoice.adresa || '—')}</li>
                            <li><strong>Data emitere:</strong> ${escapeHtml(invoice.data_emitere_formatata || '—')}</li>
                            <li><strong>Termen plată:</strong> ${escapeHtml(invoice.termen_plata_formatat || '—')}</li>
                            <li><strong>Sumă:</strong> ${escapeHtml(invoice.suma_formatata || '—')}</li>
                            <li><strong>Status:</strong> ${formatStatusBadge(invoice.status)}</li>
                        </ul>
                    </div>
                    <div class="modal-actions">
                        <button class="btn btn-primary" data-modal-download data-type="factura" data-id="${invoice.id}">
                            <span class="material-symbols-outlined">download</span>
                            Descarcă factura
                        </button>
                        <button class="btn btn-secondary" data-modal-download data-type="somatie" data-id="${invoice.id}">
                            <span class="material-symbols-outlined">gavel</span>
                            Descarcă somația
                        </button>
                    </div>
                `;

                body.html(html);
                openModal();
            })
            .catch(error => {
                notify('error', error.message || 'Eroare la încărcarea detaliilor facturii.');
            });
    }

    function downloadPDF(id, type) {
        window.open(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=download&id=${id}&document=${type}`, '_blank');
    }

    function updateStatus(id, status) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', status);

        fetch(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=update_status`, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    throw new Error(result.message || 'Actualizarea statusului a eșuat.');
                }
                notify('success', result.message || 'Status actualizat.');
                if (facturiTable) {
                    facturiTable.ajax.reload(null, false);
                }
                fetchStats();
            })
            .catch(error => {
                notify('error', error.message || 'Eroare la actualizarea statusului.');
            });
    }

    function deleteInvoice(id) {
        if (!confirm('Ești sigur că vrei să ștergi această factură?')) {
            return;
        }

        const formData = new FormData();
        formData.append('id', id);

        fetch(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=delete`, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(result => {
                if (!result.success) {
                    throw new Error(result.message || 'Ștergerea a eșuat.');
                }
                notify('success', result.message || 'Factura a fost ștearsă.');
                if (facturiTable) {
                    facturiTable.ajax.reload(null, false);
                }
                fetchStats();
            })
            .catch(error => {
                notify('error', error.message || 'Eroare la ștergere.');
            });
    }

    function bindModalEvents() {
        $(selectors.closeModalBtn).on('click', closeModal);
        $(selectors.modal).on('click', function (event) {
            if (event.target === this) {
                closeModal();
            }
        });

        $(selectors.modalBody).on('click', '[data-modal-download]', function () {
            const id = $(this).data('id');
            const type = $(this).data('type');
            downloadPDF(id, type);
        });

        $(selectors.resultsDisplay).on('click', '[data-action="download"]', function () {
            const id = $(this).data('id');
            const type = $(this).data('type');
            downloadPDF(id, type);
        });
    }

    function openModal() {
        $(selectors.modal).attr('aria-hidden', 'false').addClass('open');
        $('body').addClass('modal-open');
    }

    function closeModal() {
        $(selectors.modal).attr('aria-hidden', 'true').removeClass('open');
        $('body').removeClass('modal-open');
    }

    function notify(type, message) {
        if (message === undefined) {
            message = type;
            type = 'info';
        }

        if (window.showToast) {
            window.showToast(message, type);
        } else if (type === 'error') {
            console.error(message);
        } else if (type === 'warning') {
            console.warn(message);
        } else {
            console.log(message);
        }
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function normalizeInvoiceData(invoice) {
    if (!invoice) return null;
    
        return {
            ...invoice,
            // Formatează datele dacă nu sunt deja formatate
            data_emitere_formatata: invoice.data_emitere_formatata || formatDateFromString(invoice.data_emitere),
            termen_plata_formatat: invoice.termen_plata_formatat || formatDateFromString(invoice.termen_plata),
            suma_formatata: invoice.suma_formatata || formatCurrencyValue(parseFloat(invoice.suma || 0))
        };
    }

    function formatDateFromString(dateStr) {
        if (!dateStr) return '—';
        
        // Dacă e deja formatat (dd.mm.yyyy), returnează
        if (/^\d{2}\.\d{2}\.\d{4}$/.test(dateStr)) {
            return dateStr;
        }
        
        // Convertește din dd/mm/yyyy în dd.mm.yyyy
        if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateStr)) {
            return dateStr.replace(/\//g, '.');
        }
        
        // Încearcă să parseze data în format ISO sau alte formate
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return '—';
        
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}.${month}.${year}`;
    }

    function formatCurrencyValue(value) {
        if (isNaN(value)) return '0,00 RON';
        return value.toFixed(2).replace('.', ',') + ' RON';
    }

    function formatCurrency(value) {
        return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON' }).format(value || 0);
    }

    $(document).ready(init);
})(jQuery);