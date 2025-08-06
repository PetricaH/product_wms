<?php
/**
 * Debug Template Loading Script
 * Test if templates load correctly
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', '/var/www/notsowms.ro');
}

echo "=== TEMPLATE LOADING DEBUG ===\n";

$templateDir = BASE_PATH . '/storage/templates/product_labels/';
$testSku = '806.25';
$testProductName = 'Test Product';

echo "Template Directory: $templateDir\n";
echo "Test SKU: $testSku\n\n";

// Test the findProductTemplate function logic
echo "=== TESTING TEMPLATE FINDING LOGIC ===\n";

if (preg_match('/(\d+)/', $testSku, $matches)) {
    $productCode = $matches[1];
    echo "Extracted product code: '$productCode' from SKU '$testSku'\n";
    
    // Look for templates containing this number
    $availableTemplates = glob($templateDir . '*.png');
    echo "Total PNG templates found: " . count($availableTemplates) . "\n";
    
    $matchingTemplates = [];
    foreach ($availableTemplates as $templatePath) {
        $templateName = basename($templatePath, '.png');
        echo "  Checking template: $templateName\n";
        
        if (strpos($templateName, $productCode) !== false) {
            echo "    ✅ MATCHES product code '$productCode'\n";
            $matchingTemplates[] = $templatePath;
        } else {
            echo "    ❌ No match\n";
        }
    }
    
    if (!empty($matchingTemplates)) {
        $selectedTemplate = $matchingTemplates[0];
        echo "\n✅ SELECTED TEMPLATE: " . basename($selectedTemplate) . "\n";
        
        // Test loading the template
        echo "\n=== TESTING TEMPLATE LOADING ===\n";
        echo "Loading: $selectedTemplate\n";
        echo "File exists: " . (file_exists($selectedTemplate) ? 'YES' : 'NO') . "\n";
        echo "File size: " . filesize($selectedTemplate) . " bytes\n";
        echo "File readable: " . (is_readable($selectedTemplate) ? 'YES' : 'NO') . "\n";
        
        $image = imagecreatefrompng($selectedTemplate);
        if ($image === false) {
            echo "❌ FAILED to load with imagecreatefrompng()\n";
            
            // Check if it's a GD issue
            if (!extension_loaded('gd')) {
                echo "❌ GD extension not loaded!\n";
            } else {
                echo "✅ GD extension is loaded\n";
                echo "GD info: " . gd_info()['GD Version'] . "\n";
            }
            
        } else {
            echo "✅ SUCCESS - Template loaded!\n";
            $width = imagesx($image);
            $height = imagesy($image);
            echo "Dimensions: ${width} x ${height}\n";
            echo "Is truecolor: " . (imageistruecolor($image) ? 'YES' : 'NO') . "\n";
            
            // Save a test copy
            $testOutputDir = BASE_PATH . '/storage/label_pngs/';
            if (!is_dir($testOutputDir)) {
                mkdir($testOutputDir, 0755, true);
            }
            
            $testOutput = $testOutputDir . 'DEBUG_loaded_template.png';
            if (imagepng($image, $testOutput)) {
                echo "✅ Test copy saved to: $testOutput\n";
            } else {
                echo "❌ Failed to save test copy\n";
            }
            
            // Test creating a simple overlay
            echo "\n=== TESTING OVERLAY ===\n";
            $black = imagecolorallocate($image, 0, 0, 0);
            imagestring($image, 5, 50, 50, "TEST OVERLAY", $black);
            
            $overlayTest = $testOutputDir . 'DEBUG_with_overlay.png';
            if (imagepng($image, $overlayTest)) {
                echo "✅ Overlay test saved to: $overlayTest\n";
            } else {
                echo "❌ Failed to save overlay test\n";
            }
            
            imagedestroy($image);
        }
        
    } else {
        echo "\n❌ NO MATCHING TEMPLATES FOUND\n";
    }
    
} else {
    echo "❌ No number found in SKU '$testSku'\n";
}

// Test GD library functions
echo "\n=== GD LIBRARY TEST ===\n";
if (extension_loaded('gd')) {
    echo "✅ GD extension loaded\n";
    $gdInfo = gd_info();
    echo "GD Version: " . $gdInfo['GD Version'] . "\n";
    echo "PNG Support: " . ($gdInfo['PNG Support'] ? 'YES' : 'NO') . "\n";
    echo "JPEG Support: " . ($gdInfo['JPEG Support'] ? 'YES' : 'NO') . "\n";
    
    // Test creating simple image
    $testImage = imagecreatetruecolor(200, 100);
    $white = imagecolorallocate($testImage, 255, 255, 255);
    $black = imagecolorallocate($testImage, 0, 0, 0);
    imagefill($testImage, 0, 0, $white);
    imagestring($testImage, 5, 10, 10, "GD Test", $black);
    
    $gdTest = BASE_PATH . '/storage/label_pngs/GD_test.png';
    if (!is_dir(dirname($gdTest))) {
        mkdir(dirname($gdTest), 0755, true);
    }
    
    if (imagepng($testImage, $gdTest)) {
        echo "✅ GD test image created: $gdTest\n";
    } else {
        echo "❌ Failed to create GD test image\n";
    }
    imagedestroy($testImage);
    
} else {
    echo "❌ GD extension not loaded\n";
}

echo "\n=== DEBUG COMPLETE ===\n";
?>