// File: scripts/orders_awb_tracking.js
// Handles expandable AWB tracking timeline rendering on the orders page

(function() {
    const timelineCache = new Map();
    const DEFAULT_EMPTY_MESSAGE = 'Nu există evenimente înregistrate pentru acest AWB.';

    document.addEventListener('DOMContentLoaded', () => {
        document.addEventListener('click', handleToggleClick, true);
        window.addEventListener('resize', debounce(resizeVisibleTimelines, 200));
    });

    function handleToggleClick(event) {
        const toggle = event.target.closest('.awb-timeline-toggle');
        if (toggle) {
            event.preventDefault();
            toggleTimeline(toggle);
            return;
        }

        const closeButton = event.target.closest('.awb-timeline-close');
        if (closeButton) {
            event.preventDefault();
            collapseTimeline(closeButton.dataset.orderId);
        }
    }

    function toggleTimeline(toggle) {
        const orderId = toggle.getAttribute('data-order-id');
        const awb = toggle.getAttribute('data-awb');
        if (!orderId || !awb) {
            return;
        }

        const row = document.querySelector(`.awb-timeline-row[data-order-id="${safeCssEscape(orderId)}"]`);
        if (!row) {
            return;
        }

        const isVisible = row.classList.contains('is-visible');
        if (isVisible) {
            collapseTimeline(orderId);
        } else {
            expandTimeline(orderId, awb, toggle, row);
        }
    }

    function expandTimeline(orderId, awb, toggle, row) {
        row.style.display = 'table-row';
        requestAnimationFrame(() => {
            row.classList.add('is-visible');
            const panel = row.querySelector('.awb-timeline-panel');
            panel?.classList.add('is-expanded');
            toggle.setAttribute('data-expanded', 'true');
        });

        loadTimelineData(orderId, awb, row)
            .catch((error) => {
                console.error('Nu s-a putut încărca cronologia AWB:', error);
            });
    }

    function collapseTimeline(orderId) {
        const row = document.querySelector(`.awb-timeline-row[data-order-id="${safeCssEscape(orderId)}"]`);
        if (!row) {
            return;
        }

        const toggle = document.querySelector(`.awb-timeline-toggle[data-order-id="${safeCssEscape(orderId)}"]`);
        const panel = row.querySelector('.awb-timeline-panel');

        row.classList.remove('is-visible');
        panel?.classList.remove('is-expanded');
        toggle?.setAttribute('data-expanded', 'false');

        const onTransitionEnd = (event) => {
            if (event.propertyName === 'max-height') {
                row.style.display = 'none';
                panel?.removeEventListener('transitionend', onTransitionEnd);
            }
        };

        panel?.addEventListener('transitionend', onTransitionEnd);
    }

    async function loadTimelineData(orderId, awb, row) {
        if (timelineCache.has(orderId)) {
            return renderTimeline(orderId, row, timelineCache.get(orderId));
        }

        const loadingElement = row.querySelector('.awb-timeline-loading');
        const errorElement = row.querySelector('.awb-timeline-error');
        const emptyElement = row.querySelector('.awb-timeline-empty');
        if (errorElement) {
            errorElement.hidden = true;
            errorElement.textContent = '';
            errorElement.setAttribute('hidden', 'hidden');
        }
        if (emptyElement) {
            emptyElement.hidden = true;
        }
        if (loadingElement) {
            loadingElement.hidden = false;
        }

        try {
            const response = await fetch(`/api/awb/track_awb.php?awb=${encodeURIComponent(awb)}`, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Cod răspuns server: ${response.status}`);
            }

            const payload = await response.json();
            if (!payload.success || !payload.data || !Array.isArray(payload.data.history)) {
                throw new Error(payload.error || 'Istoric indisponibil pentru acest AWB.');
            }

            const events = normalizeEvents(payload.data.history);
            const cacheEntry = {
                events,
                hasEvents: events.length > 0,
                message: events.length ? '' : (payload.message || DEFAULT_EMPTY_MESSAGE)
            };
            timelineCache.set(orderId, cacheEntry);
            return renderTimeline(orderId, row, cacheEntry);
        } catch (error) {
            showTimelineError(orderId, row, error.message || 'Nu s-a putut încărca istoricul.');
            throw error;
        } finally {
            if (loadingElement) {
                loadingElement.hidden = true;
            }
        }
    }

    function normalizeEvents(history) {
        return history
            .map((event, index) => {
                const timeString = String(event.time || '').trim();
                const description = String(event.event || '').trim();
                const location = String(event.location || '').trim();
                let parsedDate = timeString ? new Date(timeString) : null;
                let displayDate = timeString;

                if (parsedDate && Number.isNaN(parsedDate.getTime())) {
                    parsedDate = parseCargusDate(timeString);
                }

                if (parsedDate && !Number.isNaN(parsedDate.getTime())) {
                    displayDate = parsedDate.toLocaleString('ro-RO', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                } else {
                    parsedDate = new Date();
                }

                return {
                    id: `${parsedDate.getTime()}-${index}`,
                    start: parsedDate,
                    dateDisplay: displayDate,
                    description,
                    location,
                    isReturn: /expediere\s+returnata/i.test(description)
                };
            })
            .sort((a, b) => a.start.getTime() - b.start.getTime());
    }

    function renderTimeline(orderId, row, entry) {
        const { events, hasEvents, message } = normalizeCacheEntry(entry);
        const container = row.querySelector('.awb-timeline-container');
        const errorElement = row.querySelector('.awb-timeline-error');
        const emptyElement = row.querySelector('.awb-timeline-empty');

        if (!container) {
            return;
        }

        if (!hasEvents) {
            showTimelineEmpty(orderId, row, message || DEFAULT_EMPTY_MESSAGE);
            return;
        }

        if (errorElement) {
            errorElement.hidden = true;
            errorElement.textContent = '';
            errorElement.setAttribute('hidden', 'hidden');
        }
        if (emptyElement) {
            emptyElement.hidden = true;
            emptyElement.setAttribute('hidden', 'hidden');
        }

        container.removeAttribute('hidden');
        container.hidden = false;
        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Cronologie evenimente AWB');
        container.innerHTML = '';

        const track = document.createElement('div');
        track.className = 'awb-timeline-track';

        events.forEach((event, index) => {
            track.appendChild(buildTimelineEventElement(event, index, events.length));
        });

        container.appendChild(track);
    }

    function showTimelineError(orderId, row, message) {
        const errorElement = row.querySelector('.awb-timeline-error');
        const emptyElement = row.querySelector('.awb-timeline-empty');
        const container = row.querySelector('.awb-timeline-container');
        if (!errorElement) {
            return;
        }

        if (emptyElement) {
            emptyElement.hidden = true;
            emptyElement.setAttribute('hidden', 'hidden');
        }
        destroyTimeline(orderId);
        if (container) {
            container.innerHTML = '';
            container.setAttribute('hidden', 'hidden');
            container.hidden = true;
        }
        errorElement.textContent = message;
        errorElement.hidden = false;
        errorElement.removeAttribute('hidden');
    }

    function showTimelineEmpty(orderId, row, message) {
        const emptyElement = row.querySelector('.awb-timeline-empty');
        const errorElement = row.querySelector('.awb-timeline-error');
        const container = row.querySelector('.awb-timeline-container');

        if (errorElement) {
            errorElement.hidden = true;
            errorElement.textContent = '';
            errorElement.setAttribute('hidden', 'hidden');
        }
        destroyTimeline(orderId);

        if (container) {
            container.innerHTML = '';
            container.setAttribute('hidden', 'hidden');
            container.hidden = true;
        }

        if (emptyElement) {
            emptyElement.hidden = false;
            emptyElement.removeAttribute('hidden');
            const messageElement = emptyElement.querySelector('[data-empty-message]');
            if (messageElement) {
                messageElement.textContent = message || DEFAULT_EMPTY_MESSAGE;
            }
        }
    }

    function resizeVisibleTimelines() {
        document.querySelectorAll('.awb-timeline-row.is-visible .awb-timeline-container').forEach((container) => {
            // Trigger a reflow to ensure flex layouts size correctly after resize.
            void container.offsetHeight;
        });
    }

    function normalizeCacheEntry(entry) {
        if (!entry) {
            return { events: [], hasEvents: false, message: DEFAULT_EMPTY_MESSAGE };
        }

        if (Array.isArray(entry)) {
            return { events: entry, hasEvents: entry.length > 0, message: DEFAULT_EMPTY_MESSAGE };
        }

        const events = Array.isArray(entry.events) ? entry.events : [];
        const hasEvents = typeof entry.hasEvents === 'boolean' ? entry.hasEvents : events.length > 0;
        return {
            events,
            hasEvents,
            message: typeof entry.message === 'string' && entry.message.trim() ? entry.message : DEFAULT_EMPTY_MESSAGE
        };
    }

    function parseCargusDate(value) {
        const match = /^\s*(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})\s*$/.exec(value);
        if (!match) {
            return null;
        }

        const [, day, month, year, hour, minute] = match;
        const isoString = `${year}-${month}-${day}T${hour}:${minute}:00`;
        const parsed = new Date(isoString);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function buildClassName(base, modifiers) {
        const classes = [base];
        Object.keys(modifiers).forEach((key) => {
            if (modifiers[key]) {
                classes.push(key);
            }
        });
        return classes.join(' ');
    }

    function debounce(fn, wait = 200) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn.apply(null, args), wait);
        };
    }

    function safeCssEscape(value) {
        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
            return CSS.escape(value);
        }

        return String(value).replace(/"/g, '\\"');
    }

    function destroyTimeline(orderId) {
        const row = document.querySelector(`.awb-timeline-row[data-order-id="${safeCssEscape(orderId)}"]`);
        const container = row ? row.querySelector('.awb-timeline-container') : null;
        if (container) {
            container.innerHTML = '';
        }
    }

    function buildTimelineEventElement(event, index, totalEvents) {
        const eventElement = document.createElement('div');
        eventElement.className = buildClassName('awb-timeline-event', {
            'awb-timeline-event--first': index === 0,
            'awb-timeline-event--last': index === totalEvents - 1,
            'awb-timeline-event--return': Boolean(event.isReturn)
        });

        if (event.description) {
            eventElement.title = [event.description, event.location, event.dateDisplay]
                .filter(Boolean)
                .join('\n');
        }

        const dateElement = document.createElement('div');
        dateElement.className = 'awb-timeline-event-date';
        dateElement.textContent = event.dateDisplay || '';
        eventElement.appendChild(dateElement);

        const markerWrapper = document.createElement('div');
        markerWrapper.className = 'awb-timeline-event-marker-wrapper';

        const leftLine = document.createElement('span');
        leftLine.className = 'awb-timeline-event-line awb-timeline-event-line--left';
        markerWrapper.appendChild(leftLine);

        const marker = document.createElement('span');
        marker.className = 'awb-timeline-event-marker';
        marker.setAttribute('aria-hidden', 'true');
        markerWrapper.appendChild(marker);

        const rightLine = document.createElement('span');
        rightLine.className = 'awb-timeline-event-line awb-timeline-event-line--right';
        markerWrapper.appendChild(rightLine);

        eventElement.appendChild(markerWrapper);

        const descriptionElement = document.createElement('div');
        descriptionElement.className = 'awb-timeline-event-description';
        descriptionElement.textContent = event.description || '';
        eventElement.appendChild(descriptionElement);

        if (event.location) {
            const locationElement = document.createElement('div');
            locationElement.className = 'awb-timeline-event-location';
            locationElement.textContent = event.location;
            eventElement.appendChild(locationElement);
        }

        return eventElement;
    }
})();
