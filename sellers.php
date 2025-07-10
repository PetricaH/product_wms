<?php
// File: sellers.php - Sellers Management Page
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

// Include models
require_once BASE_PATH . '/models/Seller.php';
$sellerModel = new Seller($db);

// Handle operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $sellerData = [
                    'supplier_name' => trim($_POST['supplier_name'] ?? ''),
                    'cif' => trim($_POST['cif'] ?? ''),
                    'registration_number' => trim($_POST['registration_number'] ?? ''),
                    'supplier_code' => trim($_POST['supplier_code'] ?? ''),
                    'address' => trim($_POST['address'] ?? ''),
                    'city' => trim($_POST['city'] ?? ''),
                    'county' => trim($_POST['county'] ?? ''),
                    'bank_name' => trim($_POST['bank_name'] ?? ''),
                    'iban' => trim($_POST['iban'] ?? ''),
                    'country' => trim($_POST['country'] ?? 'Romania'),
                    'email' => trim($_POST['email'] ?? ''),
                    'contact_person' => trim($_POST['contact_person'] ?? ''),
                    'phone' => trim($_POST['phone'] ?? ''),
                    'notes' => trim($_POST['notes'] ?? ''),
                    'status' => $_POST['status'] ?? 'active'
                ];
                
                if (empty($sellerData['supplier_name'])) {
                    throw new Exception('Numele furnizorului este obligatoriu.');
                }
                
                if (!empty($sellerData['email']) && !filter_var($sellerData['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Adresa de email nu este validă.');
                }
                
                $sellerId = $sellerModel->createSeller($sellerData);
                if ($sellerId) {
                    $message = 'Furnizorul a fost adăugat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la adăugarea furnizorului.');
                }
                break;
                
            case 'update':
                $sellerId = intval($_POST['seller_id'] ?? 0);
                $sellerData = [
                    'supplier_name' => trim($_POST['supplier_name'] ?? ''),
                    'cif' => trim($_POST['cif'] ?? ''),
                    'registration_number' => trim($_POST['registration_number'] ?? ''),
                    'supplier_code' => trim($_POST['supplier_code'] ?? ''),
                    'address' => trim($_POST['address'] ?? ''),
                    'city' => trim($_POST['city'] ?? ''),
                    'county' => trim($_POST['county'] ?? ''),
                    'bank_name' => trim($_POST['bank_name'] ?? ''),
                    'iban' => trim($_POST['iban'] ?? ''),
                    'country' => trim($_POST['country'] ?? 'Romania'),
                    'email' => trim($_POST['email'] ?? ''),
                    'contact_person' => trim($_POST['contact_person'] ?? ''),
                    'phone' => trim($_POST['phone'] ?? ''),
                    'notes' => trim($_POST['notes'] ?? ''),
                    'status' => $_POST['status'] ?? 'active'
                ];
                
                if ($sellerId <= 0) {
                    throw new Exception('ID furnizor invalid.');
                }
                
                if (empty($sellerData['supplier_name'])) {
                    throw new Exception('Numele furnizorului este obligatoriu.');
                }
                
                if (!empty($sellerData['email']) && !filter_var($sellerData['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Adresa de email nu este validă.');
                }
                
                if ($sellerModel->updateSeller($sellerId, $sellerData)) {
                    $message = 'Informațiile furnizorului au fost actualizate cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la actualizarea furnizorului.');
                }
                break;
                
            case 'delete':
                $sellerId = intval($_POST['seller_id'] ?? 0);
                
                if ($sellerId <= 0) {
                    throw new Exception('ID furnizor invalid.');
                }
                
                if ($sellerModel->deleteSeller($sellerId)) {
                    $message = 'Furnizorul a fost șters/dezactivat cu succes.';
                    $messageType = 'success';
                } else {
                    throw new Exception('Eroare la ștergerea furnizorului.');
                }
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get search parameters
$searchQuery = trim($_GET['search'] ?? '');

// Get sellers
if (!empty($searchQuery)) {
    $sellers = $sellerModel->searchSellers($searchQuery);
} else {
    $sellers = $sellerModel->getAllSellers();
}

// Include header
$currentPage = 'sellers';
require_once __DIR__ . '/includes/header.php';
?>

<div class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">Furnizori</h1>
                    <div class="header-actions">
                        <button class="btn btn-primary" onclick="openCreateModal()">
                            <span class="material-symbols-outlined">add</span>
                            Furnizor Nou
                        </button>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>">
                    <span class="material-symbols-outlined">
                        <?= $messageType === 'success' ? 'check_circle' : 'error' ?>
                    </span>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Search and Stats -->
            <div class="stats-grid">
                <?php
                $statsQuery = "SELECT 
                    COUNT(*) as total_sellers,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_sellers,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_sellers
                    FROM sellers";
                $statsStmt = $db->prepare($statsQuery);
                $statsStmt->execute();
                $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
                ?>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">store</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['total_sellers']) ?></h3>
                        <p>Total Furnizori</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">check_circle</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['active_sellers']) ?></h3>
                        <p>Activi</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <span class="material-symbols-outlined">block</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= number_format($stats['inactive_sellers']) ?></h3>
                        <p>Inactivi</p>
                    </div>
                </div>
            </div>

            <!-- Search -->
            <div class="search-section">
                <form method="GET" class="search-form">
                    <div class="search-group">
                        <input type="text" name="search" class="form-control" 
                               value="<?= htmlspecialchars($searchQuery) ?>" 
                               placeholder="Căutare după nume, CIF, email...">
                        <button type="submit" class="btn btn-primary">
                            <span class="material-symbols-outlined">search</span>
                            Căutare
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="?" class="btn btn-secondary">
                                <span class="material-symbols-outlined">clear</span>
                                Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Sellers Table -->
            <div class="sellers-table-container">
                <?php if (!empty($sellers)): ?>
                    <table class="sellers-table">
                        <thead>
                            <tr>
                                <th>Furnizor</th>
                                <th>Contact</th>
                                <th>Informații Fiscale</th>
                                <th>Adresă</th>
                                <th>Status</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellers as $seller): ?>
                                <tr>
                                    <td>
                                        <div class="seller-info">
                                            <strong><?= htmlspecialchars($seller['supplier_name']) ?></strong>
                                            <?php if ($seller['supplier_code']): ?>
                                                <br><small class="text-muted">Cod: <?= htmlspecialchars($seller['supplier_code']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <?php if ($seller['contact_person']): ?>
                                                <div><strong><?= htmlspecialchars($seller['contact_person']) ?></strong></div>
                                            <?php endif; ?>
                                            <?php if ($seller['email']): ?>
                                                <div><a href="mailto:<?= htmlspecialchars($seller['email']) ?>"><?= htmlspecialchars($seller['email']) ?></a></div>
                                            <?php endif; ?>
                                            <?php if ($seller['phone']): ?>
                                                <div><a href="tel:<?= htmlspecialchars($seller['phone']) ?>"><?= htmlspecialchars($seller['phone']) ?></a></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fiscal-info">
                                            <?php if ($seller['cif']): ?>
                                                <div>CIF: <?= htmlspecialchars($seller['cif']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($seller['registration_number']): ?>
                                                <div>Reg Com: <?= htmlspecialchars($seller['registration_number']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="address-info">
                                            <?php if ($seller['address']): ?>
                                                <div><?= htmlspecialchars($seller['address']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($seller['city'] || $seller['county']): ?>
                                                <div>
                                                    <?= htmlspecialchars($seller['city']) ?>
                                                    <?php if ($seller['city'] && $seller['county']): ?>, <?php endif; ?>
                                                    <?= htmlspecialchars($seller['county']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($seller['country'] && $seller['country'] !== 'Romania'): ?>
                                                <div><?= htmlspecialchars($seller['country']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $seller['status'] ?>">
                                            <?= ucfirst($seller['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewSellerDetails(<?= $seller['id'] ?>)"
                                                    title="Vezi detalii">
                                                <span class="material-symbols-outlined">visibility</span>
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary" 
                                                    onclick="openEditModal(<?= $seller['id'] ?>)"
                                                    title="Editează">
                                                <span class="material-symbols-outlined">edit</span>
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="openDeleteModal(<?= $seller['id'] ?>, '<?= htmlspecialchars($seller['supplier_name']) ?>')"
                                                    title="Șterge">
                                                <span class="material-symbols-outlined">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-content">
                            <span class="material-symbols-outlined">store</span>
                            <h3>Nu există furnizori</h3>
                            <p>
                                <?php if (!empty($searchQuery)): ?>
                                    Nu s-au găsit furnizori care să corespundă criteriilor de căutare.
                                    <a href="?" class="btn btn-secondary">Șterge căutarea</a>
                                <?php else: ?>
                                    Adaugă primul furnizor pentru a putea crea comenzi de achiziție.
                                    <button class="btn btn-primary" onclick="openCreateModal()">Adaugă Furnizor</button>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Seller Modal -->
    <div class="modal" id="createSellerModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Furnizor Nou</h3>
                    <button class="modal-close" onclick="closeModal('createSellerModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h4>Informații de Bază</h4>
                            <div class="row">
                                <div class="form-group">
                                    <label for="supplier_name" class="form-label">Nume Furnizor *</label>
                                    <input type="text" name="supplier_name" id="supplier_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label for="supplier_code" class="form-label">Cod Furnizor</label>
                                    <input type="text" name="supplier_code" id="supplier_code" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="form-group">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-control">
                                        <option value="active">Activ</option>
                                        <option value="inactive">Inactiv</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Fiscal Information -->
                        <div class="form-section">
                            <h4>Informații Fiscale</h4>
                            <div class="row">
                                <div class="form-group">
                                    <label for="cif" class="form-label">CIF</label>
                                    <input type="text" name="cif" id="cif" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="registration_number" class="form-label">Număr Înregistrare</label>
                                    <input type="text" name="registration_number" id="registration_number" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="form-section">
                            <h4>Informații de Contact</h4>
                            <div class="row">
                                <div class="form-group">
                                    <label for="contact_person" class="form-label">Persoană de Contact</label>
                                    <input type="text" name="contact_person" id="contact_person" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="email" class="form-label">Email</label>
                                    <input type="email" name="email" id="email" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="form-group">
                                    <label for="phone" class="form-label">Telefon</label>
                                    <input type="tel" name="phone" id="phone" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="form-section">
                            <h4>Adresă</h4>
                            <div class="form-group">
                                <label for="address" class="form-label">Adresă</label>
                                <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                            </div>
                            <div class="row">
                                <div class="form-group">
                                    <label for="city" class="form-label">Localitate</label>
                                    <input type="text" name="city" id="city" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="county" class="form-label">Județ</label>
                                    <input type="text" name="county" id="county" class="form-control">
                                </div>
                            </div>
                            <div class="row">
                                <div class="form-group">
                                    <label for="country" class="form-label">Țară</label>
                                    <input type="text" name="country" id="country" class="form-control" value="Romania">
                                </div>
                            </div>
                        </div>

                        <!-- Banking Information -->
                        <div class="form-section">
                            <h4>Informații Bancare</h4>
                            <div class="row">
                                <div class="form-group">
                                    <label for="bank_name" class="form-label">Banca</label>
                                    <input type="text" name="bank_name" id="bank_name" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="iban" class="form-label">IBAN</label>
                                    <input type="text" name="iban" id="iban" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="form-section">
                            <h4>Observații</h4>
                            <div class="form-group">
                                <label for="notes" class="form-label">Observații</label>
                                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('createSellerModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Adaugă Furnizor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Seller Modal -->
    <div class="modal" id="editSellerModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Editează Furnizor</h3>
                    <button class="modal-close" onclick="closeModal('editSellerModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="seller_id" id="editSellerId">
                        
                        <!-- Form content will be populated by JavaScript -->
                        <div id="editSellerForm">
                            <div class="loading-message">
                                <span class="material-symbols-outlined">hourglass_empty</span>
                                Se încarcă informațiile furnizorului...
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editSellerModal')">Anulează</button>
                        <button type="submit" class="btn btn-primary">Actualizează Furnizor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteSellerModal">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Confirmare Ștergere</h3>
                    <button class="modal-close" onclick="closeModal('deleteSellerModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="seller_id" id="deleteSellerId">
                        
                        <div class="alert alert-warning">
                            <span class="material-symbols-outlined">warning</span>
                            Ești sigur că vrei să ștergi furnizorul <strong id="deleteSellerName"></strong>?
                        </div>
                        
                        <p><small class="text-muted">Dacă furnizorul are comenzi asociate, va fi dezactivat în loc să fie șters.</small></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteSellerModal')">Anulează</button>
                        <button type="submit" class="btn btn-danger">Șterge Furnizor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Seller Details Modal -->
    <div class="modal" id="sellerDetailsModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">Detalii Furnizor</h3>
                    <button class="modal-close" onclick="closeModal('sellerDetailsModal')">
                        <span class="material-symbols-outlined">close</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="sellerDetailsContent">
                        <div class="loading-message">
                            <span class="material-symbols-outlined">hourglass_empty</span>
                            Se încarcă detaliile furnizorului...
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('sellerDetailsModal')">Închide</button>
                    <button type="button" class="btn btn-primary" onclick="editCurrentSeller()">Editează</button>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</div>