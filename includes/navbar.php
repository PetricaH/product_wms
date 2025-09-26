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

if (!function_exists('resolveIncidentSidebarCount')) {
    /**
     * Return the number of unresolved incidents for the sidebar badge.
     */
    function resolveIncidentSidebarCount(?array $existingConfig = null): int
    {
        static $cachedCount = null;

        if ($cachedCount !== null) {
            return $cachedCount;
        }

        $cachedCount = 0;

        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            return $cachedCount;
        }

        $configData = $existingConfig;
        if ($configData === null) {
            $configPath = BASE_PATH . '/config/config.php';
            if (!is_readable($configPath)) {
                return $cachedCount;
            }
            $configData = require $configPath;
        }

        $dbFactory = $configData['connection_factory'] ?? null;
        if (!$dbFactory || !is_callable($dbFactory)) {
            return $cachedCount;
        }

        try {
            $db = $dbFactory();
            require_once BASE_PATH . '/models/Incident.php';
            $incidentModel = new Incident($db);
            $cachedCount = (int) $incidentModel->getUnresolvedTotal();
        } catch (Throwable $exception) {
            $cachedCount = 0;
        }

        return $cachedCount;
    }
}

$incidentSidebarCount = $incidentSidebarCount ?? null;
if ($incidentSidebarCount === null) {
    $incidentSidebarCount = resolveIncidentSidebarCount($config ?? null);
}
?>

<button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="Open Menu">
    <span class="material-symbols-outlined">menu</span>
</button>

<aside class="sidebar" id="sidebar">
    <div class="sidebar__header">
        <a href="<?= getNavUrl('index.php') ?>" class="sidebar__logo">
            <div class="logo-icon">W</div>
            <span class="logo-text">WMS</span>
        </a>
        <button class="sidebar__toggle" id="sidebar-toggle" type="button" aria-label="Restrânge bara laterală" aria-expanded="true">
            <span class="material-symbols-outlined">chevron_left</span>
        </button>
    </div>

    <ul class="sidebar__nav" role="navigation">
        
        <li class="sidebar__item">
            <a href="<?= getNavUrl('index.php') ?>" 
               class="sidebar__link <?= getActiveClass('index.php') ?>"
               data-tooltip="Tablou de Bord">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="link-text">Tablou de Bord</span>
            </a>
        </li>
        
        <li class="sidebar__item">
            <a href="<?= getNavUrl('orders.php') ?>" 
               class="sidebar__link <?= getActiveClass('orders.php') ?>"
               data-tooltip="Comenzi">
                <span class="material-symbols-outlined">shopping_cart</span>
                <span class="link-text">Comenzi</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('inventory.php') ?>" 
               class="sidebar__link <?= getActiveClass('inventory.php') ?>"
               data-tooltip="Stocuri">
                <span class="material-symbols-outlined">inventory</span>
                <span class="link-text">Stocuri</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('purchase_orders.php') ?>" 
               class="sidebar__link <?= getActiveClass('purchase_orders.php') ?>"
               data-tooltip="Comenzi Achiziție">
                <span class="material-symbols-outlined">shopping_basket</span>
                <span class="link-text">Comenzi Stoc</span>
            </a>
        </li>
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

        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager'])): ?>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('returns_dashboard.php') ?>"
               class="sidebar__link <?= getActiveClass('returns_dashboard.php') ?>"
               data-tooltip="Returnări">
                <span class="material-symbols-outlined">assignment_return</span>
                <span class="link-text">Returnări</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('incidents-admin.php') ?>"
               class="sidebar__link <?= getActiveClass('incidents-admin.php') ?>"
               data-tooltip="Incidente">
                <span class="material-symbols-outlined">emergency</span>
                <span class="link-text">Incidente</span>
                <span class="sidebar__badge <?= ($incidentSidebarCount ?? 0) > 0 ? 'sidebar__badge--alert' : 'sidebar__badge--clear' ?>">
                    <?= $incidentSidebarCount ?? 0 ?>
                </span>
            </a>
        </li>
        <?php endif; ?>

        <li class="sidebar__item">
            <a href="<?= getNavUrl('products.php') ?>"
               class="sidebar__link <?= getActiveClass('products.php') ?>"
               data-tooltip="Produse">
                <span class="material-symbols-outlined">inventory_2</span>
                <span class="link-text">Produse</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('sellers.php') ?>" 
               class="sidebar__link <?= getActiveClass('sellers.php') ?>"
               data-tooltip="Furnizori">
                <span class="material-symbols-outlined">store</span>
                <span class="link-text">Furnizori</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('locations.php') ?>" 
               class="sidebar__link <?= getActiveClass('locations.php') ?>"
               data-tooltip="Depozite">
                <span class="material-symbols-outlined">shelf_position</span>
                <span class="link-text">Locații</span>
            </a>
        </li>

        <li class="sidebar__item">
            <a href="<?= getNavUrl('users.php') ?>" 
               class="sidebar__link <?= getActiveClass('users.php') ?>"
               data-tooltip="Utilizatori">
                <span class="material-symbols-outlined">group</span>
                <span class="link-text">Utilizatori</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('warehouse_settings.php') ?>"
               class="sidebar__link <?= getActiveClass('warehouse_settings.php') ?>"
               data-tooltip="Setări Depozit">
                <span class="material-symbols-outlined">settings</span>
                <span class="link-text">Setări Depozit</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('product-units.php') ?>" 
               class="sidebar__link <?= getActiveClass('product-units.php') ?>"
               data-tooltip="Unități Produse">
                <span class="material-symbols-outlined">rule_settings</span> <span class="link-text">Setări Produse</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('printer-management.php') ?>"
               class="sidebar__link <?= getActiveClass('printer-management.php') ?>"
               data-tooltip="Imprimante">
                <span class="material-symbols-outlined">print</span>
                <span class="link-text">Imprimante</span>
            </a>
        </li>
        <li class="sidebar__item">
            <a href="<?= getNavUrl('activities.php') ?>"
               class="sidebar__link <?= getActiveClass('activities.php') ?>"
               data-tooltip="Istoric Acțiuni">
                <span class="material-symbols-outlined">history</span>
                <span class="link-text">Istoric Acțiuni</span>
            </a>
        </li>
         <li class="sidebar__item">
            <a href="<?= getNavUrl('warehouse_hub.php') ?>"
               class="sidebar__link <?= getActiveClass('warehouse_hub.php') ?>"
               data-tooltip="Warehouse View">
                <span class="material-symbols-outlined">warehouse</span>
                <span class="link-text">Warehouse View</span>
            </a>
        </li>
        
        </ul>

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
           title="Logout">
            <span class="material-symbols-outlined">logout</span>
        </a>
    </div>
</aside>

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
    const logoutLink = document.querySelector('.sidebar__profile .logout-link');

    const lockBodyScroll = (locked) => {
        document.body.style.overflow = locked ? 'hidden' : '';
    };

    if (sidebar && sidebarToggle) {
        const toggleIcon = sidebarToggle.querySelector('.material-symbols-outlined');

        const applyCollapsedState = (collapsed) => {
            sidebar.classList.toggle('collapsed', collapsed);
            localStorage.setItem('sidebar-collapsed', collapsed);

            if (toggleIcon) {
                toggleIcon.textContent = collapsed ? 'chevron_right' : 'chevron_left';
            }

            const label = collapsed ? 'Extinde bara laterală' : 'Restrânge bara laterală';
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', label);
            sidebarToggle.setAttribute('title', label);
        };

        const savedCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
        applyCollapsedState(savedCollapsed);

        sidebarToggle.addEventListener('click', function() {
            const collapsed = !sidebar.classList.contains('collapsed');
            applyCollapsedState(collapsed);
        });
    }

    const toggleMobileSidebar = () => {
        if (!sidebar) {
            return;
        }

        const isOpen = sidebar.classList.toggle('mobile-open');
        if (sidebarOverlay) {
            sidebarOverlay.classList.toggle('active', isOpen);
        }
        lockBodyScroll(isOpen);
    };

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', toggleMobileSidebar);
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', toggleMobileSidebar);
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                toggleMobileSidebar();
            }
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar) {
            sidebar.classList.remove('mobile-open');
            if (sidebarOverlay) {
                sidebarOverlay.classList.remove('active');
            }
            lockBodyScroll(false);
        }
    });

    const showLogoutModal = (logoutUrl) => {
        if (!logoutUrl) {
            return;
        }

        if (sidebar && sidebar.classList.contains('mobile-open')) {
            toggleMobileSidebar();
        }

        const existingModal = document.querySelector('.wms-modal[data-modal="logout"]');
        if (existingModal) {
            existingModal.remove();
        }

        const modal = document.createElement('div');
        modal.className = 'wms-modal';
        modal.dataset.modal = 'logout';
        modal.innerHTML = `
            <div class="wms-modal__backdrop"></div>
            <div class="wms-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title" aria-describedby="logout-modal-message">
                <div class="wms-modal__icon">
                    <span class="material-symbols-outlined" aria-hidden="true">logout</span>
                </div>
                <h2 class="wms-modal__title" id="logout-modal-title">Confirmați deconectarea</h2>
                <p class="wms-modal__message" id="logout-modal-message">
                    Sunteți sigur că doriți să ieșiți din aplicația WMS?
                </p>
                <div class="wms-modal__actions">
                    <button type="button" class="wms-modal__cancel">Rămâi conectat</button>
                    <button type="button" class="wms-modal__confirm">
                        <span class="material-symbols-outlined" aria-hidden="true">check_circle</span>
                        Deconectare
                    </button>
                </div>
            </div>
        `;

        const cancelButton = modal.querySelector('.wms-modal__cancel');
        const confirmButton = modal.querySelector('.wms-modal__confirm');
        const backdrop = modal.querySelector('.wms-modal__backdrop');

        const closeModal = () => {
            modal.classList.remove('is-visible');
            document.body.classList.remove('modal-open');
            modal.addEventListener('transitionend', () => modal.remove(), { once: true });
            document.removeEventListener('keydown', handleKeydown);
        };

        const handleKeydown = (event) => {
            if (event.key === 'Escape') {
                closeModal();
            }
        };

        if (cancelButton) {
            cancelButton.addEventListener('click', (event) => {
                event.preventDefault();
                closeModal();
            });
        }

        if (confirmButton) {
            confirmButton.addEventListener('click', (event) => {
                event.preventDefault();
                closeModal();
                window.location.href = logoutUrl;
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', closeModal);
        }

        document.body.appendChild(modal);
        requestAnimationFrame(() => {
            modal.classList.add('is-visible');
            if (confirmButton) {
                confirmButton.focus();
            }
        });

        document.body.classList.add('modal-open');
        document.addEventListener('keydown', handleKeydown);
    };

    if (logoutLink) {
        logoutLink.addEventListener('click', function(event) {
            event.preventDefault();
            showLogoutModal(this.getAttribute('href'));
        });
    }
});
</script>