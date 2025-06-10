<?php
// File: login.php

// Ensure BASE_PATH is defined and bootstrap.php is included for DB and User model
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is already logged in, redirect them based on role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: ' . getNavUrl('index.php')); // Use helper for full URL
        exit;
    } elseif ($_SESSION['role'] === 'user') { // Assuming 'user' is the role for mobile picker
        header('Location: ' . getNavUrl('mobile_picker.html')); // Or mobile_picker.php if you rename it
        exit;
    }
}

// Get database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly in config.php"); // Should not happen if bootstrap is correct
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once BASE_PATH . '/models/User.php';
$usersModel = new Users($db);

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? ''); // Can be username or email
    $password = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $login_error = 'Please enter both username/email and password.';
    } else {
        $user = $usersModel->findByUsernameOrEmail($identifier);

        if ($user && password_verify($password, $user['password'])) {
            // Password is correct, and user is active (checked in findByUsernameOrEmail)
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Regenerate session ID for security
            session_regenerate_id(true);

            if ($user['role'] === 'admin') {
                header('Location: ' . getNavUrl('index.php'));
                exit;
            } elseif ($user['role'] === 'user') {
                header('Location: ' . getNavUrl('mobile_picker.html')); // Or mobile_picker.php
                exit;
            } else {
                // Should not happen if roles are 'admin' or 'user'
                $login_error = 'Login successful, but role is undefined. Contact administrator.';
            }
        } else {
            $login_error = 'Invalid username/email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WMS Login</title>
    <?php
    // Assuming you might want to use the global header for styles,
    // but a dedicated login.css might be better for a distinct look.
    // For now, let's include the standard header.
    // If you create a specific login.css, include it here.
    // require_once BASE_PATH . '/includes/header.php';
    ?>
    <link rel="stylesheet" href="<?php echo getNavUrl('styles/global.css'); ?>"> <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            color: #333;
        }
        .login-container {
            background-color: #DDEBF3; /* Matching sidebar */
            padding: 2rem 2.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-container h1 {
            color: #00385E; /* Dark blue from sidebar palette */
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
            font-weight: 600;
        }
        .login-form .form-group {
            margin-bottom: 1.25rem;
            text-align: left;
        }
        .login-form label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #495057; /* Sidebar icon color */
        }
        .login-form input[type="text"],
        .login-form input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #A4C8E1; /* Sidebar hover color as border */
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 1rem;
            color: #212529; /* Sidebar text color */
            background-color: #fff;
        }
        .login-form input[type="text"]:focus,
        .login-form input[type="password"]:focus {
            border-color: #0d6efd; /* Primary color from your theme */
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
            outline: none;
        }
        .login-form .login-button {
            width: 100%;
            padding: 0.85rem 1.5rem;
            background-color: #0d6efd; /* Primary color */
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .login-form .login-button:hover {
            background-color: #0b5ed7; /* Darker primary */
        }
        .login-error {
            color: #dc3545; /* Danger color */
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            text-align: center;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>WMS Login</h1>
        <?php if (!empty($login_error)): ?>
            <p class="login-error"><?php echo htmlspecialchars($login_error); ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST" class="login-form">
            <div class="form-group">
                <label for="identifier">Username or Email:</label>
                <input type="text" id="identifier" name="identifier" required>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="login-button">Login</button>
        </form>
    </div>
</body>
</html>