#!/usr/bin/env python3
"""Build tutorial_pt.json from tutorial_br.json + locales/pt.json legacy keys."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BR_SRC = Path(r"C:\Users\super\Downloads\tutorial_br.json")
PT_LOCALE = ROOT / "locales" / "pt.json"
OUT = ROOT / "tutorial_pt.json"

# UI chrome keys in locales/pt.json tutorial block — not step dialogue.
TUTORIAL_UI_KEYS = frozenset({
    "speaker", "exitTitle", "back", "next", "finish",
})

DEV_KEY_RE = re.compile(r"\b(?:hub|deck|options|missions|toast|profile|friends|tutorial)\.[a-zA-Z0-9_.]+\b")
SPANISH_LEAK_RE = re.compile(
    r"(?:\b(?:mazo|mazos|clasificatoria|Constructor de mazos|Tienda de stickers|¡|¿|Reclamar)\b)",
    re.I,
)

# Legacy slideshow steps present in tutorial.json but not in the interactive BR export.
PT_LEGACY_SLIDESHOW: dict[str, str] = {
    "t1_perf_intro": "Ambos os jogadores terminam a Fase de LIVE — começa a **Apresentação**! Se alguém colocou Lives, você verá a tela **Início de Live**. É aqui que se decide se as Lives têm sucesso ou não!",
    "t1_hearts_check": "Aqui você confere se as cartas do Palco atendem aos Corações exigidos desta Live. **Shiki Wakana** só fornece **1 Coração roxo**, então ainda faltam **1 Coração vermelho** e **1 Coração de qualquer cor**.",
    "t1_hearts_grey": "Corações **cinza / qualquer cor** contam como **qualquer cor** — com vermelho e roxo cobertos, os Corações restantes podem preencher o espaço \"qualquer\" de **WE WILL!!**.",
    "t1_yell": "Mesmo que uma Live pareça perdida, ainda não acabou… entra o **Grito**. Use o valor **Blade** das cartas — Liella! faz \"Grito\" e revela cartas extras do deck conforme o total de **Blade** no Palco. **Shiki Wakana** tem **Blade 2**, então revela 2 cartas do deck!",
    "t1_yell_hearts": "As cartas da Natsumi somaram 2! As cartas de Grito adicionam Corações deitados (**Corações de Blade**) ao total. Dois **Corações vermelhos** cobrem **vermelho 1** e **qualquer 1**. **A Live teve sucesso!**",
    "t1_success": "Pense no **Grito** como o apoio da plateia — é essencial para cumprir Corações difíceis. Observe os valores **Blade** do Palco — a Live **WE WILL!!** **foi bem-sucedida**!",
    "t1_yell_opp": "Próximo **Grito** de μ's — **Blade 1** no Palco revela **1** carta. **Nico** não tem Corações de Blade — **Kitto Seishun ga Kikoeru** ainda não pode ter sucesso.",
    "t1_fail": "μ's não conseguiu cumprir o custo de Corações de **Kitto Seishun ga Kikoeru** — essa **Live** vai para a **Sala de Espera**.",
    "t1_judge": "Liella! vence por pontuação e obtém uma **Live bem-sucedida**!",
    "t2_perf_intro": "Ambos confirmaram — outra **Apresentação**! As Lives são reveladas de novo.",
    "t2_live_start_offer": "**[Início de Live]** — **START:DASH!!** permite que μ's **veja as 3 cartas do topo** do deck e as reordene antes de continuar a Performance. Este aviso demonstra o efeito **[Início de Live]**.",
    "t2_yell_mine": "Primeiro o seu **Grito** — **Blade 3** no Palco revela **3** cartas. Apareceram **Ren**, **Keke** e **Mei**, mas nenhuma tem **Corações de Blade** — **Watashi no Symphony ~Shibuya Kanon Ver.~** precisa de **vermelho 4**, **roxo 4** e **qualquer 3**, então essa **Live** **falha**.",
    "t2_yell_opp": "Próximo **Grito** de μ's — a primeira carta é **Korekara no Someday** com **Corações de Blade** de TODAS as cores (contam como qualquer cor e cobrem o que falta de **START:DASH!!** — aqui **amarelo**). A segunda, **Rin**, não tem Corações de Blade.",
    "t2_outcomes": "Ambas as Lives foram resolvidas — as bem-sucedidas ficam na pilha de sucesso e as que falharam vão para a **Sala de Espera**.",
    "t2_judge": "Só **μ's** conseguiu **START:DASH!!** com sucesso — sua Live falhou, então **Watashi no Symphony ~Shibuya Kanon Ver.~** vai para a **Sala de Espera**. μ's obtém uma **Live bem-sucedida**!",
    "t3_perf_intro": "Ambos confirmaram — a **Apresentação** final!",
    "t3_yell_mine": "Primeiro o **Grito** de μ's — revela cartas do deck por **Corações de Blade**. **START:DASH!!** já combina com o Palco (**rosa**, **amarelo** e **roxo** de **Rin Hoshizora**, **Honoka** e **Umi**).",
    "t3_yell_opp": "Depois o seu **Grito** — **Shiki Wakana** no **Centro** (**Blade 2**) e **Mei Yoneme** na **Direita** (**Blade 1**) revelam **3** cartas. **Mirai wa Kaze no You ni** trata esses Corações de Grito como **qualquer** cor — a **Live** **tem sucesso** junto com o Palco!",
    "t3_outcomes": "Ambas as Lives **tiveram sucesso**! Neste caso, um desempate decide o vencedor — hora do **Juiz de Live**!",
    "t3_judge": "**Juiz de Live** — μ's vence com pontuação reforçada! Mais uma **Live bem-sucedida** mais perto da vitória na partida.",
}


def step_ids_from(path: Path) -> list[str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    return [s["id"] for s in data.get("steps", []) if s.get("id")]


def main() -> int:
    br_path = BR_SRC if BR_SRC.is_file() else ROOT / "tutorial_br_import.json"
    if not br_path.is_file():
        print(f"Missing BR tutorial source: {br_path}", file=sys.stderr)
        return 1

    br = json.loads(br_path.read_text(encoding="utf-8"))
    pt_locale = json.loads(PT_LOCALE.read_text(encoding="utf-8"))
    curated = pt_locale.get("tutorial") or {}

    out: dict[str, str] = {}

    for step in br.get("steps", []):
        sid = step.get("id")
        if not sid:
            continue
        dialogue = (step.get("dialogue") or "").strip()
        if dialogue:
            out[sid] = dialogue
        portrait = (step.get("dialogue_portrait") or "").strip()
        if portrait:
            out[f"{sid}_portrait"] = portrait

    # Legacy slideshow + any guide ids not in BR export
    for key, val in curated.items():
        if key in TUTORIAL_UI_KEYS or not isinstance(val, str):
            continue
        text = val.strip()
        if not text:
            continue
        if key not in out:
            out[key] = text

    # Aliases used by some locale packs (see tutorial_es.json)
    if "coin" in out and "choose_first" not in out:
        out["choose_first"] = out["coin"]

    for key, text in PT_LEGACY_SLIDESHOW.items():
        if key not in out:
            out[key] = text

    # Required step ids from both tutorial sources
    required: list[str] = []
    seen: set[str] = set()
    for fname in ("tutorial.json", "tutorial_guide.json"):
        for sid in step_ids_from(ROOT / fname):
            if sid in seen:
                continue
            seen.add(sid)
            required.append(sid)

    missing = [sid for sid in required if sid not in out or not str(out[sid]).strip()]
    if missing:
        print(f"Missing {len(missing)} step(s) after merge:", ", ".join(missing[:20]), file=sys.stderr)
        if len(missing) > 20:
            print(f"  … and {len(missing) - 20} more", file=sys.stderr)
        return 1

    leaks: list[str] = []
    for key, text in out.items():
        if DEV_KEY_RE.search(text):
            leaks.append(f"{key}: dev key")
        if SPANISH_LEAK_RE.search(text):
            leaks.append(f"{key}: Spanish leak")

    if leaks:
        print("Validation failed:", file=sys.stderr)
        for line in leaks[:10]:
            print(f"  {line}", file=sys.stderr)
        return 1

    OUT.write_text(json.dumps(out, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {OUT} ({len(out)} keys, {len(required)} required steps covered)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
