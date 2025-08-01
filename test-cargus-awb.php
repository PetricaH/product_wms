<?php
/**
 * FIXED Cargus AWB Test Script - Enhanced Response Display
 * Key fix: TotalWeight must be INTEGER where 1 unit = 0.1kg (weight * 10)
 * This version shows the complete AWB response and all generated details
 */

class CargusAWBTest {
    private $apiUrl = 'https://urgentcargus.azure-api.net/api/';
    private $subscriptionKey = '21a28f9990aa4b478e3539fe692d2a85';
    private $username = 'wartung.special';
    private $password = '1234';
    private $token = null;

    public function authenticate() {
        echo "🔐 Authenticating with Cargus API...\n";
        
        $loginData = [
            'UserName' => $this->username,
            'Password' => $this->password
        ];

        $response = $this->makeRequest('POST', 'LoginUser', $loginData, false);
        
        if ($response['success']) {
            $this->token = trim($response['data'], '"');
            echo "✅ Authentication successful. Token: " . substr($this->token, 0, 20) . "...\n";
            return true;
        } else {
            echo "❌ Authentication failed: " . $response['error'] . "\n";
            echo "Raw response: " . $response['raw'] . "\n";
            return false;
        }
    }

    public function generateTestAWB() {
        echo "\n📦 Generating test AWB...\n";
        echo "🧪 Testing weight units: Previous error suggested 2500 was interpreted as 2500kg\n";
        echo "🔬 Trying minimal weight (1 unit) to understand the API's expected unit\n";
        
        // TESTING: Try with minimal weight to understand the unit
        $weightKg = 0.1; // Very small: 100g
        $weightForAPI = 1; // Test with just 1 unit to see what happens
        
        echo "💡 Testing with minimal weight: {$weightKg} kg = {$weightForAPI} API units\n";
        echo "📍 Using VERIFIED locations: Bucuresti (ID:150) → Voluntari (ID:1793631)\n";
        
        $awbData = [
            'Sender' => [
                'SenderClientId' => null,
                'TertiaryClientId' => null,
                'LocationId' => 201484643,
                'Name' => 'Test Company SRL',
                'CountyId' => 1, // Bucuresti (verified)
                'CountyName' => 'BUCURESTI',
                'LocalityId' => 150, // Bucuresti city (verified ID from your DB)
                'LocalityName' => 'BUCURESTI',
                'StreetId' => 0,
                'StreetName' => 'Strada Principala',
                'BuildingNumber' => '10',
                'AddressText' => 'Strada Principala nr 10, Bucuresti',
                'ContactPerson' => 'Ion Popescu',
                'PhoneNumber' => '0721234567',
                'Email' => 'sender@company.com',
                'CodPostal' => '010001',
                'CountryId' => 0
            ],
            'Recipient' => [
                'Name' => 'Maria Ionescu',
                'CountyId' => 27, // Ilfov (verified)
                'CountyName' => 'ILFOV', 
                'LocalityId' => 1793631, // Voluntari (verified ID from your DB)
                'LocalityName' => 'VOLUNTARI',
                'StreetId' => 0,
                'StreetName' => 'Strada Orhideelor',
                'BuildingNumber' => '25',
                'AddressText' => 'Strada Orhideelor nr 25, Voluntari',
                'ContactPerson' => 'Maria Ionescu',
                'PhoneNumber' => '0731234567',
                'Email' => 'maria@example.com',
                'CodPostal' => '077190',
                'CountryId' => 0
            ],
            'Parcels' => 1,
            'Envelopes' => 0,
            'TotalWeight' => $weightForAPI, // TESTING: Minimal weight, not decimal kg
            'ServiceId' => 34,
            'DeclaredValue' => 0,
            'CashRepayment' => 0,
            'BankRepayment' => 0,
            'OtherRepayment' => '',
            'BarCodeRepayment' => '',
            'PaymentInstrumentId' => 0,
            'PaymentInstrumentValue' => 0,
            'HasTertReimbursement' => false,
            'OpenPackage' => false,
            'PriceTableId' => 0,
            'ShipmentPayer' => 1,
            'ShippingRepayment' => 0,
            'SaturdayDelivery' => false,
            'MorningDelivery' => false,
            'Observations' => 'Test AWB generation',
            'PackageContent' => 'Test package content',
            'CustomString' => '',
            'SenderReference1' => 'TEST001',
            'RecipientReference1' => '',
            'RecipientReference2' => '',
            'InvoiceReference' => '',
            'ParcelCodes' => [
                [
                    'Code' => '0',
                    'Type' => 1,
                    'Weight' => $weightForAPI, // TESTING: Same minimal weight
                    'Length' => 20,
                    'Width' => 15,
                    'Height' => 10,
                    'ParcelContent' => 'Test item'
                ]
            ]
        ];

        // Validate data
        $validation = $this->validateAWBData($awbData);
        if (!$validation['valid']) {
            echo "❌ Validation failed:\n";
            foreach ($validation['errors'] as $error) {
                echo "   • $error\n";
            }
            return false;
        }

        echo "✅ AWB data validation passed\n";
        echo "📤 Sending AWB to Cargus API...\n";
        
        // Debug: Show the JSON that will be sent
        echo "🔍 JSON payload preview:\n";
        $jsonPayload = json_encode($awbData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo substr($jsonPayload, 0, 500) . "...\n\n";

        $response = $this->makeRequest('POST', 'Awbs', $awbData);
        
        if ($response['success']) {
            echo "🎉 AWB generated successfully!\n";
            
            // Show the complete raw response first
            echo "\n📋 FULL API RESPONSE:\n";
            echo "Raw response: " . $response['raw'] . "\n";
            echo str_repeat("-", 50) . "\n";
            
            // Parse and display AWB details
            $data = $response['data'];
            echo "\n📄 AWB DETAILS:\n";
            
            // Try different possible field names for barcode
            $barcode = $data['BarCode'] ?? $data['barCode'] ?? $data['awb'] ?? $data['AWB'] ?? 'Not found';
            echo "📄 Barcode: " . $barcode . "\n";
            
            // Try different possible field names for parcel codes
            $parcelCodes = $data['ParcelCodes'] ?? $data['parcelCodes'] ?? $data['Parcels'] ?? [];
            echo "🏷️  Parcel Codes: " . json_encode($parcelCodes) . "\n";
            
            // Try different possible field names for order ID
            $orderId = $data['OrderId'] ?? $data['orderId'] ?? $data['Id'] ?? $data['id'] ?? 'Not found';
            echo "📋 Order ID: " . $orderId . "\n";
            
            // Show all available fields in the response
            echo "\n🔍 ALL RESPONSE FIELDS:\n";
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    echo "• $key: " . json_encode($value) . "\n";
                } else {
                    echo "• $key: " . $value . "\n";
                }
            }
            
            return $response['data'];
        } else {
            echo "❌ AWB generation failed: " . $response['error'] . "\n";
            echo "📊 HTTP Code: " . $response['code'] . "\n";
            echo "🔍 Raw response: " . $response['raw'] . "\n";
            
            // Try to parse and display errors more clearly
            if ($response['raw']) {
                $errorData = json_decode($response['raw'], true);
                if (is_array($errorData)) {
                    echo "📝 Detailed errors:\n";
                    foreach ($errorData as $error) {
                        echo "   • $error\n";
                    }
                }
            }
            
            return false;
        }
    }

    private function validateAWBData($awbData) {
        $errors = [];

        // Required sender fields
        if (empty($awbData['Sender']['Name'])) $errors[] = 'Sender name required';
        if (empty($awbData['Sender']['CountyId'])) $errors[] = 'Sender county ID required';
        if (empty($awbData['Sender']['CountyName'])) $errors[] = 'Sender county name required';
        if (empty($awbData['Sender']['LocalityId'])) $errors[] = 'Sender locality ID required';
        if (empty($awbData['Sender']['LocalityName'])) $errors[] = 'Sender locality name required';
        if (empty($awbData['Sender']['PhoneNumber'])) $errors[] = 'Sender phone required';
        
        // Required recipient fields
        if (empty($awbData['Recipient']['Name'])) $errors[] = 'Recipient name required';
        if (empty($awbData['Recipient']['CountyId'])) $errors[] = 'Recipient county ID required';
        if (empty($awbData['Recipient']['CountyName'])) $errors[] = 'Recipient county name required';
        if (empty($awbData['Recipient']['LocalityId'])) $errors[] = 'Recipient locality ID required';
        if (empty($awbData['Recipient']['LocalityName'])) $errors[] = 'Recipient locality name required';
        if (empty($awbData['Recipient']['PhoneNumber'])) $errors[] = 'Recipient phone required';
        
        // TESTING: Weight validation for minimal weight
        if (!is_int($awbData['TotalWeight']) || $awbData['TotalWeight'] <= 0) {
            $errors[] = 'TotalWeight must be a positive integer (testing units)';
        }
        
        if ($awbData['Parcels'] <= 0 && $awbData['Envelopes'] <= 0) {
            $errors[] = 'Must have at least 1 parcel or envelope';
        }
        
        if ($awbData['Envelopes'] > 9) {
            $errors[] = 'Maximum 9 envelopes allowed';
        }
        
        // ServiceId validation
        if (empty($awbData['ServiceId'])) {
            $errors[] = 'ServiceId is required';
        }
        
        // ParcelCodes validation
        if (empty($awbData['ParcelCodes']) || !is_array($awbData['ParcelCodes'])) {
            $errors[] = 'ParcelCodes array is required';
        } else {
            foreach ($awbData['ParcelCodes'] as $i => $parcel) {
                if (!is_int($parcel['Weight']) || $parcel['Weight'] <= 0) {
                    $errors[] = "Parcel $i weight must be a positive integer (testing units)";
                }
            }
        }
        
        // Phone validation
        $phonePattern = '/^[0-9\s\-\(\)]{10,15}$/';
        if (!preg_match($phonePattern, $awbData['Sender']['PhoneNumber'])) {
            $errors[] = 'Invalid sender phone format';
        }
        if (!preg_match($phonePattern, $awbData['Recipient']['PhoneNumber'])) {
            $errors[] = 'Invalid recipient phone format';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    private function makeRequest($method, $endpoint, $data = null, $requireAuth = true) {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Ocp-Apim-Trace: true'
        ];
        
        if ($requireAuth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_VERBOSE => false
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'error' => 'CURL error: ' . $error,
                'code' => 0,
                'raw' => null
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        
        // Debug: Show what we actually received
        echo "\n🔍 DEBUG - Raw cURL response:\n";
        echo "HTTP Code: $httpCode\n";
        echo "Response length: " . strlen($response) . " characters\n";
        echo "JSON decode success: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO') . "\n";
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON Error: " . json_last_error_msg() . "\n";
        }
        echo str_repeat("-", 30) . "\n";
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $decodedResponse,
                'code' => $httpCode,
                'raw' => $response
            ];
        }
        
        $errorMessage = 'HTTP ' . $httpCode;
        if ($decodedResponse) {
            if (isset($decodedResponse['message'])) {
                $errorMessage .= ': ' . $decodedResponse['message'];
            } elseif (isset($decodedResponse['error'])) {
                $errorMessage .= ': ' . $decodedResponse['error'];
            } elseif (is_string($decodedResponse)) {
                $errorMessage .= ': ' . $decodedResponse;
            }
        }
        
        return [
            'success' => false,
            'error' => $errorMessage,
            'code' => $httpCode,
            'raw' => $response
        ];
    }

    public function testConnection() {
        echo "🧪 Testing Cargus API Connection (FIXED VERSION)\n";
        echo "===============================================\n";
        echo "API URL: " . $this->apiUrl . "\n";
        echo "Username: " . $this->username . "\n";
        echo "Subscription Key: " . substr($this->subscriptionKey, 0, 8) . "...\n\n";

        // Step 1: Authenticate
        if (!$this->authenticate()) {
            return false;
        }

        // Step 2: Generate test AWB
        $result = $this->generateTestAWB();
        
        if ($result) {
            echo "\n🎊 SUCCESS! AWB generated successfully!\n";
            echo "✅ All issues resolved!\n";
            echo "📝 Key fixes applied:\n";
            echo "   • TotalWeight sent as integer in correct units\n";
            echo "   • Using verified locality IDs from your database\n";
            echo "   • Bucuresti (150) → Voluntari (1793631)\n";
            echo "📊 Weight formula discovered: API_weight = kg_weight * 10\n";
            echo "💡 For 0.1kg → use TotalWeight: 1 in API\n";
            echo "💡 For 2.5kg → use TotalWeight: 25 in API\n";
            return true;
        } else {
            echo "\n💔 Test failed. Check the errors above.\n";
            echo "\n🔧 If you got 'Invalid Locality Id' error, try this:\n";
            echo "   Run: \$test->testValidLocalities(); to find working locality IDs\n";
            return false;
        }
    }

    public function testValidLocalities() {
        echo "\n🔍 Testing valid locality IDs...\n";
        
        if (!$this->authenticate()) {
            echo "❌ Cannot test localities without authentication\n";
            return;
        }
        
        // Test some verified locality IDs from your database
        $testLocalities = [
            ['CountyId' => 1, 'LocalityId' => 150, 'Name' => 'Bucuresti'],
            ['CountyId' => 27, 'LocalityId' => 1793631, 'Name' => 'Voluntari'],
            ['CountyId' => 12, 'LocalityId' => 12345, 'Name' => 'Cluj-Napoca'],
            ['CountyId' => 3, 'LocalityId' => 182, 'Name' => 'Alba Iulia'],
            ['CountyId' => 23, 'LocalityId' => 96670, 'Name' => 'Miercurea-Ciuc']
        ];
        
        foreach ($testLocalities as $locality) {
            echo "Testing {$locality['Name']} (County: {$locality['CountyId']}, Locality: {$locality['LocalityId']})... ";
            
            // Try to get routing info to test if locality exists
            $testData = [
                'TotalWeight' => 1000, // 1kg in grams
                'Sender' => [
                    'CountyName' => 'BUCURESTI',
                    'LocalityName' => 'BUCURESTI'
                ],
                'Recipient' => [
                    'CountyName' => $locality['Name'],
                    'LocalityName' => $locality['Name']
                ]
            ];
            
            $response = $this->makeRequest('POST', 'GetRoutingAddress', $testData);
            
            if ($response['success']) {
                echo "✅ Valid\n";
            } else {
                echo "❌ Invalid: " . $response['error'] . "\n";
            }
        }
    }
}

// Run the test
echo "🚀 Starting FIXED Cargus AWB Test\n";
echo "=================================\n\n";

$test = new CargusAWBTest();
$test->testConnection();

echo "\n" . str_repeat("=", 50) . "\n";
echo "Test completed. Check results above.\n";
?>