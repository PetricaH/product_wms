  <!-- Universal JavaScript -->
  <script src="<?= getAsset('main', 'scripts', true) ?>"></script>
    
    <?php
    // Load page-specific JavaScript if exists
    echo loadPageAsset($currentPage, 'scripts');
    ?>
</body>
</html>