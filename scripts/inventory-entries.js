(function () {
    const appConfig = window.APP_CONFIG || {};
    const baseUrl = typeof appConfig.baseUrl === 'string' ? appConfig.baseUrl.replace(/\/$/, '') : '';
    const uploadEndpoint = baseUrl ? `${baseUrl}/api/purchase_orders/upload_invoice.php` : 'api/purchase_orders/upload_invoice.php';
    const verificationEndpoint = baseUrl ? `${baseUrl}/api/purchase_orders/toggle_invoice_verification.php` : 'api/purchase_orders/toggle_invoice_verification.php';
    const noteEndpoint = baseUrl ? `${baseUrl}/api/receiving/update_entry_notes.php` : 'api/receiving/update_entry_notes.php';

    const notificationEl = document.getElementById('entries-notification');
    const uploadInput = document.getElementById('entries-invoice-upload');
    let currentUploadContext = null;
    let notificationTimeout = null;

    function showNotification(message, type = 'info', persistent = false) {
        if (!notificationEl) {
            return;
        }

        notificationEl.textContent = message;
        notificationEl.className = `entries-notification entries-notification--${type}`;

        if (notificationTimeout) {
            clearTimeout(notificationTimeout);
            notificationTimeout = null;
        }

        if (!persistent) {
            notificationTimeout = window.setTimeout(() => {
                notificationEl.textContent = '';
                notificationEl.className = 'entries-notification';
            }, type === 'error' ? 6000 : 4000);
        }
    }

    function parseJsonSafe(value, fallback) {
        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    function setEditorValue(editor, value) {
        if (!editor) {
            return;
        }

        if (!value) {
            editor.innerHTML = '';
            return;
        }

        const fragment = document.createDocumentFragment();
        const lines = String(value).split('\n');
        lines.forEach((line, index) => {
            if (index > 0) {
                fragment.appendChild(document.createElement('br'));
            }
            fragment.appendChild(document.createTextNode(line));
        });
        editor.innerHTML = '';
        editor.appendChild(fragment);
    }

    function normalizeEditorValue(editor) {
        if (!editor) {
            return '';
        }

        const text = editor.innerText.replace(/\u00A0/g, ' ');
        return text
            .replace(/\r\n/g, '\n')
            .split('\n')
            .map(line => line.replace(/\s+$/g, ''))
            .join('\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function handleInvoiceUploadSuccess(context, result) {
        if (!context) {
            return;
        }

        const { row, invoiceCell, actionsContainer } = context;
        const invoiceUrl = result.invoice_url || '';
        const invoiceFilename = result.invoice_filename || 'Factura';

        if (invoiceCell && invoiceUrl) {
            const link = document.createElement('a');
            link.href = invoiceUrl;
            link.target = '_blank';
            link.className = 'invoice-link';
            link.innerHTML = `<span class="material-symbols-outlined">description</span>${invoiceFilename}`;
            invoiceCell.innerHTML = '';
            invoiceCell.appendChild(link);
        }

        if (row) {
            row.querySelectorAll('.entry-upload-invoice-btn').forEach(btn => btn.remove());

            const wrapper = row.querySelector('.invoice-verified-toggle-wrapper');
            const checkbox = wrapper ? wrapper.querySelector('.invoice-verified-toggle') : null;
            if (wrapper && checkbox) {
                wrapper.classList.remove('is-disabled');
                checkbox.disabled = false;
                checkbox.dataset.invoicePresent = '1';
                const srOnly = wrapper.querySelector('.sr-only');
                if (srOnly) {
                    srOnly.textContent = 'Marchează factura ca verificată';
                }
            }

            if (actionsContainer && invoiceUrl && !actionsContainer.querySelector('.entries-download-invoice')) {
                const downloadLink = document.createElement('a');
                downloadLink.href = invoiceUrl;
                downloadLink.target = '_blank';
                downloadLink.className = 'btn btn-outline-primary btn-sm entries-download-invoice';
                downloadLink.innerHTML = '<span class="material-symbols-outlined">download</span>Descarcă';
                actionsContainer.appendChild(downloadLink);
            }
        }
    }

    function attachInvoiceUploadHandlers() {
        if (!uploadInput) {
            return;
        }

        document.querySelectorAll('.entry-upload-invoice-btn').forEach(button => {
            button.addEventListener('click', () => {
                const orderId = Number(button.dataset.orderId || 0);
                if (!orderId) {
                    showNotification('Nu există o comandă de achiziție asociată pentru această recepție.', 'error', true);
                    return;
                }

                const row = button.closest('tr');
                currentUploadContext = {
                    orderId,
                    row,
                    invoiceCell: row ? row.querySelector('.receiving-entry-cell--invoice') : null,
                    actionsContainer: row ? row.querySelector('.receiving-entry-cell--actions .entries-actions') : null,
                };

                uploadInput.value = '';
                uploadInput.click();
            });
        });

        uploadInput.addEventListener('change', async (event) => {
            const file = event.target.files ? event.target.files[0] : null;
            if (!file || !currentUploadContext) {
                currentUploadContext = null;
                return;
            }

            const formData = new FormData();
            formData.append('order_id', String(currentUploadContext.orderId));
            formData.append('invoice_file', file);

            showNotification('Se încarcă factura...', 'info', true);

            try {
                const response = await fetch(uploadEndpoint, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                });
                const result = await response.json().catch(() => null);

                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.message) || 'Eroare la încărcarea facturii.');
                }

                showNotification(result.message || 'Factura a fost încărcată cu succes.', 'success');
                handleInvoiceUploadSuccess(currentUploadContext, result);
            } catch (error) {
                console.error('Invoice upload failed', error);
                showNotification(error.message || 'Eroare la încărcarea facturii.', 'error', true);
            } finally {
                uploadInput.value = '';
                currentUploadContext = null;
            }
        });
    }

    function attachVerificationHandlers() {
        document.querySelectorAll('.invoice-verified-toggle').forEach(checkbox => {
            checkbox.addEventListener('change', async () => {
                const orderId = Number(checkbox.dataset.orderId || 0);
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

                checkbox.disabled = true;

                try {
                    const response = await fetch(verificationEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            order_id: orderId,
                            verified: Boolean(isChecked),
                        }),
                    });

                    const result = await response.json().catch(() => null);
                    if (!response.ok || !result || !result.success) {
                        throw new Error((result && result.message) || 'Eroare la actualizarea verificării.');
                    }

                    showNotification(result.message || 'Statusul facturii a fost actualizat.', 'success');

                    const wrapper = checkbox.closest('.invoice-verified-toggle-wrapper');
                    if (wrapper) {
                        wrapper.classList.toggle('is-verified', Boolean(result.verified));
                        const srOnly = wrapper.querySelector('.sr-only');
                        if (srOnly) {
                            srOnly.textContent = result.verified
                                ? 'Debifează marcarea facturii ca verificată'
                                : 'Marchează factura ca verificată';
                        }
                    }

                    const cell = checkbox.closest('td');
                    if (!cell) {
                        return;
                    }

                    let metaContainer = cell.querySelector('.invoice-verified-meta');
                    if (!metaContainer && result.verified) {
                        metaContainer = document.createElement('div');
                        metaContainer.className = 'invoice-verified-meta';
                        cell.appendChild(metaContainer);
                    }

                    if (metaContainer) {
                        if (result.verified) {
                            metaContainer.innerHTML = '';

                            const icon = document.createElement('span');
                            icon.className = 'material-symbols-outlined';
                            icon.textContent = 'task_alt';
                            metaContainer.appendChild(icon);

                            if (result.verified_at) {
                                const dateSpan = document.createElement('span');
                                dateSpan.textContent = result.verified_at;
                                metaContainer.appendChild(dateSpan);
                            }

                            if (result.verified_by) {
                                const userSpan = document.createElement('span');
                                userSpan.textContent = `de ${result.verified_by}`;
                                metaContainer.appendChild(userSpan);
                            }
                        } else {
                            metaContainer.innerHTML = '';
                        }
                    }
                } catch (error) {
                    console.error('Verification toggle failed', error);
                    checkbox.checked = !isChecked;
                    showNotification(error.message || 'Eroare la actualizarea verificării.', 'error');
                } finally {
                    checkbox.disabled = false;
                }
            });
        });
    }

    function attachNotesEditors() {
        const editors = document.querySelectorAll('.entry-notes-editor');
        editors.forEach(editor => {
            if (editor.__entryNotesBound) {
                return;
            }
            editor.__entryNotesBound = true;

            const itemId = Number(editor.dataset.itemId || 0);
            if (!itemId) {
                return;
            }

            const statusEl = document.querySelector(`.entry-notes-status[data-item-id="${itemId}"]`);
            let saveTimeout = null;
            let isSaving = false;
            let pendingValue = null;

            const initialValue = editor.dataset.originalValue || normalizeEditorValue(editor);
            editor.dataset.originalValue = initialValue;
            setEditorValue(editor, initialValue);

            let lastSavedValue = initialValue;

            const scheduleSave = (immediate = false) => {
                const currentValue = normalizeEditorValue(editor);

                if (currentValue === lastSavedValue) {
                    pendingValue = null;
                    editor.classList.remove('is-dirty', 'is-saving', 'is-error', 'is-success');
                    if (statusEl && !isSaving) {
                        statusEl.textContent = '';
                        statusEl.classList.remove('is-saving', 'is-error', 'is-success');
                    }
                    return;
                }

                if (isSaving) {
                    pendingValue = currentValue;
                    editor.classList.add('is-dirty');
                    return;
                }

                if (saveTimeout) {
                    clearTimeout(saveTimeout);
                }

                editor.classList.add('is-dirty');
                if (statusEl) {
                    statusEl.textContent = 'Modificări nesalvate...';
                    statusEl.classList.add('is-saving');
                    statusEl.classList.remove('is-error', 'is-success');
                }

                saveTimeout = window.setTimeout(() => {
                    saveTimeout = null;
                    persistValue(currentValue);
                }, immediate ? 0 : 700);
            };

            const persistValue = async (valueToSave) => {
                isSaving = true;
                editor.classList.remove('is-dirty', 'is-error', 'is-success');
                editor.classList.add('is-saving');

                if (statusEl) {
                    statusEl.textContent = 'Se salvează...';
                    statusEl.classList.add('is-saving');
                    statusEl.classList.remove('is-error', 'is-success');
                }

                try {
                    const response = await fetch(noteEndpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            receiving_item_id: itemId,
                            notes: valueToSave,
                        }),
                    });

                    const result = await response.json().catch(() => null);
                    if (!response.ok || !result || !result.success) {
                        throw new Error((result && result.message) || 'Eroare la salvarea observațiilor.');
                    }

                    const responseNotes = typeof result.notes === 'string' ? result.notes : '';
                    lastSavedValue = responseNotes;
                    editor.dataset.originalValue = lastSavedValue;
                    editor.classList.remove('is-saving');
                    editor.classList.add('is-success');
                    setEditorValue(editor, lastSavedValue);

                    if (statusEl) {
                        statusEl.textContent = lastSavedValue ? 'Observații salvate.' : 'Fără observații';
                        statusEl.classList.remove('is-saving', 'is-error');
                        statusEl.classList.add('is-success');
                    }

                    if (pendingValue !== null && pendingValue !== lastSavedValue) {
                        const nextValue = pendingValue;
                        pendingValue = null;
                        persistValue(nextValue);
                        return;
                    }

                    pendingValue = null;
                    editor.classList.remove('is-dirty');

                    window.setTimeout(() => {
                        editor.classList.remove('is-success');
                        if (statusEl && statusEl.classList.contains('is-success')) {
                            statusEl.textContent = '';
                            statusEl.classList.remove('is-success');
                        }
                    }, 1800);
                } catch (error) {
                    console.error('Failed to save receiving notes', error);
                    editor.classList.remove('is-saving');
                    editor.classList.add('is-error');

                    if (statusEl) {
                        statusEl.textContent = error.message || 'Eroare la salvarea observațiilor.';
                        statusEl.classList.remove('is-saving', 'is-success');
                        statusEl.classList.add('is-error');
                    }

                    showNotification(error.message || 'Eroare la salvarea observațiilor.', 'error', true);
                } finally {
                    isSaving = false;
                    if (!editor.textContent.trim()) {
                        editor.innerHTML = '';
                    }
                }
            };

            editor.addEventListener('input', () => {
                const sanitized = normalizeEditorValue(editor);
                if (!sanitized) {
                    editor.innerHTML = '';
                }
                pendingValue = sanitized;
                scheduleSave(false);
            });

            editor.addEventListener('blur', () => {
                const sanitized = normalizeEditorValue(editor);
                setEditorValue(editor, sanitized);
                pendingValue = sanitized;
                scheduleSave(true);
            });
        });
    }

    function setupPhotoModal() {
        const photoModal = document.getElementById('receiving-photo-modal');
        if (!photoModal) {
            return;
        }

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
            photos = Array.isArray(photoList) ? photoList : [];
            currentIndex = index >= 0 && index < photos.length ? index : 0;
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
                openModal(data, index);
            });
        });

        photoModal.querySelectorAll('[data-modal-dismiss]').forEach(el => {
            el.addEventListener('click', closeModal);
        });

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (!photos.length) {
                    return;
                }
                currentIndex = (currentIndex - 1 + photos.length) % photos.length;
                renderPhoto();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (!photos.length) {
                    return;
                }
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

    attachInvoiceUploadHandlers();
    attachVerificationHandlers();
    attachNotesEditors();
    setupPhotoModal();
})();
