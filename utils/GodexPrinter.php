<?php
namespace Utils;

use Exception;
use InvalidArgumentException;
use PDO;

class GodexPrinter
{
    private ?string $host;
    private int $port;
    private ?string $printServerUrl;
    private ?string $queueName;

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? getenv('GODEX_PRINTER_HOST') ?: null;
        $this->port = (int)($config['port'] ?? getenv('GODEX_PRINTER_PORT') ?: 9100);
        $this->printServerUrl = $config['print_server_url'] ?? getenv('PRINT_SERVER_URL') ?: null;
        $this->queueName = $config['queue'] ?? getenv('GODEX_PRINTER_QUEUE') ?: null;
    }

    public function generateLabel(array $productData): string
    {
        $sku = trim((string)($productData['sku'] ?? ''));
        if ($sku === '') {
            throw new InvalidArgumentException('Cannot generate label without SKU value.');
        }

        $name = $this->sanitizeText($productData['name'] ?? '', 30);
        $code = $this->sanitizeText($productData['product_code'] ?? $sku, 30);
        $weight = isset($productData['weight']) ? (float)$productData['weight'] : null;
        $unitCode = $this->sanitizeText($productData['unit_code'] ?? '', 10);

        $weightText = $weight !== null ? sprintf('Greutate: %.3f %s', $weight, $unitCode ?: 'kg') : '';

        $lines = [
            'N',
            'q400',
            'Q50,30',
            'S2',
            'D10',
            'A10,10,0,3,1,1,N,"WARTUNG WMS"',
            sprintf('B10,40,0,1,2,2,60,B,"%s"', $sku),
            sprintf('A10,110,0,2,1,1,N,"%s"', $sku),
            sprintf('A10,130,0,2,1,1,N,"%s"', $name),
        ];

        if ($code !== '' && $code !== $sku) {
            $lines[] = sprintf('A10,150,0,1,1,1,N,"Cod: %s"', $code);
        }

        if ($weightText !== '') {
            $lines[] = sprintf('A10,170,0,1,1,1,N,"%s"', $weightText);
        }

        $lines[] = 'P1';

        return implode("\n", $lines) . "\n";
    }

    public function printLabel(string $labelData): void
    {
        if ($this->host) {
            $this->sendToNetworkPrinter($labelData);
            return;
        }

        if ($this->printServerUrl) {
            $this->sendToPrintServer($labelData);
            return;
        }

        throw new Exception('Printer connection is not configured.');
    }

    public function printBatch(array $products): array
    {
        $printed = 0;
        $errors = [];

        foreach ($products as $product) {
            try {
                $label = $this->generateLabel($product);
                $this->printLabel($label);
                $printed++;
            } catch (Exception $e) {
                $errors[] = [
                    'product_id' => $product['product_id'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'printed' => $printed,
            'errors' => $errors,
        ];
    }

    private function sanitizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/["\r\n]+/', ' ', $value);
        if (function_exists('mb_strimwidth')) {
            $value = mb_strimwidth($value, 0, $maxLength, '', 'UTF-8');
        } else {
            $value = substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function sendToNetworkPrinter(string $payload): void
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5.0);
        if (!$socket) {
            throw new Exception(sprintf('Cannot connect to printer %s:%d (%s)', $this->host, $this->port, $errstr ?: $errno));
        }

        stream_set_timeout($socket, 5);
        $written = fwrite($socket, $payload);
        fclose($socket);

        if ($written === false || $written === 0) {
            throw new Exception('Failed to send label data to printer.');
        }
    }

    private function sendToPrintServer(string $payload): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode([
                    'queue' => $this->queueName,
                    'payload' => base64_encode($payload),
                    'encoding' => 'base64',
                    'type' => 'epl',
                ], JSON_THROW_ON_ERROR),
                'timeout' => 5,
            ],
        ]);

        $result = @file_get_contents($this->printServerUrl, false, $context);
        if ($result === false) {
            $error = error_get_last();
            throw new Exception('Print server request failed: ' . ($error['message'] ?? 'unknown error'));
        }

        $decoded = json_decode($result, true);
        if (is_array($decoded) && isset($decoded['status']) && $decoded['status'] !== 'success') {
            $message = $decoded['message'] ?? 'Unknown print server error';
            throw new Exception('Print server responded with error: ' . $message);
        }
    }
}

