(function () {
    const detailModal = document.getElementById('incidentDetailModal');
    const statusModal = document.getElementById('incidentStatusModal');
    const detailBody = document.getElementById('incident-detail-body');
    const statusForm = document.getElementById('incident-status-form');
    const statusSaveBtn = document.getElementById('status-save-btn');
    const incidentDataEl = document.getElementById('incident-data');
    const toastContainer = (() => {
        const container = document.createElement('div');
        container.className = 'admin-toast-container';
        document.body.appendChild(container);
        return container;
    })();

    const updateEndpoint = incidentDataEl?.dataset.updateEndpoint || '';
    const csrfToken = incidentDataEl?.dataset.csrf || '';
    const statusLabels = {
        reported: 'Raportat',
        under_review: 'În Revizuire',
        investigating: 'În Investigare',
        resolved: 'Rezolvat',
        rejected: 'Respins'
    };

    const baseUrl = window.APP_CONFIG?.baseUrl || (window.BASE_URL || '/');

    const openModal = (modal) => {
        if (!modal) return;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    };

    const closeModal = (modal) => {
        if (!modal) return;
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.modal[aria-hidden="false"]')) {
            document.body.classList.remove('modal-open');
        }
    };

    const showToast = (message, type = 'success') => {
        const toast = document.createElement('div');
        toast.className = `admin-toast ${type}`;
        toast.innerHTML = `
            <span class="material-symbols-outlined">${type === 'success' ? 'task_alt' : 'error'}</span>
            <span>${message}</span>
        `;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('hide');
            toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        }, 4000);
    };

    const formatValue = (value) => {
        if (value === null || value === undefined || value === '') {
            return '<span class="text-muted">N/A</span>';
        }
        if (typeof value === 'string') {
            return value.replace(/\n/g, '<br>');
        }
        return value;
    };

    const renderDetailModal = (incident) => {
        if (!detailBody) return;
        const locationInfo = [incident.location_code, incident.location_description]
            .filter(Boolean)
            .join(' - ');
        const photos = Array.isArray(incident.photos) ? incident.photos : [];
        const followUpRequired = incident.follow_up_required === '1' || incident.follow_up_required === 1;
        const photoHtml = photos.length
            ? `<div class="photo-gallery">${photos.map((photo) => {
                    const url = `${baseUrl.replace(/\/$/, '')}/${photo.file_path}`;
                    return `<a href="${url}" target="_blank" rel="noopener noreferrer">
                                <img src="${url}" alt="${photo.original_filename}">
                            </a>`;
                }).join('')}</div>`
            : '<p class="text-muted">Nu există fotografii atașate.</p>';

        detailBody.innerHTML = `
            <div class="detail-layout">
                <section class="detail-summary">
                    <div class="detail-grid">
                        <div class="detail-card">
                            <span class="label">Număr incident</span>
                            <span class="value mono">${incident.incident_number}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Tip</span>
                            <span class="value">${incident.type_label}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Severitate</span>
                            <span class="value">${incident.severity_label}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Status</span>
                            <span class="value">${incident.status_label}</span>
                        </div>
        
                        <div class="detail-card">
                            <span class="label">Raportant</span>
                            <span class="value">${incident.reporter_name} (${incident.reporter_email})</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Data producerii</span>
                            <span class="value">${incident.occurred_at_display}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Data raportării</span>
                            <span class="value">${incident.reported_at_display}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Cost estimativ</span>
                            <span class="value">${incident.estimated_cost_display}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Locație</span>
                            <span class="value">${locationInfo || '<span class="text-muted">Nespecificat</span>'}</span>
                        </div>
                        <div class="detail-card">
                            <span class="label">Acțiuni suplimentare</span>
                            <span class="value">${followUpRequired ? 'Da' : 'Nu'}</span>
                        </div>
                    </div>
                    <div class="detail-card">
                        <span class="label">Descriere</span>
                        <p>${formatValue(incident.description)}</p>
                    </div>
                </section>
                <aside class="detail-meta">
                    <div class="detail-card">
                        <span class="label">Note administrator</span>
                        <p>${formatValue(incident.admin_notes)}</p>
                    </div>
                    <div class="detail-card">
                        <span class="label">Note rezolvare</span>
                        <p>${formatValue(incident.resolution_notes)}</p>
                    </div>
                    <div class="detail-card full-height">
                        <span class="label">Documentare foto</span>
                        ${photoHtml}
                    </div>
                </aside>
            </div>
        `;
    };

    const parseIncident = (element) => {
        const dataset = element.dataset.incident;
        if (!dataset) return null;
        try {
            return JSON.parse(dataset);
        } catch (error) {
            console.error('Nu se poate parsa incidentul:', error);
            return null;
        }
    };

    const updateIncidentDataAttributes = (row, incident) => {
        if (!row) return;
        const incidentJson = JSON.stringify(incident);
        row.querySelectorAll('[data-incident]').forEach((btn) => {
            btn.dataset.incident = incidentJson;
        });
    };

    document.addEventListener('click', (event) => {
        const viewButton = event.target.closest('.view-incident');
        if (viewButton) {
            const incident = parseIncident(viewButton);
            if (incident) {
                renderDetailModal(incident);
                openModal(detailModal);
            }
            return;
        }

        const updateButton = event.target.closest('.update-incident');
        if (updateButton) {
            const incident = parseIncident(updateButton);
            if (incident) {
                statusForm.reset();
                statusForm.querySelector('#status-incident-id').value = incident.id;
                statusForm.querySelector('#status-select').value = incident.status;
                statusForm.querySelector('#status-admin-notes').value = incident.admin_notes || '';
                statusForm.querySelector('#status-resolution-notes').value = incident.resolution_notes || '';
                statusForm.querySelector('#status-follow-up').checked = incident.follow_up_required === '1' || incident.follow_up_required === 1;
                statusForm.dataset.rowSelector = `tr[data-incident-id="${incident.id}"]`;
                openModal(statusModal);
            }
            return;
        }

        const closeTrigger = event.target.closest('[data-modal-close]');
        if (closeTrigger) {
            const modal = closeTrigger.closest('.modal');
            closeModal(modal);
            return;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal[aria-hidden="false"]').forEach((modal) => closeModal(modal));
        }
    });

    const updateTableRow = (row, incident) => {
        if (!row) return;
        const statusBadge = row.querySelector('.badge.status');
        if (statusBadge) {
            statusBadge.textContent = statusLabels[incident.status] || incident.status;
            statusBadge.className = `badge status ${incident.status}`;
        }
        updateIncidentDataAttributes(row, incident);
    };

    const handleStatusSave = async () => {
        if (!statusForm || !updateEndpoint) {
            showToast('Endpoint-ul de actualizare nu este configurat.', 'error');
            return;
        }

        const incidentId = statusForm.querySelector('#status-incident-id').value;
        const statusValue = statusForm.querySelector('#status-select').value;
        const adminNotes = statusForm.querySelector('#status-admin-notes').value.trim();
        const resolutionNotes = statusForm.querySelector('#status-resolution-notes').value.trim();
        const followUp = statusForm.querySelector('#status-follow-up').checked ? 1 : 0;

        if (!incidentId || !statusValue) {
            showToast('Completează toate câmpurile obligatorii.', 'error');
            return;
        }

        statusSaveBtn.disabled = true;
        statusSaveBtn.querySelector('.material-symbols-outlined').textContent = 'hourglass_bottom';

        try {
            const response = await fetch(updateEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    incident_id: incidentId,
                    status: statusValue,
                    admin_notes: adminNotes,
                    resolution_notes: resolutionNotes,
                    follow_up_required: followUp
                })
            });

            const result = await response.json();
            if (!response.ok || result.success === false) {
                throw new Error(result.message || 'Actualizarea statusului a eșuat.');
            }

            const row = document.querySelector(statusForm.dataset.rowSelector || '');
            if (row) {
                const incident = parseIncident(row.querySelector('.view-incident'));
                if (incident) {
                    incident.status = statusValue;
                    incident.status_label = statusLabels[statusValue] || statusValue;
                    incident.admin_notes = adminNotes;
                    incident.resolution_notes = resolutionNotes;
                    incident.follow_up_required = followUp;
                    updateTableRow(row, incident);
                }
            }

            showToast(result.message || 'Status actualizat cu succes.');
            closeModal(statusModal);
        } catch (error) {
            console.error('Status update error:', error);
            showToast(error.message || 'A apărut o eroare la actualizarea statusului.', 'error');
        } finally {
            statusSaveBtn.disabled = false;
            statusSaveBtn.querySelector('.material-symbols-outlined').textContent = 'save';
        }
    };

    if (statusSaveBtn) {
        statusSaveBtn.addEventListener('click', handleStatusSave);
    }
})();
