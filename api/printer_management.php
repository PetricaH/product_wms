<?php
// File: api/printer_management.php
// Enhanced API for managing print servers and printers

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/bootstrap.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

$config = require BASE_PATH . '/config/config.php';
$db = $config['connection_factory']();

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['path'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $path);
            break;
        case 'POST':
            handlePost($db, $path);
            break;
        case 'PUT':
            handlePut($db, $path);
            break;
        case 'DELETE':
            handleDelete($db, $path);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGet(PDO $db, string $path) {
    switch ($path) {
        case 'print-servers':
            getPrintServers($db);
            break;
        case 'printers':
            getPrinters($db);
            break;
        case 'print-jobs':
            getPrintJobs($db);
            break;
        case 'ping-server':
            pingPrintServer($db);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

function handlePost(PDO $db, string $path) {
    switch ($path) {
        case 'print-servers':
            createPrintServer($db);
            break;
        case 'printers':
            createPrinter($db);
            break;
        case 'test-print':
            testPrint($db);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

function handlePut(PDO $db, string $path) {
    switch ($path) {
        case 'print-servers':
            updatePrintServer($db);
            break;
        case 'printers':
            updatePrinter($db);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

function handleDelete(PDO $db, string $path) {
    switch ($path) {
        case 'print-servers':
            deletePrintServer($db);
            break;
        case 'printers':
            deletePrinter($db);
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
    }
}

// ===== PRINT SERVERS FUNCTIONS =====

function getPrintServers(PDO $db) {
    $stmt = $db->query("
        SELECT ps.*, 
               COUNT(p.id) as printer_count,
               MAX(p.last_used) as last_printer_used
        FROM print_servers ps
        LEFT JOIN printers p ON ps.id = p.print_server_id AND p.is_active = 1
        GROUP BY ps.id
        ORDER BY ps.name ASC
    ");
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'ip_address' => $row['ip_address'],
            'port' => (int)$row['port'],
            'is_active' => (bool)$row['is_active'],
            'last_ping' => $row['last_ping'],
            'location' => $row['location'],
            'printer_count' => (int)$row['printer_count'],
            'last_printer_used' => $row['last_printer_used'],
            'url' => "http://{$row['ip_address']}:{$row['port']}/print_server.php"
        ];
    }, $servers);

    echo json_encode($formatted);
}

function createPrintServer(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['name']) || empty($input['ip_address'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, ip_address']);
        return;
    }

    $port = $input['port'] ?? 8000;
    $location = $input['location'] ?? '';

    $stmt = $db->prepare('INSERT INTO print_servers (name, ip_address, port, location) VALUES (?, ?, ?, ?)');
    $result = $stmt->execute([$input['name'], $input['ip_address'], $port, $location]);

    if ($result) {
        $id = $db->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id]);
        
        // Test connection to new server
        testServerConnection($input['ip_address'], $port, $id, $db);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create print server']);
    }
}

function updatePrintServer(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing server ID']);
        return;
    }

    $fields = [];
    $values = [];

    $allowedFields = ['name', 'ip_address', 'port', 'location', 'is_active'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $values[] = $input[$field];
        }
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data to update']);
        return;
    }

    $values[] = $input['id'];
    $stmt = $db->prepare('UPDATE print_servers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $result = $stmt->execute($values);

    echo json_encode(['success' => $result]);
}

// ===== PRINTERS FUNCTIONS =====

function getPrinters(PDO $db) {
    $stmt = $db->query("
        SELECT p.*, 
               ps.name as server_name,
               ps.ip_address,
               ps.port,
               ps.is_active as server_active
        FROM printers p
        LEFT JOIN print_servers ps ON p.print_server_id = ps.id
        ORDER BY ps.name ASC, p.name ASC
    ");
    $printers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'network_identifier' => $row['network_identifier'],
            'printer_type' => $row['printer_type'],
            'paper_size' => $row['paper_size'],
            'is_active' => (bool)$row['is_active'],
            'is_default' => (bool)$row['is_default'],
            'last_used' => $row['last_used'],
            'notes' => $row['notes'],
            'print_server' => $row['print_server_id'] ? [
                'id' => (int)$row['print_server_id'],
                'name' => $row['server_name'],
                'ip_address' => $row['ip_address'],
                'port' => (int)$row['port'],
                'is_active' => (bool)$row['server_active']
            ] : null
        ];
    }, $printers);

    echo json_encode($formatted);
}

function createPrinter(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || empty($input['name']) || empty($input['network_identifier'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: name, network_identifier']);
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO printers (name, network_identifier, print_server_id, printer_type, paper_size, notes) 
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $result = $stmt->execute([
        $input['name'],
        $input['network_identifier'],
        $input['print_server_id'] ?? null,
        $input['printer_type'] ?? 'invoice',
        $input['paper_size'] ?? 'A4',
        $input['notes'] ?? ''
    ]);

    if ($result) {
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create printer']);
    }
}

// ===== UTILITY FUNCTIONS =====

function pingPrintServer(PDO $db) {
    $serverId = $_GET['server_id'] ?? null;
    if (!$serverId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing server_id']);
        return;
    }

    $stmt = $db->prepare('SELECT ip_address, port FROM print_servers WHERE id = ?');
    $stmt->execute([$serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$server) {
        http_response_code(404);
        echo json_encode(['error' => 'Server not found']);
        return;
    }

    $success = testServerConnection($server['ip_address'], $server['port'], $serverId, $db);
    echo json_encode(['success' => $success, 'timestamp' => date('Y-m-d H:i:s')]);
}

function testServerConnection(string $ip, int $port, int $serverId, PDO $db): bool {
    $url = "http://$ip:$port/print_server.php";
    $success = false;
    
    // Test with a simple GET request
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $success = true;
    }
    
    // Update last_ping timestamp
    $stmt = $db->prepare('UPDATE print_servers SET last_ping = ?, is_active = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s'), $success ? 1 : 0, $serverId]);
    
    return $success;
}

function testPrint(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['printer_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing printer_id']);
        return;
    }

    // Get printer and server info
    $stmt = $db->prepare('
        SELECT p.*, ps.ip_address, ps.port, ps.is_active as server_active
        FROM printers p
        JOIN print_servers ps ON p.print_server_id = ps.id
        WHERE p.id = ?
    ');
    $stmt->execute([$input['printer_id']]);
    $printer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$printer) {
        http_response_code(404);
        echo json_encode(['error' => 'Printer or print server not found']);
        return;
    }

    if (!$printer['server_active']) {
        http_response_code(503);
        echo json_encode(['error' => 'Print server is not active']);
        return;
    }

    // Create a simple test PDF URL (you'll need to implement this)
    $testPdfUrl = "https://www.orimi.com/pdf-test.pdf"; // Sample PDF for testing
    
    $printServerUrl = "http://{$printer['ip_address']}:{$printer['port']}/print_server.php";
    $result = sendToPrintServer($printServerUrl, $testPdfUrl);
    
    if ($result['success']) {
        // Update last_used timestamp
        $stmt = $db->prepare('UPDATE printers SET last_used = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $input['printer_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Test print sent successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
}

function sendToPrintServer(string $printServerUrl, string $pdfUrl): array {
    $url = $printServerUrl . '?url=' . urlencode($pdfUrl);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Failed to connect to print server'];
    }
    
    if (strpos($response, 'Trimis la imprimantă') !== false || 
        strpos($response, 'sent to printer') !== false) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'Print server returned: ' . $response];
}

function getPrintJobs(PDO $db) {
    // *** CORRECTED: Cast $_GET params to integers ***
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $stmt = $db->prepare("
        SELECT pj.*, 
               p.name as printer_name,
               ps.name as server_name,
               ps.ip_address
        FROM print_jobs pj
        JOIN printers p ON pj.printer_id = p.id
        JOIN print_servers ps ON pj.print_server_id = ps.id
        ORDER BY pj.created_at DESC
        LIMIT :limit OFFSET :offset
    ");

    // Bind parameters explicitly with their type
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($jobs);
}

function deletePrintServer(PDO $db) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing server ID']);
        return;
    }

    // Check if server has printers
    $stmt = $db->prepare('SELECT COUNT(*) FROM printers WHERE print_server_id = ?');
    $stmt->execute([$id]);
    $printerCount = $stmt->fetchColumn();

    if ($printerCount > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot delete server with active printers']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM print_servers WHERE id = ?');
    $result = $stmt->execute([$id]);

    echo json_encode(['success' => $result]);
}

function deletePrinter(PDO $db) {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing printer ID']);
        return;
    }

    $stmt = $db->prepare('DELETE FROM printers WHERE id = ?');
    $result = $stmt->execute([$id]);

    echo json_encode(['success' => $result]);
}

function updatePrinter(PDO $db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing printer ID']);
        return;
    }

    $fields = [];
    $values = [];

    $allowedFields = ['name', 'network_identifier', 'print_server_id', 'printer_type', 'paper_size', 'notes', 'is_active'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $fields[] = "$field = ?";
            $values[] = $input[$field];
        }
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data to update']);
        return;
    }

    $values[] = $input['id'];
    $stmt = $db->prepare('UPDATE printers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $result = $stmt->execute($values);

    echo json_encode(['success' => $result]);
}
