#!/usr/bin/env python3
"""Insert display line breaks in card rules text (skill blocks + bullet lists)."""

from __future__ import annotations

import re

# Inline modifiers that share a line with the trigger bracket (never start a new line).
MODIFIER_LABELS = frozenset({
    "Once per turn", "Once per Turn", "Twice per turn", "Twice per Turn",
    "Center", "Left Side", "Right Side",
    "Una vez por turno", "Dos veces por turno", "Centro", "Lado izquierdo", "Lado derecho",
    "Uma vez por turno", "Duas vezes por turno", "Lado Esquerdo", "Lado Direito",
    "턴에 1회", "턴당 2회", "센터", "왼쪽", "오른쪽",
    "每回合1次", "每回合2次", "中央", "左侧", "右侧",
    "เทิร์นละ 1 ครั้ง", "เทิร์นละ 2 ครั้ง", "เซ็นเตอร์", "ฝั่งซ้าย", "ฝั่งขวา",
    "ターン1回", "ターン2回", "センター", "左サイド", "右サイド",
})

BRACKET_RE = re.compile(r"\[([^\]]+)\]")
BULLET_BREAK_RE = re.compile(r'(?<=[.!?"\'\）\)])\s*•')
SLASH_BREAK_RE = re.compile(r"(?<=[/])\s*(?=\[)")


def _is_modifier(label: str) -> bool:
    return label.strip() in MODIFIER_LABELS


def insert_card_text_line_breaks(text: str) -> str:
    """Heuristic line breaks before primary skill brackets and bullet items."""
    if not text or not text.strip():
        return text or ""

    s = BULLET_BREAK_RE.sub("\n•", text)
    s = SLASH_BREAK_RE.sub("\n", s)

    breaks: set[int] = set()
    for m in BRACKET_RE.finditer(s):
        label = m.group(1)
        if _is_modifier(label):
            continue
        pos = m.start()
        if pos == 0:
            continue
        if s[pos - 1] == "\n":
            continue
        prev = s[pos - 1]
        before = s[:pos].rstrip()
        if before.endswith("]"):
            # [Activated] [Once per turn] — modifier handled above; another primary after ] is rare.
            if prev not in ".!?。/":
                continue
        if prev in ".!?。/":
            breaks.add(pos)

    if not breaks:
        return re.sub(r"\n{3,}", "\n\n", s).strip()

    out: list[str] = []
    for i, ch in enumerate(s):
        if i in breaks:
            out.append("\n")
        out.append(ch)
    result = "".join(out)
    return re.sub(r"\n{3,}", "\n\n", result).strip()


def transfer_line_breaks_from_reference(reference: str, target: str) -> str | None:
    """Copy newline positions from a curated locale (e.g. text_es) by bracket index."""
    if not target or not reference:
        return None
    ref_brackets = [m.start() for m in BRACKET_RE.finditer(reference)]
    tgt_brackets = [m.start() for m in BRACKET_RE.finditer(target)]
    if not ref_brackets or len(ref_brackets) != len(tgt_brackets):
        return None

    breaks: set[int] = set()
    for i in range(1, len(ref_brackets)):
        ref_pos = ref_brackets[i]
        if ref_pos > 0 and reference[ref_pos - 1] == "\n":
            breaks.add(tgt_brackets[i])

    out: list[str] = []
    for i, ch in enumerate(target):
        if i in breaks:
            out.append("\n")
        out.append(ch)
    result = "".join(out)

    if "\n•" in reference and "•" in result and result.count("\n•") < reference.count("\n•"):
        result = BULLET_BREAK_RE.sub("\n•", result)
        if result.count("\n•") < reference.count("\n•"):
            result = re.sub(r"(?<!\n)•", "\n•", result)

    return re.sub(r"\n{3,}", "\n\n", result).strip()


def format_card_rules_text(text: str, reference: str | None = None) -> str:
    """Prefer reference-aligned breaks; fall back to heuristic."""
    if reference:
        transferred = transfer_line_breaks_from_reference(reference, text)
        if transferred is not None:
            return transferred
    return insert_card_text_line_breaks(text)
