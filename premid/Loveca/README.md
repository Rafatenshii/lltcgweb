# Loveca PreMiD activity

PC Discord Rich Presence for [Loveca](https://loveliveradio.ca/tcg/) via [PreMiD](https://premid.app/), mirroring the Android Social SDK status text (and Spectate / Join queue URL buttons).

Status strings and join tokens come from the live page API:

`window.LLTCG_DISCORD_PRESENCE.getSnapshot()` in `client/js/discord-presence.js`.

## Requirements

- [PreMiD](https://premid.app/downloads) browser extension
- Discord desktop app open
- Node.js 20+ (for local activity development)

## Develop locally

1. Clone [PreMiD/Activities](https://github.com/PreMiD/Activities) (or use `npx pmd` in a fresh Activities checkout).
2. Copy this folder to `websites/L/Loveca/` inside that repo (letter folder matches service name).
3. Replace `author.id` in `metadata.json` with your Discord user snowflake before any store PR.
4. Enable **Activity Developer Mode** in the PreMiD extension settings.
5. From the Activities repo root:

```bash
npx pmd dev Loveca
```

6. Open `https://loveliveradio.ca/tcg/` (or your local Hostinger/dev copy) and check Discord status.

Production must serve a `discord-presence.js` build that exposes `getSnapshot()` (deployed with the TCG site).

## Settings

| Setting | Default | Effect |
|---------|---------|--------|
| Privacy Mode | off | Generic “In Loveca”; hides state and buttons |
| Show timestamps | on | Elapsed time since the current activity kind |
| Show Spectate / Join buttons | on | Opaque `?presence_action=` links (not Discord Social SDK Join) |

## Parity notes

| Android Social SDK | PreMiD (PC) |
|--------------------|-------------|
| Native Join / Spectate deep link | URL button → `https://loveliveradio.ca/tcg/?presence_action=…` |
| Options opt-in + Discord link | Installing / enabling this PreMiD activity |
| Same details / state copy | Same (shared `deriveActivity`) |
| App id `1538239969976647740` (Loveca Sim) | Same `clientId` |

## Store submission

Submitting to the PreMiD store is a separate PR against PreMiD/Activities. Update the author Discord id first; PreMiD validates it against your account.
