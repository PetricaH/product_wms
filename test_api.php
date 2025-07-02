<?php
/**
 * Online API Test Script
 * File: test_online_api.php
 * 
 * Test your WMS API from your domain
 */

echo "🌐 Testing WMS API on notsowms.ro\n";
echo "=================================\n\n";

// Your production settings
$WMS_DOMAIN = 'https://notsowms.ro';
$API_KEY = 'WMS_API_2025_SECURE_KEY_' . md5('notsowms_warehouse_api_2025'); // Same as config

echo "🔑 API Key: " . substr($API_KEY, 0, 30) . "...\n";
echo "🌐 Testing domain: {$WMS_DOMAIN}\n\n";

// Test endpoints
$tests = [
    'Health Check' => [
        'url' => $WMS_DOMAIN . '/api/index.php?endpoint=health&api_key=' . urlencode($API_KEY),
        'expected' => 'healthy'
    ],
    'Inventory Check' => [
        'url' => $WMS_DOMAIN . '/api/index.php?endpoint=inventory/check&skus=TEST-001&api_key=' . urlencode($API_KEY),
        'expected' => 'inventory'
    ],
    'Get Orders' => [
        'url' => $WMS_DOMAIN . '/api/index.php?endpoint=orders&api_key=' . urlencode($API_KEY),
        'expected' => 'orders'
    ]
];

foreach ($tests as $testName => $testData) {
    echo "🧪 Testing: {$testName}\n";
    echo "   URL: {$testData['url']}\n";
    
    $result = testEndpoint($testData['url']);
    
    if ($result['success']) {
        echo "   ✅ Success: {$result['message']}\n";
    } else {
        echo "   ❌ Failed: {$result['error']}\n";
        echo "   📊 HTTP Code: {$result['http_code']}\n";
        echo "   📝 Response: " . substr($result['response'], 0, 200) . "\n";
    }
    echo "\n";
}

function testEndpoint($url) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: WMS-API-Test/1.0'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => 'Connection error: ' . $error,
            'http_code' => 0,
            'response' => ''
        ];
    }
    
    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => 'HTTP error',
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        return [
            'success' => false,
            'error' => 'Invalid JSON response',
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    
    if (!$data['success']) {
        return [
            'success' => false,
            'error' => $data['error'] ?? 'API returned error',
            'http_code' => $httpCode,
            'response' => $response
        ];
    }
    
    return [
        'success' => true,
        'message' => 'API responded correctly',
        'data' => $data
    ];
}

echo "🔗 Integration URLs for CRM company:\n";
echo "====================================\n";
echo "API Base URL: {$WMS_DOMAIN}/api/index.php\n";
echo "Health Check: {$WMS_DOMAIN}/api/index.php?endpoint=health\n";
echo "Inventory: {$WMS_DOMAIN}/api/index.php?endpoint=inventory/check&skus=SKU1,SKU2\n";
echo "Create Order: {$WMS_DOMAIN}/api/index.php?endpoint=orders (POST)\n";
echo "\n";
echo "🔑 API Key for CRM: {$API_KEY}\n";
echo "\n";
echo "📧 Send this information to sales.aicontrol.ro team!\n";
?>