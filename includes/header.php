<?php
// includes/header.php - Updated to include new CSS files
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../bootstrap.php';
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
  <link rel="stylesheet" href="<?= asset('/styles/index.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/users.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/products.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/locations.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/inventory.css') ?>">
  <link rel="stylesheet" href="<?= asset('/styles/orders.css') ?>">
  <script src="<?= asset('scripts/universal.js') ?>" defer></script>
  <script src="<?= asset('scripts/index.js') ?>" defer></script>
<?php else: ?>
  <!-- Development CSS -->
  <link rel="stylesheet" href="/product_wms/styles/global.css">
  <link rel="stylesheet" href="/product_wms/styles/index.css">
  <link rel="stylesheet" href="/product_wms/styles/users.css">
  <link rel="stylesheet" href="/product_wms/styles/products.css">
  <link rel="stylesheet" href="/product_wms/styles/locations.css">
  <link rel="stylesheet" href="/product_wms/styles/inventory.css">
  <link rel="stylesheet" href="/product_wms/styles/orders.css">
  <link rel="stylesheet" href="/product_wms/styles/transactions.css">
  <script src="/product_wms/scripts/universal.js" defer></script>
  <script src="/product_wms/scripts/index.js" defer></script>
<?php endif; ?>