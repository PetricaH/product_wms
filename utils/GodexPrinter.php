<?php
namespace Utils;

use Exception;
use InvalidArgumentException;

class GodexPrinter
{
    private const DEFAULT_STORAGE_SUBDIR = '/storage/label_pdfs';

    /**
     * Code 128 patterns (0-106) from specification.
     * @var array<int,string>
     */
    private const CODE128_PATTERNS = [
        '11011001100','11001101100','11001100110','10010011000','10010001100','10001001100',
        '10011001000','10011000100','10001100100','11001001000','11001000100','11000100100',
        '10110011100','10011011100','10011001110','10111001100','10011101100','10011100110',
        '11001110010','11001011100','11001001110','11011100100','11001110100','11101101110',
        '11101001100','11100101100','11100100110','11101100100','11100110100','11100110010',
        '11011011000','11011000110','11000110110','10100011000','10001011000','10001000110',
        '10110001000','10001101000','10001100010','11010001000','11000101000','11000100010',
        '10110111000','10110001110','10001101110','10111011000','10111000110','10001110110',
        '11101110110','11010001110','11000101110','11011101000','11011100010','11011101110',
        '11101011000','11101000110','11100010110','11101101000','11101100010','11100011010',
        '11101111010','11001000010','11110001010','10100110000','10100001100','10010110000',
        '10010000110','10000101100','10000100110','10110010000','10110000100','10011010000',
        '10011000010','10000110100','10000110010','11000010010','11001010000','11110111010',
        '11000010100','10001111010','10100111100','10010111100','10010011110','10111100100',
        '10011110100','10011110010','11110100100','11110010100','11110010010','11011011110',
        '11011110110','11110110110','10101111000','10100011110','10001011110','10111101000',
        '10111100010','11110101000','11110100010','10111011110','10111101110','11101011110',
        '11110101110','11010000100','11010010000','11010011100','11000111010','11010111000',
        '1100011101011'
    ];

    private static ?array $code128MapB = null;

    private ?string $printServerUrl;
    private string $printerName;
    private string $storageDir;
    private string $storageUrlPath;
    private string $baseUrl;
    private string $fontRegular;
    private string $fontBold;

    public function __construct(array $config = [])
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }

        $this->printServerUrl = $config['print_server_url'] ?? getenv('PRINT_SERVER_URL') ?: null;
        $this->printerName = (string)($config['printer'] ?? $config['queue'] ?? getenv('GODEX_PRINTER_QUEUE') ?: 'godex');

        $storageDir = $config['storage_dir'] ?? (defined('BASE_PATH') ? BASE_PATH . self::DEFAULT_STORAGE_SUBDIR : __DIR__ . '/../storage/label_pdfs');
        $this->storageDir = rtrim((string)$storageDir, '/');
        if ($this->storageDir === '') {
            $this->storageDir = BASE_PATH . self::DEFAULT_STORAGE_SUBDIR;
        }

        $storageUrlPath = $config['storage_url_path'] ?? self::DEFAULT_STORAGE_SUBDIR;
        $storageUrlPath = '/' . ltrim((string)$storageUrlPath, '/');
        $this->storageUrlPath = rtrim($storageUrlPath, '/');

        $baseUrl = $config['base_url'] ?? $this->detectBaseUrl();
        $this->baseUrl = rtrim((string)$baseUrl, '/');
        if ($this->baseUrl === '') {
            $this->baseUrl = 'http://localhost';
        }

        $this->ensureStorageDirectory();
        $this->locateFonts();
    }

    public function printBatch(array $products): array
    {
        $printed = 0;
        $errors = [];

        foreach ($products as $product) {
            try {
                $label = $this->createLabelDocument($product);
                $result = $this->sendToPrintServer($label['url'], $this->printerName, $label['format']);
                if (!$result['success']) {
                    throw new Exception($result['error'] ?? 'Print server error');
                }
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

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            if (!@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
                throw new Exception('Unable to create storage directory for labels.');
            }
        }

        if (!is_writable($this->storageDir)) {
            throw new Exception('Storage directory for labels is not writable.');
        }
    }

    private function detectBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';

        if ($host === '') {
            return 'http://localhost';
        }

        return $scheme . '://' . $host;
    }

    private function locateFonts(): void
    {
        $defaultRegular = BASE_PATH . '/fonts/Roboto-Regular.ttf';
        $defaultBold = BASE_PATH . '/fonts/Roboto-Bold.ttf';

        $this->fontRegular = is_readable($defaultRegular) ? $defaultRegular : '';
        $this->fontBold = is_readable($defaultBold) ? $defaultBold : $this->fontRegular;

        if (($this->fontRegular === '' || $this->fontBold === '') && function_exists('imagettftext')) {
            $systemFont = getenv('GDFONTPATH');
            if ($systemFont && is_readable($systemFont)) {
                $this->fontRegular = $this->fontRegular ?: $systemFont;
                $this->fontBold = $this->fontBold ?: $systemFont;
            }
        }

        $this->fontRegular = $this->fontRegular ?: '';
        $this->fontBold = $this->fontBold ?: $this->fontRegular;
    }

    private function createLabelDocument(array $product): array
    {
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku === '') {
            throw new InvalidArgumentException('Cannot generate label without SKU value.');
        }

        $fileName = $this->buildFileName($sku);
        $filePath = $this->storageDir . '/' . $fileName;

        $document = $this->renderPngLabel($product, $filePath);

        return [
            'path' => $filePath,
            'url' => $this->buildFileUrl($fileName),
            'format' => $document['format'] ?? 'png',
        ];
    }

    private function buildFileName(string $sku): string
    {
        $safeSku = preg_replace('/[^A-Za-z0-9_-]/', '_', $sku);
        $safeSku = trim($safeSku, '_');
        if ($safeSku === '') {
            $safeSku = 'label';
        }

        $unique = substr(str_replace('.', '', uniqid('', true)), -6);

        return sprintf('product_label_%s_%s_%s.png', $safeSku, date('Ymd_His'), $unique);
    }

    private function buildFileUrl(string $fileName): string
    {
        return $this->baseUrl . $this->storageUrlPath . '/' . rawurlencode($fileName);
    }

    private function sanitizeText(?string $value, int $maxLength = 0): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/["\r\n\t]+/', ' ', $value);

        if ($maxLength > 0) {
            if (function_exists('mb_strimwidth')) {
                $value = mb_strimwidth($value, 0, $maxLength, '', 'UTF-8');
            } else {
                $value = substr($value, 0, $maxLength);
            }
        }

        return $value;
    }

    private function encodeCode128(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('Cannot encode an empty barcode value.');
        }

        $map = self::$code128MapB;
        if ($map === null) {
            $map = [];
            for ($i = 32; $i <= 126; $i++) {
                $map[chr($i)] = $i - 32;
            }
            $map[chr(127)] = 95;
            self::$code128MapB = $map;
        }

        $quietZone = str_repeat('0', 10);
        $patterns = [$quietZone, self::CODE128_PATTERNS[104]]; // Start Code B
        $checksum = 104;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if (!isset($map[$char])) {
                throw new InvalidArgumentException(sprintf('Unsupported character "%s" for Code 128 barcode.', $char));
            }
            $code = $map[$char];
            $patterns[] = self::CODE128_PATTERNS[$code];
            $checksum += $code * ($i + 1);
        }

        $checksumCode = $checksum % 103;
        $patterns[] = self::CODE128_PATTERNS[$checksumCode];
        $patterns[] = self::CODE128_PATTERNS[106];
        $patterns[] = '11'; // Termination bars
        $patterns[] = $quietZone;

        return $patterns;
    }

    private function renderPngLabel(array $product, string $filePath): array
    {
        if (!extension_loaded('gd')) {
            throw new Exception('GD extension is required for PNG label rendering.');
        }

        $width = 600;
        $height = 400;

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new Exception('Unable to initialize image canvas for label.');
        }

        if (function_exists('imageantialias')) {
            imageantialias($image, true);
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 90, 90, 90);

        imagefill($image, 0, 0, $white);

        $margin = 28;
        $usableWidth = $width - ($margin * 2);

        $title = 'WARTUNG WMS';
        $titleSize = 26;
        $titleY = $margin + $titleSize;
        $this->drawText($image, $title, $this->fontBold, $titleSize, $margin, $titleY, $black);

        $barcodeTop = $titleY + 20;
        $barcodeHeight = 160;
        $patterns = implode('', $this->encodeCode128(trim((string)$product['sku'])));
        $moduleCount = strlen($patterns);
        if ($moduleCount === 0) {
            imagedestroy($image);
            throw new Exception('Unable to encode barcode pattern.');
        }

        $moduleWidth = (int)max(1, floor($usableWidth / $moduleCount));
        $barcodeWidth = $moduleWidth * $moduleCount;
        $barcodeLeft = (int)($margin + ($usableWidth - $barcodeWidth) / 2);

        for ($i = 0; $i < $moduleCount; $i++) {
            if ($patterns[$i] === '1') {
                $x1 = $barcodeLeft + ($i * $moduleWidth);
                $x2 = $x1 + $moduleWidth - 1;
                imagefilledrectangle($image, $x1, $barcodeTop, $x2, $barcodeTop + $barcodeHeight, $black);
            }
        }

        $skuTextSize = 28;
        $skuY = $barcodeTop + $barcodeHeight + 42;
        $this->drawCenteredText($image, $product['sku'], $this->fontRegular, $skuTextSize, $width / 2, $skuY, $black);

        $textTop = $skuY + 24;
        $lineHeight = 36;

        $name = $this->sanitizeText($product['name'] ?? '', 60);
        if ($name !== '') {
            $this->drawWrappedText($image, $name, $this->fontBold ?: $this->fontRegular, 24, $margin, $textTop, $usableWidth, $lineHeight, $black);
            $textTop += $lineHeight * $this->estimateLineCount($name, $usableWidth, 24, $this->fontBold ?: $this->fontRegular);
        }

        $code = $this->sanitizeText($product['product_code'] ?? '', 40);
        if ($code !== '' && $code !== $product['sku']) {
            $this->drawText($image, 'Cod: ' . $code, $this->fontRegular ?: $this->fontBold, 22, $margin, $textTop, $gray);
            $textTop += $lineHeight;
        }

        $weight = isset($product['weight']) ? (float)$product['weight'] : null;
        $unitCode = $this->sanitizeText($product['unit_code'] ?? '', 10) ?: 'kg';
        if ($weight !== null && $weight > 0) {
            $weightText = sprintf('Greutate: %.3f %s', $weight, $unitCode);
            $this->drawText($image, $weightText, $this->fontRegular ?: $this->fontBold, 22, $margin, $textTop, $gray);
        }

        $saved = imagepng($image, $filePath, 0);
        imagedestroy($image);

        if (!$saved) {
            throw new Exception('Failed to save PNG label to storage.');
        }

        return ['format' => 'png'];
    }

    private function drawText($image, string $text, string $font, int $size, int $x, int $y, int $color): void
    {
        if ($font !== '' && function_exists('imagettftext')) {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
        } else {
            imagestring($image, 5, $x, $y - 12, $text, $color);
        }
    }

    private function drawCenteredText($image, string $text, string $font, int $size, float $centerX, int $baselineY, int $color): void
    {
        if ($font !== '' && function_exists('imagettfbbox')) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            $textWidth = abs($bbox[2] - $bbox[0]);
            $x = (int)($centerX - ($textWidth / 2));
            imagettftext($image, $size, 0, $x, $baselineY, $color, $font, $text);
        } else {
            $textWidth = imagefontwidth(5) * strlen($text);
            $x = (int)($centerX - ($textWidth / 2));
            imagestring($image, 5, $x, $baselineY - 12, $text, $color);
        }
    }

    private function drawWrappedText($image, string $text, string $font, int $size, int $x, int $startY, int $maxWidth, int $lineHeight, int $color): void
    {
        $words = preg_split('/\s+/', $text);
        $line = '';
        $y = $startY;

        foreach ($words as $word) {
            $testLine = trim($line === '' ? $word : $line . ' ' . $word);
            if ($testLine === '') {
                continue;
            }

            $lineWidth = $this->measureTextWidth($testLine, $font, $size);
            if ($lineWidth > $maxWidth && $line !== '') {
                $this->drawText($image, $line, $font, $size, $x, $y, $color);
                $line = $word;
                $y += $lineHeight;
            } else {
                $line = $testLine;
            }
        }

        if ($line !== '') {
            $this->drawText($image, $line, $font, $size, $x, $y, $color);
        }
    }

    private function measureTextWidth(string $text, string $font, int $size): float
    {
        if ($font !== '' && function_exists('imagettfbbox')) {
            $bbox = imagettfbbox($size, 0, $font, $text);
            return abs($bbox[2] - $bbox[0]);
        }

        return imagefontwidth(5) * strlen($text);
    }

    private function estimateLineCount(string $text, int $maxWidth, int $size, string $font): int
    {
        $words = preg_split('/\s+/', $text);
        $lines = 1;
        $current = '';

        foreach ($words as $word) {
            $test = trim($current === '' ? $word : $current . ' ' . $word);
            if ($this->measureTextWidth($test, $font, $size) > $maxWidth && $current !== '') {
                $lines++;
                $current = $word;
            } else {
                $current = $test;
            }
        }

        return max(1, $lines);
    }

    private function sendToPrintServer(string $fileUrl, string $printer, string $format = 'pdf'): array
    {
        if ($this->printServerUrl === null) {
            return ['success' => false, 'error' => 'Print server URL is not configured'];
        }

        $query = [
            'url' => $fileUrl,
            'printer' => $printer,
        ];

        if ($format !== '') {
            $query['format'] = $format;
        }

        $requestUrl = $this->printServerUrl . '?' . $this->buildPrintQuery($query);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
                'user_agent' => 'WMS-PrintClient/1.0',
            ],
        ]);

        $response = @file_get_contents($requestUrl, false, $context);
        if ($response === false) {
            return ['success' => false, 'error' => 'Failed to connect to print server'];
        }

        foreach (['Trimis la imprimantă', 'sent to printer', 'Print successful'] as $indicator) {
            if (stripos($response, $indicator) !== false) {
                return ['success' => true];
            }
        }

        return ['success' => false, 'error' => 'Print server response: ' . $response];
    }

    private function buildPrintQuery(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            $encodedKey = rawurlencode((string) $key);
            $encodedValue = rawurlencode((string) $value);

            if ($key === 'printer') {
                $encodedValue = str_replace('%2B', '+', $encodedValue);
            }

            $pairs[] = $encodedKey . '=' . $encodedValue;
        }

        return implode('&', $pairs);
    }
}
