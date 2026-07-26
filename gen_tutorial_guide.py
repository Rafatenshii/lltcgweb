#!/usr/bin/env python3
"""Generate tutorial_guide.json — interactive beginner tutorial (no state snapshots).

Step order and dialogue follow the official 8-minute how-to-play video; see
docs/tutorials/OFFICIAL_8MIN_SCRIPT.md for the transcript and the beat map that
maps each video timestamp to the step ids below.
"""
from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
CARDS = json.loads((ROOT / "cards.json").read_text(encoding="utf-8"))


def card_type_en(card_no: str) -> str:
    for c in CARDS["cards"]:
        if c.get("card_no") == card_no:
            return c.get("card_type_en") or ""
    return ""


def variety_shuffle(pool: list[str], seed: str) -> list[str]:
    members, lives = [], []
    for cn in pool:
        (lives if card_type_en(cn) == "Live" else members).append(cn)
    import random

    rng = random.Random(seed)
    rng.shuffle(members)
    rng.shuffle(lives)
    out: list[str] = []
    mi = li = since = 0
    while mi < len(members) or li < len(lives):
        if li < len(lives) and (mi >= len(members) or since >= 3):
            out.append(lives[li])
            li += 1
            since = 0
        elif mi < len(members):
            out.append(members[mi])
            mi += 1
            since += 1
    return out


def build_scripted_deck(starter: list[str], slots: dict, seed: str) -> list[str]:
    cnt: dict[str, int] = {}
    for cn in starter:
        cnt[cn] = cnt.get(cn, 0) + 1
    size = len(starter)
    out: list[str | None] = [None] * size
    for idx_s, cn in slots.items():
        i = int(idx_s)
        if cnt.get(cn, 0) <= 0:
            raise RuntimeError(f"No copy of {cn} for slot {i}")
        out[i] = cn
        cnt[cn] -= 1
    pool: list[str] = []
    for cn in starter:
        while cnt.get(cn, 0) > 0:
            pool.append(cn)
            cnt[cn] -= 1
    pool = variety_shuffle(pool, seed)
    pi = 0
    for i in range(size):
        if out[i] is None:
            out[i] = pool[pi]
            pi += 1
    return out  # type: ignore[return-value]


def H(target: str, shape: str = "rounded", padding: int = 12) -> dict:
    return {"target": target, "shape": shape, "padding": padding}


P1_SLOTS = {
    "0": "PL!SP-sd1-019-SD",
    "1": "PL!SP-sd1-007-SD",
    "2": "PL!SP-sd1-023-SD",
    "4": "PL!SP-sd1-006-SD",
    "5": "PL!SP-sd1-008-SD",
    "8": "PL!SP-sd1-020-SD",
    "11": "PL!SP-sd1-026-SD",
    "15": "PL!SP-sd1-023-SD",
    "16": "PL!SP-sd1-016-SD",
    "17": "PL!SP-sd1-002-SD",
}

P2_SLOTS = {
    "0": "PL!-sd1-013-SD",
    "1": "PL!-sd1-020-SD",
    "2": "PL!-sd1-002-SD",
    "3": "PL!-sd1-010-SD",
    "4": "PL!-sd1-019-SD",
}

sd = CARDS["starter_decks"]
p1_main = build_scripted_deck(sd["liella"]["main_deck"], P1_SLOTS, "liella-tutorial-guide")
p2_main = build_scripted_deck(sd["muse"]["main_deck"], P2_SLOTS, "muse-tutorial-guide")

guide = {
    "version": 3,
    "title": "Beginner Tutorial",
    "initial_labels": {"p1": "You", "p2": "Player2"},
    "config": {
        "p1_deck": "liella",
        "p2_deck": "muse",
        "shuffle": False,
        "p1_main_order": p1_main,
        "p1_energy_order": list(sd["liella"]["energy_deck"]),
        "p2_main_order": p2_main,
        "p2_energy_order": list(sd["muse"]["energy_deck"]),
        "phase_timer_enabled": False,
        "coin_flip_winner": "p1",
    },
    "steps": [
        # 0:01 — greeting
        {
            "id": "welcome",
            "kind": "info",
            "dialogue": (
                "Hi! I'm **Shibuya Kanon**. I'll show you how to play the "
                "**Love Live! Official Card Game** — Loveca for short. Let's learn it "
                "together while we play a match!"
            ),
            "highlights": [H("playmat-column", padding=8)],
        },
        # 0:10 — three card types
        {
            "id": "card_types",
            "kind": "info",
            "dialogue": (
                "First, the types of cards. Loveca uses three: **Live** cards, "
                "**Member** cards, and **Energy** cards."
            ),
            "highlights": [H("hand-row"), H("my-energy")],
        },
        # 0:18 — Live cards, win by three successful Lives
        {
            "id": "goal",
            "kind": "info",
            "dialogue": (
                "Just as the name says, a **Live** card is a card for live shows. You "
                "perform live shows with these — and the first player to make **three** "
                "of them a success wins the game!"
            ),
            "highlights": [H("my-pips", shape="circle", padding=14)],
        },
        # 0:31 — Member cards and Energy cards
        {
            "id": "card_member_energy",
            "kind": "info",
            "dialogue": (
                "A **Member** card is your strongest tool for making a live show a "
                "success, and an **Energy** card is what you spend to bring a Member out "
                "onto the **Stage**."
            ),
            "highlights": [H("my-stage-center"), H("my-energy")],
        },
        # board tour — Stage
        {
            "id": "intro_stage",
            "kind": "info",
            "dialogue": (
                "This is your **Stage** — Left, Center and Right. Members you bring out "
                "stand here, and the **Hearts** they carry decide whether your live show "
                "succeeds."
            ),
            "highlights": [
                H("my-stage-left", padding=12),
                H("my-stage-center", padding=14),
                H("my-stage-right", padding=12),
            ],
        },
        # board tour — Live card storage
        {
            "id": "intro_live",
            "kind": "info",
            "dialogue": (
                "Beside the Stage is your **Live card storage**. It holds up to **3** "
                "cards face down during the Live Phase — you can see your own, but your "
                "opponent's stay hidden."
            ),
            "highlights": [
                H("my-live-0", padding=10),
                H("my-live-1", padding=12),
                H("my-live-2", padding=10),
            ],
        },
        # board tour — decks, Waiting Room, success storage
        {
            "id": "zones_storage",
            "kind": "info",
            "dialogue": (
                "Around the edge sit your **main deck storage**, your **energy deck**, "
                "the **Waiting Room** where used cards rest, and the **Success live card "
                "storage** that counts your wins."
            ),
            "highlights": [
                H("my-deck-pile", padding=8),
                H("my-nrg-deck-pile", padding=8),
                H("my-wait-pile", padding=8),
                H("my-pips", padding=8),
            ],
        },
        # 0:43 — shuffle the main deck
        {
            "id": "prep_deck",
            "kind": "info",
            "dialogue": (
                "Now that you know the cards, we can begin! Both players shuffle their "
                "**main deck** and place it in the main deck storage."
            ),
            "highlights": [H("my-deck-pile", padding=10)],
        },
        # 0:54 — decide who plays first (one bubble through flip + choose)
        {
            "id": "coin",
            "kind": "action",
            "dialogue": (
                "A **coin flip** decides who chooses first. When you win, tap "
                "**I'll go first**."
            ),
            "highlights": [H("overlay-coin")],
            "goal": {"type": "choose_first_player", "pick": "self"},
            "spotlightDim": "none",
            "bubbleAnchor": "overlay-coin",
        },
        # 1:04–1:35 — opening hand and the exchange
        {
            "id": "mulligan",
            "kind": "action",
            "dialogue": (
                "You draw **6** cards. Set any of them aside, and you'll draw new ones. "
                "Let's **Replace 1 card**."
            ),
            "highlights": [H("mulligan-overlay")],
            "goal": {"type": "mulligan", "replace": ["PL!SP-sd1-012-SD"]},
            "mulligan_replace": ["PL!SP-sd1-012-SD"],
            "spotlightDim": "none",
            "bubbleAnchor": "overlay-mull",
        },
        # 1:35 — three energy face up
        {
            "id": "prep_energy",
            "kind": "info",
            "dialogue": (
                "That's the hand exchange done. Each player then places **3** energy "
                "cards from the energy deck face up on the **energy deck storage**. "
                "You're all set — let's play!"
            ),
            "highlights": [H("my-energy", padding=10)],
        },
        # 1:44–1:56 — Active Phase
        {
            "id": "phase_active",
            "kind": "info",
            "dialogue": (
                "The turn opens with the **Active Phase**: every energy card lying "
                "sideways stands upright again. From the second turn on there will be "
                "more of them waiting to stand."
            ),
            "highlights": [H("my-energy", padding=10)],
        },
        # 2:10 — Energy Phase
        {
            "id": "phase_energy",
            "kind": "info",
            "dialogue": (
                "Then the **Energy Phase** — one more energy card moves from the energy "
                "deck onto the energy deck storage."
            ),
            "highlights": [H("my-nrg-deck-pile", padding=8), H("my-energy", padding=10)],
        },
        # 2:18 — Draw Phase
        {
            "id": "phase_draw",
            "kind": "info",
            "dialogue": (
                "Then the **Draw Phase** — draw one card from the main deck and add it to "
                "your hand."
            ),
            "highlights": [H("my-deck-pile", padding=8), H("hand-row")],
        },
        # 2:18–2:36 — Main Phase
        {
            "id": "main_intro",
            "kind": "info",
            "dialogue": (
                "Now the **Main Phase**. There are two things you can do: bring Members "
                "out onto the Stage, and use the effects of Members already there. Both, "
                "in any order, as many times as you like!"
            ),
            "highlights": [H("hand-row"), H("my-energy")],
        },
        # 2:45–2:54 — cost and playing a Member
        {
            "id": "play_member",
            "kind": "action",
            "dialogue": (
                "The number in a Member's **upper right corner** is her cost. Turn that "
                "many energy cards sideways and she steps onto the Stage. **Shiki Wakana** "
                "costs **2** — tap her in your hand, then tap the **Center** slot, or drag "
                "her there."
            ),
            "highlights": [H("my-stage-center", padding=10)],
            "spotlightDim": "none",
            "select_hand": "PL!SP-sd1-019-SD",
            "goal": {"type": "play_member", "card_no": "PL!SP-sd1-019-SD", "slot": "center"},
            "bubblePlacement": "playmat-left",
        },
        {
            "id": "energy_tip",
            "kind": "info",
            "dialogue": (
                "The energy you spent rests **sideways** until your next Active Phase. And "
                "look at Shiki on Stage — the **Hearts** in her upper left corner are what "
                "will carry your live show."
            ),
            "highlights": [H("my-energy"), H("sb-my-hearts")],
        },
        # 3:03–3:15 — baton pass
        {
            "id": "baton_pass",
            "kind": "info",
            "dialogue": (
                "Here's a piece of advice: there's another way to bring a Member out — the "
                "**baton pass**. Send a Member already on Stage to the **Waiting Room**, "
                "and her cost counts as paid for a new Member of the same cost."
            ),
            "highlights": [H("my-stage-center"), H("my-wait-pile", padding=8)],
        },
        # 3:24 — End Phase, opponent's turn
        {
            "id": "end_main",
            "kind": "action",
            "dialogue": (
                "After the Main Phase comes the **End Phase**. Press **End Main Phase** — "
                "then your opponent takes a turn in exactly the same order."
            ),
            "highlights": [H("phase-action-bar")],
            "spotlightDim": "none",
            "goal": {"type": "end_main"},
            "cpu_after": [
                {"type": "play_member", "card_no": "PL!-sd1-002-SD", "slot": "center"},
                {"type": "end_main"},
            ],
        },
        # 3:34–3:48 — Live Card Set Phase
        {
            "id": "live_intro",
            "kind": "info",
            "dialogue": (
                "Now the **Live Phase**, starting with the **Live Card Set Phase**. Choose "
                "**0 to 3** cards from your hand, place them face down in Live card "
                "storage, then draw that many cards from your main deck."
            ),
            "highlights": [H("my-live-0"), H("my-live-1"), H("my-live-2")],
            "hold_cpu": True,
        },
        {
            "id": "set_live",
            "kind": "action",
            "dialogue": (
                "Select **WE WILL!!** from your hand, then press **Set Live Cards** to put "
                "it face down. You draw one card for each card you set!"
            ),
            "highlights": [],
            "spotlightDim": "none",
            "select_hand": "PL!SP-sd1-023-SD",
            "goal": {"type": "end_live_set"},
            "bubblePlacement": "playmat-left",
            "hold_cpu": True,
        },
        # 3:55–4:02 — Performance Phase opens
        {
            "id": "perf_intro",
            "kind": "info",
            "dialogue": (
                "Next is the **Performance Phase**: the cards you set turn face up. "
                "Anything that isn't a Live card goes to the **Waiting Room**, and if a "
                "Live card is there, the live show starts!"
            ),
            "highlights": [H("my-live-0"), H("my-wait-pile", padding=8)],
            "hold_cpu": True,
        },
        # 4:12–4:26 — required hearts
        {
            "id": "required_hearts",
            "kind": "info",
            "dialogue": (
                "To see whether a live show succeeds, check the **required hearts** on the "
                "Live card. **WE WILL!!** asks for one red heart, one purple heart and one "
                "grey heart — three required hearts in total."
            ),
            "highlights": [H("my-live-0")],
            "hold_cpu": True,
        },
        # 4:40–4:58 — basic hearts
        {
            "id": "basic_hearts",
            "kind": "info",
            "dialogue": (
                "Now look at the Members on your Stage. The upright hearts in their upper "
                "left corner are **basic hearts**. Compare them with the required hearts: "
                "enough and the live show succeeds, short and it fails."
            ),
            "highlights": [H("my-stage-center"), H("sb-my-hearts")],
            "hold_cpu": True,
        },
        # 5:12–5:49 — Blade and Yell
        {
            "id": "yell_blade",
            "kind": "info",
            "dialogue": (
                "Short of hearts? You still have support. The round penlight icon on a "
                "Member is a **Blade**, and during the Performance Phase you flip one card "
                "from your main deck for every Blade on your Stage. That flip is a **Yell**, "
                "and any sideways **blade heart** on the flipped card joins your total."
            ),
            "highlights": [H("sb-my-yell"), H("my-deck-pile", padding=8)],
            "hold_cpu": True,
            "cpu_after": [
                {"type": "set_live_cards", "card_no": "PL!-sd1-019-SD"},
                {"type": "end_live_set"},
            ],
        },
        # 5:49–6:09 — watch it resolve
        {
            "id": "perf_watch",
            "kind": "watch",
            "dialogue": (
                "Here we go — watch the hearts come in. Your Yells resolve first, then your "
                "opponent runs their Performance Phase the very same way."
            ),
            "highlights": [H("perf-spectacle")],
            "goal": {"type": "live_judge_reached"},
            "spotlightDim": "light",
        },
        # 6:16–6:39 — Live Win/Loss Check
        {
            "id": "success",
            "kind": "info",
            "dialogue": (
                "After both Performances comes the **Live Win/Loss Check**. If only one "
                "player succeeded, that player moves one card into their **Success live "
                "card storage**. If both succeeded, the **score** in the Live card's upper "
                "right corner decides — only the higher score scores a card. Three cards "
                "there wins the game!"
            ),
            "highlights": [H("my-pips", shape="circle", padding=14)],
        },
        # 6:46–6:58 — tie rule
        {
            "id": "tie_rule",
            "kind": "info",
            "dialogue": (
                "If the scores are exactly tied, both players place a card — except that a "
                "player who would end the game on that tie doesn't get to place one."
            ),
            "highlights": [H("my-pips", padding=10), H("opp-pips", padding=10)],
        },
        # 7:07–7:25 — turn order can switch
        {
            "id": "turn_order",
            "kind": "info",
            "dialogue": (
                "One last point: the turn order can change mid-game. If only one player "
                "placed a card in the Live Win/Loss Check, that player takes the first turn "
                "next round — so check before you play."
            ),
            "highlights": [H("pbadge", padding=8)],
        },
        # 7:25–7:44 — wrap-up
        {
            "id": "outro",
            "kind": "info",
            "dialogue": (
                "That's the basic flow of Loveca: **Main → Live → Performance**. When "
                "you're ready, try **Practice vs CPU** — skills and baton pass come up "
                "naturally as you play. Thanks for learning with me!"
            ),
            "highlights": [],
        },
    ],
}

out = ROOT / "tutorial_guide.json"
out.write_text(json.dumps(guide, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"Wrote {out} ({len(guide['steps'])} steps)")
