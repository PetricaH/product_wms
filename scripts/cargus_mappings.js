(() => {
    'use strict';

    const baseUrl = (window.APP_CONFIG && window.APP_CONFIG.baseUrl) || '';
    const apiEndpoints = {
        list: `${baseUrl}/api/cargus_mappings/list.php`,
        search: `${baseUrl}/api/cargus_mappings/search.php`,
        update: `${baseUrl}/api/cargus_mappings/update.php`
    };

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const state = {
        page: 1,
        perPage: 20,
        search: '',
        onlyMissing: false,
        mappings: [],
        matches: [],
        selectedMapping: null,
        selectedMatchIndex: null,
        loading: false
    };

    const elements = {
        tableBody: document.getElementById('mappingsTableBody'),
        pagination: document.getElementById('mappingsPagination'),
        resultsSummary: document.getElementById('resultsSummary'),
        totalMappings: document.getElementById('totalMappings'),
        missingMappings: document.getElementById('missingMappings'),
        verifiedMappings: document.getElementById('verifiedMappings'),
        recentUsage: document.getElementById('recentUsage'),
        searchInput: document.getElementById('searchMappings'),
        perPageSelect: document.getElementById('perPageSelect'),
        onlyMissingToggle: document.getElementById('onlyMissingToggle'),
        refreshButton: document.getElementById('refreshMappings'),
        resetButton: document.getElementById('resetFilters'),
        feedbackBanner: document.getElementById('feedbackBanner'),
        loadingOverlay: document.getElementById('pageLoadingOverlay'),
        modal: document.getElementById('mappingModal'),
        modalTitle: document.getElementById('mappingModalTitle'),
        modalSubtitle: document.getElementById('mappingModalSubtitle'),
        modalCounty: document.getElementById('modalCounty'),
        modalLocality: document.getElementById('modalLocality'),
        modalMeta: document.getElementById('modalMeta'),
        modalAlert: document.getElementById('modalAlert'),
        modalLoading: document.getElementById('modalLoading'),
        modalMatches: document.getElementById('mappingMatches'),
        matchesTableBody: document.getElementById('matchesTableBody'),
        retrySearchButton: document.getElementById('retrySearch'),
        applyToOrders: document.getElementById('applyToOrders'),
        confirmButton: document.getElementById('confirmMapping')
    };

    const formatDateTime = (value) => {
        if (!value) {
            return '—';
        }
        try {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return date.toLocaleString('ro-RO');
        } catch (error) {
            return value;
        }
    };

    const togglePageLoading = (show) => {
        if (!elements.loadingOverlay) {
            return;
        }
        if (show) {
            elements.loadingOverlay.removeAttribute('hidden');
        } else {
            elements.loadingOverlay.setAttribute('hidden', 'hidden');
        }
    };

    const showFeedback = (message, type = 'success') => {
        if (!elements.feedbackBanner) {
            return;
        }

        elements.feedbackBanner.textContent = message;
        elements.feedbackBanner.classList.remove('feedback-banner--success', 'feedback-banner--error', 'is-visible');

        if (type === 'success') {
            elements.feedbackBanner.classList.add('feedback-banner--success');
        } else {
            elements.feedbackBanner.classList.add('feedback-banner--error');
        }

        elements.feedbackBanner.classList.add('is-visible');
    };

    const hideFeedback = () => {
        if (!elements.feedbackBanner) {
            return;
        }
        elements.feedbackBanner.classList.remove('is-visible', 'feedback-banner--success', 'feedback-banner--error');
        elements.feedbackBanner.textContent = '';
    };

    const renderStats = (stats) => {
        if (!stats) {
            return;
        }
        if (elements.totalMappings) {
            elements.totalMappings.textContent = stats.total ?? '0';
        }
        if (elements.missingMappings) {
            elements.missingMappings.textContent = stats.missing ?? '0';
        }
        if (elements.verifiedMappings) {
            elements.verifiedMappings.textContent = stats.verified ?? '0';
        }
        if (elements.recentUsage) {
            elements.recentUsage.textContent = stats.recent_usage ?? '0';
        }
    };

    const renderMappings = (mappings) => {
        if (!elements.tableBody) {
            return;
        }

        elements.tableBody.innerHTML = '';

        if (!Array.isArray(mappings) || mappings.length === 0) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 7;
            cell.className = 'table-placeholder';
            cell.textContent = 'Nu există mapări pentru criteriile selectate.';
            row.appendChild(cell);
            elements.tableBody.appendChild(row);
            return;
        }

        mappings.forEach((mapping) => {
            const row = document.createElement('tr');
            const needsAttention = !mapping.cargus_locality_id;
            if (needsAttention) {
                row.classList.add('mapping-row--missing');
            }

            const confidenceLabel = mapping.mapping_confidence ? mapping.mapping_confidence.toUpperCase() : 'NECUNOSCUT';
            const verifiedBadge = mapping.is_verified ? '<span class="badge badge--verified">Verificat</span>' : '<span class="badge badge--missing">Necesită verificare</span>';

            row.innerHTML = `
                <td>${mapping.county_name ?? '—'}</td>
                <td>${mapping.locality_name ?? '—'}</td>
                <td>${mapping.cargus_county_name ?? '—'}</td>
                <td>${mapping.cargus_locality_name ?? '—'}</td>
                <td>${mapping.cargus_postal_code ?? '—'}</td>
                <td>
                    <div class="mapping-confidence">
                        <span class="badge">${confidenceLabel}</span>
                        ${verifiedBadge}
                    </div>
                </td>
                <td class="table-actions">
                    <button class="btn btn-primary btn-sm" type="button" data-action="fix-mapping" data-id="${mapping.id}">
                        <span class="material-symbols-outlined">handyman</span>
                        Rezolvă
                    </button>
                </td>
            `;

            elements.tableBody.appendChild(row);
        });
    };

    const renderPagination = (pagination) => {
        if (!elements.pagination) {
            return;
        }

        elements.pagination.innerHTML = '';

        if (!pagination || pagination.total_pages <= 1) {
            return;
        }

        const createPageButton = (label, page, disabled = false, isActive = false) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            if (isActive) {
                button.classList.add('active');
            }
            if (disabled) {
                button.disabled = true;
            } else {
                button.addEventListener('click', () => {
                    state.page = page;
                    fetchMappings();
                });
            }
            return button;
        };

        const prevButton = createPageButton('‹', Math.max(1, pagination.page - 1), pagination.page === 1);
        elements.pagination.appendChild(prevButton);

        for (let page = 1; page <= pagination.total_pages; page += 1) {
            if (page === 1 || page === pagination.total_pages || Math.abs(page - pagination.page) <= 1) {
                const pageButton = createPageButton(page, page, false, page === pagination.page);
                elements.pagination.appendChild(pageButton);
            } else if (page === 2 && pagination.page > 3) {
                const dots = document.createElement('span');
                dots.textContent = '…';
                elements.pagination.appendChild(dots);
            } else if (page === pagination.total_pages - 1 && pagination.page < pagination.total_pages - 2) {
                const dots = document.createElement('span');
                dots.textContent = '…';
                elements.pagination.appendChild(dots);
            }
        }

        const nextButton = createPageButton('›', Math.min(pagination.total_pages, pagination.page + 1), pagination.page === pagination.total_pages);
        elements.pagination.appendChild(nextButton);
    };

    const renderResultsSummary = (pagination) => {
        if (!elements.resultsSummary || !pagination) {
            return;
        }
        const total = Number(pagination.total ?? 0);
        if (total === 0) {
            elements.resultsSummary.textContent = 'Nu există mapări pentru criteriile selectate.';
            return;
        }
        const start = (pagination.page - 1) * pagination.per_page + 1;
        const end = Math.min(pagination.page * pagination.per_page, total);
        elements.resultsSummary.textContent = `Afișate ${start} – ${end} din ${total} mapări`;
    };

    const fetchMappings = async () => {
        state.loading = true;
        togglePageLoading(state.page === 1);
        hideFeedback();

        try {
            const url = new URL(apiEndpoints.list, window.location.origin);
            url.searchParams.set('page', state.page.toString());
            url.searchParams.set('per_page', state.perPage.toString());
            if (state.search) {
                url.searchParams.set('search', state.search);
            }
            if (state.onlyMissing) {
                url.searchParams.set('only_missing', '1');
            }

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || 'Nu am putut încărca mapările');
            }

            state.mappings = data.data || [];
            renderMappings(state.mappings);
            renderPagination(data.pagination);
            renderResultsSummary(data.pagination);
            renderStats(data.stats);
        } catch (error) {
            console.error('Cargus mappings fetch error', error);
            showFeedback(error.message || 'Nu am putut încărca mapările', 'error');
            renderMappings([]);
            renderPagination(null);
            renderResultsSummary({ page: 1, per_page: 0, total: 0 });
        } finally {
            state.loading = false;
            togglePageLoading(false);
        }
    };

    const closeModal = () => {
        if (!elements.modal) {
            return;
        }
        elements.modal.classList.remove('is-open');
        elements.modal.setAttribute('aria-hidden', 'true');
        elements.matchesTableBody.innerHTML = '';
        elements.modalAlert.setAttribute('hidden', 'hidden');
        elements.modalMatches.setAttribute('hidden', 'hidden');
        elements.modalLoading.setAttribute('hidden', 'hidden');
        elements.confirmButton.disabled = true;
        state.matches = [];
        state.selectedMapping = null;
        state.selectedMatchIndex = null;
    };

    const openModal = (mapping) => {
        if (!elements.modal) {
            return;
        }

        state.selectedMapping = mapping;
        state.selectedMatchIndex = null;
        state.matches = [];

        elements.modalCounty.textContent = mapping.county_name ?? '';
        elements.modalLocality.textContent = mapping.locality_name ?? '';
        elements.modalSubtitle.textContent = mapping.cargus_locality_id
            ? 'Actualizează codurile Cargus dacă există o potrivire mai bună.'
            : 'Nu există cod Cargus asociat. Selectează mai jos varianta corectă.';
        elements.modalMeta.textContent = `Ultima actualizare: ${formatDateTime(mapping.updated_at)} · Utilizat de ${mapping.usage_count ?? 0} ori`;

        elements.modalAlert.setAttribute('hidden', 'hidden');
        elements.modalMatches.setAttribute('hidden', 'hidden');
        elements.modalLoading.removeAttribute('hidden');
        elements.confirmButton.disabled = true;

        elements.modal.classList.add('is-open');
        elements.modal.setAttribute('aria-hidden', 'false');

        fetchMatches();
    };

    const renderMatches = (matches) => {
        elements.matchesTableBody.innerHTML = '';

        if (!Array.isArray(matches) || matches.length === 0) {
            elements.modalAlert.textContent = 'Nu am găsit potriviri pentru această localitate în Cargus.';
            elements.modalAlert.removeAttribute('hidden');
            elements.modalMatches.setAttribute('hidden', 'hidden');
            return;
        }

        elements.modalAlert.setAttribute('hidden', 'hidden');
        elements.modalMatches.removeAttribute('hidden');

        matches.forEach((match, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="radio" name="cargusMatch" value="${index}" data-index="${index}">
                </td>
                <td>${match.county_name}</td>
                <td>${match.locality_name}</td>
                <td>${match.postal_code ?? '—'}</td>
                <td>${match.score}%</td>
            `;
            elements.matchesTableBody.appendChild(row);
        });
    };

    const fetchMatches = async () => {
        if (!state.selectedMapping) {
            return;
        }

        elements.modalLoading.removeAttribute('hidden');
        elements.modalMatches.setAttribute('hidden', 'hidden');
        elements.modalAlert.setAttribute('hidden', 'hidden');
        elements.confirmButton.disabled = true;

        try {
            const url = new URL(apiEndpoints.search, window.location.origin);
            url.searchParams.set('county', state.selectedMapping.county_name ?? '');
            url.searchParams.set('locality', state.selectedMapping.locality_name ?? '');
            url.searchParams.set('mapping_id', state.selectedMapping.id);

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                const message = data.error || 'Nu am putut căuta localitatea în API-ul Cargus';
                throw new Error(message);
            }

            state.matches = data.matches || [];
            renderMatches(state.matches);
        } catch (error) {
            console.error('Cargus mapping search error', error);
            elements.modalAlert.textContent = error.message || 'Nu am putut căuta localitatea în API-ul Cargus';
            elements.modalAlert.removeAttribute('hidden');
        } finally {
            elements.modalLoading.setAttribute('hidden', 'hidden');
        }
    };

    const submitMappingUpdate = async () => {
        if (state.selectedMatchIndex === null || state.selectedMatchIndex === undefined) {
            showFeedback('Selectează mai întâi o potrivire Cargus.', 'error');
            return;
        }

        const selectedMatch = state.matches[state.selectedMatchIndex];
        if (!selectedMatch || !state.selectedMapping) {
            showFeedback('Nu am putut identifica potrivirea selectată.', 'error');
            return;
        }

        elements.confirmButton.disabled = true;
        elements.confirmButton.classList.add('is-loading');

        try {
            const response = await fetch(apiEndpoints.update, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    mapping_id: state.selectedMapping.id,
                    county_id: selectedMatch.county_id,
                    locality_id: selectedMatch.locality_id,
                    county_name: selectedMatch.county_name,
                    locality_name: selectedMatch.locality_name,
                    postal_code: selectedMatch.postal_code,
                    update_orders: elements.applyToOrders?.checked ?? false
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Nu am putut salva maparea selectată');
            }

            closeModal();
            await fetchMappings();

            const ordersInfo = typeof data.orders_updated === 'number'
                ? ` · Comenzi actualizate: ${data.orders_updated}`
                : '';
            showFeedback(`Maparea a fost actualizată cu succes${ordersInfo}.`, 'success');
        } catch (error) {
            console.error('Cargus mapping update error', error);
            elements.confirmButton.disabled = false;
            showFeedback(error.message || 'Nu am putut salva maparea selectată', 'error');
        } finally {
            elements.confirmButton.classList.remove('is-loading');
        }
    };

    const handleTableClick = (event) => {
        const target = event.target.closest('[data-action="fix-mapping"]');
        if (!target) {
            return;
        }
        const mappingId = Number(target.dataset.id);
        const mapping = state.mappings.find((item) => Number(item.id) === mappingId);
        if (mapping) {
            openModal(mapping);
        }
    };

    const handleMatchSelection = (event) => {
        if (event.target.name !== 'cargusMatch') {
            return;
        }
        const index = Number(event.target.dataset.index);
        if (!Number.isNaN(index)) {
            state.selectedMatchIndex = index;
            elements.confirmButton.disabled = false;
        }
    };

    const initEvents = () => {
        if (elements.tableBody) {
            elements.tableBody.addEventListener('click', handleTableClick);
        }

        if (elements.matchesTableBody) {
            elements.matchesTableBody.addEventListener('change', handleMatchSelection);
        }

        if (elements.retrySearchButton) {
            elements.retrySearchButton.addEventListener('click', fetchMatches);
        }

        if (elements.confirmButton) {
            elements.confirmButton.addEventListener('click', submitMappingUpdate);
        }

        document.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', closeModal);
        });

        if (elements.modal) {
            elements.modal.addEventListener('click', (event) => {
                if (event.target === elements.modal.querySelector('.mapping-modal__backdrop')) {
                    closeModal();
                }
            });
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && elements.modal?.classList.contains('is-open')) {
                closeModal();
            }
        });

        if (elements.refreshButton) {
            elements.refreshButton.addEventListener('click', () => {
                fetchMappings();
            });
        }

        if (elements.resetButton) {
            elements.resetButton.addEventListener('click', () => {
                elements.searchInput.value = '';
                elements.perPageSelect.value = '20';
                elements.onlyMissingToggle.checked = false;
                state.search = '';
                state.perPage = 20;
                state.onlyMissing = false;
                state.page = 1;
                fetchMappings();
            });
        }

        if (elements.searchInput) {
            let debounceTimer;
            elements.searchInput.addEventListener('input', (event) => {
                clearTimeout(debounceTimer);
                const value = event.target.value.trim();
                debounceTimer = setTimeout(() => {
                    state.search = value;
                    state.page = 1;
                    fetchMappings();
                }, 300);
            });
        }

        if (elements.perPageSelect) {
            elements.perPageSelect.addEventListener('change', (event) => {
                state.perPage = Number(event.target.value) || 20;
                state.page = 1;
                fetchMappings();
            });
        }

        if (elements.onlyMissingToggle) {
            elements.onlyMissingToggle.addEventListener('change', (event) => {
                state.onlyMissing = event.target.checked;
                state.page = 1;
                fetchMappings();
            });
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        initEvents();
        fetchMappings();
    });
})();
