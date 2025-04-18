<?php
// included in <head> of every page
require_once __DIR__ . '/helpers.php';
?>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet" />
<title>Admin Dashboard</title>

<?php if (in_prod()): ?>
  <link rel="stylesheet" href="<?= asset('styles/global.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('styles/index.min.css') ?>">
  <script src="<?= asset('scripts/universal.min.js') ?>" defer></script>
  <script src="<?= asset('scripts/index.min.js') ?>" defer></script>
<?php else: ?>
  <link rel="stylesheet" href="/styles/global.css">
  <link rel="stylesheet" href="/styles/index.css">
  <script src="/scripts/universal.js" defer></script>
  <script src="/scripts/index.js" defer></script>
<?php endif; ?>
