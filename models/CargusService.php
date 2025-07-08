<?php
class CargusService {
    private $apiUrl = 'https://urgentcargus.azure-api.net/api/';
    private $username;
    private $password;
    private $token;
    private $tokenExpiry;

    public function __construct() {
        $config = require BASE_PATH . '/config/config.php';
        $this->username = $config['cargus']['username'] ?? '';
        $this->password = $config['cargus']['password'] ?? '';
        if (!empty($config['cargus']['api_url'])) {
            $this->apiUrl = rtrim($config['cargus']['api_url'], '/') . '/';
        }
    }

    private function authenticate() {
        if ($this->token && $this->tokenExpiry > time()) {
            return true;
        }

        $response = $this->makeRequest('POST', 'LoginUser', [
            'UserName' => $this->username,
            'Password' => $this->password
        ]);

        if ($response['success']) {
            $this->token = $response['data'];
            $this->tokenExpiry = time() + (24 * 3600);
            return true;
        }

        return false;
    }

    public function generateAWB($orderData) {
        if (!$this->authenticate()) {
            throw new Exception('Cargus authentication failed');
        }

        $awbData = [
            'Sender' => [
                'LocationId' => $orderData['sender_location_id'] ?? 0,
                'Name' => 'Your Company Name',
                'CountyId' => 1,
                'LocalityId' => 1,
                'StreetId' => 1,
                'BuildingNumber' => 'Your building number',
                'ContactPerson' => 'Warehouse Manager',
                'PhoneNumber' => 'Your phone',
                'Email' => 'warehouse@company.com'
            ],
            'Recipient' => [
                'Name' => $orderData['recipient_contact_person'],
                'CountyId' => $orderData['recipient_county_id'],
                'LocalityId' => $orderData['recipient_locality_id'],
                'StreetId' => $orderData['recipient_street_id'],
                'BuildingNumber' => $orderData['recipient_building_number'],
                'ContactPerson' => $orderData['recipient_contact_person'],
                'PhoneNumber' => $orderData['recipient_phone'],
                'Email' => $orderData['recipient_email']
            ],
            'Parcels' => $orderData['parcels_count'],
            'Envelopes' => $orderData['envelopes_count'],
            'TotalWeight' => $orderData['total_weight'],
            'DeclaredValue' => $orderData['declared_value'],
            'CashRepayment' => $orderData['cash_repayment'],
            'BankRepayment' => $orderData['bank_repayment'],
            'OpenPackage' => $orderData['open_package'],
            'SaturdayDelivery' => $orderData['saturday_delivery'],
            'Observations' => $orderData['observations'],
            'PackageContent' => $orderData['package_content']
        ];

        $response = $this->makeRequest('POST', 'Awbs', $awbData);

        if ($response['success']) {
            return [
                'success' => true,
                'barcode' => $response['data']['BarCode'],
                'parcelCodes' => $response['data']['ParcelCodes']
            ];
        }

        return ['success' => false, 'error' => $response['error']];
    }

    private function makeRequest($method, $endpoint, $data = null) {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        if ($this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && $data !== null) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $decoded];
        }

        $errMsg = $decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $httpCode);
        return ['success' => false, 'error' => $errMsg];
    }
}