<?php
/**
 * Shared text filters matching Love Live Radio web chat moderation
 * (Chiichan cogs/radio_chat.py + radio/chat_slurs.txt).
 *
 * Used for user-authored tournament titles (and similar public labels).
 */

/**
 * Path to the slur phrase list. Override with TCG_CHAT_SLURS_PATH.
 * Default: config/chat_slurs.txt (mirrored from Chiichan radio/chat_slurs.txt).
 */
function tcgChatSlursPath(): string {
    $env = getenv('TCG_CHAT_SLURS_PATH');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $sibling = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Chiichan' . DIRECTORY_SEPARATOR
        . 'radio' . DIRECTORY_SEPARATOR . 'chat_slurs.txt';
    if (is_readable($sibling)) {
        return $sibling;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'chat_slurs.txt';
}

/** @return list<string> */
function tcgLoadChatSlurs(): array {
    static $cache = ['mtime' => null, 'path' => '', 'lines' => []];
    $path = tcgChatSlursPath();
    if (!is_readable($path)) {
        return [];
    }
    $mtime = @filemtime($path);
    if ($cache['path'] === $path && $cache['mtime'] === $mtime && is_array($cache['lines'])) {
        return $cache['lines'];
    }
    $lines = [];
    $raw = file($path, FILE_IGNORE_NEW_LINES);
    if (is_array($raw)) {
        foreach ($raw as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $lines[] = mb_strtolower($line, 'UTF-8');
        }
    }
    $cache = ['mtime' => $mtime, 'path' => $path, 'lines' => $lines];
    return $lines;
}

/**
 * Collapse LLR single-letter server emotes so slur checks cannot be bypassed
 * by spelling with <:LLR_x:id> / :LLR_x: tokens (same as radio_chat.py).
 */
function tcgCollapseLlrLetterEmotes(string $text): string {
    $s = preg_replace('/<a?:llr_([a-z0-9]):\d+>/iu', '$1', $text) ?? $text;
    $s = preg_replace('/:llr_([a-z0-9]):/iu', '$1', $s) ?? $s;
    return $s;
}

function tcgTextContainsSlur(string $text): bool {
    $low = mb_strtolower(tcgCollapseLlrLetterEmotes($text), 'UTF-8');
    foreach (tcgLoadChatSlurs() as $phrase) {
        if ($phrase !== '' && mb_strpos($low, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

function tcgNormalizeTextForLinkScan(string $text): string {
    $s = preg_replace('/[\x{200b}-\x{200d}\x{feff}]/u', '', $text) ?? $text;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s*([.:\/@])\s*/u', '$1', $s) ?? $s;
    return trim($s);
}

function tcgTextContainsLink(string $text): bool {
    $candidates = [$text];
    $normalized = tcgNormalizeTextForLinkScan($text);
    if ($normalized !== '' && $normalized !== $text) {
        $candidates[] = $normalized;
    }
    foreach ($candidates as $candidate) {
        if (preg_match('/https?:\/\//i', $candidate)) {
            return true;
        }
        if (preg_match('/\bwww\./i', $candidate)) {
            return true;
        }
        if (preg_match('/\[[^\]]*\]\(\s*https?:\/\//i', $candidate)) {
            return true;
        }
        if (preg_match(
            '/(?:^|[\s(\[<"\'])[a-z0-9][-a-z0-9]{0,62}\.(?:com|net|org|gg|tv|io|ca|co|me|xyz|dev)\b/i',
            $candidate
        )) {
            return true;
        }
    }
    return false;
}

/**
 * Validate a public tournament title against radio chat rules.
 * @throws Exception
 */
function tcgAssertTournamentTitleAllowed(string $title): void {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 80) {
        throw new Exception('Title required (max 80 chars)', 400);
    }
    if (tcgTextContainsLink($title)) {
        throw new Exception('Please do not put links in the tournament name', 400);
    }
    if (tcgTextContainsSlur($title)) {
        throw new Exception('Blocked word(s) detected in tournament name', 400);
    }
}
