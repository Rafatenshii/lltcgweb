<?php
/**
 * Discord profile resolution for TCG accounts (shared by llr_auth.php).
 * Reads session, wrapped/user_cache.json, iframe bearer rows, and snake_scores
 * before falling back to placeholder names.
 */
declare(strict_types=1);

/** True for empty / generic names that must not overwrite real Discord handles. */
function tcgIsPlaceholderUsername(?string $name): bool
{
    $t = trim((string)($name ?? ''));
    if ($t === '') {
        return true;
    }
    return strcasecmp($t, 'Player') === 0 || strcasecmp($t, 'User') === 0;
}

/** Site root (public_html) — parent of tcg/ on Hostinger. */
function tcgSiteRootPath(): string
{
    $override = getenv('TCG_SITE_ROOT');
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), '/\\');
    }
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $here = __DIR__;
    if (function_exists('tcgPath')) {
        $tcgDir = rtrim((string)tcgPath('TCG_ROOT', $here), '/\\');
        $root = dirname($tcgDir);
        return $root;
    }
    if (basename($here) === 'tcg') {
        $root = dirname($here);
        return $root;
    }
    // Local repo checkout: auth_profile.php sits beside db.php under lltcgweb/.
    $root = $here;
    return $root;
}

function tcgWrappedUserCachePath(): string
{
    return tcgSiteRootPath() . '/wrapped/user_cache.json';
}

/** Read wrapped user_cache entry without deleting expired rows (auth fallback). */
function tcgReadWrappedUserCacheEntry(string $userId): ?array
{
    $cacheFile = tcgWrappedUserCachePath();
    if (!is_file($cacheFile)) {
        return null;
    }
    $cache = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($cache)) {
        return null;
    }
    $entry = $cache[$userId] ?? null;
    return is_array($entry) ? $entry : null;
}

function tcgLookupSnakeProfile(string $userId): ?array
{
    $dbPath = tcgSiteRootPath() . '/database.db';
    if (!is_file($dbPath)) {
        return null;
    }
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('SELECT username, avatar_url FROM snake_scores WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $username = trim((string)($row['username'] ?? ''));
        if (tcgIsPlaceholderUsername($username)) {
            return null;
        }
        $avatarUrl = isset($row['avatar_url']) ? (string)$row['avatar_url'] : null;
        $avatarHash = null;
        if ($avatarUrl !== '' && preg_match('#/avatars/\d+/([a-zA-Z0-9_]+)\.#', $avatarUrl, $m)) {
            $avatarHash = $m[1];
        }
        return [
            'username' => $username,
            'avatar_hash' => $avatarHash,
            'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function tcgLookupIframeBearerProfile(string $userId): ?array
{
    if (!function_exists('llrResolveIframeBearerTokenRow') || !function_exists('llrReadIframeBearerRawFromRequest')) {
        return null;
    }
    $raw = llrReadIframeBearerRawFromRequest();
    if ($raw === '') {
        return null;
    }
    $row = llrResolveIframeBearerTokenRow($raw);
    if (!is_array($row) || (string)($row['user_id'] ?? '') !== (string)$userId) {
        return null;
    }
    $username = trim((string)($row['username'] ?? ''));
    if (tcgIsPlaceholderUsername($username)) {
        return null;
    }
    $avatar = $row['avatar'] ?? null;
    return [
        'username' => $username,
        'avatar_hash' => is_string($avatar) && $avatar !== '' ? $avatar : null,
    ];
}

function tcgLookupStoredTcgUserProfile(string $userId): ?array
{
    if (!function_exists('tcgDb')) {
        return null;
    }
    try {
        $db = tcgDb();
        $stmt = $db->prepare('SELECT username, avatar_url FROM tcg_users WHERE discord_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $username = trim((string)($row['username'] ?? ''));
        if (tcgIsPlaceholderUsername($username)) {
            return null;
        }
        return [
            'username' => $username,
            'avatar_url' => $row['avatar_url'] ?? null,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve Discord display + handle for a signed-in user id.
 *
 * @return array{id:string,username:string,display_name:string,avatar_url:?string}
 */
function tcgBuildAuthUserProfile(string $userId): array
{
    $userId = (string)$userId;
    $displayName = null;
    $uniqueUsername = null;
    $avatarHash = null;
    $avatarUrl = null;

    if (function_exists('tcgSessionStart')) {
        tcgSessionStart();
        if ((string)($_SESSION['user_id'] ?? '') === $userId) {
            $sessionName = trim((string)($_SESSION['username'] ?? ''));
            if (!tcgIsPlaceholderUsername($sessionName)) {
                $displayName = $sessionName;
            }
            if (!empty($_SESSION['avatar'])) {
                $avatarHash = (string)$_SESSION['avatar'];
            }
        }
    }

    $cacheEntry = tcgReadWrappedUserCacheEntry($userId);
    if (is_array($cacheEntry)) {
        $cachedHandle = trim((string)($cacheEntry['username'] ?? ''));
        if (!tcgIsPlaceholderUsername($cachedHandle)) {
            $uniqueUsername = $cachedHandle;
        }
        $cachedGlobal = trim((string)($cacheEntry['global_name'] ?? ''));
        if (!$displayName && !tcgIsPlaceholderUsername($cachedGlobal)) {
            $displayName = $cachedGlobal;
        }
        if (!$displayName && $uniqueUsername) {
            $displayName = $uniqueUsername;
        }
        if (!$avatarHash && !empty($cacheEntry['avatar'])) {
            $avatarHash = (string)$cacheEntry['avatar'];
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupIframeBearerProfile')) {
        $bearer = tcgLookupIframeBearerProfile($userId);
        if (is_array($bearer)) {
            if (!$displayName && !empty($bearer['username'])) {
                $displayName = (string)$bearer['username'];
                if (!$uniqueUsername) {
                    $uniqueUsername = $displayName;
                }
            }
            if (!$avatarHash && !empty($bearer['avatar_hash'])) {
                $avatarHash = (string)$bearer['avatar_hash'];
            }
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupSnakeProfile')) {
        $snake = tcgLookupSnakeProfile($userId);
        if (is_array($snake)) {
            if (!$displayName) {
                $displayName = (string)$snake['username'];
            }
            if (!$uniqueUsername) {
                $uniqueUsername = (string)$snake['username'];
            }
            if (!$avatarHash && !empty($snake['avatar_hash'])) {
                $avatarHash = (string)$snake['avatar_hash'];
            }
            if (!$avatarUrl && !empty($snake['avatar_url'])) {
                $avatarUrl = (string)$snake['avatar_url'];
            }
        }
    }

    if ((!$displayName || !$uniqueUsername) && function_exists('tcgLookupStoredTcgUserProfile')) {
        $stored = tcgLookupStoredTcgUserProfile($userId);
        if (is_array($stored)) {
            if (!$displayName) {
                $displayName = (string)$stored['username'];
            }
            if (!$uniqueUsername) {
                $uniqueUsername = (string)$stored['username'];
            }
            if (!$avatarUrl && !empty($stored['avatar_url'])) {
                $avatarUrl = (string)$stored['avatar_url'];
            }
        }
    }

    $username = !tcgIsPlaceholderUsername($uniqueUsername)
        ? $uniqueUsername
        : (!tcgIsPlaceholderUsername($displayName) ? $displayName : 'Player');

    if (!$avatarUrl && function_exists('tcgDiscordAvatarUrl')) {
        $avatarUrl = tcgDiscordAvatarUrl($userId, is_string($avatarHash) ? $avatarHash : null);
    } elseif (!$avatarUrl) {
        $avatarUrl = 'https://cdn.discordapp.com/embed/avatars/0.png';
    }

    return [
        'id' => $userId,
        'username' => $username,
        'display_name' => !tcgIsPlaceholderUsername($displayName) ? $displayName : $username,
        'avatar_url' => $avatarUrl,
    ];
}

/**
 * Batch-repair tcg_users rows stuck on placeholder names (CLI / operator script).
 *
 * @return array{scanned:int,repaired:int,still_placeholder:int,samples:list<array{discord_id:string,username:string}>}
 */
function tcgRepairPlaceholderUsernames(int $sampleLimit = 20): array
{
    if (!function_exists('tcgDb')) {
        throw new RuntimeException('tcgDb unavailable');
    }
    $db = tcgDb();
    $stmt = $db->query("SELECT discord_id, username FROM tcg_users WHERE username = 'Player' OR username = 'User' OR TRIM(username) = ''");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $repaired = 0;
    $still = 0;
    $samples = [];
    $now = time();
    foreach ($rows as $row) {
        $uid = (string)($row['discord_id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $profile = tcgBuildAuthUserProfile($uid);
        $newName = trim((string)($profile['username'] ?? ''));
        if (tcgIsPlaceholderUsername($newName)) {
            $still++;
            if (count($samples) < $sampleLimit) {
                $samples[] = ['discord_id' => $uid, 'username' => $newName];
            }
            continue;
        }
        $db->prepare('UPDATE tcg_users SET username = ?, avatar_url = ?, updated_at = ? WHERE discord_id = ?')
            ->execute([
                $newName,
                $profile['avatar_url'] ?? null,
                $now,
                $uid,
            ]);
        $repaired++;
    }
    return [
        'scanned' => count($rows),
        'repaired' => $repaired,
        'still_placeholder' => $still,
        'samples' => $samples,
    ];
}
