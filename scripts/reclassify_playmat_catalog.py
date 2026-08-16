#!/usr/bin/env python3
"""Reclassify playmats_catalog.json idol/group using fixed classify()."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from download_bushiroad_playmats import classify  # noqa: E402

CATALOG = ROOT / "playmats_catalog.json"


def main() -> int:
    data = json.loads(CATALOG.read_text(encoding="utf-8"))
    items = data["items"]
    changed = []
    for row in items:
        g, i = classify(row.get("name") or "")
        old = (row.get("group"), row.get("idol"))
        new = (g, i)
        if old != new:
            changed.append((row["id"], old, new, row["name"]))
            row["group"] = g
            row["idol"] = i
    items.sort(key=lambda x: (x["group"], x["idol"], x.get("vol") or 0, x["name"]))
    CATALOG.write_text(
        json.dumps({"items": items}, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"updated {len(changed)} rows")
    for cid, old, new, name in changed:
        print(f"{cid}: {old} -> {new} | {name[:70]}")

    still = []
    for row in items:
        m = re.search(r"『([^』]+)』", row["name"] or "")
        if not m:
            continue
        tag = m.group(1)
        if not re.search(r"[一-龯ぁ-んァ-ン]", tag):
            continue
        if row["idol"] != "Group":
            continue
        if re.search(
            r"ラブライブ|Aqours|μ|µ|Liella|虹|蓮|サンシャイン|スーパースター|[123]年生",
            tag,
        ):
            continue
        still.append((row["id"], tag, row["name"]))
    print(f"still mis-tagged as Group: {len(still)}")
    for s in still:
        print(s)

    # Quick sanity checks
    samples = [
        ("Vol.37 ラブライブ！ 『高坂 穂乃果』", "Muse", "Honoka"),
        ("Vol.136 ラブライブ！サンシャイン!! 『高海 千歌』", "Aqours", "Chika"),
        ("Vol.39 ラブライブ！ 『南 ことり』", "Muse", "Kotori"),
        ("Vol.53 ラブライブ！サンシャイン!! 『Aqours』", "Aqours", "Group"),
    ]
    for title, eg, ei in samples:
        g, i = classify(title)
        ok = g == eg and i == ei
        print(f"{'OK' if ok else 'FAIL'} {title!r} -> {(g, i)} expected {(eg, ei)}")
        if not ok:
            return 1
    return 0 if not still else 1


if __name__ == "__main__":
    raise SystemExit(main())
