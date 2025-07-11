<?php
/**
 * FPDF Diagnostic Script
 * Run this to diagnose and fix FPDF issues
 * Save as fpdf_diagnostic.php and run it
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

echo "=== FPDF Diagnostic Script ===\n";
echo "Base Path: " . BASE_PATH . "\n\n";

// Check if vendor/autoload.php exists
$autoloadPath = BASE_PATH . '/vendor/autoload.php';
echo "1. Checking Composer autoload...\n";
echo "   Path: $autoloadPath\n";
if (file_exists($autoloadPath)) {
    echo "   ✅ EXISTS\n";
    require_once $autoloadPath;
    echo "   ✅ LOADED\n";
} else {
    echo "   ❌ NOT FOUND\n";
    echo "   Run: composer install\n";
}

// Check FPDF class availability
echo "\n2. Checking FPDF class availability...\n";
if (class_exists('FPDF')) {
    echo "   ✅ FPDF class is available\n";
} else {
    echo "   ❌ FPDF class not found\n";
    
    // Try to find FPDF files
    echo "\n3. Searching for FPDF files...\n";
    
    $possiblePaths = [
        BASE_PATH . '/vendor/setasign/fpdf/fpdf.php',
        BASE_PATH . '/vendor/fpdf/fpdf/fpdf.php', 
        BASE_PATH . '/lib/fpdf.php',
        BASE_PATH . '/fpdf.php'
    ];
    
    foreach ($possiblePaths as $path) {
        echo "   Checking: $path\n";
        if (file_exists($path)) {
            echo "   ✅ FOUND: $path\n";
            echo "   Attempting to include...\n";
            require_once $path;
            if (class_exists('FPDF')) {
                echo "   ✅ FPDF class now available!\n";
                break;
            } else {
                echo "   ❌ File exists but class not loaded\n";
            }
        } else {
            echo "   ❌ NOT FOUND\n";
        }
    }
    
    // Search entire vendor directory
    if (!class_exists('FPDF')) {
        echo "\n4. Searching entire vendor directory...\n";
        $fpdfFiles = [];
        if (is_dir(BASE_PATH . '/vendor')) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(BASE_PATH . '/vendor')
            );
            foreach ($iterator as $file) {
                if ($file->getFilename() === 'fpdf.php') {
                    $fpdfFiles[] = $file->getPathname();
                }
            }
        }
        
        if (empty($fpdfFiles)) {
            echo "   ❌ No fpdf.php files found in vendor directory\n";
            echo "   Solution: Run 'composer require setasign/fpdf'\n";
        } else {
            echo "   Found FPDF files:\n";
            foreach ($fpdfFiles as $file) {
                echo "   - $file\n";
            }
            
            // Try to include the first one
            echo "   Attempting to include: " . $fpdfFiles[0] . "\n";
            require_once $fpdfFiles[0];
            if (class_exists('FPDF')) {
                echo "   ✅ FPDF class now available!\n";
            }
        }
    }
}

// Test PDF generation if FPDF is available
if (class_exists('FPDF')) {
    echo "\n5. Testing PDF generation...\n";
    try {
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, 'Test PDF');
        
        $testDir = BASE_PATH . '/storage/purchase_order_pdfs/';
        if (!is_dir($testDir)) {
            mkdir($testDir, 0755, true);
        }
        
        $testFile = $testDir . 'test_' . time() . '.pdf';
        $pdf->Output('F', $testFile);
        
        if (file_exists($testFile)) {
            echo "   ✅ PDF generation successful: $testFile\n";
            unlink($testFile); // Clean up test file
        } else {
            echo "   ❌ PDF file was not created\n";
        }
    } catch (Exception $e) {
        echo "   ❌ PDF generation failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n❌ FPDF class still not available\n";
    echo "\nSolutions:\n";
    echo "1. Run: composer install\n";
    echo "2. Run: composer require setasign/fpdf\n";
    echo "3. Download FPDF manually and place in lib/fpdf.php\n";
}

// Check directory permissions
echo "\n6. Checking storage directory...\n";
$storageDir = BASE_PATH . '/storage/purchase_order_pdfs/';
echo "   Directory: $storageDir\n";

if (!is_dir($storageDir)) {
    echo "   ❌ Directory does not exist\n";
    echo "   Creating directory...\n";
    if (mkdir($storageDir, 0755, true)) {
        echo "   ✅ Directory created\n";
    } else {
        echo "   ❌ Failed to create directory\n";
    }
} else {
    echo "   ✅ Directory exists\n";
}

if (is_dir($storageDir)) {
    if (is_writable($storageDir)) {
        echo "   ✅ Directory is writable\n";
    } else {
        echo "   ❌ Directory is not writable\n";
        echo "   Attempting to fix permissions...\n";
        if (chmod($storageDir, 0755)) {
            echo "   ✅ Permissions fixed\n";
        } else {
            echo "   ❌ Could not fix permissions - contact hosting provider\n";
        }
    }
}

echo "\n=== Summary ===\n";
echo "FPDF Available: " . (class_exists('FPDF') ? '✅ YES' : '❌ NO') . "\n";
echo "Storage Ready: " . (is_dir($storageDir) && is_writable($storageDir) ? '✅ YES' : '❌ NO') . "\n";

if (class_exists('FPDF') && is_dir($storageDir) && is_writable($storageDir)) {
    echo "\n🎉 Everything looks good! Your email system should work now.\n";
} else {
    echo "\n⚠️  Issues found. Please address the problems above.\n";
}

?>