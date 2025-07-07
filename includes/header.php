<?php
// includes/header.php - Updated to include new CSS files with conditional loading
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';

// Get current page name for conditional loading
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<title>WMS Admin Dashboard</title>

<?php if (in_prod()): ?>
  <!-- Production CSS -->
  <link rel="stylesheet" href="<?= asset('/styles/global.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/sidebar.css') ?>">
  
  <?php
  // Load page-specific CSS in production
  $pageSpecificCSS = [
      'index' => 'index.css',
      'users' => 'users.css', 
      'products' => 'products.css',
      'locations' => 'locations.css',
      'inventory' => 'inventory.css',
      'orders' => 'orders.css',
      'transactions' => 'transactions.css',
      'smartbill-sync' => 'smartbill-sync.css',
      'activities' => 'activities.css'
  ];
  
  if (isset($pageSpecificCSS[$currentPage])) {
      echo '<link rel="stylesheet" href="' . asset('/styles/' . $pageSpecificCSS[$currentPage]) . '">';
  }
  ?>
  
  <!-- Universal Scripts -->
  <script src="<?= asset('scripts/universal.js') ?>" defer></script>
  
<?php else: ?>
  <!-- Development CSS -->
  <link rel="stylesheet" href="/product_wms/styles/global.css">
  <link rel="stylesheet" href="/product_wms/styles/sidebar.css">
  
  <?php
  // Load page-specific CSS in development
  $pageSpecificCSS = [
      'index' => 'index.css',
      'users' => 'users.css',
      'products' => 'products.css', 
      'locations' => 'locations.css',
      'inventory' => 'inventory.css',
      'orders' => 'orders.css',
      'transactions' => 'transactions.css',
      'smartbill-sync' => 'smartbill_sync.css',
      'activities' => 'activities.css'
  ];
  
  if (isset($pageSpecificCSS[$currentPage])) {
      echo '<link rel="stylesheet" href="/product_wms/styles/' . $pageSpecificCSS[$currentPage] . '">';
  }
  ?>
  
  <!-- Universal Scripts -->
  <script src="/product_wms/scripts/universal.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="<?= asset('scripts/theme-toggle.js') ?>" defer></script>
<?php endif; ?>