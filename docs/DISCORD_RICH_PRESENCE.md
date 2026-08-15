# Discord Rich Presence (Android Loveca)

Android-only Discord Social SDK Rich Presence. The Capacitor shell lives in the private
sibling repo `lltcg-android`.

**Two Discord applications:**

| App | ID | Purpose |
|-----|-----|---------|
| Love Live Radio (Wrapped / site) | `1439716818058088612` | Website Discord login (`identify`) only |
| **Loveca Sim** | `1538239969976647740` | Social SDK Rich Presence / join deep links |

Website OAuth sessions do **not** grant Social SDK presence scopes.

## Discord Developer Portal checklist (Loveca Sim)

1. Enable **Social SDK / Social Layer** on **Loveca Sim**.
2. OAuth2 redirect URI (mobile PKCE) — must use the **Loveca Sim** id:
   `discord-1538239969976647740:/authorize/callback`
3. **Deep Link URL** (General Information, after Social SDK is enabled):
   `https://loveliveradio.ca/tcg`
   Discord appends `/_discord/join?secret=…` when a friend accepts Join.
4. Upload Rich Presence art assets (optional once hosted icon URL works; keys the client
   can use later: `loveca`, `loveca_ranked`, `loveca_casual`, `loveca_cpu`, `loveca_booster`,
   `loveca_sticker`). Until then the client uses
   `https://loveliveradio.ca/tcg/downloads/loveca-icon-192.png`.
   Also set the app **name** / icon under General Information — RPC can otherwise show
   a generic “Game” label (the Android shell forces `Activity::SetName("Loveca Sim")`
   and uses the APK Honoka icon URL for large image art).
5. Download `discord_partner_sdk.aar` and place it at:
   `lltcg-android/android/app/libs/discord_partner_sdk.aar`
   (gitignored — never commit the AAR).

Do **not** change Love Live Radio’s Wrapped redirect
(`https://www.loveliveradio.ca/wrapped`).

## Android shell

See `lltcg-android/README.md` § Discord Rich Presence.

- App Links / intent filters open Loveca for
  `https://loveliveradio.ca/tcg/_discord/join?secret=…` and
  `https://loveliveradio.ca/tcg/?presence_action=…`.
- Without the AAR, deep-link join/spectate still works; live Discord profile presence
  requires the Social SDK binary.

## Web / API

- Client: `client/js/discord-presence.js` (no-op off Android; uses Loveca Sim app id).
- Opt-in: Options → “Discord Rich Presence (Android)”.
- Opaque actions: `account.php` `presence_action_mint` / `presence_action_redeem`
  (table `tcg_presence_actions`). Join secrets never embed room IDs or seat tokens
  in Discord activity text.
