<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
require_once BASE_PATH . '/bootstrap.php';

function send_json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

try {
    $config = require BASE_PATH . '/config/config.php';
    
    if (!isset($config['connection_factory']) || !is_callable($config['connection_factory'])) {
        send_json_response(['status' => 'error', 'message' => 'Database config error.'], 500);
    }

    $dbFactory = $config['connection_factory'];
    $db = $dbFactory();

    $scannedLocation = trim($_POST['scanned_location'] ?? $_GET['scanned_location'] ?? '');
    $expectedLocation = trim($_POST['expected_location'] ?? $_GET['expected_location'] ?? '');

    if (empty($scannedLocation)) {
        send_json_response(['status' => 'error', 'message' => 'Scanned location is required.'], 400);
    }

    $normalizedScanned = normalizeLocationCode($scannedLocation);
    
    if (!empty($expectedLocation)) {
        $normalizedExpected = normalizeLocationCode($expectedLocation);
        
        if ($normalizedScanned === $normalizedExpected) {
            send_json_response([
                'status' => 'success',
                'message' => 'Location verified successfully',
                'scanned_location' => $scannedLocation,
                'expected_location' => $expectedLocation,
                'valid' => true
            ]);
        }
    }

    $locationResult = findLocationInDatabase($db, $normalizedScanned);
    
    if ($locationResult) {
        send_json_response([
            'status' => 'success',
            'message' => 'Location found in database',
            'scanned_location' => $scannedLocation,
            'database_location' => $locationResult,
            'valid' => true
        ]);
    } else {
        $similarLocations = findSimilarLocations($db, $normalizedScanned);
        
        send_json_response([
            'status' => 'error',
            'message' => 'Location not found in database',
            'scanned_location' => $scannedLocation,
            'similar_locations' => $similarLocations,
            'valid' => false
        ]);
    }

} catch (Exception $e) {
    error_log("Error in validate_location.php: " . $e->getMessage());
    send_json_response([
        'status' => 'error',
        'message' => 'Server error occurred'
    ], 500);
}

function normalizeLocationCode($locationCode) {
    $code = strtoupper(trim($locationCode));
    $code = str_replace([' ', '-', '_', '/'], '', $code);
    
    if (preg_match('/^([A-Z]+)(\d+)([A-Z]*)(\d+)$/', $code, $matches)) {
        $zone = $matches[1];
        $zoneNumber = $matches[2];
        $subZone = $matches[3];
        $shelfNumber = str_pad($matches[4], 2, '0', STR_PAD_LEFT);
        
        return $zone . $zoneNumber . $subZone . '-' . $shelfNumber;
    }
    
    if (preg_match('/^([A-Z]+)(\d+)$/', $code, $matches)) {
        return $matches[1] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
    }
    
    return $code;
}

function findLocationInDatabase($db, $normalizedCode) {
    $stmt = $db->prepare("SELECT * FROM locations WHERE UPPER(REPLACE(REPLACE(REPLACE(location_code, ' ', ''), '-', ''), '_', '')) = :code OR location_code = :original_code");
    $stmt->execute([
        ':code' => str_replace('-', '', $normalizedCode),
        ':original_code' => $normalizedCode
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return $result;
    }
    
    $variations = [
        $normalizedCode,
        str_replace('-', '', $normalizedCode),
        str_replace('-', '_', $normalizedCode),
        str_replace('-', '/', $normalizedCode),
        str_replace('-', ' ', $normalizedCode)
    ];
    
    foreach ($variations as $variation) {
        $stmt = $db->prepare("SELECT * FROM locations WHERE location_code = :code");
        $stmt->execute([':code' => $variation]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            return $result;
        }
    }
    
    return null;
}

function findSimilarLocations($db, $normalizedCode) {
    $baseCode = preg_replace('/\d+$/', '', $normalizedCode);
    
    $stmt = $db->prepare("
        SELECT location_code, zone, type 
        FROM locations 
        WHERE location_code LIKE :pattern 
        OR zone LIKE :zone_pattern
        ORDER BY location_code 
        LIMIT 5
    ");
    
    $stmt->execute([
        ':pattern' => $baseCode . '%',
        ':zone_pattern' => $baseCode . '%'
    ]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>