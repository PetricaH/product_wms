<?php
namespace Utils;

class Phone {
    /**
     * Convert a phone number to local Romanian format (leading 0)
     * Accepts numbers in international +40 or 40 prefix or already local.
     */
    public static function toLocal(string $phone): string {
        if ($phone === null || $phone === '') {
            return '';
        }
        // Remove non digit characters except leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // If starts with +40 or 40, convert to local 0 prefix
        if (strpos($phone, '+40') === 0) {
            return '0' . substr($phone, 3);
        }
        if (strpos($phone, '40') === 0) {
            return '0' . substr($phone, 2);
        }
        if (strpos($phone, '+4') === 0) {
            return '0' . substr($phone, 2);
        }
        return $phone;
    }
}