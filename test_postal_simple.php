<?php
/**
 * Test Multiple Postal Codes from Address Mappings
 * File: test_multiple_postal_codes.php
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/CargusService.php';

echo "🔍 TESTING POSTAL CODES FOR MULTIPLE LOCATIONS\n";
echo str_repeat("=", 60) . "\n";

try {
    $config = require BASE_PATH . '/config/config.php';
    $db = $config['connection_factory']();
    $cargus = new CargusService($db);
    
    // Get sample mappings from database
    $stmt = $db->prepare("
        SELECT county_name, locality_name, cargus_county_id, cargus_locality_id,
               cargus_county_name, cargus_locality_name, usage_count
        FROM address_location_mappings 
        WHERE cargus_county_id IS NOT NULL AND cargus_locality_id IS NOT NULL
        ORDER BY usage_count DESC, county_name, locality_name
        LIMIT 15
    ");
    $stmt->execute();
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($mappings)) {
        echo "❌ No address mappings found in database\n";
        exit;
    }
    
    echo "📊 Testing " . count($mappings) . " locations from your address_location_mappings table:\n\n";
    
    $results = [];
    $successCount = 0;
    $failCount = 0;
    
    foreach ($mappings as $i => $mapping) {
        $countyId = $mapping['cargus_county_id'];
        $localityId = $mapping['cargus_locality_id'];
        $countyName = $mapping['county_name'];
        $localityName = $mapping['locality_name'];
        $usage = $mapping['usage_count'];
        
        echo sprintf("🔍 %d. Testing: %s, %s (County ID: %s, Locality ID: %s, Usage: %d)\n", 
            $i + 1, $countyName, $localityName, $countyId, $localityId, $usage);
        
        // Test postal code fetch
        $postalCode = $cargus->getPostalCode($countyId, $localityId);
        
        if ($postalCode) {
            echo "   ✅ SUCCESS: Postal code = $postalCode\n";
            $successCount++;
            $results[] = [
                'status' => 'success',
                'county' => $countyName,
                'locality' => $localityName,
                'county_id' => $countyId,
                'locality_id' => $localityId,
                'postal_code' => $postalCode,
                'usage' => $usage
            ];
        } else {
            echo "   ❌ FAILED: No postal code found\n";
            $failCount++;
            $results[] = [
                'status' => 'failed',
                'county' => $countyName,
                'locality' => $localityName,
                'county_id' => $countyId,
                'locality_id' => $localityId,
                'postal_code' => null,
                'usage' => $usage
            ];
        }
        
        echo "\n";
        
        // Small delay to avoid overwhelming API
        usleep(200000); // 0.2 seconds
    }
    
    echo str_repeat("=", 60) . "\n";
    echo "📈 SUMMARY:\n";
    echo "Total tested: " . count($mappings) . "\n";
    echo "✅ Successful: $successCount\n";
    echo "❌ Failed: $failCount\n";
    echo "Success rate: " . round(($successCount / count($mappings)) * 100, 1) . "%\n\n";
    
    if ($successCount > 0) {
        echo "✅ LOCATIONS WITH POSTAL CODES:\n";
        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                echo sprintf("   %s, %s -> %s (County: %s, Locality: %s)\n",
                    $result['county'], $result['locality'], $result['postal_code'],
                    $result['county_id'], $result['locality_id']);
            }
        }
        echo "\n";
    }
    
    if ($failCount > 0) {
        echo "❌ LOCATIONS WITHOUT POSTAL CODES:\n";
        foreach ($results as $result) {
            if ($result['status'] === 'failed') {
                echo sprintf("   %s, %s (County: %s, Locality: %s) - Usage: %d\n",
                    $result['county'], $result['locality'], 
                    $result['county_id'], $result['locality_id'], $result['usage']);
            }
        }
        echo "\n";
    }
    
    // Recommendations
    echo "💡 RECOMMENDATIONS:\n";
    
    if ($successCount > 0) {
        echo "1. ✅ Good news: Some locations DO have postal codes in Cargus API\n";
        echo "2. 🔧 Update your address_location_mappings table with found postal codes:\n";
        
        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                echo "   UPDATE address_location_mappings SET cargus_postal_code = '{$result['postal_code']}' WHERE cargus_county_id = {$result['county_id']} AND cargus_locality_id = {$result['locality_id']};\n";
            }
        }
        echo "\n";
        
        echo "3. 🔄 Update existing orders with found postal codes:\n";
        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                echo "   UPDATE orders SET recipient_postal = '{$result['postal_code']}' WHERE recipient_county_id = {$result['county_id']} AND recipient_locality_id = {$result['locality_id']} AND recipient_postal IS NULL;\n";
            }
        }
        echo "\n";
    }
    
    if ($failCount > 0) {
        echo "4. ⚠️ For locations without postal codes:\n";
        echo "   - These may be data gaps in Cargus system\n";
        echo "   - Consider setting default postal codes manually\n";
        echo "   - Check if locality IDs are correct\n\n";
        
        // Group failures by county for easier analysis
        $failuresByCounty = [];
        foreach ($results as $result) {
            if ($result['status'] === 'failed') {
                $failuresByCounty[$result['county']][] = $result;
            }
        }
        
        echo "   Failures by county:\n";
        foreach ($failuresByCounty as $county => $failures) {
            echo "   - $county: " . count($failures) . " locations\n";
        }
    }
    
    if ($successCount === 0) {
        echo "❌ CRITICAL: No postal codes found at all!\n";
        echo "This suggests either:\n";
        echo "1. Authentication issue (but you said it works in product-units.php)\n";
        echo "2. API response structure is different than expected\n";
        echo "3. All your locality IDs are incorrect\n";
        echo "\nRecommendation: Add debug logging to getPostalCode() method\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n✅ Test completed!\n";
?>