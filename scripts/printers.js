'use strict';

const baseUrl = window.APP_CONFIG?.baseUrl || '';
const printersApi = `${baseUrl}/api/printers.php`;

const PrinterApp = {
    init() {
        this.cache();
        this.bindEvents();
        this.loadPrinters();
    },

    cache() {
        this.tableBody = document.getElementById('printersBody');
        this.addBtn = document.getElementById('addPrinterBtn');
        this.modal = document.getElementById('printerModal');
        this.modalTitle = document.getElementById('printerModalTitle');
        this.form = document.getElementById('printerForm');
        this.idInput = document.getElementById('printerId');
        this.nameInput = document.getElementById('printerName');
        this.addressInput = document.getElementById('printerAddress');
        this.deleteModal = document.getElementById('deletePrinterModal');
        this.deleteForm = document.getElementById('deletePrinterForm');
        this.deleteId = document.getElementById('deletePrinterId');
        this.deleteName = document.getElementById('deletePrinterName');
    },

    bindEvents() {
        if (this.addBtn) {
            this.addBtn.addEventListener('click', () => this.open());
        }
        if (this.form) {
            this.form.addEventListener('submit', e => {
                e.preventDefault();
                this.save();
            });
        }
        if (this.deleteForm) {
            this.deleteForm.addEventListener('submit', e => {
                e.preventDefault();
                this.remove();
            });
        }
    },

    async loadPrinters() {
        try {
            const res = await fetch(printersApi);
            const data = await res.json();
            this.render(data);
        } catch (err) {
            console.error('Failed to load printers', err);
        }
    },

    render(printers) {
        this.tableBody.innerHTML = '';
        printers.forEach(p => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${p.id}</td>
                <td>${p.name}</td>
                <td>${p.network_identifier}</td>
                <td>
                    <button class="btn btn-sm btn-primary" data-id="${p.id}" data-name="${p.name}" data-address="${p.network_identifier}">Editează</button>
                    <button class="btn btn-sm btn-danger" data-del="${p.id}" data-name="${p.name}">Șterge</button>
                </td>`;
            tr.querySelector('[data-id]')?.addEventListener('click', () => {
                this.open(tr.querySelector('[data-id]').dataset);
            });
            tr.querySelector('[data-del]')?.addEventListener('click', () => {
                this.confirmDelete(tr.querySelector('[data-del]').dataset);
            });
            this.tableBody.appendChild(tr);
        });
    },

    open(data = {}) {
        this.idInput.value = data.id || '';
        this.nameInput.value = data.name || '';
        this.addressInput.value = data.address || '';
        this.modalTitle.textContent = data.id ? 'Editează Imprimantă' : 'Adaugă Imprimantă';
        this.modal.classList.add('show');
    },

    close() {
        this.modal.classList.remove('show');
        this.form.reset();
        this.idInput.value = '';
    },

    async save() {
        const payload = {
            name: this.nameInput.value.trim(),
            network_identifier: this.addressInput.value.trim()
        };
        const id = this.idInput.value;
        if (id) payload.id = parseInt(id, 10);

        const res = await fetch(printersApi + (id ? '' : ''), {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.ok) {
            this.close();
            this.loadPrinters();
        } else {
            console.error('Save failed');
        }
    },

    confirmDelete(data) {
        this.deleteId.value = data.del;
        this.deleteName.textContent = data.name;
        this.deleteModal.classList.add('show');
    },

    closeDelete() {
        this.deleteModal.classList.remove('show');
        this.deleteForm.reset();
    },

    async remove() {
        const id = this.deleteId.value;
        const res = await fetch(`${printersApi}?id=${id}`, { method: 'DELETE' });
        if (res.ok) {
            this.closeDelete();
            this.loadPrinters();
        } else {
            console.error('Delete failed');
        }
    }
};

document.addEventListener('DOMContentLoaded', () => PrinterApp.init());

function closePrinterModal() {
    PrinterApp.close();
}
function closeDeleteModal() {
    PrinterApp.closeDelete();
}
