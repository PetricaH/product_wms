
<?php
// Save this as test_cargus_manual.php

$credentials = [
    "UserName" => "wartung.special",
    "Password" => "1234"
];

$url = "https://urgentcargus.portal.azure-api.net/api/LoginUser";
$headers = [
    "Content-Type: application/json",
    "Ocp-Apim-Subscription-Key: 21a28f9990aa4b478e3539fe692d2a85"
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($credentials));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing only

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Code: " . $httpCode . "\n";
echo "Response: " . $response . "\n";
echo "Error: " . $error . "\n";

if ($httpCode == 200) {
    $data = json_decode($response, true);
    if ($data) {
        echo "SUCCESS! Token: " . (isset($data) ? $data : "Token not in expected format") . "\n";
    }
}
?>