<?php
/**
 * Biometric Authentication Setup Page
 * Allows users to register their biometric credentials
 * File: biometric-setup.php
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Get database connection
$config = require BASE_PATH . '/config/config.php';
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include WebAuthn service
require_once BASE_PATH . '/models/WebAuthnService.php';
$webauthnService = new WebAuthnService($db);

// Check current WebAuthn status
$hasWebAuthn = $webauthnService->hasWebAuthn($_SESSION['user_id']);
$credentials = $webauthnService->getUserCredentials($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurare Autentificare Biometrică - WMS</title>
    
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
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .setup-card {
            background: var(--container-background);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: var(--card-shadow);
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .header p {
            color: var(--text-secondary);
            font-size: 16px;
        }

        .status-section {
            margin-bottom: 32px;
        }

        .status-card {
            background: var(--surface-background);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .status-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .status-icon.enabled {
            background: rgba(25, 135, 84, 0.1);
            color: var(--success-color);
        }

        .status-icon.disabled {
            background: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
        }

        .status-content h3 {
            margin: 0 0 4px 0;
            font-size: 18px;
            font-weight: 600;
        }

        .status-content p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 14px;
        }

        .setup-section {
            margin-bottom: 32px;
        }

        .setup-section h2 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .feature-list {
            list-style: none;
            padding: 0;
            margin: 16px 0;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            color: var(--text-secondary);
        }

        .feature-list .material-symbols-outlined {
            color: var(--success-color);
            font-size: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: #0b5ed7;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover:not(:disabled) {
            background: #bb2d3b;
        }

        .btn-secondary {
            background: var(--surface-background);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-secondary:hover:not(:disabled) {
            background: var(--button-hover);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .loading-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            display: none;
        }

        .btn.loading .loading-spinner {
            display: block;
        }

        .btn.loading .material-symbols-outlined {
            display: none;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .credentials-list {
            margin-top: 24px;
        }

        .credential-item {
            background: var(--surface-background);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .credential-info h4 {
            margin: 0 0 4px 0;
            font-weight: 600;
        }

        .credential-info p {
            margin: 0;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid;
        }

        .alert-success {
            background: rgba(25, 135, 84, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }

        .alert-warning {
            background: rgba(255, 193, 7, 0.1);
            border-color: var(--warning-color);
            color: #b76e00;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .back-link:hover {
            color: var(--text-primary);
        }

        .device-name-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--input-border);
            border-radius: 8px;
            font-size: 16px;
            background: var(--input-background);
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .device-name-input::placeholder {
            color: var(--text-muted);
        }

        .device-name-input:focus {
            outline: none;
            border-color: var(--input-focus);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="<?= getNavUrl('index.php') ?>" class="back-link">
            <span class="material-symbols-outlined">arrow_back</span>
            Înapoi la dashboard
        </a>

        <div class="setup-card">
            <div class="header">
                <h1>Autentificare Biometrică</h1>
                <p>Configurați autentificarea cu amprenta digitală sau recunoașterea facială pentru acces rapid și sigur</p>
            </div>

            <!-- Status Section -->
            <div class="status-section">
                <div class="status-card">
                    <?php if ($hasWebAuthn): ?>
                        <div class="status-icon enabled">
                            <span class="material-symbols-outlined">verified_user</span>
                        </div>
                        <div class="status-content">
                            <h3>Autentificarea biometrică este activă</h3>
                            <p>Aveți <?= count($credentials) ?> dispozitiv<?= count($credentials) != 1 ? 'e' : '' ?> înregistrat<?= count($credentials) != 1 ? 'e' : '' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="status-icon disabled">
                            <span class="material-symbols-outlined">security</span>
                        </div>
                        <div class="status-content">
                            <h3>Autentificarea biometrică nu este configurată</h3>
                            <p>Înregistrați un dispozitiv pentru acces rapid</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Browser Support Check -->
            <div id="browser-check" style="display: none;">
                <div class="alert alert-danger">
                    <strong>Browser necompatibil:</strong> Browser-ul dumneavoastră nu suportă autentificarea biometrică. 
                    Folosiți Chrome, Firefox, Safari sau Edge într-o versiune recentă.
                </div>
            </div>

            <!-- Platform Check -->
            <div id="platform-check" style="display: none;">
                <div class="alert alert-warning">
                    <strong>Dispozitiv necompatibil:</strong> Dispozitivul dumneavoastră nu are senzori biometrici disponibili 
                    sau nu sunt activați.
                </div>
            </div>

            <!-- Success/Error Messages -->
            <div id="message-area"></div>

            <!-- Setup Section -->
            <div class="setup-section">
                <h2>
                    <span class="material-symbols-outlined">fingerprint</span>
                    Beneficii
                </h2>
                <ul class="feature-list">
                    <li>
                        <span class="material-symbols-outlined">check_circle</span>
                        <span>Acces instant fără parolă</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">check_circle</span>
                        <span>Securitate maximă cu biometrie</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">check_circle</span>
                        <span>Funcționează offline</span>
                    </li>
                    <li>
                        <span class="material-symbols-outlined">check_circle</span>
                        <span>Datele biometrice rămân pe dispozitiv</span>
                    </li>
                </ul>

                <div id="setup-controls">
                    <?php if (!$hasWebAuthn): ?>
                        <input type="text" id="device-name" class="device-name-input" 
                               placeholder="Nume dispozitiv (opțional)" 
                               value="<?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Dispozitivul meu') ?>">
                        <button id="register-btn" class="btn btn-primary">
                            <span class="material-symbols-outlined">fingerprint</span>
                            <div class="loading-spinner"></div>
                            Înregistrează autentificarea biometrică
                        </button>
                    <?php else: ?>
                        <button id="add-device-btn" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            <div class="loading-spinner"></div>
                            Adaugă un dispozitiv nou
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Registered Devices -->
            <?php if ($hasWebAuthn && !empty($credentials)): ?>
            <div class="setup-section">
                <h2>
                    <span class="material-symbols-outlined">devices</span>
                    Dispozitive înregistrate
                </h2>
                <div class="credentials-list">
                    <?php foreach ($credentials as $credential): ?>
                    <div class="credential-item" data-credential-id="<?= htmlspecialchars($credential['credential_id']) ?>">
                        <div class="credential-info">
                            <h4><?= htmlspecialchars($credential['device_name'] ?: 'Dispozitiv necunoscut') ?></h4>
                            <p>
                                Înregistrat: <?= date('d.m.Y H:i', strtotime($credential['created_at'])) ?>
                                <?php if ($credential['last_used_at']): ?>
                                    | Ultima utilizare: <?= date('d.m.Y H:i', strtotime($credential['last_used_at'])) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <button class="btn btn-danger remove-credential-btn" 
                                data-credential-id="<?= htmlspecialchars($credential['credential_id']) ?>">
                            <span class="material-symbols-outlined">delete</span>
                            Șterge
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        class BiometricSetup {
            constructor() {
                this.init();
            }

            init() {
                this.checkBrowserSupport();
                this.initEventListeners();
            }

            async checkBrowserSupport() {
                // Check WebAuthn support
                if (!window.PublicKeyCredential) {
                    document.getElementById('browser-check').style.display = 'block';
                    document.getElementById('setup-controls').style.display = 'none';
                    return;
                }

                // Check platform authenticator support
                try {
                    const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    if (!available) {
                        document.getElementById('platform-check').style.display = 'block';
                        document.getElementById('setup-controls').style.display = 'none';
                    }
                } catch (error) {
                    console.error('Error checking platform support:', error);
                    document.getElementById('platform-check').style.display = 'block';
                    document.getElementById('setup-controls').style.display = 'none';
                }
            }

            initEventListeners() {
                // Register new credential
                const registerBtn = document.getElementById('register-btn');
                const addDeviceBtn = document.getElementById('add-device-btn');
                
                if (registerBtn) {
                    registerBtn.addEventListener('click', () => this.registerCredential());
                }
                
                if (addDeviceBtn) {
                    addDeviceBtn.addEventListener('click', () => this.registerCredential());
                }

                // Remove credentials
                document.querySelectorAll('.remove-credential-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const credentialId = e.target.closest('button').dataset.credentialId;
                        this.removeCredential(credentialId);
                    });
                });
            }

            async registerCredential() {
                const btn = document.getElementById('register-btn') || document.getElementById('add-device-btn');
                const originalContent = btn.innerHTML;
                
                try {
                    // Show loading state
                    btn.classList.add('loading');
                    btn.disabled = true;

                    // Get device name
                    const deviceNameInput = document.getElementById('device-name');
                    const deviceName = deviceNameInput ? deviceNameInput.value.trim() : 'Dispozitivul meu';

                    // Begin registration
                    const beginResponse = await fetch('api/webauthn.php?action=register-begin', {
                        method: 'POST',
                        credentials: 'same-origin'
                    });

                    const beginData = await beginResponse.json();
                    if (!beginData.success) {
                        throw new Error(beginData.error || 'Failed to begin registration');
                    }

                    // Convert base64url to ArrayBuffer for WebAuthn API
                    const options = this.prepareRegistrationOptions(beginData.options);

                    // Call WebAuthn API
                    const credential = await navigator.credentials.create({
                        publicKey: options
                    });

                    if (!credential) {
                        throw new Error('Registration was cancelled');
                    }

                    // Prepare response data
                    const regResponse = {
                        id: credential.id,
                        rawId: this.arrayBufferToBase64url(credential.rawId),
                        response: {
                            clientDataJSON: this.arrayBufferToBase64url(credential.response.clientDataJSON),
                            attestationObject: this.arrayBufferToBase64url(credential.response.attestationObject)
                        },
                        type: credential.type
                    };

                    // Complete registration
                    const completeResponse = await fetch('api/webauthn.php?action=register-complete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            response: regResponse,
                            deviceName: deviceName || 'Dispozitivul meu'
                        }),
                        credentials: 'same-origin'
                    });

                    const completeData = await completeResponse.json();
                    if (!completeData.success) {
                        throw new Error(completeData.error || 'Registration failed');
                    }

                    // Success
                    this.showMessage('Autentificarea biometrică a fost configurată cu succes!', 'success');
                    
                    // Reload page to show updated status
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);

                } catch (error) {
                    console.error('Registration failed:', error);
                    
                    let errorMessage = 'Eroare la înregistrarea autentificării biometrice. ';
                    if (error.name === 'NotAllowedError') {
                        errorMessage += 'Înregistrarea a fost anulată.';
                    } else if (error.name === 'SecurityError') {
                        errorMessage += 'Eroare de securitate.';
                    } else if (error.name === 'AbortError') {
                        errorMessage += 'Operațiunea a fost întreruptă.';
                    } else {
                        errorMessage += error.message || 'Încercați din nou.';
                    }
                    
                    this.showMessage(errorMessage, 'danger');
                } finally {
                    // Restore button
                    btn.innerHTML = originalContent;
                    btn.classList.remove('loading');
                    btn.disabled = false;
                }
            }

            async removeCredential(credentialId) {
                if (!confirm('Sigur doriți să ștergeți acest dispozitiv?')) {
                    return;
                }

                try {
                    const response = await fetch('api/webauthn.php?action=remove-credential', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ credentialId }),
                        credentials: 'same-origin'
                    });

                    const data = await response.json();
                    if (!data.success) {
                        throw new Error(data.error || 'Failed to remove credential');
                    }

                    // Remove from UI
                    const credentialItem = document.querySelector(`[data-credential-id="${credentialId}"]`);
                    if (credentialItem) {
                        credentialItem.remove();
                    }

                    this.showMessage('Dispozitivul a fost șters cu succes.', 'success');

                } catch (error) {
                    console.error('Remove credential failed:', error);
                    this.showMessage('Eroare la ștergerea dispozitivului: ' + error.message, 'danger');
                }
            }

            prepareRegistrationOptions(options) {
                const prepared = {
                    ...options,
                    challenge: this.base64urlToArrayBuffer(options.challenge),
                    user: {
                        ...options.user,
                        id: this.base64urlToArrayBuffer(options.user.id)
                    }
                };

                if (options.excludeCredentials) {
                    prepared.excludeCredentials = options.excludeCredentials.map(cred => ({
                        ...cred,
                        id: this.base64urlToArrayBuffer(cred.id)
                    }));
                }

                return prepared;
            }

            base64urlToArrayBuffer(base64url) {
                const padding = '='.repeat((4 - base64url.length % 4) % 4);
                const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
                const rawData = window.atob(base64);
                const outputArray = new Uint8Array(rawData.length);
                for (let i = 0; i < rawData.length; ++i) {
                    outputArray[i] = rawData.charCodeAt(i);
                }
                return outputArray.buffer;
            }

            arrayBufferToBase64url(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.byteLength; i++) {
                    binary += String.fromCharCode(bytes[i]);
                }
                const base64 = window.btoa(binary);
                return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
            }

            showMessage(message, type) {
                const messageArea = document.getElementById('message-area');
                messageArea.innerHTML = `
                    <div class="alert alert-${type}">
                        ${message}
                    </div>
                `;

                // Auto-hide success messages
                if (type === 'success') {
                    setTimeout(() => {
                        messageArea.innerHTML = '';
                    }, 5000);
                }
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            new BiometricSetup();
        });
    </script>
</body>
</html>