<?php
/**
 * lib_candle_algo.php
 * ===================
 * Location : /public_html/lib_candle_algo.php
 *
 * Shared candle algorithm library.
 *
 * Contains the EXACT implementations extracted from:
 *   - valid_pairs.php         (is_bullish, is_bearish, calculate_price_target_from_candles)
 *   - evaluate_win_loss.php   (evaluatePatternTrade, fetchBarsFromCSVSliced)
 *
 * DO NOT alter the business logic in these functions.
 * Wrapped in function_exists() guards so including this file multiple times
 * and including it alongside the original files is always safe.
 *
 * Include from:
 *   valid_pairs.php          →  require_once __DIR__ . '/lib_candle_algo.php';
 *   evaluate_win_loss.php    →  require_once __DIR__ . '/lib_candle_algo.php';
 *   historical_verify.php    →  require_once __DIR__ . '/../lib_candle_algo.php';
 */

// ================================================================
// CANDLE DIRECTION HELPERS  (from valid_pairs.php)
// ================================================================
if (!function_exists('is_bullish')) {
    function is_bullish($open, $close) {
        return floatval($close) > floatval($open);
    }
}

if (!function_exists('is_bearish')) {
    function is_bearish($open, $close) {
        return floatval($close) < floatval($open);
    }
}

// ================================================================
// PRICE TARGET ALGORITHM  (exact copy from valid_pairs.php)
// Candle ordering: oldest → newest  (index 0 = oldest, 4 = newest/alert)
// ================================================================

if (!function_exists('calculate_price_target_from_candles')) {
    function calculate_price_target_from_candles($candles) {
        if (count($candles) < 5) return null;

        $first3_bullish = true;
        $first3_bearish = true;

        for ($i = 0; $i < 3; $i++) {
            if (!is_bullish($candles[$i]['O'], $candles[$i]['C'])) $first3_bullish = false;
            if (!is_bearish($candles[$i]['O'], $candles[$i]['C'])) $first3_bearish = false;
        }

        if (!$first3_bullish && !$first3_bearish) return null;

        $price_target = null;

        $c3          = $candles[2];
        $c3_range    = floatval($c3['H']) - floatval($c3['L']);
        $c3_half_range = $c3_range / 2.0;

        // ── DOWNTREND (first 3 all bearish) ──────────────────────
        if ($first3_bearish) {
            $c3_midpoint     = floatval($c3['L']) + $c3_half_range;
            $touches_half    = false;
            $highest_opp_high = null;
            $opp_candles_exist = false;

            for ($i = 3; $i < 5; $i++) {
                if (is_bullish($candles[$i]['O'], $candles[$i]['C'])) {
                    $opp_candles_exist = true;
                    if (floatval($candles[$i]['H']) >= $c3_midpoint) {
                        $touches_half = true;
                    }
                    if ($highest_opp_high === null || floatval($candles[$i]['H']) > $highest_opp_high) {
                        $highest_opp_high = floatval($candles[$i]['H']);
                    }
                }
            }

            if ($opp_candles_exist) {
                if (!$touches_half) {
                    $price_target = $highest_opp_high;
                } else {
                    $c_prev1 = $candles[2];
                    $c_prev2 = $candles[1];
                    $u_wick1 = floatval($c_prev1['H']) - max(floatval($c_prev1['O']), floatval($c_prev1['C']));

                    $recent_candles = [$candles[0], $candles[1], $candles[2]];
                    $total_size = 0;
                    foreach ($recent_candles as $c) {
                        $total_size += (floatval($c['H']) - floatval($c['L']));
                    }
                    $avg_candle_size = $total_size / 3.0;

                    if ($avg_candle_size > 0 && $u_wick1 > ($avg_candle_size * 0.15)) {
                        $price_target = floatval($c_prev1['H']);
                    } else {
                        $price_target = (floatval($c_prev1['H']) > floatval($c_prev2['H']))
                            ? floatval($c_prev1['H'])
                            : floatval($c_prev2['H']);
                    }
                }
            }
            return $price_target;
        }

        // ── UPTREND (first 3 all bullish) ───────────────────────
        if ($first3_bullish) {
            $c3_midpoint    = floatval($c3['H']) - $c3_half_range;
            $touches_half   = false;
            $lowest_opp_low = null;
            $opp_candles_exist = false;

            for ($i = 3; $i < 5; $i++) {
                if (is_bearish($candles[$i]['O'], $candles[$i]['C'])) {
                    $opp_candles_exist = true;
                    if (floatval($candles[$i]['L']) <= $c3_midpoint) {
                        $touches_half = true;
                    }
                    if ($lowest_opp_low === null || floatval($candles[$i]['L']) < $lowest_opp_low) {
                        $lowest_opp_low = floatval($candles[$i]['L']);
                    }
                }
            }

            if ($opp_candles_exist) {
                if (!$touches_half) {
                    $price_target = $lowest_opp_low;
                } else {
                    $c_prev1 = $candles[2];
                    $c_prev2 = $candles[1];
                    $l_wick1 = min(floatval($c_prev1['O']), floatval($c_prev1['C'])) - floatval($c_prev1['L']);

                    $recent_candles = [$candles[0], $candles[1], $candles[2]];
                    $total_size = 0;
                    foreach ($recent_candles as $c) {
                        $total_size += (floatval($c['H']) - floatval($c['L']));
                    }
                    $avg_candle_size = $total_size / 3.0;

                    if ($avg_candle_size > 0 && $l_wick1 > ($avg_candle_size * 0.15)) {
                        $price_target = floatval($c_prev1['L']);
                    } else {
                        $price_target = (floatval($c_prev1['L']) < floatval($c_prev2['L']))
                            ? floatval($c_prev1['L'])
                            : floatval($c_prev2['L']);
                    }
                }
            }
            return $price_target;
        }

        return null;
    }
}

// ================================================================
// CPANEL-OPTIMISED CSV LOADER  (exact copy from evaluate_win_loss.php)
//
// Loads prev-day + alert-day + next-day files.
// Slices from alertTimestamp-1hr to sessionEnd+300s.
// Deduplicates and sorts chronologically.
//
// Supports dated sub-folder layout:
//   {dataBasePath}/{PAIR}/FX_{PAIR}-YYYY-MM-DD.csv
// AND legacy single-file layout:
//   {dataBasePath}/FX_{PAIR}.csv
// ================================================================

if (!function_exists('fetchBarsFromCSVSliced')) {
    function fetchBarsFromCSVSliced(string $dataBasePath, string $pairName, int $alertTimestamp): array {
        $IST = new DateTimeZone('Asia/Kolkata');
        $out = [];

        if (!is_dir($dataBasePath)) return $out;

        $cleanPair    = str_replace(['/', '_', ' '], '', $pairName);
        $pairFolder   = $dataBasePath . '/' . $cleanPair;
        $legacyFile   = $dataBasePath . '/FX_' . $cleanPair . '.csv';
        $hasPairFolder = is_dir($pairFolder);
        $hasLegacyFile = is_file($legacyFile);

        if (!$hasPairFolder && !$hasLegacyFile) return $out;

        $alertDT = new DateTime("@$alertTimestamp");
        $alertDT->setTimezone($IST);
        $alertDate = $alertDT->format('Y-m-d');

        $sessionEndDT = new DateTime("{$alertDate} 21:30:00", $IST);
        $endThreshold = $sessionEndDT->getTimestamp();

        $prevDate = (clone $alertDT)->modify('-1 day')->format('Y-m-d');
        $nextDate = (clone $alertDT)->modify('+1 day')->format('Y-m-d');
        $datesToLoad = [$prevDate, $alertDate, $nextDate];

        $startThreshold = $alertTimestamp - 3600;

        if (!$hasPairFolder && $hasLegacyFile) {
            $datesToLoad = [null];
        }

        foreach ($datesToLoad as $dateStr) {
            $filePath = $hasPairFolder
                ? $pairFolder . "/FX_{$cleanPair}-{$dateStr}.csv"
                : $legacyFile;

            if (!file_exists($filePath)) continue;

            $handle = fopen($filePath, 'r');
            if (!$handle) continue;

            $header = fgetcsv($handle, 1000, ',');
            if (!$header) { fclose($handle); continue; }
            $header = array_map(fn($c) => trim(str_replace('"', '', $c)), $header);

            $timeIdx  = array_search('time',  $header);
            $openIdx  = array_search('open',  $header);
            $highIdx  = array_search('high',  $header);
            $lowIdx   = array_search('low',   $header);
            $closeIdx = array_search('close', $header);

            if ($timeIdx === false || $openIdx === false || $closeIdx === false) {
                fclose($handle);
                continue;
            }

            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (!isset($data[$timeIdx], $data[$openIdx], $data[$closeIdx])) continue;

                $timeVal = (int)floatval($data[$timeIdx]);
                if ($timeVal < $startThreshold) continue;
                if ($timeVal > $endThreshold + 300) break;

                $out[] = [
                    'time'  => $timeVal,
                    'open'  => (float)$data[$openIdx],
                    'high'  => ($highIdx !== false && isset($data[$highIdx])) ? (float)$data[$highIdx] : 0.0,
                    'low'   => ($lowIdx  !== false && isset($data[$lowIdx]))  ? (float)$data[$lowIdx]  : 0.0,
                    'close' => (float)$data[$closeIdx],
                ];
            }
            fclose($handle);
            if (!$hasPairFolder) break;
        }

        usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
        $deduped = [];
        foreach ($out as $row) {
            $deduped[$row['time']] = $row;
        }
        return array_values($deduped);
    }
}

// ================================================================
// TRADE EVALUATOR  (exact copy from evaluate_win_loss.php)
//
// Args:
//   $candles          — array from fetchBarsFromCSVSliced
//   $direction        — 'UP' or 'DOWN'
//   $targetPrice      — float
//   $alertTimestamp   — Unix seconds of alert candle (5-min window check)
//   $sessionEndTimestamp — Unix seconds of 21:30 IST on trade date
//   $newsEvents       — [['impact'=>int, 'time'=>int], ...]
//
// Returns: ['result'=>string, 'reason'=>string, 'time'=>int?, 'price'=>float?]
// ================================================================

if (!function_exists('evaluatePatternTrade')) {
    function evaluatePatternTrade($candles, $direction, $targetPrice, $alertTimestamp, $sessionEndTimestamp, $newsEvents = []) {
        $alertCandleIndex = -1;
        for ($i = 0; $i < count($candles); $i++) {
            if ($alertTimestamp >= $candles[$i]['time'] && $alertTimestamp < ($candles[$i]['time'] + 300)) {
                $alertCandleIndex = $i;
                break;
            }
        }
        if ($alertCandleIndex === -1) {
            return ['result' => 'pending', 'reason' => 'Waiting for CSV candle data matching alert time.'];
        }

        $entryPrice       = $candles[$alertCandleIndex]['close'];
        $fiftyPercentPrice = $entryPrice + (($targetPrice - $entryPrice) / 2);
        $touched50Percent  = false;

        $consecutiveRed   = 0;
        $consecutiveGreen = 0;

        $startWaveIndex = $alertCandleIndex;
        for ($j = $alertCandleIndex; $j < count($candles); $j++) {
            $cTemp = $candles[$j];
            if ($direction === 'UP') {
                if ($cTemp['close'] < $cTemp['open']) { $startWaveIndex = $j; break; }
            } elseif ($direction === 'DOWN') {
                if ($cTemp['close'] > $cTemp['open']) { $startWaveIndex = $j; break; }
            }
        }

        $targetBroken    = false;
        $cooldownEndIndex = -1;

        for ($i = 0; $i < count($candles); $i++) {
            $c = $candles[$i];
            $was50PercentTouchedBeforeThisCandle = $touched50Percent;

            if ($i >= $alertCandleIndex && !$touched50Percent) {
                if ($direction === 'UP'   && $c['low']  <= $fiftyPercentPrice) $touched50Percent = true;
                elseif ($direction === 'DOWN' && $c['high'] >= $fiftyPercentPrice) $touched50Percent = true;
            }

            $impactForCandle = 0;
            foreach ($newsEvents as $news) {
                if ($news['time'] >= $c['time'] && $news['time'] < ($c['time'] + 300)) {
                    $impactForCandle = max($impactForCandle, (int)$news['impact']);
                }
            }

            $brokeTargetThisCandle = false;
            if ($direction === 'UP'   && $c['close'] < $targetPrice) $brokeTargetThisCandle = true;
            elseif ($direction === 'DOWN' && $c['close'] > $targetPrice) $brokeTargetThisCandle = true;

            if ($impactForCandle > 0 && $i >= $alertCandleIndex) {
                if ($impactForCandle == 1) {
                    $cooldownEndIndex = max($cooldownEndIndex, $i + 1);
                } else {
                    if (!$was50PercentTouchedBeforeThisCandle) {
                        $cooldownEndIndex = max($cooldownEndIndex, $i + ($impactForCandle == 2 ? 5 : 8));
                    } else {
                        if (!$brokeTargetThisCandle) {
                            $cooldownEndIndex = max($cooldownEndIndex, $i + ($impactForCandle == 2 ? 5 : 8));
                        }
                    }
                }
            }

            if ($i < $cooldownEndIndex) {
                $consecutiveRed = 0;
                $consecutiveGreen = 0;
            } else {
                if ($c['close'] < $c['open'])      { $consecutiveRed++;   $consecutiveGreen = 0; }
                elseif ($c['close'] > $c['open'])  { $consecutiveGreen++; $consecutiveRed   = 0; }
                else                               { $consecutiveRed = 0; $consecutiveGreen = 0; }
            }

            if ($i >= $alertCandleIndex) {
                if ($c['time'] > $sessionEndTimestamp) break;

                $waveLength = $i - $startWaveIndex + 1;
                if ($i < $startWaveIndex) $waveLength = $i - $alertCandleIndex + 1;

                if ($direction === 'UP') {
                    if ($c['close'] < $targetPrice) {
                        if (!$targetBroken) {
                            $isNewsBreak = ($impactForCandle >= 2);
                            if ($waveLength < 3 && !$isNewsBreak)
                                return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                            $targetBroken = true;
                        }
                        if ($consecutiveRed >= 3) {
                            $c1 = $candles[$i + 1] ?? null;
                            $c2 = $candles[$i + 2] ?? null;
                            if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                            if ($c1['close'] > $c1['open'])
                                return ['result' => 'win',  'reason' => 'Direct Win.', 'time' => $c1['time'], 'price' => $c1['close']];
                            if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                            if ($c2['close'] > $c2['open'])
                                return ['result' => 'win',  'reason' => 'MTG1 Win.', 'time' => $c2['time'], 'price' => $c2['close']];
                            return ['result' => 'loss', 'reason' => 'Failed.',   'time' => $c2['time'], 'price' => $c2['close']];
                        }
                    }
                    if ($targetBroken && $consecutiveRed === 0 && $i >= $cooldownEndIndex) {
                        return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
                    }
                }

                if ($direction === 'DOWN') {
                    if ($c['close'] > $targetPrice) {
                        if (!$targetBroken) {
                            $isNewsBreak = ($impactForCandle >= 2);
                            if ($waveLength < 3 && !$isNewsBreak)
                                return ['result' => 'setup_not_formed', 'reason' => 'Too fast.'];
                            $targetBroken = true;
                        }
                        if ($consecutiveGreen >= 3) {
                            $c1 = $candles[$i + 1] ?? null;
                            $c2 = $candles[$i + 2] ?? null;
                            if (!$c1) return ['result' => 'pending', 'reason' => 'Waiting for C1.'];
                            if ($c1['close'] < $c1['open'])
                                return ['result' => 'win',  'reason' => 'Direct Win.', 'time' => $c1['time'], 'price' => $c1['close']];
                            if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                            if ($c2['close'] < $c2['open'])
                                return ['result' => 'win',  'reason' => 'MTG1 Win.', 'time' => $c2['time'], 'price' => $c2['close']];
                            return ['result' => 'loss', 'reason' => 'Failed.',   'time' => $c2['time'], 'price' => $c2['close']];
                        }
                    }
                    if ($targetBroken && $consecutiveGreen === 0 && $i >= $cooldownEndIndex) {
                        return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
                    }
                }
            }
        }

        if ($targetBroken) {
            $lastCandle = end($candles);
            if ($lastCandle && $lastCandle['time'] >= $sessionEndTimestamp) {
                return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
            } else {
                return ['result' => 'pending', 'reason' => 'Waiting for setup streak to form.'];
            }
        }

        return ['result' => 'pending', 'reason' => 'Target not broken.'];
    }
}
