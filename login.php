<?php
/**
 * Enhanced Login with Debug - Complete Version
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

// Initialize variables
$error = '';
$debug = [];

// Handle POST request (login processing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $extendedSession = isset($_POST['extended_session']);

    if (empty($username) || empty($password)) {
        $error = 'Completați toate câmpurile obligatorii.';
    } else {
        $debug[] = "Input username: '$username'";
        $debug[] = "Input password length: " . strlen($password);
        
        // Get database connection
        $config = require BASE_PATH . '/config/config.php';
        
        if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
            $error = 'Eroare de configurare a bazei de date.';
            $debug[] = "❌ Database configuration error";
        } else {
            try {
                $dbFactory = $config['connection_factory'];
                $db = $dbFactory();
                $debug[] = "✅ Database connection successful";
                
                // Include User model
                require_once BASE_PATH . '/models/User.php';
                $usersModel = new Users($db);
                
                // Find user by username or email
                $user = $usersModel->findByUsernameOrEmail($username);
                
                // Enhanced debugging
                if ($user) {
                    $debug[] = "✅ User found: ID={$user['id']}, Username={$user['username']}, Email={$user['email']}, Status={$user['status']}, Role={$user['role']}";
                    
                    // Check status
                    if ($user['status'] != 1) {
                        $debug[] = "❌ User status check failed: status = {$user['status']}";
                        $error = 'Cont inactiv.';
                    } else {
                        $debug[] = "✅ User status check passed";
                        
                        // Check password
                        $passwordValid = password_verify($password, $user['password']);
                        $debug[] = "Password verification: " . ($passwordValid ? "✅ VALID" : "❌ INVALID");
                        
                        if ($passwordValid) {
                            $debug[] = "✅ All checks passed - should login successfully";

                            session_regenerate_id(true);

                            // Successful login - set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['login_time'] = time();

                            if ($extendedSession) {
                                $_SESSION['extended_session'] = true;
                            } else {
                                unset($_SESSION['extended_session']);
                            }

                            $debug[] = "Session variables set";

                            try {
                                $usersModel->purgeExpiredRememberTokens();
                            } catch (Throwable $tokenPurgeError) {
                                error_log('Failed to purge expired remember tokens: ' . $tokenPurgeError->getMessage());
                            }

                            $rememberLifetime = 30 * 24 * 60 * 60;

                            if ($extendedSession) {
                                try {
                                    $usersModel->deleteRememberTokens($user['id']);

                                    $selector = bin2hex(random_bytes(9));
                                    $validator = bin2hex(random_bytes(32));
                                    $validatorHash = hash('sha256', $validator);
                                    $expiresAt = date('Y-m-d H:i:s', time() + $rememberLifetime);

                                    if ($usersModel->createRememberToken($user['id'], $selector, $validatorHash, $expiresAt)) {
                                        queueRememberMeCookie($selector . ':' . $validator, time() + $rememberLifetime);
                                        $debug[] = "Extended session token issued";
                                    } else {
                                        $debug[] = "Failed to persist remember token";
                                    }
                                } catch (Throwable $rememberError) {
                                    $debug[] = "Remember token error: " . $rememberError->getMessage();
                                    error_log('Remember token error: ' . $rememberError->getMessage());
                                }
                            } else {
                                try {
                                    $usersModel->deleteRememberTokens($user['id']);
                                } catch (Throwable $tokenCleanupError) {
                                    error_log('Failed to clear remember tokens: ' . $tokenCleanupError->getMessage());
                                }
                                forgetRememberMeCookie();
                                $debug[] = "Extended session cleared";
                            }

                            // Update last login
                            $usersModel->updateLastLogin($user['id']);
                            $debug[] = "Last login updated";

                            // Log login event (if function exists)
                            if (function_exists('logActivity')) {
                                logActivity(
                                    $user['id'],
                                    'login',
                                    'user',
                                    $user['id'],
                                    'User logged in'
                                );
                                $debug[] = "Activity logged";
                            } else {
                                $debug[] = "logActivity function not found - skipping";
                            }
                            
                            // Log all debug info
                            error_log("LOGIN SUCCESS DEBUG: " . implode(" | ", $debug));
                            
                            // Redirect based on user role
                            $redirectUrl = '';
                            if ($user['role'] === 'admin') {
                                $redirectUrl = getNavUrl('index.php');
                            } else {
                                $redirectUrl = getNavUrl('warehouse_hub.php');
                            }
                            
                            $debug[] = "Redirecting to: $redirectUrl";
                            
                            // Clear debug info from session before redirect
                            unset($_SESSION['debug']);
                            
                            header('Location: ' . $redirectUrl);
                            exit;
                        } else {
                            $error = 'Parolă incorectă.';
                        }
                    }
                } else {
                    $debug[] = "❌ User NOT found in database";
                    
                    // Additional debug: show what users exist
                    try {
                        $stmt = $db->query("SELECT username, email FROM users WHERE status = 1 LIMIT 5");
                        $existingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $userList = [];
                        foreach ($existingUsers as $u) {
                            $userList[] = $u['username'] . ' (' . $u['email'] . ')';
                        }
                        $debug[] = "Available users: " . implode(', ', $userList);
                    } catch (Exception $e) {
                        $debug[] = "Could not fetch available users: " . $e->getMessage();
                    }
                    
                    $error = 'Utilizator inexistent.';
                }
                
                // Log debug info for failed login
                error_log("LOGIN FAILED DEBUG: " . implode(" | ", $debug));
                
            } catch (Exception $e) {
                $debug[] = "Exception: " . $e->getMessage();
                error_log("LOGIN EXCEPTION DEBUG: " . implode(" | ", $debug));
                error_log("Login error: " . $e->getMessage());
                $error = 'Eroare de sistem. Încercați din nou.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autentificare - WMS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="styles/global.css">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--app-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: var(--text-primary);
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            background: var(--surface-background);
            border-radius: var(--border-radius-large);
            border: 1px solid var(--border-color-strong);
            box-shadow: var(--base-shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .login-header {
            padding: 32px;
            border-bottom: 1px solid var(--border-color-strong);
            background: var(--container-background);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), rgba(13, 110, 253, 0.75));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 20px;
            letter-spacing: 0.05em;
        }

        .brand-text h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .brand-text p {
            margin: 4px 0 0 0;
            font-size: 13px;
            color: var(--text-secondary);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .login-body {
            padding: 32px;
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .status-message {
            padding: 12px 16px;
            border-radius: var(--border-radius);
            border: 1px solid transparent;
            font-size: 14px;
        }

        .status-message.error {
            border-color: rgba(220, 53, 69, 0.35);
            background: rgba(220, 53, 69, 0.12);
            color: var(--danger-color);
        }

        .debug-message {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color-strong);
            border-radius: var(--border-radius);
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 12px;
            font-family: "ui-monospace", "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            max-height: 220px;
            overflow-y: auto;
        }

        .debug-message h4 {
            margin: 0 0 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-stack {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
        }

        .form-input {
            width: 100%;
            border: 1px solid var(--border-color-strong);
            border-radius: var(--border-radius);
            background: var(--input-background);
            color: var(--text-primary);
            padding: 12px 44px 12px 16px;
            font-size: 15px;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .form-input::placeholder {
            color: var(--text-muted);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--container-background);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.15);
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: var(--border-radius);
            transition: color 0.2s ease, background 0.2s ease;
        }

        .password-toggle:hover {
            color: var(--text-primary);
            background: var(--button-hover);
        }

        .options {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-top: 8px;
        }

        .checkbox-group {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .checkbox-group input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        .options-inline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .link-inline {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
        }

        .link-inline:hover {
            text-decoration: underline;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 15px;
            font-weight: 600;
            background: var(--primary-color);
            color: #ffffff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .btn:hover:not(:disabled) {
            background: #0b5ed7;
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-biometric {
            background: transparent;
            border: 1px solid var(--border-color-strong);
            color: var(--text-secondary);
        }

        .btn-biometric:hover:not(:disabled) {
            background: var(--button-hover);
            color: var(--text-primary);
        }

        .biometric-section {
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            display: none;
        }

        .or-divider {
            position: relative;
            margin: 0 0 16px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .or-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border-color);
        }

        .or-divider span {
            position: relative;
            padding: 0 16px;
            background: var(--surface-background);
        }

        .login-footer {
            padding: 24px 32px 28px;
            border-top: 1px solid var(--border-color-strong);
            background: var(--container-background);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .footer-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 12px;
        }

        .footer-link:hover {
            color: var(--text-primary);
            text-decoration: underline;
        }

        .footer-text {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        @media (max-width: 480px) {
            .login-container {
                border-radius: var(--border-radius);
            }

            .login-header,
            .login-body,
            .login-footer {
                padding: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="brand">
                <div class="brand-mark">W</div>
                <div class="brand-text">
                    <h1>Wartung WMS</h1>
                    <p>Sistem de management depozit</p>
                </div>
            </div>
        </div>

        <div class="login-body">
            <?php if (!empty($debug)): ?>
                <div class="debug-message">
                    <h4>Detalii depanare</h4>
                    <?php foreach ($debug as $d): ?>
                        <div><?= htmlspecialchars($d) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="status-message error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on">
                <div class="form-stack">
                    <div class="form-group">
                        <label class="form-label" for="username">Utilizator</label>
                        <input type="text" class="form-input" id="username" name="username"
                               placeholder="Nume utilizator sau email"
                               autocomplete="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Parolă</label>
                        <input type="password" class="form-input" id="password" name="password"
                               placeholder="Introduceți parola"
                               autocomplete="current-password"
                               required>
                        <button type="button" id="password-toggle" class="password-toggle" aria-label="Comută vizibilitatea parolei">Arată</button>
                    </div>
                </div>

                <div class="options">
                    <label class="checkbox-group" for="extended-session">
                        <input type="checkbox" id="extended-session" name="extended_session" <?= isset($_POST['extended_session']) ? 'checked' : '' ?>>
                        <span>Ține-mă conectat 30 de zile</span>
                    </label>

                    <div class="options-inline">
                        <label class="checkbox-group" for="remember-me">
                            <input type="checkbox" id="remember-me" name="remember" <?= isset($_POST['remember']) ? 'checked' : '' ?>>
                            <span>Reține utilizatorul</span>
                        </label>
                        <a href="#" class="link-inline" onclick="alert('Contactați administratorul pentru resetarea parolei.'); return false;">Parolă uitată?</a>
                    </div>
                </div>

                <button type="submit" class="btn">Autentificare</button>
            </form>

            <div class="biometric-section" id="biometric-section">
                <div class="or-divider">
                    <span>sau</span>
                </div>
                <button type="button" class="btn btn-biometric" id="biometric-login">
                    Autentificare biometrică
                </button>
            </div>
        </div>

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
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.getElementById('password-toggle');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.textContent = 'Ascunde';
            } else {
                passwordInput.type = 'password';
                toggleButton.textContent = 'Arată';
            }
        }

        const passwordToggleButton = document.getElementById('password-toggle');
        if (passwordToggleButton) {
            passwordToggleButton.addEventListener('click', togglePassword);
        }

        window.addEventListener('DOMContentLoaded', function() {
            const usernameInput = document.getElementById('username');
            const rememberedUser = localStorage.getItem('wms_remember_user');

            if (rememberedUser && usernameInput && !usernameInput.value) {
                usernameInput.value = rememberedUser;
                const rememberCheckbox = document.getElementById('remember-me');
                if (rememberCheckbox) {
                    rememberCheckbox.checked = true;
                }
            }

            checkBiometricSupport();
        });

        const loginForm = document.querySelector('form');
        if (loginForm) {
            loginForm.addEventListener('submit', function() {
                const rememberCheckbox = document.getElementById('remember-me');
                const usernameInput = document.getElementById('username');

                if (rememberCheckbox && rememberCheckbox.checked) {
                    localStorage.setItem('wms_remember_user', usernameInput ? usernameInput.value : '');
                } else {
                    localStorage.removeItem('wms_remember_user');
                }
            });
        }

        async function checkBiometricSupport() {
            if (window.PublicKeyCredential) {
                try {
                    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    if (available) {
                        const section = document.getElementById('biometric-section');
                        if (section) {
                            section.style.display = 'block';
                        }

                        const biometricButton = document.getElementById('biometric-login');
                        if (biometricButton) {
                            biometricButton.addEventListener('click', handleBiometricLogin);
                        }
                    }
                } catch (error) {
                    console.log('Biometric check failed:', error);
                }
            }
        }

        async function handleBiometricLogin() {
            const btn = document.getElementById('biometric-login');
            if (!btn) {
                return;
            }

            const originalText = btn.textContent;

            try {
                btn.textContent = 'Autentificare...';
                btn.disabled = true;

                await new Promise(resolve => setTimeout(resolve, 1500));
                alert('Funcționalitatea de autentificare biometrică nu este încă disponibilă.');
            } catch (error) {
                console.error('Biometric login failed:', error);
                alert('A apărut o problemă la autentificarea biometrică.');
            } finally {
                btn.textContent = originalText;
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
