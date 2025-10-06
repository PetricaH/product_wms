<?php
namespace Utils;

use InvalidArgumentException;

class BarcodeGenerator
{
    private const INTERNAL_PREFIX = '5990000';
    private const PRODUCT_ID_LENGTH = 5;
    private const EAN_LENGTH = 13;

    public static function generateEAN13(int $productId): string
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('Product ID must be a positive integer.');
        }

        if ($productId > 99999) {
            throw new InvalidArgumentException('Product ID exceeds supported range (max 99,999).');
        }

        $base = self::INTERNAL_PREFIX . str_pad((string)$productId, self::PRODUCT_ID_LENGTH, '0', STR_PAD_LEFT);
        $checkDigit = self::calculateCheckDigit($base);

        return $base . $checkDigit;
    }

    public static function validateEAN13(?string $barcode): bool
    {
        if ($barcode === null) {
            return false;
        }

        $barcode = trim($barcode);
        if (strlen($barcode) !== self::EAN_LENGTH || !ctype_digit($barcode)) {
            return false;
        }

        $expected = self::calculateCheckDigit(substr($barcode, 0, self::EAN_LENGTH - 1));
        $provided = (int)substr($barcode, -1);

        return $expected === $provided;
    }

    public static function parseProductId(string $barcode): ?int
    {
        if (!self::validateEAN13($barcode)) {
            return null;
        }

        if (strpos($barcode, self::INTERNAL_PREFIX) !== 0) {
            return null;
        }

        $productIdSegment = substr($barcode, strlen(self::INTERNAL_PREFIX), self::PRODUCT_ID_LENGTH);

        if (!ctype_digit($productIdSegment)) {
            return null;
        }

        return (int)ltrim($productIdSegment, '0') ?: 0;
    }

    public static function isEAN13Format(?string $sku): bool
    {
        if ($sku === null) {
            return false;
        }

        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        return self::validateEAN13($sku);
    }

    public static function isAlphanumeric(?string $sku): bool
    {
        if ($sku === null) {
            return false;
        }

        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        return (bool)preg_match('/[a-zA-Z]/', $sku);
    }

    private static function calculateCheckDigit(string $firstTwelveDigits): int
    {
        $sum = 0;
        $length = strlen($firstTwelveDigits);

        if ($length !== self::EAN_LENGTH - 1 || !ctype_digit($firstTwelveDigits)) {
            throw new InvalidArgumentException('Base for EAN-13 check digit must be 12 numeric characters.');
        }

        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$firstTwelveDigits[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }

        return (10 - ($sum % 10)) % 10;
    }
}

