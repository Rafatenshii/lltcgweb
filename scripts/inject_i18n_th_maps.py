#!/usr/bin/env python3
"""Inject TH_NAME_MAP and TH_SONG_MAP into i18n.js from locales/th_names.json
and locales/th_songs.json.

TH_NAME_MAP flattens the `characters` (EN name -> TH) and `characters_jp`
(JP name -> TH) tables from th_names.json into a single lookup used by
cardLocaleName() to resolve a card's Thai display name from either its
name_en or name (JP) field.

TH_SONG_MAP flattens the `songs` table from th_songs.json (EN title -> TH
title) for Live cards, whose name/name_en fields are the song title.
"""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
I18N = ROOT / "i18n.js"
TH_NAMES = ROOT / "locales" / "th_names.json"
TH_SONGS = ROOT / "locales" / "th_songs.json"

names = json.loads(TH_NAMES.read_text(encoding="utf-8"))
songs = json.loads(TH_SONGS.read_text(encoding="utf-8"))

name_map = {}
name_map.update(names.get("characters", {}))
name_map.update(names.get("characters_jp", {}))

song_map = {}
for title, entry in songs.get("songs", {}).items():
    if isinstance(entry, dict):
        th = entry.get("th")
    else:
        th = entry
    if th:
        song_map[title] = th

name_map_js = json.dumps(name_map, ensure_ascii=False, indent=2, sort_keys=True)
song_map_js = json.dumps(song_map, ensure_ascii=False, indent=2, sort_keys=True)

block = (
    "  var TH_NAME_MAP = " + name_map_js + ";\n"
    "  var TH_SONG_MAP = " + song_map_js + ";\n\n"
)

text = I18N.read_text(encoding="utf-8")

# Remove any previously injected maps so this script is idempotent.
# json.dumps(..., indent=2) closes a top-level dict with "}" at column 0
# (no leading spaces), so the closing brace pattern below must match "\n};"
# rather than "\n  };".
text = re.sub(
    r"  var TH_NAME_MAP = \{.*?\n\};\n  var TH_SONG_MAP = \{.*?\n\};\n\n",
    "",
    text,
    flags=re.DOTALL,
)

marker = "  function cardLocaleName(card) {"
if marker not in text:
    raise SystemExit("Could not find cardLocaleName() in i18n.js")

text = text.replace(marker, block + marker, 1)

I18N.write_text(text, encoding="utf-8")
print(f"Injected TH_NAME_MAP ({len(name_map)} entries) and TH_SONG_MAP ({len(song_map)} entries) into {I18N}")
