#!/usr/bin/env python3
"""Validate icon tags in cards.json English rules text."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CARDS_PATH = ROOT / "cards.json"

ALLOWED_TAGS = {
    "energy",
    "blade",
    "pinkH",
    "redH",
    "yellowH",
    "greenH",
    "blueH",
    "purpleH",
    "anyH",
    "allH",
    "pinkBH",
    "redBH",
    "yellowBH",
    "greenBH",
    "blueBH",
    "purpleBH",
    "allBH",
    "score+1",
    "card+1",
}

TAG_RE = re.compile(r"<([^>/]+)>")
BRACKET_KW_RE = re.compile(
    r"\[(On Enter|On Leave|On Play|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per turn|Twice per turn|Automatic|Auto|Center)\]",
    re.IGNORECASE,
)
MIXED_BLADE_RE = re.compile(r"\+?\d+\s*Blade.*<blade>|<blade>.*\+?\d+\s*Blade", re.IGNORECASE)
MIXED_ENERGY_RE = re.compile(r"Pay\s+\d+\s+Energy.*<energy>|<energy>.*Pay\s+\d+\s+Energy", re.IGNORECASE)

NOTE_CARDS = {
    "PL!-bp5-014-N",
    "PL!-bp6-005-P",
    "PL!-bp6-005-R",
    "PL!N-sd2-019-SD2",
    "PL!SP-pb2-002-PP",
    "PL!SP-pb2-002-R",
    "PL!SP-pb2-022-P＋",
    "PL!SP-pb2-023-N",
    "PL!SP-pb2-026-N",
    "PL!SP-pb2-027-N",
    "PL!SP-pb2-030-N",
    "PL!SP-pb2-032-N",
    "PL!S-pb1-003-P＋",
    "PL!S-pb1-003-R",
    "PL!S-sd1-007-SD",
}


def main() -> int:
    data = json.loads(CARDS_PATH.read_text(encoding="utf-8"))
    errors: list[str] = []
    warnings: list[str] = []
    tagged_cards = 0

    for card in data.get("cards") or []:
        no = card.get("card_no") or "?"
        text = card.get("text") or ""
        if not text:
            continue
        if "<" in text:
            tagged_cards += 1
        for m in TAG_RE.finditer(text):
            tag = m.group(1)
            if tag not in ALLOWED_TAGS:
                errors.append(f"{no}: unknown tag <{tag}>")
        # stray < not part of allowed tag
        stripped = TAG_RE.sub("", text)
        if "<" in stripped or ">" in stripped:
            errors.append(f"{no}: malformed angle brackets in text")
        if MIXED_BLADE_RE.search(text):
            warnings.append(f"{no}: mixed prose Blade and <blade> tag")
        if MIXED_ENERGY_RE.search(text):
            warnings.append(f"{no}: mixed prose Energy and <energy> tag")

    for no in NOTE_CARDS:
        found = next((c for c in data["cards"] if c.get("card_no") == no), None)
        if not found:
            errors.append(f"{no}: note-card missing from catalog")
        elif not (found.get("text") or "").strip():
            errors.append(f"{no}: note-card has empty text")

    print(f"Checked {len(data.get('cards') or [])} cards; {tagged_cards} with inline tags")
    if warnings:
        print(f"WARN ({len(warnings)}):")
        for w in warnings[:20]:
            print(f"  {w}")
        if len(warnings) > 20:
            print(f"  ... and {len(warnings) - 20} more")
    if errors:
        print(f"ERROR ({len(errors)}):")
        for e in errors[:40]:
            print(f"  {e}")
        if len(errors) > 40:
            print(f"  ... and {len(errors) - 40} more")
        return 1
    print("OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
