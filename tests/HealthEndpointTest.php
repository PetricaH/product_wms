<?php
use PHPUnit\Framework\TestCase;

class HealthEndpointTest extends TestCase
{
    private static $proc;
    private static $port = 8050; // choose port unlikely to conflict
    private static $baseUri;

    public static function setUpBeforeClass(): void
    {
        self::$baseUri = 'http://127.0.0.1:' . self::$port;
        $docRoot = realpath(__DIR__ . '/..');
        $cmd = sprintf('WMS_API_KEY=testkey DB_USER=wms_user DB_PASS= DB_HOST=127.0.0.1 DB_NAME=product_wms DB_PORT=3306 php -S 127.0.0.1:%d -t "%s" >/tmp/php-server.log 2>&1 & echo $!', self::$port, $docRoot);
        $output = [];
        exec($cmd, $output);
        self::$proc = (int)($output[0] ?? 0);
        // wait a moment for server to start
        sleep(1);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) {
            exec('kill ' . self::$proc);
        }
    }

    public function testHealthEndpoint()
    {
        $url = self::$baseUri . '/api/index.php?endpoint=health&api_key=testkey';
        $response = @file_get_contents($url);
        $this->assertNotFalse($response, 'Request failed');
        $data = json_decode($response, true);
        $this->assertIsArray($data, 'Invalid JSON');
        $this->assertEquals('healthy', $data['status'] ?? null);
    }
}
