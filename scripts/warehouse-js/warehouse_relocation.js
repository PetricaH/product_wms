(function () {
    const config = window.WAREHOUSE_RELOCATION_CONFIG || {};

    class AudioFeedback {
        constructor() {
            this.context = null;
        }

        get audioContext() {
            if (!window.AudioContext && !window.webkitAudioContext) {
                return null;
            }
            if (!this.context) {
                try {
                    this.context = new (window.AudioContext || window.webkitAudioContext)();
                } catch (err) {
                    this.context = null;
                }
            }
            return this.context;
        }

        play(type) {
            const ctx = this.audioContext;
            if (!ctx) {
                return;
            }
            const now = ctx.currentTime;
            const oscillator = ctx.createOscillator();
            const gainNode = ctx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(ctx.destination);

            switch (type) {
                case 'success':
                    oscillator.frequency.setValueAtTime(880, now);
                    gainNode.gain.setValueAtTime(0.2, now);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.2);
                    break;
                case 'error':
                    oscillator.frequency.setValueAtTime(220, now);
                    gainNode.gain.setValueAtTime(0.25, now);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.25);
                    break;
                default:
                    oscillator.frequency.setValueAtTime(440, now);
                    gainNode.gain.setValueAtTime(0.12, now);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.18);
            }

            oscillator.start(now);
            oscillator.stop(now + 0.3);
        }
    }

    class RelocationWorkflow {
        constructor(rootEl, options) {
            this.root = rootEl;
            this.options = options;
            this.audio = new AudioFeedback();
            this.currentTask = null;
            this.position = 0;
            this.total = Number(rootEl.dataset.total || 0);
            this.stepIndex = 0;
            this.scannerActive = false;
            this.manualMode = false;
            this.manualCallback = null;
            this.stepCompletion = {
                source: false,
                product: false,
                destination: false,
            };
            this.scanBuffer = '';
            this.scanTimer = null;
            this.scannerPaused = false;

            this.elements = {
                progress: document.getElementById('mobileTaskProgress'),
                product: document.getElementById('taskProduct'),
                sku: document.getElementById('taskSku'),
                qty: document.getElementById('taskQty'),
                from: document.getElementById('taskFrom'),
                to: document.getElementById('taskTo'),
                stepTitle: document.getElementById('stepTitle'),
                stepStatus: document.getElementById('stepStatus'),
                expectedValue: document.getElementById('expectedValue'),
                scannerState: document.getElementById('scannerState'),
                manualEntry: document.getElementById('manualEntry'),
                manualInput: document.getElementById('manualInput'),
                manualConfirm: document.getElementById('manualConfirm'),
                manualCancel: document.getElementById('manualCancel'),
                functionKeys: document.querySelectorAll('.function-key'),
                f1Action: document.getElementById('f1Action'),
                f2Action: document.getElementById('f2Action'),
                f3Action: document.getElementById('f3Action'),
                f4Action: document.getElementById('f4Action'),
                f5Action: document.getElementById('f5Action'),
                scannerBuffer: document.getElementById('scannerBuffer'),
            };

            this.stepConfig = [
                {
                    id: 'select',
                    title: 'Pasul 0 - Selectare sarcină',
                    status: 'Folosiți F1 pentru a începe',
                    expectedKey: null,
                },
                {
                    id: 'source',
                    title: 'Pasul 1 - Scanare locație sursă',
                    status: 'Scanați locația sursă (F3)',
                    expectedKey: 'from_location',
                },
                {
                    id: 'product',
                    title: 'Pasul 2 - Verificare produs',
                    status: 'Scanați produsul corect (F3)',
                    expectedKey: 'product_sku',
                },
                {
                    id: 'destination',
                    title: 'Pasul 3 - Scanare destinație',
                    status: 'Scanați locația destinație (F3)',
                    expectedKey: 'to_location',
                },
                {
                    id: 'confirm',
                    title: 'Pasul 4 - Confirmare relocare',
                    status: 'Apăsați F1 pentru confirmare',
                    expectedKey: null,
                },
            ];

            this.bindEvents();
            this.restoreLocalTask();
            this.applyInitialTask();
            this.updateStepUI();
            this.updateFunctionBar();
        }

        bindEvents() {
            document.addEventListener('keydown', (event) => {
                if (!this.root.isConnected) {
                    return;
                }

                if (this.handleScannerKey(event)) {
                    return;
                }

                if (event.repeat) {
                    return;
                }

                switch (event.key) {
                    case 'F1':
                        event.preventDefault();
                        this.handleF1();
                        break;
                    case 'F2':
                        event.preventDefault();
                        this.handleF2();
                        break;
                    case 'F3':
                        event.preventDefault();
                        this.handleF3();
                        break;
                    case 'F4':
                        event.preventDefault();
                        this.handleF4();
                        break;
                    case 'F5':
                        event.preventDefault();
                        this.handleF5();
                        break;
                    default:
                        break;
                }
            });

            this.elements.manualConfirm.addEventListener('click', () => {
                const value = this.elements.manualInput.value.trim();
                if (value) {
                    this.handleCode(value, true);
                }
            });

            this.elements.manualCancel.addEventListener('click', () => {
                this.toggleManualMode(false);
            });

            if (this.elements.scannerBuffer) {
                this.elements.scannerBuffer.addEventListener('blur', () => {
                    if (this.scannerActive && !this.scannerPaused) {
                        this.focusScannerBuffer();
                    }
                });
            }
        }

        applyInitialTask() {
            if (this.options.initialTask) {
                this.applyTaskPayload(this.options.initialTask);
            }
        }

        restoreLocalTask() {
            if (!window.localStorage) {
                return;
            }
            try {
                const raw = window.localStorage.getItem('relocation:lastTask');
                if (raw && !this.options.initialTask) {
                    const payload = JSON.parse(raw);
                    if (payload && payload.task) {
                        this.applyTaskPayload(payload);
                    }
                }
            } catch (err) {
                console.warn('Nu s-a putut restaura sarcina locală.', err);
            }
        }

        persistLocalTask(payload) {
            if (!window.localStorage) {
                return;
            }
            try {
                if (payload) {
                    window.localStorage.setItem('relocation:lastTask', JSON.stringify(payload));
                } else {
                    window.localStorage.removeItem('relocation:lastTask');
                }
            } catch (err) {
                console.warn('Nu s-a putut salva sarcina locală.', err);
            }
        }

        applyTaskPayload(payload) {
            if (!payload || !payload.task) {
                this.currentTask = null;
                this.position = 0;
                this.total = 0;
                this.manualMode = false;
                this.elements.manualEntry.hidden = true;
                this.elements.manualInput.value = '';
                this.stopScanner();
                this.resetForNoTask();
                return;
            }

            this.currentTask = payload.task;
            this.position = payload.position || 0;
            this.total = payload.total || 0;
            this.stepIndex = 0;
            this.manualMode = false;
            this.elements.manualEntry.hidden = true;
            this.elements.manualInput.value = '';
            this.stepCompletion = {
                source: false,
                product: false,
                destination: false,
            };
            this.updateTaskSummary();
            this.updateProgress();
            this.updateStepUI();
            this.updateFunctionBar();
            this.persistLocalTask(payload);
        }

        resetForNoTask() {
            this.stepIndex = 0;
            this.elements.product.textContent = 'Nu există sarcini active';
            this.elements.sku.textContent = '';
            this.elements.qty.textContent = '';
            this.elements.from.textContent = '---';
            this.elements.to.textContent = '---';
            this.elements.progress.textContent = 'Sarcina 0 din 0';
            this.elements.stepStatus.textContent = 'Nu sunt sarcini de relocare.';
            this.elements.expectedValue.textContent = '--';
            this.elements.scannerState.textContent = 'Scanner inactiv';
            this.updateFunctionBar(true);
            this.persistLocalTask(null);
        }

        updateTaskSummary() {
            if (!this.currentTask) {
                this.resetForNoTask();
                return;
            }

            this.elements.product.textContent = this.currentTask.product_name || 'Produs necunoscut';
            this.elements.sku.textContent = this.currentTask.product_sku ? `SKU: ${this.currentTask.product_sku}` : '';
            this.elements.qty.textContent = `Cantitate: ${this.currentTask.quantity || 0} bucăți`;
            this.elements.from.textContent = this.currentTask.from_location || 'Nespecificat';
            this.elements.to.textContent = this.currentTask.to_location || 'Nespecificat';
        }

        updateProgress() {
            const pos = this.position || 0;
            const total = this.total || 0;
            this.elements.progress.textContent = `Sarcina ${Math.min(pos, total)} din ${total}`;
        }

        updateStepUI() {
            const step = this.stepConfig[this.stepIndex];
            if (!step) {
                return;
            }

            this.elements.stepTitle.textContent = step.title;
            this.elements.stepStatus.textContent = step.status;
            if (step.expectedKey && this.currentTask) {
                const value = this.currentTask[step.expectedKey] || '--';
                if (step.id === 'product') {
                    this.elements.expectedValue.textContent = `SKU așteptat: ${value}`;
                } else {
                    this.elements.expectedValue.textContent = value;
                }
            } else {
                this.elements.expectedValue.textContent = '--';
            }

            if (this.scannerActive) {
                this.elements.scannerState.textContent = 'Scanner laser activ - scanați codul';
                this.elements.scannerState.classList.remove('error');
            } else {
                this.elements.scannerState.textContent = 'Scanner inactiv';
                this.elements.scannerState.classList.remove('error');
            }

            if (this.manualMode) {
                this.elements.manualEntry.hidden = false;
                this.elements.manualInput.focus({ preventScroll: true });
            } else {
                this.elements.manualEntry.hidden = true;
            }
        }

        updateFunctionBar(noTask = false) {
            if (noTask) {
                this.setFunctionKeyState('F1', 'Inactiv', true);
                this.setFunctionKeyState('F2', 'Inactiv', true);
                this.setFunctionKeyState('F3', 'Inactiv', true);
                this.setFunctionKeyState('F4', 'Inactiv', true);
                this.setFunctionKeyState('F5', 'Înapoi', false);
                return;
            }

            const step = this.stepConfig[this.stepIndex];
            switch (step.id) {
                case 'select':
                    this.setFunctionKeyState('F1', 'Pornește', false);
                    this.setFunctionKeyState('F2', 'Următor', this.total <= 1);
                    this.setFunctionKeyState('F3', 'Scanează', false);
                    this.setFunctionKeyState('F4', 'Manual', false);
                    this.setFunctionKeyState('F5', 'Înapoi', false);
                    break;
                case 'source':
                    this.setFunctionKeyState('F1', this.manualMode ? 'Confirmă' : 'Confirmă', this.manualMode ? false : !this.stepCompletion.source);
                    this.setFunctionKeyState('F2', 'Următor', false);
                    this.setFunctionKeyState('F3', this.scannerActive ? 'Oprește' : 'Scanează', false);
                    this.setFunctionKeyState('F4', this.manualMode ? 'Ascunde' : 'Manual', false);
                    this.setFunctionKeyState('F5', 'Înapoi', false);
                    break;
                case 'product':
                    this.setFunctionKeyState('F1', this.manualMode ? 'Confirmă' : 'Confirmă', this.manualMode ? false : !this.stepCompletion.product);
                    this.setFunctionKeyState('F2', 'Următor', false);
                    this.setFunctionKeyState('F3', this.scannerActive ? 'Oprește' : 'Scanează', false);
                    this.setFunctionKeyState('F4', this.manualMode ? 'Ascunde' : 'Manual', false);
                    this.setFunctionKeyState('F5', 'Înapoi', false);
                    break;
                case 'destination':
                    this.setFunctionKeyState('F1', this.manualMode ? 'Confirmă' : 'Confirmă', this.manualMode ? false : !this.stepCompletion.destination);
                    this.setFunctionKeyState('F2', 'Următor', false);
                    this.setFunctionKeyState('F3', this.scannerActive ? 'Oprește' : 'Scanează', false);
                    this.setFunctionKeyState('F4', this.manualMode ? 'Ascunde' : 'Manual', false);
                    this.setFunctionKeyState('F5', 'Înapoi', false);
                    break;
                case 'confirm':
                    this.setFunctionKeyState('F1', 'Finalizează', false);
                    this.setFunctionKeyState('F2', 'Următor', this.total <= 1);
                    this.setFunctionKeyState('F3', this.scannerActive ? 'Oprește' : 'Scanează', true);
                    this.setFunctionKeyState('F4', this.manualMode ? 'Ascunde' : 'Manual', true);
                    this.setFunctionKeyState('F5', 'Înapoi', false);
                    break;
                default:
                    break;
            }
        }

        setFunctionKeyState(key, label, disabled) {
            const element = Array.from(this.elements.functionKeys).find((el) => el.dataset.key === key);
            if (!element) {
                return;
            }
            const labelElement = element.querySelector('.key-action');
            if (labelElement) {
                labelElement.textContent = label;
            }
            if (disabled) {
                element.classList.add('disabled');
            } else {
                element.classList.remove('disabled');
            }
        }

        handleF1() {
            if (!this.currentTask) {
                this.fetchTask('current');
                return;
            }
            const step = this.stepConfig[this.stepIndex];

            if (this.manualMode) {
                const value = this.elements.manualInput.value.trim();
                if (value) {
                    this.handleCode(value, true);
                }
                return;
            }

            switch (step.id) {
                case 'select':
                    this.audio.play('navigate');
                    this.setStep(1);
                    break;
                case 'confirm':
                    this.completeTask();
                    break;
                default:
                    // For scanning steps require manual confirm if already scanned
                    if (this.stepCompletion[step.id]) {
                        this.advanceStep();
                    }
                    break;
            }
        }

        handleF2() {
            if (!this.currentTask) {
                this.fetchTask('current');
                return;
            }
            this.audio.play('navigate');
            this.fetchTask('next');
        }

        handleF3() {
            this.toggleScanner();
        }

        handleF4() {
            this.toggleManualMode(!this.manualMode);
        }

        handleF5() {
            if (this.manualMode) {
                this.toggleManualMode(false);
                return;
            }
            if (this.scannerActive) {
                this.toggleScanner(false);
                return;
            }
            if (this.stepIndex > 0) {
                this.audio.play('navigate');
                this.setStep(this.stepIndex - 1);
            } else {
                this.audio.play('navigate');
                this.fetchTask('previous');
            }
        }

        setStep(index) {
            if (index < 0) {
                index = 0;
            }
            if (index >= this.stepConfig.length) {
                index = this.stepConfig.length - 1;
            }
            this.stepIndex = index;
            this.updateStepUI();
            this.updateFunctionBar();
        }

        advanceStep() {
            this.setStep(this.stepIndex + 1);
        }

        toggleManualMode(force) {
            const newState = typeof force === 'boolean' ? force : !this.manualMode;
            this.manualMode = newState;
            if (!newState) {
                this.elements.manualInput.value = '';
                if (this.scannerActive && !this.scannerPaused) {
                    this.focusScannerBuffer();
                }
            } else {
                this.toggleScanner(false);
            }
            this.updateStepUI();
            this.updateFunctionBar();
        }

        focusScannerBuffer() {
            if (!this.elements.scannerBuffer) {
                return;
            }
            try {
                this.elements.scannerBuffer.focus({ preventScroll: true });
            } catch (err) {
                this.elements.scannerBuffer.focus();
            }
        }

        resetScanTimer() {
            if (this.scanTimer) {
                clearTimeout(this.scanTimer);
                this.scanTimer = null;
            }
            if (!this.scannerActive || this.scannerPaused) {
                return;
            }
            this.scanTimer = window.setTimeout(() => {
                if (!this.scanBuffer) {
                    return;
                }
                const code = this.scanBuffer;
                this.scanBuffer = '';
                this.handleCode(code, false);
            }, 120);
        }

        handleScannerKey(event) {
            if (!this.scannerActive || this.scannerPaused || this.manualMode) {
                return false;
            }
            const key = event.key;
            if (['F1', 'F2', 'F3', 'F4', 'F5'].includes(key)) {
                return false;
            }
            if (key === 'Enter') {
                event.preventDefault();
                const code = this.scanBuffer.trim();
                this.scanBuffer = '';
                if (code) {
                    this.handleCode(code, false);
                }
                return true;
            }
            if (key === 'Escape') {
                event.preventDefault();
                this.toggleScanner(false);
                return true;
            }
            if (key === 'Backspace') {
                event.preventDefault();
                this.scanBuffer = this.scanBuffer.slice(0, -1);
                this.resetScanTimer();
                return true;
            }
            if (key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.scanBuffer += key;
                this.resetScanTimer();
                return true;
            }
            return false;
        }

        toggleScanner(force) {
            const newState = typeof force === 'boolean' ? force : !this.scannerActive;
            if (newState) {
                this.startScanner();
            } else {
                this.stopScanner();
            }
        }

        async startScanner() {
            if (this.scannerActive) {
                return;
            }
            this.scannerActive = true;
            this.scannerPaused = false;
            this.scanBuffer = '';
            if (this.elements.scannerBuffer) {
                this.elements.scannerBuffer.value = '';
            }
            this.elements.scannerState.textContent = 'Scanner laser activ - scanați codul';
            this.elements.scannerState.classList.remove('error');
            this.focusScannerBuffer();
            this.updateFunctionBar();
        }

        async stopScanner() {
            if (this.scanTimer) {
                clearTimeout(this.scanTimer);
                this.scanTimer = null;
            }
            this.scannerActive = false;
            this.scannerPaused = false;
            this.scanBuffer = '';
            if (this.elements.scannerBuffer) {
                this.elements.scannerBuffer.value = '';
            }
            this.updateStepUI();
            if (!this.currentTask) {
                this.updateFunctionBar(true);
            } else {
                this.updateFunctionBar();
            }
        }

        async pauseScanner() {
            if (!this.scannerActive || this.scannerPaused) {
                return;
            }
            this.scannerPaused = true;
            if (this.scanTimer) {
                clearTimeout(this.scanTimer);
                this.scanTimer = null;
            }
        }

        async resumeScanner() {
            if (!this.scannerActive || !this.scannerPaused) {
                return;
            }
            this.scannerPaused = false;
            this.focusScannerBuffer();
        }

        handleCode(rawCode, isManual) {
            if (!rawCode) {
                return;
            }

            const code = rawCode.trim();
            if (!code) {
                return;
            }

            const step = this.stepConfig[this.stepIndex];
            if (!step || !this.currentTask) {
                return;
            }

            if (step.expectedKey) {
                const expected = (this.currentTask[step.expectedKey] || '').toString().trim();
                if (!expected) {
                    this.elements.scannerState.textContent = 'Nu există o valoare de referință pentru acest pas.';
                    this.elements.scannerState.classList.add('error');
                    return;
                }

                const normalizedExpected = expected.replace(/\s+/g, '').toUpperCase();
                const normalizedCode = code.replace(/\s+/g, '').toUpperCase();

                if (normalizedExpected === normalizedCode) {
                    this.elements.scannerState.textContent = 'Confirmare reușită!';
                    this.elements.scannerState.classList.remove('error');
                    this.audio.play('success');
                    this.stepCompletion[step.id] = true;
                    if (isManual) {
                        this.toggleManualMode(false);
                    }
                    this.pauseScanner().then(() => {
                        setTimeout(() => {
                            this.advanceStep();
                            this.resumeScanner();
                        }, 300);
                    });
                } else {
                    this.audio.play('error');
                    this.elements.scannerState.textContent = `Cod invalid (${code}). Încearcă din nou.`;
                    this.elements.scannerState.classList.add('error');
                    if (isManual) {
                        this.elements.manualInput.select();
                    }
                }
            } else {
                // Steps without expected value
                this.audio.play('navigate');
            }
            this.updateFunctionBar();
        }

        async fetchTask(direction = 'current') {
            const params = new URLSearchParams({
                ajax: '1',
                action: 'fetch_task',
                direction,
            });
            if (this.currentTask) {
                params.set('task_id', this.currentTask.id);
            }

            try {
                const response = await fetch(`${this.options.fetchUrl}?${params.toString()}`, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                    },
                });
                if (!response.ok) {
                    throw new Error(`Cod răspuns ${response.status}`);
                }
                const payload = await response.json();
                if (payload && payload.success && payload.data) {
                    this.applyTaskPayload(payload.data);
                } else {
                    this.applyTaskPayload(null);
                    this.elements.stepStatus.textContent = payload.message || 'Nu există sarcini disponibile.';
                }
            } catch (err) {
                console.error('Nu s-a putut prelua sarcina', err);
                this.elements.scannerState.textContent = 'Eroare de conexiune. Verificați rețeaua.';
                this.elements.scannerState.classList.add('error');
            }
        }

        async completeTask() {
            if (!this.currentTask) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('ajax', '1');
                formData.append('action', 'complete_with_move');
                formData.append('task_id', String(this.currentTask.id));
                formData.append('csrf_token', this.options.csrfToken);

                const response = await fetch(this.options.fetchUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });

                const payload = await response.json();
                if (!response.ok || !payload.success) {
                    throw new Error(payload.message || 'Eroare la finalizare.');
                }

                this.audio.play('success');
                this.elements.stepStatus.textContent = 'Relocare finalizată!';
                this.fetchTask('next');
            } catch (err) {
                console.error('Nu s-a putut finaliza sarcina', err);
                this.audio.play('error');
                this.elements.stepStatus.textContent = err.message || 'Eroare la finalizare.';
                this.elements.scannerState.classList.add('error');
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (!config.isMobile) {
            return;
        }
        const root = document.getElementById('mobileRelocationApp');
        if (!root) {
            return;
        }
        new RelocationWorkflow(root, config);
    });
})();
