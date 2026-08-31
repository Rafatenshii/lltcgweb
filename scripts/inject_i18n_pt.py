#!/usr/bin/env python3
"""Inject locales/pt.json into i18n.js STRINGS.pt."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from i18n_inject_lib import LOCALE_ORDER, inject_locale  # noqa: E402

PT_JSON = ROOT / "locales" / "pt.json"


def main() -> int:
    if not PT_JSON.is_file():
        print(f"Missing {PT_JSON}", file=sys.stderr)
        return 1
    data = json.loads(PT_JSON.read_text(encoding="utf-8"))
    order = LOCALE_ORDER[:]
    if "pt" not in order:
        order.append("pt")
    inject_locale("pt", data, locales_js=order)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
