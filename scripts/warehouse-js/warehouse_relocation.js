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
            };
            this.autoSubmitTimer = null;

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
                manualLabel: document.getElementById('manualLabel'),

                manualInput: document.getElementById('manualInput'),
                manualConfirm: document.getElementById('manualConfirm'),
                manualCancel: document.getElementById('manualCancel'),
                functionKeys: document.querySelectorAll('.function-key'),
                f1Action: document.getElementById('f1Action'),
                f2Action: document.getElementById('f2Action'),
                f3Action: document.getElementById('f3Action'),
                f4Action: document.getElementById('f4Action'),
                f5Action: document.getElementById('f5Action'),

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
                    id: 'quantity',
                    title: 'Pasul 3 - Confirmare cantitate',
                    status: 'Verificați cantitatea și apăsați F1',
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

            if (this.elements.manualConfirm) {
                this.elements.manualConfirm.addEventListener('click', () => {
                    if (!this.elements.manualInput) {
                        return;
                    }
                    const value = this.elements.manualInput.value.trim();
                    if (value) {
                        this.handleCode(value, true);
                    }
                });
            }

            if (this.elements.manualCancel) {
                this.elements.manualCancel.addEventListener('click', () => {
                    this.toggleManualMode(false);
                });
            }

            if (this.elements.manualInput) {
                this.elements.manualInput.addEventListener('keydown', (event) => {
                    if (!this.scannerActive || this.manualMode) {
                        return;
                    }
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        this.submitScannerInput();
                    }
                });

                this.elements.manualInput.addEventListener('input', () => {
                    if (!this.scannerActive || this.manualMode) {
                        return;
                    }
                    this.scheduleAutoSubmit();
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
                if (this.elements.manualEntry) {
                    this.elements.manualEntry.hidden = true;
                }
                if (this.elements.manualInput) {
                    this.elements.manualInput.value = '';
                    this.elements.manualInput.readOnly = true;
                }
                this.stopScanner();
                this.resetForNoTask();
                return;
            }

            this.currentTask = payload.task;
            this.position = payload.position || 0;
            this.total = payload.total || 0;
            this.stepIndex = 0;
            this.manualMode = false;
            if (this.elements.manualEntry) {
                this.elements.manualEntry.hidden = true;
            }
            if (this.elements.manualInput) {
                this.elements.manualInput.value = '';
                this.elements.manualInput.readOnly = true;
            }

            this.stepCompletion = {
                source: false,
                product: false,
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
            if (step.id === 'quantity' && this.currentTask) {
                this.elements.expectedValue.textContent = `Cantitate de mutat: ${this.currentTask.quantity ?? 0}`;
            } else if (step.expectedKey && this.currentTask) {
                const value = this.currentTask[step.expectedKey] || '--';
                if (step.id === 'product') {
                    this.elements.expectedValue.textContent = `SKU așteptat: ${value}`;
                } else if (step.id === 'source') {
                    const sku = this.currentTask.product_sku || '--';
                    this.elements.expectedValue.textContent = `Locație așteptată: ${value} • SKU: ${sku}`;
                } else {
                    this.elements.expectedValue.textContent = value;
                }
            } else {
                this.elements.expectedValue.textContent = '--';
            }

            if (step.id === 'quantity') {
                this.elements.scannerState.textContent = 'Confirmați cantitatea și apăsați F1 pentru finalizare.';
                this.elements.scannerState.classList.remove('error');
            } else if (this.scannerActive) {
                this.elements.scannerState.textContent = 'Scanner laser activ - scanați codul';
                this.elements.scannerState.classList.remove('error');
            } else {
                this.elements.scannerState.textContent = 'Scanner inactiv';
                this.elements.scannerState.classList.remove('error');
            }

            if (this.manualMode) {
                if (this.elements.manualEntry) {
                    this.elements.manualEntry.hidden = false;
                    this.elements.manualEntry.classList.add('mode-manual');
                    this.elements.manualEntry.classList.remove('mode-scanner');
                }
                if (this.elements.manualInput) {
                    this.elements.manualInput.readOnly = false;
                    this.elements.manualInput.placeholder = 'Introduceți codul manual';
                }
                if (this.elements.manualLabel) {
                    this.elements.manualLabel.textContent = 'Introduceți codul manual';
                }
                this.focusManualInput();
            } else if (this.scannerActive) {
                if (this.elements.manualEntry) {
                    this.elements.manualEntry.hidden = false;
                    this.elements.manualEntry.classList.add('mode-scanner');
                    this.elements.manualEntry.classList.remove('mode-manual');
                }
                if (this.elements.manualInput) {
                    this.elements.manualInput.readOnly = false;
                    this.elements.manualInput.placeholder = 'Așteptăm codul scanat';
                }
                if (this.elements.manualLabel) {
                    this.elements.manualLabel.textContent = 'Cod scanat automat';
                }
                this.focusManualInput();
            } else {
                if (this.elements.manualEntry) {
                    this.elements.manualEntry.hidden = true;
                    this.elements.manualEntry.classList.remove('mode-manual');
                    this.elements.manualEntry.classList.remove('mode-scanner');
                }
                if (this.elements.manualInput) {
                    this.elements.manualInput.value = '';
                    this.elements.manualInput.readOnly = true;
                    this.elements.manualInput.placeholder = '';
                }
                if (this.elements.manualLabel) {
                    this.elements.manualLabel.textContent = 'Introduceți codul';
                }
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
                case 'quantity':
                    this.setFunctionKeyState('F1', 'Finalizează', false);
                    this.setFunctionKeyState('F2', 'Următor', this.total <= 1);
                    this.setFunctionKeyState('F3', 'Scanează', true);
                    this.setFunctionKeyState('F4', 'Manual', true);
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

        stepRequiresScanner(stepId) {
            return ['source', 'product'].includes(stepId);
        }

        handleF1() {
            if (!this.currentTask) {
                this.fetchTask('current');
                return;
            }
            const step = this.stepConfig[this.stepIndex];

            if (this.manualMode) {
                if (!this.elements.manualInput) {
                    return;
                }
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
                case 'quantity':
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
            const step = this.stepConfig[this.stepIndex];
            if (!this.currentTask || !this.stepRequiresScanner(step?.id)) {
                return;
            }
            this.toggleScanner();
        }

        handleF4() {
            const step = this.stepConfig[this.stepIndex];
            if (!this.currentTask || !this.stepRequiresScanner(step?.id)) {
                return;
            }
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
            const step = this.stepConfig[this.stepIndex];
            if (!this.stepRequiresScanner(step?.id)) {
                this.manualMode = false;
                this.scannerActive = false;
                this.scannerPaused = false;
                if (this.elements.manualEntry) {
                    this.elements.manualEntry.hidden = true;
                }
                if (this.elements.manualInput) {
                    this.elements.manualInput.value = '';
                    this.elements.manualInput.readOnly = true;
                }
            }
            this.updateStepUI();
            this.updateFunctionBar();
        }

        advanceStep() {
            this.setStep(this.stepIndex + 1);
        }

        toggleManualMode(force) {
            const newState = typeof force === 'boolean' ? force : !this.manualMode;
            this.manualMode = newState;
            const input = this.elements.manualInput;
            if (!newState) {
                if (input) {
                    input.value = '';
                }
                if (this.scannerActive && !this.scannerPaused) {
                    this.focusManualInput();
                }
            } else {
                this.toggleScanner(false);
            }
            this.updateStepUI();
            this.updateFunctionBar();
        }

        focusManualInput() {
            if (!this.elements.manualInput) {
                return;
            }
            try {
                this.elements.manualInput.focus({ preventScroll: true });
            } catch (err) {
                this.elements.manualInput.focus();
            }
        }

        scheduleAutoSubmit() {
            if (this.autoSubmitTimer) {
                clearTimeout(this.autoSubmitTimer);
            }
            if (!this.scannerActive || this.manualMode) {
                return;
            }
            if (!this.elements.manualInput) {
                return;
            }
            const value = this.elements.manualInput.value.trim();
            if (!value) {
                return;
            }
            this.autoSubmitTimer = window.setTimeout(() => {
                this.submitScannerInput();
            }, 120);
        }

        submitScannerInput() {
            if (!this.scannerActive || this.manualMode) {
                return;
            }
            if (this.autoSubmitTimer) {
                clearTimeout(this.autoSubmitTimer);
                this.autoSubmitTimer = null;
            }
            if (!this.elements.manualInput) {
                return;
            }
            const value = this.elements.manualInput.value.trim();
            if (!value) {
                return;
            }
            this.handleCode(value, false);
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
            this.elements.scannerState.textContent = 'Scanner laser activ - scanați codul';
            this.elements.scannerState.classList.remove('error');
            if (this.elements.manualInput) {
                this.elements.manualInput.value = '';
                this.elements.manualInput.readOnly = false;
            }
            if (this.elements.manualEntry) {
                this.elements.manualEntry.hidden = false;
            }
            this.updateStepUI();
            this.focusManualInput();
            this.updateFunctionBar();
        }

        async stopScanner() {
            if (this.autoSubmitTimer) {
                clearTimeout(this.autoSubmitTimer);
                this.autoSubmitTimer = null;
            }
            this.scannerActive = false;
            this.scannerPaused = false;
            if (this.elements.manualInput) {
                this.elements.manualInput.value = '';
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
            if (this.autoSubmitTimer) {
                clearTimeout(this.autoSubmitTimer);
                this.autoSubmitTimer = null;

            }
        }

        async resumeScanner() {
            if (!this.scannerActive || !this.scannerPaused) {
                return;
            }
            this.scannerPaused = false;
            this.focusManualInput();

        }

        handleCode(rawCode, isManual) {
            if (!rawCode) {
                return;
            }

            const code = rawCode.trim();
            if (!code) {
                return;
            }

            if (!isManual && this.elements.manualInput && this.scannerActive) {
                this.elements.manualInput.value = code;
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
                    this.clearCodeInput(isManual);

                    this.pauseScanner().then(() => {
                        setTimeout(() => {
                            this.advanceStep();
                            const nextStep = this.stepConfig[this.stepIndex];
                            if (this.stepRequiresScanner(nextStep?.id)) {
                                this.resumeScanner();
                            }
                        }, 300);
                    });
                } else {
                    this.audio.play('error');
                    this.elements.scannerState.textContent = `Cod invalid (${code}). Încearcă din nou.`;
                    this.elements.scannerState.classList.add('error');
                    this.handleInvalidCode(isManual);
                }
            } else {
                // Steps without expected value
                this.audio.play('navigate');
            }
            this.updateFunctionBar();
        }

        clearCodeInput(isManual) {
            if (!this.elements.manualInput) {
                return;
            }
            if (isManual) {
                this.toggleManualMode(false);
            } else if (this.scannerActive) {
                this.elements.manualInput.value = '';
            }
        }

        handleInvalidCode(isManual) {
            if (!this.elements.manualInput) {
                return;
            }
            this.elements.manualInput.value = '';
            if (this.autoSubmitTimer) {
                clearTimeout(this.autoSubmitTimer);
                this.autoSubmitTimer = null;
            }

            if (isManual) {
                this.elements.manualInput.focus();
            } else if (this.scannerActive) {
                this.elements.manualInput.focus();
            }
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
