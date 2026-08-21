<?php
/**
 * Per-account tournament history: match W/L, H2H, coins earned / contributed.
 */

function tcgTournamentStatsEnsureSchema(?PDO $db = null): void {
    $db = $db ?: tcgDb();
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_user_stats (
        discord_id TEXT PRIMARY KEY,
        match_wins INTEGER NOT NULL DEFAULT 0,
        match_losses INTEGER NOT NULL DEFAULT 0,
        coins_earned INTEGER NOT NULL DEFAULT 0,
        coins_contributed INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tcg_tournament_h2h (
        discord_id TEXT NOT NULL,
        opponent_discord_id TEXT NOT NULL,
        wins INTEGER NOT NULL DEFAULT 0,
        losses INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (discord_id, opponent_discord_id),
        FOREIGN KEY (discord_id) REFERENCES tcg_users(discord_id),
        FOREIGN KEY (opponent_discord_id) REFERENCES tcg_users(discord_id)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_tcg_tournament_h2h_opp
        ON tcg_tournament_h2h(opponent_discord_id)');
}

function tcgTournamentStatsEnsureUserRow(string $discordId): void {
    $discordId = trim($discordId);
    if ($discordId === '') {
        return;
    }
    tcgTournamentStatsEnsureSchema();
    tcgDb()->prepare(
        'INSERT OR IGNORE INTO tcg_tournament_user_stats
         (discord_id, match_wins, match_losses, coins_earned, coins_contributed, updated_at)
         VALUES (?, 0, 0, 0, 0, ?)'
    )->execute([$discordId, time()]);
}

function tcgTournamentStatsBumpMatch(string $discordId, bool $won): void {
    $discordId = trim($discordId);
    if ($discordId === '') {
        return;
    }
    tcgTournamentStatsEnsureUserRow($discordId);
    $col = $won ? 'match_wins' : 'match_losses';
    tcgDb()->prepare(
        "UPDATE tcg_tournament_user_stats SET {$col} = {$col} + 1, updated_at = ? WHERE discord_id = ?"
    )->execute([time(), $discordId]);
}

function tcgTournamentStatsBumpH2h(string $winnerId, string $loserId): void {
    $winnerId = trim($winnerId);
    $loserId = trim($loserId);
    if ($winnerId === '' || $loserId === '' || $winnerId === $loserId) {
        return;
    }
    tcgTournamentStatsEnsureSchema();
    $now = time();
    $db = tcgDb();
    $db->prepare(
        'INSERT INTO tcg_tournament_h2h (discord_id, opponent_discord_id, wins, losses, updated_at)
         VALUES (?, ?, 1, 0, ?)
         ON CONFLICT(discord_id, opponent_discord_id) DO UPDATE SET
           wins = wins + 1, updated_at = excluded.updated_at'
    )->execute([$winnerId, $loserId, $now]);
    $db->prepare(
        'INSERT INTO tcg_tournament_h2h (discord_id, opponent_discord_id, wins, losses, updated_at)
         VALUES (?, ?, 0, 1, ?)
         ON CONFLICT(discord_id, opponent_discord_id) DO UPDATE SET
           losses = losses + 1, updated_at = excluded.updated_at'
    )->execute([$loserId, $winnerId, $now]);
}

function tcgTournamentStatsAddCoinsEarned(string $discordId, int $amount): void {
    if ($amount === 0) {
        return;
    }
    $discordId = trim($discordId);
    if ($discordId === '') {
        return;
    }
    tcgTournamentStatsEnsureUserRow($discordId);
    tcgDb()->prepare(
        'UPDATE tcg_tournament_user_stats
         SET coins_earned = MAX(0, coins_earned + ?), updated_at = ?
         WHERE discord_id = ?'
    )->execute([$amount, time(), $discordId]);
}

function tcgTournamentStatsAddCoinsContributed(string $discordId, int $amount): void {
    if ($amount === 0) {
        return;
    }
    $discordId = trim($discordId);
    if ($discordId === '') {
        return;
    }
    tcgTournamentStatsEnsureUserRow($discordId);
    tcgDb()->prepare(
        'UPDATE tcg_tournament_user_stats
         SET coins_contributed = MAX(0, coins_contributed + ?), updated_at = ?
         WHERE discord_id = ?'
    )->execute([$amount, time(), $discordId]);
}

/**
 * Record a completed tournament series (or bye). Idempotent via meta.stats_recorded.
 *
 * @param array<string,mixed> $meta match meta_json (by ref — sets stats_recorded)
 */
function tcgTournamentStatsRecordSeriesResult(
    string $winnerDiscordId,
    string $loserDiscordId,
    array &$meta
): void {
    if (!empty($meta['stats_recorded'])) {
        return;
    }
    $winnerDiscordId = trim($winnerDiscordId);
    if ($winnerDiscordId === '') {
        return;
    }
    tcgTournamentStatsBumpMatch($winnerDiscordId, true);
    $loserDiscordId = trim($loserDiscordId);
    if ($loserDiscordId !== '') {
        tcgTournamentStatsBumpMatch($loserDiscordId, false);
        tcgTournamentStatsBumpH2h($winnerDiscordId, $loserDiscordId);
    }
    $meta['stats_recorded'] = true;
}

/**
 * Apply coin side-effects for a successful ledger write.
 */
function tcgTournamentStatsOnLedger(string $kind, ?string $discordId, int $amount): void {
    $discordId = trim((string)$discordId);
    if ($discordId === '' || $amount <= 0) {
        return;
    }
    if ($kind === 'entry_escrow' || $kind === 'host_deposit') {
        tcgTournamentStatsAddCoinsContributed($discordId, $amount);
        return;
    }
    if ($kind === 'payout') {
        tcgTournamentStatsAddCoinsEarned($discordId, $amount);
        return;
    }
    if ($kind === 'refund') {
        // Entry / host vault returned — no longer "contributed".
        tcgTournamentStatsAddCoinsContributed($discordId, -$amount);
    }
}

/**
 * @return array{
 *   match_wins:int,match_losses:int,coins_earned:int,coins_contributed:int,
 *   h2h:list<array{opponent_discord_id:string,opponent_username:string,wins:int,losses:int}>
 * }
 */
function tcgTournamentStatsSummaryForUser(string $discordId, int $h2hLimit = 12): array {
    $discordId = trim($discordId);
    $empty = [
        'match_wins' => 0,
        'match_losses' => 0,
        'coins_earned' => 0,
        'coins_contributed' => 0,
        'h2h' => [],
    ];
    if ($discordId === '') {
        return $empty;
    }
    try {
        tcgTournamentStatsEnsureSchema();
    } catch (Throwable $e) {
        return $empty;
    }
    $stmt = tcgDb()->prepare(
        'SELECT match_wins, match_losses, coins_earned, coins_contributed
         FROM tcg_tournament_user_stats WHERE discord_id = ?'
    );
    $stmt->execute([$discordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $h2hLimit = max(0, min(50, $h2hLimit));
    $h2h = [];
    if ($h2hLimit > 0) {
        $hs = tcgDb()->prepare(
            'SELECT h.opponent_discord_id, h.wins, h.losses, u.username AS opponent_username
             FROM tcg_tournament_h2h h
             LEFT JOIN tcg_users u ON u.discord_id = h.opponent_discord_id
             WHERE h.discord_id = ?
             ORDER BY (h.wins + h.losses) DESC, h.wins DESC, h.losses ASC
             LIMIT ?'
        );
        $hs->execute([$discordId, $h2hLimit]);
        foreach ($hs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $h2h[] = [
                'opponent_discord_id' => (string)$r['opponent_discord_id'],
                'opponent_username' => (string)($r['opponent_username'] ?? 'Player'),
                'wins' => (int)$r['wins'],
                'losses' => (int)$r['losses'],
            ];
        }
    }
    return [
        'match_wins' => (int)($row['match_wins'] ?? 0),
        'match_losses' => (int)($row['match_losses'] ?? 0),
        'coins_earned' => (int)($row['coins_earned'] ?? 0),
        'coins_contributed' => (int)($row['coins_contributed'] ?? 0),
        'h2h' => $h2h,
    ];
}
