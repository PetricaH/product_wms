(function () {
    const appConfig = window.APP_CONFIG || {};
    const baseUrl = typeof appConfig.baseUrl === 'string' ? appConfig.baseUrl.replace(/\/$/, '') : '';
    const uploadEndpoint = baseUrl ? `${baseUrl}/api/purchase_orders/upload_invoice.php` : 'api/purchase_orders/upload_invoice.php';
    const verificationEndpoint = baseUrl ? `${baseUrl}/api/purchase_orders/toggle_invoice_verification.php` : 'api/purchase_orders/toggle_invoice_verification.php';

    const notificationEl = document.getElementById('entries-notification');
    const uploadInput = document.getElementById('entries-invoice-upload');
    let currentOrderId = null;

    function showNotification(message, type = 'info') {
        if (!notificationEl) {
            return;
        }
        notificationEl.textContent = message;
        notificationEl.className = `entries-notification entries-notification--${type}`;
    }

    function parseJsonSafe(value, fallback) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    if (uploadInput) {
        document.querySelectorAll('.entry-upload-invoice-btn').forEach(button => {
            button.addEventListener('click', () => {
                const orderId = button.dataset.orderId;
                if (!orderId) {
                    showNotification('Nu există o comandă de achiziție asociată pentru această recepție.', 'error');
                    return;
                }
                currentOrderId = orderId;
                uploadInput.value = '';
                uploadInput.click();
            });
        });

        uploadInput.addEventListener('change', async (event) => {
            const file = event.target.files[0];
            if (!file || !currentOrderId) {
                return;
            }

            const formData = new FormData();
            formData.append('order_id', currentOrderId);
            formData.append('invoice_file', file);

            showNotification('Se încarcă factura...', 'info');

            try {
                const response = await fetch(uploadEndpoint, {
                    method: 'POST',
                    body: formData,
                });
                const result = await response.json();
                if (response.ok && result && result.success) {
                    showNotification(result.message || 'Factura a fost încărcată cu succes.', 'success');
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showNotification((result && result.message) || 'Eroare la încărcarea facturii.', 'error');
                }
            } catch (error) {
                console.error('Invoice upload failed', error);
                showNotification('Eroare la comunicarea cu serverul.', 'error');
            } finally {
                uploadInput.value = '';
                currentOrderId = null;
            }
        });
    }

    document.querySelectorAll('.invoice-verified-toggle').forEach(checkbox => {
        checkbox.addEventListener('change', async () => {
            const orderId = checkbox.dataset.orderId;
            const invoicePresent = checkbox.dataset.invoicePresent === '1';
            const isChecked = checkbox.checked;

            if (!orderId) {
                checkbox.checked = !isChecked;
                showNotification('Nu se poate actualiza verificarea fără o comandă de achiziție.', 'error');
                return;
            }

            if (!invoicePresent) {
                checkbox.checked = false;
                showNotification('Încărcați mai întâi factura pentru a marca verificarea.', 'info');
                return;
            }

            const payload = {
                order_id: Number(orderId),
                verified: Boolean(isChecked),
            };

            checkbox.disabled = true;

            try {
                const response = await fetch(verificationEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                let result = null;
                try {
                    result = await response.json();
                } catch (error) {
                    result = null;
                }

                if (response.ok && result && result.success) {
                    showNotification(result.message || 'Statusul facturii a fost actualizat.', 'success');

                    const label = checkbox.nextElementSibling;
                    if (label) {
                        label.textContent = result.verified ? 'Verificată' : 'Neverificată';
                    }

                    let metaContainer = checkbox.closest('td').querySelector('.invoice-verified-meta');
                    if (!metaContainer && result.verified) {
                        metaContainer = document.createElement('div');
                        metaContainer.className = 'invoice-verified-meta';
                        checkbox.closest('td').appendChild(metaContainer);
                    }

                    if (metaContainer) {
                        if (result.verified) {
                            metaContainer.innerHTML = '';
                            const icon = document.createElement('span');
                            icon.className = 'material-symbols-outlined';
                            icon.textContent = 'task_alt';
                            metaContainer.appendChild(icon);

                            const dateSpan = document.createElement('span');
                            dateSpan.textContent = result.verified_at || '';
                            metaContainer.appendChild(dateSpan);

                            if (result.verified_by) {
                                const userSpan = document.createElement('span');
                                userSpan.textContent = `de ${result.verified_by}`;
                                metaContainer.appendChild(userSpan);
                            }
                            metaContainer.classList.add('is-visible');
                        } else {
                            metaContainer.innerHTML = '';
                            metaContainer.classList.remove('is-visible');
                        }
                    }
                } else {
                    checkbox.checked = !isChecked;
                    showNotification((result && result.message) || 'Eroare la actualizarea verificării.', 'error');
                }
            } catch (error) {
                console.error('Verification toggle failed', error);
                checkbox.checked = !isChecked;
                showNotification('Eroare la comunicarea cu serverul.', 'error');
            } finally {
                checkbox.disabled = false;
            }
        });
    });

    const photoModal = document.getElementById('receiving-photo-modal');
    if (photoModal) {
        const modalImage = document.getElementById('receiving-photo-modal-image');
        const modalFilename = document.getElementById('receiving-photo-modal-filename');
        const modalCounter = document.getElementById('receiving-photo-modal-counter');
        const prevBtn = photoModal.querySelector('[data-modal-nav="prev"]');
        const nextBtn = photoModal.querySelector('[data-modal-nav="next"]');
        let photos = [];
        let currentIndex = 0;

        const closeModal = () => {
            photoModal.classList.remove('is-visible');
            photoModal.setAttribute('aria-hidden', 'true');
            photos = [];
            currentIndex = 0;
        };

        const renderPhoto = () => {
            if (!photos.length) {
                closeModal();
                return;
            }
            const currentPhoto = photos[currentIndex];
            modalImage.src = currentPhoto.url;
            modalImage.alt = currentPhoto.filename || 'Imagine recepție';
            modalFilename.textContent = currentPhoto.filename || '';
            modalCounter.textContent = `${currentIndex + 1} / ${photos.length}`;
            photoModal.classList.add('is-visible');
            photoModal.setAttribute('aria-hidden', 'false');
        };

        const openModal = (photoList, index) => {
            photos = photoList;
            currentIndex = index;
            renderPhoto();
        };

        document.querySelectorAll('.entry-photo-thumb').forEach(button => {
            button.addEventListener('click', () => {
                const data = button.dataset.photos ? parseJsonSafe(button.dataset.photos, []) : [];
                if (!Array.isArray(data) || !data.length) {
                    showNotification('Nu există imagini pentru această recepție.', 'info');
                    return;
                }
                const index = Number(button.dataset.index || 0);
                openModal(data, index >= 0 && index < data.length ? index : 0);
            });
        });

        photoModal.querySelectorAll('[data-modal-dismiss]').forEach(el => {
            el.addEventListener('click', closeModal);
        });

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (!photos.length) return;
                currentIndex = (currentIndex - 1 + photos.length) % photos.length;
                renderPhoto();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (!photos.length) return;
                currentIndex = (currentIndex + 1) % photos.length;
                renderPhoto();
            });
        }

        document.addEventListener('keydown', (event) => {
            if (!photoModal.classList.contains('is-visible')) {
                return;
            }
            if (event.key === 'Escape') {
                closeModal();
            } else if (event.key === 'ArrowRight' && nextBtn) {
                nextBtn.click();
            } else if (event.key === 'ArrowLeft' && prevBtn) {
                prevBtn.click();
            }
        });
    }
})();
