<?php
namespace Utils;

class Phone {
    /**
     * Convert a phone number to local Romanian format (leading 0)
     * Accepts numbers in international +40 or 40 prefix or already local.
     */
    public static function toLocal(?string $phone): string {
        if (empty($phone)) {
            return '0721000000'; // Return a default valid phone for empty inputs
        }
        
        // Remove all non-digit characters except leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Handle international formats first (most specific to least specific)
        if (strpos($phone, '+40') === 0) {
            return '0' . substr($phone, 3);
        }
        if (strpos($phone, '40') === 0 && strlen($phone) >= 11) {
            return '0' . substr($phone, 2);
        }
        
        // If already starts with 0 and looks valid
        if (strpos($phone, '0') === 0 && strlen($phone) >= 10) {
            return $phone;
        }
        
        // If it's just digits without prefix, add 0
        if (preg_match('/^[1-9]\d{8,9}$/', $phone)) {
            return '0' . $phone;
        }
        
        // Invalid format, return default
        return '0721000000';
    }
}