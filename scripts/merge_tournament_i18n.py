#!/usr/bin/env python3
"""Merge Tournament Mode UI strings into locale JSON + inject into i18n.js.

Adds a nested ``tournament`` namespace, ``hub.tournamentModeSubLive``, and
``spectate.listTitleTournament`` for en / ja / es / ko / zh / th.

Usage:
  python scripts/merge_tournament_i18n.py
"""
from __future__ import annotations

import json
import re
import sys
from copy import deepcopy
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))
from i18n_inject_lib import inject_locale  # noqa: E402

I18N = ROOT / "i18n.js"
EN_PATH = ROOT / "locales" / "en_extracted.json"
LOCALE_JSON = {
    "es": ROOT / "locales" / "es.json",
    "ko": ROOT / "locales" / "ko.json",
    "zh": ROOT / "locales" / "zh.json",
    "th": ROOT / "locales" / "th.json",
}


def deep_merge(dst: dict, src: dict) -> dict:
    for k, v in src.items():
        if k in dst and isinstance(dst[k], dict) and isinstance(v, dict):
            deep_merge(dst[k], v)
        else:
            dst[k] = deepcopy(v)
    return dst


def leaf_count(obj) -> int:
    if isinstance(obj, dict):
        return sum(leaf_count(v) for v in obj.values())
    return 1


def leaf_paths(obj, prefix: str = "") -> set[str]:
    out: set[str] = set()
    if isinstance(obj, dict):
        for k, v in obj.items():
            path = f"{prefix}.{k}" if prefix else str(k)
            if isinstance(v, dict):
                out |= leaf_paths(v, path)
            else:
                out.add(path)
    return out


def extract_locale_block(text: str, code: str) -> dict:
    m = re.search(rf'"{re.escape(code)}":\s*(\{{)', text)
    if not m:
        raise SystemExit(f"{code} block not found in i18n.js")
    start = m.start(1)
    depth = 0
    i = start
    block = None
    while i < len(text):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                block = text[start : i + 1]
                break
        i += 1
    if block is None:
        raise SystemExit(f"unbalanced braces for {code}")
    block = re.sub(r",(\s*[}\]])", r"\1", block)
    return json.loads(block)


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def save_json(path: Path, data: dict) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


# ---------------------------------------------------------------------------
# English catalog (nested under tournament + hub / spectate patches)
# ---------------------------------------------------------------------------

EN_TOURNAMENT = {
    "backHub": "← Hub",
    "title": "Tournament Mode",
    "lead": "Schedule events, lock decks, check in, and run a single-elim bracket.",
    "timezoneLabel": "Timezone",
    "timezoneAria": "Display timezone",
    "tzHint": "Times shown in {tz}",
    "tzHintJstFallback": "Times shown in JST",
    "createEvent": "Create event",
    "refresh": "Refresh",
    "filterMode": "Mode",
    "mode": {
        "all": "All",
        "standard": "Standard",
        "starters": "Starters",
        "randomized": "Randomized",
        "free": "Free",
        "freeDeckExperiment": "Free (Deck Experiment)",
    },
    "listEmpty": "No open tournaments yet. Create one to get started.",
    "card": {
        "fee": "fee {n}",
        "watching": "{n} watching",
        "starts": "starts {when}",
        "fog": "fog {fog}",
        "delay": "delay {n}s",
        "metaSep": "·",
    },
    "backBulletin": "← Bulletin",
    "notify": {
        "title": "LLTCG Tournament",
        "checkinOpen": "Check-in open: {title}",
        "checkinSoon": "Check-in soon: {title}",
    },
    "createHeading": "Create tournament",
    "createTzNote": "Start time uses {tz}.",
    "createTzNoteFallback": "Start time uses your selected timezone.",
    "field": {
        "title": "Title",
        "titlePlaceholder": "Friday Night Bracket",
        "startLocal": "Start (local)",
        "checkinMins": "Check-in minutes",
        "minPlayers": "Min players",
        "maxPlayers": "Max players",
        "entryFee": "Entry fee (Coins)",
        "gameMode": "Game mode",
        "format": "Format",
        "matchLength": "Match length",
        "fog": "Fog of war",
        "rules": "Rules template",
        "rulesTitle": "Extra deck rules; only Standard applies for Starters / Randomized",
        "streamDelay": "Stream delay (spectate)",
    },
    "format": {
        "singleElim": "Single elimination",
        "doubleElimBracket": "Double elim (Winners/Losers)",
        "doubleElimLives": "Double elim (2 lives)",
        "swiss": "Swiss",
        "single_elim": "Single elimination",
        "double_elim_bracket": "Double elim (Winners/Losers)",
        "double_elim": "Double elim (2 lives)",
    },
    "bestOf": {
        "1": "Best of 1",
        "3": "Best of 3",
    },
    "fog": {
        "hiddenHands": "Hidden hands (spectators)",
        "openHands": "Open hands",
        "hiddenHandsShort": "hidden hands",
        "openHandsShort": "open hands",
    },
    "rules": {
        "standardOption": "Standard (no extra limits)",
        "pauperOption": "Pauper (N/R)",
        "highlanderOption": "Highlander (1-of)",
        "standard": {
            "label": "Standard",
            "help": "No extra deck limits beyond the selected game mode. Full rarity and normal copy limits apply.",
        },
        "pauper": {
            "label": "Pauper (N/R)",
            "help": "Only lower rarities: N, R, C, U, and CL. Higher rarities (SR+, SEC, etc.) are not allowed.",
        },
        "highlander": {
            "label": "Highlander (1-of)",
            "help": "At most one copy of each card in the whole deck (main + energy). No duplicates of any card number.",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "Game mode already sets deck rules — only Standard applies here.",
    },
    "delay": {
        "none": "None",
        "secs": "{n} seconds",
    },
    "schedule": "Schedule",
    "detail": {
        "host": "Host",
        "hostFallback": "Host",
        "prize": "prize {n}",
        "watching": "watching {n}",
        "starts": "starts {when}",
        "mode": "mode {mode}",
        "rules": "rules {rules}",
        "fog": "fog {fog}",
        "streamDelay": "stream delay {n}s",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "Entrants",
    "bracketHeading": "Bracket",
    "entrantsEmpty": "No entrants",
    "person": {
        "playerFallback": "Player",
    },
    "standingsHeading": "Standings",
    "standings": {
        "record": "{wins}W–{losses}L",
    },
    "action": {
        "register": "Register",
        "registerTip": "Lock in a deck and enter this event. Pays the entry fee into the prize pool if one is set.",
        "checkin": "Check in",
        "checkinTip": "Confirm you are present before the bracket starts. Missing check-in marks you as a no-show.",
        "unregister": "Unregister",
        "unregisterTip": "Leave the event before it starts and refund your entry fee.",
        "checkedIn": "Checked in",
        "checkedInTip": "You are checked in and waiting for the bracket to start.",
        "deposit": "Deposit prize",
        "depositTip": "Add Coins from your balance to this event’s prize pool (host only).",
        "cancel": "Cancel (refund)",
        "cancelTip": "Cancel the tournament and refund entry fees plus remaining host prize deposits.",
        "tick": "Refresh / tick",
        "tickTip": "Refresh this event and advance server timers (check-in window, bracket, room seeding).",
        "join": "Join my match",
        "joinTip": "Enter your ready tournament match room when your bracket game is available.",
        "spectateList": "Spectate matches",
        "spectateListTip": "Browse and watch live matches from this tournament as a spectator.",
    },
    "prompt": {
        "deposit": "Coins to deposit into prize vault:",
        "depositDefault": "1000",
    },
    "confirm": {
        "cancel": "Cancel tournament and refund entrants?",
    },
    "bracket": {
        "empty": "Bracket layout appears once max players / format is set.",
        "previewSuffix": "Preview (names fill in after check-in)",
        "slot": "Slot",
        "tbd": "TBD",
        "bye": "Bye",
        "waiting": "Waiting…",
        "spectate": "Spectate",
        "spectateTip": "Watch this match as a spectator (non-players welcome)",
        "winner": "Winner: {name}",
        "namesLock": "Names lock in at bracket start",
    },
    "formatCaption": {
        "swiss": "Swiss rounds",
        "doubleElimLives": "Double elim (2 lives)",
        "doubleElimBracket": "Double elim (Winners/Losers)",
        "singleElim": "Single elimination",
    },
    "round": {
        "swiss": "Swiss · Round {n}",
        "losersFinal": "Losers Final",
        "losers": "Losers · R{n}",
        "grandFinal": "Grand Final",
        "grandFinalReset": "Grand Final (Reset)",
        "winnersFinal": "Winners Final",
        "semifinals": "Semifinals",
        "roundOf": "Round of {n}",
    },
    "matchStatus": {
        "live": "Live",
        "ready": "Ready",
        "done": "Done",
        "pending": "Upcoming",
    },
    "register": {
        "backEvent": "← Event",
        "heading": "Choose deck to lock in",
        "leadDefault": "This deck is locked for the tournament when you register.",
        "leadPick": "Pick a legal deck to lock in for this event.",
        "leadEmpty": "No eligible deck yet — build one in Deck Builder, then come back.",
        "leadFreePick": "Pick a Deck Experiment preset, a saved account deck, or enter an experiment password.",
        "leadFreeEmpty": "No saved Free decks yet — open Deck Experiment, save a preset (or use a share password), then come back.",
        "noEligible": "No eligible decks for this game mode.",
        "noFreeDecks": "No experiment presets or owned account decks found.",
        "deckFallback": "Deck",
        "metaPreset": "Preset slot {slot}",
        "metaEquipped": "· equipped",
        "metaStarter": "Starter · {label}",
        "metaExperiment": "Deck Experiment · slot {slot}",
        "passwordLabel": "Experiment password",
        "passwordPlaceholder": "Shared Deck Experiment code",
        "withPassword": "Register with password",
        "openDeckBuilder": "Open Deck Builder",
        "openDeckExperiment": "Open Deck Experiment",
    },
    "status": {
        "open": "Open",
        "checkin": "Check-in",
        "running": "Running",
        "finished": "Finished",
        "cancelled": "Cancelled",
    },
    "entrant": {
        "registered": "Registered",
        "checked_in": "Checked in",
        "no_show": "No-show",
        "eliminated": "Eliminated",
        "active": "Active",
    },
    "err": {
        "cancelled": "This tournament was cancelled.",
        "unavailable": "That tournament is no longer available.",
        "cancelRefunded": "Tournament cancelled — entrants refunded.",
        "pickStart": "Pick a start date and time",
        "startTooSoon": "Start time must be at least 1 minute from now",
        "pickStartSoon": "Pick a start time at least 1 minute from now",
        "experimentPassword": "Enter an experiment password",
        "joinHelperMissing": "Join helper missing",
        "spectateHelperMissing": "Spectate helper missing",
    },
    "toast": {
        "noMatchReady": "No tournament match ready",
    },
    "cal": {
        "pickDateTime": "Pick date & time",
        "dialogAria": "Pick start date and time",
        "prevMonth": "Previous month",
        "nextMonth": "Next month",
        "monthFallback": "Month",
        "dow": {
            "su": "Su",
            "mo": "Mo",
            "tu": "Tu",
            "we": "We",
            "th": "Th",
            "fr": "Fr",
            "sa": "Sa",
        },
        "hour": "Hour",
        "min": "Min",
        "cancel": "Cancel",
        "apply": "Apply",
    },
    "tz": {
        "Asia/Tokyo": "Japan (JST)",
        "America/New_York": "US Eastern",
        "America/Chicago": "US Central",
        "America/Denver": "US Mountain",
        "America/Los_Angeles": "US Pacific",
        "America/Toronto": "Canada Eastern",
        "America/Vancouver": "Canada Pacific",
        "Europe/London": "UK (London)",
        "Europe/Paris": "Central Europe",
        "Europe/Berlin": "Berlin",
        "Australia/Sydney": "Sydney",
        "Asia/Singapore": "Singapore",
        "Asia/Seoul": "Korea (KST)",
        "Asia/Shanghai": "China",
        "Asia/Hong_Kong": "Hong Kong",
        "Asia/Bangkok": "Bangkok",
        "Pacific/Auckland": "Auckland",
        "UTC": "UTC",
    },
}

EN_PATCH = {
    "hub": {"tournamentModeSubLive": "Events & brackets"},
    "spectate": {"listTitleTournament": "Spectate tournament"},
    "tournament": EN_TOURNAMENT,
}


# ---------------------------------------------------------------------------
# Translations (same key tree)
# ---------------------------------------------------------------------------

JA_TOURNAMENT = {
    "backHub": "← ハブ",
    "title": "トーナメントモード",
    "lead": "イベントを作成し、デッキをロック、チェックインしてシングルエリムのブラケットを進行します。",
    "timezoneLabel": "タイムゾーン",
    "timezoneAria": "表示タイムゾーン",
    "tzHint": "時刻は{tz}で表示",
    "tzHintJstFallback": "時刻はJSTで表示",
    "createEvent": "イベント作成",
    "refresh": "更新",
    "filterMode": "モード",
    "mode": {
        "all": "すべて",
        "standard": "スタンダード",
        "starters": "スターター",
        "randomized": "ランダム",
        "free": "フリー",
        "freeDeckExperiment": "フリー（デッキ実験）",
    },
    "listEmpty": "開催中のトーナメントはまだありません。作成してみましょう。",
    "card": {
        "fee": "参加費 {n}",
        "watching": "観戦 {n}",
        "starts": "開始 {when}",
        "fog": "フォグ {fog}",
        "delay": "遅延 {n}秒",
        "metaSep": "·",
    },
    "backBulletin": "← 掲示板",
    "notify": {
        "title": "LLTCG トーナメント",
        "checkinOpen": "チェックイン開始: {title}",
        "checkinSoon": "まもなくチェックイン: {title}",
    },
    "createHeading": "トーナメント作成",
    "createTzNote": "開始時刻は{tz}を使います。",
    "createTzNoteFallback": "開始時刻は選択したタイムゾーンを使います。",
    "field": {
        "title": "タイトル",
        "titlePlaceholder": "金曜夜ブラケット",
        "startLocal": "開始（ローカル）",
        "checkinMins": "チェックイン分数",
        "minPlayers": "最少人数",
        "maxPlayers": "最大人数",
        "entryFee": "参加費（コイン）",
        "gameMode": "ゲームモード",
        "format": "形式",
        "matchLength": "マッチ形式",
        "fog": "フォグ・オブ・ウォー",
        "rules": "ルールテンプレ",
        "rulesTitle": "追加デッキ制限。スターター／ランダムではスタンダードのみ適用",
        "streamDelay": "配信遅延（観戦）",
    },
    "format": {
        "singleElim": "シングルエリミネーション",
        "doubleElimBracket": "ダブルエリム（勝者／敗者）",
        "doubleElimLives": "ダブルエリム（2ライフ）",
        "swiss": "スイス",
        "single_elim": "シングルエリミネーション",
        "double_elim_bracket": "ダブルエリム（勝者／敗者）",
        "double_elim": "ダブルエリム（2ライフ）",
    },
    "bestOf": {"1": "ベストオブ1", "3": "ベストオブ3"},
    "fog": {
        "hiddenHands": "手札非公開（観戦者）",
        "openHands": "手札公開",
        "hiddenHandsShort": "手札非公開",
        "openHandsShort": "手札公開",
    },
    "rules": {
        "standardOption": "スタンダード（追加制限なし）",
        "pauperOption": "ポーパー（N/R）",
        "highlanderOption": "ハイランダー（1枚制限）",
        "standard": {
            "label": "スタンダード",
            "help": "選択したゲームモード以外の追加デッキ制限はありません。通常のレアリティと枚数制限が適用されます。",
        },
        "pauper": {
            "label": "ポーパー（N/R）",
            "help": "低いレアリティのみ：N・R・C・U・CL。SR+やSECなど高レアは不可。",
        },
        "highlander": {
            "label": "ハイランダー（1枚制限）",
            "help": "メイン＋エネルギー全体で各カード1枚まで。同番号の重複不可。",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "ゲームモード側でデッキ制限が決まっているため、ここではスタンダードのみです。",
    },
    "delay": {"none": "なし", "secs": "{n}秒"},
    "schedule": "スケジュール",
    "detail": {
        "host": "主催",
        "hostFallback": "主催",
        "prize": "賞金 {n}",
        "watching": "観戦 {n}",
        "starts": "開始 {when}",
        "mode": "モード {mode}",
        "rules": "ルール {rules}",
        "fog": "フォグ {fog}",
        "streamDelay": "配信遅延 {n}秒",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "参加者",
    "bracketHeading": "ブラケット",
    "entrantsEmpty": "参加者なし",
    "person": {"playerFallback": "プレイヤー"},
    "standingsHeading": "順位表",
    "standings": {"record": "{wins}勝–{losses}敗"},
    "action": {
        "register": "登録",
        "registerTip": "デッキをロックして参加。参加費がある場合は賞金プールへ支払います。",
        "checkin": "チェックイン",
        "checkinTip": "ブラケット開始前に出席確認。未チェックインはノーショーになります。",
        "unregister": "登録取消",
        "unregisterTip": "開始前に退出して参加費を返金します。",
        "checkedIn": "チェックイン済",
        "checkedInTip": "チェックイン済み。ブラケット開始を待っています。",
        "deposit": "賞金を預ける",
        "depositTip": "残高からこのイベントの賞金プールへコインを追加（主催のみ）。",
        "cancel": "キャンセル（返金）",
        "cancelTip": "トーナメントを中止し、参加費と残りの主催賞金を返金します。",
        "tick": "更新／進行",
        "tickTip": "イベントを更新し、サーバー側のタイマー（チェックイン・ブラケット・部屋作成）を進めます。",
        "join": "自分の試合に入る",
        "joinTip": "ブラケットの試合が準備できたらトーナメント部屋に入ります。",
        "spectateList": "試合を観戦",
        "spectateListTip": "このトーナメントの進行中マッチを観戦者として見ます。",
    },
    "prompt": {"deposit": "賞金プールに預けるコイン:", "depositDefault": "1000"},
    "confirm": {"cancel": "トーナメントを中止して参加者に返金しますか？"},
    "bracket": {
        "empty": "最大人数／形式が決まるとブラケットが表示されます。",
        "previewSuffix": "プレビュー（チェックイン後に名前が入る）",
        "slot": "枠",
        "tbd": "未定",
        "bye": "不戦勝",
        "waiting": "待機中…",
        "spectate": "観戦",
        "spectateTip": "観戦者としてこの試合を見る（非参加者もOK）",
        "winner": "勝者: {name}",
        "namesLock": "名前はブラケット開始時に確定",
    },
    "formatCaption": {
        "swiss": "スイスラウンド",
        "doubleElimLives": "ダブルエリム（2ライフ）",
        "doubleElimBracket": "ダブルエリム（勝者／敗者）",
        "singleElim": "シングルエリミネーション",
    },
    "round": {
        "swiss": "スイス · ラウンド{n}",
        "losersFinal": "敗者決勝",
        "losers": "敗者 · R{n}",
        "grandFinal": "グランドファイナル",
        "grandFinalReset": "グランドファイナル（リセット）",
        "winnersFinal": "勝者決勝",
        "semifinals": "準決勝",
        "roundOf": "{n}人ラウンド",
    },
    "matchStatus": {
        "live": "進行中",
        "ready": "準備完了",
        "done": "終了",
        "pending": "予定",
    },
    "register": {
        "backEvent": "← イベント",
        "heading": "ロックするデッキを選ぶ",
        "leadDefault": "登録するとこのデッキがトーナメント用にロックされます。",
        "leadPick": "このイベント用に合法デッキを選んでロックしてください。",
        "leadEmpty": "まだ対象デッキがありません — デッキビルダーで作ってから戻ってください。",
        "leadFreePick": "デッキ実験のプリセット、保存済みデッキ、または実験パスワードを入力。",
        "leadFreeEmpty": "フリーデッキがまだありません — デッキ実験でプリセットを保存（または共有パスワード）してから戻ってください。",
        "noEligible": "このゲームモード用の対象デッキがありません。",
        "noFreeDecks": "実験プリセットや所持デッキが見つかりません。",
        "deckFallback": "デッキ",
        "metaPreset": "プリセット枠 {slot}",
        "metaEquipped": "· 装備中",
        "metaStarter": "スターター · {label}",
        "metaExperiment": "デッキ実験 · 枠 {slot}",
        "passwordLabel": "実験パスワード",
        "passwordPlaceholder": "共有デッキ実験コード",
        "withPassword": "パスワードで登録",
        "openDeckBuilder": "デッキビルダーを開く",
        "openDeckExperiment": "デッキ実験を開く",
    },
    "status": {
        "open": "募集中",
        "checkin": "チェックイン",
        "running": "進行中",
        "finished": "終了",
        "cancelled": "中止",
    },
    "entrant": {
        "registered": "登録済",
        "checked_in": "チェックイン済",
        "no_show": "ノーショー",
        "eliminated": "敗退",
        "active": "出場中",
    },
    "err": {
        "cancelled": "このトーナメントは中止されました。",
        "unavailable": "そのトーナメントはもう利用できません。",
        "cancelRefunded": "トーナメント中止 — 参加者へ返金しました。",
        "pickStart": "開始日時を選んでください",
        "startTooSoon": "開始は現在から1分以上先にしてください",
        "pickStartSoon": "開始時刻は現在から1分以上先を選んでください",
        "experimentPassword": "実験パスワードを入力してください",
        "joinHelperMissing": "参加ヘルパーがありません",
        "spectateHelperMissing": "観戦ヘルパーがありません",
    },
    "toast": {"noMatchReady": "トーナメント試合の準備ができていません"},
    "cal": {
        "pickDateTime": "日時を選択",
        "dialogAria": "開始日時を選択",
        "prevMonth": "前の月",
        "nextMonth": "次の月",
        "monthFallback": "月",
        "dow": {
            "su": "日",
            "mo": "月",
            "tu": "火",
            "we": "水",
            "th": "木",
            "fr": "金",
            "sa": "土",
        },
        "hour": "時",
        "min": "分",
        "cancel": "キャンセル",
        "apply": "適用",
    },
    "tz": {
        "Asia/Tokyo": "日本（JST）",
        "America/New_York": "米国東部",
        "America/Chicago": "米国中部",
        "America/Denver": "米国山地",
        "America/Los_Angeles": "米国太平洋",
        "America/Toronto": "カナダ東部",
        "America/Vancouver": "カナダ太平洋",
        "Europe/London": "英国（ロンドン）",
        "Europe/Paris": "中央ヨーロッパ",
        "Europe/Berlin": "ベルリン",
        "Australia/Sydney": "シドニー",
        "Asia/Singapore": "シンガポール",
        "Asia/Seoul": "韓国（KST）",
        "Asia/Shanghai": "中国",
        "Asia/Hong_Kong": "香港",
        "Asia/Bangkok": "バンコク",
        "Pacific/Auckland": "オークランド",
        "UTC": "UTC",
    },
}

ES_TOURNAMENT = {
    "backHub": "← Hub",
    "title": "Modo torneo",
    "lead": "Programa eventos, fija mazos, haz check-in y juega un bracket de eliminación simple.",
    "timezoneLabel": "Zona horaria",
    "timezoneAria": "Zona horaria de visualización",
    "tzHint": "Horas mostradas en {tz}",
    "tzHintJstFallback": "Horas mostradas en JST",
    "createEvent": "Crear evento",
    "refresh": "Actualizar",
    "filterMode": "Modo",
    "mode": {
        "all": "Todos",
        "standard": "Estándar",
        "starters": "Iniciales",
        "randomized": "Aleatorio",
        "free": "Libre",
        "freeDeckExperiment": "Libre (Experimento de mazo)",
    },
    "listEmpty": "Aún no hay torneos abiertos. Crea uno para empezar.",
    "card": {
        "fee": "cuota {n}",
        "watching": "{n} viendo",
        "starts": "empieza {when}",
        "fog": "niebla {fog}",
        "delay": "retraso {n}s",
        "metaSep": "·",
    },
    "backBulletin": "← Tablón",
    "notify": {
        "title": "Torneo LLTCG",
        "checkinOpen": "Check-in abierto: {title}",
        "checkinSoon": "Check-in pronto: {title}",
    },
    "createHeading": "Crear torneo",
    "createTzNote": "La hora de inicio usa {tz}.",
    "createTzNoteFallback": "La hora de inicio usa tu zona horaria seleccionada.",
    "field": {
        "title": "Título",
        "titlePlaceholder": "Bracket del viernes",
        "startLocal": "Inicio (local)",
        "checkinMins": "Minutos de check-in",
        "minPlayers": "Jugadores mín.",
        "maxPlayers": "Jugadores máx.",
        "entryFee": "Cuota de entrada (Coins)",
        "gameMode": "Modo de juego",
        "format": "Formato",
        "matchLength": "Duración del partido",
        "fog": "Niebla de guerra",
        "rules": "Plantilla de reglas",
        "rulesTitle": "Reglas extra de mazo; solo Estándar aplica en Iniciales / Aleatorio",
        "streamDelay": "Retraso de stream (espectar)",
    },
    "format": {
        "singleElim": "Eliminación simple",
        "doubleElimBracket": "Doble elim (Ganadores/Perdedores)",
        "doubleElimLives": "Doble elim (2 vidas)",
        "swiss": "Suizo",
        "single_elim": "Eliminación simple",
        "double_elim_bracket": "Doble elim (Ganadores/Perdedores)",
        "double_elim": "Doble elim (2 vidas)",
    },
    "bestOf": {"1": "Al mejor de 1", "3": "Al mejor de 3"},
    "fog": {
        "hiddenHands": "Manos ocultas (espectadores)",
        "openHands": "Manos abiertas",
        "hiddenHandsShort": "manos ocultas",
        "openHandsShort": "manos abiertas",
    },
    "rules": {
        "standardOption": "Estándar (sin límites extra)",
        "pauperOption": "Pauper (N/R)",
        "highlanderOption": "Highlander (1 copia)",
        "standard": {
            "label": "Estándar",
            "help": "Sin límites de mazo extra más allá del modo de juego. Rarity y copias normales aplican.",
        },
        "pauper": {
            "label": "Pauper (N/R)",
            "help": "Solo rarezas bajas: N, R, C, U y CL. Rarezas altas (SR+, SEC, etc.) no permitidas.",
        },
        "highlander": {
            "label": "Highlander (1 copia)",
            "help": "Como máximo una copia de cada carta en todo el mazo (principal + energía). Sin duplicados de número.",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "El modo de juego ya fija las reglas de mazo — aquí solo aplica Estándar.",
    },
    "delay": {"none": "Ninguno", "secs": "{n} segundos"},
    "schedule": "Programar",
    "detail": {
        "host": "Anfitrión",
        "hostFallback": "Anfitrión",
        "prize": "premio {n}",
        "watching": "viendo {n}",
        "starts": "empieza {when}",
        "mode": "modo {mode}",
        "rules": "reglas {rules}",
        "fog": "niebla {fog}",
        "streamDelay": "retraso de stream {n}s",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "Inscritos",
    "bracketHeading": "Bracket",
    "entrantsEmpty": "Sin inscritos",
    "person": {"playerFallback": "Jugador"},
    "standingsHeading": "Clasificación",
    "standings": {"record": "{wins}V–{losses}D"},
    "action": {
        "register": "Inscribirse",
        "registerTip": "Fija un mazo y entra al evento. Paga la cuota al pozo de premios si hay una.",
        "checkin": "Check-in",
        "checkinTip": "Confirma que estás presente antes de que empiece el bracket. Sin check-in = no-show.",
        "unregister": "Cancelar inscripción",
        "unregisterTip": "Sal del evento antes de que empiece y recupera tu cuota.",
        "checkedIn": "Check-in hecho",
        "checkedInTip": "Estás en check-in y esperando el inicio del bracket.",
        "deposit": "Depositar premio",
        "depositTip": "Añade Coins de tu saldo al pozo de premios (solo anfitrión).",
        "cancel": "Cancelar (reembolso)",
        "cancelTip": "Cancela el torneo y reembolsa cuotas más depósitos de premio restantes del anfitrión.",
        "tick": "Actualizar / tick",
        "tickTip": "Actualiza el evento y avanza temporizadores del servidor (check-in, bracket, salas).",
        "join": "Unirme a mi partida",
        "joinTip": "Entra a tu sala de torneo cuando tu partido del bracket esté listo.",
        "spectateList": "Espectar partidas",
        "spectateListTip": "Explora y mira partidas en vivo de este torneo como espectador.",
    },
    "prompt": {"deposit": "Coins a depositar en el pozo de premios:", "depositDefault": "1000"},
    "confirm": {"cancel": "¿Cancelar el torneo y reembolsar a los inscritos?"},
    "bracket": {
        "empty": "El bracket aparece cuando se fijan jugadores máx. / formato.",
        "previewSuffix": "Vista previa (nombres tras el check-in)",
        "slot": "Plaza",
        "tbd": "PDT",
        "bye": "Bye",
        "waiting": "Esperando…",
        "spectate": "Espectar",
        "spectateTip": "Mira este partido como espectador (no jugadores bienvenidos)",
        "winner": "Ganador: {name}",
        "namesLock": "Los nombres se fijan al iniciar el bracket",
    },
    "formatCaption": {
        "swiss": "Rondas suizas",
        "doubleElimLives": "Doble elim (2 vidas)",
        "doubleElimBracket": "Doble elim (Ganadores/Perdedores)",
        "singleElim": "Eliminación simple",
    },
    "round": {
        "swiss": "Suizo · Ronda {n}",
        "losersFinal": "Final de perdedores",
        "losers": "Perdedores · R{n}",
        "grandFinal": "Gran final",
        "grandFinalReset": "Gran final (reset)",
        "winnersFinal": "Final de ganadores",
        "semifinals": "Semifinales",
        "roundOf": "Ronda de {n}",
    },
    "matchStatus": {
        "live": "En vivo",
        "ready": "Listo",
        "done": "Hecho",
        "pending": "Próximo",
    },
    "register": {
        "backEvent": "← Evento",
        "heading": "Elige el mazo a fijar",
        "leadDefault": "Este mazo queda fijado para el torneo al inscribirte.",
        "leadPick": "Elige un mazo legal para fijar en este evento.",
        "leadEmpty": "Aún no hay mazo válido — constrúyelo en el Constructor y vuelve.",
        "leadFreePick": "Elige un preset de Experimento, un mazo de cuenta o una contraseña de experimento.",
        "leadFreeEmpty": "Aún no hay mazos libres — abre Experimento, guarda un preset (o usa una contraseña) y vuelve.",
        "noEligible": "No hay mazos aptos para este modo.",
        "noFreeDecks": "No se encontraron presets de experimento ni mazos de cuenta.",
        "deckFallback": "Mazo",
        "metaPreset": "Slot de preset {slot}",
        "metaEquipped": "· equipado",
        "metaStarter": "Inicial · {label}",
        "metaExperiment": "Experimento de mazo · slot {slot}",
        "passwordLabel": "Contraseña de experimento",
        "passwordPlaceholder": "Código compartido de Experimento",
        "withPassword": "Inscribirse con contraseña",
        "openDeckBuilder": "Abrir Constructor de mazos",
        "openDeckExperiment": "Abrir Experimento de mazo",
    },
    "status": {
        "open": "Abierto",
        "checkin": "Check-in",
        "running": "En curso",
        "finished": "Finalizado",
        "cancelled": "Cancelado",
    },
    "entrant": {
        "registered": "Inscrito",
        "checked_in": "Check-in hecho",
        "no_show": "No presentado",
        "eliminated": "Eliminado",
        "active": "Activo",
    },
    "err": {
        "cancelled": "Este torneo fue cancelado.",
        "unavailable": "Ese torneo ya no está disponible.",
        "cancelRefunded": "Torneo cancelado — inscritos reembolsados.",
        "pickStart": "Elige fecha y hora de inicio",
        "startTooSoon": "El inicio debe ser al menos 1 minuto desde ahora",
        "pickStartSoon": "Elige una hora de inicio al menos 1 minuto desde ahora",
        "experimentPassword": "Introduce una contraseña de experimento",
        "joinHelperMissing": "Falta el asistente de unión",
        "spectateHelperMissing": "Falta el asistente de espectar",
    },
    "toast": {"noMatchReady": "Ninguna partida de torneo lista"},
    "cal": {
        "pickDateTime": "Elegir fecha y hora",
        "dialogAria": "Elegir fecha y hora de inicio",
        "prevMonth": "Mes anterior",
        "nextMonth": "Mes siguiente",
        "monthFallback": "Mes",
        "dow": {
            "su": "Do",
            "mo": "Lu",
            "tu": "Ma",
            "we": "Mi",
            "th": "Ju",
            "fr": "Vi",
            "sa": "Sa",
        },
        "hour": "Hora",
        "min": "Min",
        "cancel": "Cancelar",
        "apply": "Aplicar",
    },
    "tz": {
        "Asia/Tokyo": "Japón (JST)",
        "America/New_York": "EE. UU. Este",
        "America/Chicago": "EE. UU. Central",
        "America/Denver": "EE. UU. Montaña",
        "America/Los_Angeles": "EE. UU. Pacífico",
        "America/Toronto": "Canadá Este",
        "America/Vancouver": "Canadá Pacífico",
        "Europe/London": "Reino Unido (Londres)",
        "Europe/Paris": "Europa Central",
        "Europe/Berlin": "Berlín",
        "Australia/Sydney": "Sídney",
        "Asia/Singapore": "Singapur",
        "Asia/Seoul": "Corea (KST)",
        "Asia/Shanghai": "China",
        "Asia/Hong_Kong": "Hong Kong",
        "Asia/Bangkok": "Bangkok",
        "Pacific/Auckland": "Auckland",
        "UTC": "UTC",
    },
}

KO_TOURNAMENT = {
    "backHub": "← 허브",
    "title": "토너먼트 모드",
    "lead": "이벤트를 잡고, 덱을 잠그고, 체크인한 뒤 싱글 엘리미네이션 브래킷을 진행하세요.",
    "timezoneLabel": "시간대",
    "timezoneAria": "표시 시간대",
    "tzHint": "시간은 {tz} 기준",
    "tzHintJstFallback": "시간은 JST 기준",
    "createEvent": "이벤트 만들기",
    "refresh": "새로고침",
    "filterMode": "모드",
    "mode": {
        "all": "전체",
        "standard": "스탠다드",
        "starters": "스타터",
        "randomized": "랜덤",
        "free": "프리",
        "freeDeckExperiment": "프리 (덱 실험)",
    },
    "listEmpty": "아직 열린 토너먼트가 없습니다. 하나 만들어 보세요.",
    "card": {
        "fee": "참가비 {n}",
        "watching": "관전 {n}",
        "starts": "시작 {when}",
        "fog": "포그 {fog}",
        "delay": "지연 {n}초",
        "metaSep": "·",
    },
    "backBulletin": "← 게시판",
    "notify": {
        "title": "LLTCG 토너먼트",
        "checkinOpen": "체크인 시작: {title}",
        "checkinSoon": "곧 체크인: {title}",
    },
    "createHeading": "토너먼트 만들기",
    "createTzNote": "시작 시간은 {tz}를 사용합니다.",
    "createTzNoteFallback": "시작 시간은 선택한 시간대를 사용합니다.",
    "field": {
        "title": "제목",
        "titlePlaceholder": "금요일 밤 브래킷",
        "startLocal": "시작 (로컬)",
        "checkinMins": "체크인 분",
        "minPlayers": "최소 인원",
        "maxPlayers": "최대 인원",
        "entryFee": "참가비 (코인)",
        "gameMode": "게임 모드",
        "format": "형식",
        "matchLength": "매치 길이",
        "fog": "전장의 안개",
        "rules": "규칙 템플릿",
        "rulesTitle": "추가 덱 규칙; 스타터/랜덤은 스탠다드만 적용",
        "streamDelay": "스트림 지연 (관전)",
    },
    "format": {
        "singleElim": "싱글 엘리미네이션",
        "doubleElimBracket": "더블 엘림 (승자/패자)",
        "doubleElimLives": "더블 엘림 (2라이프)",
        "swiss": "스위스",
        "single_elim": "싱글 엘리미네이션",
        "double_elim_bracket": "더블 엘림 (승자/패자)",
        "double_elim": "더블 엘림 (2라이프)",
    },
    "bestOf": {"1": "베스트 오브 1", "3": "베스트 오브 3"},
    "fog": {
        "hiddenHands": "손패 비공개 (관전자)",
        "openHands": "손패 공개",
        "hiddenHandsShort": "손패 비공개",
        "openHandsShort": "손패 공개",
    },
    "rules": {
        "standardOption": "스탠다드 (추가 제한 없음)",
        "pauperOption": "파우퍼 (N/R)",
        "highlanderOption": "하이랜더 (1장)",
        "standard": {
            "label": "스탠다드",
            "help": "선택한 게임 모드 외 추가 덱 제한 없음. 일반 레어도·장수 제한 적용.",
        },
        "pauper": {
            "label": "파우퍼 (N/R)",
            "help": "낮은 레어도만: N, R, C, U, CL. SR+·SEC 등 고레어 불가.",
        },
        "highlander": {
            "label": "하이랜더 (1장)",
            "help": "메인+에너지 전체에서 각 카드 1장까지. 같은 번호 중복 불가.",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "게임 모드가 이미 덱 규칙을 정합니다 — 여기서는 스탠다드만 적용됩니다.",
    },
    "delay": {"none": "없음", "secs": "{n}초"},
    "schedule": "일정 잡기",
    "detail": {
        "host": "주최",
        "hostFallback": "주최",
        "prize": "상금 {n}",
        "watching": "관전 {n}",
        "starts": "시작 {when}",
        "mode": "모드 {mode}",
        "rules": "규칙 {rules}",
        "fog": "포그 {fog}",
        "streamDelay": "스트림 지연 {n}초",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "참가자",
    "bracketHeading": "브래킷",
    "entrantsEmpty": "참가자 없음",
    "person": {"playerFallback": "플레이어"},
    "standingsHeading": "순위표",
    "standings": {"record": "{wins}승–{losses}패"},
    "action": {
        "register": "등록",
        "registerTip": "덱을 잠그고 참가합니다. 참가비가 있으면 상금 풀에 납부합니다.",
        "checkin": "체크인",
        "checkinTip": "브래킷 시작 전 출석 확인. 미체크인은 노쇼 처리됩니다.",
        "unregister": "등록 취소",
        "unregisterTip": "시작 전에 나와 참가비를 환불받습니다.",
        "checkedIn": "체크인 완료",
        "checkedInTip": "체크인했습니다. 브래킷 시작을 기다리는 중.",
        "deposit": "상금 입금",
        "depositTip": "잔액 코인을 이 이벤트의 상금 풀에 추가 (주최만).",
        "cancel": "취소 (환불)",
        "cancelTip": "토너먼트를 취소하고 참가비와 남은 주최 상금을 환불합니다.",
        "tick": "새로고침 / 틱",
        "tickTip": "이벤트를 갱신하고 서버 타이머(체크인·브래킷·방 배정)를 진행합니다.",
        "join": "내 매치 참가",
        "joinTip": "브래킷 경기가 준비되면 토너먼트 방에 들어갑니다.",
        "spectateList": "매치 관전",
        "spectateListTip": "이 토너먼트의 진행 중 매치를 관전자로 봅니다.",
    },
    "prompt": {"deposit": "상금 금고에 넣을 코인:", "depositDefault": "1000"},
    "confirm": {"cancel": "토너먼트를 취소하고 참가자를 환불할까요?"},
    "bracket": {
        "empty": "최대 인원/형식이 정해지면 브래킷이 표시됩니다.",
        "previewSuffix": "미리보기 (체크인 후 이름 표시)",
        "slot": "슬롯",
        "tbd": "미정",
        "bye": "부전승",
        "waiting": "대기 중…",
        "spectate": "관전",
        "spectateTip": "관전자로 이 경기를 봅니다 (비참가자 환영)",
        "winner": "승자: {name}",
        "namesLock": "이름은 브래킷 시작 시 확정",
    },
    "formatCaption": {
        "swiss": "스위스 라운드",
        "doubleElimLives": "더블 엘림 (2라이프)",
        "doubleElimBracket": "더블 엘림 (승자/패자)",
        "singleElim": "싱글 엘리미네이션",
    },
    "round": {
        "swiss": "스위스 · 라운드 {n}",
        "losersFinal": "패자 결승",
        "losers": "패자 · R{n}",
        "grandFinal": "그랜드 파이널",
        "grandFinalReset": "그랜드 파이널 (리셋)",
        "winnersFinal": "승자 결승",
        "semifinals": "준결승",
        "roundOf": "{n}강",
    },
    "matchStatus": {
        "live": "진행 중",
        "ready": "준비됨",
        "done": "종료",
        "pending": "예정",
    },
    "register": {
        "backEvent": "← 이벤트",
        "heading": "잠글 덱 선택",
        "leadDefault": "등록하면 이 덱이 토너먼트용으로 잠깁니다.",
        "leadPick": "이 이벤트에 쓸 합법 덱을 골라 잠그세요.",
        "leadEmpty": "아직 대상 덱이 없습니다 — 덱 빌더에서 만든 뒤 돌아오세요.",
        "leadFreePick": "덱 실험 프리셋, 저장 덱, 또는 실험 비밀번호를 입력하세요.",
        "leadFreeEmpty": "저장된 프리 덱이 없습니다 — 덱 실험에서 프리셋을 저장(또는 공유 비밀번호)한 뒤 돌아오세요.",
        "noEligible": "이 게임 모드에 맞는 덱이 없습니다.",
        "noFreeDecks": "실험 프리셋이나 보유 계정 덱을 찾지 못했습니다.",
        "deckFallback": "덱",
        "metaPreset": "프리셋 슬롯 {slot}",
        "metaEquipped": "· 장착 중",
        "metaStarter": "스타터 · {label}",
        "metaExperiment": "덱 실험 · 슬롯 {slot}",
        "passwordLabel": "실험 비밀번호",
        "passwordPlaceholder": "공유 덱 실험 코드",
        "withPassword": "비밀번호로 등록",
        "openDeckBuilder": "덱 빌더 열기",
        "openDeckExperiment": "덱 실험 열기",
    },
    "status": {
        "open": "모집 중",
        "checkin": "체크인",
        "running": "진행 중",
        "finished": "종료",
        "cancelled": "취소됨",
    },
    "entrant": {
        "registered": "등록됨",
        "checked_in": "체크인됨",
        "no_show": "노쇼",
        "eliminated": "탈락",
        "active": "활성",
    },
    "err": {
        "cancelled": "이 토너먼트가 취소되었습니다.",
        "unavailable": "해당 토너먼트는 더 이상 사용할 수 없습니다.",
        "cancelRefunded": "토너먼트 취소 — 참가자에게 환불했습니다.",
        "pickStart": "시작 날짜와 시간을 선택하세요",
        "startTooSoon": "시작은 지금부터 최소 1분 뒤여야 합니다",
        "pickStartSoon": "시작 시간은 지금부터 최소 1분 뒤로 선택하세요",
        "experimentPassword": "실험 비밀번호를 입력하세요",
        "joinHelperMissing": "참가 도우미가 없습니다",
        "spectateHelperMissing": "관전 도우미가 없습니다",
    },
    "toast": {"noMatchReady": "준비된 토너먼트 매치가 없습니다"},
    "cal": {
        "pickDateTime": "날짜·시간 선택",
        "dialogAria": "시작 날짜와 시간 선택",
        "prevMonth": "이전 달",
        "nextMonth": "다음 달",
        "monthFallback": "월",
        "dow": {
            "su": "일",
            "mo": "월",
            "tu": "화",
            "we": "수",
            "th": "목",
            "fr": "금",
            "sa": "토",
        },
        "hour": "시",
        "min": "분",
        "cancel": "취소",
        "apply": "적용",
    },
    "tz": {
        "Asia/Tokyo": "일본 (JST)",
        "America/New_York": "미국 동부",
        "America/Chicago": "미국 중부",
        "America/Denver": "미국 산지",
        "America/Los_Angeles": "미국 서부",
        "America/Toronto": "캐나다 동부",
        "America/Vancouver": "캐나다 서부",
        "Europe/London": "영국 (런던)",
        "Europe/Paris": "중부 유럽",
        "Europe/Berlin": "베를린",
        "Australia/Sydney": "시드니",
        "Asia/Singapore": "싱가포르",
        "Asia/Seoul": "한국 (KST)",
        "Asia/Shanghai": "중국",
        "Asia/Hong_Kong": "홍콩",
        "Asia/Bangkok": "방콕",
        "Pacific/Auckland": "오클랜드",
        "UTC": "UTC",
    },
}

ZH_TOURNAMENT = {
    "backHub": "← 主页",
    "title": "锦标赛模式",
    "lead": "安排赛事、锁定卡组、签到，并进行单败淘汰对阵。",
    "timezoneLabel": "时区",
    "timezoneAria": "显示时区",
    "tzHint": "时间按 {tz} 显示",
    "tzHintJstFallback": "时间按 JST 显示",
    "createEvent": "创建赛事",
    "refresh": "刷新",
    "filterMode": "模式",
    "mode": {
        "all": "全部",
        "standard": "标准",
        "starters": "新手",
        "randomized": "随机",
        "free": "自由",
        "freeDeckExperiment": "自由（卡组实验）",
    },
    "listEmpty": "暂无开放的锦标赛。创建一个开始吧。",
    "card": {
        "fee": "报名费 {n}",
        "watching": "{n} 人观战",
        "starts": "开始 {when}",
        "fog": "迷雾 {fog}",
        "delay": "延迟 {n}秒",
        "metaSep": "·",
    },
    "backBulletin": "← 公告板",
    "notify": {
        "title": "LLTCG 锦标赛",
        "checkinOpen": "签到已开始：{title}",
        "checkinSoon": "即将签到：{title}",
    },
    "createHeading": "创建锦标赛",
    "createTzNote": "开始时间使用 {tz}。",
    "createTzNoteFallback": "开始时间使用你选择的时区。",
    "field": {
        "title": "标题",
        "titlePlaceholder": "周五夜对阵",
        "startLocal": "开始（本地）",
        "checkinMins": "签到分钟数",
        "minPlayers": "最少人数",
        "maxPlayers": "最多人数",
        "entryFee": "报名费（Coins）",
        "gameMode": "游戏模式",
        "format": "赛制",
        "matchLength": "对局局数",
        "fog": "战争迷雾",
        "rules": "规则模板",
        "rulesTitle": "额外卡组规则；新手/随机仅适用标准",
        "streamDelay": "直播延迟（观战）",
    },
    "format": {
        "singleElim": "单败淘汰",
        "doubleElimBracket": "双败（胜者/败者）",
        "doubleElimLives": "双败（2命）",
        "swiss": "瑞士轮",
        "single_elim": "单败淘汰",
        "double_elim_bracket": "双败（胜者/败者）",
        "double_elim": "双败（2命）",
    },
    "bestOf": {"1": "一局定胜负", "3": "三局两胜"},
    "fog": {
        "hiddenHands": "隐藏手牌（观众）",
        "openHands": "公开手牌",
        "hiddenHandsShort": "隐藏手牌",
        "openHandsShort": "公开手牌",
    },
    "rules": {
        "standardOption": "标准（无额外限制）",
        "pauperOption": "贫民（N/R）",
        "highlanderOption": "高地人（各1）",
        "standard": {
            "label": "标准",
            "help": "除所选游戏模式外无额外卡组限制。适用完整稀有度与常规张数限制。",
        },
        "pauper": {
            "label": "贫民（N/R）",
            "help": "仅较低稀有度：N、R、C、U、CL。不允许更高稀有度（SR+、SEC 等）。",
        },
        "highlander": {
            "label": "高地人（各1）",
            "help": "主卡组+能量整副中每种卡最多1张。不允许同编号重复。",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "游戏模式已设定卡组规则 — 此处仅适用标准。",
    },
    "delay": {"none": "无", "secs": "{n} 秒"},
    "schedule": "安排",
    "detail": {
        "host": "主办",
        "hostFallback": "主办",
        "prize": "奖金 {n}",
        "watching": "观战 {n}",
        "starts": "开始 {when}",
        "mode": "模式 {mode}",
        "rules": "规则 {rules}",
        "fog": "迷雾 {fog}",
        "streamDelay": "直播延迟 {n}秒",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "参赛者",
    "bracketHeading": "对阵表",
    "entrantsEmpty": "暂无参赛者",
    "person": {"playerFallback": "玩家"},
    "standingsHeading": "积分榜",
    "standings": {"record": "{wins}胜–{losses}负"},
    "action": {
        "register": "报名",
        "registerTip": "锁定卡组并报名。若有报名费将计入奖池。",
        "checkin": "签到",
        "checkinTip": "在对阵开始前确认到场。未签到视为缺席。",
        "unregister": "取消报名",
        "unregisterTip": "在开始前退出并退还报名费。",
        "checkedIn": "已签到",
        "checkedInTip": "你已签到，等待对阵开始。",
        "deposit": "存入奖金",
        "depositTip": "从余额向本赛事奖池添加 Coins（仅主办）。",
        "cancel": "取消（退款）",
        "cancelTip": "取消锦标赛并退还报名费及剩余主办奖金。",
        "tick": "刷新 / 推进",
        "tickTip": "刷新本赛事并推进服务器计时（签到窗口、对阵、房间分配）。",
        "join": "加入我的对局",
        "joinTip": "当你的对阵局准备好时进入锦标赛房间。",
        "spectateList": "观战对局",
        "spectateListTip": "以观众身份浏览并观看本锦标赛的进行中对局。",
    },
    "prompt": {"deposit": "存入奖池的 Coins 数量：", "depositDefault": "1000"},
    "confirm": {"cancel": "取消锦标赛并为参赛者退款？"},
    "bracket": {
        "empty": "设定最多人数/赛制后显示对阵表。",
        "previewSuffix": "预览（签到后填入名字）",
        "slot": "席位",
        "tbd": "待定",
        "bye": "轮空",
        "waiting": "等待中…",
        "spectate": "观战",
        "spectateTip": "以观众身份观看本局（非参赛者也可）",
        "winner": "胜者：{name}",
        "namesLock": "名字在对阵开始时锁定",
    },
    "formatCaption": {
        "swiss": "瑞士轮",
        "doubleElimLives": "双败（2命）",
        "doubleElimBracket": "双败（胜者/败者）",
        "singleElim": "单败淘汰",
    },
    "round": {
        "swiss": "瑞士 · 第{n}轮",
        "losersFinal": "败者决赛",
        "losers": "败者 · R{n}",
        "grandFinal": "总决赛",
        "grandFinalReset": "总决赛（重置）",
        "winnersFinal": "胜者决赛",
        "semifinals": "半决赛",
        "roundOf": "{n}强",
    },
    "matchStatus": {
        "live": "进行中",
        "ready": "就绪",
        "done": "结束",
        "pending": "即将开始",
    },
    "register": {
        "backEvent": "← 赛事",
        "heading": "选择要锁定的卡组",
        "leadDefault": "报名后此卡组将锁定用于锦标赛。",
        "leadPick": "为本赛事选择合法卡组并锁定。",
        "leadEmpty": "尚无合格卡组 — 请先在卡组构建器制作后再回来。",
        "leadFreePick": "选择卡组实验预设、已存账号卡组，或输入实验密码。",
        "leadFreeEmpty": "尚无已存自由卡组 — 打开卡组实验保存预设（或使用分享密码）后再回来。",
        "noEligible": "此游戏模式没有合格卡组。",
        "noFreeDecks": "未找到实验预设或账号卡组。",
        "deckFallback": "卡组",
        "metaPreset": "预设栏位 {slot}",
        "metaEquipped": "· 已装备",
        "metaStarter": "新手 · {label}",
        "metaExperiment": "卡组实验 · 栏位 {slot}",
        "passwordLabel": "实验密码",
        "passwordPlaceholder": "分享的卡组实验代码",
        "withPassword": "用密码报名",
        "openDeckBuilder": "打开卡组构建器",
        "openDeckExperiment": "打开卡组实验",
    },
    "status": {
        "open": "开放",
        "checkin": "签到",
        "running": "进行中",
        "finished": "已结束",
        "cancelled": "已取消",
    },
    "entrant": {
        "registered": "已报名",
        "checked_in": "已签到",
        "no_show": "缺席",
        "eliminated": "已淘汰",
        "active": "进行中",
    },
    "err": {
        "cancelled": "此锦标赛已取消。",
        "unavailable": "该锦标赛已不可用。",
        "cancelRefunded": "锦标赛已取消 — 已为参赛者退款。",
        "pickStart": "请选择开始日期与时间",
        "startTooSoon": "开始时间须至少在1分钟之后",
        "pickStartSoon": "请选择至少1分钟之后的开始时间",
        "experimentPassword": "请输入实验密码",
        "joinHelperMissing": "缺少加入助手",
        "spectateHelperMissing": "缺少观战助手",
    },
    "toast": {"noMatchReady": "暂无就绪的锦标赛对局"},
    "cal": {
        "pickDateTime": "选择日期与时间",
        "dialogAria": "选择开始日期与时间",
        "prevMonth": "上个月",
        "nextMonth": "下个月",
        "monthFallback": "月",
        "dow": {
            "su": "日",
            "mo": "一",
            "tu": "二",
            "we": "三",
            "th": "四",
            "fr": "五",
            "sa": "六",
        },
        "hour": "时",
        "min": "分",
        "cancel": "取消",
        "apply": "应用",
    },
    "tz": {
        "Asia/Tokyo": "日本（JST）",
        "America/New_York": "美国东部",
        "America/Chicago": "美国中部",
        "America/Denver": "美国山地",
        "America/Los_Angeles": "美国太平洋",
        "America/Toronto": "加拿大东部",
        "America/Vancouver": "加拿大太平洋",
        "Europe/London": "英国（伦敦）",
        "Europe/Paris": "中欧",
        "Europe/Berlin": "柏林",
        "Australia/Sydney": "悉尼",
        "Asia/Singapore": "新加坡",
        "Asia/Seoul": "韩国（KST）",
        "Asia/Shanghai": "中国",
        "Asia/Hong_Kong": "香港",
        "Asia/Bangkok": "曼谷",
        "Pacific/Auckland": "奥克兰",
        "UTC": "UTC",
    },
}

TH_TOURNAMENT = {
    "backHub": "← ฮับ",
    "title": "โหมดทัวร์นาเมนต์",
    "lead": "จัดอีเวนต์ ล็อกเด็ค เช็คอิน และเล่นแบร็กเก็ตแบบคัดออกเดี่ยว",
    "timezoneLabel": "เขตเวลา",
    "timezoneAria": "เขตเวลาที่แสดง",
    "tzHint": "แสดงเวลาตาม {tz}",
    "tzHintJstFallback": "แสดงเวลาตาม JST",
    "createEvent": "สร้างอีเวนต์",
    "refresh": "รีเฟรช",
    "filterMode": "โหมด",
    "mode": {
        "all": "ทั้งหมด",
        "standard": "มาตรฐาน",
        "starters": "สตาร์ทเตอร์",
        "randomized": "สุ่ม",
        "free": "ฟรี",
        "freeDeckExperiment": "ฟรี (ทดลองเด็ค)",
    },
    "listEmpty": "ยังไม่มีทัวร์นาเมนต์ที่เปิดอยู่ สร้างหนึ่งรายการเพื่อเริ่มต้น",
    "card": {
        "fee": "ค่าเข้า {n}",
        "watching": "ชม {n}",
        "starts": "เริ่ม {when}",
        "fog": "หมอก {fog}",
        "delay": "หน่วง {n}วินาที",
        "metaSep": "·",
    },
    "backBulletin": "← บอร์ด",
    "notify": {
        "title": "ทัวร์นาเมนต์ LLTCG",
        "checkinOpen": "เปิดเช็คอินแล้ว: {title}",
        "checkinSoon": "ใกล้เช็คอิน: {title}",
    },
    "createHeading": "สร้างทัวร์นาเมนต์",
    "createTzNote": "เวลาเริ่มใช้ {tz}",
    "createTzNoteFallback": "เวลาเริ่มใช้เขตเวลาที่คุณเลือก",
    "field": {
        "title": "ชื่อ",
        "titlePlaceholder": "แบร็กเก็ตคืนวันศุกร์",
        "startLocal": "เริ่ม (ท้องถิ่น)",
        "checkinMins": "นาทีเช็คอิน",
        "minPlayers": "ผู้เล่นขั้นต่ำ",
        "maxPlayers": "ผู้เล่นสูงสุด",
        "entryFee": "ค่าเข้า (Coins)",
        "gameMode": "โหมดเกม",
        "format": "รูปแบบ",
        "matchLength": "ความยาวแมตช์",
        "fog": "หมอกสงคราม",
        "rules": "เทมเพลตกฎ",
        "rulesTitle": "กฎเด็คเพิ่มเติม; สตาร์ทเตอร์/สุ่มใช้ได้เฉพาะมาตรฐาน",
        "streamDelay": "หน่วงสตรีม (ชม)",
    },
    "format": {
        "singleElim": "คัดออกเดี่ยว",
        "doubleElimBracket": "คัดออกคู่ (ฝ่ายชนะ/แพ้)",
        "doubleElimLives": "คัดออกคู่ (2 ชีวิต)",
        "swiss": "สวิส",
        "single_elim": "คัดออกเดี่ยว",
        "double_elim_bracket": "คัดออกคู่ (ฝ่ายชนะ/แพ้)",
        "double_elim": "คัดออกคู่ (2 ชีวิต)",
    },
    "bestOf": {"1": "Best of 1", "3": "Best of 3"},
    "fog": {
        "hiddenHands": "ซ่อนมือถือ (ผู้ชม)",
        "openHands": "เปิดมือถือ",
        "hiddenHandsShort": "ซ่อนมือถือ",
        "openHandsShort": "เปิดมือถือ",
    },
    "rules": {
        "standardOption": "มาตรฐาน (ไม่มีข้อจำกัดเพิ่ม)",
        "pauperOption": "Pauper (N/R)",
        "highlanderOption": "Highlander (ใบละ 1)",
        "standard": {
            "label": "มาตรฐาน",
            "help": "ไม่มีข้อจำกัดเด็คเพิ่มนอกโหมดเกมที่เลือก ใช้เราริตี้และจำนวนสำเนาปกติ",
        },
        "pauper": {
            "label": "Pauper (N/R)",
            "help": "เฉพาะเราริตี้ต่ำ: N, R, C, U และ CL ไม่อนุญาตเราริตี้สูง (SR+, SEC ฯลฯ)",
        },
        "highlander": {
            "label": "Highlander (ใบละ 1)",
            "help": "ทั้งเด็คหลัก+พลังงาน การ์ดละไม่เกิน 1 ใบ ห้ามเลขการ์ดซ้ำ",
        },
    },
    "rulesHelp": {
        "modeLockedPrefix": "โหมดเกมกำหนดกฎเด็คแล้ว — ที่นี่ใช้ได้เฉพาะมาตรฐาน",
    },
    "delay": {"none": "ไม่มี", "secs": "{n} วินาที"},
    "schedule": "ตั้งเวลา",
    "detail": {
        "host": "โฮสต์",
        "hostFallback": "โฮสต์",
        "prize": "รางวัล {n}",
        "watching": "ชม {n}",
        "starts": "เริ่ม {when}",
        "mode": "โหมด {mode}",
        "rules": "กฎ {rules}",
        "fog": "หมอก {fog}",
        "streamDelay": "หน่วงสตรีม {n}วินาที",
        "bestOfShort": "Bo{n}",
    },
    "entrantsHeading": "ผู้เข้าแข่ง",
    "bracketHeading": "แบร็กเก็ต",
    "entrantsEmpty": "ยังไม่มีผู้เข้าแข่ง",
    "person": {"playerFallback": "ผู้เล่น"},
    "standingsHeading": "ตารางคะแนน",
    "standings": {"record": "{wins}ชนะ–{losses}แพ้"},
    "action": {
        "register": "ลงทะเบียน",
        "registerTip": "ล็อกเด็คและเข้าอีเวนต์ จ่ายค่าเข้าเข้าสู่กองรางวัลหากมี",
        "checkin": "เช็คอิน",
        "checkinTip": "ยืนยันว่าคุณอยู่ก่อนแบร็กเก็ตเริ่ม ไม่เช็คอินถือว่าไม่มา",
        "unregister": "ยกเลิกการลงทะเบียน",
        "unregisterTip": "ออกจากอีเวนต์ก่อนเริ่มและคืนค่าเข้า",
        "checkedIn": "เช็คอินแล้ว",
        "checkedInTip": "คุณเช็คอินแล้ว รอแบร็กเก็ตเริ่ม",
        "deposit": "ฝากรางวัล",
        "depositTip": "เพิ่ม Coins จากยอดคงเหลือเข้ากองรางวัล (โฮสต์เท่านั้น)",
        "cancel": "ยกเลิก (คืนเงิน)",
        "cancelTip": "ยกเลิกทัวร์นาเมนต์และคืนค่าเข้าพร้อมเงินรางวัลโฮสต์ที่เหลือ",
        "tick": "รีเฟรช / tick",
        "tickTip": "รีเฟรชอีเวนต์และเดินเวลาเซิร์ฟเวอร์ (เช็คอิน แบร็กเก็ต จัดห้อง)",
        "join": "เข้าแมตช์ของฉัน",
        "joinTip": "เข้าห้องทัวร์นาเมนต์เมื่อแมตช์ในแบร็กเก็ตพร้อม",
        "spectateList": "ชมแมตช์",
        "spectateListTip": "เรียกดูและชมแมตช์สดของทัวร์นาเมนต์นี้ในฐานะผู้ชม",
    },
    "prompt": {"deposit": "Coins ที่จะฝากเข้ากองรางวัล:", "depositDefault": "1000"},
    "confirm": {"cancel": "ยกเลิกทัวร์นาเมนต์และคืนเงินผู้เข้าแข่ง?"},
    "bracket": {
        "empty": "แบร็กเก็ตจะปรากฏเมื่อตั้งผู้เล่นสูงสุด/รูปแบบแล้ว",
        "previewSuffix": "ตัวอย่าง (ชื่อเติมหลังเช็คอิน)",
        "slot": "ช่อง",
        "tbd": "รอกำหนด",
        "bye": "บาย",
        "waiting": "รอ…",
        "spectate": "ชม",
        "spectateTip": "ชมแมตช์นี้ในฐานะผู้ชม (ผู้ที่ไม่แข่งก็ได้)",
        "winner": "ผู้ชนะ: {name}",
        "namesLock": "ชื่อล็อกเมื่อแบร็กเก็ตเริ่ม",
    },
    "formatCaption": {
        "swiss": "รอบสวิส",
        "doubleElimLives": "คัดออกคู่ (2 ชีวิต)",
        "doubleElimBracket": "คัดออกคู่ (ฝ่ายชนะ/แพ้)",
        "singleElim": "คัดออกเดี่ยว",
    },
    "round": {
        "swiss": "สวิส · รอบ {n}",
        "losersFinal": "ชิงฝ่ายแพ้",
        "losers": "ฝ่ายแพ้ · R{n}",
        "grandFinal": "ชิงชนะเลิศ",
        "grandFinalReset": "ชิงชนะเลิศ (รีเซ็ต)",
        "winnersFinal": "ชิงฝ่ายชนะ",
        "semifinals": "รอบรองชนะเลิศ",
        "roundOf": "รอบ {n} คน",
    },
    "matchStatus": {
        "live": "สด",
        "ready": "พร้อม",
        "done": "จบ",
        "pending": "ถัดไป",
    },
    "register": {
        "backEvent": "← อีเวนต์",
        "heading": "เลือกเด็คที่จะล็อก",
        "leadDefault": "เด็คนี้จะถูกล็อกสำหรับทัวร์นาเมนต์เมื่อคุณลงทะเบียน",
        "leadPick": "เลือกเด็คที่ถูกกติกาเพื่อล็อกในอีเวนต์นี้",
        "leadEmpty": "ยังไม่มีเด็คที่ใช้ได้ — สร้างในตัวสร้างเด็คแล้วกลับมา",
        "leadFreePick": "เลือกพรีเซ็ตทดลองเด็ค เด็คบัญชีที่บันทึก หรือใส่รหัสทดลอง",
        "leadFreeEmpty": "ยังไม่มีเด็คฟรีที่บันทึก — เปิดทดลองเด็ค บันทึกพรีเซ็ต (หรือใช้รหัสแชร์) แล้วกลับมา",
        "noEligible": "ไม่มีเด็คที่ใช้ได้สำหรับโหมดนี้",
        "noFreeDecks": "ไม่พบพรีเซ็ตทดลองหรือเด็คบัญชีที่เป็นเจ้าของ",
        "deckFallback": "เด็ค",
        "metaPreset": "ช่องพรีเซ็ต {slot}",
        "metaEquipped": "· ติดตั้งอยู่",
        "metaStarter": "สตาร์ทเตอร์ · {label}",
        "metaExperiment": "ทดลองเด็ค · ช่อง {slot}",
        "passwordLabel": "รหัสทดลอง",
        "passwordPlaceholder": "รหัสทดลองเด็คที่แชร์",
        "withPassword": "ลงทะเบียนด้วยรหัส",
        "openDeckBuilder": "เปิดตัวสร้างเด็ค",
        "openDeckExperiment": "เปิดทดลองเด็ค",
    },
    "status": {
        "open": "เปิดรับ",
        "checkin": "เช็คอิน",
        "running": "กำลังแข่ง",
        "finished": "จบแล้ว",
        "cancelled": "ยกเลิกแล้ว",
    },
    "entrant": {
        "registered": "ลงทะเบียนแล้ว",
        "checked_in": "เช็คอินแล้ว",
        "no_show": "ไม่มา",
        "eliminated": "ตกรอบ",
        "active": "ใช้งานอยู่",
    },
    "err": {
        "cancelled": "ทัวร์นาเมนต์นี้ถูกยกเลิกแล้ว",
        "unavailable": "ทัวร์นาเมนต์นั้นใช้ไม่ได้อีกแล้ว",
        "cancelRefunded": "ยกเลิกทัวร์นาเมนต์แล้ว — คืนเงินผู้เข้าแข่งแล้ว",
        "pickStart": "เลือกวันและเวลาเริ่ม",
        "startTooSoon": "เวลาเริ่มต้องอย่างน้อย 1 นาทีจากตอนนี้",
        "pickStartSoon": "เลือกเวลาเริ่มอย่างน้อย 1 นาทีจากตอนนี้",
        "experimentPassword": "ใส่รหัสทดลอง",
        "joinHelperMissing": "ไม่มีตัวช่วยเข้าร่วม",
        "spectateHelperMissing": "ไม่มีตัวช่วยชม",
    },
    "toast": {"noMatchReady": "ยังไม่มีแมตช์ทัวร์นาเมนต์ที่พร้อม"},
    "cal": {
        "pickDateTime": "เลือกวันและเวลา",
        "dialogAria": "เลือกวันและเวลาเริ่ม",
        "prevMonth": "เดือนก่อน",
        "nextMonth": "เดือนถัดไป",
        "monthFallback": "เดือน",
        "dow": {
            "su": "อา",
            "mo": "จ",
            "tu": "อ",
            "we": "พ",
            "th": "พฤ",
            "fr": "ศ",
            "sa": "ส",
        },
        "hour": "ชม.",
        "min": "นาที",
        "cancel": "ยกเลิก",
        "apply": "ใช้",
    },
    "tz": {
        "Asia/Tokyo": "ญี่ปุ่น (JST)",
        "America/New_York": "สหรัฐฯ ตะวันออก",
        "America/Chicago": "สหรัฐฯ กลาง",
        "America/Denver": "สหรัฐฯ ภูเขา",
        "America/Los_Angeles": "สหรัฐฯ แปซิฟิก",
        "America/Toronto": "แคนาดา ตะวันออก",
        "America/Vancouver": "แคนาดา แปซิฟิก",
        "Europe/London": "สหราชอาณาจักร (ลอนดอน)",
        "Europe/Paris": "ยุโรปกลาง",
        "Europe/Berlin": "เบอร์ลิน",
        "Australia/Sydney": "ซิดนีย์",
        "Asia/Singapore": "สิงคโปร์",
        "Asia/Seoul": "เกาหลี (KST)",
        "Asia/Shanghai": "จีน",
        "Asia/Hong_Kong": "ฮ่องกง",
        "Asia/Bangkok": "กรุงเทพฯ",
        "Pacific/Auckland": "โอ๊คแลนด์",
        "UTC": "UTC",
    },
}

HUB_SUBLIVE = {
    "en": "Events & brackets",
    "ja": "イベント＆ブラケット",
    "es": "Eventos y brackets",
    "ko": "이벤트 & 브래킷",
    "zh": "赛事与对阵表",
    "th": "อีเวนต์และแบร็กเก็ต",
}

SPECTATE_LIST_TITLE = {
    "en": "Spectate tournament",
    "ja": "トーナメントを観戦",
    "es": "Espectar torneo",
    "ko": "토너먼트 관전",
    "zh": "观战锦标赛",
    "th": "ชมทัวร์นาเมนต์",
}

TOURNAMENT_BY_LOCALE = {
    "en": EN_TOURNAMENT,
    "ja": JA_TOURNAMENT,
    "es": ES_TOURNAMENT,
    "ko": KO_TOURNAMENT,
    "zh": ZH_TOURNAMENT,
    "th": TH_TOURNAMENT,
}


def make_patch(code: str) -> dict:
    return {
        "hub": {"tournamentModeSubLive": HUB_SUBLIVE[code]},
        "spectate": {"listTitleTournament": SPECTATE_LIST_TITLE[code]},
        "tournament": TOURNAMENT_BY_LOCALE[code],
    }


def assert_same_keys(ref: dict, other: dict, code: str) -> None:
    ref_paths = leaf_paths(ref)
    other_paths = leaf_paths(other)
    missing = sorted(ref_paths - other_paths)
    extra = sorted(other_paths - ref_paths)
    if missing or extra:
        msg = [f"Key tree mismatch for {code}:"]
        if missing:
            msg.append(f"  missing ({len(missing)}): {missing[:12]}{'…' if len(missing) > 12 else ''}")
        if extra:
            msg.append(f"  extra ({len(extra)}): {extra[:12]}{'…' if len(extra) > 12 else ''}")
        raise SystemExit("\n".join(msg))


def main() -> None:
    en_patch = make_patch("en")
    for code in ("ja", "es", "ko", "zh", "th"):
        assert_same_keys(en_patch, make_patch(code), code)

    patch_leaves = leaf_count(en_patch)
    print(f"Patch leaf keys (per locale): {patch_leaves}")

    # --- EN extracted ---
    en = load_json(EN_PATH)
    before_en = leaf_paths(en)
    deep_merge(en, en_patch)
    added_en = sorted(leaf_paths(en) - before_en)
    save_json(EN_PATH, en)
    print(f"en_extracted.json: +{len(added_en)} leaves (tournament={leaf_count(en['tournament'])})")

    # --- es/ko/zh/th JSON ---
    locale_data: dict[str, dict] = {}
    for code, path in LOCALE_JSON.items():
        data = load_json(path)
        before = leaf_paths(data)
        deep_merge(data, make_patch(code))
        added = leaf_paths(data) - before
        save_json(path, data)
        locale_data[code] = data
        print(f"{code}.json: +{len(added)} leaves")

    # --- JA from i18n.js ---
    i18n_text = I18N.read_text(encoding="utf-8")
    ja = extract_locale_block(i18n_text, "ja")
    before_ja = leaf_paths(ja)
    deep_merge(ja, make_patch("ja"))
    added_ja = leaf_paths(ja) - before_ja
    print(f"ja (from i18n.js): +{len(added_ja)} leaves")

    # --- Inject all ---
    inject_locale("en", en)
    inject_locale("ja", ja)
    for code in ("es", "ko", "zh", "th"):
        inject_locale(code, locale_data[code])

    # Spot-check
    text_after = I18N.read_text(encoding="utf-8")
    for needle in (
        '"tournamentModeSubLive"',
        '"listTitleTournament"',
        '"Events & brackets"',
        '"tournament":',
    ):
        if needle not in text_after:
            raise SystemExit(f"inject check failed: missing {needle!r} in i18n.js")

    print("--- summary ---")
    print(f"EN tournament leaves: {leaf_count(en['tournament'])}")
    print(f"hub.tournamentModeSubLive (en): {en['hub'].get('tournamentModeSubLive')!r}")
    print(f"spectate.listTitleTournament (en): {en['spectate'].get('listTitleTournament')!r}")
    print(f"New EN leaf paths added this run: {len(added_en)}")
    print("Inject succeeded for: en, ja, es, ko, zh, th")


if __name__ == "__main__":
    main()
