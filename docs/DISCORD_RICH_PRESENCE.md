# Discord Rich Presence (Android Loveca)

Android-only Discord Social SDK Rich Presence. The Capacitor shell lives in the private
sibling repo `lltcg-android`. Website Discord OAuth (`identify` via Wrapped) is **not**
enough for mobile presence — Discord requires Social SDK account linking.

## Discord Developer Portal checklist

Application: Love Live Radio / Loveca (`client_id` `1439716818058088612`).

1. Enable **Social SDK / Social Layer** for the application.
2. OAuth2 redirect URI (mobile PKCE):
   `discord-1439716818058088612:/authorize/callback`
3. **Deep link URL** (General / Social Layer):
   `https://loveliveradio.ca/tcg`
   Discord appends `/_discord/join?secret=…` when a friend accepts Join.
4. Upload Rich Presence art assets (large image keys used by the client:
   `loveca`, `loveca_ranked`, `loveca_casual`, `loveca_cpu`, `loveca_booster`, `loveca_sticker`).
5. Download `discord_partner_sdk.aar` and place it at:
   `lltcg-android/android/app/libs/discord_partner_sdk.aar`
   (gitignored — never commit the AAR).

## Android shell

See `lltcg-android/README.md` § Discord Rich Presence.

- App Links / intent filters open Loveca for
  `https://loveliveradio.ca/tcg/_discord/join?secret=…` and
  `https://loveliveradio.ca/tcg/?presence_action=…`.
- Without the AAR, deep-link join/spectate still works; live Discord profile presence
  requires the Social SDK binary.

## Web / API

- Client: `client/js/discord-presence.js` (no-op off Android).
- Opt-in: Options → “Discord Rich Presence (Android)”.
- Opaque actions: `account.php` `presence_action_mint` / `presence_action_redeem`
  (table `tcg_presence_actions`). Join secrets never embed room IDs or seat tokens
  in Discord activity text.
