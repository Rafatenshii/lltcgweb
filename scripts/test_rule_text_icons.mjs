#!/usr/bin/env node
/**
 * Smoke tests for rule-text-icons strip/compare helpers.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const jsPath = path.join(root, 'client/js/rule-text-icons.js');
const code = readFileSync(jsPath, 'utf8');

const sandbox = { window: {}, console };
vm.runInNewContext(code, sandbox);
const { stripRuleInlineTags, normalizeRuleTextForCompare, RULE_INLINE_TAG_RE } = sandbox.window;

let failed = 0;
function assert(cond, msg) {
  if (!cond) {
    console.error('FAIL:', msg);
    failed++;
  }
}

assert(typeof stripRuleInlineTags === 'function', 'stripRuleInlineTags exported');
assert(typeof normalizeRuleTextForCompare === 'function', 'normalizeRuleTextForCompare exported');

const tagged = 'Pay 2<energy>: gain 1<blade> and 1<pinkH>.';
const stripped = stripRuleInlineTags(tagged);
assert(stripped.includes('2 Energy'), 'energy tag strips to Energy');
assert(stripped.includes('Blade'), 'blade tag strips');
assert(stripped.includes('Pink heart'), 'pinkH tag strips');

const prosePrompt = 'Pay 2 Energy for this Live Start effect?';
const taggedLine = 'Pay 2<energy> for this Live Start effect?';
assert(
  normalizeRuleTextForCompare(prosePrompt).includes('pay 2 energy'),
  'prose prompt normalizes'
);
assert(
  normalizeRuleTextForCompare(taggedLine).includes('pay 2 energy'),
  'tagged line normalizes to comparable prose'
);

const re = new RegExp(RULE_INLINE_TAG_RE.source, 'g');
const tags = [...'Pay 3<energy> +2<blade> <score+1>'.matchAll(re)].map(m => m[1]);
assert(tags.join(',') === 'energy,blade,score+1', 'regex captures score+1');

if (failed) {
  console.error(`${failed} test(s) failed`);
  process.exit(1);
}
console.log('OK rule-text-icons smoke tests');
