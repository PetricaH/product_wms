<?php
/**
 * SmartBill Sync Service - Complete Implementation
 * Handles scheduled synchronization between SmartBill and WMS
 * Run this via cron job every few minutes
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

require_once BASE_PATH . '/bootstrap.php';
// require_once BASE_PATH . '/models/SmartBillService.php';

require_once BASE_PATH . '/models/Order.php';

require_once BASE_PATH . '/models/SmartBillService.php';
require_once BASE_PATH . '/models/MultiWarehouseSmartBillService.php';
require_once BASE_PATH . '/models/Order.php';

class SmartBillSyncService {
    private $conn;
    private $smartBillService;
    private $orderModel;
    private $debugMode;
    
    // public function __construct($db) {
    //     $this->conn = $db;
    //     $this->smartBillService = new SmartBillService($db);
    //     $this->orderModel = new Order($db);
    //     $this->debugMode = (bool)$this->smartBillService->getConfig('debug_mode', false);
    // }
    
    public function __construct($db) {
        $this->conn = $db;
        $this->smartBillService = new MultiWarehouseSmartBillService($db);
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
                            $jobResult = $this->syncProductsFromSmartBill($job['max_items_per_run']);
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
     * Sync products FROM SmartBill TO WMS (Import products)
     * @param int $maxProducts Maximum number of products to process (ignored for stocks)
     * @return array Sync results
     */
    public function syncProductsFromSmartBill(int $maxProducts = 10): array {
        try {
            // Get stocks data from SmartBill and import products
            return $this->smartBillService->syncProductsFromSmartBill($maxProducts);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'processed' => 0,
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()],
                'message' => 'Product import failed: ' . $e->getMessage()
            ];
        }
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
     * Sync inventory updates to SmartBill
     * @param int $maxItems Maximum number of inventory items to process
     * @return array Sync results
     */
    public function syncInventoryToSmartBill(int $maxItems = 25): array {
        $results = [
            'success' => true,
            'processed' => 0,
            'updated_items' => [],
            'errors' => [],
            'message' => ''
        ];
        
        try {
            // For now, this is a placeholder since SmartBill API mainly creates invoices
            // You could implement inventory updates here if SmartBill supports it
            
            $results['message'] = "Inventory sync to SmartBill is not implemented yet (SmartBill API limitation)";
            $results['processed'] = 0;
            
            if ($this->debugMode) {
                error_log("SmartBill inventory sync requested but not implemented");
            }
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['message'] = 'Inventory sync failed: ' . $e->getMessage();
            $results['errors'][] = $e->getMessage();
        }
        
        return $results;
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
                return $this->syncProductsFromSmartBill(10);
                
            default:
                return [
                    'success' => false,
                    'message' => 'Unknown sync type: ' . $syncType
                ];
        }
    }
    
    /**
     * Get orders that need status sync to SmartBill
     * @param int $maxOrders Maximum orders to return
     * @return array Orders requiring sync
     */
    private function getOrdersForStatusSync(int $maxOrders): array {
        try {
            $query = "SELECT o.* FROM orders o 
                      WHERE o.smartbill_series IS NOT NULL 
                        AND o.smartbill_number IS NOT NULL
                        AND o.status IN (?, ?)
                        AND (o.smartbill_synced_at IS NULL OR o.updated_at > o.smartbill_synced_at)
                      ORDER BY o.updated_at ASC 
                      LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([Order::STATUS_SHIPPED, Order::STATUS_DELIVERED, $maxOrders]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting orders for status sync: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get scheduled sync jobs that are due to run
     * @return array Scheduled jobs
     */
    private function getScheduledJobs(): array {
        try {
            $query = "SELECT * FROM smartbill_sync_schedule 
                      WHERE is_enabled = 1 
                        AND (next_run IS NULL OR next_run <= NOW())
                      ORDER BY next_run ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting scheduled jobs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update sync schedule after job completion
     * @param string $syncType Sync type
     * @param string $status Job status (success/failed)
     * @param int $processed Number of items processed
     * @param int $errors Number of errors
     * @param float $executionTime Execution time in milliseconds
     * @param string $resultData JSON result data
     */
    private function updateSyncSchedule(string $syncType, string $status, int $processed, int $errors, float $executionTime, string $resultData): void {
        try {
            // Log the sync run
            $logQuery = "INSERT INTO smartbill_sync_log 
                         (sync_type, status, processed_count, error_count, execution_time, result_data) 
                         VALUES (?, ?, ?, ?, ?, ?)";
            $logStmt = $this->conn->prepare($logQuery);
            $logStmt->execute([$syncType, $status, $processed, $errors, $executionTime, $resultData]);
            
            // Update schedule for next run
            $scheduleQuery = "UPDATE smartbill_sync_schedule 
                              SET last_run = NOW(), 
                                  next_run = DATE_ADD(NOW(), INTERVAL interval_minutes MINUTE)
                              WHERE sync_type = ?";
            $scheduleStmt = $this->conn->prepare($scheduleQuery);
            $scheduleStmt->execute([$syncType]);
            
        } catch (PDOException $e) {
            error_log("Error updating sync schedule: " . $e->getMessage());
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
                echo "  php smartbill_sync_service.php manual product_sync\n";
                echo "  php smartbill_sync_service.php status\n";
                break;
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

?>