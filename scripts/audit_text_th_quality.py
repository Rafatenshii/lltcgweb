#!/usr/bin/env python3
"""Audit text_th for English skill brackets and residual English prose."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"

EN_BRACKET = re.compile(
    r"\[(?:On Enter|On Leave|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per [Tt]urn|Twice per [Tt]urn|Automatic|Auto|Center|Yell|On Play|Left Side|Right Side)\]"
)
KEEP = re.compile(
    r"\b(?:Blade|Yell|Wait|Live|Ver|μ's|Aqours|Liella!?|Nijigasaki|Hasunosora|QU4RTZ|R3BIRTH|"
    r"DOLLCHESTRA|CatChu!?|Saint Snow|START:DASH!!|WE WILL!!|CPU)\b",
    re.I,
)


def main() -> int:
    cards = json.loads(CARDS.read_text(encoding="utf-8"))["cards"]
    bracket_leaks = []
    residual = []
    for c in cards:
        th = (c.get("text_th") or "").strip()
        if not th:
            continue
        if EN_BRACKET.search(th):
            bracket_leaks.append(c.get("card_no"))
            continue
        scrub = re.sub(r'"[^"]*"', "", th)
        scrub = re.sub(r"\[[^\]]+\]", "", scrub)
        scrub = KEEP.sub("", scrub)
        # Flag clear English connectors that should not survive MT into Thai
        if re.search(r"\b(?:the|and|from|into|your|this|that|with|for|Member|Energy|Waiting)\b", scrub, re.I):
            residual.append(c.get("card_no"))
    print(f"text_th audit: bracket_leaks={len(bracket_leaks)} residual_en={len(residual)}")
    if bracket_leaks[:10]:
        print(" bracket sample:", ", ".join(str(x) for x in bracket_leaks[:10]))
    if residual[:10]:
        print(" residual sample:", ", ".join(str(x) for x in residual[:10]))
    return 1 if bracket_leaks or residual else 0


if __name__ == "__main__":
    raise SystemExit(main())
