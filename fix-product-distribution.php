<?php
/**
 * Fix the mismatch between per-level subdivision numbering (location_subdivisions) 
 * and global subdivision numbering (inventory)
 */

require_once 'bootstrap.php';

$config = require 'config/config.php';
$db = $config['connection_factory']();

try {
    $db->beginTransaction();
    
    echo "🔍 Analyzing numbering system mismatch...\n\n";
    
    // First, let's understand the current mismatch
    $analysisStmt = $db->prepare("
        SELECT 
            i.location_id,
            i.product_id,
            i.subdivision_number,
            ls.level_number,
            ls.subdivision_number as config_subdivision,
            CASE 
                WHEN ls.dedicated_product_id = i.product_id THEN 'MATCH' 
                ELSE 'MISMATCH' 
            END as product_match
        FROM inventory i
        LEFT JOIN location_subdivisions ls ON i.location_id = ls.location_id 
            AND i.product_id = ls.dedicated_product_id
        WHERE i.location_id IN (34, 35, 36, 37, 38)
        AND i.subdivision_number IS NOT NULL
        ORDER BY i.location_id, i.product_id
    ");
    $analysisStmt->execute();
    $analysis = $analysisStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📊 Product-to-level matching analysis:\n";
    foreach ($analysis as $row) {
        $status = $row['product_match'] === 'MATCH' ? '✅' : '❌';
        $levelInfo = $row['level_number'] ? "Level {$row['level_number']}" : "No assignment";
        echo "  {$status} Location {$row['location_id']}, Product {$row['product_id']}: Inventory Sub {$row['subdivision_number']} → {$levelInfo}\n";
    }
    echo "\n";
    
    // Method: Match inventory products to their designated levels
    echo "🎯 Fixing subdivision assignments by product matching...\n\n";
    
    $locations = [34, 35, 36, 37, 38];
    $totalUpdated = 0;
    
    foreach ($locations as $locationId) {
        echo "Processing Location $locationId...\n";
        
        // Get location configuration: which products belong to which levels
        $subdivisionStmt = $db->prepare("
            SELECT 
                level_number,
                subdivision_number,
                dedicated_product_id
            FROM location_subdivisions
            WHERE location_id = ?
            AND dedicated_product_id IS NOT NULL
            ORDER BY level_number, subdivision_number
        ");
        $subdivisionStmt->execute([$locationId]);
        $locationConfig = $subdivisionStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create product-to-level mapping
        $productToLevel = [];
        foreach ($locationConfig as $config) {
            $productToLevel[$config['dedicated_product_id']] = [
                'level_number' => $config['level_number'],
                'level_name' => "Nivel {$config['level_number']}"
            ];
        }
        
        echo "  📋 Product assignments:\n";
        foreach ($locationConfig as $config) {
            echo "    Product {$config['dedicated_product_id']} → Level {$config['level_number']}\n";
        }
        
        // Get inventory items that need fixing
        $inventoryStmt = $db->prepare("
            SELECT 
                i.id,
                i.product_id,
                i.subdivision_number,
                i.shelf_level,
                i.quantity,
                p.name as product_name
            FROM inventory i
            JOIN products p ON i.product_id = p.product_id
            WHERE i.location_id = ?
            AND i.shelf_level = 'middle'
            AND i.subdivision_number IS NOT NULL
            ORDER BY i.product_id
        ");
        $inventoryStmt->execute([$locationId]);
        $inventoryItems = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "  🔧 Updating inventory items:\n";
        $locationUpdated = 0;
        
        foreach ($inventoryItems as $item) {
            $productId = $item['product_id'];
            
            if (isset($productToLevel[$productId])) {
                $levelInfo = $productToLevel[$productId];
                $newLevelName = $levelInfo['level_name'];
                
                // Update the inventory item
                $updateStmt = $db->prepare("
                    UPDATE inventory 
                    SET shelf_level = ?
                    WHERE id = ?
                ");
                $result = $updateStmt->execute([$newLevelName, $item['id']]);
                
                if ($result) {
                    echo "    ✅ {$item['product_name']} (ID: {$productId}): middle → {$newLevelName}\n";
                    $locationUpdated++;
                    $totalUpdated++;
                } else {
                    echo "    ❌ Failed to update item {$item['id']}\n";
                }
            } else {
                echo "    ⚠️  {$item['product_name']} (ID: {$productId}): No level assignment found\n";
            }
        }
        
        echo "  📈 Updated {$locationUpdated} items for location {$locationId}\n\n";
    }
    
    $db->commit();
    echo "🎉 Successfully updated {$totalUpdated} inventory items based on product-to-level matching!\n\n";
    
    // Show results
    echo "📊 Final distribution:\n";
    $finalStmt = $db->query("
        SELECT 
            l.location_code,
            i.shelf_level,
            COUNT(*) as item_count,
            SUM(i.quantity) as total_quantity,
            GROUP_CONCAT(DISTINCT i.product_id ORDER BY i.product_id) as products
        FROM inventory i
        JOIN locations l ON i.location_id = l.id
        WHERE i.location_id IN (34, 35, 36, 37, 38)
        GROUP BY l.location_code, i.shelf_level
        ORDER BY l.location_code, 
            CASE 
                WHEN i.shelf_level LIKE '%1' THEN 1 
                WHEN i.shelf_level LIKE '%2' THEN 2 
                WHEN i.shelf_level LIKE '%3' THEN 3 
                ELSE 9 
            END
    ");
    
    while ($row = $finalStmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['location_code']}: {$row['shelf_level']} = {$row['item_count']} items ({$row['total_quantity']} qty) [Products: {$row['products']}]\n";
    }
    
} catch (Exception $e) {
    $db->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>