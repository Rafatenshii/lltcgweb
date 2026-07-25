#!/usr/bin/env python3
"""Extract STRINGS.en from i18n.js as JSON (tolerates JS trailing commas)."""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
text = (ROOT / "i18n.js").read_text(encoding="utf-8")
m = re.search(r'"en":\s*(\{)', text)
if not m:
    raise SystemExit("en block not found")
start = m.start(1)
depth = 0
i = start
en_json = None
while i < len(text):
    if text[i] == "{":
        depth += 1
    elif text[i] == "}":
        depth -= 1
        if depth == 0:
            en_json = text[start : i + 1]
            break
    i += 1
if en_json is None:
    raise SystemExit("unbalanced braces")

# i18n.js is a JS object literal — strip trailing commas before JSON parse.
en_json = re.sub(r",(\s*[}\]])", r"\1", en_json)
en = json.loads(en_json)
out = ROOT / "locales" / "en_extracted.json"
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps(en, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def leaf_count(obj) -> int:
    if isinstance(obj, dict):
        return sum(leaf_count(v) for v in obj.values())
    return 1


print(f"Wrote {out} ({leaf_count(en)} leaf keys)")
if "sticker" not in en:
    raise SystemExit("sticker section missing from extracted EN")
print("sticker keys:", ", ".join(sorted(en["sticker"].keys())))
print("hub.stickerShop:", en.get("hub", {}).get("stickerShop"))
