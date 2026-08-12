<?php
/**
 * Required-heart reduction effects — extracted from AbilityResolverSwitch.php.
 */

function tryResolveAbilityEffectSwitchReduceHearts(
    array $state,
    string $pid,
    array $source,
    array $ab,
    array $ctx,
    string $type,
    array &$p,
    string $name
): array {
    switch ($type) {
        case 'reduce_required_hearts_if_blade':
            if (totalStageBlade($p) >= intval($ab['min_blade'] ?? 10)) {
                $reduce = intval($ab['reduce'] ?? 2);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (Stage Blade 10+).");
            }
            break;
        case 'reduce_hearts_if_success_score':
            $scoreSum = sumSuccessLiveScores($p, $state, $pid);
            if ($scoreSum >= intval($ab['min_score_6'] ?? 6)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        // Apply −N to required_hearts on the Live card so UI and heart
                        // checks stay aligned (hearts_color_reduction alone left the
                        // printed 8 gray visible while the check used 7 / fewer).
                        $req = $lc['required_hearts'] ?? $lc['hearts'] ?? [];
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            $lc['required_hearts'] = reduceHeartRequirementsByColor($req, $reduceColor, $reduce);
                        } else {
                            $lc['required_hearts'] = reduceHeartRequirements($req, $reduce);
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
                if ($scoreSum >= intval($ab['min_score_9'] ?? 9)) {
                    bumpLiveCardScore($state, $pid, $source['instance_id'] ?? '', intval($ab['bonus_score'] ?? 1));
                    $state = addLog($state, $state['players'][$pid]['name'] .
                        ' — [' . $name . '] score +' . intval($ab['bonus_score'] ?? 1) . ' (Success score 9+).');
                }
            }
            break;
        case 'reduce_hearts_per_success_count':
            $n = count($p['success_lives'] ?? []) * intval($ab['per_success'] ?? 1);
            if ($n > 0) {
                $color = $ab['color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required hearts reduced by $label (Success Live area).");
            }
            break;
        case 'reduce_hearts_if_opp_wait':
            $opp = ($pid === 'p1') ? 'p2' : 'p1';
            if (stageHasWaitMember($state, $opp)) {
                $reduce = intval($ab['reduce'] ?? 1);
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduce;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $reduce;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$reduce Gray heart(s)" : "$reduce heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced (opponent has Wait).");
            }
            break;
        case 'reduce_hearts_if_baton_group':
            $turn = intval($state['turn'] ?? 1);
            $cnt = countBatonEnteredGroupThisTurn($p, $ab['group'] ?? '', $turn);
            if ($cnt >= intval($ab['min_baton'] ?? 2)) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) .
                    " ($cnt Baton-entered Members).");
            }
            break;
        case 'reduce_hearts_if_named_cost_pair':
            $baseNames = $ab['base_names'] ?? [];
            $higherNames = $ab['higher_names'] ?? [];
            $baseCost = null;
            $ok = false;
            foreach ($p['stage'] as $mbr) {
                if (!$mbr || !cardMatchesNames($mbr, $baseNames)) continue;
                // Use Live-modified cost (Aurora / Proof Kosuzu / Fantasy Sayaka, etc.).
                $baseCost = getEffectiveStageMemberCost($state, $pid, $mbr);
                break;
            }
            if ($baseCost !== null) {
                foreach ($p['stage'] as $mbr) {
                    if (!$mbr || !cardMatchesNames($mbr, $higherNames)) continue;
                    if (getEffectiveStageMemberCost($state, $pid, $mbr) > $baseCost) {
                        $ok = true;
                        break;
                    }
                }
            }
            if ($ok) {
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'any',
                    intval($ab['reduce'] ?? 1)
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    ' — [' . $name . '] required ' . ($ab['color'] ?? 'any') .
                    ' hearts reduced by ' . intval($ab['reduce'] ?? 1) . '.');
            }
            break;
        case 'reduce_hearts_per_entered_moved_subunit':
            $turn = intval($state['turn'] ?? 1);
            $n = countEnteredMovedSubunitThisTurn($p, $ab['subunit'] ?? '', $turn)
                * intval($ab['per_member'] ?? 1);
            if ($n > 0) {
                $color = $ab['reduce_heart_color'] ?? '';
                foreach ($p['live_zone'] as &$lc) {
                    if ($lc && ($lc['instance_id'] ?? '') === ($source['instance_id'] ?? '')) {
                        if ($color !== '') {
                            $reduceColor = ($color === 'gray') ? 'any' : $color;
                            if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                                $lc['hearts_color_reduction'] = [];
                            }
                            $lc['hearts_color_reduction'][$reduceColor] =
                                intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $n;
                        } else {
                            $lc['hearts_reduction'] = intval($lc['hearts_reduction'] ?? 0) + $n;
                        }
                        break;
                    }
                }
                unset($lc);
                $label = ($color === 'gray') ? "$n Gray heart(s)" : "$n heart(s)";
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required $label reduced.");
            }
            break;
        case 'convert_hearts_per_distinct_subunit': {
            // Distortion (PL!SP-pb2-048): per distinct-named subunit Member on Stage,
            // −N gray/any required hearts and +M red; then optional score if red ≥ min.
            $distinct = countDistinctNamedSubunit($p, $ab['subunit'] ?? '');
            if ($distinct > 0) {
                $reducePer = intval($ab['reduce_per'] ?? 2);
                $increasePer = intval($ab['increase_per'] ?? 1);
                $reduceColorRaw = (string)($ab['reduce_heart_color'] ?? 'gray');
                $increaseColorRaw = (string)($ab['increase_heart_color'] ?? 'red');
                $reduceColor = ($reduceColorRaw === 'gray') ? 'any' : normalizeRequiredHeartColor($reduceColorRaw);
                $increaseColor = normalizeRequiredHeartColor($increaseColorRaw);
                $reduceTotal = $distinct * $reducePer;
                $increaseTotal = $distinct * $increasePer;
                $iid = (string)($source['instance_id'] ?? '');
                foreach ($p['live_zone'] as &$lc) {
                    if (!$lc || ($lc['instance_id'] ?? '') !== $iid) {
                        continue;
                    }
                    if ($reduceTotal > 0) {
                        if (!isset($lc['hearts_color_reduction']) || !is_array($lc['hearts_color_reduction'])) {
                            $lc['hearts_color_reduction'] = [];
                        }
                        $lc['hearts_color_reduction'][$reduceColor] =
                            intval($lc['hearts_color_reduction'][$reduceColor] ?? 0) + $reduceTotal;
                    }
                    if ($increaseTotal > 0) {
                        if (!isset($lc['hearts_color_increase']) || !is_array($lc['hearts_color_increase'])) {
                            $lc['hearts_color_increase'] = [];
                        }
                        $lc['hearts_color_increase'][$increaseColor] =
                            intval($lc['hearts_color_increase'][$increaseColor] ?? 0) + $increaseTotal;
                    }
                    break;
                }
                unset($lc);
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] −{$reduceTotal} Gray / +{$increaseTotal} Red required hearts"
                    . " ({$distinct} distinct " . ($ab['subunit'] ?? 'subunit') . ').');

                $scoreMin = intval($ab['score_if_color_min'] ?? 0);
                if ($scoreMin > 0) {
                    $scoreColor = (string)($ab['score_if_color'] ?? 'red');
                    $scoreAmt = intval($ab['score_amount'] ?? 1);
                    foreach ($p['live_zone'] as $lcCheck) {
                        if (!$lcCheck || ($lcCheck['instance_id'] ?? '') !== $iid) {
                            continue;
                        }
                        $eff = applyLiveHeartReductions(
                            $lcCheck['required_hearts'] ?? $lcCheck['hearts'] ?? [],
                            $lcCheck
                        );
                        $colorCount = 0;
                        foreach ($eff as $h) {
                            if (heartRequirementColorsMatch((string)($h['color'] ?? ''), $scoreColor)) {
                                $colorCount += intval($h['count'] ?? 0);
                            }
                        }
                        if ($colorCount >= $scoreMin) {
                            bumpLiveCardScore($state, $pid, $iid, $scoreAmt);
                            $state = addLog($state, $state['players'][$pid]['name'] .
                                " — [$name] score +{$scoreAmt} (required {$scoreColor} ≥ {$scoreMin}).");
                        }
                        break;
                    }
                }
            }
            break;
        }
        case 'reduce_hearts_per_live_zone_group':
            $other = countOtherLiveZoneGroup(
                $p,
                $ab['group'] ?? '',
                !empty($ab['exclude_self']) ? ($source['instance_id'] ?? '') : ''
            );
            if ($other > 0) {
                $reduce = $other * intval($ab['per_card'] ?? 2);
                bumpLiveCardColorReduction(
                    $state,
                    $pid,
                    $source['instance_id'] ?? '',
                    $ab['color'] ?? 'pink',
                    $reduce
                );
                $state = addLog($state, $state['players'][$pid]['name'] .
                    " — [$name] required " . ($ab['color'] ?? 'pink') .
                    " hearts reduced by $reduce.");
            }
            break;
    }
    return $state;
}
