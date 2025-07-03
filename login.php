<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS - Autentificare</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <style>
        /* WMS Design Language */
        :root {
            --primary-color: #00385E;
            --secondary-color: #DDEBF3;
            --hover-color: #A4C8E1;
            --success-color: #198754;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #0dcaf0;
            --background-color: #f8f9fa;
            --container-background: #ffffff;
            --text-primary: #212529;
            --text-secondary: #495057;
            --text-muted: #6c757d;
            --border-color: #dee2e6;
            --border-radius: 8px;
            --base-padding: 1.5rem;
            --base-shadow: 0 5px 15px rgba(0, 0, 0, 0.07);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, #004a7c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            overflow-x: hidden;
        }

        /* Background Pattern */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 25% 25%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
            z-index: -1;
        }

        /* Login Container */
        .login-container {
            background-color: var(--container-background);
            border-radius: var(--border-radius);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            margin: 1rem;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--info-color), var(--success-color));
        }

        /* Header */
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .logo-icon {
            background-color: var(--primary-color);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: 0 4px 20px rgba(0, 56, 94, 0.3);
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .login-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Form */
        .login-form {
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: var(--container-background);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 56, 94, 0.1);
        }

        .form-input.error {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.2rem;
            pointer-events: none;
            margin-top: 12px;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            margin-top: 12px;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            margin-bottom: 1rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background-color: #002c4a;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 56, 94, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* User Type Selection */
        .user-type-selection {
            margin-bottom: 1.5rem;
        }

        .user-type-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }

        .user-type-card {
            background-color: var(--background-color);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .user-type-card:hover {
            border-color: var(--primary-color);
            background-color: var(--secondary-color);
        }

        .user-type-card.selected {
            border-color: var(--primary-color);
            background-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 56, 94, 0.1);
        }

        .user-type-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .user-type-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .user-type-subtitle {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        /* Status Messages */
        .status-message {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .status-success {
            background-color: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(25, 135, 84, 0.2);
        }

        .status-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .status-info {
            background-color: rgba(13, 202, 240, 0.1);
            color: var(--info-color);
            border: 1px solid rgba(13, 202, 240, 0.2);
        }

        /* Footer */
        .login-footer {
            text-align: center;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }

        .footer-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s ease;
        }

        .footer-link:hover {
            color: var(--primary-color);
        }

        .footer-text {
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        /* Loading States */
        .loading {
            position: relative;
            pointer-events: none;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Remember Me */
        .remember-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .remember-checkbox {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .remember-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        .remember-checkbox label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: #002c4a;
        }

        /* Hidden utility */
        .hidden {
            display: none !important;
        }

        /* Responsive Design */
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
                margin: 0.5rem;
            }

            .logo-text {
                font-size: 1.5rem;
            }

            .user-type-grid {
                grid-template-columns: 1fr;
            }

            .remember-section {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <div class="logo">
                <div class="logo-icon">
                    <span class="material-symbols-outlined">warehouse</span>
                </div>
                <div class="logo-text">WMS</div>
            </div>
            <h1 class="login-title">Autentificare</h1>
            <p class="login-subtitle">Intrați în contul dumneavoastră pentru a accesa sistemul</p>
        </div>

        <!-- User Type Selection -->
        <div class="user-type-selection">
            <div class="user-type-grid">
                <div class="user-type-card" data-type="admin">
                    <div class="user-type-icon">
                        <span class="material-symbols-outlined">admin_panel_settings</span>
                    </div>
                    <div class="user-type-title">Administrator</div>
                    <div class="user-type-subtitle">Acces complet sistem</div>
                </div>
                <div class="user-type-card selected" data-type="worker">
                    <div class="user-type-icon">
                        <span class="material-symbols-outlined">person</span>
                    </div>
                    <div class="user-type-title">Lucrător Depozit</div>
                    <div class="user-type-subtitle">Operații depozit</div>
                </div>
            </div>
        </div>

        <!-- Status Messages -->
        <div id="status-message-container"></div>

        <!-- Login Form -->
        <form class="login-form" id="login-form">
            <div class="form-group">
                <label class="form-label" for="username">Nume utilizator sau Email</label>
                <div style="position: relative;">
                    <span class="input-icon material-symbols-outlined">person</span>
                    <input type="text" class="form-input" id="username" name="username" 
                           placeholder="Introduceți numele de utilizator" autocomplete="username" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Parolă</label>
                <div style="position: relative;">
                    <span class="input-icon material-symbols-outlined">lock</span>
                    <input type="password" class="form-input" id="password" name="password" 
                           placeholder="Introduceți parola" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="password-toggle">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
                </div>
            </div>

            <div class="remember-section">
                <div class="remember-checkbox">
                    <input type="checkbox" id="remember-me" name="remember">
                    <label for="remember-me">Ține-mă minte</label>
                </div>
                <a href="#" class="forgot-password" id="forgot-password-link">Parolă uitată?</a>
            </div>

            <button type="submit" class="btn btn-primary" id="login-btn">
                <span class="material-symbols-outlined">login</span>
                Autentificare
            </button>
        </form>

        <!-- Footer -->
        <div class="login-footer">
            <div class="footer-links">
                <a href="#" class="footer-link">Suport</a>
                <a href="#" class="footer-link">Ajutor</a>
                <a href="#" class="footer-link">Termeni</a>
            </div>
            <div class="footer-text">
                WMS © 2025 - Sistem de Management Depozit
            </div>
        </div>
    </div>

    <script>
        class LoginInterface {
            constructor() {
                this.selectedUserType = 'worker'; // Default to worker
                this.init();
            }

            init() {
                this.initEventListeners();
                this.loadRememberedCredentials();
            }

            initEventListeners() {
                // User type selection
                document.querySelectorAll('.user-type-card').forEach(card => {
                    card.addEventListener('click', (e) => this.selectUserType(e.currentTarget.dataset.type));
                });

                // Form submission
                document.getElementById('login-form').addEventListener('submit', (e) => this.handleLogin(e));

                // Password toggle
                document.getElementById('password-toggle').addEventListener('click', () => this.togglePassword());

                // Forgot password
                document.getElementById('forgot-password-link').addEventListener('click', (e) => {
                    e.preventDefault();
                    this.handleForgotPassword();
                });

                // Enter key handling
                document.getElementById('username').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        document.getElementById('password').focus();
                    }
                });

                document.getElementById('password').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') {
                        this.handleLogin(e);
                    }
                });
            }

            selectUserType(type) {
                this.selectedUserType = type;
                
                // Update UI
                document.querySelectorAll('.user-type-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.querySelector(`[data-type="${type}"]`).classList.add('selected');

                // Update form placeholder based on user type
                const usernameInput = document.getElementById('username');
                if (type === 'admin') {
                    usernameInput.placeholder = 'Administrator username';
                } else {
                    usernameInput.placeholder = 'ID lucrător sau email';
                }
            }

            async handleLogin(e) {
                e.preventDefault();
                
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value;
                const remember = document.getElementById('remember-me').checked;

                // Validation
                if (!username || !password) {
                    this.showStatusMessage('error', 'Completați toate câmpurile obligatorii.');
                    return;
                }

                try {
                    this.setLoading(true);
                    this.showStatusMessage('info', 'Se verifică credențialele...');

                    const response = await this.authenticate(username, password, this.selectedUserType);

                    if (response.success) {
                        // Save session data
                        this.saveSession(response.data, remember);
                        
                        // Show success message
                        this.showStatusMessage('success', 'Autentificare reușită! Se redirecționează...');
                        
                        // Redirect based on user role
                        setTimeout(() => {
                            this.redirectUser(response.data);
                        }, 1000);
                    } else {
                        this.showStatusMessage('error', response.message || 'Credențiale invalide.');
                        this.clearFormErrors();
                        this.highlightFormErrors();
                    }
                } catch (error) {
                    this.showStatusMessage('error', 'Eroare de conexiune. Încercați din nou.');
                    console.error('Login error:', error);
                } finally {
                    this.setLoading(false);
                }
            }

            async authenticate(username, password, userType) {
                const formData = new FormData();
                formData.append('username', username);
                formData.append('password', password);
                formData.append('user_type', userType);

                const response = await fetch('login.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.text();
                
                // Check if login was successful (assuming your login.php redirects on success)
                if (response.redirected || result.includes('Location:') || result.includes('redirect')) {
                    // Extract user data from session or response
                    return {
                        success: true,
                        data: {
                            username: username,
                            role: userType === 'admin' ? 'admin' : 'warehouse_worker',
                            first_name: username,
                            user_type: userType
                        }
                    };
                } else {
                    // Parse error message from response if available
                    return {
                        success: false,
                        message: 'Credențiale invalide sau cont inactiv.'
                    };
                }
            }

            redirectUser(userData) {
                // Determine redirect based on user role/quality
                if (this.isWarehouseWorker(userData)) {
                    // Redirect to warehouse worker hub
                    window.location.href = 'warehouse_hub.html';
                } else {
                    // Redirect to existing admin dashboard
                    window.location.href = 'index.php';
                }
            }

            isWarehouseWorker(userData) {
                // Check if user has warehouse worker role/quality
                return userData.role === 'warehouse_worker' || 
                       userData.role === 'worker' ||
                       userData.user_type === 'worker' ||
                       this.selectedUserType === 'worker';
            }

            saveSession(userData, remember) {
                // Save remember me data if checked
                if (remember) {
                    localStorage.setItem('wms_remember_user', document.getElementById('username').value);
                    localStorage.setItem('wms_remember_type', this.selectedUserType);
                } else {
                    localStorage.removeItem('wms_remember_user');
                    localStorage.removeItem('wms_remember_type');
                }

                // Set worker name for worker interfaces
                if (this.isWarehouseWorker(userData)) {
                    localStorage.setItem('workerName', userData.first_name || userData.username || 'Lucrător');
                }
            }

            loadRememberedCredentials() {
                const rememberedUser = localStorage.getItem('wms_remember_user');
                const rememberedType = localStorage.getItem('wms_remember_type');

                if (rememberedUser) {
                    document.getElementById('username').value = rememberedUser;
                    document.getElementById('remember-me').checked = true;
                }

                if (rememberedType) {
                    this.selectUserType(rememberedType);
                }
            }

            togglePassword() {
                const passwordInput = document.getElementById('password');
                const toggleIcon = document.querySelector('.password-toggle .material-symbols-outlined');
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    toggleIcon.textContent = 'visibility_off';
                } else {
                    passwordInput.type = 'password';
                    toggleIcon.textContent = 'visibility';
                }
            }

            handleForgotPassword() {
                // In a real implementation, this would open a forgot password modal or redirect
                alert('Funcția de recuperare a parolei va fi disponibilă în curând.\n\nContactați administratorul pentru resetarea parolei.');
            }

            showStatusMessage(type, message) {
                const container = document.getElementById('status-message-container');
                container.innerHTML = `
                    <div class="status-message status-${type}">
                        <span class="material-symbols-outlined">${this.getStatusIcon(type)}</span>
                        ${message}
                    </div>
                `;

                // Auto-hide success/info messages
                if (type === 'success' || type === 'info') {
                    setTimeout(() => {
                        container.innerHTML = '';
                    }, 3000);
                }
            }

            getStatusIcon(type) {
                switch(type) {
                    case 'success': return 'check_circle';
                    case 'error': return 'error';
                    case 'info': return 'info';
                    default: return 'info';
                }
            }

            clearFormErrors() {
                document.querySelectorAll('.form-input').forEach(input => {
                    input.classList.remove('error');
                });
            }

            highlightFormErrors() {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value;

                if (!username) {
                    document.getElementById('username').classList.add('error');
                }
                if (!password) {
                    document.getElementById('password').classList.add('error');
                }
            }

            setLoading(loading) {
                const loginBtn = document.getElementById('login-btn');
                const form = document.getElementById('login-form');
                
                if (loading) {
                    loginBtn.disabled = true;
                    loginBtn.classList.add('loading');
                    form.style.pointerEvents = 'none';
                } else {
                    loginBtn.disabled = false;
                    loginBtn.classList.remove('loading');
                    form.style.pointerEvents = 'auto';
                }
            }
        }

        // Initialize the login interface when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new LoginInterface();
        });
    </script>
</body>
</html>