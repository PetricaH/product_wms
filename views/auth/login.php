<?php
// file: views/auth/login.php

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require  BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: ' . getNavUrl('index.php'));
        exit;
    } elseif ($_SESSION['role'] === 'user') {
        header('Location: ' . getNavUrl('mobile_picker.html'));
        exit;
    }
}

// get database connection
if (!isset($config['connection_factory']) || !is_callable($conifg['connection_factory'])) {
    die("database connection factory is not configured correctly in config.php");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

require_once BASE_PATH . '/models/Users.php';
$usersModel = new Users($db);

$login_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $login_error = 'Please enter both username/email and password.';
    } else {
        $user = $usersModel->findByUsernameOrEmail($identifier);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // regenerate ses id for security
            session_regenerate_id(true);

            if ($user['role'] === 'admin') {
                header('Location: ' . getNavUrl('index.php'));
                exit;
            } elseif ($user['role'] === 'user') {
                header('Location: ' . getNavUrl('mobile_picker.html'));
                exit;
            } else {
                $login_error = 'Login successful, but role is undefined. Contact administrator.';
            }
        } else {
            $login_error = 'Invalid username/email or password.';
        }
    }
}