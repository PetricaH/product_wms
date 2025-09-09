// Inventory Stock Import Frontend
let stockImportFile = null;

function openImportStockModal() {
    resetStockImportModal();
    document.getElementById('importStockModal').classList.add('show');
}

function closeImportStockModal() {
    document.getElementById('importStockModal').classList.remove('show');
}

function resetStockImportModal() {
    stockImportFile = null;
    document.getElementById('stock-import-file').value = '';
    document.getElementById('stock-import-selected').style.display = 'none';
    document.getElementById('stock-import-start').disabled = true;
    showStockImportStep('upload');
}

function showStockImportStep(step) {
    ['upload','progress','results'].forEach(s => {
        const el = document.getElementById('stock-import-' + s);
        if (el) el.style.display = (s === step) ? 'block' : 'none';
    });
}

function handleStockImportFile(file) {
    stockImportFile = file;
    document.getElementById('stock-import-filename').textContent = file.name;
    document.getElementById('stock-import-selected').style.display = 'flex';
    document.getElementById('stock-import-start').disabled = false;
}

document.getElementById('stock-import-file').addEventListener('change', e => {
    const file = e.target.files[0];
    if (file) handleStockImportFile(file);
});

document.getElementById('stock-import-remove').addEventListener('click', () => {
    stockImportFile = null;
    document.getElementById('stock-import-selected').style.display = 'none';
    document.getElementById('stock-import-start').disabled = true;
});

const dropArea = document.getElementById('stock-import-drop');
if (dropArea) {
    dropArea.addEventListener('dragover', e => {
        e.preventDefault();
        dropArea.classList.add('dragover');
    });
    dropArea.addEventListener('dragleave', () => dropArea.classList.remove('dragover'));
    dropArea.addEventListener('drop', e => {
        e.preventDefault();
        dropArea.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) {
            if (validateStockImportFile(file)) {
                handleStockImportFile(file);
            }
        }
    });
    dropArea.addEventListener('click', () => {
        document.getElementById('stock-import-file').click();
    });
}

function validateStockImportFile(file) {
    const allowed = ['application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!allowed.includes(file.type)) {
        showNotification('Tip fișier invalid', 'error');
        return false;
    }
    if (file.size > 10 * 1024 * 1024) {
        showNotification('Fișier prea mare (max 10MB)', 'error');
        return false;
    }
    return true;
}

document.getElementById('stock-import-start').addEventListener('click', () => {
    if (!stockImportFile) return;
    if (!validateStockImportFile(stockImportFile)) return;
    showStockImportStep('progress');
    const formData = new FormData();
    formData.append('excel_file', stockImportFile);
    const progressBar = document.getElementById('stock-import-progress-bar');
    progressBar.style.width = '10%';
    fetch('api/inventory_stock_import.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            progressBar.style.width = '100%';
            displayStockImportResults(data);
        })
        .catch(() => {
            progressBar.style.width = '100%';
            displayStockImportResults({success:false,message:'Eroare la încărcare'});
        });
});

function displayStockImportResults(data) {
    showStockImportStep('results');
    document.getElementById('stock-import-summary').innerHTML = `
        <p>Procesate: ${data.processed || 0}</p>
        <p>Adăugate: ${data.stock_added || 0}</p>
        <p>Sărite: ${data.skipped || 0}</p>
        <p>${data.message || ''}</p>`;
    const warnList = document.getElementById('stock-import-warnings');
    warnList.innerHTML = '';
    if (data.warnings && data.warnings.length) {
        warnList.innerHTML = '<h5>Avertizări</h5><ul>' + data.warnings.map(w => `<li>${w}</li>`).join('') + '</ul>';
    }
    const errList = document.getElementById('stock-import-errors');
    errList.innerHTML = '';
    if (data.errors && data.errors.length) {
        errList.innerHTML = '<h5>Erori</h5><ul>' + data.errors.map(e => `<li>${e}</li>`).join('') + '</ul>';
    }
    if (typeof showNotification === 'function') {
        showNotification(data.message || 'Import finalizat', data.success ? 'success' : 'error');
    } else {
        alert(data.message || 'Import finalizat');
    }
}

// fallback notification if none defined
if (typeof showNotification !== 'function') {
    function showNotification(msg) { alert(msg); }
}
