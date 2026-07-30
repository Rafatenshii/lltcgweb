<?php
/**
 * Stub when Chiichan wrapped/iframe_bearer_token.php is not mounted (VPS Docker).
 * Token/HMAC Discord auth still works; iframe bearer path is a no-op.
 */
if (!function_exists('llrReadIframeBearerRawFromRequest')) {
    function llrReadIframeBearerRawFromRequest(): string {
        return '';
    }
}
if (!function_exists('llrResolveIframeBearerTokenRow')) {
    function llrResolveIframeBearerTokenRow(string $raw): ?array {
        return null;
    }
}
if (!function_exists('llrRevokeIframeBearerToken')) {
    function llrRevokeIframeBearerToken(string $raw): void {
    }
}
