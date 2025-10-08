(function ($) {
    'use strict';

    let selectedFile = null;
    let mediaStream = null;
    let facturiTable = null;

    const selectors = {
        tabButtons: '.tab-button',
        tabPanels: '.tab-panel',
        uploadArea: '#upload-area',
        fileInput: '#invoice-file',
        selectFileBtn: '#select-file-btn',
        cameraBtn: '#camera-btn',
        cameraContainer: '#camera-container',
        cameraStream: '#camera-stream',
        captureBtn: '#capture-btn',
        closeCameraBtn: '#close-camera-btn',
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
        bindTabEvents();
        bindUploadEvents();
        bindCameraEvents();
        bindProcessingEvents();
        initDataTable();
        fetchStats();
        bindFilterEvents();
        bindModalEvents();
    }

    function bindTabEvents() {
        $(document).on('click', selectors.tabButtons, function () {
            const tab = $(this).data('tab');
            switchTab(tab);
        });
    }

    function switchTab(tabName) {
        $(selectors.tabButtons).removeClass('active').attr('aria-selected', 'false');
        $(selectors.tabPanels).removeClass('active').attr('aria-hidden', 'true');

        $(`${selectors.tabButtons}[data-tab="${tabName}"]`).addClass('active').attr('aria-selected', 'true');
        $(`#tab-${tabName}`).addClass('active').attr('aria-hidden', 'false');

        if (tabName === 'management' && facturiTable) {
            facturiTable.columns.adjust();
        }
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
        $(selectors.cameraBtn).on('click', openCamera);
        $(selectors.closeCameraBtn).on('click', closeCamera);
        $(selectors.captureBtn).on('click', capturePhoto);
    }

    function openCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            notify('error', 'Camera nu este suportată pe acest dispozitiv.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                mediaStream = stream;
                const video = document.querySelector(selectors.cameraStream);
                if (video) {
                    video.srcObject = stream;
                }
                $(selectors.cameraContainer).prop('hidden', false);
            })
            .catch(err => {
                notify('error', 'Nu s-a putut porni camera: ' + err.message);
            });
    }

    function closeCamera() {
        if (mediaStream) {
            mediaStream.getTracks().forEach(track => track.stop());
            mediaStream = null;
        }
        $(selectors.cameraContainer).prop('hidden', true);
    }

    function capturePhoto() {
        const video = document.querySelector(selectors.cameraStream);
        if (!video || !mediaStream) {
            notify('warning', 'Camera nu este activă.');
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 1280;
        canvas.height = video.videoHeight || 720;
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        canvas.toBlob(blob => {
            if (!blob) {
                notify('error', 'Nu s-a putut captura imaginea.');
                return;
            }

            const file = new File([blob], `factura_${Date.now()}.jpg`, { type: 'image/jpeg' });
            handleFileUpload(file);
            closeCamera();
        }, 'image/jpeg', 0.95);
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
        formData.append('invoice_file', selectedFile);

        try {
            const response = await fetch(`${WMS_CONFIG.apiBase}/facturi_somatii.php?action=process`, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Procesarea a eșuat.');
            }

            displayResults(result.invoice, result);
            notify(result.message || 'Factura a fost procesată.', 'success');
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
                                <button class="btn btn-icon view-invoice" data-id="${row.id}" title="Detalii">
                                    <span class="material-symbols-outlined">visibility</span>
                                </button>
                                <button class="btn btn-icon download-factura" data-id="${row.id}" data-type="factura" title="Descarcă factura">
                                    <span class="material-symbols-outlined">download</span>
                                </button>
                                <button class="btn btn-icon download-somatie" data-id="${row.id}" data-type="somatie" title="Descarcă somația">
                                    <span class="material-symbols-outlined">gavel</span>
                                </button>
                                <button class="btn btn-icon status-toggle" data-id="${row.id}" data-status="${toggleStatus}" title="${toggleLabel}">
                                    <span class="material-symbols-outlined">${toggleIcon}</span>
                                </button>
                                <button class="btn btn-icon delete-invoice" data-id="${row.id}" title="Șterge factura">
                                    <span class="material-symbols-outlined">delete</span>
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

    function formatCurrency(value) {
        return new Intl.NumberFormat('ro-RO', { style: 'currency', currency: 'RON' }).format(value || 0);
    }

    $(document).ready(init);
})(jQuery);