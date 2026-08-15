#!/usr/bin/env node
/**
 * Regression: high-frequency engine prompt strings must not stay English
 * when localized via LLTCG_LOG_I18N.localizePromptText for ja/es/ko/zh/th.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import vm from 'vm';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

const SAMPLES = [
  'Choose 1 Member card from your Waiting Room to add to your hand.',
  'Choose up to 2 Member card(s) from your Waiting Room to add to your hand.',
  'Choose 1 card card from your Waiting Room to add to your hand.',
  'Choose a card from your Waiting Room to add to your hand.',
  'Choose 1 Member on your Stage.',
  'Choose up to 2 Members on your Stage.',
  'Discard 1 card from your hand.',
  'Choose 2 cards from your hand to send to the Waiting Room.',
  'Look at the top 3 cards of your deck.',
  'Choose one effect',
  'Choose a heart color.',
  'Choose yourself or your opponent.',
];

function loadScript(filename, sandbox) {
  const code = fs.readFileSync(path.join(root, filename), 'utf8');
  vm.runInNewContext(code, sandbox, { filename });
}

function assertLocalized(loc, input, output) {
  if (!output || output === input) {
    throw new Error(`[${loc}] unchanged: ${JSON.stringify(input)}`);
  }
  if (/\bChoose\b/i.test(output) || /\bDiscard\b/i.test(output) || /\bLook at the top\b/i.test(output)) {
    throw new Error(`[${loc}] English verb remained\n  in:  ${input}\n  out: ${output}`);
  }
}

const store = Object.create(null);
const sandbox = {
  console,
  localStorage: {
    getItem(k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
    setItem(k, v) { store[k] = String(v); },
    removeItem(k) { delete store[k]; },
  },
  document: {
    documentElement: { lang: 'en' },
    body: {
      classList: {
        _c: new Set(),
        remove(...xs) { xs.forEach((x) => this._c.delete(x)); },
        add(...xs) { xs.forEach((x) => this._c.add(x)); },
      },
    },
    getElementById() { return null; },
  },
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
sandbox.global = sandbox;

loadScript('i18n.js', sandbox);
loadScript('log_i18n.js', sandbox);

const i18n = sandbox.LLTCG_I18N;
const logI18n = sandbox.LLTCG_LOG_I18N;
if (!i18n?.setLocale || !logI18n?.localizePromptText) {
  console.error('Missing LLTCG_I18N / LLTCG_LOG_I18N after load');
  process.exit(1);
}

const locales = ['ja', 'es', 'ko', 'zh', 'th'];
let failures = 0;
for (const loc of locales) {
  i18n.setLocale(loc);
  for (const sample of SAMPLES) {
    try {
      const out = logI18n.localizePromptText(sample, []);
      assertLocalized(loc, sample, out);
    } catch (e) {
      console.error(String(e.message || e));
      failures += 1;
    }
  }
}

if (failures) {
  console.error(`prompt localization test FAILED (${failures})`);
  process.exit(1);
}
console.log(`prompt localization OK (${SAMPLES.length} samples × ${locales.length} locales)`);
