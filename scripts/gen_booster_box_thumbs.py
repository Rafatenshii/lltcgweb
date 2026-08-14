#!/usr/bin/env python3
"""Download official booster box art and write small picker thumbs."""
from __future__ import annotations

import io
import ssl
import urllib.request
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "assets" / "packs" / "boxes"
MAX_EDGE = 420  # matches ~210 CSS max-height @2x
JPEG_QUALITY = 78

# id -> official box render URL (from booster.php)
BOXES = {
    "bp_vol1": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2024/12/28162156/L_TCG_-BP_vol1_box_image_250220.png",
    "bp_next": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/01/28114813/L_TCG_-BP_vol2_box_image.png",
    "bp_summer": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/07/02143841/L_TCG_-BP_vol3_box_image.png",
    "bp_sapphire": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/07/26224902/L_TCG_-BP_vol4_box_image.png",
    "bp_royal": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2026/02/27171602/LLC_-BP06_box_image.png",
    "bp_mellow": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2026/02/19180756/LLC_-BP07_box_image.png",
    "bp_anniv": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/10/05190851/L_TCG_-BP_vol4_box_image-1.png",
    "pb_muse": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/05/26224815/L_TCG_-PBP_03_box_image.png",
    "pb_niji": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/08/01160806/L_TCG_-PBP_04_box_image.png",
    "pb_sunshine": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/02/28161326/L_TCG_-PBP_02_box_image.png",
    "pb_superstar": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/01/28114915/L_TCG_-PBP_01_box_image.png",
    "pb_superstar_duo": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2026/02/27171531/LLC_-PB06_box_image.png",
    "pb_hasunosora": "https://llofficial-cardgame.com/wordpress/wp-content/uploads/2025/11/17105656/L_TCG_-PBP_06_box_image.png",
}


def fetch(url: str) -> bytes:
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "lltcgweb-box-thumb/1.0"})
    with urllib.request.urlopen(req, context=ctx, timeout=60) as resp:
        return resp.read()


def make_thumb(data: bytes, dest: Path) -> None:
    im = Image.open(io.BytesIO(data))
    im = im.convert("RGBA")
    # Flatten onto light gray so JPEG has no checkerboard
    bg = Image.new("RGB", im.size, (24, 32, 48))
    bg.paste(im, mask=im.split()[-1] if im.mode == "RGBA" else None)
    w, h = bg.size
    scale = min(1.0, MAX_EDGE / max(w, h))
    if scale < 1.0:
        nw = max(1, int(round(w * scale)))
        nh = max(1, int(round(h * scale)))
        bg = bg.resize((nw, nh), Image.Resampling.LANCZOS)
    dest.parent.mkdir(parents=True, exist_ok=True)
    bg.save(dest, format="JPEG", quality=JPEG_QUALITY, optimize=True, progressive=True)


def main() -> None:
    OUT.mkdir(parents=True, exist_ok=True)
    total_src = 0
    total_out = 0
    for box_id, url in BOXES.items():
        dest = OUT / f"{box_id}.jpg"
        print(f"fetch {box_id} …", flush=True)
        raw = fetch(url)
        total_src += len(raw)
        make_thumb(raw, dest)
        total_out += dest.stat().st_size
        print(f"  {len(raw)/1024:.0f} KB -> {dest.stat().st_size/1024:.1f} KB ({dest.name})")
    print(f"done: {total_src/1024/1024:.1f} MB src -> {total_out/1024:.0f} KB thumbs")


if __name__ == "__main__":
    main()
