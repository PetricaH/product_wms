// File: scripts/locations.js
// JavaScript functionality for the locations page

document.addEventListener('DOMContentLoaded', function() {
    console.log('Locations page loaded');
});

// Modal functions
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Adaugă Locație';
    document.getElementById('formAction').value = 'create';
    document.getElementById('locationId').value = '';
    document.getElementById('submitBtn').textContent = 'Salvează';
    
    // Clear form
    document.getElementById('locationForm').reset();
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function openEditModal(location) {
    document.getElementById('modalTitle').textContent = 'Editează Locație';
    document.getElementById('formAction').value = 'update';
    document.getElementById('locationId').value = location.id;
    document.getElementById('submitBtn').textContent = 'Actualizează';
    
    // Populate form
    document.getElementById('location_code').value = location.location_code;
    document.getElementById('zone').value = location.zone;
    document.getElementById('type').value = location.type || 'Standard';
    document.getElementById('capacity').value = location.capacity || '';
    document.getElementById('status').value = location.status;
    document.getElementById('description').value = location.description || '';
    
    // Show modal
    document.getElementById('locationModal').classList.add('show');
}

function closeModal() {
    document.getElementById('locationModal').classList.remove('show');
}

function openDeleteModal(locationId, locationCode) {
    document.getElementById('deleteLocationId').value = locationId;
    document.getElementById('deleteLocationCode').textContent = locationCode;
    document.getElementById('deleteModal').classList.add('show');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('show');
}

// Close modals when clicking outside
window.onclick = function(event) {
    const locationModal = document.getElementById('locationModal');
    const deleteModal = document.getElementById('deleteModal');
    
    if (event.target === locationModal) {
        closeModal();
    }
    if (event.target === deleteModal) {
        closeDeleteModal();
    }
}

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// Form validation
document.getElementById('locationForm').addEventListener('submit', function(event) {
    const locationCode = document.getElementById('location_code').value.trim();
    const zone = document.getElementById('zone').value.trim();
    
    if (!locationCode || !zone) {
        event.preventDefault();
        alert('Codul locației și zona sunt obligatorii!');
        return false;
    }
    
    // Validate location code format (optional)
    const codePattern = /^[A-Z0-9\-]+$/i;
    if (!codePattern.test(locationCode)) {
        event.preventDefault();
        alert('Codul locației poate conține doar litere, cifre și cratima (-)');
        return false;
    }
});