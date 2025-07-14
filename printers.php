<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . getNavUrl('login.php'));
    exit;
}

$pageTitle = 'Administrare Imprimante';
$currentPage = 'printers';
?>
<!DOCTYPE html>
<html lang="ro" data-theme="dark">
<head>
    <?php require_once BASE_PATH . '/includes/header.php'; ?>
</head>
<body>
<div class="app">
    <?php require_once BASE_PATH . '/includes/navbar.php'; ?>
    <main class="main-content">
        <div class="page-container">
            <div class="page-header">
                <div class="page-header-content">
                    <h1 class="page-title">
                        <span class="material-symbols-outlined">print</span>
                        Administrare Imprimante
                    </h1>
                    <div class="header-actions">
                        <button class="btn btn-primary" id="addPrinterBtn">
                            <span class="material-symbols-outlined">add</span>
                            Adaugă Imprimantă
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" id="printersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nume</th>
                            <th>Adresă Rețea</th>
                            <th>Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody id="printersBody">
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<div class="modal" id="printerModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="printerModalTitle">Imprimantă</h3>
                <button class="modal-close" onclick="closePrinterModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="printerForm">
                <div class="modal-body">
                    <input type="hidden" id="printerId" name="id">
                    <div class="form-group">
                        <label for="printerName" class="form-label">Nume</label>
                        <input type="text" id="printerName" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="printerAddress" class="form-label">Adresă Rețea</label>
                        <input type="text" id="printerAddress" name="network_address" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closePrinterModal()">Anulează</button>
                    <button type="submit" class="btn btn-primary">Salvează</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="deletePrinterModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Șterge Imprimantă</h3>
                <button class="modal-close" onclick="closeDeleteModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <form id="deletePrinterForm">
                <div class="modal-body">
                    <p>Sunteți sigur că doriți să ștergeți imprimanta <strong id="deletePrinterName"></strong>?</p>
                    <input type="hidden" id="deletePrinterId" name="id">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Anulează</button>
                    <button type="submit" class="btn btn-danger">Șterge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
