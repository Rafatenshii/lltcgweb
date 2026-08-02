# Client components

## Goals

- Zone UI owns its CSS/animation without touching networking or rules.
- `state-apply.js` remains the only path that commits server boards to UI.
- Components never call `api.php` directly.

## State → DOM contract

```
SSE/poll → onState → applyStateUpdate → view-model snapshot → components
                                              ↓
                                    UI events (ll-*) → sendAct / handlers
```

- **Input:** immutable snapshots (`stage`, `hand`, `liveZone`, `log`, `promptMeta`).
- **Output:** CustomEvents on the element or `document` (`ll-card-click`, `ll-slot-pick`, `ll-hand-confirm`).
- Global `G` stays the session bag for sync flags; components must not mutate `G.gameState` except via approved apply path.

## Zone ownership

| Element | Owns | Does not own |
|---------|------|----------------|
| `<ll-stage-board>` | Stage slots, enter/leave CSS | Action legality |
| `<ll-hand-zone>` | Hand layout / pick chrome | Deck/WR piles logic |
| `<ll-live-zone>` | Live hearts / score display | Live Start resolution |
| `<ll-prompt-host>` | Hosts `prompt-renderer` overlay | Prompt server schema |
| `<ll-side-panel>` | Log, stamps mount, UI scale chrome | Stamp network protocol |

## Extraction order

1. CSS → `client/css/` (tokens, board, prompts, hub).
2. `renderGame` / stage helpers → `client/js/board-render.js` (callable from shell).
3. Wrap DOM regions with custom elements that call into those helpers.
4. Thin `index.html` to mount points + script tags.

## Testing

- Playwright: hub visible; open/debug room paints stage + hand.
- Manual: UI text scale + spectacle still gates apply correctly.
