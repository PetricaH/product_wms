<?php
/**
 * WebAuthn API Endpoints
 * Handles WebAuthn registration and authentication requests
 * File: api/webauthn.php
 */

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON response header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get database connection
$config = require BASE_PATH . '/config/config.php';
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Database configuration error']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include required classes
require_once BASE_PATH . '/models/User.php';
require_once BASE_PATH . '/models/WebAuthnService.php';

$usersModel = new Users($db);
$webauthnService = new WebAuthnService($db);

// Get action from URL
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'register-begin':
            handleRegistrationBegin();
            break;
            
        case 'register-complete':
            handleRegistrationComplete();
            break;
            
        case 'authenticate-begin':
            handleAuthenticationBegin();
            break;
            
        case 'authenticate-complete':
            handleAuthenticationComplete();
            break;
            
        case 'credentials':
            handleGetCredentials();
            break;
            
        case 'remove-credential':
            handleRemoveCredential();
            break;
            
        case 'check-support':
            handleCheckSupport();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("WebAuthn API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Begin WebAuthn registration
 */
function handleRegistrationBegin() {
    global $webauthnService, $usersModel;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not authenticated']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    $displayName = $_SESSION['email'] ?? $username;
    
    try {
        $options = $webauthnService->generateRegistrationOptions($userId, $username, $displayName);
        echo json_encode(['success' => true, 'options' => $options]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to generate registration options: ' . $e->getMessage()]);
    }
}

/**
 * Complete WebAuthn registration
 */
function handleRegistrationComplete() {
    global $webauthnService;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not authenticated']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['response'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $response = $input['response'];
    $deviceName = $input['deviceName'] ?? 'Unknown Device';
    
    try {
        $success = $webauthnService->verifyRegistration($userId, $response, $deviceName);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Biometric authentication registered successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Registration verification failed']);
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
    }
}

/**
 * Begin WebAuthn authentication
 */
function handleAuthenticationBegin() {
    global $webauthnService;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    
    try {
        $options = $webauthnService->generateAuthenticationOptions($username);
        echo json_encode(['success' => true, 'options' => $options]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to generate authentication options: ' . $e->getMessage()]);
    }
}

/**
 * Complete WebAuthn authentication
 */
function handleAuthenticationComplete() {
    global $webauthnService, $usersModel;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['response'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request data']);
        return;
    }
    
    $response = $input['response'];
    $username = $input['username'] ?? '';
    
    try {
        $user = $webauthnService->verifyAuthentication($response, $username);
        
        if ($user) {
            // Set session variables (same as regular login)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['webauthn_login'] = true;
            
            // Update last login
            $usersModel->updateLastLogin($user['id']);
            
            // Determine redirect URL based on role
            $redirectUrl = ($user['role'] === 'admin') ? 'index.php' : 'warehouse_hub.html';
            
            echo json_encode([
                'success' => true, 
                'message' => 'Authentication successful',
                'redirect' => $redirectUrl,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication failed']);
        }
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication failed: ' . $e->getMessage()]);
    }
}

/**
 * Get user's WebAuthn credentials
 */
function handleGetCredentials() {
    global $webauthnService;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not authenticated']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    try {
        $credentials = $webauthnService->getUserCredentials($userId);
        $hasWebAuthn = $webauthnService->hasWebAuthn($userId);
        
        echo json_encode([
            'success' => true,
            'hasWebAuthn' => $hasWebAuthn,
            'credentials' => $credentials
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get credentials: ' . $e->getMessage()]);
    }
}

/**
 * Remove a WebAuthn credential
 */
function handleRemoveCredential() {
    global $webauthnService;
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'User not authenticated']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['credentialId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Credential ID required']);
        return;
    }
    
    $userId = $_SESSION['user_id'];
    $credentialId = $input['credentialId'];
    
    try {
        $success = $webauthnService->removeCredential($userId, $credentialId);
        
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Credential removed successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to remove credential']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to remove credential: ' . $e->getMessage()]);
    }
}

/**
 * Check WebAuthn browser support
 */
function handleCheckSupport() {
    // This is handled client-side, but we can return server capabilities
    echo json_encode([
        'success' => true,
        'serverSupport' => true,
        'algorithms' => ['ES256', 'RS256'],
        'rpId' => $_SERVER['HTTP_HOST'] ?? 'localhost'
    ]);
}