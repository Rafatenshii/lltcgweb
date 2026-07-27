<?php
/**
 * Core ability resolver — orchestrates type switch and set-module delegation.
 */

require_once __DIR__ . '/AbilityResolverSwitch.php';

function resolveAbilityEffect(array $state, string $pid, array $source, array $ab, array $ctx = []): array {
    // Do not refreshEmptyMainDecks here: WR-targeting skills (add_from_wr, etc.) must
    // see Waiting Room cards. Deck refresh happens on draw / mill / explicit empty-deck needs.
    $type = $ab['type'] ?? '';
    if (isMemberCard($source) && spBp2StageMemberAbilitiesSuppressed($state, $pid)) {
        return $state;
    }
    $p = &$state['players'][$pid];
    $name = $source['name_en'] ?? $source['name'] ?? 'Card';

    // Attribute Wait Energy / Member activations to Nijigasaki effects (Cara Tesoro, etc.).
    $prevNijiAttr = !empty($p['_effect_source_is_niji']);
    $sourceGroup = (string)($source['group'] ?? $ab['group'] ?? '');
    if ($sourceGroup === 'Nijigasaki') {
        $p['_effect_source_is_niji'] = true;
    }

    $state = resolveAbilityEffectSwitch($state, $pid, $source, $ab, $ctx, $type, $p, $name);

    if (nijiIsNijigasakiEffectType($type)) {
        $state = nijiResolveNijigasakiEffect($state, $pid, $source, $ab, $ctx);
        if (!$prevNijiAttr) {
            unset($state['players'][$pid]['_effect_source_is_niji']);
        }
        return $state;
    }

    if (hsIsHasunosoraBp6EffectType($type)) {
        $state = hsResolveHasunosoraEffect($state, $pid, $source, $ab, $ctx);
    } elseif (hsIsHasunosoraPb1EffectType($type)) {
        $state = hsResolveHasunosoraPb1Effect($state, $pid, $source, $ab, $ctx);
    } elseif (hsIsHasunosoraCl1EffectType($type)) {
        $state = hsResolveHasunosoraCl1Effect($state, $pid, $source, $ab, $ctx);
    } elseif (nBp5IsEffectType($type)) {
        $state = nBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sBp5IsEffectType($type)) {
        $state = sBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sBp6IsEffectType($type)) {
        $state = sBp6ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (sSd1IsEffectType($type)) {
        $state = sSd1ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (spBp5IsEffectType($type)) {
        $state = spBp5ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (plMuseGapIsEffectType($type)) {
        $state = plMuseGapResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (plSpSd2IsEffectType($type)) {
        $state = plSpSd2ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (batch99IsEffectType($type)) {
        $state = batch99ResolveEffect($state, $pid, $source, $ab, $ctx);
    } elseif (spBp2IsHandlerType($type)) {
        $state = spBp2ResolveEffect($state, $pid, $source, $ab, $ctx);
    }

    if (!$prevNijiAttr) {
        unset($state['players'][$pid]['_effect_source_is_niji']);
    }
    return $state;
}
