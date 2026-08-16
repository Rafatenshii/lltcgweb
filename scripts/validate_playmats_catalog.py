#!/usr/bin/env python3
"""Validate playmats_catalog.json coverage and asset files."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "playmats_catalog.json"
MANIFEST = ROOT / "Playmats" / "_meta" / "manifest.json"

# Minimum expected unique products from curated original + V2 lists.
EXPECTED_MIN = 110


def main() -> int:
    if not CATALOG.is_file():
        print(f"Missing {CATALOG}", file=sys.stderr)
        return 1
    data = json.loads(CATALOG.read_text(encoding="utf-8"))
    items = data.get("items") if isinstance(data, dict) else None
    if not isinstance(items, list) or not items:
        print("Empty catalog", file=sys.stderr)
        return 1

    errors = []
    seen = set()
    for row in items:
        if not isinstance(row, dict):
            errors.append("non-object item")
            continue
        sid = str(row.get("id") or "")
        if not sid or sid in seen:
            errors.append(f"bad/dup id: {sid!r}")
            continue
        seen.add(sid)
        group = str(row.get("group") or "")
        idol = str(row.get("idol") or "")
        src = str(row.get("src") or "")
        if group not in ("Muse", "Aqours", "Nijigasaki", "Liella", "Hasunosora", "Other"):
            errors.append(f"{sid}: unexpected group {group!r}")
        if not idol:
            errors.append(f"{sid}: empty idol")
        if not src.startswith("assets/playmats/"):
            errors.append(f"{sid}: bad src {src!r}")
        path = ROOT / src.replace("\\", "/")
        if not path.is_file():
            errors.append(f"{sid}: missing file {path}")

    if len(items) < EXPECTED_MIN:
        errors.append(f"catalog has {len(items)} items; expected >= {EXPECTED_MIN}")

    if MANIFEST.is_file():
        man = json.loads(MANIFEST.read_text(encoding="utf-8"))
        m_items = man.get("items") if isinstance(man, dict) else []
        ok = sum(1 for r in m_items if isinstance(r, dict) and r.get("ok"))
        if ok and abs(ok - len(items)) > 5:
            errors.append(f"manifest ok={ok} vs catalog={len(items)} (drift)")

    # Spaced JP idol names in 『…』 must not stay under Group.
    try:
        sys.path.insert(0, str(ROOT / "scripts"))
        from download_bushiroad_playmats import classify as classify_title  # type: ignore
    except Exception:
        classify_title = None  # type: ignore
    if callable(classify_title):
        for row in items:
            if not isinstance(row, dict):
                continue
            name = str(row.get("name") or "")
            m = re.search(r"『([^』]+)』", name)
            if not m:
                continue
            tag = m.group(1)
            if not re.search(r"[一-龯ぁ-んァ-ン]", tag):
                continue
            if re.search(r"ラブライブ|Aqours|μ|µ|Liella|虹|蓮|サンシャイン|スーパースター|[123]年生", tag):
                continue
            _g, expect_idol = classify_title(name)
            got = str(row.get("idol") or "")
            if expect_idol != "Group" and got == "Group":
                errors.append(f"{row.get('id')}: idol is Group but title has member {tag!r}")
            elif expect_idol != "Group" and got != expect_idol:
                errors.append(
                    f"{row.get('id')}: idol {got!r} != classified {expect_idol!r} for {tag!r}"
                )

    if errors:
        print(f"FAIL ({len(errors)}):", file=sys.stderr)
        for e in errors[:40]:
            print(f"  - {e}", file=sys.stderr)
        if len(errors) > 40:
            print(f"  … +{len(errors) - 40} more", file=sys.stderr)
        return 1

    print(f"OK: {len(items)} playmats, {len(seen)} unique ids")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
