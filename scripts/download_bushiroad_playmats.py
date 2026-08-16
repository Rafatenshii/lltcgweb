#!/usr/bin/env python3
"""Download Love Live rubber playmats from bushiroad.com (ex_rm + ex_rm_v2).

Curated wave pages only — no arbitrary URL proxy. Saves:
  Playmats/_raw/          original product images
  Playmats/_meta/         provenance + manifest
Optional waifu2x-ncnn-vulkan upscale, then cover-crop to 1024x563.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import time
import urllib.error
import urllib.request
from io import BytesIO
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
PLAYMATS = ROOT / "Playmats"
META = PLAYMATS / "_meta"
RAW = PLAYMATS / "_raw"
OUT_PNG = PLAYMATS / "_processed"
TARGET_W, TARGET_H = 1024, 563
BASE = "https://bushiroad.com"
UA = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    ),
    "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ja,en;q=0.8",
}

# Curated wave product pages that contain the Love Live playmats we identified.
WAVE_PAGES: list[dict] = [
    # Original Rubber Mat Collection (ex_rm)
    {"line": "ex_rm", "wave": 4, "url": f"{BASE}/products/ex_rm/4", "vols": [9]},
    {"line": "ex_rm", "wave": 15, "url": f"{BASE}/products/ex_rm/15", "vols": list(range(37, 46))},
    {"line": "ex_rm", "wave": 16, "url": f"{BASE}/products/ex_rm/16", "vols": [46]},
    {"line": "ex_rm", "wave": 20, "url": f"{BASE}/products/ex_rm/20", "vols": [53]},
    {"line": "ex_rm", "wave": 25, "url": f"{BASE}/products/ex_rm/25", "vols": [64, 65, 66]},
    {"line": "ex_rm", "wave": 27, "url": f"{BASE}/products/ex_rm/27", "vols": [69]},
    {"line": "ex_rm", "wave": 57, "url": f"{BASE}/products/ex_rm/639", "vols": list(range(136, 145))},
    {"line": "ex_rm", "wave": 115, "url": f"{BASE}/products/ex_rm/1028", "vols": list(range(368, 377))},
    {"line": "ex_rm", "wave": 116, "url": f"{BASE}/products/ex_rm/1029", "vols": list(range(377, 386))},
    {"line": "ex_rm", "wave": 166, "url": f"{BASE}/products/ex_rm/1233", "vols": list(range(612, 621))},
    # V2
    {"line": "ex_rm_v2", "wave": 1, "url": f"{BASE}/products/ex_rm_v2/1386", "vols": list(range(1, 11))},
    {"line": "ex_rm_v2", "wave": 15, "url": f"{BASE}/products/ex_rm_v2/1461", "vols": list(range(70, 79))},
    {"line": "ex_rm_v2", "wave": 36, "url": f"{BASE}/products/ex_rm_v2/1536", "vols": list(range(181, 186))},
    {"line": "ex_rm_v2", "wave": 52, "url": f"{BASE}/products/ex_rm_v2/1587", "vols": list(range(269, 278))},
    {"line": "ex_rm_v2", "wave": 63, "url": f"{BASE}/products/ex_rm_v2/1628", "vols": [328]},
    {"line": "ex_rm_v2", "wave": 120, "url": f"{BASE}/products/ex_rm_v2/1801", "vols": list(range(578, 587))},
    {"line": "ex_rm_v2", "wave": 135, "url": f"{BASE}/products/ex_rm_v2/1851", "vols": list(range(452, 456))},
    {"line": "ex_rm_v2", "wave": 156, "url": f"{BASE}/products/ex_rm_v2/1928", "vols": list(range(720, 732))},
    {"line": "ex_rm_v2", "wave": 331, "url": f"{BASE}/products/ex_rm_v2/2559", "vols": list(range(1434, 1440))},
    # Hasunosora Dream Believers (第357弾)
    {"line": "ex_rm_v2", "wave": 357, "url": f"{BASE}/products/ex_rm_v2/2650", "vols": list(range(1540, 1549))},
]

IDOLS: list[tuple[str, str, str]] = [
    ("高坂穂乃果", "Muse", "Honoka"),
    ("絢瀬絵里", "Muse", "Eli"),
    ("南 ことり", "Muse", "Kotori"),
    ("南ことり", "Muse", "Kotori"),
    ("園田海未", "Muse", "Umi"),
    ("星空 凛", "Muse", "Rin"),
    ("星空凛", "Muse", "Rin"),
    ("西木野真姫", "Muse", "Maki"),
    ("東條 希", "Muse", "Nozomi"),
    ("東條希", "Muse", "Nozomi"),
    ("小泉花陽", "Muse", "Hanayo"),
    ("矢澤にこ", "Muse", "Nico"),
    ("μ's", "Muse", "Group"),
    ("µ's", "Muse", "Group"),
    ("高海千歌", "Aqours", "Chika"),
    ("桜内梨子", "Aqours", "Riko"),
    ("松浦果南", "Aqours", "Kanan"),
    ("黒澤ダイヤ", "Aqours", "Dia"),
    ("渡辺 曜", "Aqours", "You"),
    ("渡辺曜", "Aqours", "You"),
    ("津島善子", "Aqours", "Yoshiko"),
    ("国木田花丸", "Aqours", "Hanamaru"),
    ("小原鞠莉", "Aqours", "Mari"),
    ("黒澤ルビィ", "Aqours", "Ruby"),
    ("Aqours", "Aqours", "Group"),
    ("2年生", "Aqours", "2nd Years"),
    ("1年生", "Aqours", "1st Years"),
    ("3年生", "Aqours", "3rd Years"),
    ("上原歩夢", "Nijigasaki", "Ayumu"),
    ("中須かすみ", "Nijigasaki", "Kasumi"),
    ("桜坂しずく", "Nijigasaki", "Shizuku"),
    ("朝香果林", "Nijigasaki", "Karin"),
    ("宮下 愛", "Nijigasaki", "Ai"),
    ("宮下愛", "Nijigasaki", "Ai"),
    ("近江彼方", "Nijigasaki", "Kanata"),
    ("優木せつ菜", "Nijigasaki", "Setsuna"),
    ("エマ・ヴェルデ", "Nijigasaki", "Emma"),
    ("エマ･ヴェルデ", "Nijigasaki", "Emma"),
    ("天王寺璃奈", "Nijigasaki", "Rina"),
    ("三船栞子", "Nijigasaki", "Shioriko"),
    ("ミア・テイラー", "Nijigasaki", "Mia"),
    ("ミア･テイラー", "Nijigasaki", "Mia"),
    ("鐘 嵐珠", "Nijigasaki", "Lanzhu"),
    ("鐘嵐珠", "Nijigasaki", "Lanzhu"),
    ("虹ヶ咲", "Nijigasaki", "Group"),
    ("澁谷かのん", "Liella", "Kanon"),
    ("渋谷かのん", "Liella", "Kanon"),
    ("唐 可可", "Liella", "Keke"),
    ("唐可可", "Liella", "Keke"),
    ("嵐 千砂都", "Liella", "Chisato"),
    ("嵐千砂都", "Liella", "Chisato"),
    ("平安名すみれ", "Liella", "Sumire"),
    ("葉月 恋", "Liella", "Ren"),
    ("葉月恋", "Liella", "Ren"),
    ("桜小路きな子", "Liella", "Kinako"),
    ("米女メイ", "Liella", "Mei"),
    ("若菜四季", "Liella", "Shiki"),
    ("鬼塚夏美", "Liella", "Natsumi"),
    ("スーパースター", "Liella", "Group"),
    ("日野下花帆", "Hasunosora", "Kaho"),
    ("村野さやか", "Hasunosora", "Sayaka"),
    ("乙宗 梢", "Hasunosora", "Kozue"),
    ("乙宗梢", "Hasunosora", "Kozue"),
    ("夕霧綴理", "Hasunosora", "Tsuzuri"),
    ("大沢瑠璃乃", "Hasunosora", "Rurino"),
    ("藤島 慈", "Hasunosora", "Megumi"),
    ("藤島慈", "Hasunosora", "Megumi"),
    ("百生吟子", "Hasunosora", "Ginko"),
    ("徒町小鈴", "Hasunosora", "Kosuzu"),
    ("安養寺姫芽", "Hasunosora", "Hime"),
    ("蓮ノ空", "Hasunosora", "Group"),
]


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=45) as resp:
        return resp.read().decode("utf-8", "replace")


def fetch_bytes(url: str) -> bytes:
    req = urllib.request.Request(url, headers={**UA, "Accept": "image/*,*/*"})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read()


def is_ll(text: str) -> bool:
    t = text.replace("&nbsp;", " ")
    if "とらぶる" in t or "to loveる" in t.lower() or "to love-ru" in t.lower():
        return False
    markers = ("ラブライブ", "love live", "μ's", "µ's", "aqours", "虹ヶ咲", "蓮ノ空", "スーパースター", "liella")
    blob = t.lower()
    return any(m.lower() in blob or m in t for m in markers)


def _norm_title(title: str) -> str:
    """Strip spaces / HTML entities so 『高坂 穂乃果』 matches 高坂穂乃果."""
    s = (
        str(title or "")
        .replace("&nbsp;", " ")
        .replace("&mu;", "μ")
        .replace("&micro;", "μ")
        .replace("&#956;", "μ")
    )
    return re.sub(r"[\s\u3000]+", "", s)


def classify(title: str) -> tuple[str, str]:
    group = "Other"
    blob = title.lower()
    compact = _norm_title(title)
    compact_low = compact.lower()
    if "蓮ノ空" in title or "hasunosora" in blob:
        group = "Hasunosora"
    elif "虹ヶ咲" in title or "nijigasaki" in blob:
        group = "Nijigasaki"
    elif "スーパースター" in title or "super star" in blob or "liella" in blob:
        group = "Liella"
    elif "サンシャイン" in title or "sunshine" in blob or "aqours" in blob:
        group = "Aqours"
    elif "ラブライブ" in title or "love live" in blob or "μ's" in title or "µ's" in title or "μ's" in compact:
        group = "Muse"

    idol = "Group"
    # Prefer member names over group labels (titles often include スーパースター / μ's / Aqours).
    member_keys = [(k, g, n) for k, g, n in IDOLS if n != "Group"]
    group_keys = [(k, g, n) for k, g, n in IDOLS if n == "Group"]
    for key, g, name in sorted(member_keys, key=lambda t: -len(_norm_title(t[0]))):
        nk = _norm_title(key)
        if not nk:
            continue
        if nk in compact or nk.lower() in compact_low:
            idol = name
            if group == "Other":
                group = g
            break
    if idol == "Group":
        for key, g, name in sorted(group_keys, key=lambda t: -len(_norm_title(t[0]))):
            nk = _norm_title(key)
            if not nk:
                continue
            if nk in compact or nk.lower() in compact_low:
                idol = name
                if group == "Other":
                    group = g
                break
    # Year groups for Sunshine
    if "『2年生』" in title:
        idol = "2nd Years"
        group = "Aqours"
    elif "『1年生』" in title:
        idol = "1st Years"
        group = "Aqours"
    elif "『3年生』" in title:
        idol = "3rd Years"
        group = "Aqours"
    return group, idol


def parse_wave_playmats(html: str, wave: dict) -> list[dict]:
    main = html
    if 'class="detail' in html:
        main = html.split('class="detail', 1)[1]
        if "content-sns-section" in main:
            main = main.split("content-sns-section", 1)[0]

    allowed = set(wave.get("vols") or [])
    items: list[dict] = []
    for m in re.finditer(
        r'<img[^>]+src="(https://s3-ap-northeast-1\.amazonaws\.com/bushiroad-com/[^"]+)"[^>]*>'
        r"(.*?)"
        r"(?=<(?:img|ul|h3|table|div class=\"blogparts_element img)|$)",
        main,
        re.S | re.I,
    ):
        img, rest = m.group(1), m.group(2)
        rest_txt = re.sub(r"<br\s*/?>", " ", rest, flags=re.I)
        rest_txt = re.sub(r"<[^>]+>", " ", rest_txt)
        rest_txt = re.sub(r"\s+", " ", rest_txt).strip()
        vm = re.search(r"Vol\.?\s*(\d+)\s*(.*)", rest_txt, re.I)
        if not vm:
            continue
        vol = int(vm.group(1))
        if allowed and vol not in allowed:
            continue
        caption = ("Vol." + vm.group(1) + " " + vm.group(2)).strip()[:240]
        if not is_ll(caption) and not is_ll(html[:2000]):
            # Mixed waves: keep only LL captions
            if not is_ll(caption):
                continue
        group, idol = classify(caption)
        items.append(
            {
                "vol": vol,
                "line": wave["line"],
                "wave": wave.get("wave"),
                "title": caption,
                "image": img,
                "listing_url": wave["url"],
                "group": group,
                "idol": idol,
            }
        )

    # Fallback: equal-count pairing of images + Vol captions from page text
    if not items:
        imgs = re.findall(
            r'src="(https://s3-ap-northeast-1\.amazonaws\.com/bushiroad-com/[^"]+)"',
            main,
        )
        vols = [
            (int(a), b.strip())
            for a, b in re.findall(r"Vol\.?\s*(\d+)\s*([^\n<]+)", html)
        ]
        if allowed:
            vols = [(v, c) for v, c in vols if v in allowed]
        if imgs and vols and len(imgs) >= len(vols):
            for img, (vol, cap) in zip(imgs, vols):
                caption = f"Vol.{vol} {cap}".strip()[:240]
                if not is_ll(caption):
                    continue
                group, idol = classify(caption)
                items.append(
                    {
                        "vol": vol,
                        "line": wave["line"],
                        "wave": wave.get("wave"),
                        "title": caption,
                        "image": img,
                        "listing_url": wave["url"],
                        "group": group,
                        "idol": idol,
                    }
                )
    return items


def find_waifu2x() -> str | None:
    for name in ("waifu2x-ncnn-vulkan", "waifu2x-ncnn-vulkan.exe"):
        path = shutil.which(name)
        if path:
            return path
    # Common local drop locations
    for cand in (
        ROOT / "tools" / "waifu2x-ncnn-vulkan" / "waifu2x-ncnn-vulkan.exe",
        ROOT / "tools" / "waifu2x-ncnn-vulkan.exe",
        Path.home() / "waifu2x-ncnn-vulkan" / "waifu2x-ncnn-vulkan.exe",
    ):
        if cand.is_file():
            return str(cand)
    # Official Windows release archives unpack into a versioned child folder.
    bundled = ROOT / "tools" / "waifu2x-ncnn-vulkan"
    if bundled.is_dir():
        matches = sorted(bundled.rglob("waifu2x-ncnn-vulkan.exe"))
        if matches:
            return str(matches[0])
    return None


def upscale_image(src: Path, dest: Path, waifu: str | None) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    if waifu:
        tmp = dest.with_suffix(".w2x.png")
        try:
            subprocess.run(
                [waifu, "-i", str(src), "-o", str(tmp), "-n", "2", "-s", "2"],
                check=True,
                capture_output=True,
            )
            if tmp.is_file():
                cover_crop(tmp, dest)
                tmp.unlink(missing_ok=True)
                return
        except Exception as e:
            print(f"  waifu2x fail {src.name}: {e}; LANCZOS fallback")
    cover_crop(src, dest, upsample=True)


def cover_crop(src: Path, dest: Path, upsample: bool = False) -> None:
    im = Image.open(src).convert("RGB")
    w, h = im.size
    target_ratio = TARGET_W / TARGET_H
    ratio = w / h if h else target_ratio
    if ratio > target_ratio:
        # too wide — crop sides
        new_w = int(h * target_ratio)
        x0 = max(0, (w - new_w) // 2)
        im = im.crop((x0, 0, x0 + new_w, h))
    else:
        # too tall — crop top/bottom
        new_h = int(w / target_ratio)
        y0 = max(0, (h - new_h) // 2)
        im = im.crop((0, y0, w, y0 + new_h))
    if im.size != (TARGET_W, TARGET_H):
        im = im.resize((TARGET_W, TARGET_H), Image.Resampling.LANCZOS)
    elif upsample and max(w, h) < max(TARGET_W, TARGET_H):
        im = im.resize((TARGET_W, TARGET_H), Image.Resampling.LANCZOS)
    dest.parent.mkdir(parents=True, exist_ok=True)
    im.save(dest, format="PNG", optimize=True)


def product_id(line: str, vol: int) -> str:
    prefix = "br-rm" if line == "ex_rm" else "br-rmv2"
    return f"{prefix}-{vol}"


def main() -> int:
    # Unbuffered progress so long runs are visible in CI / agent terminals.
    try:
        import sys

        sys.stdout.reconfigure(line_buffering=True)  # type: ignore[attr-defined]
    except Exception:
        pass

    META.mkdir(parents=True, exist_ok=True)
    RAW.mkdir(parents=True, exist_ok=True)
    OUT_PNG.mkdir(parents=True, exist_ok=True)

    waves = list(WAVE_PAGES)

    (META / "wave_pages.json").write_text(
        json.dumps(waves, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )

    all_items: list[dict] = []
    for wave in waves:
        print(f"wave {wave['line']} {wave.get('wave')}: {wave['url']}", flush=True)
        try:
            html = fetch(wave["url"])
        except Exception as e:
            print(f"  FAIL fetch: {e}", flush=True)
            continue
        found = parse_wave_playmats(html, wave)
        print(f"  found {len(found)} LL playmat images", flush=True)
        all_items.extend(found)
        time.sleep(0.15)

    # De-dupe by (line, vol)
    seen: set[tuple[str, int]] = set()
    unique: list[dict] = []
    for item in all_items:
        key = (item["line"], int(item["vol"]))
        if key in seen:
            continue
        seen.add(key)
        unique.append(item)

    (META / "bushiroad_playmats.json").write_text(
        json.dumps(unique, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"unique playmats: {len(unique)}")

    waifu = find_waifu2x()
    print(f"waifu2x: {waifu or 'not found (LANCZOS upscale)'}")

    manifest = {"items": []}
    ok = 0
    fails: list[dict] = []
    for item in unique:
        vol = int(item["vol"])
        line = item["line"]
        pid = product_id(line, vol)
        ext = Path(item["image"]).suffix.lower() or ".jpg"
        if ext not in (".jpg", ".jpeg", ".png", ".webp"):
            ext = ".jpg"
        raw_path = RAW / f"{pid}{ext}"
        processed = OUT_PNG / f"{pid}.png"
        try:
            if not raw_path.is_file():
                data = fetch_bytes(item["image"])
                raw_path.write_bytes(data)
                time.sleep(0.08)
            else:
                data = raw_path.read_bytes()
            # Save a clean RGB source next to raw for upscale
            src_png = RAW / f"{pid}.src.png"
            im = Image.open(BytesIO(data)).convert("RGB")
            im.save(src_png, format="PNG")
            upscale_image(src_png, processed, waifu)
        except Exception as e:
            print(f"  FAIL {pid}: {e}")
            fails.append({**item, "error": str(e), "productId": pid})
            continue

        group, idol = item.get("group") or "Other", item.get("idol") or "Group"
        if group == "Other" or idol == "Group":
            g2, i2 = classify(item["title"])
            if group == "Other":
                group = g2
            if idol == "Group":
                idol = i2

        rel = str(processed.relative_to(PLAYMATS)).replace("\\", "/")
        print(f"  ok {pid} [{group}/{idol}] {item['title'][:80]}")
        manifest["items"].append(
            {
                "source": "bushiroad",
                "productId": pid,
                "productName": item["title"],
                "vol": vol,
                "line": line,
                "listing_url": item["listing_url"],
                "image": item["image"],
                "group": group,
                "idol": idol,
                "path": rel,
                "ok": True,
            }
        )
        ok += 1

    manifest_path = META / "manifest.json"
    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    (META / "download_failures.json").write_text(
        json.dumps(fails, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    print(f"downloaded/processed {ok}; failed {len(fails)}")
    expected = sum(len(w["vols"]) for w in waves)
    print(f"expected vols from curated waves: {expected}")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
