<?php
// File: api/config.php
return [
    'api_key' => getenv('WMS_API_KEY') ?: 'wms_webhook_2025_secure!',
    
    'allowed_origins' => [
        'https://sales.aicontrol.ro',        
        'https://notsowms.ro',             
        'http://localhost',                  
        'http://127.0.0.1',              
        'https://www.notsowms.ro',         
    ],
    'rate_limit' => [
        'requests_per_minute' => 100,       
        'requests_per_hour' => 2000
    ],
    'debug' => false 
];
?>