(function () {
    const widget = document.getElementById('incident-report-widget');
    if (!widget) {
        return;
    }

    const modal = document.getElementById('incident-modal');
    const fab = document.getElementById('incident-fab');
    const form = document.getElementById('incident-form');
    const toastContainer = document.getElementById('incident-toast');
    const submitButton = document.getElementById('incident-submit');
    const photoInput = document.getElementById('incident_photos');
    const previewContainer = document.getElementById('incident-photo-preview');
    const occurredInput = document.getElementById('occurred_at');

    const createEndpoint = widget.dataset.createEndpoint;
    const csrfToken = widget.dataset.csrf;
    const maxPhotos = parseInt(widget.dataset.maxPhotos || '5', 10);
    const maxFileSize = 5 * 1024 * 1024; // 5MB
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    let fileBuffer = [];

    const setDefaultDate = () => {
        if (!occurredInput) {
            return;
        }
        const now = new Date();
        const offset = now.getTimezoneOffset();
        const localDate = new Date(now.getTime() - offset * 60000);
        occurredInput.value = localDate.toISOString().slice(0, 16);
    };

    const toggleModal = (show) => {
        if (!modal) return;
        modal.setAttribute('aria-hidden', show ? 'false' : 'true');
        if (show) {
            document.body.classList.add('incident-modal-open');
        } else {
            document.body.classList.remove('incident-modal-open');
        }
    };

    const createToast = (message, type = 'success') => {
        if (!toastContainer) return;
        const toast = document.createElement('div');
        toast.className = `incident-toast ${type}`;
        toast.innerHTML = `
            <span class="material-symbols-outlined">${type === 'success' ? 'task_alt' : 'error'}</span>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('hide');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
            toast.style.opacity = '0';
        }, 4000);
    };

    const syncInputFiles = () => {
        if (!photoInput) return;
        const dataTransfer = new DataTransfer();
        fileBuffer.forEach((file) => dataTransfer.items.add(file));
        photoInput.files = dataTransfer.files;
    };

    const renderPreview = () => {
        if (!previewContainer) return;
        previewContainer.innerHTML = '';
        fileBuffer.forEach((file, index) => {
            const chip = document.createElement('div');
            chip.className = 'incident-photo-chip';
            chip.innerHTML = `
                <span class="material-symbols-outlined">image</span>
                <span>${file.name}</span>
                <button type="button" data-remove="${index}" aria-label="Șterge imaginea">
                    <span class="material-symbols-outlined">close</span>
                </button>
            `;
            previewContainer.appendChild(chip);
        });
    };

    const clearForm = () => {
        form.reset();
        fileBuffer = [];
        syncInputFiles();
        renderPreview();
        setDefaultDate();
    };

    const handleFileSelection = (event) => {
        const inputFiles = Array.from(event.target.files || []);
        let added = false;

        inputFiles.forEach((file) => {
            if (!allowedTypes.includes(file.type)) {
                createToast('Formatul imaginii nu este acceptat (JPEG/PNG/WebP).', 'error');
                return;
            }
            if (file.size > maxFileSize) {
                createToast(`Fișierul ${file.name} depășește 5MB.`, 'error');
                return;
            }
            if (fileBuffer.length >= maxPhotos) {
                createToast('Ai atins limita maximă de fotografii.', 'error');
                return;
            }
            fileBuffer.push(file);
            added = true;
        });

        if (added) {
            syncInputFiles();
            renderPreview();
        }

        photoInput.value = '';
    };

    const handleRemovePhoto = (event) => {
        const target = event.target.closest('button[data-remove]');
        if (!target) return;
        const index = parseInt(target.dataset.remove || '-1', 10);
        if (Number.isNaN(index)) return;
        fileBuffer.splice(index, 1);
        syncInputFiles();
        renderPreview();
    };

    const buildFormData = () => {
        const formData = new FormData(form);
        formData.delete('photos[]');
        fileBuffer.forEach((file) => formData.append('photos[]', file));
        return formData;
    };

    const handleSubmit = async (event) => {
        event.preventDefault();
        if (!createEndpoint) {
            createToast('Endpoint-ul de raportare nu este configurat.', 'error');
            return;
        }

        const formData = buildFormData();
        if (!formData.get('occurred_at')) {
            createToast('Completează data și ora incidentului.', 'error');
            return;
        }

        submitButton.disabled = true;
        submitButton.classList.add('loading');
        submitButton.querySelector('.material-symbols-outlined').textContent = 'hourglass_bottom';

        try {
            const response = await fetch(createEndpoint, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': csrfToken || ''
                }
            });

            const result = await response.json();
            if (!response.ok || result.success === false) {
                throw new Error(result.message || 'Eroare la raportarea incidentului');
            }

            createToast(result.message || 'Incident raportat cu succes.', 'success');
            clearForm();
            toggleModal(false);
        } catch (error) {
            console.error('Incident report error:', error);
            createToast(error.message || 'Eroare la raportarea incidentului.', 'error');
        } finally {
            submitButton.disabled = false;
            submitButton.classList.remove('loading');
            submitButton.querySelector('.material-symbols-outlined').textContent = 'send';
        }
    };

    const handleCloseClick = (event) => {
        if (event.target.matches('[data-close="modal"]')) {
            toggleModal(false);
        }
    };

    const handleKeyDown = (event) => {
        if (event.key === 'Escape') {
            toggleModal(false);
        }
    };

    if (fab) {
        fab.addEventListener('click', () => {
            toggleModal(true);
        });
    }

    if (modal) {
        modal.addEventListener('click', handleCloseClick);
    }

    if (form) {
        form.addEventListener('submit', handleSubmit);
    }

    if (photoInput) {
        photoInput.addEventListener('change', handleFileSelection);
    }

    if (previewContainer) {
        previewContainer.addEventListener('click', handleRemovePhoto);
    }

    document.addEventListener('keydown', handleKeyDown);
    setDefaultDate();
})();
