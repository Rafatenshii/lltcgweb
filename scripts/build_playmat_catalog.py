#!/usr/bin/env python3
"""Build playmats_catalog.json + assets/playmats/{id}.webp from Playmats/_meta/manifest.json."""
from __future__ import annotations

import json
import re
import shutil
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "Playmats" / "_meta" / "manifest.json"
OUT_CATALOG = ROOT / "playmats_catalog.json"
OUT_DIR = ROOT / "assets" / "playmats"

# Import classify from the download script so catalog idol/group stay consistent.
sys.path.insert(0, str(ROOT / "scripts"))
try:
    from download_bushiroad_playmats import classify as classify_title  # type: ignore
except Exception:
    classify_title = None  # type: ignore


def normalize_id(raw: str) -> str:
    s = re.sub(r"[^a-z0-9._-]+", "-", raw.lower().strip())
    s = re.sub(r"-{2,}", "-", s).strip("-._")
    if not s:
        return ""
    if not re.match(r"^[a-z0-9]", s):
        s = "p" + s
    return s[:64]


def clean_display_name(name: str) -> str:
    s = str(name or "").strip()
    s = re.sub(r"(?i)\bbushiroad\b\s*", "", s)
    s = re.sub(r"^ラバーマットコレクション\s*(V2\s*)?", "", s)
    s = re.sub(r"\s{2,}", " ", s)
    return s.strip(" \t-:")


def main() -> int:
    if not MANIFEST.is_file():
        print(f"Missing {MANIFEST}", file=sys.stderr)
        return 1
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    items = data.get("items") if isinstance(data, dict) else data
    if not isinstance(items, list):
        print("Bad manifest", file=sys.stderr)
        return 1

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    catalog = []
    seen = set()
    try:
        from PIL import Image
        has_pil = True
    except Exception:
        has_pil = False
        print("PIL not available — copying PNG as fallback", file=sys.stderr)

    for row in items:
        if not isinstance(row, dict) or not row.get("ok"):
            continue
        pid = row.get("productId")
        name = clean_display_name(str(row.get("productName") or f"Playmat {pid}"))
        group = str(row.get("group") or "Other")
        idol = str(row.get("idol") or "Group")
        if callable(classify_title):
            g2, i2 = classify_title(str(row.get("productName") or name))
            if g2 and g2 != "Other":
                group = g2
            if i2:
                idol = i2
        vol = row.get("vol")
        line = str(row.get("line") or "")
        listing_url = str(row.get("listing_url") or "")
        rel = str(row.get("path") or "")
        if not rel:
            continue
        src_path = ROOT / "Playmats" / rel.replace("\\", "/")
        if not src_path.is_file():
            print(f"skip missing {src_path}")
            continue
        sid = normalize_id(str(pid) if pid else Path(rel).stem)
        if not sid or sid in seen:
            continue
        seen.add(sid)
        dest_webp = OUT_DIR / f"{sid}.webp"
        dest_png = OUT_DIR / f"{sid}.png"
        ext = ".webp"
        if has_pil:
            try:
                im = Image.open(src_path).convert("RGB")
                # Already 1024x563 from download pipeline; re-encode as webp.
                im.save(dest_webp, "WEBP", quality=84, method=4)
            except Exception as e:
                print(f"webp fail {sid}: {e}; copy png")
                shutil.copy2(src_path, dest_png)
                ext = ".png"
        else:
            shutil.copy2(src_path, dest_png)
            ext = ".png"
        catalog.append(
            {
                "id": sid,
                "name": name,
                "group": group,
                "idol": idol,
                "vol": vol,
                "line": line,
                "listing_url": listing_url,
                "src": f"assets/playmats/{sid}{ext}",
            }
        )

    catalog.sort(key=lambda x: (x["group"], x["idol"], x.get("vol") or 0, x["name"]))
    OUT_CATALOG.write_text(
        json.dumps({"items": catalog}, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Wrote {len(catalog)} playmats → {OUT_CATALOG}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
