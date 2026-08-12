<?php
/**
 * Card sleeve ids stored on deck presets / match seats.
 * Catalog lives on the client (client/js/sleeves.js); server only sanitizes the id.
 */
if (!function_exists('tcgNormalizeSleeveId')) {
    function tcgNormalizeSleeveId(mixed $raw): string {
        $id = strtolower(trim((string)$raw));
        if ($id === '' || $id === 'none' || $id === 'default') {
            return '';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $id)) {
            return '';
        }
        return $id;
    }
}

if (!function_exists('tcgSleeveIdFromBodyOrRow')) {
    /** Prefer request body, then a stored row / previous player. */
    function tcgSleeveIdFromBodyOrRow(array $body, mixed $stored = null): string {
        if (array_key_exists('sleeve_id', $body)) {
            return tcgNormalizeSleeveId($body['sleeve_id']);
        }
        if (is_array($stored) && array_key_exists('sleeve_id', $stored)) {
            return tcgNormalizeSleeveId($stored['sleeve_id']);
        }
        return tcgNormalizeSleeveId($stored);
    }
}
