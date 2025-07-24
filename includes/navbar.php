<?php
/**
 * Modern Monochrome Sidebar Navigation
 * Compact, collapsible sidebar that matches the new design language
 */

/**
 * Return the active class if the current script matches $page.
 */
function getActiveClass(string $page): string {
    $currentPage = basename($_SERVER['SCRIPT_NAME']);
    $targetPage = basename($page);
    
    return $currentPage === $targetPage ? 'sidebar__link--active' : '';
}

// Get user initials for avatar
$userInitials = 'AD'; // Default
if (isset($_SESSION['username'])) {
    $nameParts = explode(' ', $_SESSION['username']);
    $userInitials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $userInitials .= strtoupper(substr($nameParts[1], 0, 1));
    }
}
?>

<!-- Mobile menu toggle button -->
<button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Open Menu">
    <span class="material-symbols-outlined">menu</span>
</button>

<aside class="sidebar" id="sidebar">
    <!-- Sidebar Header -->
    <div class="sidebar__header">
        <a href="<?= getNavUrl('index.php') ?>" class="sidebar__logo">
            <div class="logo-icon">W</div>
            <span class="logo-text">WMS</span>
        </a>
        <button class="sidebar__toggle" id="sidebar-toggle" aria-label="Toggle Sidebar">
            <span class="material-symbols-outlined">chevron_left</span>
        </button>
    </div>

    <!-- Navigation Menu -->
    <ul class="sidebar__nav" role="navigation">
        <!-- Dashboard -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('index.php') ?>" 
               class="sidebar__link <?= getActiveClass('index.php') ?>"
               data-tooltip="Tablou de Bord">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="link-text">Tablou de Bord</span>
            </a>
        </li>
        
        <!-- User Management -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('users.php') ?>" 
               class="sidebar__link <?= getActiveClass('users.php') ?>"
               data-tooltip="Utilizatori">
                <span class="material-symbols-outlined">group</span>
                <span class="link-text">Utilizatori</span>
            </a>
        </li>
        
        <!-- Products -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('products.php') ?>" 
               class="sidebar__link <?= getActiveClass('products.php') ?>"
               data-tooltip="Produse">
                <span class="material-symbols-outlined">inventory_2</span>
                <span class="link-text">Produse</span>
            </a>
        </li>
        
        <!-- Inventory -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('inventory.php') ?>" 
               class="sidebar__link <?= getActiveClass('inventory.php') ?>"
               data-tooltip="Stocuri">
                <span class="material-symbols-outlined">inventory</span>
                <span class="link-text">Stocuri</span>
            </a>
        </li>

        <!-- Product Units Management -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('product-units.php') ?>" 
               class="sidebar__link <?= getActiveClass('product-units.php') ?>"
               data-tooltip="Unități Produse">
                <span class="material-symbols-outlined">inventory_2</span>
                <span class="link-text">Setări Produse</span>
            </a>
        </li>
        
        <!-- Locations -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('locations.php') ?>" 
               class="sidebar__link <?= getActiveClass('locations.php') ?>"
               data-tooltip="Depozite">
                <span class="material-symbols-outlined">warehouse</span>
                <span class="link-text">Locații</span>
            </a>
        </li>
        
        <!-- Orders -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('orders.php') ?>" 
               class="sidebar__link <?= getActiveClass('orders.php') ?>"
               data-tooltip="Comenzi">
                <span class="material-symbols-outlined">shopping_cart</span>
                <span class="link-text">Comenzi</span>
            </a>
        </li>
        
        <!-- Transactions -->
        <!-- <li class="sidebar__item">
            <a href="<?= getNavUrl('transactions.php') ?>" 
               class="sidebar__link <?= getActiveClass('transactions.php') ?>"
               data-tooltip="Tranzacții">
                <span class="material-symbols-outlined">receipt_long</span>
                <span class="link-text">Tranzacții</span>
            </a>
        </li> -->

        <!-- Sellers -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('sellers.php') ?>" 
               class="sidebar__link <?= getActiveClass('sellers.php') ?>"
               data-tooltip="Furnizori">
                <span class="material-symbols-outlined">store</span>
                <span class="link-text">Furnizori</span>
            </a>
        </li>

        <!-- Purchase Orders -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('purchase_orders.php') ?>" 
               class="sidebar__link <?= getActiveClass('purchase_orders.php') ?>"
               data-tooltip="Comenzi Achiziție">
                <span class="material-symbols-outlined">shopping_basket</span>
                <span class="link-text">Comenzi Stoc</span>
            </a>
        </li>

         <!-- Quality Control Management -->
        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'supervisor'])): ?>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('qc_management.php') ?>" 
               class="sidebar__link <?= getActiveClass('qc_management.php') ?>"
               data-tooltip="Control Calitate">
                <span class="material-symbols-outlined">verified</span>
                <span class="link-text">Control Calitate</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- SmartBill Sync -->
        <!-- <li class="sidebar__item">
            <a href="<?= getNavUrl('smartbill-sync.php') ?>"
               class="sidebar__link <?= getActiveClass('smartbill-sync.php') ?>"
               data-tooltip="SmartBill Sync">
                <span class="material-symbols-outlined">sync</span>
                <span class="link-text">SmartBill Sync</span>
            </a>
        </li> -->

        <!-- Printers -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('printer-management.php') ?>"
               class="sidebar__link <?= getActiveClass('printer-management.php') ?>"
               data-tooltip="Imprimante">
                <span class="material-symbols-outlined">print</span>
                <span class="link-text">Imprimante</span>
            </a>
        </li>

        <!-- Warehouse Settings -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('warehouse_settings.php') ?>"
               class="sidebar__link <?= getActiveClass('warehouse_settings.php') ?>"
               data-tooltip="Setări Depozit">
                <span class="material-symbols-outlined">settings</span>
                <span class="link-text">Setări Depozit</span>
            </a>
        </li>

        <!-- Activity Log -->
        <li class="sidebar__item">
            <a href="<?= getNavUrl('activities.php') ?>"
               class="sidebar__link <?= getActiveClass('activities.php') ?>"
               data-tooltip="Istoric Acțiuni">
                <span class="material-symbols-outlined">history</span>
                <span class="link-text">Istoric Acțiuni</span>
            </a>
        </li>
    </ul>

    <!-- User Profile Section -->
    <div class="sidebar__profile">
        <a href="views/users/profile.php">
            <div class="profile-avatar">
                <?= htmlspecialchars($userInitials) ?>
            </div>
            <div class="profile-info">
                <span class="profile-name">
                    <?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Admin User' ?>
                </span>
                <span class="profile-role">
                    <?= isset($_SESSION['role']) ? ucfirst(htmlspecialchars($_SESSION['role'])) : 'Administrator' ?>
                </span>
            </div>
        </a>
        <a href="<?= getNavUrl('logout.php') ?>" 
           class="logout-link" 
           title="Logout"
           onclick="return confirm('Sunteți sigur că doriți să vă deconectați?')">
            <span class="material-symbols-outlined">logout</span>
        </a>
    </div>
</aside>

<!-- Mobile Overlay -->
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<script>
/**
 * Sidebar Toggle Functionality
 */
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    
    // Load saved sidebar state
    const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
    }
    
    // Toggle sidebar collapse
    sidebarToggle.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        const collapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebar-collapsed', collapsed);
    });
    
    // Mobile functionality
    if (window.innerWidth <= 768) {
        // Mobile menu toggle
        function toggleMobileSidebar() {
            sidebar.classList.toggle('mobile-open');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
        }

        // Open sidebar when clicking the mobile menu button
        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                toggleMobileSidebar();
            });
        }
        
        // Close sidebar when clicking overlay
        sidebarOverlay.addEventListener('click', function() {
            toggleMobileSidebar();
        });
        
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
                toggleMobileSidebar();
            }
        });
        
        // Expose toggle function globally for mobile menu button
        window.toggleMobileSidebar = toggleMobileSidebar;
    }
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            sidebar.classList.remove('mobile-open');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});
</script>