#!/usr/bin/env python3
"""Import Mandarake 1999.co.jp Love Live playmats into assets + playmats_catalog.json."""
from __future__ import annotations

import json
import re
import sys
from datetime import date
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SCRAPE = Path("/tmp/playmats1999")
PRODUCTS = SCRAPE / "products_all.json"
HIRES = SCRAPE / "raw_hires"
OUT_DIR = ROOT / "assets" / "playmats"
CATALOG = ROOT / "playmats_catalog.json"
TARGET_W, TARGET_H = 1024, 563
ADDED_AT = date.today().isoformat()
LINE = "mandarake_1999"

# English name → (group, idol)
IDOL_MAP: list[tuple[re.Pattern[str], str, str]] = [
    # Hasunosora (check before generic)
    (re.compile(r"kaho\s*hinoshita|hinoshita\s*kaho", re.I), "Hasunosora", "Kaho"),
    (re.compile(r"sayaka\s*murano|sayaka\s*muraka|murano\s*sayaka|muraka\s*sayaka", re.I), "Hasunosora", "Sayaka"),
    (re.compile(r"kozue\s*otomune|otomune\s*kozue", re.I), "Hasunosora", "Kozue"),
    (re.compile(r"tsuzuri\s*yugiri|yugiri\s*tsuzuri", re.I), "Hasunosora", "Tsuzuri"),
    (re.compile(r"rurino\s*osawa|osawa\s*rurino", re.I), "Hasunosora", "Rurino"),
    (re.compile(r"megumi\s*fujishima|fujishima\s*megumi", re.I), "Hasunosora", "Megumi"),
    (re.compile(r"ginko\s*momose|momose\s*ginko", re.I), "Hasunosora", "Ginko"),
    (re.compile(r"kosuzu\s*kachimachi|kachimachi\s*kosuzu", re.I), "Hasunosora", "Kosuzu"),
    (re.compile(r"hime\s*anyoji|anyoji\s*hime", re.I), "Hasunosora", "Hime"),
    # Nijigasaki
    (re.compile(r"ayumu\s*uehara|uehara\s*ayumu", re.I), "Nijigasaki", "Ayumu"),
    (re.compile(r"kasumi\s*nakasu|nakasu\s*kasumi", re.I), "Nijigasaki", "Kasumi"),
    (re.compile(r"shizuku\s*osaka|osaka\s*shizuku", re.I), "Nijigasaki", "Shizuku"),
    (re.compile(r"karin\s*asaka|asaka\s*karin", re.I), "Nijigasaki", "Karin"),
    (re.compile(r"\bai\s*miyashita|miyashita\s*ai\b", re.I), "Nijigasaki", "Ai"),
    (re.compile(r"kanata\s*konoe|konoe\s*kanata", re.I), "Nijigasaki", "Kanata"),
    (re.compile(r"setsuna\s*yuki|yuki\s*setsuna", re.I), "Nijigasaki", "Setsuna"),
    (re.compile(r"emma\s*verde|verde\s*emma", re.I), "Nijigasaki", "Emma"),
    (re.compile(r"rina\s*tennoji|tennoji\s*rina|tennōji", re.I), "Nijigasaki", "Rina"),
    (re.compile(r"shioriko\s*mifune|mifune\s*shioriko", re.I), "Nijigasaki", "Shioriko"),
    (re.compile(r"\bmia\s*taylor|taylor\s*mia\b", re.I), "Nijigasaki", "Mia"),
    (re.compile(r"lanzhu\s*zhong|zhong\s*lanzhu", re.I), "Nijigasaki", "Lanzhu"),
    # Aqours
    (re.compile(r"chika\s*takami|takami\s*chika", re.I), "Aqours", "Chika"),
    (re.compile(r"riko\s*sakurauchi|sakurauchi\s*riko", re.I), "Aqours", "Riko"),
    (re.compile(r"kanan\s*matsuura|matsuura\s*kanan", re.I), "Aqours", "Kanan"),
    (re.compile(r"dia\s*kurosawa|kurosawa\s*dia", re.I), "Aqours", "Dia"),
    (re.compile(r"you\s*watanabe|watanabe\s*you", re.I), "Aqours", "You"),
    (re.compile(r"yoshiko\s*tsushima|tsushima\s*yoshiko", re.I), "Aqours", "Yoshiko"),
    (re.compile(r"hanamaru\s*kunikida|kunikida\s*hanamaru", re.I), "Aqours", "Hanamaru"),
    (re.compile(r"mari\s*ohara|ohara\s*mari", re.I), "Aqours", "Mari"),
    (re.compile(r"ruby\s*kurosawa|kurosawa\s*ruby", re.I), "Aqours", "Ruby"),
    # Muse
    (re.compile(r"honoka\s*kosaka|kosaka\s*honoka", re.I), "Muse", "Honoka"),
    (re.compile(r"eli\s*ayase|ayase\s*eli", re.I), "Muse", "Eli"),
    (re.compile(r"kotori\s*minami|minami\s*kotori", re.I), "Muse", "Kotori"),
    (re.compile(r"umi\s*sonoda|sonoda\s*umi", re.I), "Muse", "Umi"),
    (re.compile(r"rin\s*hoshizora|hoshizora\s*rin", re.I), "Muse", "Rin"),
    (re.compile(r"maki\s*nishikino|nishikino\s*maki", re.I), "Muse", "Maki"),
    (re.compile(r"nozomi\s*tojo|tojo\s*nozomi|nozomi\s*tōjō|tōjō\s*nozomi", re.I), "Muse", "Nozomi"),
    (re.compile(r"hanayo\s*koizumi|koizumi\s*hanayo", re.I), "Muse", "Hanayo"),
    (re.compile(r"nico\s*yazawa|yazawa\s*nico", re.I), "Muse", "Nico"),
]

SUBUNIT_MAP: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"\bAZALEA\b", re.I), "Aqours", "AZALEA"),
    (re.compile(r"\bCYaRon!?\b", re.I), "Aqours", "CYaRon!"),
    (re.compile(r"\bGuilty\s*Kiss\b", re.I), "Aqours", "Guilty Kiss"),
]

YEAR_MAP: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"1st\s*Graders?|1年生", re.I), "Aqours", "1st Years"),
    (re.compile(r"2nd\s*Graders?|2年生", re.I), "Aqours", "2nd Years"),
    (re.compile(r"3rd\s*Graders?|3年生", re.I), "Aqours", "3rd Years"),
]


def classify(title: str) -> tuple[str, str]:
    t = title or ""
    for rx, g, i in YEAR_MAP:
        if rx.search(t):
            return g, i
    for rx, g, i in SUBUNIT_MAP:
        if rx.search(t):
            return g, i
    # Bracket contents may list multiple idols → Group
    bracket = re.findall(r"\[([^\]]+)\]", t)
    multi = False
    if bracket:
        inner = bracket[-1]
        if "&" in inner or "/" in inner or "," in inner:
            multi = True
        # "Maki/Umi/Eli"
        if re.search(r"[A-Za-z]+/[A-Za-z]+", inner):
            multi = True
    hits: list[tuple[str, str]] = []
    for rx, g, i in IDOL_MAP:
        if rx.search(t):
            hits.append((g, i))
    # de-dupe preserving order
    seen = set()
    uniq = []
    for h in hits:
        if h not in seen:
            seen.add(h)
            uniq.append(h)
    if multi or len(uniq) > 1:
        # Prefer series from title keywords
        low = t.lower()
        if "hasu" in low or "蓮" in t:
            return "Hasunosora", "Group"
        if "nijigasaki" in low or "虹" in t:
            return "Nijigasaki", "Group"
        if "sunshine" in low or "aqours" in low:
            return "Aqours", "Group"
        if "liella" in low or "superstar" in low:
            return "Liella", "Group"
        if uniq:
            return uniq[0][0], "Group"
        return "Muse", "Group"
    if uniq:
        return uniq[0]
    low = t.lower()
    if "hasu" in low:
        return "Hasunosora", "Group"
    if "nijigasaki" in low:
        return "Nijigasaki", "Group"
    if "sunshine" in low or "aqours" in low:
        return "Aqours", "Group"
    if "liella" in low:
        return "Liella", "Group"
    if "love live!" in low or "love live" in low:
        return "Muse", "Group"
    return "Other", "Group"


def clean_name(title: str) -> str:
    s = re.sub(r"^\s*(Early|Mid|Late)\s+[A-Za-z]+\.?,?\s+\d{4}\s+Released\s+", "", title or "", flags=re.I)
    s = re.sub(r"\s*\(Anime Toy\)\s*$", "", s, flags=re.I)
    s = re.sub(r"\s*\(Card Supplies\)\s*$", "", s, flags=re.I)
    s = re.sub(r"\s{2,}", " ", s).strip(" -")
    # Shorten verbose prefixes for shop tiles
    s = re.sub(
        r"^Character Univers(?:e|al)\s+Rubber(?:\s+Mat)?\s+",
        "Character Universe ",
        s,
        flags=re.I,
    )
    s = re.sub(r"^Universal Cloth Desk Mat\s+", "Cloth Desk Mat ", s, flags=re.I)
    return s.strip()[:160]


def cover_resize(im: Image.Image, tw: int, th: int) -> Image.Image:
    im = im.convert("RGB")
    sw, sh = im.size
    scale = max(tw / sw, th / sh)
    nw, nh = max(1, int(round(sw * scale))), max(1, int(round(sh * scale)))
    im = im.resize((nw, nh), Image.Resampling.LANCZOS)
    left = max(0, (nw - tw) // 2)
    top = max(0, (nh - th) // 2)
    return im.crop((left, top, left + tw, top + th))


def find_raw(pid: str) -> Path | None:
    for p in HIRES.glob(f"{pid}.*"):
        if p.is_file() and p.stat().st_size > 5000:
            return p
    return None


def should_skip(title: str, existing_ids: set[str]) -> str | None:
    """Skip clear duplicates of Bushiroad Hasunosora Dream Believers already in shop."""
    t = title or ""
    if re.search(r"Dream\s*Believers", t, re.I):
        return "dream_believers_already_in_catalog"
    return None


def main() -> int:
    products = json.loads(PRODUCTS.read_text(encoding="utf-8"))
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    items = catalog.setdefault("items", [])
    existing_ids = {str(it.get("id")) for it in items if isinstance(it, dict)}
    # Also skip if listing_url already points at this mandarake id
    existing_listings = {
        str(it.get("listing_url") or "")
        for it in items
        if isinstance(it, dict)
    }

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    added: list[dict] = []
    skipped: list[tuple[str, str]] = []

    for row in products:
        pid = str(row.get("id") or "")
        title = str(row.get("title") or "")
        url = str(row.get("url") or f"https://www.1999.co.jp/eng/{pid}")
        if not pid:
            continue
        sid = f"md-{pid}"
        if sid in existing_ids or url in existing_listings:
            skipped.append((pid, "already_in_catalog"))
            continue
        reason = should_skip(title, existing_ids)
        if reason:
            skipped.append((pid, reason))
            continue
        raw = find_raw(pid)
        if not raw:
            skipped.append((pid, "missing_image"))
            continue
        try:
            im = Image.open(raw)
            out_im = cover_resize(im, TARGET_W, TARGET_H)
        except Exception as e:
            skipped.append((pid, f"image_error:{e}"))
            continue
        out_path = OUT_DIR / f"{sid}.webp"
        out_im.save(out_path, "WEBP", quality=85, method=6)
        group, idol = classify(title)
        entry = {
            "id": sid,
            "name": clean_name(title),
            "group": group,
            "idol": idol,
            "vol": None,
            "line": LINE,
            "listing_url": url,
            "src": f"assets/playmats/{sid}.webp",
            "added_at": ADDED_AT,
        }
        items.append(entry)
        existing_ids.add(sid)
        added.append(entry)
        print(f"+ {sid} [{group}/{idol}] {entry['name'][:70]}", flush=True)

    catalog["items"] = items
    CATALOG.write_text(json.dumps(catalog, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    by_cat: dict[str, list[str]] = {}
    for e in added:
        key = f"{e['group']} / {e['idol']}"
        by_cat.setdefault(key, []).append(f"{e['id']}: {e['name']}")

    report = SCRAPE / "IMPORT_REPORT.md"
    lines = [
        f"# Mandarake import — {ADDED_AT}",
        "",
        f"Added **{len(added)}** playmats (skipped {len(skipped)}).",
        "",
    ]
    for key in sorted(by_cat):
        lines.append(f"## {key}")
        for row in by_cat[key]:
            lines.append(f"- {row}")
        lines.append("")
    if skipped:
        lines.append("## Skipped")
        for pid, why in skipped:
            lines.append(f"- {pid}: {why}")
        lines.append("")
    report.write_text("\n".join(lines), encoding="utf-8")
    print(f"Added {len(added)}; skipped {len(skipped)}. Report: {report}", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
