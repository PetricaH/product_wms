// File: scripts/transactions.js
// JavaScript functionality for the transactions page

document.addEventListener('DOMContentLoaded', function() {
    console.log('Transactions page loaded');
    initializeAmountCalculations();
});

// Modal functions
function openCreateModal() {
    document.getElementById('createTransactionModal').classList.add('show');
}

function closeCreateModal() {
    document.getElementById('createTransactionModal').classList.remove('show');
    document.getElementById('createTransactionForm').reset();
}

function openStatusModal(transactionId, currentStatus) {
    document.getElementById('statusTransactionId').value = transactionId;
    document.getElementById('updateStatus').value = currentStatus;
    document.getElementById('statusModal').classList.add('show');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.remove('show');
}

function openDeleteModal(transactionId, transactionNumber) {
    document.getElementById('deleteTransactionId').value = transactionId;
    document.getElementById('deleteTransactionNumber').textContent = transactionNumber;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

function viewTransactionDetails(transactionId) {
    // Redirect to transaction details page or open details modal
    window.location.href = `transaction_details.php?id=${transactionId}`;
}

// Amount calculations
function initializeAmountCalculations() {
    const amountInput = document.getElementById('amount');
    const taxAmountInput = document.getElementById('tax_amount');
    
    if (amountInput && taxAmountInput) {
        amountInput.addEventListener('input', calculateTax);
        taxAmountInput.addEventListener('input', validateTax);
    }
}

function calculateTax() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const taxAmount = amount * 0.19; // 19% TVA default
    document.getElementById('tax_amount').value = taxAmount.toFixed(2);
}

function validateTax() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const taxAmount = parseFloat(document.getElementById('tax_amount').value) || 0;
    
    if (taxAmount > amount) {
        alert('TVA nu poate fi mai mare decât suma totală!');
        document.getElementById('tax_amount').value = (amount * 0.19).toFixed(2);
    }
}

// Transaction type change handler
document.addEventListener('change', function(e) {
    if (e.target.id === 'transaction_type') {
        handleTransactionTypeChange(e.target.value);
    }
});

function handleTransactionTypeChange(type) {
    const customerField = document.getElementById('customer_name').closest('.form-group');
    const supplierField = document.getElementById('supplier_name').closest('.form-group');
    
    // Show/hide relevant fields based on transaction type
    switch (type) {
        case 'sales':
            customerField.style.display = 'block';
            supplierField.style.display = 'none';
            document.getElementById('supplier_name').value = '';
            break;
        case 'purchase':
            customerField.style.display = 'none';
            supplierField.style.display = 'block';
            document.getElementById('customer_name').value = '';
            break;
        case 'adjustment':
        case 'transfer':
        case 'return':
            customerField.style.display = 'block';
            supplierField.style.display = 'block';
            break;
        default:
            customerField.style.display = 'block';
            supplierField.style.display = 'block';
    }
}

// Close modals when clicking outside
window.onclick = function(event) {
    const modals = ['createTransactionModal', 'statusModal', 'deleteModal'];
    modals.forEach(modalId => {
        const modal = document.getElementById(modalId);
        if (event.target === modal) {
            modal.classList.remove('show');
        }
    });
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCreateModal();
        closeStatusModal();
        closeDeleteModal();
    }
});

// Form validation
document.getElementById('createTransactionForm').addEventListener('submit', function(event) {
    const transactionType = document.getElementById('transaction_type').value;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    
    if (!transactionType) {
        event.preventDefault();
        alert('Tipul tranzacției este obligatoriu!');
        return false;
    }
    
    if (amount <= 0) {
        event.preventDefault();
        alert('Suma trebuie să fie mai mare decât 0!');
        return false;
    }
    
    const taxAmount = parseFloat(document.getElementById('tax_amount').value) || 0;
    if (taxAmount > amount) {
        event.preventDefault();
        alert('TVA nu poate fi mai mare decât suma totală!');
        return false;
    }
});

// Number formatting
function formatCurrency(amount, currency = 'RON') {
    return new Intl.NumberFormat('ro-RO', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2
    }).format(amount);
}

// Export functions for external use
window.TransactionManager = {
    openCreateModal,
    closeCreateModal,
    openStatusModal,
    closeStatusModal,
    openDeleteModal,
    closeDeleteModal,
    viewTransactionDetails,
    formatCurrency
};