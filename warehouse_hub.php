<?php
// Include necessary files
require_once 'bootstrap.php';
require_once 'includes/helpers.php';
require_once 'models/LocationLevelSettings.php';

// Check if user is logged in (optional - depending on your auth system)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <?php include_once 'includes/warehouse_header.php'; ?>
    <title>Hub Operații Depozit - WMS</title>
</head>
<body>
    <!-- Header Navigation -->
    <?php include_once 'includes/warehouse_navbar.php'; ?>

    <!-- Main Container -->
    <div class="main-container">
        <!-- <div class="page-header">
            <h1 class="page-title">Hub Operații Depozit</h1>
            <p class="page-subtitle">Selectează operația dorită pentru a începe lucrul</p>
            <a href="biometric-setup.php">Setup Biometric Auth</a>
        </div> -->

        <div class="operations-grid">
            <!-- Picking -->
            <div class="operation-card picking-card" data-operation="picking">
                <div class="status-indicator" id="picking-status"></div>
                <span class="material-symbols-outlined operation-icon">shopping_cart</span>
                <h2 class="operation-title">Picking Comenzi</h2>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="pending-picks">-</span>
                        <span class="stat-label">În așteptare</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="picks-today">-</span>
                        <span class="stat-label">Astăzi</span>
                    </div>
                </div>
            </div>

            <!-- Receiving -->
            <div class="operation-card receiving-card" data-operation="receiving">
                <div class="status-indicator" id="receiving-status"></div>
                <span class="material-symbols-outlined operation-icon">inventory_2</span>
                <h2 class="operation-title">Recepție Marfă</h2>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="pending-receipts">-</span>
                        <span class="stat-label">De procesat</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="received-today">-</span>
                        <span class="stat-label">Astăzi</span>
                    </div>
                </div>
            </div>

            <!-- Relocation -->
            <div class="operation-card relocation-card" data-operation="relocation">
                <div class="status-indicator" id="relocation-status"></div>
                <span class="material-symbols-outlined operation-icon">swap_horiz</span>
                <h2 class="operation-title">Relocare Stoc</h2>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="pending-relocations">-</span>
                        <span class="stat-label">În așteptare</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="relocated-today">-</span>
                        <span class="stat-label">Astăzi</span>
                    </div>
                </div>
            </div>
            
            <!-- Inventory -->
            <div class="operation-card inventory-card" data-operation="inventory">
                <div class="status-indicator" id="inventory-status"></div>
                <span class="material-symbols-outlined operation-icon">inventory</span>
                <h2 class="operation-title">Căutare Stoc</h2>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="total-products">-</span>
                        <span class="stat-label">Produse</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="low-stock-items">-</span>
                        <span class="stat-label">Stoc scăzut</span>
                    </div>
                </div>
            </div>

            <!-- Cycle Count -->
            <div class="operation-card cycle-count-card" data-operation="cycle-count">
                <div class="status-indicator" id="cycle-count-status"></div>
                <span class="material-symbols-outlined operation-icon">fact_check</span>
                <h2 class="operation-title">Inventariere Ciclică</h2>
                <div class="operation-stats">
                    <div class="stat-item">
                        <span class="stat-number" id="scheduled-counts">-</span>
                        <span class="stat-label">Programate</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number" id="variance-items">-</span>
                        <span class="stat-label">Diferențe</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php include_once 'includes/warehouse_footer.php'; ?>