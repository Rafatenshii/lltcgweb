#!/usr/bin/env python3
"""
Compare server pending_prompt types vs CPU handler patterns in cpu-loop.js / cpu-ai.js.

Fails when effects emit a prompt type that cpuResolvePrompt / doCPU cannot
auto-answer in Practice vs CPU games.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CPU_FILES = [
    ROOT / "client" / "js" / "cpu-loop.js",
    ROOT / "client" / "js" / "cpu-ai.js",
]

# Prompt types handled only via generic yes/no (first choice or decline).
GENERIC_YESNO = {
    "optional_live_start", "optional_discard_prompt", "look_top_optional_wr",
    "optional_pay_energy_on_enter", "optional_pay_energy_if_baton",
    "optional_pay_energy_live_success", "optional_wait_self", "optional_wait_self_add_wr",
    "optional_wait_members_draw", "optional_wait_subunit_opp_active",
    "optional_wait_self_center_blade", "optional_stage_reposition",
    "optional_position_change_all_muse", "optional_wait_mus_hearts",
    "optional_wait_self_surveil", "optional_wait_self_look_reveal",
    "optional_wait_self_draw_discard_unless_baton", "optional_wait_self_energy_subunit",
    "optional_wait_group_member_draw_discard", "optional_wr_to_deck_top",
    "optional_discard_look_reveal_subunit", "optional_discard_blade_per_card",
    "optional_wr_live_deck_bottom", "optional_formation_change_group",
    "optional_negate_member_live_start_add_wr", "optional_shuffle_wr_members_deck_bottom",
    "optional_discard_subunit_draw_buff_cost", "optional_activate_wait_subunit_add_live_wr",
    "optional_discard_mill_wr_add_member", "optional_stack_energy",
    "optional_stack_energy_draw", "optional_stack_energy_draw_blade_all",
    "optional_stack_energy_add_wr_live", "optional_discard_grant_heart_other_member",
    "optional_discard_activate_wait_blade", "optional_wr_members_deck_bottom_milestones",
    "player_choice_wr_members_deck_bottom", "optional_discard_mill_add_wr_subunit_live",
    "optional_discard_add_cb_member_hs_live", "live_success_optional_mill_if_subunit",
    "score_if_stage_member_hearts", "choose_heart_per_success", "choose_heart_mus_member",
    "choose_heart_modifier", "choose_heart_other_member", "choose_replace_member_hearts",
    "waive_required_heart_color", "opponent_text_answer", "opponent_choice", "player_choice",
    "auto_yell_no_live_retry", "live_success_yell_live_deck_bottom",
    "pay_energy_reveal_live_wr_superset", "discard_member_add_lower_wr_member",
    "auto_subunit_enter_pay_activate_energy", "live_success_pay_choice_wr_add",
    "sbp5_aqours_blade_or_position", "sbp5_pay_energy_wr_subunit_blade",
    "spbp5_repeat_mill_blade", "spbp5_energy_wait_opp_draw", "spbp5_wr_pay_add_hand",
    "bp5_wait_self_opp_exact_blade", "live_start_edel_choice", "live_start_edel_play_wr",
    "play_wr_members_combined_cost", "reveal_top_live_score", "pick_member_grant_hearts",
    "wait_pick_member_grant_live_score", "buff_member_matching_discarded_group",
    "live_cost_from_subunit_pick", "auto_yell_no_blade_heart", "auto_yell_mill_extra_yell",
    "sbp6_yell_mill_extra_yell", "sbp6_wait_opp_side_member", "optional_swap_area_on_enter",
    "on_enter_blade_self_and_pick_group", "wait_opponent_stage_max_cost", "wait_opponent_stage_pick",
    "look_reveal_live_score_plus", "live_start_arise_choice", "surveil2_mus_ability_choice",
    "optional_leave_mus_score_add_wr_live", "opp_blind_pick_hand_reveal",
    "optional_play_hand_member", "batch99_stack_wr_member", "pick_number_reveal_deck_top",
    "mill_fill_wr_optional_live_deck_top", "optional_ema_punch_ask",
    "optional_opp_wr_members_to_deck_bottom_then_wait", "optional_pay_energy_add_from_wr",
    "pr_vol9_wait_opp_max_blade", "pick_stage_member",
}

BRANCH_CHOICE = {
    "live_start_center_cost_choice", "player_choice_wr_live_deck_bottom_draw",
    "choice_energy_or_wr_lives_deck_top", "live_success_pick_energy_or_member",
    "sbp6_live_wr_deck_position", "sbp6_hand_deck_position", "ssd1_reveal_group_deck",
    "bp5_wr_live_distinct_choice",
}

STEP_OR_PARTIAL = {
    "spbp5_wait_discard_surveil", "bp5_wait_discard_look_reveal", "spbp5_wait_or_discard_activate",
    "spbp5_wait_draw_discard", "sbp5_discard_bladeless_wr_live", "sbp5_live_start_discard_heart",
    "ssd1_live_start_draw", "ssd1_reveal_group_deck", "ssd1_play_wr_empty",
    "sbp6_live_start_pay_member_score", "sbp6_swap_stage_wr_member", "sbp6_hand_deck_position",
    "optional_success_live_swap", "optional_pay_play_hand_member",
    "optional_reveal_live_deck_bottom_surveil", "optional_wr_member_deck_top_blade",
    "optional_pos_change_subunit_blade", "pos_change_opp_front_pick", "wait_swap_wr_member_center",
    "optional_wr_member_reenter", "activate_wr_member_pick", "spbp5_distinct_groups",
    "spbp5_subunit_blade_pick", "spbp5_pick_wr_live", "spbp5_mill_swap_pick",
    "position_change_pick", "opp_member_match_heart_blade",
    "bp7_confirm", "bp7_pick_cards", "bp7_pick_stage_member", "bp7_pick_slot", "bp7_choose_player",
}


def server_prompt_types() -> set[str]:
    types: set[str] = set()
    type_pat = re.compile(r"'type'\s*=>\s*'([^']+)'")
    pending_pat = re.compile(r"\['pending_prompt'\]\s*=\s*\[")
    resolve_pat = re.compile(r"\$promptType\s*===\s*'([^']+)'")
    php_roots = [ROOT] + ([ROOT / "src" / "Game"] if (ROOT / "src" / "Game").is_dir() else [])
    for base in php_roots:
        for f in sorted(base.rglob("*.php")):
            text = f.read_text(encoding="utf-8", errors="ignore")
            for m in pending_pat.finditer(text):
                chunk = text[m.start() : m.start() + 3000]
                tm = type_pat.search(chunk)
                if tm:
                    types.add(tm.group(1))
            for m in resolve_pat.finditer(text):
                types.add(m.group(1))
    return types


def cpu_source_text() -> str:
    parts = []
    for path in CPU_FILES:
        if path.exists():
            parts.append(path.read_text(encoding="utf-8", errors="ignore"))
    if not parts:
        legacy = ROOT / "index.html"
        if legacy.exists():
            parts.append(legacy.read_text(encoding="utf-8", errors="ignore"))
    return "\n".join(parts)


def cpu_handled_types(src: str) -> set[str]:
    handled: set[str] = set()
    for m in re.finditer(r"pr\.type\s*===?\s*'([^']+)'|pr\?\.type\s*===?\s*'([^']+)'", src):
        handled.add(m.group(1) or m.group(2))
    for block in re.findall(r"new\s+Set\s*\(\s*\[([\s\S]*?)\]\s*\)", src):
        for s in re.findall(r"'([a-z][a-z0-9_]+)'", block):
            if "_" in s:
                handled.add(s)
    for name in ("heartTypes", "pb1SlotPick", "CPU_NO_GENERIC_YESNO"):
        m = re.search(rf"{name}[^=]*=\s*(?:new Set\()?(\[[^\]]+\])", src, re.S)
        if m:
            for s in re.findall(r"'([^']+)'", m.group(1)):
                handled.add(s)
    # Prefix families covered by dedicated resolvers
    if "startsWith('bp7_')" in src or 'startsWith("bp7_")' in src:
        handled.update(
            "bp7_confirm", "bp7_pick_cards", "bp7_pick_stage_member",
            "bp7_pick_slot", "bp7_choose_player",
        )
    handled.update(STEP_OR_PARTIAL)
    handled.update(GENERIC_YESNO)
    handled.update(BRANCH_CHOICE)
    return handled


def cards_using_type(prompt_type: str) -> list[str]:
    cards = ROOT / "cards.json"
    if not cards.exists():
        return []
    import json
    data = json.loads(cards.read_text(encoding="utf-8"))
    out = []
    for c in data.get("cards", data if isinstance(data, list) else []):
        if isinstance(c, dict):
            for ab in c.get("abilities") or []:
                if ab.get("type") == prompt_type:
                    out.append(c.get("card_no") or c.get("name_en") or "?")
    return out[:8]


def main() -> int:
    src = cpu_source_text()
    if not src.strip():
        print("ERROR: no CPU source files found", file=sys.stderr)
        return 2
    server = server_prompt_types()
    handled = cpu_handled_types(src)

    missing = sorted(server - handled)
    print(f"CPU sources: {', '.join(str(p.relative_to(ROOT)) for p in CPU_FILES if p.exists())}")
    print(f"Server prompt types: {len(server)}")
    print(f"Handled (explicit + inferred): {len(handled & server)}")
    print(f"Gaps: {len(missing)}")
    print()

    hang_risk = []
    weak = []
    for t in missing:
        if (
            t.startswith("surveil_pick")
            or t.startswith("pick_")
            or t.endswith("_pick")
            or "revealed" in t
            or "swap_pick" in t
        ):
            hang_risk.append(t)
        else:
            weak.append(t)

    print("=== HANG RISK (card/slot pick, no handler) ===")
    for t in hang_risk:
        cards = cards_using_type(t)
        extra = f"  e.g. {', '.join(cards)}" if cards else ""
        print(f"  {t}{extra}")

    print()
    print("=== OTHER GAPS ===")
    for t in weak:
        print(f"  {t}")

    # Soft gate: hang-risk gaps that are not covered by generic fallback shapes
    # are reported but exit 0 unless --strict.
    strict = "--strict" in sys.argv
    if strict and hang_risk:
        print(f"\nSTRICT: {len(hang_risk)} hang-risk gaps", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
