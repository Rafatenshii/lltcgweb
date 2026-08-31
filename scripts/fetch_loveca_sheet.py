#!/usr/bin/env python3
"""Download live EN + BR card text from the Loveca translation Google Sheet."""

from __future__ import annotations

import argparse
import csv
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EXPORTS = ROOT / "exports"
DEFAULT_OUT = EXPORTS / "loveca_sheet_latest.csv"
SHEET_ID = "1TDRIBZH82uLviOsqQBAjTgpYRBXsOXwHtsRr9DCYBwQ"
EXPORT_URL = f"https://docs.google.com/spreadsheets/d/{SHEET_ID}/export?format=csv"

CARD_NO_HEADERS = ("card_no", "card_number", "Card No", "card_no ")
TEXT_EN_HEADERS = (
    "text_en_edited",
    "Special Info [EN]",
    "text_en",
    "Special Info EN",
)
TEXT_BR_HEADERS = (
    "text_br_edited",
    "Special Info [BR]",
    "text_br",
    "Special Info BR",
)


def normalize_header(name: str) -> str:
    return (name or "").strip().lstrip("\ufeff")


def pick_column(headers: list[str], candidates: tuple[str, ...]) -> str | None:
    norm = {normalize_header(h): h for h in headers}
    for c in candidates:
        if c in norm:
            return norm[c]
    lower = {normalize_header(h).lower(): h for h in headers}
    for c in candidates:
        if c.lower() in lower:
            return lower[c.lower()]
    return None


def fetch_csv(url: str) -> bytes:
    req = urllib.request.Request(url, headers={"User-Agent": "lltcgweb-fetch/1.0"})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def write_normalized_csv(raw: bytes, out_path: Path) -> dict[str, int]:
    text = raw.decode("utf-8-sig", errors="replace")
    reader = csv.DictReader(text.splitlines())
    if not reader.fieldnames:
        raise SystemExit("Sheet CSV has no header row")
    headers = list(reader.fieldnames)
    col_no = pick_column(headers, CARD_NO_HEADERS)
    col_en = pick_column(headers, TEXT_EN_HEADERS)
    col_br = pick_column(headers, TEXT_BR_HEADERS)
    if not col_no:
        raise SystemExit(f"Missing card id column in: {headers}")
    if not col_en and not col_br:
        raise SystemExit(f"Missing EN/BR text columns in: {headers}")

    out_path.parent.mkdir(parents=True, exist_ok=True)
    stamped = EXPORTS / f"loveca_sheet_{datetime.now(timezone.utc).strftime('%Y%m%d_%H%M%S')}.csv"
    rows_out: list[dict[str, str]] = []
    stats = {"rows": 0, "with_en": 0, "with_br": 0}
    for row in reader:
        no = (row.get(col_no) or "").strip()
        if not no:
            continue
        en = (row.get(col_en) or "").strip() if col_en else ""
        br = (row.get(col_br) or "").strip() if col_br else ""
        rows_out.append({"card_no": no, "text_en_edited": en, "text_br_edited": br})
        stats["rows"] += 1
        if en:
            stats["with_en"] += 1
        if br:
            stats["with_br"] += 1

    fieldnames = ["card_no", "text_en_edited", "text_br_edited"]
    for path in (out_path, stamped):
        with path.open("w", encoding="utf-8", newline="") as fh:
            writer = csv.DictWriter(fh, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(rows_out)

    return stats


def main() -> int:
    parser = argparse.ArgumentParser(description="Fetch Loveca EN+BR sheet CSV")
    parser.add_argument("--out", type=Path, default=DEFAULT_OUT, help="Normalized output CSV")
    parser.add_argument("--url", default=EXPORT_URL, help="Google Sheets CSV export URL")
    args = parser.parse_args()

    try:
        raw = fetch_csv(args.url)
    except urllib.error.URLError as exc:
        print(f"Fetch failed: {exc}", file=sys.stderr)
        return 1

    stats = write_normalized_csv(raw, args.out)
    print(
        f"Fetched {stats['rows']} rows "
        f"({stats['with_en']} with text_en_edited, {stats['with_br']} with text_br_edited)"
    )
    print(f"Wrote {args.out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
