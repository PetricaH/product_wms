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

// Define current page for footer
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Utilizatori - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <main class="main-content">
        <div class="users-container">
            <div class="page-header">
                <h1 class="page-title">Gestionare Utilizatori</h1>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <span class="material-symbols-outlined">person_add</span>
                    Adaugă Utilizator
                </button>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($allUsers)): ?>
                <table class="users-table">
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
                                    <span class="role-badge role-<?= $user['role'] ?>">
                                        <?= $user['role'] === 'admin' ? 'Administrator' : 'Utilizator' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $user['status'] == 1 ? 'active' : 'inactive' ?>">
                                        <span class="material-symbols-outlined" style="font-size: 1rem;">
                                            <?= $user['status'] == 1 ? 'check_circle' : 'cancel' ?>
                                        </span>
                                        <?= $user['status'] == 1 ? 'Activ' : 'Inactiv' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button class="btn btn-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)">
                                            <span class="material-symbols-outlined">edit</span>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger" onclick="confirmDelete(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                <span class="material-symbols-outlined">delete</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">group</span>
                    <h3>Nu există utilizatori înregistrați</h3>
                    <p>Adăugați primul utilizator folosind butonul de mai sus.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Create/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Adaugă Utilizator</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="userForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="user_id" id="userId" value="">
                
                <div class="form-group">
                    <label for="username" class="form-label">Nume Utilizator</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Parolă</label>
                    <input type="password" class="form-control" id="password" name="password">
                    <small id="passwordHelp" style="color: #6c757d; font-size: 0.8rem; display: none;">
                        Lăsați gol pentru a păstra parola existentă
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="role" class="form-label">Rol</label>
                    <select class="form-control" id="role" name="role" required>
                        <option value="user">Utilizator</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="status" name="status" checked>
                        <label for="status" class="form-label">Cont activ</label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                    <button type="submit" class="btn btn-success" id="submitBtn">Salvează</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Ștergere</h2>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <p>Sunteți sigur că doriți să ștergeți utilizatorul <strong id="deleteUsername"></strong>?</p>
            <p style="color: #dc3545; font-weight: 500;">Această acțiune nu poate fi anulată.</p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" id="deleteUserId" value="">
                    <button type="submit" class="btn btn-danger">Șterge</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Adaugă Utilizator';
            document.getElementById('formAction').value = 'create';
            document.getElementById('userId').value = '';
            document.getElementById('username').value = '';
            document.getElementById('email').value = '';
            document.getElementById('password').value = '';
            document.getElementById('password').required = true;
            document.getElementById('role').value = 'user';
            document.getElementById('status').checked = true;
            document.getElementById('submitBtn').textContent = 'Adaugă';
            document.getElementById('passwordHelp').style.display = 'none';
            document.getElementById('userModal').style.display = 'block';
        }

        function openEditModal(user) {
            document.getElementById('modalTitle').textContent = 'Editează Utilizator';
            document.getElementById('formAction').value = 'update';
            document.getElementById('userId').value = user.id;
            document.getElementById('username').value = user.username;
            document.getElementById('email').value = user.email;
            document.getElementById('password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('role').value = user.role;
            document.getElementById('status').checked = user.status == 1;
            document.getElementById('submitBtn').textContent = 'Actualizează';
            document.getElementById('passwordHelp').style.display = 'block';
            document.getElementById('userModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        function confirmDelete(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const userModal = document.getElementById('userModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === userModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });
    </script>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>