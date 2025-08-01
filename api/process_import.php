<?php
// File: /api/process_import.php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Metodă nepermisă.']);
    exit;
}

// Define Base Path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

// Bootstrap and Config
require_once BASE_PATH . '/bootstrap.php';
$config = require BASE_PATH . '/config/config.php';

// Database Connection
if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Eroare configurare bază de date.']);
    exit;
}

$dbFactory = $config['connection_factory'];
$db = $dbFactory();

// Get import_id parameter
$importId = $_GET['import_id'] ?? null;

if (!$importId || !is_numeric($importId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'import_id este necesar și trebuie să fie numeric.']);
    exit;
}

try {
    $db->beginTransaction();

    // Get the import record
    $importQuery = "
        SELECT * FROM order_imports 
        WHERE id = :import_id AND processing_status = 'pending'
    ";
    $importStmt = $db->prepare($importQuery);
    $importStmt->bindParam(':import_id', $importId, PDO::PARAM_INT);
    $importStmt->execute();
    
    $import = $importStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$import) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Import nu a fost găsit sau este deja procesat.']);
        exit;
    }

    // Update status to processing
    $updateStatusQuery = "UPDATE order_imports SET processing_status = 'processing', conversion_attempts = conversion_attempts + 1, last_attempt_at = CURRENT_TIMESTAMP WHERE id = :import_id";
    $updateStatusStmt = $db->prepare($updateStatusQuery);
    $updateStatusStmt->bindParam(':import_id', $importId, PDO::PARAM_INT);
    $updateStatusStmt->execute();

    // Parse JSON data
    $jsonData = json_decode($import['json_data'], true);
    if (!$jsonData) {
        throw new Exception('Date JSON invalide în import.');
    }

    // Generate unique order number
    $orderNumber = generateOrderNumber($db);
    
    // Determine priority based on invoice value or customer
    $priority = determinePriority($import['total_value'], $import['contact_person_name']);
    
    // Create the main order
    $orderQuery = "
        INSERT INTO orders (
            order_number, customer_name, customer_email, 
            shipping_address, order_date, status, priority, 
            total_value, notes, source, created_by, type
        ) VALUES (
            :order_number, :customer_name, :customer_email,
            :shipping_address, CURRENT_TIMESTAMP, 'pending', :priority,
            :total_value, :notes, 'email', 1, 'outbound'
        )
    ";
    
    $orderStmt = $db->prepare($orderQuery);
    $orderStmt->execute([
        ':order_number' => $orderNumber,
        ':customer_name' => $import['contact_person_name'] . ($import['company_name'] ? ' (' . $import['company_name'] . ')' : ''),
        ':customer_email' => $import['contact_email'],
        ':shipping_address' => buildShippingAddress($import),
        ':priority' => $priority,
        ':total_value' => $import['total_value'],
        ':notes' => 'Import automat din factură: ' . $import['invoice_number']
    ]);
    
    $orderId = $db->lastInsertId();

    // Process items from JSON data
    $itemsProcessed = 0;
    $itemsSkipped = 0;
    $processingErrors = [];

    if (isset($jsonData['items']) && is_array($jsonData['items'])) {
        foreach ($jsonData['items'] as $item) {
            try {
                // Extract item data
                $externalProductName = $item['name'] ?? $item['product_name'] ?? '';
                $quantity = floatval($item['quantity'] ?? $item['qty'] ?? 0);
                $unitPrice = floatval($item['unit_price'] ?? $item['price'] ?? 0);

                if (empty($externalProductName) || $quantity <= 0) {
                    $itemsSkipped++;
                    continue;
                }

                // Try to map external product to internal product
                $productMapping = mapExternalProduct($db, $externalProductName);
                
                if ($productMapping) {
                    // Create order item
                    $itemQuery = "
                        INSERT INTO order_items (
                            order_id, product_id, quantity_ordered, 
                            unit_price, picked_quantity, notes
                        ) VALUES (
                            :order_id, :product_id, :quantity, 
                            :unit_price, 0, :notes
                        )
                    ";
                    
                    $itemStmt = $db->prepare($itemQuery);
                    $itemStmt->execute([
                        ':order_id' => $orderId,
                        ':product_id' => $productMapping['wms_product_id'],
                        ':quantity' => $quantity,
                        ':unit_price' => $unitPrice,
                        ':notes' => 'Import: ' . $externalProductName
                    ]);
                    
                    $itemsProcessed++;
                } else {
                    // Create unmapped product record for manual mapping later
                    createUnmappedProduct($db, $externalProductName, $orderId, $quantity, $unitPrice);
                    $itemsSkipped++;
                    $processingErrors[] = "Produs nemapat: {$externalProductName}";
                }
                
            } catch (Exception $e) {
                $itemsSkipped++;
                $processingErrors[] = "Eroare proces articol '{$externalProductName}': " . $e->getMessage();
            }
        }
    }

    // Update import record with conversion results
    if ($itemsProcessed > 0) {
        $finalStatus = ($itemsSkipped > 0) ? 'converted' : 'converted'; // Could be 'partial' if you want to distinguish
        $conversionErrors = empty($processingErrors) ? null : implode('; ', $processingErrors);
        
        $updateImportQuery = "
            UPDATE order_imports SET 
                processing_status = :status,
                wms_order_id = :order_id,
                wms_order_number = :order_number,
                conversion_errors = :errors,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :import_id
        ";
        
        $updateImportStmt = $db->prepare($updateImportQuery);
        $updateImportStmt->execute([
            ':status' => $finalStatus,
            ':order_id' => $orderId,
            ':order_number' => $orderNumber,
            ':errors' => $conversionErrors,
            ':import_id' => $importId
        ]);
        
        $db->commit();
        
        // Return success response
        echo json_encode([
            'status' => 'success',
            'message' => 'Import procesat cu succes.',
            'data' => [
                'import_id' => $importId,
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'items_processed' => $itemsProcessed,
                'items_skipped' => $itemsSkipped,
                'errors' => $processingErrors,
                'customer_name' => $import['contact_person_name']
            ]
        ]);
        
    } else {
        // No items could be processed
        $updateImportQuery = "
            UPDATE order_imports SET 
                processing_status = 'failed',
                conversion_errors = :errors,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :import_id
        ";
        
        $updateImportStmt = $db->prepare($updateImportQuery);
        $updateImportStmt->execute([
            ':errors' => 'Niciun articol nu a putut fi procesat: ' . implode('; ', $processingErrors),
            ':import_id' => $importId
        ]);
        
        // Delete the empty order
        $deleteOrderQuery = "DELETE FROM orders WHERE id = :order_id";
        $deleteOrderStmt = $db->prepare($deleteOrderQuery);
        $deleteOrderStmt->execute([':order_id' => $orderId]);
        
        $db->rollback();
        
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Nu s-a putut procesa niciun articol din import.',
            'errors' => $processingErrors
        ]);
    }

} catch (Exception $e) {
    $db->rollback();
    
    // Update import record with error
    try {
        $errorUpdateQuery = "
            UPDATE order_imports SET 
                processing_status = 'failed',
                conversion_errors = :error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :import_id
        ";
        $errorUpdateStmt = $db->prepare($errorUpdateQuery);
        $errorUpdateStmt->execute([
            ':error' => $e->getMessage(),
            ':import_id' => $importId
        ]);
    } catch (Exception $updateError) {
        error_log("Could not update import error status: " . $updateError->getMessage());
    }
    
    error_log("Error processing import {$importId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Eroare server: ' . $e->getMessage()
    ]);
}

/**
 * Generate unique order number
 */
function generateOrderNumber($db) {
    $prefix = 'WMS-' . date('Y') . '-';
    
    // Get the last order number for this year
    $query = "SELECT order_number FROM orders WHERE order_number LIKE :prefix ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([':prefix' => $prefix . '%']);
    $lastOrder = $stmt->fetchColumn();
    
    if ($lastOrder) {
        // Extract number and increment
        $lastNumber = intval(substr($lastOrder, strlen($prefix)));
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    return $prefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
}

/**
 * Determine order priority based on value and customer
 */
function determinePriority($totalValue, $customerName) {
    // High value orders
    if ($totalValue > 5000) {
        return 'high';
    }
    
    // VIP customers (you can customize this logic)
    $vipKeywords = ['urgent', 'express', 'priority', 'vip'];
    $customerLower = strtolower($customerName);
    
    foreach ($vipKeywords as $keyword) {
        if (strpos($customerLower, $keyword) !== false) {
            return 'high';
        }
    }
    
    return 'normal';
}

/**
 * Build shipping address from import data
 */
function buildShippingAddress($import) {
    $parts = array_filter([
        $import['delivery_street'],
        $import['delivery_locality'],
        $import['delivery_county'],
        $import['delivery_postal_code']
    ]);
    
    return implode(', ', $parts);
}

/**
 * Map external product name to internal WMS product
 */
function mapExternalProduct($db, $externalProductName) {
    // Normalize the product name
    $normalizedName = normalizeProductName($externalProductName);
    
    // First, try exact match
    $query = "
        SELECT wms_product_id, wms_sku, mapping_confidence 
        FROM import_product_mappings 
        WHERE external_product_name_normalized = :normalized_name 
        AND is_active = TRUE 
        ORDER BY mapping_confidence DESC, usage_count DESC 
        LIMIT 1
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':normalized_name' => $normalizedName]);
    $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($mapping) {
        // Update usage statistics
        $updateQuery = "UPDATE import_product_mappings SET usage_count = usage_count + 1, last_used_at = CURRENT_TIMESTAMP WHERE wms_product_id = :product_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([':product_id' => $mapping['wms_product_id']]);
        
        return $mapping;
    }
    
    // Try fuzzy matching with existing products
    $fuzzyQuery = "
        SELECT product_id as wms_product_id, sku as wms_sku, name
        FROM products 
        WHERE LOWER(name) LIKE :search_pattern 
        OR sku LIKE :search_pattern
        LIMIT 1
    ";
    
    $searchPattern = '%' . strtolower(substr($normalizedName, 0, 10)) . '%';
    $fuzzyStmt = $db->prepare($fuzzyQuery);
    $fuzzyStmt->execute([':search_pattern' => $searchPattern]);
    $fuzzyMatch = $fuzzyStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fuzzyMatch) {
        // Create new mapping with low confidence
        $insertMappingQuery = "
            INSERT INTO import_product_mappings (
                external_product_name, external_product_name_normalized, 
                wms_product_id, wms_sku, mapping_confidence, usage_count
            ) VALUES (
                :external_name, :normalized_name, :product_id, :sku, 'low', 1
            )
        ";
        
        try {
            $insertMappingStmt = $db->prepare($insertMappingQuery);
            $insertMappingStmt->execute([
                ':external_name' => $externalProductName,
                ':normalized_name' => $normalizedName,
                ':product_id' => $fuzzyMatch['wms_product_id'],
                ':sku' => $fuzzyMatch['wms_sku']
            ]);
        } catch (Exception $e) {
            // Mapping might already exist - continue
        }
        
        return $fuzzyMatch;
    }
    
    return null; // No mapping found
}

/**
 * Normalize product name for matching
 */
function normalizeProductName($name) {
    // Remove special characters, convert to lowercase, trim spaces
    $normalized = strtolower(trim($name));
    $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    
    return $normalized;
}

/**
 * Create unmapped product record for manual processing
 */
function createUnmappedProduct($db, $externalProductName, $orderId, $quantity, $unitPrice) {
    $normalizedName = normalizeProductName($externalProductName);
    
    // Check if mapping already exists
    $checkQuery = "SELECT id FROM import_product_mappings WHERE external_product_name_normalized = :normalized_name";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([':normalized_name' => $normalizedName]);
    
    if (!$checkStmt->fetchColumn()) {
        // Create new unmapped product record
        $insertQuery = "
            INSERT INTO import_product_mappings (
                external_product_name, external_product_name_normalized,
                estimated_price, mapping_confidence, is_active
            ) VALUES (
                :external_name, :normalized_name, :price, 'low', FALSE
            )
        ";
        
        try {
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                ':external_name' => $externalProductName,
                ':normalized_name' => $normalizedName,
                ':price' => $unitPrice
            ]);
        } catch (Exception $e) {
            // Might already exist - continue
        }
    }
}
?>