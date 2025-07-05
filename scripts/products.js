/**
 * Products.js - Smart Search, Pagination & CRUD Operations
 * Modern vanilla JavaScript implementation
 */

class ProductsManager {
    constructor() {
        this.products = [];
        this.filteredProducts = [];
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalPages = 0;
        this.totalCount = 0;
        this.searchTerm = '';
        this.filters = {
            category: '',
            stock: '',
            sort: 'name-asc'
        };
        
        this.debounceTimer = null;
        this.init();
    }

    async init() {
        this.bindEvents();
        await this.loadProducts();
        this.renderProducts();
        this.updatePagination();
    }

    bindEvents() {
        // Search functionality
        const searchInput = document.getElementById('search-input');
        const clearSearch = document.getElementById('clear-search');
        
        searchInput.addEventListener('input', (e) => {
            this.debounceSearch(e.target.value);
        });
        
        clearSearch.addEventListener('click', () => {
            searchInput.value = '';
            this.searchTerm = '';
            clearSearch.style.display = 'none';
            this.applyFilters();
        });

        // Filter controls
        document.getElementById('category-filter').addEventListener('change', (e) => {
            this.filters.category = e.target.value;
            this.applyFilters();
        });

        document.getElementById('stock-filter').addEventListener('change', (e) => {
            this.filters.stock = e.target.value;
            this.applyFilters();
        });

        document.getElementById('sort-filter').addEventListener('change', (e) => {
            this.filters.sort = e.target.value;
            this.applyFilters();
        });

        // Pagination controls
        document.getElementById('first-page').addEventListener('click', () => this.goToPage(1));
        document.getElementById('prev-page').addEventListener('click', () => this.goToPage(this.currentPage - 1));
        document.getElementById('next-page').addEventListener('click', () => this.goToPage(this.currentPage + 1));
        document.getElementById('last-page').addEventListener('click', () => this.goToPage(this.totalPages));

        document.getElementById('page-size').addEventListener('change', async (e) => {
            this.pageSize = parseInt(e.target.value);
            this.currentPage = 1;
            await this.applyFilters();
        });

        // Modal controls
        document.getElementById('add-product-btn').addEventListener('click', () => this.openModal());
        document.getElementById('modal-close').addEventListener('click', () => this.closeModal());
        document.getElementById('cancel-btn').addEventListener('click', () => this.closeModal());
        
        // Form submission
        document.getElementById('product-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveProduct();
        });

        // Click outside modal to close
        document.getElementById('product-modal').addEventListener('click', (e) => {
            if (e.target.id === 'product-modal') {
                this.closeModal();
            }
        });

        // Export functionality
        document.getElementById('export-btn').addEventListener('click', () => this.exportProducts());
    }

    debounceSearch(searchTerm) {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => {
            this.searchTerm = searchTerm.toLowerCase();
            const clearBtn = document.getElementById('clear-search');
            clearBtn.style.display = searchTerm ? 'block' : 'none';
            this.applyFilters();
        }, 300);
    }

    async loadProducts() {
        this.showLoading(true);
        try {
            const response = await this.apiCall('get_products');
            if (response.success) {
                this.products = response.data;
                this.filteredProducts = [...this.products];
            } else {
                throw new Error(response.message || 'Failed to load products');
            }
        } catch (error) {
            console.error('Error loading products:', error);
            this.showMessage('Eroare la încărcarea produselor', 'error');
        } finally {
            this.showLoading(false);
        }
    }

    async apiCall(action, data = {}) {
        const formData = new FormData();
        formData.append('api_action', action);
        
        for (const [key, value] of Object.entries(data)) {
            formData.append(key, value);
        }

        const response = await fetch(window.PRODUCTS_API_URL || window.location.href, {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    applyFilters() {
        this.filteredProducts = this.products.filter(product => {
            // Text search
            if (this.searchTerm) {
                const searchFields = [
                    product.name.toLowerCase(),
                    product.sku.toLowerCase(),
                    product.category.toLowerCase(),
                    product.description?.toLowerCase() || ''
                ];
                
                if (!searchFields.some(field => field.includes(this.searchTerm))) {
                    return false;
                }
            }

            // Category filter
            if (this.filters.category && product.category !== this.filters.category) {
                return false;
            }

            // Stock filter
            if (this.filters.stock) {
                switch (this.filters.stock) {
                    case 'in-stock':
                        if (product.quantity <= product.min_stock_level) return false;
                        break;
                    case 'low-stock':
                        if (product.quantity === 0 || product.quantity > product.min_stock_level) return false;
                        break;
                    case 'out-of-stock':
                        if (product.quantity > 0) return false;
                        break;
                }
            }

            return true;
        });

        // Apply sorting
        this.sortProducts();
        
        // Reset to first page
        this.currentPage = 1;
        this.renderProducts();
        this.updatePagination();
        this.updateResultsCount();
    }

    sortProducts() {
        const [field, direction] = this.filters.sort.split('-');
        
        this.filteredProducts.sort((a, b) => {
            let aVal, bVal;
            
            switch (field) {
                case 'name':
                    aVal = a.name.toLowerCase();
                    bVal = b.name.toLowerCase();
                    break;
                case 'sku':
                    aVal = a.sku.toLowerCase();
                    bVal = b.sku.toLowerCase();
                    break;
                case 'created':
                    aVal = new Date(a.created_at);
                    bVal = new Date(b.created_at);
                    break;
                case 'stock':
                    aVal = a.quantity;
                    bVal = b.quantity;
                    break;
                default:
                    return 0;
            }
            
            if (direction === 'asc') {
                return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
            } else {
                return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
            }
        });
    }

    renderProducts() {
        const grid = document.getElementById('products-grid');
        this.totalPages = Math.ceil(this.filteredProducts.length / this.pageSize);
        
        const startIndex = (this.currentPage - 1) * this.pageSize;
        const endIndex = startIndex + this.pageSize;
        const pageProducts = this.filteredProducts.slice(startIndex, endIndex);

        if (pageProducts.length === 0) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--light-gray);">
                    <span class="material-symbols-outlined" style="font-size: 4rem; margin-bottom: 1rem; display: block;">inventory_2</span>
                    <h3>Nu s-au găsit produse</h3>
                    <p>Încercați să modificați criteriile de căutare</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = pageProducts.map(product => this.createProductCard(product)).join('');
        
        // Bind action events
        this.bindProductActions();
    }

    createProductCard(product) {
        const stockStatus = this.getStockStatus(product);
        const stockIndicatorClass = stockStatus.toLowerCase().replace(' ', '-');
        
        return `
            <div class="product-card" data-product-id="${product.product_id}">
                <div class="product-header">
                    <div class="product-sku">${product.sku}</div>
                    <div class="product-name">${product.name}</div>
                    <div class="product-category">${product.category}</div>
                </div>
                
                <div class="product-body">
                    ${product.description ? `<div class="product-description">${product.description}</div>` : ''}
                    
                    <div class="product-stats">
                        <div class="stat-item">
                            <div class="stat-value">${product.quantity}</div>
                            <div class="stat-label">Stoc actual</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">${product.price.toFixed(2)} RON</div>
                            <div class="stat-label">Preț</div>
                        </div>
                    </div>
                    
                    <div class="stock-indicator ${stockIndicatorClass}">
                        ${stockStatus}
                    </div>
                    
                    <div class="product-actions">
                        <button class="action-btn edit" data-action="edit" data-id="${product.product_id}">
                            <span class="material-symbols-outlined">edit</span>
                            Editează
                        </button>
                        <button class="action-btn delete" data-action="delete" data-id="${product.product_id}">
                            <span class="material-symbols-outlined">delete</span>
                            Șterge
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    getStockStatus(product) {
        if (product.quantity === 0) {
            return 'Fără stoc';
        } else if (product.quantity <= product.min_stock_level) {
            return 'Stoc scăzut';
        } else {
            return 'În stoc';
        }
    }

    bindProductActions() {
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const action = e.currentTarget.dataset.action;
                const productId = parseInt(e.currentTarget.dataset.id);
                
                if (action === 'edit') {
                    this.editProduct(productId);
                } else if (action === 'delete') {
                    this.deleteProduct(productId);
                }
            });
        });
    }

    updatePagination() {
        const firstBtn = document.getElementById('first-page');
        const prevBtn = document.getElementById('prev-page');
        const nextBtn = document.getElementById('next-page');
        const lastBtn = document.getElementById('last-page');
        
        firstBtn.disabled = this.currentPage === 1;
        prevBtn.disabled = this.currentPage === 1;
        nextBtn.disabled = this.currentPage === this.totalPages || this.totalPages === 0;
        lastBtn.disabled = this.currentPage === this.totalPages || this.totalPages === 0;
        
        this.renderPageNumbers();
        this.updatePaginationInfo();
    }

    renderPageNumbers() {
        const container = document.getElementById('page-numbers');
        const maxVisible = 5;
        let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(this.totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        let html = '';
        
        for (let i = startPage; i <= endPage; i++) {
            html += `
                <button class="page-btn ${i === this.currentPage ? 'active' : ''}" 
                        data-page="${i}">${i}</button>
            `;
        }
        
        container.innerHTML = html;
        
        // Bind page number events
        container.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const page = parseInt(e.target.dataset.page);
                this.goToPage(page);
            });
        });
    }

    updatePaginationInfo() {
        const info = document.getElementById('pagination-info');
        const start = (this.currentPage - 1) * this.pageSize + 1;
        const end = Math.min(this.currentPage * this.pageSize, this.filteredProducts.length);
        const total = this.filteredProducts.length;
        
        info.textContent = `Afișare ${start}-${end} din ${total} produse`;
    }

    updateResultsCount() {
        const count = document.getElementById('results-count');
        count.textContent = `${this.filteredProducts.length} produse găsite`;
    }

    goToPage(page) {
        if (page >= 1 && page <= this.totalPages) {
            this.currentPage = page;
            this.renderProducts();
            this.updatePagination();
        }
    }

    openModal(product = null) {
        const modal = document.getElementById('product-modal');
        const title = document.getElementById('modal-title');
        const form = document.getElementById('product-form');
        
        if (product) {
            title.textContent = 'Editare Produs';
            this.populateForm(product);
            document.getElementById('form-action').value = 'update';
        } else {
            title.textContent = 'Adaugă Produs';
            form.reset();
            document.getElementById('form-action').value = 'create';
            document.getElementById('product-id').value = '';
        }
        
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    closeModal() {
        const modal = document.getElementById('product-modal');
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }

    populateForm(product) {
        document.getElementById('product-id').value = product.product_id;
        document.getElementById('product-sku').value = product.sku;
        document.getElementById('product-name').value = product.name;
        document.getElementById('product-description').value = product.description || '';
        document.getElementById('product-category').value = product.category;
        document.getElementById('product-price').value = product.price;
        document.getElementById('product-quantity').value = product.quantity;
        document.getElementById('product-min-stock').value = product.min_stock_level;
    }

    async saveProduct() {
        this.showLoading(true);
        
        try {
            const formData = new FormData(document.getElementById('product-form'));
            const productData = Object.fromEntries(formData.entries());
            
            // Simulate API call
            await this.mockSaveProduct(productData);
            
            this.showMessage('Produsul a fost salvat cu succes', 'success');
            this.closeModal();
            await this.loadProducts();
            this.applyFilters();
            
        } catch (error) {
            console.error('Error saving product:', error);
            this.showMessage('Eroare la salvarea produsului', 'error');
        } finally {
            this.showLoading(false);
        }
    }

    async mockSaveProduct(productData) {
        return new Promise((resolve) => {
            setTimeout(() => {
                console.log('Saving product:', productData);
                resolve();
            }, 1000);
        });
    }

    editProduct(productId) {
        const product = this.products.find(p => p.product_id === productId);
        if (product) {
            this.openModal(product);
        }
    }

    async deleteProduct(productId) {
        if (confirm('Sunteți sigur că doriți să ștergeți acest produs?')) {
            this.showLoading(true);
            
            try {
                // Simulate API call
                await this.mockDeleteProduct(productId);
                
                this.showMessage('Produsul a fost șters cu succes', 'success');
                await this.loadProducts();
                this.applyFilters();
                
            } catch (error) {
                console.error('Error deleting product:', error);
                this.showMessage('Eroare la ștergerea produsului', 'error');
            } finally {
                this.showLoading(false);
            }
        }
    }

    async mockDeleteProduct(productId) {
        return new Promise((resolve) => {
            setTimeout(() => {
                console.log('Deleting product:', productId);
                resolve();
            }, 500);
        });
    }

    exportProducts() {
        const data = this.filteredProducts.map(product => ({
            SKU: product.sku,
            Nume: product.name,
            Descriere: product.description || '',
            Categorie: product.category,
            Stoc: product.quantity,
            'Stoc Minim': product.min_stock_level,
            'Preț (RON)': product.price
        }));
        
        this.downloadCSV(data, 'produse_export.csv');
        this.showMessage('Export finalizat cu succes', 'success');
    }

    downloadCSV(data, filename) {
        const headers = Object.keys(data[0]);
        const csvContent = [
            headers.join(','),
            ...data.map(row => headers.map(header => `"${row[header]}"`).join(','))
        ].join('\n');
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
    }

    showLoading(show) {
        const overlay = document.getElementById('loading-overlay');
        overlay.style.display = show ? 'flex' : 'none';
    }

    showMessage(text, type = 'info') {
        const container = document.getElementById('message-container');
        const message = document.createElement('div');
        message.className = `message ${type}`;
        
        const icon = this.getMessageIcon(type);
        message.innerHTML = `
            <span class="material-symbols-outlined">${icon}</span>
            ${text}
        `;
        
        container.appendChild(message);
        
        setTimeout(() => {
            message.remove();
        }, 5000);
    }

    getMessageIcon(type) {
        switch (type) {
            case 'success': return 'check_circle';
            case 'error': return 'error';
            case 'warning': return 'warning';
            default: return 'info';
        }
    }
}

// Initialize the application when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new ProductsManager();
});