# Tournament Mode — local smoke checklist

Feature stays **off** unless both are set:

- Server: `TCG_TOURNAMENTS_ENABLED=1` (e.g. in `.env` / shell)
- Client: `localStorage.tcg_tournaments_enabled = '1'` or `?tournaments=1`

**Local Docker fake login:** `compose.yaml` sets `TCG_LOCAL_FAKE_AUTH=1`. Opening localhost auto-signs in as **LocalDev1** (Nijigasaki starter + 10k Coins). Use `?local_user=2` for a second account.

Optional: `TCG_TOURNAMENT_WEBHOOK_URL` (Discord incoming webhook) fires on check-in open / bracket start / finish.

## Automated

```bash
composer test -- --filter Tournament
# or
./vendor/bin/phpunit tests/Tournament
```

Covers bracket pairing, title filter, lifecycle, Phase 2 settings/rules/delay, and `spectate_list` category `tournament`.

## Manual Hub flow

1. Start local PHP (`api.php` / `account.php`) with file GameStore + SQLite `data/tcg.db`.
2. Enable flags (above). Hard-refresh Hub — Tournament Mode should unlock (not “Coming Soon”).
3. Two signed-in accounts (or offline-dev users):
   - Host: Create event (start ~2–5 min out, check-in 5, min 2, fee optional).
     Choose fog (hidden/open hands), rules template (standard / starters / pauper / highlander), stream delay, format (single elim).
     Titles use the same slur/link filters as web radio chat (`config/chat_slurs.txt`).
   - Host + entrants show Discord avatars next to names on the event detail view.
   - Host: Deposit prize Coins.
   - Both: Register (locks equipped deck; rules template rejects illegal decks).
4. When check-in opens: both Check in. Optional: close host tab; keep one client open (tick poll) or run:
   `TCG_TOURNAMENTS_ENABLED=1 php scripts/tournament_tick.php <ID>`
   Bulletin may toast a check-in reminder (~15 min window / just opened).
5. After `start_at`, tick builds single-elim bracket (byes for odd counts) and seeds rooms.
6. Players: **Join my match** → board (Hostinger GameStore lock; match status becomes `live`).
   Spectators: bracket **Spec**, detail **Spectate matches**, or hub Spectate → tournament list
   (always Hostinger origin under match-primary). Hidden hands follow fog setting.
7. With stream delay &gt; 0, spectators see lagged snapshots under `data/spectate_delay/`; players stay live.
8. Finish / force result / connect forfeit (~3 min) → bracket advances → payout on final.
9. Cancel mid-registration → entry + remaining host vault refunded.

## Notes

- Match rooms use `mode: "tournament"` on Hostinger GameStore (not VPS ranked overflow).
- `scripts/tournament_tick.php` advances events with no browser tab.
- Tournament sky is purple; event times default to **Asia/Tokyo** and follow account `preferred_timezone`.
- Register opens a deck picker (or Deck Builder shortcut) when no eligible deck is equipped.
- List/detail show `spectator_count` for live tournament rooms.
- Phase 3 formats (Swiss / double-elim / Bo3) are stubbed as `settings.format = single_elim` only.
