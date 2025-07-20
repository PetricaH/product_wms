<?php
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();
require_once BASE_PATH . '/models/Setting.php';

$settingModel = new Setting($db);
$message = '';

$keys = ['pallets_per_level','barrels_per_pallet_5l','barrels_per_pallet_10l','barrels_per_pallet_25l'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [];
    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            $data[$k] = intval($_POST[$k]);
        }
    }
    if ($settingModel->setMultiple($data)) {
        $message = 'Setările au fost salvate';
    } else {
        $message = 'Eroare la salvare';
    }
}
$current = $settingModel->getMultiple($keys);

$pageTitle = 'Setări Depozit';
$currentPage = 'warehouse-settings.php';
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
            <h1 class="page-title"><span class="material-symbols-outlined">settings</span> Configurare Depozit</h1>
        </header>
        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST" class="form-grid">
            <div class="form-group">
                <label for="pallets_per_level" class="form-label">Paleți pe nivel</label>
                <input type="number" id="pallets_per_level" name="pallets_per_level" class="form-control" value="<?= htmlspecialchars($current['pallets_per_level'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group">
                <label for="barrels_per_pallet_5l" class="form-label">Butoaie 5L / palet</label>
                <input type="number" id="barrels_per_pallet_5l" name="barrels_per_pallet_5l" class="form-control" value="<?= htmlspecialchars($current['barrels_per_pallet_5l'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group">
                <label for="barrels_per_pallet_10l" class="form-label">Butoaie 10L / palet</label>
                <input type="number" id="barrels_per_pallet_10l" name="barrels_per_pallet_10l" class="form-control" value="<?= htmlspecialchars($current['barrels_per_pallet_10l'] ?? 0) ?>" min="0">
            </div>
            <div class="form-group">
                <label for="barrels_per_pallet_25l" class="form-label">Butoaie 25L / palet</label>
                <input type="number" id="barrels_per_pallet_25l" name="barrels_per_pallet_25l" class="form-control" value="<?= htmlspecialchars($current['barrels_per_pallet_25l'] ?? 0) ?>" min="0">
            </div>
            <button type="submit" class="btn btn-primary">Salvează</button>
        </form>
    </div>
</div>
<?php require_once BASE_PATH . '/includes/footer.php'; ?>
</body>
</html>
