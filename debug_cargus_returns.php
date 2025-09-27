<?php
/**
 * Enhanced Debug Script for Cargus Return Data
 * Save this as: /var/www/notsowms.ro/enhanced_debug_cargus.php
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/services/AutomatedReturnProcessor.php';

echo "🔍 ENHANCED CARGUS RETURN DEBUGGING\n";
echo "===================================\n\n";

try {
    // Get database connection
    $config = require BASE_PATH . '/config/config.php';
    $db = ($config['connection_factory'])();
    require_once BASE_PATH . '/models/CargusService.php';
    $cargusService = new CargusService($db);
    
    $date = date('Y-m-d');
    echo "📅 Checking returns for date: {$date}\n\n";
    
    $response = $cargusService->getReturnedAWBs($date);
    
    if (!$response['success']) {
        echo "❌ API Error: " . $response['error'] . "\n";
        exit(1);
    }
    
    $data = $response['data'] ?? [];
    echo "✅ Found " . count($data) . " returned AWBs\n\n";
    
    // Extract serial numbers first
    $serialNumbers = [];
    foreach ($data as $index => $awbData) {
        if (isset($awbData['ClientObservation'])) {
            if (preg_match('/seria (\d+)/', $awbData['ClientObservation'], $matches)) {
                $serialNumbers[] = $matches[1];
                echo "📋 AWB #{$index}: Found serial number {$matches[1]}\n";
            }
        }
    }
    
    echo "\n🔍 CHECKING DATABASE FOR THESE AWB NUMBERS:\n";
    echo "============================================\n";
    
    foreach ($serialNumbers as $awbNumber) {
        $stmt = $db->prepare("SELECT id, order_number, customer_name, status, awb_barcode FROM orders WHERE awb_barcode = ?");
        $stmt->execute([$awbNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            echo "✅ AWB {$awbNumber} FOUND in database:\n";
            echo "   Order ID: {$order['id']}\n";
            echo "   Order Number: {$order['order_number']}\n";
            echo "   Customer: {$order['customer_name']}\n";
            echo "   Status: {$order['status']}\n";
            
            // Check if return already exists
            $returnStmt = $db->prepare("SELECT id, status, auto_created FROM returns WHERE order_id = ?");
            $returnStmt->execute([$order['id']]);
            $existingReturn = $returnStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingReturn) {
                echo "   🔄 Return already exists: ID {$existingReturn['id']}, Status: {$existingReturn['status']}, Auto-created: " . ($existingReturn['auto_created'] ? 'Yes' : 'No') . "\n";
            } else {
                echo "   ✨ No return exists yet - ready for automation!\n";
            }
        } else {
            echo "❌ AWB {$awbNumber} NOT FOUND in database\n";
        }
        echo "\n";
    }
    
    echo "🔍 DETAILED AWB ARRAY STRUCTURE:\n";
    echo "================================\n";
    
    foreach ($data as $index => $awbData) {
        echo "\n📋 RETURNED AWB #{$index} - DETAILED ANALYSIS:\n";
        echo str_repeat("-", 50) . "\n";
        
        // Look specifically in the Awb array for barcode-like fields
        if (isset($awbData['Awb']) && is_array($awbData['Awb'])) {
            echo "🔍 Searching Awb array for barcode fields:\n";
            foreach ($awbData['Awb'] as $key => $value) {
                $keyLower = strtolower($key);
                if (strpos($keyLower, 'code') !== false || 
                    strpos($keyLower, 'barcode') !== false ||
                    strpos($keyLower, 'awb') !== false ||
                    strpos($keyLower, 'number') !== false ||
                    (is_string($value) && preg_match('/^\d{8,12}$/', $value))) {
                    
                    $valueStr = is_array($value) ? '[Array: ' . count($value) . ' items]' : $value;
                    echo "   🎯 {$key}: {$valueStr}\n";
                    
                    // If it's an array, check its contents too
                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if (is_string($subValue) && preg_match('/^\d{8,12}$/', $subValue)) {
                                echo "      📍 {$subKey}: {$subValue}\n";
                            }
                        }
                    }
                }
            }
        }
        
        // Also check AwbReturn array
        if (isset($awbData['AwbReturn']) && is_array($awbData['AwbReturn'])) {
            echo "\n🔍 Searching AwbReturn array for barcode fields:\n";
            foreach ($awbData['AwbReturn'] as $key => $value) {
                $keyLower = strtolower($key);
                if (strpos($keyLower, 'code') !== false || 
                    strpos($keyLower, 'barcode') !== false ||
                    strpos($keyLower, 'awb') !== false ||
                    strpos($keyLower, 'number') !== false ||
                    (is_string($value) && preg_match('/^\d{8,12}$/', $value))) {
                    
                    $valueStr = is_array($value) ? '[Array: ' . count($value) . ' items]' : $value;
                    echo "   🎯 {$key}: {$valueStr}\n";
                    
                    // If it's an array, check its contents too
                    if (is_array($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if (is_string($subValue) && preg_match('/^\d{8,12}$/', $subValue)) {
                                echo "      📍 {$subKey}: {$subValue}\n";
                            }
                        }
                    }
                }
            }
        }
        
        // Show the extracted serial number for comparison
        if (isset($awbData['ClientObservation']) && preg_match('/seria (\d+)/', $awbData['ClientObservation'], $matches)) {
            echo "\n🎯 Extracted from ClientObservation: {$matches[1]}\n";
        }
    }
    
    echo "\n💡 RECOMMENDATIONS:\n";
    echo "===================\n";
    echo "1. The AWB barcodes are clearly visible in ClientObservation field\n";
    echo "2. We should update extractBarcode() to parse ClientObservation\n";
    echo "3. Pattern to extract: /seria (\\d+)/\n";
    echo "4. This will enable automatic return creation for these AWBs\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . "\n";
    echo "📍 Line: " . $e->getLine() . "\n";
}