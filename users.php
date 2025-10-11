<?php
// File: users.php - User Management Interface for Admin
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bootstrap and configuration
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Session and authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

// Database connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    die("Database connection factory not configured correctly.");
}
$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Include User model
require_once BASE_PATH . '/models/User.php';
$usersModel = new Users($db);

// Handle CRUD operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';
            $status = isset($_POST['status']) ? 1 : 0;
            
            if (empty($username) || empty($email) || empty($password)) {
                $message = 'Toate câmpurile sunt obligatorii pentru crearea unui utilizator nou.';
                $messageType = 'error';
            } else {
                $userData = [
                    'username' => $username,
                    'email' => $email,
                    'password' => $password,
                    'role' => $role,
                    'status' => $status
                ];
                
                $userId = $usersModel->createUser($userData);
                if ($userId) {
                    $message = 'Utilizatorul a fost creat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la crearea utilizatorului. Verificați dacă username-ul sau email-ul nu există deja.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update':
            $userId = intval($_POST['user_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';
            $status = isset($_POST['status']) ? 1 : 0;
            
            if ($userId <= 0 || empty($username) || empty($email)) {
                $message = 'Date invalide pentru actualizarea utilizatorului.';
                $messageType = 'error';
            } else {
                $userData = [
                    'username' => $username,
                    'email' => $email,
                    'role' => $role,
                    'status' => $status
                ];
                
                // Only update password if provided
                if (!empty($password)) {
                    $userData['password'] = $password;
                }
                
                $success = $usersModel->updateUser($userId, $userData);
                if ($success) {
                    $message = 'Utilizatorul a fost actualizat cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la actualizarea utilizatorului.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete':
            $userId = intval($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                $message = 'ID utilizator invalid.';
                $messageType = 'error';
            } elseif ($userId == $_SESSION['user_id']) {
                $message = 'Nu vă puteți șterge propriul cont.';
                $messageType = 'error';
            } else {
                $success = $usersModel->deleteUser($userId);
                if ($success) {
                    $message = 'Utilizatorul a fost șters cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea utilizatorului.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get all users for display
$allUsers = $usersModel->getAllUsers();

// Available role options and labels used across the page
$availableRoles = [
    'admin' => 'Administrator',
    'manager' => 'Manager',
    'user' => 'Utilizator',
    'warehouse' => 'Depozit',
    'warehouse_worker' => 'Lucrător Depozit'
];

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Utilizatori - WMS</title>
</head>
<body>
    <div class="app">
        <?php require_once __DIR__ . '/includes/navbar.php'; ?>
        
        <div class="main-content">
            <div class="page-container">
                <!-- Page Header -->
                <header class="page-header">
                    <div class="page-header-content">
                        <h1 class="page-title">
                            <span class="material-symbols-outlined">group</span>
                            Gestionare Utilizatori
                        </h1>
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">person_add</span>
                            Adaugă Utilizator
                        </button>
                    </div>
                </header>
                
                <!-- Alert Messages -->
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>" role="alert">
                        <span class="material-symbols-outlined">
                            <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                        </span>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Users Table Card -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Lista Utilizatori</h3>
                        <div class="card-actions">
                            <input type="search" class="form-control" placeholder="Caută utilizatori..." id="search-users">
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($allUsers)): ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nume Utilizator</th>
                                            <th>Email</th>
                                            <th>Rol</th>
                                            <th>Status</th>
                                            <th>Acțiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allUsers as $user): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($user['id']) ?></td>
                                                <td><?= htmlspecialchars($user['username']) ?></td>
                                                <td><?= htmlspecialchars($user['email']) ?></td>
                                                <td>
                                                    <?php
                                                        $roleKey = $user['role'] ?? '';
                                                        $roleLabel = $availableRoles[$roleKey] ?? ucfirst($roleKey);
                                                        $roleBadgeClass = $roleKey === 'admin' ? 'primary' : 'secondary';
                                                    ?>
                                                    <span class="badge badge-<?= $roleBadgeClass ?>">
                                                        <?= htmlspecialchars($roleLabel) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?= $user['status'] == 1 ? 'success' : 'danger' ?>">
                                                        <span class="material-symbols-outlined">
                                                            <?= $user['status'] == 1 ? 'check_circle' : 'cancel' ?>
                                                        </span>
                                                        <?= $user['status'] == 1 ? 'Activ' : 'Inactiv' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-secondary" 
                                                                onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)"
                                                                title="Editează utilizatorul">
                                                            <span class="material-symbols-outlined">edit</span>
                                                        </button>
                                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                            <button class="btn btn-sm btn-danger" 
                                                                    onclick="confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')"
                                                                    title="Șterge utilizatorul">
                                                                <span class="material-symbols-outlined">delete</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">group_off</span>
                                <h3>Nu există utilizatori</h3>
                                <p>Încă nu există utilizatori în sistem. Adaugă primul utilizator folosind butonul de mai sus.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create User Modal -->
    <div class="modal" id="createUserModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Adaugă Utilizator Nou</h3>
                    <button class="modal-close" onclick="closeCreateModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="form-group">
                            <label for="create-username" class="form-label">Nume Utilizator *</label>
                            <input type="text" id="create-username" name="username" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="create-email" class="form-label">Email *</label>
                            <input type="email" id="create-email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="create-password" class="form-label">Parolă *</label>
                            <input type="password" id="create-password" name="password" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="create-role" class="form-label">Rol</label>
                            <select id="create-role" name="role" class="form-control">
                                <?php foreach ($availableRoles as $roleValue => $roleName): ?>
                                    <option value="<?= htmlspecialchars($roleValue) ?>"><?= htmlspecialchars($roleName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" id="create-status" name="status" class="form-check-input" checked>
                            <label for="create-status" class="form-check-label">Cont activ</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Creează Utilizator</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Editează Utilizator</h3>
                    <button class="modal-close" onclick="closeEditModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" id="edit-user-id" name="user_id">
                        
                        <div class="form-group">
                            <label for="edit-username" class="form-label">Nume Utilizator *</label>
                            <input type="text" id="edit-username" name="username" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-email" class="form-label">Email *</label>
                            <input type="email" id="edit-email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-password" class="form-label">Parolă Nouă (lasă gol pentru a păstra actuala)</label>
                            <input type="password" id="edit-password" name="password" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit-role" class="form-label">Rol</label>
                            <select id="edit-role" name="role" class="form-control">
                                <?php foreach ($availableRoles as $roleValue => $roleName): ?>
                                    <option value="<?= htmlspecialchars($roleValue) ?>"><?= htmlspecialchars($roleName) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" id="edit-status" name="status" class="form-check-input">
                            <label for="edit-status" class="form-check-label">Cont activ</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează Utilizator</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteUserModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Confirmă Ștergerea</h3>
                    <button class="modal-close" onclick="closeDeleteModal()">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Ești sigur că vrei să ștergi utilizatorul <strong id="delete-username"></strong>?</p>
                    <p class="text-danger">Această acțiune nu poate fi anulată.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" id="delete-user-id" name="user_id">
                        <button type="submit" class="btn btn-danger">Șterge Utilizator</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>