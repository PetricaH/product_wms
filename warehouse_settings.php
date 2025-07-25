<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();
require_once BASE_PATH . '/models/Setting.php';

// Include enhanced models if they exist
if (file_exists(BASE_PATH . '/models/LocationLevelSettings.php')) {
    require_once BASE_PATH . '/models/LocationLevelSettings.php';
    $levelSettingsModel = new LocationLevelSettings($db);
}

if (file_exists(BASE_PATH . '/models/AutoRepartitionService.php')) {
    require_once BASE_PATH . '/models/AutoRepartitionService.php';
}

$settingModel = new Setting($db);
$message = '';
$messageType = 'info';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_basic_settings':
            // Existing functionality - preserve as-is
            $keys = ['pallets_per_level','barrels_per_pallet_5l','barrels_per_pallet_10l','barrels_per_pallet_25l'];
            $data = [];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $data[$k] = intval($_POST[$k]);
                }
            }
            if ($settingModel->setMultiple($data)) {
                $message = 'Setările de bază au fost salvate cu succes.';
                $messageType = 'success';
            } else {
                $message = 'Eroare la salvarea setărilor de bază.';
                $messageType = 'error';
            }
            break;
            
        case 'save_shelf_dimensions':
            $defaultSettings = [
                'default_shelf_length' => intval($_POST['default_shelf_length'] ?? 1000),
                'default_shelf_depth' => intval($_POST['default_shelf_depth'] ?? 400),
                'default_level_height' => intval($_POST['default_level_height'] ?? 300),
                'default_level_weight_capacity' => floatval($_POST['default_level_weight_capacity'] ?? 50.0),
                'auto_repartition_enabled' => isset($_POST['auto_repartition_enabled']) ? 1 : 0,
                'repartition_check_interval' => intval($_POST['repartition_check_interval'] ?? 24),
                'repartition_trigger_threshold' => intval($_POST['repartition_trigger_threshold'] ?? 80)
            ];
            
            if ($settingModel->setMultiple($defaultSettings)) {
                $message = 'Setările pentru dimensiuni rafturi au fost salvate cu succes.';
                $messageType = 'success';
            } else {
                $message = 'Eroare la salvarea setărilor pentru dimensiuni.';
                $messageType = 'error';
            }
            break;

        case 'add_barrel_dimension':
            $label  = trim($_POST['barrel_label'] ?? '');
            $len    = floatval($_POST['barrel_length'] ?? 0);
            $wid    = floatval($_POST['barrel_width'] ?? 0);
            $hei    = floatval($_POST['barrel_height'] ?? 0);

            if ($label && $len > 0 && $wid > 0 && $hei > 0) {
                $stmt = $db->prepare('INSERT INTO barrel_dimensions (label, length_cm, width_cm, height_cm) VALUES (?, ?, ?, ?)');
                if ($stmt->execute([$label, $len, $wid, $hei])) {
                    $message = 'Dimensiunea bidonului a fost adăugată.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la adăugarea dimensiunii.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Completați toate câmpurile pentru dimensiunea bidonului.';
                $messageType = 'error';
            }
            break;

        case 'delete_barrel_dimension':
            $id = intval($_POST['dimension_id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare('DELETE FROM barrel_dimensions WHERE id = ?');
                if ($stmt->execute([$id])) {
                    $message = 'Dimensiunea a fost ștearsă.';
                    $messageType = 'success';
                } else {
                    $message = 'Eroare la ștergerea dimensiunii.';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'run_repartition_analysis':
            if (isset($levelSettingsModel) && class_exists('AutoRepartitionService')) {
                $repartitionService = new AutoRepartitionService($db, $levelSettingsModel);
                $repartitionService->setDryRun(true); // Analysis only
                
                $results = $repartitionService->processAllLocations();
                
                if ($results['total_moves'] > 0) {
                    $message = "Analiza completă: {$results['total_moves']} mișcări recomandate pentru {$results['processed_locations']} locații.";
                    $messageType = 'info';
                } else {
                    $message = "Analiza completă: Nu sunt necesare repartizări pentru {$results['processed_locations']} locații verificate.";
                    $messageType = 'success';
                }
                
                if (!empty($results['errors'])) {
                    $message .= " Erori: " . implode(', ', array_slice($results['errors'], 0, 3));
                    $messageType = 'warning';
                }
            } else {
                $message = 'Serviciul de repartizare automată nu este disponibil.';
                $messageType = 'warning';
            }
            break;
            
        case 'execute_repartition':
            if (isset($levelSettingsModel) && class_exists('AutoRepartitionService')) {
                $repartitionService = new AutoRepartitionService($db, $levelSettingsModel);
                $repartitionService->setDryRun(false); // Execute moves
                
                $results = $repartitionService->processAllLocations();
                
                if ($results['total_moves'] > 0) {
                    $message = "Repartizare executată cu succes: {$results['total_moves']} mișcări efectuate pentru {$results['processed_locations']} locații.";
                    $messageType = 'success';
                } else {
                    $message = "Nu au fost necesare repartizări pentru {$results['processed_locations']} locații verificate.";
                    $messageType = 'info';
                }
                
                if (!empty($results['errors'])) {
                    $message .= " Cu erori: " . implode(', ', array_slice($results['errors'], 0, 3));
                    $messageType = 'warning';
                }
            } else {
                $message = 'Serviciul de repartizare automată nu este disponibil.';
                $messageType = 'warning';
            }
            break;
            
        default:
            // Fallback to original functionality for backwards compatibility
            $keys = ['pallets_per_level','barrels_per_pallet_5l','barrels_per_pallet_10l','barrels_per_pallet_25l'];
            $data = [];
            foreach ($keys as $k) {
                if (isset($_POST[$k])) {
                    $data[$k] = intval($_POST[$k]);
                }
            }
            if ($settingModel->setMultiple($data)) {
                $message = 'Setările au fost salvate';
                $messageType = 'success';
            } else {
                $message = 'Eroare la salvare';
                $messageType = 'error';
            }
            break;
    }
}

// Get current settings
$basicKeys = ['pallets_per_level','barrels_per_pallet_5l','barrels_per_pallet_10l','barrels_per_pallet_25l'];
$dimensionKeys = ['default_shelf_length','default_shelf_depth','default_level_height','default_level_weight_capacity',
                 'auto_repartition_enabled','repartition_check_interval','repartition_trigger_threshold'];

$basicSettings = $settingModel->getMultiple($basicKeys);
$dimensionSettings = $settingModel->getMultiple($dimensionKeys);

// Fetch barrel dimensions
$barrelStmt = $db->prepare('SELECT id, label, length_cm, width_cm, height_cm FROM barrel_dimensions ORDER BY id ASC');
$barrelStmt->execute();
$barrelDimensions = $barrelStmt->fetchAll(PDO::FETCH_ASSOC);

// Get repartition statistics
$repartitionStats = [];
try {
    if (isset($levelSettingsModel)) {
        $statsQuery = "SELECT 
                        COUNT(DISTINCT l.id) as total_locations,
                        COUNT(DISTINCT CASE WHEN lls.enable_auto_repartition = 1 THEN l.id END) as auto_repartition_enabled,
                        AVG(lls.repartition_trigger_threshold) as avg_threshold
                       FROM locations l
                       LEFT JOIN location_level_settings lls ON l.id = lls.location_id
                       WHERE l.type = 'shelf' AND l.status = 'active'";
        
        $stmt = $db->prepare($statsQuery);
        $stmt->execute();
        $repartitionStats = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching repartition stats: " . $e->getMessage());
}

// Set defaults if no stats available
if (empty($repartitionStats)) {
    $repartitionStats = ['total_locations' => 0, 'auto_repartition_enabled' => 0, 'avg_threshold' => 80];
}

$pageTitle = 'Setări Depozit';
$currentPage = 'warehouse_settings.php';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/header.php'; ?>
</head>
<body>
<?php require_once BASE_PATH . '/includes/navbar.php'; ?>
<div class="main-content">
    <div class="page-container">
        <header class="page-header">
            <h1 class="page-title">
                <span class="material-symbols-outlined">settings</span> 
                Configurare Depozit
            </h1>
        </header>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span class="material-symbols-outlined">
                    <?= $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info') ?>
                </span>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <!-- Tabs Navigation -->
        <div class="settings-tabs">
            <button type="button" class="tab-button active" onclick="switchTab('basic')">
                <span class="material-symbols-outlined">inventory_2</span>
                Setări de Bază
            </button>
            <?php if (isset($levelSettingsModel)): ?>
            <button type="button" class="tab-button" onclick="switchTab('dimensions')">
                <span class="material-symbols-outlined">straighten</span>
                Dimensiuni
            </button>
            <button type="button" class="tab-button" onclick="switchTab('repartition')">
                <span class="material-symbols-outlined">auto_fix_high</span>
                Repartizare Automată
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Basic Settings Tab -->
        <div id="basic-tab" class="tab-content active">
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">inventory_2</span>
                    Setări Capacitate Depozit
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_basic_settings">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="pallets_per_level" class="form-label">Paleți pe nivel</label>
                            <input type="number" id="pallets_per_level" name="pallets_per_level" 
                                   class="form-control" value="<?= htmlspecialchars($basicSettings['pallets_per_level'] ?? 0) ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label for="barrels_per_pallet_5l" class="form-label">Butoaie 5L / palet</label>
                            <input type="number" id="barrels_per_pallet_5l" name="barrels_per_pallet_5l" 
                                   class="form-control" value="<?= htmlspecialchars($basicSettings['barrels_per_pallet_5l'] ?? 0) ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label for="barrels_per_pallet_10l" class="form-label">Butoaie 10L / palet</label>
                            <input type="number" id="barrels_per_pallet_10l" name="barrels_per_pallet_10l" 
                                   class="form-control" value="<?= htmlspecialchars($basicSettings['barrels_per_pallet_10l'] ?? 0) ?>" min="0">
                        </div>
                        <div class="form-group">
                            <label for="barrels_per_pallet_25l" class="form-label">Butoaie 25L / palet</label>
                            <input type="number" id="barrels_per_pallet_25l" name="barrels_per_pallet_25l" 
                                   class="form-control" value="<?= htmlspecialchars($basicSettings['barrels_per_pallet_25l'] ?? 0) ?>" min="0">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">save</span>
                        Salvează
                    </button>
                </form>
            </div>
        </div>
        
        <?php if (isset($levelSettingsModel)): ?>
        <!-- Dimensions Tab -->
        <div id="dimensions-tab" class="tab-content">
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">straighten</span>
                    Dimensiuni Standard Rafturi
                </h3>
                <p class="settings-description">
                    Aceste valori vor fi folosite ca valori implicite pentru rafturile nou create. 
                    Pot fi personalizate individual pentru fiecare raft.
                </p>
                <form method="POST">
                    <input type="hidden" name="action" value="save_shelf_dimensions">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="default_shelf_length" class="form-label">Lungime standard raft (mm)</label>
                            <input type="number" id="default_shelf_length" name="default_shelf_length" 
                                   class="form-control" value="<?= htmlspecialchars($dimensionSettings['default_shelf_length'] ?? 1000) ?>" min="100" max="5000">
                            <small class="help-text">Lungimea totală a raftului în milimetri</small>
                        </div>
                        <div class="form-group">
                            <label for="default_shelf_depth" class="form-label">Adâncime standard raft (mm)</label>
                            <input type="number" id="default_shelf_depth" name="default_shelf_depth" 
                                   class="form-control" value="<?= htmlspecialchars($dimensionSettings['default_shelf_depth'] ?? 400) ?>" min="100" max="1000">
                            <small class="help-text">Adâncimea raftului în milimetri</small>
                        </div>
                        <div class="form-group">
                            <label for="default_level_height" class="form-label">Înălțime standard nivel (mm)</label>
                            <input type="number" id="default_level_height" name="default_level_height" 
                                   class="form-control" value="<?= htmlspecialchars($dimensionSettings['default_level_height'] ?? 300) ?>" min="100" max="1000">
                            <small class="help-text">Înălțimea fiecărui nivel individual</small>
                        </div>
                        <div class="form-group">
                            <label for="default_level_weight_capacity" class="form-label">Capacitate greutate nivel (kg)</label>
                            <input type="number" id="default_level_weight_capacity" name="default_level_weight_capacity" 
                                   class="form-control" step="0.1" value="<?= htmlspecialchars($dimensionSettings['default_level_weight_capacity'] ?? 50.0) ?>" min="1" max="500">
                            <small class="help-text">Greutatea maximă suportată de un nivel</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">save</span>
                        Salvează Dimensiunile Standard
                    </button>
                </form>
            </div>
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">straighten</span>
                    Dimensiuni Standard Bidoane
                </h3>
                <form method="POST" style="margin-bottom:1rem;">
                    <input type="hidden" name="action" value="add_barrel_dimension">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="barrel_label" class="form-label">Tip Bidon</label>
                            <input type="text" id="barrel_label" name="barrel_label" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="barrel_length" class="form-label">Lungime (cm)</label>
                            <input type="number" id="barrel_length" name="barrel_length" step="0.1" min="0" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="barrel_width" class="form-label">Lățime (cm)</label>
                            <input type="number" id="barrel_width" name="barrel_width" step="0.1" min="0" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="barrel_height" class="form-label">Înălțime (cm)</label>
                            <input type="number" id="barrel_height" name="barrel_height" step="0.1" min="0" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">add</span>
                        Adaugă Dimensiune
                    </button>
                </form>
                <?php if (!empty($barrelDimensions)): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tip</th>
                            <th>Lungime</th>
                            <th>Lățime</th>
                            <th>Înălțime</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($barrelDimensions as $bd): ?>
                        <tr>
                            <td><?= htmlspecialchars($bd['label']) ?></td>
                            <td><?= $bd['length_cm'] ?></td>
                            <td><?= $bd['width_cm'] ?></td>
                            <td><?= $bd['height_cm'] ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_barrel_dimension">
                                    <input type="hidden" name="dimension_id" value="<?= $bd['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Ștergeți această dimensiune?')">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Auto Repartition Tab -->
        <div id="repartition-tab" class="tab-content">
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">analytics</span>
                    Statistici Repartizare
                </h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= $repartitionStats['total_locations'] ?? 0 ?></div>
                        <div class="stat-label">Total Locații Rafturi</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $repartitionStats['auto_repartition_enabled'] ?? 0 ?></div>
                        <div class="stat-label">Cu Repartizare Automată</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= round($repartitionStats['avg_threshold'] ?? 80) ?>%</div>
                        <div class="stat-label">Prag Mediu Activare</div>
                    </div>
                </div>
            </div>
            
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">auto_fix_high</span>
                    Configurare Repartizare Automată
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="save_shelf_dimensions">
                    <div class="form-grid">
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" id="auto_repartition_enabled" name="auto_repartition_enabled" 
                                       <?= !empty($dimensionSettings['auto_repartition_enabled']) ? 'checked' : '' ?>>
                                <label for="auto_repartition_enabled" class="form-label">Activează repartizarea automată globală</label>
                            </div>
                            <small class="help-text">Permite sistemului să redistribuie automat produsele</small>
                        </div>
                        <div class="form-group">
                            <label for="repartition_check_interval" class="form-label">Interval verificare (ore)</label>
                            <input type="number" id="repartition_check_interval" name="repartition_check_interval" 
                                   class="form-control" value="<?= htmlspecialchars($dimensionSettings['repartition_check_interval'] ?? 24) ?>" min="1" max="168">
                            <small class="help-text">Cât de des să verifice sistemul pentru repartizări</small>
                        </div>
                        <div class="form-group">
                            <label for="repartition_trigger_threshold" class="form-label">Prag activare standard (%)</label>
                            <input type="number" id="repartition_trigger_threshold" name="repartition_trigger_threshold" 
                                   class="form-control" value="<?= htmlspecialchars($dimensionSettings['repartition_trigger_threshold'] ?? 80) ?>" min="50" max="95">
                            <small class="help-text">Procentul de ocupare care declanșează repartizarea</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">save</span>
                        Salvează Configurarea
                    </button>
                </form>
            </div>
            
            <div class="settings-section">
                <h3>
                    <span class="material-symbols-outlined">play_arrow</span>
                    Acțiuni Repartizare
                </h3>
                <p class="settings-description">
                    Executați manual operațiuni de repartizare pentru toate locațiile cu repartizare automată activată.
                </p>
                <div class="action-buttons">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="run_repartition_analysis">
                        <button type="submit" class="btn btn-secondary">
                            <span class="material-symbols-outlined">analytics</span>
                            Analizează Necesitățile
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline;" 
                          onsubmit="return confirm('Sigur doriți să executați repartizarea automată? Această acțiune va muta produsele între nivelurile rafturilor.')">
                        <input type="hidden" name="action" value="execute_repartition">
                        <button type="submit" class="btn btn-warning">
                            <span class="material-symbols-outlined">auto_fix_high</span>
                            Execută Repartizarea
                        </button>
                    </form>
                    
                    <a href="locations.php" class="btn btn-primary">
                        <span class="material-symbols-outlined">tune</span>
                        Configurează Locații Individual
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
</body>
</html>