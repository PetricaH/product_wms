// Mobile Returns Workflow Script
// Handles scanning, step navigation, offline alerts

document.addEventListener('DOMContentLoaded', () => {
    const steps = ['lookup', 'summary', 'verification', 'discrepancy', 'completion'];
    const stepEls = {};
    steps.forEach(id => stepEls[id] = document.getElementById(`${id}-step`));
    const progressBar = document.getElementById('progress-bar');
    let currentStep = 0;

    function showStep(index) {
        currentStep = index;
        steps.forEach((id, i) => {
            stepEls[id].classList.toggle('hidden', i !== index);
        });
        const percent = (index / (steps.length - 1)) * 100;
        progressBar.style.width = `${percent}%`;
    }

    showStep(0);

    // Offline banner
    const offlineBanner = document.getElementById('offline-banner');
    function updateOnlineStatus() {
        offlineBanner.classList.toggle('hidden', navigator.onLine);
    }
    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    updateOnlineStatus();

    // QR scanning
    let html5QrCode = null;
    function startScanner(callback) {
        const modal = document.getElementById('scanner-modal');
        modal.classList.remove('hidden');
        html5QrCode = new Html5Qrcode('scanner-container');
        html5QrCode
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 250 },
                (decodedText) => {
                    callback(decodedText);
                    stopScanner();
                },
                (errorMessage) => {
                    console.log('Scan error', errorMessage);
                }
            )
            .catch(err => {
                showError('lookup-error', 'Camera error: ' + err);
                stopScanner();
            });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
        }
        document.getElementById('scanner-modal').classList.add('hidden');
    }
    document.getElementById('close-scanner-btn').addEventListener('click', stopScanner);

    function showError(id, msg) {
        const el = document.getElementById(id);
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    // Step 1: Lookup order
    const orderInput = document.getElementById('order-input');
    document.getElementById('order-scan-btn').addEventListener('click', () => {
        startScanner(text => {
            orderInput.value = text;
        });
    });

    document.getElementById('lookup-btn').addEventListener('click', () => {
        if (!orderInput.value) {
            showError('lookup-error', 'Introduceți numărul comenzii.');
            return;
        }
        document.getElementById('lookup-error').classList.add('hidden');
        // TODO: fetch order details from API
        document.getElementById('order-summary').textContent = `Comanda ${orderInput.value} încărcată.`;
        showStep(1);
    });

    // Step 2 -> Step 3
    document.getElementById('start-verification-btn').addEventListener('click', () => {
        showStep(2);
    });

    // Step 3: Item verification
    document.getElementById('item-scan-btn').addEventListener('click', () => {
        startScanner(code => {
            document.getElementById('item-info').textContent = `Produs: ${code}`;
            document.getElementById('condition-select').classList.remove('hidden');
        });
    });

    document.getElementById('confirm-item-btn').addEventListener('click', () => {
        const condition = document.getElementById('item-condition').value;
        if (condition === 'damaged') {
            const list = document.getElementById('discrepancy-list');
            const item = document.createElement('div');
            item.className = 'discrepancy';
            item.textContent = 'Produs marcat ca deteriorat.';
            list.appendChild(item);
        }
        document.getElementById('condition-select').classList.add('hidden');
        showStep(3);
    });

    // Step 4 -> Step 5
    document.getElementById('complete-return-btn').addEventListener('click', () => {
        document.getElementById('completion-details').textContent = `Returnare completă pentru comanda ${orderInput.value}.`;
        showStep(4);
    });

    document.getElementById('new-return-btn').addEventListener('click', () => {
        orderInput.value = '';
        document.getElementById('order-summary').textContent = '';
        document.getElementById('discrepancy-list').innerHTML = '';
        showStep(0);
    });

    // Touch gestures for navigation
    let touchStartX = null;
    document.addEventListener('touchstart', e => {
        touchStartX = e.changedTouches[0].screenX;
    });
    document.addEventListener('touchend', e => {
        if (touchStartX === null) return;
        const diff = e.changedTouches[0].screenX - touchStartX;
        if (Math.abs(diff) > 50) {
            if (diff < 0 && currentStep < steps.length - 1) showStep(currentStep + 1);
            if (diff > 0 && currentStep > 0) showStep(currentStep - 1);
        }
        touchStartX = null;
    });
});
