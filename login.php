<?php
/**
 * Login Page with Backend Authentication
 * File: login.php
 */

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    header('Location: ' . getNavUrl('index.php'));
    exit;
}

// Handle POST request (login processing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'admin';

    $error = '';

    if (empty($username) || empty($password)) {
        $error = 'Completați toate câmpurile obligatorii.';
    } else {
        // Get database connection
        $config = require BASE_PATH . '/config/config.php';
        
        if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
            $error = 'Eroare de configurare a bazei de date.';
        } else {
            try {
                $dbFactory = $config['connection_factory'];
                $db = $dbFactory();
                
                // Include User model
                require_once BASE_PATH . '/models/User.php';
                $usersModel = new Users($db);
                
                // Find user by username or email
                $user = $usersModel->findByUsernameOrEmail($username);
                
                if ($user && $user['status'] == 1 && password_verify($password, $user['password'])) {
                    // Check user type matches
                    if ($userType === 'admin' && $user['role'] !== 'admin') {
                        $error = 'Credențiale invalide pentru administrator.';
                    } elseif ($userType === 'worker' && $user['role'] === 'admin') {
                        $error = 'Utilizați tipul de cont corect.';
                    } else {
                        // Successful login - set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['user_type'] = $userType;
                        $_SESSION['login_time'] = time();
                        
                        // Update last login
                        $usersModel->updateLastLogin($user['id']);
                        
                        // Redirect based on user type
                        if ($userType === 'worker' || $user['role'] !== 'admin') {
                            header('Location: ' . getNavUrl('warehouse_hub.html'));
                        } else {
                            header('Location: ' . getNavUrl('index.php'));
                        }
                        exit;
                    }
                } else {
                    $error = 'Credențiale invalide sau cont inactiv.';
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'Eroare de sistem. Încercați din nou.';
            }
        }
    }
    
    // If there's an error and this is an AJAX request, return JSON
    if (!empty($error) && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autentificare - WMS</title>
    
    <!-- Material Symbols -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 420px;
            position: relative;
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px 30px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 16px;
        }

        .login-body {
            padding: 30px;
        }

        .user-type-selection {
            margin-bottom: 30px;
        }

        .user-type-label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
            display: block;
        }

        .user-type-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .user-type-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f9fafb;
        }

        .user-type-card:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .user-type-card.selected {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }

        .user-type-card .material-symbols-outlined {
            font-size: 32px;
            margin-bottom: 8px;
            display: block;
        }

        .user-type-card .type-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .user-type-card .type-desc {
            font-size: 12px;
            opacity: 0.8;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.2s;
            background: #f9fafb;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 20px;
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #6b7280;
            font-size: 20px;
        }

        .remember-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .remember-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .remember-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .remember-checkbox label {
            font-size: 14px;
            color: #6b7280;
        }

        .forgot-password {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .status-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-message.error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .status-message.success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .status-message.info {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
        }

        .login-footer {
            background: #f9fafb;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }

        .footer-links {
            margin-bottom: 12px;
        }

        .footer-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 14px;
            margin: 0 12px;
        }

        .footer-link:hover {
            color: #667eea;
        }

        .footer-text {
            color: #9ca3af;
            font-size: 12px;
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn-primary.loading .loading-spinner {
            display: block;
        }

        .btn-primary.loading .material-symbols-outlined {
            display: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <h1>Bun venit înapoi</h1>
            <p>Conectează-te la contul tău WMS</p>
        </div>

        <!-- Login Form -->
        <div class="login-body">
            <!-- User Type Selection -->
            <div class="user-type-selection">
                <label class="user-type-label">Selectează tipul de cont</label>
                <div class="user-type-options">
                    <div class="user-type-card" data-type="worker">
                        <span class="material-symbols-outlined">badge</span>
                        <div class="type-title">Lucrător</div>
                        <div class="type-desc">Personal depozit</div>
                    </div>
                    <div class="user-type-card" data-type="admin">
                        <span class="material-symbols-outlined">admin_panel_settings</span>
                        <div class="type-title">Administrator</div>
                        <div class="type-desc">Acces complet</div>
                    </div>
                </div>
            </div>

            <!-- Error Message -->
            <?php if (!empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                <div class="status-message error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Status Message Area (for JavaScript) -->
            <div id="status-message" style="display: none;"></div>

            <form id="login-form" method="POST" action="login.php">
                <input type="hidden" name="user_type" id="user_type" value="worker">
                
                <div class="form-group">
                    <label class="form-label" for="username">Nume utilizator sau email</label>
                    <span class="input-icon material-symbols-outlined">person</span>
                    <input type="text" class="form-input" id="username" name="username" 
                           placeholder="Administrator username" autocomplete="username" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Parolă</label>
                    <span class="input-icon material-symbols-outlined">lock</span>
                    <input type="password" class="form-input" id="password" name="password" 
                           placeholder="Introduceți parola" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="password-toggle">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
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
                    <div class="loading-spinner"></div>
                    Autentificare
                </button>
            </form>
        </div>

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
                this.selectedUserType = 'worker';
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
                        document.getElementById('login-form').dispatchEvent(new Event('submit'));
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

                // Update form
                document.getElementById('user_type').value = type;

                // Update form placeholder based on user type
                const usernameInput = document.getElementById('username');
                if (type === 'admin') {
                    usernameInput.placeholder = 'Administrator username';
                } else {
                    usernameInput.placeholder = 'ID lucrător sau email';
                }
            }

            handleLogin(e) {
                // Let the form submit normally - PHP will handle it
                const btn = document.getElementById('login-btn');
                btn.classList.add('loading');
                btn.disabled = true;
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
                } else {
                    this.selectUserType('worker'); // Default
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
                alert('Funcția de recuperare a parolei va fi disponibilă în curând.\n\nContactați administratorul pentru resetarea parolei.');
            }
        }

        // Initialize login interface
        document.addEventListener('DOMContentLoaded', () => {
            new LoginInterface();
        });
    </script>
</body>
</html>