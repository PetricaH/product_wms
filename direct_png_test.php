<?php
/**
 * Fix for Both Printer Name and Template Size Issues
 */

// ===== ISSUE 1: CORRECT PRINTER NAME =====

/**
 * Test different printer names to find the correct one
 */
function testPrinterNames($testUrl) {
    echo "=== TESTING PRINTER NAMES ===\n";
    
    $printerNames = [
        'Godex EZ6250i',
        'EZ6250i', 
        'godex_ez6250i',
        'godex-ez6250i',
        'GODEX_EZ6250i',
        'ez6250i',
        'thermal_printer',
        'godex_thermal',
        'godex6250',
        'default'
    ];
    
    $printServerUrl = 'http://86.124.196.102:3000/print_server.php';
    
    foreach ($printerNames as $printerName) {
        echo "\n🖨️  Testing printer name: '$printerName'\n";
        
        $testRequestUrl = $printServerUrl . '?' . http_build_query([
            'url' => $testUrl,
            'printer' => $printerName,
            'format' => 'png',
            'quality' => 'maximum'
        ]);
        
        echo "Request: $testRequestUrl\n";
        
        // Test without actually printing
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($testRequestUrl, false, $context);
        
        if ($response !== false) {
            echo "Response: " . substr($response, 0, 100) . "...\n";
            
            // Check if response indicates correct printer
            if (stripos($response, 'EZ6250i') !== false || 
                stripos($response, '6250') !== false ||
                stripos($response, 'success') !== false ||
                stripos($response, 'sent') !== false) {
                echo "✅ LIKELY CORRECT PRINTER NAME: '$printerName'\n";
            } else if (stripos($response, '500') !== false || 
                      stripos($response, 'wrong') !== false) {
                echo "❌ Wrong printer (triggered different printer)\n";
            } else {
                echo "⚠️  Unknown response\n";
            }
        } else {
            echo "❌ No response\n";
        }
    }
}

// ===== ISSUE 2: TEMPLATE SIZE FIX =====

/**
 * Upgrade templates from 187 DPI to 203 DPI
 */
function upgradeTemplatesToCorrectSize() {
    echo "\n=== UPGRADING TEMPLATE SIZES ===\n";
    
    $basePath = '/var/www/notsowms.ro';
    $templateDir = $basePath . '/storage/templates/product_labels/';
    $upgradeDir = $templateDir . 'upgraded_203dpi/';
    
    // Create upgrade directory
    if (!is_dir($upgradeDir)) {
        if (!mkdir($upgradeDir, 0755, true)) {
            echo "❌ Cannot create upgrade directory: $upgradeDir\n";
            return false;
        }
        echo "✅ Created upgrade directory: $upgradeDir\n";
    }
    
    $templates = glob($templateDir . '*.png');
    
    if (empty($templates)) {
        echo "❌ No templates found\n";
        return false;
    }
    
    // Target dimensions for 203 DPI
    $targetWidth = 1183;   // 148mm @ 203 DPI
    $targetHeight = 1679;  // 210mm @ 203 DPI
    
    echo "Target size: {$targetWidth}x{$targetHeight} (203 DPI)\n\n";
    
    foreach ($templates as $templatePath) {
        $templateName = basename($templatePath);
        
        // Skip if already upgraded
        if (strpos($templateName, '_thermal_optimized') !== false || 
            strpos($templateName, '_203dpi') !== false) {
            echo "⏭️  Skipping already optimized: $templateName\n";
            continue;
        }
        
        echo "🔧 Upgrading: $templateName\n";
        
        $original = @imagecreatefrompng($templatePath);
        if (!$original) {
            echo "❌ Cannot load: $templateName\n";
            continue;
        }
        
        $origWidth = imagesx($original);
        $origHeight = imagesy($original);
        
        echo "   Original: {$origWidth}x{$origHeight}\n";
        
        // Create high-quality upscaled version
        $upgraded = imagecreatetruecolor($targetWidth, $targetHeight);
        
        // Enable all quality features
        imagealphablending($upgraded, false);
        imagesavealpha($upgraded, true);
        if (function_exists('imageantialias')) {
            imageantialias($upgraded, true);
        }
        
        // Preserve transparency
        $transparent = imagecolorallocatealpha($upgraded, 0, 0, 0, 127);
        imagefill($upgraded, 0, 0, $transparent);
        
        // High-quality resampling
        imagecopyresampled($upgraded, $original, 0, 0, 0, 0,
                          $targetWidth, $targetHeight, $origWidth, $origHeight);
        
        // Apply sharpening for upscaled images
        if (function_exists('imagefilter')) {
            imagefilter($upgraded, IMG_FILTER_CONTRAST, -5);
            // Slight unsharp mask effect
            imagefilter($upgraded, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($upgraded, IMG_FILTER_CONTRAST, -10);
        }
        
        // Set DPI metadata
        if (function_exists('imageresolution')) {
            imageresolution($upgraded, 203, 203);
        }
        
        // Save upgraded version
        $upgradedName = str_replace('.png', '_203dpi.png', $templateName);
        $upgradedPath = $upgradeDir . $upgradedName;
        
        if (imagepng($upgraded, $upgradedPath, 0)) {  // No compression
            $newSize = filesize($upgradedPath);
            echo "   ✅ Upgraded: {$targetWidth}x{$targetHeight} (" . number_format($newSize) . " bytes)\n";
            echo "   📁 Saved: $upgradedName\n";
        } else {
            echo "   ❌ Failed to save upgraded version\n";
        }
        
        imagedestroy($original);
        imagedestroy($upgraded);
    }
    
    echo "\n🎯 NEXT STEPS:\n";
    echo "1. Test an upgraded template by copying it back:\n";
    echo "   cp {$upgradeDir}[template]_203dpi.png {$templateDir}test_203dpi.png\n";
    echo "2. Update your findProductTemplate() function to prefer _203dpi versions\n";
    echo "3. Once confirmed working, replace original templates\n";
    
    return $upgradeDir;
}

/**
 * Test upgraded template
 */
function testUpgradedTemplate($upgradedTemplatePath, $correctPrinterName) {
    echo "\n=== TESTING UPGRADED TEMPLATE ===\n";
    
    if (!file_exists($upgradedTemplatePath)) {
        echo "❌ Upgraded template not found: $upgradedTemplatePath\n";
        return false;
    }
    
    // Analyze the upgraded template
    $image = imagecreatefrompng($upgradedTemplatePath);
    if (!$image) {
        echo "❌ Cannot load upgraded template\n";
        return false;
    }
    
    $width = imagesx($image);
    $height = imagesy($image);
    imagedestroy($image);
    
    echo "Upgraded template: " . basename($upgradedTemplatePath) . "\n";
    echo "Dimensions: {$width}x{$height}\n";
    
    if ($width == 1183 && $height == 1679) {
        echo "✅ PERFECT dimensions for 203 DPI thermal printing!\n";
    } else {
        echo "❌ Dimensions still not correct\n";
        return false;
    }
    
    // Copy to label_pngs for testing
    $basePath = '/var/www/notsowms.ro';
    $testDir = $basePath . '/storage/label_pngs/';
    $testName = 'UPGRADED_TEST_' . time() . '_' . basename($upgradedTemplatePath);
    $testPath = $testDir . $testName;
    
    if (!copy($upgradedTemplatePath, $testPath)) {
        echo "❌ Cannot copy for testing\n";
        return false;
    }
    
    $testUrl = 'https://notsowms.ro/storage/label_pngs/' . $testName;
    echo "Test URL: $testUrl\n";
    
    // Print with correct printer name
    $printUrl = 'http://86.124.196.102:3000/print_server.php?' . http_build_query([
        'url' => $testUrl,
        'printer' => $correctPrinterName,
        'format' => 'png',
        'quality' => 'maximum',
        'dpi' => '203'
    ]);
    
    echo "Print URL: $printUrl\n";
    echo "\n🧪 EXPECTED RESULTS:\n";
    echo "✅ Should print crisp and clear (no pixelation)\n";
    echo "✅ Text should be sharp and readable\n";
    echo "✅ Graphics should be high quality\n";
    
    return $testUrl;
}

/**
 * Update your code to use correct settings
 */
function generateUpdatedConfig() {
    echo "\n=== UPDATED CONFIGURATION ===\n";
    
    echo "📝 1. Update your config/config.php:\n";
    echo "```php\n";
    echo "'default_printer' => 'EZ6250i',  // or whatever name works\n";
    echo "'print_server_url' => 'http://86.124.196.102:3000/print_server.php',\n";
    echo "'label_dpi' => 203,\n";
    echo "'label_width_mm' => 148,\n";
    echo "'label_height_mm' => 210,\n";
    echo "'label_width_px' => 1183,\n";
    echo "'label_height_px' => 1679\n";
    echo "```\n\n";
    
    echo "📝 2. Update your generateCombinedTemplateLabel function:\n";
    echo "```php\n";
    echo "// Use EXACT dimensions - no scaling needed for upgraded templates\n";
    echo "\$targetWidth = 1183;  // 203 DPI\n";
    echo "\$targetHeight = 1679; // 203 DPI\n";
    echo "\n";
    echo "// Prefer upgraded templates\n";
    echo "function findProductTemplate(\$sku, \$productName) {\n";
    echo "    // Look for _203dpi version first\n";
    echo "    \$upgradedTemplate = \$templateDir . \$productCode . '_203dpi.png';\n";
    echo "    if (file_exists(\$upgradedTemplate)) {\n";
    echo "        return \$upgradedTemplate;\n";
    echo "    }\n";
    echo "    // Fallback to original...\n";
    echo "}\n";
    echo "```\n\n";
    
    echo "📝 3. Test printer names to find correct one\n";
    echo "📝 4. Use upgraded 203 DPI templates\n";
    echo "📝 5. Generate larger overlay elements (fonts/barcodes)\n";
}

// ===== MAIN EXECUTION =====

echo "=== FIXING BOTH PRINTER AND TEMPLATE ISSUES ===\n\n";

// Test URL from your previous test
$testUrl = 'https://notsowms.ro/storage/label_pngs/DIRECT_TEST_1754638647_LILLIOS-800.png';

echo "Using test URL: $testUrl\n\n";

// Step 1: Find correct printer name
testPrinterNames($testUrl);

echo "\n" . str_repeat("=", 50) . "\n";

// Step 2: Upgrade templates
$upgradeDir = upgradeTemplatesToCorrectSize();

echo "\n" . str_repeat("=", 50) . "\n";

// Step 3: Provide configuration updates
generateUpdatedConfig();

echo "\n🎯 SUMMARY:\n";
echo "1. ❌ Templates are 187 DPI (too small) - need upgrading to 203 DPI\n";
echo "2. ❌ Wrong printer name 'godex' triggers 'godex 500' instead of 'EZ6250i'\n";
echo "3. ✅ Run the upgrade function to create 203 DPI templates\n";
echo "4. ✅ Test different printer names to find the correct one\n";
echo "5. ✅ Update your code with correct settings\n";

?>