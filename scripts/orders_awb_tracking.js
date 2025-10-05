// File: scripts/orders_awb_tracking.js
// Handles expandable AWB tracking timeline rendering on the orders page

(function() {
    const timelineCache = new Map();
    const timelineInstances = new Map();
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
            .then(() => {
                const instance = timelineInstances.get(orderId);
                if (instance) {
                    instance.redraw();
                    instance.fit({ animation: { duration: 500, easingFunction: 'easeInOutCubic' } });
                }
            })
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
            const parsedDate = timeString ? new Date(timeString) : null;

            return {
                id: `${parsedDate ? parsedDate.getTime() : Date.now()}-${index}`,
                start: parsedDate || new Date(),
                dateDisplay: parsedDate 
                    ? parsedDate.toLocaleString('ro-RO', { 
                        day: '2-digit', 
                        month: '2-digit', 
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                      })
                    : timeString,
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

        if (typeof vis === 'undefined' || typeof vis.Timeline !== 'function') {
            console.error('Librăria vis-timeline nu este disponibilă.');
            showTimelineError(orderId, row, 'Componenta de cronologie nu s-a putut inițializa.');
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

        const dataset = events.map((event, index) => ({
            id: event.id,
            start: event.start,
            className: buildClassName('awb-timeline-node', {
                'awb-timeline-node--first': index === 0,
                'awb-timeline-node--last': index === events.length - 1,
                'awb-timeline-node--return': event.isReturn
            }),
            data: event,
            title: buildTooltipText(event)
        }));

        const visItems = new vis.DataSet(dataset);
        const template = (item) => buildTimelineItemTemplate(item.data);
        const options = {
            stack: false,
            showMajorLabels: false,
            showMinorLabels: false,
            selectable: false,
            zoomable: false,
            moveable: false,
            horizontalScroll: false,
            verticalScroll: false,
            margin: {
                item: 48,
                axis: 0
            },
            orientation: { axis: 'bottom' },
            template
        };

        let timeline = timelineInstances.get(orderId);
        if (!timeline) {
            timeline = new vis.Timeline(container, visItems, options);
            timelineInstances.set(orderId, timeline);
        } else {
            timeline.setItems(visItems);
            timeline.setOptions(options);
        }

        requestAnimationFrame(() => {
            timeline.redraw();
            timeline.fit({ animation: { duration: 400, easingFunction: 'easeInOutCubic' } });
        });
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

    function buildTimelineItemTemplate(data) {
        if (!data) {
            return '';
        }

        return `
            <div class="awb-timeline-node__inner">
                <div class="awb-timeline-node__date">${escapeHtml(data.dateDisplay)}</div>
                <div class="awb-timeline-node__marker" aria-hidden="true"></div>
                <div class="awb-timeline-node__details">${escapeHtml(data.description)}</div>
                ${data.location ? `<div class="awb-timeline-node__location">${escapeHtml(data.location)}</div>` : ''}
            </div>
        `;
    }

    function buildTooltipText(data) {
        if (!data) {
            return '';
        }

        const parts = [data.description, data.location, data.dateDisplay]
            .map((part) => (part ? String(part).trim() : ''))
            .filter(Boolean);

        return parts.join('\n');
    }

    function resizeVisibleTimelines() {
        document.querySelectorAll('.awb-timeline-row.is-visible').forEach((row) => {
            const orderId = row.getAttribute('data-order-id');
            const timeline = timelineInstances.get(orderId);
            if (timeline) {
                timeline.redraw();
                timeline.fit();
            }
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

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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
        const instance = timelineInstances.get(orderId);
        if (instance && typeof instance.destroy === 'function') {
            instance.destroy();
        }
        timelineInstances.delete(orderId);
    }
})();
