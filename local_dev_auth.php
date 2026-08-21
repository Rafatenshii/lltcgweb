<?php
/**
 * Local Docker: mint fake auth + seed starter/coins for Hub testing.
 * Gated by TCG_LOCAL_FAKE_AUTH=1 (llr_auth_local.php loaded).
 */

/** @param array<string,mixed> $body */
function tcgApiLocalDevStatus(array $body = []): array {
    $enabled = function_exists('tcgLocalFakeAuthEnabled') && tcgLocalFakeAuthEnabled();
    return [
        'success' => true,
        'enabled' => $enabled,
        'users' => $enabled
            ? array_values(array_map(static fn($u) => [
                'slot' => $u['slot'],
                'id' => $u['id'],
                'username' => $u['username'],
            ], tcgLocalDevUsers()))
            : [],
    ];
}

/** @param array<string,mixed> $body */
function tcgApiLocalDevLogin(array $body): array {
    if (!function_exists('tcgLocalFakeAuthEnabled') || !tcgLocalFakeAuthEnabled()) {
        throw new Exception('Local fake auth is disabled', 403);
    }
    $slot = (int)($body['slot'] ?? $_GET['slot'] ?? $_GET['local_user'] ?? 1);
    if ($slot < 1) {
        $slot = 1;
    }
    $dev = tcgLocalDevUserBySlot($slot);
    $uid = $dev['id'];
    $profile = tcgAuthUserProfile($uid);
    $user = tcgEnsureUser($uid, $profile);

    // Seed starter once so Hub / tournaments work without the picker.
    if (empty($user['starter_deck'])) {
        $cards = tcgLoadCardsData();
        try {
            tcgGrantStarterDeck($uid, 'nijigasaki', $cards);
        } catch (Throwable $e) {
            // Fallback if nijigasaki missing
            $keys = array_keys($cards['starter_decks'] ?? []);
            if ($keys) {
                tcgGrantStarterDeck($uid, (string)$keys[0], $cards);
            }
        }
        $user = tcgEnsureUser($uid, $profile);
    }

    // Top up coins for tournament prize / entry testing.
    $coins = tcgGetCoins($uid);
    if ($coins < 10000) {
        tcgAddCoins($uid, 10000 - $coins);
        $coins = tcgGetCoins($uid);
    }

    tcgSessionStart();
    $_SESSION['user_id'] = $uid;
    $_SESSION['username'] = $dev['username'];

    $token = tcgLocalIssueToken($uid);
    return [
        'success' => true,
        'auth_token' => $token,
        'user' => [
            'id' => $uid,
            'username' => $dev['username'],
            'avatar_url' => $profile['avatar_url'],
            'starter_deck' => $user['starter_deck'] ?? null,
            'needs_starter' => empty($user['starter_deck']),
        ],
        'coins' => $coins,
        'slot' => $slot,
    ];
}
