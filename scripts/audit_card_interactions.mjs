#!/usr/bin/env node
/**
 * Static card interaction audit.
 *
 * Walks typed ability/effect nodes in cards.json and compares them with
 * server ability handlers, prompt resolvers, client prompt UI branches, and
 * CPU prompt branches. This is intentionally static: it highlights places to
 * investigate, not a proof that runtime behavior is complete.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..');

const DEFAULT_WRITE_PATH = path.join(root, 'reports', 'card_interaction_matrix.json');

function usage() {
  console.log(`Usage: node scripts/audit_card_interactions.mjs [--write[=path]] [--json]

Options:
  --write        Write machine-readable JSON to reports/card_interaction_matrix.json.
  --write=path   Write JSON to a specific path.
  --json         Print the full JSON matrix to stdout instead of the concise report.
  --help         Show this help.
`);
}

function parseArgs(argv) {
  const opts = { writePath: null, json: false, help: false };
  for (const arg of argv) {
    if (arg === '--help' || arg === '-h') opts.help = true;
    else if (arg === '--json') opts.json = true;
    else if (arg === '--write') opts.writePath = DEFAULT_WRITE_PATH;
    else if (arg.startsWith('--write=')) {
      const value = arg.slice('--write='.length);
      opts.writePath = path.isAbsolute(value) ? value : path.resolve(root, value);
    } else {
      throw new Error(`Unknown argument: ${arg}`);
    }
  }
  return opts;
}

function readText(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function readJson(filePath) {
  return JSON.parse(readText(filePath));
}

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function slashPath(filePath) {
  return path.relative(root, filePath).replaceAll(path.sep, '/');
}

function walkFiles(dir, predicate, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'vendor' || entry.name === '.git' || entry.name === 'data' || entry.name === 'games') continue;
      walkFiles(full, predicate, out);
    } else if (predicate(full)) {
      out.push(full);
    }
  }
  return out.sort((a, b) => slashPath(a).localeCompare(slashPath(b)));
}

function uniqSorted(values) {
  return [...new Set(values)].sort((a, b) => a.localeCompare(b));
}

function countBy(items, keyFn) {
  const counts = new Map();
  for (const item of items) {
    const key = keyFn(item);
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  return Object.fromEntries([...counts.entries()].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0])));
}

function addMatch(set, meta, value, sourceFile, kind) {
  if (!value || typeof value !== 'string') return;
  set.add(value);
  if (!meta.has(value)) meta.set(value, []);
  const source = { file: slashPath(sourceFile), kind };
  const list = meta.get(value);
  if (!list.some((row) => row.file === source.file && row.kind === source.kind)) list.push(source);
}

function quotedStrings(src) {
  const out = [];
  const re = /['"]([a-z][a-z0-9_]*_[a-z0-9_]+|[a-z]+)['"]/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push(m[1]);
  return out;
}

function collectPhpListForVariable(src, variableName) {
  const out = [];
  const varRe = variableName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const re = new RegExp(`in_array\\(\\s*${varRe}\\s*,\\s*\\[([\\s\\S]*?)\\]\\s*,\\s*true\\s*\\)`, 'g');
  let m;
  while ((m = re.exec(src)) !== null) out.push(...quotedStrings(m[1]));
  return out;
}

function collectPhpEffectTypeRegistries(src) {
  const out = [];
  const re = /function\s+([A-Za-z_][A-Za-z0-9_]*EffectTypes)\s*\([^)]*\)\s*:\s*array\s*\{\s*return\s*\[([\s\S]*?)\]\s*;/g;
  let m;
  while ((m = re.exec(src)) !== null) out.push(...quotedStrings(m[2]));
  return out;
}

function collectPhpStringSet(files, options) {
  const values = new Set();
  const meta = new Map();
  for (const file of files) {
    const src = readText(file);
    const regexes = [];
    if (options.caseLabels) regexes.push({ kind: 'case', re: /case\s+['"]([^'"]+)['"]\s*:/g });
    // EffectHandlers::tryHandle dispatches through a match expression, not a switch.
    if (options.matchArms) regexes.push({ kind: 'match arm', re: /^\s*['"]([^'"]+)['"]\s*=>\s*self::/gm });
    if (options.typeVariable) regexes.push({ kind: '$type comparison', re: /\$type\s*={2,3}\s*['"]([^'"]+)['"]/g });
    if (options.promptTypeVariable) regexes.push({ kind: '$promptType comparison', re: /\$promptType\s*={2,3}\s*['"]([^'"]+)['"]/g });
    if (options.abilityArray) {
      regexes.push({
        kind: "$ab['type'] comparison",
        re: /\$ab\[['"]type['"]\]\s*={2,3}\s*['"]([^'"]+)['"]/g,
      });
      regexes.push({
        kind: "($ab['type'] ?? '') comparison",
        re: /\(\s*\$ab\[['"]type['"]\]\s*\?\?\s*['"][^'"]*['"]\s*\)\s*={2,3}\s*['"]([^'"]+)['"]/g,
      });
      regexes.push({
        kind: "$array['type'] comparison",
        re: /\$[A-Za-z_][A-Za-z0-9_]*\[['"]type['"]\]\s*(?:={2,3}|!={1,2})\s*['"]([^'"]+)['"]/g,
      });
      regexes.push({
        kind: "($array['type'] ?? '') comparison",
        re: /\(\s*\$[A-Za-z_][A-Za-z0-9_]*\[['"]type['"]\]\s*\?\?\s*['"][^'"]*['"]\s*\)\s*(?:={2,3}|!={1,2})\s*['"]([^'"]+)['"]/g,
      });
    }
    if (options.effectTypeRegistries) {
      for (const value of collectPhpEffectTypeRegistries(src)) addMatch(values, meta, value, file, 'EffectTypes registry');
    }
    for (const { kind, re } of regexes) {
      let m;
      while ((m = re.exec(src)) !== null) addMatch(values, meta, m[1], file, kind);
    }
    if (options.typeInArray) {
      for (const value of collectPhpListForVariable(src, '$type')) addMatch(values, meta, value, file, 'in_array($type)');
    }
    if (options.promptTypeInArray) {
      for (const value of collectPhpListForVariable(src, '$promptType')) addMatch(values, meta, value, file, 'in_array($promptType)');
    }
  }
  return { values, meta };
}

function collectPhpPrefixes(files, variableName) {
  const exact = new Set();
  const meta = new Map();
  const varRe = variableName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  for (const file of files) {
    const src = readText(file);
    const starts = new RegExp(`str_starts_with\\(\\s*${varRe}\\s*,\\s*['"]([^'"]+)['"]\\s*\\)`, 'g');
    let m;
    while ((m = starts.exec(src)) !== null) addMatch(exact, meta, m[1], file, `str_starts_with(${variableName})`);
    const preg = new RegExp(`preg_match\\(\\s*['"]/\\^\\(([^'"]+)\\)/['"]\\s*,\\s*${varRe}\\s*\\)`, 'g');
    while ((m = preg.exec(src)) !== null) {
      for (const prefix of m[1].split('|')) addMatch(exact, meta, prefix, file, `preg_match(${variableName})`);
    }
  }
  return { values: exact, meta };
}

function collectJsPromptTypes(file, options = {}) {
  const values = new Set();
  const noGeneric = new Set();
  const meta = new Map();
  const srcFull = readText(file);
  const src = options.regionStart
    ? srcFull.slice(Math.max(0, srcFull.indexOf(options.regionStart)))
    : srcFull;

  const equality = /\bpr\??\.type\s*={2,3}\s*['"]([^'"]+)['"]/g;
  let m;
  while ((m = equality.exec(src)) !== null) addMatch(values, meta, m[1], file, 'pr.type comparison');

  const setBlocks = /new\s+Set\s*\(\s*\[([\s\S]*?)\]\s*\)/g;
  while ((m = setBlocks.exec(src)) !== null) {
    const literals = quotedStrings(m[1]);
    const prefix = src.slice(Math.max(0, m.index - 120), m.index);
    const suffix = src.slice(m.index, Math.min(src.length, m.index + m[0].length + 120));
    if (/CPU_NO_GENERIC_YESNO/.test(prefix)) {
      for (const value of literals) {
        addMatch(values, meta, value, file, 'CPU_NO_GENERIC_YESNO');
        noGeneric.add(value);
      }
    } else if (/\.has\(\s*pr\.type\s*\)/.test(suffix) || options.includeSets) {
      for (const value of literals) addMatch(values, meta, value, file, 'Set.has(pr.type)');
    }
  }

  if (options.cpuNoGenericFallback) {
    const noGenericBlock = /CPU_NO_GENERIC_YESNO\s*=\s*new\s+Set\s*\(\s*\[([\s\S]*?)\]\s*\)/.exec(src);
    if (noGenericBlock) {
      for (const value of quotedStrings(noGenericBlock[1])) noGeneric.add(value);
    }
  }

  return { values, noGeneric, meta };
}

function shortParams(node) {
  const skip = new Set([
    'type', 'trigger', 'then', 'else_then', 'on_success', 'on_fail', 'effect', 'effects',
    'abilities', 'choices', 'branches', 'ability',
  ]);
  const params = {};
  for (const [key, value] of Object.entries(node)) {
    if (skip.has(key)) continue;
    if (value === null || ['string', 'number', 'boolean'].includes(typeof value)) {
      params[key] = value;
    } else if (Array.isArray(value) && value.every((item) => item === null || ['string', 'number', 'boolean'].includes(typeof item))) {
      params[key] = value.length > 8 ? [...value.slice(0, 8), `...(${value.length - 8} more)`] : value;
    }
  }
  return params;
}

function walkAbilitySubtree(node, ctx, out, seen) {
  if (!isPlainObject(node) && !Array.isArray(node)) return;
  if (isPlainObject(node)) {
    if (seen.has(node)) return;
    seen.add(node);
    const type = typeof node.type === 'string' ? node.type.trim() : '';
    const trigger = typeof node.trigger === 'string' && node.trigger.trim() ? node.trigger.trim() : ctx.trigger;
    const currentCtx = { ...ctx, trigger };
    if (type) {
      out.push({
        card_no: ctx.cardNo,
        card_name: ctx.cardName,
        card_type: ctx.cardType,
        ability_path: ctx.path,
        trigger: trigger || '(none)',
        type,
        params: shortParams(node),
      });
      currentCtx.parentType = type;
    }
    for (const [key, value] of Object.entries(node)) {
      if (key === 'text' || key === 'name' || key === 'name_en' || key === 'image_url') continue;
      if (Array.isArray(value)) {
        value.forEach((item, i) => {
          if (isPlainObject(item) || Array.isArray(item)) {
            walkAbilitySubtree(item, { ...currentCtx, path: `${ctx.path}.${key}[${i}]` }, out, seen);
          }
        });
      } else if (isPlainObject(value)) {
        for (const [childKey, childValue] of Object.entries(value)) {
          if (isPlainObject(childValue) || Array.isArray(childValue)) {
            const childPath = key === 'choices'
              ? `${ctx.path}.choices.${childKey}`
              : `${ctx.path}.${key}.${childKey}`;
            walkAbilitySubtree(childValue, { ...currentCtx, path: childPath }, out, seen);
          }
        }
        if (typeof value.type === 'string') {
          walkAbilitySubtree(value, { ...currentCtx, path: `${ctx.path}.${key}` }, out, seen);
        }
      }
    }
  } else {
    node.forEach((item, i) => walkAbilitySubtree(item, { ...ctx, path: `${ctx.path}[${i}]` }, out, seen));
  }
}

function collectAbilityNodes(cards) {
  const nodes = [];
  let topLevelAbilities = 0;
  for (const card of cards) {
    const abilities = Array.isArray(card.abilities) ? card.abilities : [];
    topLevelAbilities += abilities.length;
    abilities.forEach((ability, i) => {
      walkAbilitySubtree(ability, {
        cardNo: String(card.card_no || card.id || ''),
        cardName: String(card.name_en || card.name || ''),
        cardType: String(card.card_type || ''),
        path: `abilities[${i}]`,
        trigger: typeof ability?.trigger === 'string' ? ability.trigger : '',
        parentType: '',
      }, nodes, new WeakSet());
    });
  }
  return { nodes, topLevelAbilities };
}

function sampleNodes(nodes, type, limit = 5) {
  return nodes
    .filter((node) => node.type === type)
    .slice(0, limit)
    .map((node) => ({
      card_no: node.card_no,
      card_name: node.card_name,
      ability_path: node.ability_path,
      trigger: node.trigger,
      params: node.params,
    }));
}

function coveredByPrefix(type, prefixes) {
  return [...prefixes].find((prefix) => type.startsWith(prefix)) || null;
}

function topTypeRows(types, nodes, limit = 20) {
  const counts = countBy(nodes.filter((node) => types.has(node.type)), (node) => node.type);
  return Object.entries(counts).slice(0, limit).map(([type, count]) => ({
    type,
    count,
    samples: sampleNodes(nodes, type, 3),
  }));
}

function buildMatrix() {
  const cardsPath = path.join(root, 'cards.json');
  const cardsJson = readJson(cardsPath);
  const cards = Array.isArray(cardsJson) ? cardsJson : (cardsJson.cards || []);
  if (!Array.isArray(cards)) throw new Error('cards.json must contain a cards array');

  const { nodes, topLevelAbilities } = collectAbilityNodes(cards);
  const abilityTypes = new Set(nodes.map((node) => node.type));

  const srcGameDir = path.join(root, 'src', 'Game');
  const resolverSwitchFiles = walkFiles(srcGameDir, (file) => /^AbilityResolverSwitch.*\.php$/.test(path.basename(file)));
  const rootEffectFiles = walkFiles(root, (file) => path.dirname(file) === root && /_effects\.php$/.test(path.basename(file)));
  const abilityHandlerFiles = uniqSorted([
    ...resolverSwitchFiles,
    path.join(srcGameDir, 'AbilityResolver.php'),
    path.join(srcGameDir, 'ActivateAbility.php'),
    path.join(srcGameDir, 'EffectRegistry.php'),
    path.join(srcGameDir, 'EffectHandlers.php'),
    path.join(srcGameDir, 'LiveModifiers.php'),
    path.join(srcGameDir, 'LiveScoreBonus.php'),
    path.join(srcGameDir, 'PromptEnrichment.php'),
    path.join(srcGameDir, 'PromptResolver.php'),
    path.join(root, 'effects.php'),
    ...rootEffectFiles,
  ].filter((file) => fs.existsSync(file))).map((file) => path.resolve(root, file));

  const promptResolverFiles = uniqSorted([
    path.join(srcGameDir, 'PromptResolver.php'),
    ...rootEffectFiles,
  ].filter((file) => fs.existsSync(file))).map((file) => path.resolve(root, file));

  const abilityScan = collectPhpStringSet(abilityHandlerFiles, {
    caseLabels: true,
    matchArms: true,
    typeVariable: true,
    abilityArray: true,
    typeInArray: true,
    effectTypeRegistries: true,
  });
  const abilityPrefixes = collectPhpPrefixes(resolverSwitchFiles, '$type');

  const promptScan = collectPhpStringSet(promptResolverFiles, {
    promptTypeVariable: true,
    promptTypeInArray: true,
  });
  const promptPrefixes = collectPhpPrefixes(promptResolverFiles, '$promptType');

  const clientPromptPath = path.join(root, 'client', 'js', 'prompt-renderer.js');
  const clientPromptScan = fs.existsSync(clientPromptPath)
    ? collectJsPromptTypes(clientPromptPath, { includeSets: true })
    : { values: new Set(), noGeneric: new Set(), meta: new Map() };

  // CPU prompt branches live in client modules (overhaul); merge all sources.
  const cpuPromptFiles = [
    path.join(root, 'client', 'js', 'cpu-loop.js'),
    path.join(root, 'client', 'js', 'cpu-ai.js'),
    path.join(root, 'index.html'),
  ].filter((file) => fs.existsSync(file));
  const cpuPromptScan = { values: new Set(), noGeneric: new Set(), meta: new Map() };
  for (const cpuFile of cpuPromptFiles) {
    const opts = path.basename(cpuFile) === 'index.html'
      ? { regionStart: 'CPU_NO_GENERIC_YESNO', cpuNoGenericFallback: true, includeSets: true }
      : { cpuNoGenericFallback: true, includeSets: true };
    const scan = collectJsPromptTypes(cpuFile, opts);
    for (const value of scan.values) addMatch(cpuPromptScan.values, cpuPromptScan.meta, value, cpuFile, 'cpu');
    for (const value of scan.noGeneric) cpuPromptScan.noGeneric.add(value);
  }
  const indexPath = cpuPromptFiles.find((f) => f.endsWith('cpu-loop.js'))
    || cpuPromptFiles[0]
    || path.join(root, 'client', 'js', 'cpu-loop.js');

  const abilityExactMissing = new Set([...abilityTypes].filter((type) => !abilityScan.values.has(type)));
  const abilityMissingAnyRoute = new Set([...abilityExactMissing].filter((type) => !coveredByPrefix(type, abilityPrefixes.values)));
  const abilityPrefixOnly = new Set([...abilityExactMissing].filter((type) => coveredByPrefix(type, abilityPrefixes.values)));

  const serverPromptTypes = promptScan.values;
  const serverPromptMissingClient = new Set([...serverPromptTypes].filter((type) => !clientPromptScan.values.has(type)));
  const serverPromptMissingCpuExact = new Set([...serverPromptTypes].filter((type) => !cpuPromptScan.values.has(type)));
  const serverPromptMissingCpuNonGeneric = new Set([...serverPromptMissingCpuExact].filter((type) => cpuPromptScan.noGeneric.has(type)));
  const serverPromptMissingCpuMaybeGeneric = new Set([...serverPromptMissingCpuExact].filter((type) => !cpuPromptScan.noGeneric.has(type)));
  const clientPromptOrphans = new Set([...clientPromptScan.values].filter((type) => !serverPromptTypes.has(type)));
  const cpuPromptOrphans = new Set([...cpuPromptScan.values].filter((type) => !serverPromptTypes.has(type)));

  const triggerCounts = countBy(nodes, (node) => node.trigger || '(none)');
  const abilityTypeCounts = countBy(nodes, (node) => node.type);

  const matrix = {
    generated_at: new Date().toISOString(),
    sources: {
      cards: slashPath(cardsPath),
      ability_handler_files: abilityHandlerFiles.map(slashPath),
      prompt_resolver_files: promptResolverFiles.map(slashPath),
      client_prompt_renderer: fs.existsSync(clientPromptPath) ? slashPath(clientPromptPath) : null,
      cpu_prompt_sources: cpuPromptFiles.map(slashPath),
      cpu_prompt_source: fs.existsSync(indexPath) ? slashPath(indexPath) : null,
    },
    summary: {
      total_cards: cards.length,
      top_level_abilities: topLevelAbilities,
      ability_nodes: nodes.length,
      unique_ability_types: abilityTypes.size,
      trigger_counts: triggerCounts,
      server_ability_exact_types: abilityScan.values.size,
      server_ability_prefixes: abilityPrefixes.values.size,
      server_prompt_types: serverPromptTypes.size,
      server_prompt_prefixes: promptPrefixes.values.size,
      client_prompt_types: clientPromptScan.values.size,
      cpu_prompt_types: cpuPromptScan.values.size,
      cpu_no_generic_yesno_types: cpuPromptScan.noGeneric.size,
    },
    ability_types: {
      counts: abilityTypeCounts,
      missing_exact_server_handler: uniqSorted(abilityExactMissing),
      prefix_routed_without_exact_handler: uniqSorted(abilityPrefixOnly).map((type) => ({
        type,
        prefix: coveredByPrefix(type, abilityPrefixes.values),
        count: abilityTypeCounts[type] || 0,
      })),
      missing_exact_handler_or_prefix_route: uniqSorted(abilityMissingAnyRoute),
      top_missing_exact_server_handler: topTypeRows(abilityExactMissing, nodes),
      top_missing_any_server_route: topTypeRows(abilityMissingAnyRoute, nodes),
    },
    prompts: {
      server_prompt_types: uniqSorted(serverPromptTypes),
      server_prompt_prefixes: uniqSorted(promptPrefixes.values),
      client_prompt_types: uniqSorted(clientPromptScan.values),
      cpu_prompt_types: uniqSorted(cpuPromptScan.values),
      cpu_no_generic_yesno_types: uniqSorted(cpuPromptScan.noGeneric),
      server_prompt_missing_client_renderer: uniqSorted(serverPromptMissingClient),
      server_prompt_missing_cpu_exact_branch: uniqSorted(serverPromptMissingCpuExact),
      server_prompt_missing_cpu_non_generic: uniqSorted(serverPromptMissingCpuNonGeneric),
      server_prompt_missing_cpu_maybe_generic: uniqSorted(serverPromptMissingCpuMaybeGeneric),
      client_prompt_orphans: uniqSorted(clientPromptOrphans),
      cpu_prompt_orphans: uniqSorted(cpuPromptOrphans),
    },
    ability_nodes: nodes,
    scan_meta: {
      server_ability_exact: Object.fromEntries([...abilityScan.meta.entries()].sort()),
      server_ability_prefixes: Object.fromEntries([...abilityPrefixes.meta.entries()].sort()),
      server_prompts: Object.fromEntries([...promptScan.meta.entries()].sort()),
      client_prompts: Object.fromEntries([...clientPromptScan.meta.entries()].sort()),
      cpu_prompts: Object.fromEntries([...cpuPromptScan.meta.entries()].sort()),
    },
  };

  return matrix;
}

function formatTopRows(rows, emptyText) {
  if (!rows.length) return [`  ${emptyText}`];
  return rows.slice(0, 8).map((row) => {
    const sample = row.samples?.[0];
    const where = sample ? `, e.g. ${sample.card_no} ${sample.card_name} (${sample.trigger})` : '';
    return `  ${row.type}: ${row.count}${where}`;
  });
}

function conciseReport(matrix) {
  const s = matrix.summary;
  const ability = matrix.ability_types;
  const prompts = matrix.prompts;
  const triggerLine = Object.entries(s.trigger_counts)
    .slice(0, 8)
    .map(([trigger, count]) => `${trigger} ${count}`)
    .join(', ');

  const lines = [];
  lines.push('Card interaction audit');
  lines.push(`Cards: ${s.total_cards} | top-level abilities: ${s.top_level_abilities} | ability nodes: ${s.ability_nodes} | unique ability types: ${s.unique_ability_types}`);
  lines.push(`Triggers: ${triggerLine}`);
  lines.push('');
  lines.push('Server ability coverage');
  lines.push(`  Exact handler matches: ${s.unique_ability_types - ability.missing_exact_server_handler.length}/${s.unique_ability_types}`);
  lines.push(`  Prefix-routed without exact handler: ${ability.prefix_routed_without_exact_handler.length}`);
  lines.push(`  Missing exact handler and prefix route: ${ability.missing_exact_handler_or_prefix_route.length}`);
  lines.push('  Top missing exact handlers:');
  lines.push(...formatTopRows(ability.top_missing_exact_server_handler, 'none'));
  lines.push('');
  lines.push('Prompt coverage');
  lines.push(`  Server prompt resolver types: ${s.server_prompt_types}`);
  lines.push(`  Client prompt renderer types: ${s.client_prompt_types}`);
  lines.push(`  CPU prompt branch types: ${s.cpu_prompt_types} (${s.cpu_no_generic_yesno_types} marked no-generic yes/no)`);
  lines.push(`  Server prompts missing client renderer branch: ${prompts.server_prompt_missing_client_renderer.length}`);
  lines.push(`  Server prompts missing CPU exact branch: ${prompts.server_prompt_missing_cpu_exact_branch.length}`);
  lines.push(`    No-generic CPU risk: ${prompts.server_prompt_missing_cpu_non_generic.length}`);
  lines.push(`    May fall through generic yes/no: ${prompts.server_prompt_missing_cpu_maybe_generic.length}`);
  if (prompts.server_prompt_missing_client_renderer.length) {
    lines.push('  Missing client examples: ' + prompts.server_prompt_missing_client_renderer.slice(0, 12).join(', '));
  }
  if (prompts.server_prompt_missing_cpu_non_generic.length) {
    lines.push('  Missing CPU no-generic examples: ' + prompts.server_prompt_missing_cpu_non_generic.slice(0, 12).join(', '));
  }
  lines.push(`  Client prompt orphans: ${prompts.client_prompt_orphans.length}`);
  lines.push(`  CPU prompt orphans: ${prompts.cpu_prompt_orphans.length}`);
  return lines.join('\n');
}

function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    usage();
    return;
  }

  const matrix = buildMatrix();
  const json = JSON.stringify(matrix, null, 2);
  if (opts.writePath) {
    fs.mkdirSync(path.dirname(opts.writePath), { recursive: true });
    fs.writeFileSync(opts.writePath, `${json}\n`);
  }

  if (opts.json) console.log(json);
  else {
    console.log(conciseReport(matrix));
    if (opts.writePath) console.log(`\nWrote ${slashPath(opts.writePath)}`);
    else console.log('\nRun with --write to save reports/card_interaction_matrix.json');
  }
}

try {
  main();
} catch (err) {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
}
