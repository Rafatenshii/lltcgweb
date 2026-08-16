import { ActivityType } from 'premid'

const presence = new Presence({
  clientId: '1538239969976647740', // Loveca Sim (same Discord app as Android Rich Presence)
})

enum ActivityAssets {
  Logo = 'https://loveliveradio.ca/tcg/downloads/loveca-icon-192.png',
}

interface LovecaPresenceSnapshot {
  kind?: string
  details?: string
  state?: string
  largeImage?: string
  largeText?: string
  joinable?: boolean
  actionLabel?: string
  joinUrl?: string
  startTimestampMs?: number
}

interface LovecaPresenceApi {
  getSnapshot?: () => LovecaPresenceSnapshot | null
  refresh?: (force?: boolean) => void
}

function getApi(): LovecaPresenceApi | null {
  return (window as unknown as { LLTCG_DISCORD_PRESENCE?: LovecaPresenceApi })
    .LLTCG_DISCORD_PRESENCE ?? null
}

presence.on('UpdateData', async () => {
  const [privacy, showTimestamps, showButtons] = await Promise.all([
    presence.getSetting<boolean>('privacy'),
    presence.getSetting<boolean>('timestamps'),
    presence.getSetting<boolean>('buttons'),
  ])

  const api = getApi()
  const snap = api?.getSnapshot?.()

  if (!snap || !snap.details) {
    presence.clearActivity()
    return
  }

  const presenceData: PresenceData = {
    type: ActivityType.Playing,
    largeImageKey: snap.largeImage || ActivityAssets.Logo,
    largeImageText: snap.largeText || 'Loveca Sim',
    details: privacy ? 'In Loveca' : snap.details,
  }

  if (!privacy && snap.state)
    presenceData.state = snap.state

  if (showTimestamps && snap.startTimestampMs)
    presenceData.startTimestamp = Math.floor(snap.startTimestampMs / 1000)

  if (
    !privacy
    && showButtons
    && snap.joinable
    && snap.joinUrl
    && /^https:\/\/loveliveradio\.ca\/tcg\/\?presence_action=[a-f0-9]{32,96}$/i.test(snap.joinUrl)
  ) {
    const label = (snap.actionLabel || 'Join').slice(0, 32)
    presenceData.buttons = [
      {
        label,
        url: snap.joinUrl,
      },
    ]
  }

  presence.setActivity(presenceData)
})
