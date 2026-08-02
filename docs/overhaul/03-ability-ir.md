# Ability IR (data-driven rules)

## Reality

`cards.json` abilities are already `{ trigger, type, …params }`. Debt is **execution**: switch cases and `*_effects.php` batch modules—not inventing JSON from scratch.

## Goals

- Single dispatcher map: ability `type` → handler (grow `EffectRegistry`).
- Lint unknown / under-specified types in CI.
- New routine cards = JSON + shared handler + PHPUnit; no new batch file.

## Canonical shapes (examples)

```json
{ "trigger": "on_enter", "type": "add_from_waiting_room", "filter": "member", "count": 1 }
```

```json
{
  "trigger": "live_start",
  "type": "optional_discard_named",
  "names": ["…"],
  "exact_total": 3,
  "then": { "type": "live_score_bonus", "amount": 3 }
}
```

```json
{ "trigger": "activated", "type": "wait_self_draw_discard", "draw": 1, "discard": 1, "once_per_turn": true }
```

## Migration policy

1. Document required params per high-frequency `type`.
2. `scripts/validate_ability_ir.php` — fail on unknown type or missing required keys.
3. Move pure numeric / no-prompt effects into shared handlers first.
4. Prompt-heavy types stay in `PromptResolver` but register in the registry.
5. Unique set bosses = named handler registered once (not a new include tree).
6. Delete empty batch files only after skill audit + PHPUnit coverage.

## Out of scope (v1)

- Generic stack-based interpreter replacing all PHP.
- Porting engine to Node/Go.
