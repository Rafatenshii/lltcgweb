#!/usr/bin/env python3
"""Populate cards.json text_th for all rules-bearing cards (Thai).

Pipeline (mirrors translate_text_zh_batch.py):
  1. Localize EN skill brackets to locked TH set
  2. Apply Thai glossary (game terms)
  3. Replace "quoted" EN names with th_names / th_songs map forms
  4. Exact overrides from locales/batch_*_th_exact.json when present
  5. Machine-translate remaining unique EN bodies (cached) then re-apply glossary

Usage:
  python scripts/translate_text_th_batch.py --rebuild-all-th
  python scripts/translate_text_th_batch.py --repair-leaks
"""
from __future__ import annotations

import argparse
import json
import re
import time
from pathlib import Path

from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parent.parent
CARDS = ROOT / "cards.json"
CACHE = ROOT / "locales" / "_th_skill_mt_cache.json"
TH_NAMES = ROOT / "locales" / "th_names.json"
TH_SONGS = ROOT / "locales" / "th_songs.json"

BRACKET_EN_TO_TH: dict[str, str] = {
    "[On Enter]": "[เมื่อเข้าสนาม]",
    "[On Leave]": "[เมื่อออกจากสนาม]",
    "[Live Start]": "[เริ่ม Live]",
    "[Live Success]": "[Live สำเร็จ]",
    "[Activated]": "[เปิดใช้]",
    "[Always]": "[ต่อเนื่อง]",
    "[Continuous]": "[ต่อเนื่อง]",
    "[On Play]": "[ต่อเนื่อง]",
    "[Automatic]": "[อัตโนมัติ]",
    "[Auto]": "[อัตโนมัติ]",
    "[Once per Turn]": "[เทิร์นละ 1 ครั้ง]",
    "[Once per turn]": "[เทิร์นละ 1 ครั้ง]",
    "[Twice per Turn]": "[เทิร์นละ 2 ครั้ง]",
    "[Twice per turn]": "[เทิร์นละ 2 ครั้ง]",
    "[Center]": "[เซ็นเตอร์]",
    "[Yell]": "[Yell]",
    "[Left Side]": "[ฝั่งซ้าย]",
    "[Right Side]": "[ฝั่งขวา]",
}

EN_SKILL_BRACKET_RE = re.compile(
    r"\[(?:On Enter|On Leave|Live Start|Live Success|Activated|Always|Continuous|"
    r"Once per [Tt]urn|Twice per [Tt]urn|Automatic|Auto|Center|Yell|On Play|Left Side|Right Side)\]"
)

# Longest-first EN → Thai game terms
GLOSSARY: list[tuple[str, str]] = [
    ("Success Live area", "พื้นที่ Live สำเร็จ"),
    ("Live Card Zone", "โซนการ์ด Live"),
    ("Live zone", "โซน Live"),
    ("Left Side area", "พื้นที่ฝั่งซ้าย"),
    ("Left Side", "ฝั่งซ้าย"),
    ("Right Side", "ฝั่งขวา"),
    ("Center area", "พื้นที่เซ็นเตอร์"),
    ("Stage area", "พื้นที่เวที"),
    ("Energy Zone", "โซนพลังงาน"),
    ("Waiting Room", "ห้องรอ"),
    ("Required Hearts", "หัวใจที่ต้องการ"),
    ("required hearts", "หัวใจที่ต้องการ"),
    ("total Live Score", "คะแนน Live รวม"),
    ("Live Score", "คะแนน Live"),
    ("All Hearts", "หัวใจทั้งหมด"),
    ("Main Phase", "เฟสหลัก"),
    ("Live Phase", "เฟส Live"),
    ("Baton Touch", "บาตองทัช"),
    ("Performance", "การแสดง"),
    ("revealed by Yell", "ที่เปิดโดย Yell"),
    ("revealed for Yell", "ที่เปิดเพื่อ Yell"),
    ("entered your Stage this turn", "เข้าสู่เวทีของคุณในเทิร์นนี้"),
    ("differently named", "ที่มีชื่อต่างกัน"),
    ("Cannot attempt a Live", "ไม่สามารถทำ Live ได้"),
    ("While in Center", "ขณะอยู่ที่เซ็นเตอร์"),
    ("your opponent's", "ของฝ่ายตรงข้าม"),
    ("opponent's", "ของฝ่ายตรงข้าม"),
    ("from your Stage", "จากเวทีของคุณ"),
    ("from your Waiting Room", "จากห้องรอของคุณ"),
    ("into your Waiting Room", "เข้าสู่ห้องรอของคุณ"),
    ("on top of your deck", "ไว้บนสุดของเด็คของคุณ"),
    ("on the bottom of your deck", "ไว้ล่างสุดของเด็คของคุณ"),
    ("top 5 cards of your deck", "การ์ด 5 ใบบนสุดของเด็คของคุณ"),
    ("top 3 cards of your deck", "การ์ด 3 ใบบนสุดของเด็คของคุณ"),
    ("top 2 cards of your deck", "การ์ด 2 ใบบนสุดของเด็คของคุณ"),
    ("top of your deck", "บนสุดของเด็ค"),
    ("You may", "คุณอาจ"),
    ("you may", "คุณอาจ"),
    ("mulligan", "มัลลิแกน"),
    ("Choose", "เลือก"),
    ("choose", "เลือก"),
    ("Draw", "จั่ว"),
    ("draw", "จั่ว"),
    ("Shuffle", "สับ"),
    ("shuffle", "สับ"),
    ("Discard", "ทิ้ง"),
    ("discard", "ทิ้ง"),
    ("Return", "คืน"),
    ("return", "คืน"),
    ("Look at", "ดู"),
    ("look at", "ดู"),
    ("Put", "วาง"),
    ("put", "วาง"),
    ("Add", "เพิ่ม"),
    ("add", "เพิ่ม"),
    ("Gain", "ได้รับ"),
    ("gain", "ได้รับ"),
    ("Pay", "จ่าย"),
    ("pay", "จ่าย"),
    ("Until this Live ends", "จนกว่า Live นี้จะจบ"),
    ("until end of turn", "จนจบเทิร์น"),
    ("this turn", "เทิร์นนี้"),
    ("this Live", "Live นี้"),
    ("your Stage", "เวทีของคุณ"),
    ("your hand", "มือของคุณ"),
    ("your deck", "เด็คของคุณ"),
    ("your Waiting Room", "ห้องรอของคุณ"),
    ("Success", "สำเร็จ"),
    ("success", "สำเร็จ"),
    ("Energy", "พลังงาน"),
    ("Members", "สมาชิก"),
    ("Member", "สมาชิก"),
    ("Hearts", "หัวใจ"),
    ("Heart", "หัวใจ"),
    ("Stage", "เวที"),
    ("hand", "มือ"),
    ("deck", "เด็ค"),
    ("Blade", "เบลด"),
    ("Yell", "Yell"),
    ("Wait", "Wait"),
    ("Center", "เซ็นเตอร์"),
    ("Live", "Live"),
    ("score", "คะแนน"),
    ("cost", "คอสต์"),
    ("cards", "การ์ด"),
    ("card", "การ์ด"),
    ("and", "และ"),
    ("or", "หรือ"),
    ("then", "จากนั้น"),
]


def localize_brackets(text: str) -> str:
    out = text
    for en, th in sorted(BRACKET_EN_TO_TH.items(), key=lambda p: len(p[0]), reverse=True):
        out = out.replace(en, th)
    return out


def apply_glossary(text: str) -> str:
    out = text
    for en, th in sorted(GLOSSARY, key=lambda p: len(p[0]), reverse=True):
        out = re.sub(re.escape(en), th, out)
    return out


def load_name_maps() -> dict[str, str]:
    names = json.loads(TH_NAMES.read_text(encoding="utf-8"))
    songs = json.loads(TH_SONGS.read_text(encoding="utf-8"))
    m: dict[str, str] = {}
    m.update(names.get("characters", {}))
    m.update(names.get("characters_jp", {}))
    m.update(names.get("groups", {}))
    m.update(names.get("schools", {}))
    m.update(names.get("subunits", {}))
    for title, entry in songs.get("songs", {}).items():
        th = entry.get("th") if isinstance(entry, dict) else entry
        if th:
            m[title] = th
    return m


def replace_quoted_names(text: str, name_map: dict[str, str]) -> str:
    def repl(match: re.Match[str]) -> str:
        inner = match.group(1)
        if inner in name_map:
            return f'"{name_map[inner]}"'
        return match.group(0)

    return re.sub(r'"([^"]+)"', repl, text)


def load_exact_maps() -> dict[str, str]:
    exact: dict[str, str] = {}
    for path in sorted((ROOT / "locales").glob("batch_*_th_exact.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        if isinstance(data, dict):
            exact.update({str(k): str(v) for k, v in data.items()})
    return exact


def has_en_brackets(text: str) -> bool:
    return bool(EN_SKILL_BRACKET_RE.search(text or ""))


# Latin tokens that are allowed to remain in Thai output (kept brands / skill words).
KEEP_LATIN = re.compile(
    r"\b(?:Blade|Yell|Wait|Live|Ver|CPU|Aqours|Liella!?|Nijigasaki|Hasunosora|QU4RTZ|R3BIRTH|"
    r"DOLLCHESTRA|CatChu!?|A-RISE|BiBi|AZALEA|Printemps|DiverDiva|KALEIDOSCORE|Edel|Note|"
    r"Guilty|Kiss|Sunny|Passion|Saint|Snow)\b"
)


def _scrub_allowed(text: str) -> str:
    """Remove quoted names, bracket labels and kept Latin brands for leak detection."""
    scrub = re.sub(r'"[^"]*"', "", text or "")
    scrub = re.sub(r"\[[^\]]*\]", "", scrub)
    scrub = scrub.replace("μ's", "")
    scrub = KEEP_LATIN.sub("", scrub)
    return scrub


def has_residual_en(text: str) -> bool:
    """True if any English word survives after allowed tokens are scrubbed.

    Used to decide glossary-only vs MT: the glossary replaces game terms but
    leaves connective English ("to", "from", "when", "named", ...), so anything
    with residual Latin words must go through machine translation.
    """
    return bool(re.search(r"[A-Za-z]{2,}", _scrub_allowed(text)))


def has_leak(text: str) -> bool:
    return has_residual_en(text)


def translate_en_body(en: str, cache: dict[str, str], translator: GoogleTranslator) -> str:
    if en in cache:
        return cache[en]
    # Protect brackets
    protected = en
    vault: list[str] = []
    for br in BRACKET_EN_TO_TH:
        if br in protected:
            idx = len(vault)
            vault.append(br)
            protected = protected.replace(br, f"⟦{idx}⟧")
    try:
        th = translator.translate(protected)
    except Exception as e:
        print(f"MT fail: {e} :: {en[:80]}")
        th = protected
    for idx, br in enumerate(vault):
        th = th.replace(f"⟦{idx}⟧", br)
        th = th.replace(f"[[{idx}]]", br)
    th = localize_brackets(th)
    th = apply_glossary(th)
    cache[en] = th
    time.sleep(0.04)
    return th


def build_text_th(en: str, name_map: dict[str, str], exact: dict[str, str], cache: dict[str, str], translator: GoogleTranslator) -> str:
    en = (en or "").strip()
    if not en:
        return ""
    if en in exact:
        return exact[en]
    # Prefer glossary path first
    draft = localize_brackets(en)
    draft = apply_glossary(draft)
    draft = replace_quoted_names(draft, name_map)
    if not has_en_brackets(draft) and not has_residual_en(draft):
        return draft
    # MT remaining unique body
    th = translate_en_body(en, cache, translator)
    th = localize_brackets(th)
    th = apply_glossary(th)
    th = replace_quoted_names(th, name_map)
    return th


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--rebuild-all-th", action="store_true")
    ap.add_argument("--repair-leaks", action="store_true")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    if not args.rebuild_all_th and not args.repair_leaks:
        ap.error("Specify --rebuild-all-th and/or --repair-leaks")

    data = json.loads(CARDS.read_text(encoding="utf-8"))
    cards = data["cards"]
    name_map = load_name_maps()
    exact = load_exact_maps()
    cache: dict[str, str] = {}
    if CACHE.exists():
        cache = json.loads(CACHE.read_text(encoding="utf-8"))
    translator = GoogleTranslator(source="en", target="th")

    updated = 0
    for card in cards:
        en = (card.get("text") or "").strip()
        if not en:
            # JP-only rules: MT from JP → Thai
            jp = (card.get("text_jp") or "").strip()
            if not jp:
                continue
            if args.rebuild_all_th or not (card.get("text_th") or "").strip() or (
                args.repair_leaks and has_leak(card.get("text_th") or "")
            ):
                key = f"jp::{jp}"
                if key not in cache:
                    try:
                        jt = GoogleTranslator(source="ja", target="th")
                        cache[key] = jt.translate(jp)
                        time.sleep(0.05)
                    except Exception:
                        cache[key] = jp
                th = cache[key]
                if card.get("text_th") != th:
                    card["text_th"] = th
                    updated += 1
            continue

        need = args.rebuild_all_th or not (card.get("text_th") or "").strip()
        if args.repair_leaks and (has_en_brackets(card.get("text_th") or "") or has_leak(card.get("text_th") or "")):
            need = True
        if not need:
            continue
        th = build_text_th(en, name_map, exact, cache, translator)
        if card.get("text_th") != th:
            card["text_th"] = th
            updated += 1

    CACHE.write_text(json.dumps(cache, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    if not args.dry_run:
        CARDS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"text_th updated={updated} cache={len(cache)}")


if __name__ == "__main__":
    main()
