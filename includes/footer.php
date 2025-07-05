<?php
// includes/footer.php - Updated footer with conditional JS loading
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
    
    <?php
    // Load page-specific JavaScript if exists
    echo loadPageAsset($currentPage, 'scripts');
    ?>
</body>
</html>