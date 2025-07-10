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
    $remember = isset($_POST['remember']);
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
                            
                            // Successful login - set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['login_time'] = time();
                            
                            $debug[] = "Session variables set";
                            
                            // Extended session for "Keep me logged in"
                            if ($extendedSession) {
                                $_SESSION['extended_session'] = true;
                                ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
                                session_set_cookie_params(30 * 24 * 60 * 60);
                                $debug[] = "Extended session enabled";
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
    
    <!-- Material Symbols -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Import global WMS styles -->
    <link rel="stylesheet" href="styles/global.css">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: var(--app-background);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: var(--container-background);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            position: relative;
        }

        .login-header {
            background: var(--surface-background);
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 32px 24px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .login-header p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .login-body {
            padding: 24px;
        }

        /* Error Message */
        .status-message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .status-message.error {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }

        /* Debug Message */
        .debug-message {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 20px;
            font-size: 12px;
            font-family: monospace;
            max-height: 200px;
            overflow-y: auto;
        }

        .debug-message h4 {
            margin: 0 0 8px 0;
            color: #495057;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }

        .debug-message div {
            margin-bottom: 2px;
            padding: 2px 0;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-label {
            display: block;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 1px solid var(--input-border);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
            background: var(--input-background);
            color: var(--text-primary);
            box-sizing: border-box;
        }

        .form-input::placeholder {
            color: var(--text-muted);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--input-focus);
            background: var(--container-background);
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 20px;
            pointer-events: none;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }

        .password-toggle:hover {
            background: var(--button-hover);
            color: var(--text-primary);
        }

        .remember-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .remember-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .remember-checkbox input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary-color);
        }

        .remember-checkbox label {
            color: var(--text-secondary);
            cursor: pointer;
        }

        .forgot-password {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .extended-session {
            margin-bottom: 16px;
        }

        .extended-session label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-secondary);
            cursor: pointer;
        }

        .btn {
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #0b5ed7;
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .login-footer {
            padding: 20px 24px;
            border-top: 1px solid var(--border-color);
            background: var(--surface-background);
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 12px;
        }

        .footer-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
        }

        .footer-link:hover {
            color: var(--text-primary);
            text-decoration: underline;
        }

        .footer-text {
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* Biometric Login Section */
        .biometric-section {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
            display: none; /* Hidden by default, shown by JS if supported */
        }

        .btn-biometric {
            background: var(--surface-background);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            margin-top: 12px;
        }

        .btn-biometric:hover:not(:disabled) {
            background: var(--button-hover);
            border-color: var(--border-color-strong);
        }

        .or-divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
            color: var(--text-muted);
            font-size: 14px;
        }

        .or-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: var(--border-color);
            z-index: 1;
        }

        .or-divider span {
            background: var(--container-background);
            padding: 0 16px;
            position: relative;
            z-index: 2;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="login-header">
            <h1>WMS</h1>
            <p>Conectează-te la contul tău</p>
        </div>

        <!-- Login Form -->
        <div class="login-body">
            <!-- Debug Message (remove in production) -->
            <?php if (!empty($debug)): ?>
                <div class="debug-message">
                    <h4>🔍 Debug Info:</h4>
                    <?php foreach ($debug as $d): ?>
                        <div><?= htmlspecialchars($d) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error)): ?>
                <div class="status-message error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on">
                <div class="form-group">
                    <label class="form-label" for="username">Utilizator</label>
                    <span class="input-icon material-symbols-outlined">person</span>
                    <input type="text" class="form-input" id="username" name="username" 
                           placeholder="Nume utilizator sau email" 
                           autocomplete="username" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Parolă</label>
                    <span class="input-icon material-symbols-outlined">lock</span>
                    <input type="password" class="form-input" id="password" name="password" 
                           placeholder="Introduceți parola" 
                           autocomplete="current-password" 
                           required>
                    <button type="button" class="password-toggle" onclick="togglePassword()">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
                </div>

                <div class="extended-session">
                    <label>
                        <input type="checkbox" name="extended_session">
                        Ține-mă conectat 30 de zile
                    </label>
                </div>

                <div class="remember-section">
                    <div class="remember-checkbox">
                        <input type="checkbox" id="remember-me" name="remember">
                        <label for="remember-me">Reține utilizatorul</label>
                    </div>
                    <a href="#" class="forgot-password" onclick="alert('Contactați administratorul pentru resetarea parolei.'); return false;">Parolă uitată?</a>
                </div>

                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined">login</span>
                    Autentificare
                </button>
            </form>

            <!-- Biometric Login Section -->
            <div class="biometric-section" id="biometric-section">
                <div class="or-divider">
                    <span>sau</span>
                </div>
                <button type="button" class="btn btn-biometric" id="biometric-login">
                    <span class="material-symbols-outlined">fingerprint</span>
                    Autentificare biometrică
                </button>
            </div>
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
        // Minimal JavaScript - no interference with browser autocomplete
        
        function togglePassword() {
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

        // Load remembered credentials
        window.addEventListener('DOMContentLoaded', function() {
            const rememberedUser = localStorage.getItem('wms_remember_user');
            if (rememberedUser) {
                document.getElementById('username').value = rememberedUser;
                document.getElementById('remember-me').checked = true;
            }

            // Check biometric support
            checkBiometricSupport();
        });

        // Save credentials on form submit
        document.querySelector('form').addEventListener('submit', function() {
            if (document.getElementById('remember-me').checked) {
                localStorage.setItem('wms_remember_user', document.getElementById('username').value);
            } else {
                localStorage.removeItem('wms_remember_user');
            }
        });

        // Biometric authentication
        async function checkBiometricSupport() {
            if (window.PublicKeyCredential) {
                try {
                    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    if (available) {
                        document.getElementById('biometric-section').style.display = 'block';
                        document.getElementById('biometric-login').addEventListener('click', handleBiometricLogin);
                    }
                } catch (error) {
                    console.log('Biometric check failed:', error);
                }
            }
        }

        async function handleBiometricLogin() {
            const btn = document.getElementById('biometric-login');
            const originalText = btn.innerHTML;
            
            try {
                btn.innerHTML = '<span class="material-symbols-outlined">hourglass_empty</span> Autentificare...';
                btn.disabled = true;

                const username = document.getElementById('username').value.trim();

                // Begin authentication
                const beginResponse = await fetch('api/webauthn.php?action=authenticate-begin', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username }),
                    credentials: 'same-origin'
                });

                const beginData = await beginResponse.json();
                if (!beginData.success) {
                    throw new Error(beginData.error || 'Authentication failed');
                }

                // Prepare options
                const options = {
                    ...beginData.options,
                    challenge: base64urlToArrayBuffer(beginData.options.challenge)
                };

                if (beginData.options.allowCredentials) {
                    options.allowCredentials = beginData.options.allowCredentials.map(cred => ({
                        ...cred,
                        id: base64urlToArrayBuffer(cred.id)
                    }));
                }

                // Get credential
                const credential = await navigator.credentials.get({ publicKey: options });
                if (!credential) throw new Error('Authentication cancelled');

                // Complete authentication
                const authResponse = {
                    id: credential.id,
                    rawId: arrayBufferToBase64url(credential.rawId),
                    response: {
                        authenticatorData: arrayBufferToBase64url(credential.response.authenticatorData),
                        clientDataJSON: arrayBufferToBase64url(credential.response.clientDataJSON),
                        signature: arrayBufferToBase64url(credential.response.signature)
                    },
                    type: credential.type
                };

                const completeResponse = await fetch('api/webauthn.php?action=authenticate-complete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ response: authResponse, username }),
                    credentials: 'same-origin'
                });

                const completeData = await completeResponse.json();
                if (!completeData.success) {
                    throw new Error(completeData.error || 'Authentication failed');
                }

                // Success - redirect
                window.location.href = completeData.redirect;

            } catch (error) {
                let errorMessage = 'Eroare la autentificarea biometrică.';
                if (error.name === 'NotAllowedError') {
                    errorMessage = 'Autentificarea a fost anulată.';
                } else if (error.message) {
                    errorMessage = error.message;
                }
                alert(errorMessage);
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        // Base64url helper functions
        function base64urlToArrayBuffer(base64url) {
            const padding = '='.repeat((4 - base64url.length % 4) % 4);
            const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray.buffer;
        }

        function arrayBufferToBase64url(buffer) {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++) {
                binary += String.fromCharCode(bytes[i]);
            }
            const base64 = window.btoa(binary);
            return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        }
    </script>
</body>
</html>