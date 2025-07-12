<?php
$urls = [
    "https://urgentcargus.azure-api.net/api/LoginUser",
    "https://api.urgentcargus.ro/LoginUser", 
    "https://urgentcargus.portal.azure-api.net/urgentcargus/api/LoginUser"
];

foreach ($urls as $url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "Testing $url - Code: $httpCode\n";
}
?>