'use strict';

(function(window, document) {
    const baseUrl = (window.APP_CONFIG?.baseUrl || '').replace(/\/$/, '');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const BarcodeManager = {
        init() {
            this.endpoints = {
                generate: `${baseUrl}/api/barcode/generate.php`,
                print: `${baseUrl}/api/barcode/print.php`
            };
            this.cacheElements();
            this.bindEvents();
        },

        cacheElements() {
            this.elements = {
                table: document.getElementById('productUnitsTable'),
                confirmModal: document.getElementById('confirmOverwriteModal'),
                currentSkuValue: document.getElementById('currentSkuValue'),
                newSkuValue: document.getElementById('newSkuValue'),
                confirmOverwriteBtn: document.getElementById('confirmOverwriteBtn'),
                cancelOverwriteBtn: document.getElementById('cancelOverwriteBtn')
            };
            this.pendingConfirmation = null;
        },

        bindEvents() {
            document.addEventListener('click', (event) => this.handleTableClick(event));

            if (this.elements.cancelOverwriteBtn) {
                this.elements.cancelOverwriteBtn.addEventListener('click', () => this.resolveConfirmation(false));
            }

            if (this.elements.confirmOverwriteBtn) {
                this.elements.confirmOverwriteBtn.addEventListener('click', () => this.resolveConfirmation(true));
            }

            if (this.elements.confirmModal) {
                this.elements.confirmModal.addEventListener('click', (event) => {
                    if (event.target === this.elements.confirmModal) {
                        this.resolveConfirmation(false);
                    }
                });
            }
        },

        afterTableRender() {
            // Reserved for future enhancements (highlighting, etc.)
        },

        handleTableClick(event) {
            const generateBtn = event.target.closest('.generate-ean13-btn');
            if (generateBtn) {
                event.preventDefault();
                const unit = this.extractUnitFromButton(generateBtn);
                if (unit) {
                    this.generateForUnit(unit, { button: generateBtn });
                }
                return;
            }

            const printBtn = event.target.closest('.print-label-btn');
            if (printBtn) {
                event.preventDefault();
                const unit = this.extractUnitFromButton(printBtn);
                if (unit) {
                    this.printUnits([unit], { button: printBtn });
                }
                return;
            }

            const comboBtn = event.target.closest('.generate-print-btn');
            if (comboBtn) {
                event.preventDefault();
                const unit = this.extractUnitFromButton(comboBtn);
                if (unit) {
                    this.generateAndPrint(unit, comboBtn);
                }
            }
        },

        extractUnitFromButton(button) {
            if (!button) return null;
            const productId = parseInt(button.dataset.productId, 10);
            const unitId = parseInt(button.dataset.unitId, 10);
            if (!Number.isInteger(productId) || productId <= 0 || !Number.isInteger(unitId) || unitId <= 0) {
                this.notify('Datele produsului sunt invalide.', 'error');
                return null;
            }

            return {
                product_id: productId,
                unit_id: unitId,
                product_name: button.dataset.productName || '',
                current_sku: button.dataset.currentSku || ''
            };
        },

        describeSku(unit) {
            const rawSku = (unit?.product_code ?? unit?.sku ?? unit?.current_sku ?? '').toString().trim();
            const hasSku = rawSku !== '';
            if (!hasSku) {
                return {
                    status: 'missing',
                    badgeClass: 'badge-danger',
                    badgeLabel: '⚠️ Lipsă SKU',
                    displayValue: '',
                    sku: '',
                    shouldGenerate: true,
                    canPrint: false
                };
            }

            if (this.isEan13(rawSku)) {
                return {
                    status: 'ean13',
                    badgeClass: 'badge-success',
                    badgeLabel: 'EAN-13 ✓',
                    displayValue: rawSku,
                    sku: rawSku,
                    shouldGenerate: false,
                    canPrint: true
                };
            }

            if (/[a-zA-Z]/.test(rawSku)) {
                return {
                    status: 'alphanumeric',
                    badgeClass: 'badge-warning',
                    badgeLabel: 'Alfanumeric',
                    displayValue: rawSku,
                    sku: rawSku,
                    shouldGenerate: true,
                    canPrint: true
                };
            }

            if (/^\d+$/.test(rawSku)) {
                const badgeLabel = rawSku.length === 13 ? 'Numeric' : 'Numeric';
                return {
                    status: rawSku.length === 13 ? 'numeric-ean' : 'numeric',
                    badgeClass: rawSku.length === 13 ? 'badge-success' : 'badge-info',
                    badgeLabel,
                    displayValue: rawSku,
                    sku: rawSku,
                    shouldGenerate: rawSku.length !== 13 || !this.isEan13(rawSku),
                    canPrint: true
                };
            }

            return {
                status: 'custom',
                badgeClass: 'badge-secondary',
                badgeLabel: 'Cod personalizat',
                displayValue: rawSku,
                sku: rawSku,
                shouldGenerate: true,
                canPrint: true
            };
        },

        renderSkuCell(unit) {
            const info = this.describeSku(unit);
            if (!info.displayValue) {
                return '<div class="sku-display"><span class="badge badge-danger">⚠️ Lipsă SKU</span></div>';
            }

            return `
                <div class="sku-display">
                    <span class="sku-value">${this.escapeHtml(info.displayValue)}</span>
                    <span class="badge ${info.badgeClass}">${this.escapeHtml(info.badgeLabel)}</span>
                </div>
            `.trim();
        },

        renderRowActions(unit) {
            const info = this.describeSku(unit);
            const buttons = [];
            const attrs = `data-product-id="${unit.product_id}" data-unit-id="${unit.id || unit.unit_id || ''}" data-product-name="${this.escapeHtml(unit.product_name || '')}" data-current-sku="${this.escapeHtml(info.sku)}"`;

            if (info.shouldGenerate) {
                const generateClass = info.status === 'missing' ? 'btn btn-sm btn-primary generate-ean13-btn' : 'btn btn-sm btn-secondary generate-ean13-btn';
                buttons.push(`
                    <button class="${generateClass}" ${attrs}>
                        <span class="material-symbols-outlined">qr_code</span>
                        Generează EAN-13
                    </button>
                `);
            }

            if (info.canPrint) {
                buttons.push(`
                    <button class="btn btn-sm print-label-btn" ${attrs}>
                        <span class="material-symbols-outlined">print</span>
                        Tipărește
                    </button>
                `);
            }

            if (info.shouldGenerate) {
                buttons.push(`
                    <button class="btn btn-sm btn-success generate-print-btn" ${attrs}>
                        <span class="material-symbols-outlined">qr_code_2</span>
                        Generează &amp; Tipărește
                    </button>
                `);
            }

            return buttons.join('');
        },

        async generateForUnit(unit, options = {}) {
            try {
                if (!unit || !unit.product_id) {
                    throw new Error('Date produs invalide');
                }

                await this.generateBarcode(unit.product_id, {
                    unitId: unit.unit_id,
                    productName: unit.product_name,
                    currentSku: unit.current_sku,
                    button: options.button || null
                });
            } catch (error) {
                this.notify(error.message || 'Eroare la generarea codului.', 'error');
            }
        },

        async generateBarcode(productId, { unitId = null, forceOverwrite = false, productName = '', currentSku = '', button = null } = {}) {
            this.setButtonLoading(button, true);
            try {
                const payload = {
                    product_id: productId,
                    force_overwrite: forceOverwrite === true
                };

                const response = await this.request(this.endpoints.generate, payload);

                if (!response) {
                    throw new Error('Răspuns invalid de la server');
                }

                if (response.status === 'confirm') {
                    const confirmed = await this.openConfirmModal({
                        currentSku: response.current_sku || currentSku,
                        newSku: response.proposed_sku || this.generateEan13Code(productId),
                        productName
                    });

                    if (!confirmed) {
                        this.notify('Generarea codului a fost anulată.', 'info');
                        return { status: 'cancelled' };
                    }

                    return this.generateBarcode(productId, {
                        unitId,
                        forceOverwrite: true,
                        productName,
                        currentSku: response.current_sku,
                        button
                    });
                }

                if (response.status === 'success') {
                    this.notify(response.message || 'Cod EAN-13 generat cu succes.', 'success');
                    this.refreshData();
                    return response;
                }

                if (response.status === 'exists') {
                    this.notify(response.message || 'Produsul are deja un cod EAN-13.', 'info');
                    return response;
                }

                if (response.status === 'error') {
                    throw new Error(response.message || 'Eroare la generare.');
                }

                return response;
            } finally {
                this.setButtonLoading(button, false);
            }
        },

        async generateAndPrint(unit, button) {
            const result = await this.generateBarcode(unit.product_id, {
                unitId: unit.unit_id,
                productName: unit.product_name,
                currentSku: unit.current_sku,
                button
            });

            if (!result || result.status === 'cancelled' || result.status === 'error') {
                return;
            }

            await this.printUnits([unit], { button });
        },

        async printUnits(units, { button = null } = {}) {
            if (!Array.isArray(units) || !units.length) {
                this.notify('Nu au fost selectate unități pentru tipărire.', 'error');
                return;
            }

            const unitIds = units
                .map(unit => parseInt(unit.unit_id || unit.id, 10))
                .filter(id => Number.isInteger(id) && id > 0);

            if (!unitIds.length) {
                this.notify('Nu s-au găsit unități valide pentru tipărire.', 'error');
                return;
            }

            this.setButtonLoading(button, true);
            try {
                const payload = { product_unit_ids: unitIds };
                const response = await this.request(this.endpoints.print, payload);

                if (!response) {
                    throw new Error('Răspuns invalid de la server');
                }

                if (response.status === 'success') {
                    this.notify(`Au fost tipărite ${response.printed || unitIds.length} etichete.`, 'success');
                } else if (response.status === 'partial') {
                    this.notify('Tipărirea a fost finalizată cu avertismente.', 'info');
                    if (Array.isArray(response.errors) && response.errors.length) {
                        const details = response.errors.map(err => err.message).join('\n');
                        console.warn('Print warnings:', details);
                    }
                } else {
                    throw new Error(response.message || 'Eroare la tipărire.');
                }

                return response;
            } finally {
                this.setButtonLoading(button, false);
            }
        },

        async bulkGenerate(units) {
            const productIds = Array.from(new Set((units || []).map(unit => parseInt(unit.product_id, 10))))
                .filter(id => Number.isInteger(id) && id > 0);

            if (!productIds.length) {
                this.notify('Nu există produse valide pentru generare.', 'error');
                return;
            }

            try {
                const response = await this.request(this.endpoints.generate, { product_ids: productIds });
                if (!response) {
                    throw new Error('Răspuns invalid de la server');
                }

                if (response.status === 'error') {
                    throw new Error(response.message || 'Generarea în masă a eșuat.');
                }

                const generated = response.generated_count ?? 0;
                const warnings = response.warnings_count ?? 0;
                const errors = response.errors_count ?? 0;

                if (generated > 0) {
                    this.notify(`Au fost generate ${generated} coduri EAN-13.`, 'success');
                }

                if (warnings > 0) {
                    this.notify(`${warnings} produse necesită confirmare manuală.`, 'info');
                }

                if (errors > 0) {
                    this.notify(`${errors} produse au întâmpinat erori la generare.`, 'error');
                }

                this.refreshData();
                window.ProductUnitsApp?.clearUnitSelection?.();
            } catch (error) {
                this.notify(error.message || 'Eroare la generarea codurilor.', 'error');
            }
        },

        async bulkPrint(units) {
            const validUnits = (units || []).filter(unit => Number.isInteger(unit?.id) || Number.isInteger(unit?.unit_id));
            if (!validUnits.length) {
                this.notify('Nu există unități valide pentru tipărire.', 'error');
                return;
            }

            const response = await this.printUnits(validUnits);
            if (response && response.status === 'success') {
                window.ProductUnitsApp?.clearUnitSelection?.();
            }
        },

        async request(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });

            const text = await response.text();
            let data = null;
            if (text) {
                try {
                    data = JSON.parse(text);
                } catch (error) {
                    throw new Error('Răspuns JSON invalid.');
                }
            }

            if (!response.ok) {
                const message = data?.message || data?.error || `Eroare HTTP ${response.status}`;
                throw new Error(message);
            }

            return data;
        },

        openConfirmModal({ currentSku, newSku }) {
            if (!this.elements.confirmModal) {
                return Promise.resolve(false);
            }

            if (this.elements.currentSkuValue) {
                this.elements.currentSkuValue.textContent = currentSku || '-';
            }

            if (this.elements.newSkuValue) {
                this.elements.newSkuValue.textContent = newSku || '-';
            }

            this.elements.confirmModal.style.display = 'flex';

            return new Promise((resolve) => {
                this.pendingConfirmation = resolve;
            });
        },

        resolveConfirmation(confirmed) {
            if (this.elements.confirmModal) {
                this.elements.confirmModal.style.display = 'none';
            }

            if (typeof this.pendingConfirmation === 'function') {
                this.pendingConfirmation(Boolean(confirmed));
                this.pendingConfirmation = null;
            }
        },

        notify(message, type = 'info') {
            if (!message) {
                return;
            }

            const app = window.ProductUnitsApp;
            if (app && typeof app.showNotification === 'function') {
                app.showNotification(message, type);
                return;
            }

            const handler = type === 'error' ? 'error' : 'log';
            console[handler](message); // eslint-disable-line no-console
        },

        refreshData() {
            if (window.ProductUnitsApp && typeof window.ProductUnitsApp.loadProductUnits === 'function') {
                window.ProductUnitsApp.loadProductUnits();
            }
        },

        setButtonLoading(button, isLoading) {
            if (!button) return;
            if (isLoading) {
                button.dataset.originalHtml = button.dataset.originalHtml || button.innerHTML;
                button.classList.add('loading');
                button.disabled = true;
                button.innerHTML = '<span class="material-symbols-outlined spinning">progress_activity</span>';
            } else {
                button.disabled = false;
                button.classList.remove('loading');
                if (button.dataset.originalHtml) {
                    button.innerHTML = button.dataset.originalHtml;
                }
            }
        },

        isEan13(value) {
            const digits = (value || '').toString().trim();
            if (!/^\d{13}$/.test(digits)) {
                return false;
            }

            return this.calculateCheckDigit(digits.slice(0, 12)) === parseInt(digits[12], 10);
        },

        calculateCheckDigit(base) {
            if (!/^\d{12}$/.test(base)) {
                return 0;
            }

            let sum = 0;
            for (let i = 0; i < 12; i += 1) {
                const digit = parseInt(base[i], 10);
                sum += (i % 2 === 0) ? digit : digit * 3;
            }
            return (10 - (sum % 10)) % 10;
        },

        generateEan13Code(productId) {
            const numericId = Number(productId);
            if (!Number.isInteger(numericId) || numericId <= 0 || numericId > 99999) {
                return '';
            }

            const base = `5990000${numericId.toString().padStart(5, '0')}`;
            const checkDigit = this.calculateCheckDigit(base);
            return `${base}${checkDigit}`;
        },

        escapeHtml(value) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(value).replace(/[&<>"']/g, (char) => map[char]);
        }
    };

    document.addEventListener('DOMContentLoaded', () => BarcodeManager.init());
    window.BarcodeManager = BarcodeManager;
})(window, document);
