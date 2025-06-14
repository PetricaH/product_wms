<?php
// File: locations.php - Locations Management Interface
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

// Include Location model
require_once BASE_PATH . '/models/Location.php';
$locationModel = new Location($db);

// Handle CRUD operations
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $locationData = [
                'location_code' => trim($_POST['location_code'] ?? ''),
                'zone' => trim($_POST['zone'] ?? ''),
                'type' => trim($_POST['type'] ?? '')
            ];
            
            if (empty($locationData['location_code']) || empty($locationData['zone']) || empty($locationData['type'])) {
                $message = 'Toate câmpurile sunt obligatorii.';
                $messageType = 'error';
            } else {
                $locationId = $locationModel->create($locationData);
                if ($locationId) {
                    $message = 'Locația a fost creată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la crearea locației. Verificați dacă codul locației nu există deja.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update':
            $locationId = intval($_POST['location_id'] ?? 0);
            $locationData = [
                'location_code' => trim($_POST['location_code'] ?? ''),
                'zone' => trim($_POST['zone'] ?? ''),
                'type' => trim($_POST['type'] ?? '')
            ];
            
            if ($locationId <= 0 || empty($locationData['location_code']) || empty($locationData['zone']) || empty($locationData['type'])) {
                $message = 'Date invalide pentru actualizarea locației.';
                $messageType = 'error';
            } else {
                if ($locationModel->update($locationId, $locationData)) {
                    $message = 'Locația a fost actualizată cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la actualizarea locației.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete':
            $locationId = intval($_POST['location_id'] ?? 0);
            
            if ($locationId <= 0) {
                $message = 'ID locație invalid.';
                $messageType = 'error';
            } else {
                if ($locationModel->delete($locationId)) {
                    $message = 'Locația a fost ștearsă cu succes.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea locației. Locația poate conține inventar.';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Get all locations for display
$locations = $locationModel->getAllLocations();
$zones = $locationModel->getZones();
$types = $locationModel->getTypes();


$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require_once __DIR__ . '/includes/header.php'; ?>
    <title>Gestionare Locații - WMS</title>
</head>
<body class="app">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="main-content">
        <div class="locations-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Gestionare Locații</h1>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <span class="material-symbols-outlined">add_location</span>
                    Adaugă Locație
                </button>
            </div>

            <!-- Display Messages -->
            <?php if (!empty($message)): ?>
                <div class="message <?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-controls">
                    <select id="zoneFilter" onchange="filterLocations()">
                        <option value="">Toate zonele</option>
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?= htmlspecialchars($zone) ?>"><?= htmlspecialchars($zone) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select id="typeFilter" onchange="filterLocations()">
                        <option value="">Toate tipurile</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="text" id="searchInput" placeholder="Caută după cod locație..." onkeyup="filterLocations()">
                </div>
            </div>

            <!-- Locations Grid -->
            <?php if (!empty($locations)): ?>
                <div class="locations-grid" id="locationsGrid">
                    <?php foreach ($locations as $location): ?>
                        <div class="location-card" 
                             data-zone="<?= htmlspecialchars($location['zone']) ?>" 
                             data-type="<?= htmlspecialchars($location['type']) ?>" 
                             data-code="<?= htmlspecialchars($location['location_code']) ?>">
                            <div class="location-header">
                                <h3 class="location-code"><?= htmlspecialchars($location['location_code']) ?></h3>
                                <div class="location-status">
                                    <?php if ($location['total_items'] > 0): ?>
                                        <span class="status-badge status-occupied">
                                            <span class="material-symbols-outlined">inventory</span>
                                            Ocupat
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-empty">
                                            <span class="material-symbols-outlined">inventory_2_off</span>
                                            Gol
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="location-details">
                                <div class="detail-item">
                                    <span class="material-symbols-outlined">domain</span>
                                    <div class="detail-content">
                                        <span class="detail-label">Zonă:</span>
                                        <span class="detail-value"><?= htmlspecialchars($location['zone']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="material-symbols-outlined">category</span>
                                    <div class="detail-content">
                                        <span class="detail-label">Tip:</span>
                                        <span class="detail-value"><?= htmlspecialchars($location['type']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="material-symbols-outlined">inventory</span>
                                    <div class="detail-content">
                                        <span class="detail-label">Produse:</span>
                                        <span class="detail-value"><?= number_format($location['product_count']) ?></span>
                                    </div>
                                </div>
                                
                                <div class="detail-item">
                                    <span class="material-symbols-outlined">package_2</span>
                                    <div class="detail-content">
                                        <span class="detail-label">Total articole:</span>
                                        <span class="detail-value"><?= number_format($location['total_items']) ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="location-actions">
                                <button class="btn btn-secondary" onclick="openEditModal(<?= htmlspecialchars(json_encode($location)) ?>)">
                                    <span class="material-symbols-outlined">edit</span>
                                    Editează
                                </button>
                                <?php if ($location['total_items'] == 0): ?>
                                    <button class="btn btn-danger" onclick="confirmDelete(<?= $location['id'] ?>, '<?= htmlspecialchars($location['location_code']) ?>')">
                                        <span class="material-symbols-outlined">delete</span>
                                        Șterge
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">pin_drop</span>
                    <h3>Nu există locații</h3>
                    <p>Adăugați prima locație folosind butonul de mai sus.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Create/Edit Location Modal -->
    <div id="locationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Adaugă Locație</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="locationForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="location_id" id="locationId" value="">
                
                <div class="form-group">
                    <label for="location_code" class="form-label">
                        <span class="material-symbols-outlined">qr_code</span>
                        Cod Locație *
                    </label>
                    <input type="text" 
                           name="location_code" 
                           id="location_code" 
                           class="form-input" 
                           placeholder="ex: A-01-B-03"
                           required>
                    <small class="form-help">Format sugerat: [Zonă]-[Culoar]-[Raft]-[Poziție]</small>
                </div>
                
                <div class="form-group">
                    <label for="zone" class="form-label">
                        <span class="material-symbols-outlined">domain</span>
                        Zonă *
                    </label>
                    <input type="text" 
                           name="zone" 
                           id="zone" 
                           class="form-input" 
                           placeholder="ex: Bulk, Picking, Receiving, QC"
                           list="zoneList"
                           required>
                    <datalist id="zoneList">
                        <option value="Bulk">
                        <option value="Picking">
                        <option value="Receiving">
                        <option value="QC">
                        <option value="Shipping">
                        <option value="Returns">
                    </datalist>
                </div>
                
                <div class="form-group">
                    <label for="type" class="form-label">
                        <span class="material-symbols-outlined">category</span>
                        Tip *
                    </label>
                    <input type="text" 
                           name="type" 
                           id="type" 
                           class="form-input" 
                           placeholder="ex: Pallet Rack, Shelf, Bin, Floor"
                           list="typeList"
                           required>
                    <datalist id="typeList">
                        <option value="Pallet Rack">
                        <option value="Shelf">
                        <option value="Bin">
                        <option value="Floor">
                        <option value="Freezer">
                        <option value="Cooler">
                    </datalist>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Adaugă</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content modal-small">
            <div class="modal-header">
                <h2 class="modal-title">Confirmare Ștergere</h2>
                <button class="close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sunteți sigur că doriți să ștergeți locația <strong id="deleteLocationCode"></strong>?</p>
                <p><small>Această acțiune nu poate fi anulată.</small></p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="location_id" id="deleteLocationId" value="">
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <button type="submit" class="btn btn-danger">Șterge</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal functions
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Adaugă Locație';
            document.getElementById('formAction').value = 'create';
            document.getElementById('locationId').value = '';
            document.getElementById('locationForm').reset();
            document.getElementById('submitBtn').textContent = 'Adaugă';
            document.getElementById('locationModal').style.display = 'block';
        }

        function openEditModal(location) {
            document.getElementById('modalTitle').textContent = 'Editează Locație';
            document.getElementById('formAction').value = 'update';
            document.getElementById('locationId').value = location.id;
            
            document.getElementById('location_code').value = location.location_code || '';
            document.getElementById('zone').value = location.zone || '';
            document.getElementById('type').value = location.type || '';
            
            document.getElementById('submitBtn').textContent = 'Actualizează';
            document.getElementById('locationModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('locationModal').style.display = 'none';
        }

        function confirmDelete(locationId, locationCode) {
            document.getElementById('deleteLocationId').value = locationId;
            document.getElementById('deleteLocationCode').textContent = locationCode;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Filter functions
        function filterLocations() {
            const zoneFilter = document.getElementById('zoneFilter').value.toLowerCase();
            const typeFilter = document.getElementById('typeFilter').value.toLowerCase();
            const searchInput = document.getElementById('searchInput').value.toLowerCase();
            const locationCards = document.querySelectorAll('.location-card');

            locationCards.forEach(card => {
                const zone = card.dataset.zone.toLowerCase();
                const type = card.dataset.type.toLowerCase();
                const code = card.dataset.code.toLowerCase();

                const zoneMatch = !zoneFilter || zone === zoneFilter;
                const typeMatch = !typeFilter || type === typeFilter;
                const searchMatch = !searchInput || code.includes(searchInput);

                if (zoneMatch && typeMatch && searchMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const locationModal = document.getElementById('locationModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target === locationModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        // Close modals with Escape key
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