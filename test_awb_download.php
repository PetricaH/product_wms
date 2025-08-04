<?php
/**
 * Test Your Existing CargusService AWB Download
 * Save as: test_existing_cargus.php
 * Run: php test_existing_cargus.php
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    
    require_once BASE_PATH . '/models/CargusService.php';
    $cargusService = new CargusService($db);
    
    echo "=== Testing Existing CargusService AWB Download ===\n\n";
    
    $awbNumber = '1155793367'; // Your test AWB
    
    echo "Testing download for AWB: $awbNumber\n";
    echo "Using existing getAwbDocuments() method...\n\n";
    
    // Test the existing method
    $result = $cargusService->getAwbDocuments(
        [$awbNumber],  // AWB codes array
        'PDF',         // type
        1,             // format (1=label, 0=A4)
        1              // printMainOnce
    );
    
    if ($result['success']) {
        $base64Data = $result['data'];
        
        // Decode and save
        $pdfContent = base64_decode($base64Data, false);
        $filename = "test_existing_awb_{$awbNumber}.pdf";
        file_put_contents($filename, $pdfContent);
        
        echo "✅ SUCCESS!\n";
        echo "- Base64 data length: " . strlen($base64Data) . " characters\n";
        echo "- PDF content length: " . strlen($pdfContent) . " bytes\n";
        echo "- PDF header check: " . (substr($pdfContent, 0, 4) === '%PDF' ? '✅ Valid PDF' : '❌ Invalid PDF') . "\n";
        echo "- Saved as: $filename\n";
        echo "- You can now open this PDF to verify it works\n";
    } else {
        echo "❌ FAILED!\n";
        echo "- Error: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
?>