<?php
/**
 * Auth bootstrap for account.php and deck preset loading.
 *
 * Production: gitignored llr_auth.php (Discord session, same scheme as wrapped/).
 * Local Docker: llr_auth_local.php when TCG_LOCAL_FAKE_AUTH=1.
 * Contributors: llr_auth_offline.php (guest/CPU only; account APIs return 401).
 */
$authFile = getenv('TCG_LLR_AUTH_FILE');
$localFake = getenv('TCG_LOCAL_FAKE_AUTH');
$localFakeOn = is_string($localFake) && in_array(strtolower(trim($localFake)), ['1', 'true', 'yes', 'on'], true);

if ($localFakeOn && is_file(__DIR__ . '/llr_auth_local.php')) {
    require_once __DIR__ . '/llr_auth_local.php';
} elseif (is_string($authFile) && $authFile !== '' && is_file($authFile)) {
    require_once $authFile;
} elseif (is_file(__DIR__ . '/llr_auth.php')) {
    require_once __DIR__ . '/llr_auth.php';
} else {
    require_once __DIR__ . '/llr_auth_offline.php';
}
