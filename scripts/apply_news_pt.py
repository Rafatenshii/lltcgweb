#!/usr/bin/env python3
"""Apply Brazilian Portuguese title/body to news.json posts missing pt."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NEWS = ROOT / "news.json"
SOURCE = Path(__file__).resolve().parent / "_news_pt_source.json"

# Hand-authored PT (EN source of truth). Card IDs / URLs / markdown preserved.
PT: dict[str, dict[str, str]] = {
    "2026-08-profile-friends": {
        "title": "Perfis e Amigos disponíveis!",
        "body": (
            "**Perfis e Amigos** chegaram ao Loveca Web — entre com o Discord e use os botões **Perfil** e **Amigos** à direita do hub (e na maioria dos menus).\n\n"
            "**Seu perfil**\n"
            "- Escreva uma **bio** curta e monte uma **vitrine de cartas** com o que você possui.\n"
            "- Destaque um deck salvo, com descrição e visibilidade: **Privado**, **Amigos** ou **Público**.\n"
            "- Veja recordes ranqueados, partidas casuais e a página **Estatísticas de jogo** completa.\n"
            "- Seu **ID de amigo** fica no perfil — toque para copiar.\n\n"
            "**Amigos**\n"
            "- Adicione pessoas com um ID de amigo ou envie solicitação pelo perfil delas.\n"
            "- Abra **Solicitações** para aceitar ou recusar, e **Recentes** para achar quem você acabou de enfrentar.\n"
            "- Limite de **25** amigos.\n\n"
            "**Jogar juntos**\n"
            "- Em **Modo Casual**, **Convidar amigo** cria uma sala e avisa a pessoa. Se aceitar, entra na sua partida.\n"
            "- No perfil de outro jogador você pode enviar solicitação, ver o deck público/visível para amigos ou **Denunciar** se algo parecer errado.\n\n"
            "Compartilhe seu ID de amigo e entre na fila!"
        ),
    },
    "2026-08-android-apk-12": {
        "title": "Loveca para Android v1.2 disponível!",
        "body": (
            "**Loveca v1.2** é o app Android não oficial atual — um launcher em retrato que carrega o site ao vivo, com o mesmo hub, decks e partidas do desktop.\n\n"
            "**Novidades**\n"
            "- **Notificações de amigos** — quando um amigo entra na fila ranqueada/casual ou te convida para uma sala, o app Android pode avisar mesmo com o Loveca em segundo plano. Permita notificações quando o Android pedir.\n\n"
            "**Download**\n"
            "- [Baixar Loveca.apk](https://github.com/Yumegipsu/lltcgweb/releases/download/android-v1.2/Loveca.apk)\n"
            "- Ou abra a [página de Releases no GitHub](https://github.com/Yumegipsu/lltcgweb/releases/tag/android-v1.2)\n\n"
            "**Dicas de instalação**\n"
            "- Permita instalação pelo navegador ou gerenciador de arquivos se o Android pedir.\n"
            "- Depois de instalar, abra **Loveca** e entre com o Discord como de costume. Instale por cima da v1.1 (mesmo pacote / chave de assinatura) — não precisa desinstalar antes.\n"
            "- Atualizações web continuam automáticas — só precisa de APK novo quando o shell do app mudar.\n\n"
            "Em navegadores Android você também encontra o download no menu do hub."
        ),
    },
    "2026-08-tournament-mode": {
        "title": "Modo Torneio disponível! (v0.1.6)",
        "body": (
            "O **Modo Torneio** está no ar no Loveca Web **v0.1.6** — crie ou entre em eventos públicos pelo hub, acompanhe a chave e jogue suas partidas em sequência.\n\n"
            "**Encontrar e entrar**\n"
            "- Abra **Torneio** no mural do hub (botão violeta).\n"
            "- Veja eventos públicos, horário de início e configurações, depois **inscreva-se** com um deck legal para o modo daquele evento.\n"
            "- **Taxas de inscrição** opcionais usam **Moedas** (as mesmas que você ganha nas partidas).\n\n"
            "**Criar um evento**\n"
            "- Defina título, horário de início (seu fuso), mín./máx. de jogadores e taxa.\n"
            "- **Modo de jogo:** Padrão · Iniciais · Aleatório · **Livre (Experimentador de Decks)**.\n"
            "- **Formato:** Eliminação simples · Dupla elim (Vencedores/Perdedores) · Dupla elim (2 vidas) · Suíço.\n"
            "- **Duração:** Melhor de 1 ou melhor de 3.\n"
            "- Modelos de regras extras (ex.: Pauper / Highlander) quando o modo permitir — dicas explicam cada opção.\n\n"
            "**Eventos Livre**\n"
            "- Inscreva-se com um preset do **Experimentador de Decks**, senha de experimento ou deck da conta — ideal para listas criativas / sem restrições.\n\n"
            "**Durante o evento**\n"
            "- A **chave** atualiza conforme as partidas terminam.\n"
            "- Entre na sua sala na hora certa ou **assista** partidas abertas.\n"
            "- Vitórias, derrotas e confrontos diretos ficam registrados no torneio.\n\n"
            "Reúna amigos, publique um evento e que vença o melhor Live!"
        ),
    },
    "2026-08-playmat-shop": {
        "title": "Playmats disponíveis!",
        "body": (
            "**Playmats** chegaram ao Loveca Web — decore a mesa sob suas cartas em toda partida.\n\n"
            "**Loja de Playmats**\n"
            "- Abra **Loja** no hub, depois **Loja de Playmats**, e navegue por geração / personagem.\n"
            "- Desbloqueie playmats com **Moedas** (**3.000** cada). Ganhe Moedas ao terminar partidas.\n\n"
            "**Equipar e jogar**\n"
            "- No **Construtor de Deck**, escolha um playmat para cada preset salvo (junto com o sleeve).\n"
            "- Nas partidas, esse playmat aparece sob o seu lado da mesa.\n\n"
            "**Vs CPU**\n"
            "- No **Modo Casual**, **Treino vs CPU**, você também pode escolher sleeve e playmat para a CPU — um que possui ou **aleatório** da coleção.\n\n"
            "Colete seus favoritos e deixe a mesa com a sua cara!"
        ),
    },
    "2026-08-android-apk": {
        "title": "Loveca para Android v1.1 disponível!",
        "body": (
            "**Loveca v1.1** é o app Android não oficial atual — um launcher em retrato que carrega o site ao vivo, com o mesmo hub, decks e partidas do desktop.\n\n"
            "**Novidades**\n"
            "- **Discord Rich Presence** — com o Discord aberto no celular, amigos veem quando você está em menus, buscando partida ou jogando. Ative em **Opções** (somente Android).\n\n"
            "**Download**\n"
            "- [Baixar Loveca.apk](https://github.com/Yumegipsu/lltcgweb/releases/download/android-v1.1/Loveca.apk)\n"
            "- Ou abra a [página de Releases no GitHub](https://github.com/Yumegipsu/lltcgweb/releases/tag/android-v1.1)\n\n"
            "**Dicas de instalação**\n"
            "- Permita instalação pelo navegador ou gerenciador de arquivos se o Android pedir.\n"
            "- Depois de instalar, abra **Loveca** e entre com o Discord como de costume.\n"
            "- Atualizações web continuam automáticas — só precisa de APK novo quando o shell do app mudar.\n\n"
            "Se já instalou uma versão antiga, baixe a **v1.1** de novo e instale por cima (mesmo pacote / chave de assinatura).\n\n"
            "Em navegadores Android você também encontra o download no menu do hub. Boa partida onde estiver!"
        ),
    },
    "2026-08-sleeve-shop": {
        "title": "Sleeves de cartas disponíveis!",
        "body": (
            "**Sleeves de cartas** chegaram ao Loveca Web — personalize o verso das cartas e mostre em toda partida.\n\n"
            "**Loja de Sleeves**\n"
            "- Abra a **Loja de Sleeves** no hub e navegue por geração / personagem.\n"
            "- Desbloqueie sleeves com **Moedas** (**1.000** cada). Ganhe Moedas ao terminar partidas.\n"
            "- Algumas missões concedem **resgate de sleeve grátis** para usar na loja.\n\n"
            "**Equipar e jogar**\n"
            "- No **Construtor de Deck**, escolha um sleeve para cada preset salvo.\n"
            "- Nas partidas, esse sleeve substitui o **verso** das suas cartas (cartas viradas, deck e mais).\n\n"
            "Colete seus favoritos e decore seus decks!"
        ),
    },
    "2026-08-loveca-points-update": {
        "title": "Sistema de pontos Loveca atualizado!",
        "body": (
            "A lista oficial do **Sistema de Pontos Loveca** foi atualizada (vigente desde **8 de agosto de 2026**). Construtor de Deck, Ranqueado, Casual e Decks Aleatórios usam esta lista.\n\n"
            "**A regra continua a mesma**\n"
            "- Só cartas listadas custam pontos.\n"
            "- Some cada cópia no seu **deck principal** — o total deve ficar **9 ou menos**.\n"
            "- Cartas de Energia não contam.\n"
            "- Toque em um **ID da carta** abaixo para pré-visualizar.\n\n"
            "**O que mudou**\n"
            "- **5 pontos (aumento):** You Watanabe & Natsumi Onitsuka & Rurino Osawa — LL-bp2-001-R＋ *(antes 3)*\n"
            "- **Removida (0 pontos):** Vitamin SUMMER! — PL!SP-bp2-024-L *(e SECL / SRL)*\n"
            "- **Novos 2 pontos:** Mia Taylor — PL!N-pb1-011-R\n"
            "- **Novos 1 ponto:** Love U my friends — PL!N-bp3-030-L · Daydream Mermaid — PL!N-bp4-030-L\n\n"
            "**Lista atual (destaques)**\n"
            "- **5:** LL-bp2-001-R＋\n"
            "- **4:** Shizuku Osaka — PL!N-bp1-003-R＋\n"
            "- **3:** Lanzhu Zhong — PL!N-bp1-012-R＋\n"
            "- **2:** Kasumi Nakasu — PL!N-bp1-002-R＋ · Emma Verde — PL!N-sd1-008-SD · Rurino Osawa — PL!HS-bp2-014-N · Mia Taylor — PL!N-pb1-011-R\n"
            "- **1:** Ren Hazuki — PL!SP-bp1-005-R · Eutopia — PL!N-bp1-029-L · Shiki Wakana — PL!SP-sd1-019-SD · Natsumi Onitsuka — PL!SP-sd1-020-SD · Chisato Arashi — PL!SP-pb1-014-N · Love U my friends — PL!N-bp3-030-L · Daydream Mermaid — PL!N-bp4-030-L\n\n"
            "Abra o **Construtor de Deck** para ver seu total. Decks acima do limite não podem ser salvos nem usados em Ranqueado / Casual / Decks Aleatórios."
        ),
    },
    "2026-08-randomized-decks": {
        "title": "Novo modo: Decks Aleatórios!",
        "body": (
            "**Decks Aleatórios** é um novo modo de jogo para **Ranqueado** e **Modo Casual**.\n\n"
            "**Como funciona**\n"
            "- Escolha **Decks Aleatórios** no menu de modo antes de buscar ou criar uma sala.\n"
            "- Você não escolhe deck — quando a partida começa, **cada jogador** recebe seu próprio **deck legal aleatório** do **catálogo completo** (não precisa possuir as cartas).\n"
            "- Decks ainda seguem regras normais de legalidade (tamanho, cópias, orçamento Loveca).\n\n"
            "**Ranqueado**\n"
            "- Decks Aleatórios tem **fila própria** e **ranking próprio** — vitórias e derrotas aqui não movem seu ELO de Padrão ou Iniciais.\n\n"
            "**Casual**\n"
            "- Mesmo modo no matchmaking casual e salas — ótimo para partidas rápidas e surpresas.\n\n"
            "Pronto para outro tipo de caos? Entre na fila e veja o que o embaralhamento te dá!"
        ),
    },
    "2026-08-mellow-moment": {
        "title": "Novo booster: MELLOW MOMENT!",
        "body": (
            "**Booster Pack MELLOW MOMENT** já pode ser colecionado no Loveca Web.\n\n"
            "- Abra em **Abrir Boosters** no hub — mesmos pacotes diários grátis e aberturas com Star Gems dos outros boosters.\n"
            "- Todas as cartas do set estão na **lista de cartas**, **Construtor de Deck** e **Experimentador de Decks**.\n"
            "- Habilidades deste set funcionam em Ranqueado, Casual, salas com amigos e vs CPU.\n\n"
            "Boa sorte nas aberturas — e aproveite as novas melodias!"
        ),
    },
    "2026-08-nijigasaki-cheer": {
        "title": "Novo deck inicial Nijigasaki cheer!",
        "body": (
            "O **Start Deck — Love Live! Nijigasaki School Idol Club cheer** já pode ser colecionado no Loveca Web.\n\n"
            "- Adicionado como novo **deck inicial** que você pode escolher e jogar.\n"
            "- Se já escolheu um inicial antes, desbloqueie este em **Missões** — resgate o novo marco **Possuir 2.400 cartas** e escolha qualquer inicial que ainda não tenha (incluindo este deck cheer).\n"
            "- Colete todos os iniciais ao completar os marcos de cartas."
        ),
    },
    "2026-08-decklog-import": {
        "title": "Importe decks por código de deck log!",
        "body": (
            "Você pode colar um código oficial de **deck log** (ou URL de visualização) direto no Loveca Web.\n\n"
            "**Construtor de Deck** (sua coleção)\n"
            "- Abra o **Construtor de Deck** e use o campo **código deck log** perto do topo.\n"
            "- Cole um código curto (como `2X7YN`) ou link completo, depois toque **Importar**.\n"
            "- A receita carrega no preset atual — mas só exige cartas que você **possui**.\n"
            "- Faltam cópias? Você verá uma lista clara do que falta, com opções para **substituir** da coleção (ou escolher substitutos automaticamente).\n"
            "- Opcional: ative **Substituir Energia faltante** para preencher lacunas comuns de Energia quando possível.\n\n"
            "**Experimentador de Decks** (catálogo completo)\n"
            "- Abra o **Experimentador de Decks** e use o mesmo campo **código deck log**.\n"
            "- A importação traz a receita completa ao construtor de experimento (sem checar posse).\n"
            "- Após importar com sucesso, você recebe uma **senha de experimento** para salvar ou compartilhar no modo Livre.\n\n"
            "Monte mais rápido a partir de listas da comunidade — bom brewing!"
        ),
    },
    "2026-08-login-bonus": {
        "title": "Bônus de login diário disponível!",
        "body": (
            "Jogadores logados podem resgatar um **bônus de login diário** a cada dia do calendário (**JST**).\n\n"
            "**Como funciona**\n"
            "- Na primeira vez que abrir o jogo naquele dia, o calendário aparece sozinho — ou toque **Login** ao lado de **Missões** no hub quando quiser.\n"
            "- Recompensas desbloqueiam em ordem num **ciclo de 10 dias** (esquerda para direita, cima depois baixo).\n"
            "- Perdeu um dia? Sem problema — na próxima vez você resgata o **próximo** bônus. Dias não são pulados.\n"
            "- Depois do dia 10, o ciclo recomeça no dia 1.\n\n"
            "**O que tem no calendário**\n"
            "- **Star Gems** (100 ou 200)\n"
            "- **Selos N** e **selos SR** para a Loja de Selos\n"
            "- **Pacotes PR** (3 cartas) — mesma abertura das recompensas PR ranqueadas\n\n"
            "Entre e dê oi para a Kanon — boa coleção!"
        ),
    },
    "2026-08-deck-experiment-free": {
        "title": "Experimentador de Decks logado e modo Livre casual!",
        "body": (
            "O **Experimentador de Decks** não é mais só para visitantes — jogadores logados podem montar com o **catálogo completo** (sem precisar possuir) e salvar decks na conta.\n\n"
            "**Novidades**\n"
            "- No hub, abra **Experimentador de Decks** ao lado do Construtor de Deck.\n"
            "- **Salve decks de experimento** na conta (até 10 slots) e, se quiser, gere uma **senha** para compartilhar com amigos.\n"
            "- Visitantes ainda podem salvar e carregar com senha, como antes.\n\n"
            "**Novo modo Casual: Livre**\n"
            "- Em **Modo Casual**, escolha **Livre** como modo de jogo.\n"
            "- Livre é onde ficam decks do Experimentador — use um deck **salvo na conta** ou digite uma **senha**.\n"
            "- Jogue Livre em **salas com amigos**, **matchmaking casual** ou **vs CPU**.\n"
            "- Livre **não tem ranking** e não afeta Ranqueado.\n\n"
            "Modos Padrão e Iniciais continuam ligados à coleção. Bom experimentar!"
        ),
    },
    "2026-07-pr-packs-and-seals": {
        "title": "Pacotes PR ranqueados maiores e selos PR na Loja de Selos!",
        "body": (
            "Vitórias ranqueadas agora dão um **pacote PR de 3 cartas**, e você pode trocar por cartas PR específicas na **Loja de Selos**.\n\n"
            "**Pacotes PR ranqueados**\n"
            "- Vença uma partida **Ranqueada** para ganhar um **pacote PR de 3 cartas** (antes era 1).\n"
            "- Ainda até **5 pacotes por dia** (reinicia à meia-noite **JST**) — até **15 cartas PR** por dia.\n"
            "- A tela de fim de partida não revela o conteúdo. Volte ao **menu principal** para abrir o pacote.\n"
            "- Cópias extras além do limite usual ainda viram **Star Gems**, como nas aberturas de booster.\n\n"
            "**Selos PR na Loja de Selos**\n"
            "- Converta cartas **PR** / **PR+** sobrantes em **selos PR** (1 carta → 1 selo).\n"
            "- Na **Loja de Selos**, abra o produto **Cartas PR** e troque **20 selos PR** por **1** carta PR à sua escolha (mesmo custo dos selos **N**).\n"
            "- No **Construtor de Deck**, use **Conversão em lote** para selecionar várias cartas sobrantes e ver totais de selos antes de confirmar.\n\n"
            "Boa subida no ranking — e boa coleção!"
        ),
    },
    "2026-07-spsd02-clhs01": {
        "title": "Novo deck inicial Superstar!! cheer e cartas Clear Pocket de Hasunosora!",
        "body": (
            "Dois produtos da linha mais recente do Love Live! Official Card Game já podem ser colecionados no Loveca Web.\n\n"
            "**Start Deck — Love Live! Superstar!! cheer**\n"
            "- Adicionado como novo **deck inicial** que você pode escolher e jogar.\n"
            "- Se já escolheu um inicial antes, ainda pode desbloquear este em **Missões** — resgate o marco **Possuir 2.000 cartas** e escolha qualquer inicial que ainda não tenha (incluindo este deck cheer).\n"
            "- Colete todos os iniciais ao completar os marcos de cartas.\n\n"
            "**Collection Clear Pocket — Hasunosora Girls' High School Idol Club**\n"
            "- Estas cartas Clear Pocket agora fazem parte do **pool de cartas PR**.\n"
            "- Ganhe como as outras PR: **vitórias ranqueadas** (recompensas PR diárias) e **Pacote de Cartas PR**.\n\n"
            "Boa coleção — e boa sorte com Liella! cheer!"
        ),
    },
    "2026-07-thai-locale": {
        "title": "Opção de idioma tailandês",
        "body": (
            "Agora você pode jogar em **tailandês**. Abra **Opções** ou use o menu de idioma na tela inicial ou no hub para trocar.\n\n"
            "Menus, tutorial prático, interface do jogo e texto das regras das cartas estão localizados. Como coreano e chinês — e diferente do espanhol, onde nomes de personagens e músicas ficam em inglês — o tailandês mostra nomes de **Membro** e **Live** em leitura fonética tailandesa.\n\n"
            "**Sobre a precisão dos nomes**\n"
            "Essas leituras são localização comunitária de melhor esforço, não traduções oficiais da Bandai. A grafia pode não bater com toda convenção de fãs, e alguns títulos podem ser imperfeitos. Se vir um erro claro, avise para corrigirmos."
        ),
    },
    "2026-07-sticker-shop": {
        "title": "Loja de Selos disponível!",
        "body": (
            "Jogadores logados podem transformar cartas de booster sobrantes em **selos** e **trocá-los** por cartas que querem na **Loja de Selos**.\n\n"
            "**Conseguir selos**\n"
            "- Abra o **Construtor de Deck**, abra uma carta que possui e toque **Converter em selo** quando tiver cópias extras.\n"
            "- Cada carta conversível vira **1 selo** da raridade correspondente: **N**, **R**, **P** ou **SEC**.\n"
            "- Cópias usadas nos seus **decks salvos** ficam reservadas — só extras podem ser convertidas.\n"
            "- **Cartas PR** não podem ser convertidas.\n\n"
            "**Trocar por cartas**\n"
            "- No hub, abra a **Loja de Selos**.\n"
            "- Navegue por todos os **boosters** e **decks iniciais** que já possui.\n"
            "- Custos de troca (mesmo tipo de selo da carta):\n"
            "  - **N** → **20** selos N\n"
            "  - **R** → **15** selos R\n"
            "  - **P** → **10** selos P\n"
            "  - **SEC** → **5** selos SEC\n"
            "- Não pode passar do limite usual de cópias (**4** Membro/Live, **12** Energia).\n\n"
            "Vá atrás das cartas que faltam — boa coleção!"
        ),
    },
    "2026-07-chinese-locale": {
        "title": "Opção de chinês simplificado",
        "body": (
            "Agora você pode jogar em **chinês simplificado**. Abra **Opções** ou use o menu de idioma na tela inicial ou no hub para trocar.\n\n"
            "Menus, tutorial prático, interface do jogo e texto das regras das cartas estão localizados. Como coreano — e diferente do espanhol, onde nomes de personagens e músicas ficam em inglês — o chinês mostra nomes de **Membro** e **Live** em chinês simplificado.\n\n"
            "**Sobre a precisão dos nomes**\n"
            "Esses nomes são localização comunitária de melhor esforço, não traduções oficiais da Bandai. Escolhas podem não bater com toda convenção de fãs, e alguns títulos podem ser imperfeitos. Se vir um erro claro, avise para corrigirmos."
        ),
    },
    "2026-07-korean-locale": {
        "title": "Opção de idioma coreano",
        "body": (
            "Agora você pode jogar em **coreano**. Abra **Opções** ou use o menu de idioma na tela inicial ou no hub para trocar.\n\n"
            "Menus, tutorial prático, interface do jogo e texto das regras das cartas estão localizados. Diferente do espanhol — onde nomes de personagens e músicas ficam em inglês —, o coreano mostra nomes de **Membro** e **Live** em leituras coreanas (fonética, por significado ou sino-coreano conforme o título).\n\n"
            "**Sobre a precisão dos nomes**\n"
            "Essas leituras são localização comunitária de melhor esforço, não traduções oficiais da Bandai. A grafia pode não bater com toda convenção de fãs, e alguns títulos podem ser imperfeitos. Se vir um erro claro, avise para corrigirmos."
        ),
    },
    "2026-07-ranked-pr-rewards": {
        "title": "Vença partidas ranqueadas e ganhe cartas PR!",
        "body": (
            "Jogadores logados podem ganhar uma **carta PR aleatória** ao **vencer uma partida ranqueada** — parecido com prêmios de torneios locais.\n\n"
            "**Como funciona**\n"
            "- Entre na fila **Ranqueada**, vença a partida e volte ao hub para ver a recompensa.\n"
            "- Você pode receber até **5 recompensas PR por dia** (reinicia à meia-noite **JST**).\n"
            "- Se já tiver o **máximo de cópias** da carta (**4** para Membro/Live, **12** para Energia), a recompensa vira **Star Gems** — mesmas regras das aberturas de pacote.\n\n"
            "Continue subindo no ranking e cresça sua coleção. Boa sorte!"
        ),
    },
    "2026-07-star-gem-missions": {
        "title": "Missões de Star Gems disponíveis!",
        "body": (
            "Jogadores logados podem ganhar **Star Gems** com **Missões** no hub.\n\n"
            "**Onde encontrar**\n"
            "- No hub, toque no botão verde **Missões** ao lado do contador de Star Gems.\n\n"
            "**Como funciona**\n"
            "- Tarefas **Diárias** reiniciam à meia-noite **JST** — abra todos os pacotes grátis, jogue uma partida ranqueada, use um selo e mais.\n"
            "- **Marcos** são metas únicas como partidas ranqueadas, configurar perfil e vencer com deck principal de um só grupo.\n"
            "- Complete uma tarefa jogando, abra Missões e toque **Resgatar** para coletar suas gemas.\n\n"
            "Você verá um aviso verde no topo ao completar uma missão durante a partida. Boa sorte!"
        ),
    },
    "2026-07-pvp-stamps": {
        "title": "Selos PvP disponíveis!",
        "body": (
            "Envie reações rápidas em partidas **jogador vs. jogador** com **Selos**.\n\n"
            "**Como usar**\n"
            "- Toque **💬 Selos** na tela da partida.\n"
            "- Escolha um selo em **日本語** ou **English**, ou abra **★ Favoritos** para atalhos.\n"
            "- Seu oponente vê no tabuleiro; **clipes de voz de selos** tocam se o áudio estiver ligado.\n\n"
            "**Selos favoritos**\n"
            "Abra **Opções → Selos favoritos** para fixar até **20** selos na aba ★. Toque ☆ em qualquer selo no seletor para adicionar na hora.\n\n"
            "Selos são só para PvP humano — ranqueado, casual e salas com amigos."
        ),
    },
    "2026-07-ui-update": {
        "title": "Notícias e notas de atualização",
        "body": (
            "Bem-vindo à seção **Notícias** na tela inicial.\n\n"
            "Futuras atualizações do jogo, anúncios e changelogs aparecerão aqui!"
        ),
    },
    "2026-07-loveca-points": {
        "title": "Limite de pontos do deck em vigor",
        "body": (
            "A montagem de decks agora segue o **limite de pontos** oficial para cartas especialmente fortes.\n\n"
            "**Como funciona**\n"
            "- Só certas cartas custam pontos.\n"
            "- Some cada cópia no seu **deck principal** — o total deve ficar **9 ou menos**.\n"
            "- Cartas de Energia não entram nesse total.\n"
            "- Toque em um **ID da carta** abaixo para pré-visualizar.\n\n"
            "**Exemplos de custo em pontos (abril de 2026)**\n"
            "- **4 pontos:** Shizuku Osaka — PL!N-bp1-003-R＋\n"
            "- **3 pontos:** Lanzhu Zhong — PL!N-bp1-012-R＋ · You Watanabe & Natsumi Onitsuka & Rurino Osawa — LL-bp2-001-R＋\n"
            "- **2 pontos:** Kasumi Nakasu — PL!N-bp1-002-R＋ · Emma Verde — PL!N-sd1-008-SD · Rurino Osawa — PL!HS-bp2-014-N\n"
            "- **1 ponto:** Ren Hazuki — PL!SP-bp1-005-R · Eutopia — PL!N-bp1-029-L · Shiki Wakana — PL!SP-sd1-019-SD · Natsumi Onitsuka — PL!SP-sd1-020-SD · Chisato Arashi — PL!SP-pb1-014-N · Vitamin SUMMER! — PL!SP-bp2-024-L\n\n"
            "You Watanabe — PL!S-bp2-005-R＋ — não custa mais pontos.\n\n"
            "Abra o **Construtor de Deck** para ver seu total de pontos. Decks acima do limite não podem ser salvos nem usados em partidas ranqueadas e casuais."
        ),
    },
    "2026-07-spanish-locale": {
        "title": "Opção de idioma espanhol",
        "body": (
            "Agora você pode jogar em **espanhol** (espanhol latino-americano). Abra **Opções** ou use o menu de idioma na tela inicial ou no hub para trocar.\n\n"
            "Menus, tutorial e regras dos decks iniciais foram localizados primeiro; mais sets de booster virão em atualizações futuras."
        ),
    },
}

SPANISH_LEAK_RE = re.compile(
    r"(?:\b(?:mazo|mazos|clasificatoria|añadid|¡|¿|Constructor de mazos|Tienda de stickers|fundas de cartas|Reclamar|Emparejamiento)\b)",
    re.I,
)
DEV_KEY_RE = re.compile(r"\b(?:hub|deck|options|missions|toast|profile|friends)\.[a-zA-Z0-9_.]+\b")


def validate_pt(text: str, post_id: str, field: str) -> None:
    if not text or not text.strip():
        raise SystemExit(f"empty pt {field} for {post_id}")
    if SPANISH_LEAK_RE.search(text):
        raise SystemExit(f"Spanish leak in pt {field} for {post_id}: {SPANISH_LEAK_RE.search(text).group()}")
    if DEV_KEY_RE.search(text):
        raise SystemExit(f"dev key leak in pt {field} for {post_id}: {DEV_KEY_RE.search(text).group()}")


def main() -> int:
    data = json.loads(NEWS.read_text(encoding="utf-8"))
    source = json.loads(SOURCE.read_text(encoding="utf-8"))
    missing_ids = [pid for pid in source if pid not in PT]
    if missing_ids:
        print("Missing PT translations for:", ", ".join(missing_ids), file=sys.stderr)
        return 1

    for post in data["posts"]:
        pid = post["id"]
        if pid not in PT:
            continue
        pack = PT[pid]
        for field in ("title", "body"):
            validate_pt(pack[field], pid, field)
            post.setdefault(field, {})["pt"] = pack[field]

    # Every post must have non-empty pt title/body
    gaps = []
    for post in data["posts"]:
        for field in ("title", "body"):
            pt = (post.get(field) or {}).get("pt", "")
            if not str(pt).strip():
                gaps.append(f"{post['id']}.{field}")
    if gaps:
        print("Still missing pt:", ", ".join(gaps), file=sys.stderr)
        return 1

    NEWS.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Updated {NEWS} — all {len(data['posts'])} posts have pt title/body")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
