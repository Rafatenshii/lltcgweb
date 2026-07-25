#!/usr/bin/env python3
"""Add Thai ("th") translations to every entry in stamps_i18n.json.

Stamp labels are short reaction phrases (LLSIF-style match stamps). Translations
are hand-picked for tone/meaning and keyed by the existing (ja, en) pair so the
script stays correct if label IDs shift.

Usage: python scripts/inject_stamps_th.py
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
STAMPS_PATH = REPO_ROOT / 'stamps_i18n.json'

# (ja, en) -> th. Hand-translated; keeps catchphrases / character tone where relevant.
TH_BY_JA_EN = {
    ('', ''): '',
    ('ゴ〜ン', 'Gong~'): 'โกง~',
    ('ぷにっ', 'Squish'): 'ปุ๊นิ!',
    ('イエイっ！', 'Yay!'): 'เย้!',
    ('いい感じ♪', "Feelin' good♪"): 'รู้สึกดี♪',
    ('ファイトだよ！', 'Give it your best!'): 'สู้ๆ นะ!',
    ('みんな、お疲れさま！', 'Great job everyone!'): 'ทุกคนเก่งมาก!',
    ('楽しいわ♪', 'This is fun♪'): 'สนุกจัง♪',
    ('ハラショー！', 'Harasho!'): 'ฮาราโช!',
    ('ちゅんちゅん♪', 'Chun Chun♪'): 'จุ๊น จุ๊น♪',
    ('大丈夫だよ！', "It's okay!"): 'ไม่เป็นไรนะ!',
    ('ラブアローシュート！', 'Love Arrow SHOT!'): 'เลิฟแอร์โรว์ชูต!',
    ('あなたに届け！', 'This is for you!'): 'ส่งถึงเธอ!',
    ('助太刀します！', "I'm here to help!"): 'มาช่วยแล้วนะ!',
    ('テンションあがるにゃー！', "I'm getting pumped!"): 'ตื่นเต้นขึ้นแล้วนะ!',
    ('ドキドキ♡', 'Thump Thump♡'): 'ตุ๊บตุ๊บ♡',
    ('意味わかんない！', 'Whatever!'): 'ไม่เข้าใจเลย!',
    ('ウフフ♪', 'Ufufu♪'): 'อุฟุฟุ♪',
    ('スピリチュアルやね', 'So spiritual!'): 'จิตวิญญาณจัง',
    ('よしよし♡', 'There, there♡'): 'ดีๆ นะ♡',
    ('希パワー注入♪', 'Nozomi Power Injection!'): 'ฉีดพลังโนโซมิ♪',
    ('き、緊張してきました…', "I'm getting nervous..."): 'ต-ตึงเครียดขึ้นมา…',
    ('最高です！', 'This is the best!'): 'สุดยอดไปเลย!',
    ('ダレカタスケテー', 'Someone Save me...'): 'มีใครช่วยหน่อย…',
    ('にっこにっこにー♪', 'Nico-Nico♪ Nii!'): 'นิโกะนิโกะนี♪',
    ('ラブを届けますっ☆', 'Spreading the love☆'): 'ส่งความรักไป☆',
    ('いけいけーっ！', 'Go Go!'): 'ไปเลยๆ!',
    ('最高に最高の気分だよ♪', 'Best feeling ever♪'): 'อารมณ์ดีที่สุดเลย♪',
    ('歌の力ってすごいんだね！', 'The Song is Amazing!'): 'พลังเพลงเจ๋งจริงๆ!',
    ('嬉しい音が聞こえてくるの', 'I can hear happy sounds'): 'ได้ยินเสียงแห่งความสุข',
    ('サンキュ！', 'Thanks!'): 'ขอบคุณ!',
    ('ハグしたくなっちゃう♪', 'I wanna give you a hug♪'): 'อยากกอดจัง♪',
    ('熱くなれそうだよ♪', 'Getting fired up♪'): 'เริ่มฮึกเหิมแล้วนะ♪',
    ('当然ですわね', 'Not a Surprise'): 'ก็ต้องอย่างนั้นสิ',
    ('やりましたわね！', 'We did it!'): 'เราทำได้แล้ว!',
    ('ヨーソロー！', 'Aye-Aye!'): 'โยโซโร!',
    ('すっごく嬉しい！', 'So happy!'): 'ดีใจมากๆ!',
    ('敬礼っ！', 'Salute!'): 'คำนับ!',
    ('おいで、リトルデーモン♪', 'Come Little Demon'): 'มาสิ ลิตเติลเดมอน♪',
    ('堕天降臨！', 'The Fallen Angel Descends!'): 'ทูตสวรรค์ตกสวรรค์จุติ!',
    ('どっこいしょー！', 'Heave-Ho!'): 'เฮ้ยโฮ!',
    ('最高の気分ずら♪', 'Best feeling ever, zura♪'): 'อารมณ์ดีที่สุดซุระ♪',
    ('未来ずら〜っ✨', "It's the future, zura✨"): 'อนาคตแล้วนะซุระ✨',
    ('シャイニー☆', 'Shiny☆'): 'ไชน์นี่☆',
    ('アイムハッピー♡', "I'm Happy♡"): 'แฮปปี้♡',
    ('ピギィッ', 'Screeech'): 'ปี้ก!',
    ('ルビィは幸せです♡', 'Ruby is so happy♡'): 'รูบี้มีความสุข♡',
    ('精一杯の輝きを！', 'Shine your very best!'): 'เปล่งประกายเต็มที่!',
    ('ありがとうございます♪', 'Thank you so much♪'): 'ขอบคุณมากๆ นะ♪',
    ('最高に楽しい！', 'So much fun!'): 'สนุกสุดๆ!',
    ('一緒に楽しみましょう', "Let's have fun together"): 'มาสนุกด้วยกันเถอะ',
    ('癒してあげたい', 'Let me heal you'): 'อยากเยียวยาเธอ',
    ('すごく楽しいね♪', 'This is really fun♪'): 'สนุกจริงๆ นะ♪',
    ('とても楽しいです', 'This is very fun'): 'สนุกมากเลย',
    ('にゃにゃにゃー', 'Nya Nya Nyan~'): 'เนีย เนีย เนียน~',
    ('やったにゃー！', 'We did it-nya!'): 'ทำได้แล้วเนีย!',
    ('やるじゃない', 'Not bad'): 'ไม่เลวนะ',
    ('最高の気分かも', 'This might be the best!'): 'อาจเป็นอารมณ์ที่ดีที่สุด',
    ('楽しんでる？', 'Are you having fun?'): 'สนุกไหม?',
    ('とっても楽しいです！', 'So much fun!'): 'สนุกมากเลย!',
    ('ラブにこ♪', 'Love Nico♪'): 'เลิฟนิโกะ♪',
    ('盛り上がって来たわね', 'Things are heating up!'): 'บรรยากาศคึกคักขึ้นแล้วนะ',
    ('みんなの力をあわせよう！', "Let's Work Together!"): 'มารวมพลังกันเถอะ!',
    ('さあ、はじめよう！', "Let's get started!"): 'เอาล่ะ เริ่มกันเลย!',
    ('私…頑張ります！', "I'll... Do My Best!"): 'ฉัน… จะพยายามเต็มที่!',
    ('想いよ、響け♪', 'Hear my heart♪'): 'ความรู้สึกเอ๋ย ดังก้องไป♪',
    ('ハグしよ♪', "Let's Hug♪"): 'มากอดกัน♪',
    ('お覚悟！', 'Be Prepared!'): 'เตรียมตัวไว้!',
    ('舞い踊れ♪', 'Dance and twirl♪'): 'เต้นระบำ♪',
    ('全速前進！', 'Full Speed Ahead!'): 'พุ่งเต็มสปีด!',
    ('一緒に堕ちましょう♪', "Let's Fall Together♪"): 'มาตกต่ำด้วยกัน♪',
    ('栄光あれ！', 'All glory!'): 'จงมีเกียรติ!',
    ('オラにも出来る！', "I'mma Do it!"): 'ฉันก็ทำได้!',
    ('ハーイ♪', 'Hiya♪'): 'ฮาย♪',
    ('ノープロブレム☆', 'No Problem☆'): 'โนพรอบเลม☆',
    ('がんばルビィ！', "I'll Do My Rubesty"): 'กันบารูบี้!',
    ('きゅーんとしちゃう♡', 'My heart melts♡'): 'หัวใจละลาย♡',
    ('頼もしいですね♪', 'How reassuring♪'): 'น่าไว้วางใจจัง♪',
    ('ありがとう！', 'Thank you!'): 'ขอบคุณ!',
}


def main() -> None:
    data = json.loads(STAMPS_PATH.read_text(encoding='utf-8'))
    labels = data.get('labels', {})
    missing = []
    for stamp_id, row in labels.items():
        key = (row.get('ja', ''), row.get('en', ''))
        if key not in TH_BY_JA_EN:
            missing.append((stamp_id, key))
            continue
        row['th'] = TH_BY_JA_EN[key]

    if missing:
        print('Missing TH translation for:', file=sys.stderr)
        for stamp_id, key in missing:
            print(f'  {stamp_id}: {key!r}', file=sys.stderr)
        sys.exit(1)

    STAMPS_PATH.write_text(
        json.dumps(data, ensure_ascii=False, indent=2) + '\n',
        encoding='utf-8',
    )
    total = len(labels)
    non_empty = sum(1 for row in labels.values() if row.get('th'))
    print(f'Injected th into {total} stamp labels ({non_empty} non-empty) -> {STAMPS_PATH}')


if __name__ == '__main__':
    main()
