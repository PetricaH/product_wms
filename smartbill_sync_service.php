<?php
/**
 * SmartBill Sync Service
 * Handles scheduled synchronization between SmartBill and WMS
 * Run this via cron job every few minutes
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
require_once BASE_PATH . '/models/SmartBillService.php';
require_once BASE_PATH . '/models/Order.php';

class SmartBillSyncService {
    private $conn;
    private $smartBillService;
    private $orderModel;
    private $debugMode;
    
    public function __construct($db) {
        $this->conn = $db;
        $this->smartBillService = new SmartBillService($db);
        $this->orderModel = new Order($db);
        $this->debugMode = (bool)$this->smartBillService->getConfig('debug_mode', false);
    }
    
    /**
     * Run all scheduled sync jobs
     * @return array Sync results
     */
    public function runScheduledSyncs(): array {
        $results = [
            'total_jobs' => 0,
            'successful_jobs' => 0,
            'failed_jobs' => 0,
            'job_results' => [],
            'start_time' => microtime(true)
        ];
        
        try {
            // Get scheduled jobs
            $scheduledJobs = $this->getScheduledJobs();
            $results['total_jobs'] = count($scheduledJobs);
            
            if ($this->debugMode) {
                error_log("SmartBill Sync: Found " . count($scheduledJobs) . " scheduled jobs");
            }
            
            foreach ($scheduledJobs as $job) {
                $jobStartTime = microtime(true);
                $jobResult = null;
                
                try {
                    switch ($job['sync_type']) {
                        case 'invoice_pull':
                            $jobResult = $this->syncInvoicesToOrders($job['max_items_per_run']);
                            break;
                            
                        case 'order_push':
                            $jobResult = $this->syncOrderStatusesToSmartBill($job['max_items_per_run']);
                            break;
                            
                        case 'inventory_update':
                            $jobResult = $this->syncInventoryToSmartBill($job['max_items_per_run']);
                            break;
                            
                        case 'product_sync':
                            $jobResult = $this->syncProductsToSmartBill($job['max_items_per_run']);
                            break;
                            
                        default:
                            throw new Exception("Unknown sync type: " . $job['sync_type']);
                    }
                    
                    $executionTime = round((microtime(true) - $jobStartTime) * 1000, 3);
                    
                    // Update schedule
                    $this->updateSyncSchedule(
                        $job['sync_type'],
                        $jobResult['success'] ? 'success' : 'failed',
                        $jobResult['processed'] ?? 0,
                        count($jobResult['errors'] ?? []),
                        $executionTime,
                        json_encode($jobResult)
                    );
                    
                    if ($jobResult['success']) {
                        $results['successful_jobs']++;
                    } else {
                        $results['failed_jobs']++;
                    }
                    
                    $results['job_results'][] = [
                        'sync_type' => $job['sync_type'],
                        'success' => $jobResult['success'],
                        'processed' => $jobResult['processed'] ?? 0,
                        'errors' => count($jobResult['errors'] ?? []),
                        'execution_time' => $executionTime,
                        'message' => $jobResult['message'] ?? ''
                    ];
                    
                } catch (Exception $e) {
                    $executionTime = round((microtime(true) - $jobStartTime) * 1000, 3);
                    
                    // Log error
                    error_log("SmartBill Sync Error for {$job['sync_type']}: " . $e->getMessage());
                    
                    $this->updateSyncSchedule(
                        $job['sync_type'],
                        'failed',
                        0,
                        1,
                        $executionTime,
                        json_encode(['error' => $e->getMessage()])
                    );
                    
                    $results['failed_jobs']++;
                    $results['job_results'][] = [
                        'sync_type' => $job['sync_type'],
                        'success' => false,
                        'processed' => 0,
                        'errors' => 1,
                        'execution_time' => $executionTime,
                        'message' => $e->getMessage()
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("SmartBill Sync Service Error: " . $e->getMessage());
            $results['global_error'] = $e->getMessage();
        }
        
        $results['total_execution_time'] = round((microtime(true) - $results['start_time']) * 1000, 3);
        
        if ($this->debugMode) {
            error_log("SmartBill Sync Complete: " . json_encode($results, JSON_PRETTY_PRINT));
        }
        
        return $results;
    }
    
    /**
     * Sync invoices from SmartBill to create orders
     * @param int $maxInvoices Maximum number of invoices to process
     * @return array Sync results
     */
    public function syncInvoicesToOrders(int $maxInvoices = 50): array {
        return $this->smartBillService->syncInvoicesToOrders($maxInvoices);
    }
    
    /**
     * Sync order statuses back to SmartBill
     * @param int $maxOrders Maximum number of orders to process
     * @return array Sync results
     */
    public function syncOrderStatusesToSmartBill(int $maxOrders = 10): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'updated_orders' => [],
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // Get shipped orders that need status update in SmartBill
            $orders = $this->getOrdersForStatusSync($maxOrders);
            
            foreach ($orders as $order) {
                try {
                    // Here you would implement the logic to update SmartBill
                    // For now, we'll just mark as processed in our system
                    
                    if ($order['status'] === Order::STATUS_SHIPPED) {
                        // Update SmartBill that order was shipped
                        // $this->smartBillService->updateInvoiceStatus($order['smartbill_series'], $order['smartbill_number'], 'shipped');
                        
                        // For now, just log it
                        if ($this->debugMode) {
                            error_log("Would update SmartBill invoice {$order['smartbill_series']}-{$order['smartbill_number']} to shipped status");
                        }
                    }
                    
                    $results['updated_orders'][] = [
                        'order_id' => $order['id'],
                        'order_number' => $order['order_number'],
                        'smartbill_ref' => $order['smartbill_series'] . '-' . $order['smartbill_number']
                    ];
                    $results['processed']++;
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Error updating order {$order['id']}: " . $e->getMessage();
                }
            }
            
            $results['message'] = "Processed {$results['processed']} order status updates";
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Order status sync failed: ' . $e->getMessage();
            error_log("SmartBill order status sync error: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Sync inventory changes to SmartBill
     * @param int $maxItems Maximum number of inventory changes to process
     * @return array Sync results
     */
    public function syncInventoryToSmartBill(int $maxItems = 50): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'updated_items' => [],
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // Get recent inventory changes that need to be synced
            $inventoryChanges = $this->getInventoryChangesForSync($maxItems);
            
            foreach ($inventoryChanges as $change) {
                try {
                    // Update SmartBill inventory
                    $updateResult = $this->smartBillService->updateInventory([
                        'warehouse_code' => $change['warehouse_code'] ?? 'PRINCIPAL',
                        'items' => [[
                            'sku' => $change['sku'],
                            'quantity' => $change['new_quantity'],
                            'unit_price' => $change['unit_price'] ?? 0
                        ]]
                    ]);
                    
                    if ($updateResult['success']) {
                        // Mark as synced
                        $this->markInventoryChangeSynced($change['id']);
                        
                        $results['updated_items'][] = [
                            'sku' => $change['sku'],
                            'quantity' => $change['new_quantity']
                        ];
                        $results['processed']++;
                    } else {
                        $results['errors'][] = "Failed to update inventory for SKU {$change['sku']}: " . $updateResult['message'];
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Error updating inventory for SKU {$change['sku']}: " . $e->getMessage();
                }
            }
            
            $results['message'] = "Processed {$results['processed']} inventory updates";
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Inventory sync failed: ' . $e->getMessage();
            error_log("SmartBill inventory sync error: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Sync products to SmartBill
     * @param int $maxProducts Maximum number of products to process
     * @return array Sync results
     */
    public function syncProductsToSmartBill(int $maxProducts = 25): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'updated_products' => [],
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // Get products that need to be synced
            $products = $this->getProductsForSync($maxProducts);
            
            foreach ($products as $product) {
                try {
                    $syncResult = $this->smartBillService->syncProduct([
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'price' => $product['price'] ?? 0,
                        'unit_of_measure' => $product['unit_of_measure'] ?? 'buc',
                        'tax_percent' => $product['tax_percent'] ?? 19,
                        'currency' => 'RON',
                        'warehouse_code' => 'PRINCIPAL'
                    ]);
                    
                    if ($syncResult['success']) {
                        // Mark as synced
                        $this->markProductSynced($product['product_id']);
                        
                        $results['updated_products'][] = [
                            'product_id' => $product['product_id'],
                            'sku' => $product['sku'],
                            'name' => $product['name']
                        ];
                        $results['processed']++;
                    } else {
                        $results['errors'][] = "Failed to sync product {$product['sku']}: " . $syncResult['message'];
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = "Error syncing product {$product['sku']}: " . $e->getMessage();
                }
            }
            
            $results['message'] = "Processed {$results['processed']} product syncs";
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Product sync failed: ' . $e->getMessage();
            error_log("SmartBill product sync error: " . $e->getMessage());
        }
        
        return $results;
    }
    
    /**
     * Get scheduled sync jobs that are due to run
     * @return array Scheduled jobs
     */
    private function getScheduledJobs(): array {
        try {
            $stmt = $this->conn->prepare("CALL sp_get_scheduled_sync_jobs()");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting scheduled jobs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update sync schedule after job completion
     */
    private function updateSyncSchedule(string $syncType, string $status, int $processedCount, int $errorCount, float $executionTime, string $details): void {
        try {
            $stmt = $this->conn->prepare("CALL sp_update_sync_schedule(?, ?, ?, ?, ?, ?)");
            $stmt->execute([$syncType, $status, $processedCount, $errorCount, $executionTime, $details]);
        } catch (PDOException $e) {
            error_log("Error updating sync schedule: " . $e->getMessage());
        }
    }
    
    /**
     * Get orders that need status sync to SmartBill
     * @param int $limit Maximum number of orders
     * @return array Orders to sync
     */
    private function getOrdersForStatusSync(int $limit = 10): array {
        try {
            $query = "SELECT o.id, o.order_number, o.status, o.smartbill_series, o.smartbill_number
                     FROM orders o
                     WHERE o.smartbill_series IS NOT NULL 
                       AND o.smartbill_number IS NOT NULL
                       AND o.status IN (?, ?)
                       AND (o.smartbill_status_synced_at IS NULL OR o.smartbill_status_synced_at < o.updated_at)
                     ORDER BY o.updated_at ASC
                     LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting orders for status sync: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get inventory changes that need to be synced to SmartBill
     * @param int $limit Maximum number of changes
     * @return array Inventory changes to sync
     */
    private function getInventoryChangesForSync(int $limit = 50): array {
        try {
            // This would depend on your inventory change tracking implementation
            // For now, return empty array
            return [];
        } catch (PDOException $e) {
            error_log("Error getting inventory changes for sync: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get products that need to be synced to SmartBill
     * @param int $limit Maximum number of products
     * @return array Products to sync
     */
    private function getProductsForSync(int $limit = 25): array {
        try {
            $query = "SELECT product_id, sku, name, price, unit_of_measure, tax_percent
                     FROM products 
                     WHERE (smartbill_synced_at IS NULL OR smartbill_synced_at < updated_at)
                       AND sku IS NOT NULL 
                       AND sku != ''
                     ORDER BY updated_at ASC
                     LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting products for sync: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark inventory change as synced
     * @param int $changeId Inventory change ID
     */
    private function markInventoryChangeSynced(int $changeId): void {
        try {
            $query = "UPDATE inventory_changes SET smartbill_synced_at = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$changeId]);
        } catch (PDOException $e) {
            error_log("Error marking inventory change as synced: " . $e->getMessage());
        }
    }
    
    /**
     * Mark product as synced
     * @param int $productId Product ID
     */
    private function markProductSynced(int $productId): void {
        try {
            $query = "UPDATE products SET smartbill_synced_at = NOW() WHERE product_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$productId]);
        } catch (PDOException $e) {
            error_log("Error marking product as synced: " . $e->getMessage());
        }
    }
    
    /**
     * Get sync status and statistics
     * @return array Sync status information
     */
    public function getSyncStatus(): array {
        try {
            // Get recent sync performance
            $stmt = $this->conn->prepare("SELECT * FROM view_smartbill_sync_metrics");
            $stmt->execute();
            $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get pending invoices
            $stmt = $this->conn->prepare("SELECT COUNT(*) as pending_count FROM view_smartbill_pending_invoices");
            $stmt->execute();
            $pendingCount = $stmt->fetchColumn();
            
            // Get next scheduled runs
            $stmt = $this->conn->prepare("SELECT sync_type, next_run, is_enabled FROM smartbill_sync_schedule ORDER BY next_run ASC");
            $stmt->execute();
            $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'metrics' => $metrics,
                'pending_invoices' => $pendingCount,
                'schedule' => $schedule,
                'last_check' => date('Y-m-d H:i:s')
            ];
            
        } catch (PDOException $e) {
            error_log("Error getting sync status: " . $e->getMessage());
            return [
                'error' => $e->getMessage(),
                'last_check' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Manual sync trigger for testing
     * @param string $syncType Type of sync to run
     * @return array Sync results
     */
    public function manualSync(string $syncType): array {
        switch ($syncType) {
            case 'invoice_pull':
                return $this->syncInvoicesToOrders(10);
                
            case 'order_push':
                return $this->syncOrderStatusesToSmartBill(5);
                
            case 'inventory_update':
                return $this->syncInventoryToSmartBill(25);
                
            case 'product_sync':
                return $this->syncProductsToSmartBill(10);
                
            default:
                return [
                    'success' => false,
                    'message' => 'Unknown sync type: ' . $syncType
                ];
        }
    }
}

// ========================================================================
// Command line interface for running sync
// ========================================================================

// Check if running from command line
if (php_sapi_name() === 'cli' && isset($argv)) {
    try {
        // Get database connection
        $config = require BASE_PATH . '/config/config.php';
        if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
            die("Database connection factory not configured correctly.\n");
        }
        $dbFactory = $config['connection_factory'];
        $db = $dbFactory();
        
        $syncService = new SmartBillSyncService($db);
        
        // Parse command line arguments
        $command = $argv[1] ?? 'run';
        
        switch ($command) {
            case 'run':
                echo "Running scheduled SmartBill sync...\n";
                $results = $syncService->runScheduledSyncs();
                echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
                break;
                
            case 'status':
                echo "Getting SmartBill sync status...\n";
                $status = $syncService->getSyncStatus();
                echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
                break;
                
            case 'manual':
                $syncType = $argv[2] ?? 'invoice_pull';
                echo "Running manual sync for: {$syncType}\n";
                $results = $syncService->manualSync($syncType);
                echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
                break;
                
            case 'help':
            default:
                echo "SmartBill Sync Service\n";
                echo "Usage: php smartbill_sync_service.php [command] [options]\n\n";
                echo "Commands:\n";
                echo "  run                    Run all scheduled sync jobs\n";
                echo "  status                 Show sync status and metrics\n";
                echo "  manual [sync_type]     Run manual sync (invoice_pull, order_push, inventory_update, product_sync)\n";
                echo "  help                   Show this help message\n\n";
                echo "Examples:\n";
                echo "  php smartbill_sync_service.php run\n";
                echo "  php smartbill_sync_service.php manual invoice_pull\n";
                echo "  php smartbill_sync_service.php status\n";
                break;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>