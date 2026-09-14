<?php
// ============================================================
// HISTORICAL BULK EVALUATOR — v2
//
// Changes from v1:
//   • Signal timestamp: last_alert_time (IST) instead of created_at
//   • Target: full 5-branch decision tree instead of flat O3
//   • Signal time gate: 12:30:00–21:30:00 IST (no DB write if outside)
//   • Same-day resolution validation (≤ 21:30 IST, same IST date)
//   • News: event_date + event_time combined, 10-min IST window
//   • Setup validation: C1/C2/C3 must match direction (no doji)
//   • price_target in DB is NEVER overwritten; comparison is logged only
//   • Debug mode (&debug=1): dry-run, verbose per-trade metadata, no DB writes
//
// Direction convention (DB → spec):
//   'UP'   = SELL setup: C1/C2/C3 bullish, C4/C5 bearish pullback
//            target is BELOW current price; evaluator waits for close < target
//   'DOWN' = BUY  setup: C1/C2/C3 bearish, C4/C5 bullish pullback
//            target is ABOVE current price; evaluator waits for close > target
//
// Place at: public_html/dataset/evaluate_historical.php
// Run:      ?start_date=2020-01-01&end_date=2025-06-30&batch=500
// Resume:   &after_id=LAST_PRINTED_ID
// Debug:    &debug=1   (dry-run, full metadata per trade)
// Force:    &force=1   (re-evaluate already-evaluated records)
// ============================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', '512M');
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../db.php';

$IST = new DateTimeZone('Asia/Kolkata');
$UTC = new DateTimeZone('UTC');

// -------------------------------------------------------
// CONFIG
// -------------------------------------------------------
$startDate  = $_GET['start_date'] ?? '2020-01-01';
$endDate    = $_GET['end_date']   ?? '2025-06-30';
$batchSize  = (int)($_GET['batch']    ?? 500);
$afterId    = (int)($_GET['after_id'] ?? 0);
$forceReval = isset($_GET['force']) && $_GET['force'] === '1';
$debugMode  = isset($_GET['debug']) && $_GET['debug'] === '1';

// IST session window, in seconds-from-midnight
// 12:30:00 = 45000 s, 21:30:00 = 77400 s — both endpoints INCLUSIVE
define('SESSION_START_SECS', 45000);
define('SESSION_END_SECS',   77400);

// -------------------------------------------------------
// HELPER: seconds-from-midnight in IST, from any DateTime
// -------------------------------------------------------
function istSecondsOfDay(DateTime $dt): int
{
    $ist = clone $dt;
    $ist->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return (int)$ist->format('H') * 3600
         + (int)$ist->format('i') * 60
         + (int)$ist->format('s');
}

// -------------------------------------------------------
// CSV PATH ROUTER — UNCHANGED from original
// last_alert_time is stored as IST (YYYY-MM-DD HH:MM:SS)
// -------------------------------------------------------
function getHistoricalCsvPath(string $pair, string $lastAlertTimeIST): string
{
    $date = substr($lastAlertTimeIST, 0, 10);
    $ts   = strtotime($lastAlertTimeIST); // server TZ = Asia/Kolkata, safe

    if ($ts < strtotime('2025-06-01 00:00:00')) {
        return __DIR__ . "/BEFORE-JUN-2025/{$pair}/FX_{$pair}-{$date}.csv";
    } elseif ($ts < strtotime('2026-03-01 00:00:00')) {
        return __DIR__ . "/dataset/JUN-2025 TO FEB-2026/FX_{$pair}.csv";
    } else {
        return __DIR__ . "/dataset/dataset/FX_{$pair}.csv";
    }
}

// -------------------------------------------------------
// CSV READER — UNCHANGED from original
// Loads candles within [alertTimestamp-3600, sessionEnd+600]
// -------------------------------------------------------
function fetchHistoricalCandles(string $filePath, int $alertTimestamp, int $sessionEndTimestamp): array
{
    $out = [];
    if (!file_exists($filePath)) return $out;

    $handle = fopen($filePath, 'r');
    if (!$handle) return $out;

    fgets($handle); // skip header

    $threshold   = $alertTimestamp - 3600;
    $hardCeiling = $sessionEndTimestamp + 600; // 2 candles past session end

    while (($line = fgets($handle)) !== false) {
        $d = str_getcsv(trim($line));
        if (count($d) < 5 || !is_numeric($d[0])) continue;
        $t = (int)$d[0];
        if ($t < $threshold)   continue;
        if ($t > $hardCeiling) break;
        $out[] = [
            'time'  => $t,
            'open'  => (float)$d[1],
            'high'  => (float)$d[2],
            'low'   => (float)$d[3],
            'close' => (float)$d[4],
        ];
    }
    fclose($handle);
    return $out;
}

// -------------------------------------------------------
// EVALUATOR — UNCHANGED (verbatim from original)
// Receives the RECALCULATED target, never the DB-stored one.
// -------------------------------------------------------
function evaluatePatternTrade($candles, $direction, $targetPrice, $alertTimestamp, $sessionEndTimestamp, $newsEvents = [])
{
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

    $entryPrice        = $candles[$alertCandleIndex]['close'];
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

    $targetBroken     = false;
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
            $consecutiveRed = 0; $consecutiveGreen = 0;
        } else {
            if      ($c['close'] < $c['open']) { $consecutiveRed++;   $consecutiveGreen = 0; }
            elseif  ($c['close'] > $c['open']) { $consecutiveGreen++; $consecutiveRed   = 0; }
            else                               { $consecutiveRed = 0; $consecutiveGreen = 0; }
        }

        if ($i >= $alertCandleIndex) {
            if ($c['time'] > $sessionEndTimestamp) break;

            $waveLength = ($i >= $startWaveIndex)
                ? $i - $startWaveIndex + 1
                : $i - $alertCandleIndex + 1;

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
                            return ['result' => 'win', 'reason' => 'Direct Win.', 'time' => $c1['time'], 'price' => $c1['close']];
                        if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                        if ($c2['close'] > $c2['open'])
                            return ['result' => 'win', 'reason' => 'MTG1 Win.', 'time' => $c2['time'], 'price' => $c2['close']];
                        return ['result' => 'loss', 'reason' => 'Failed.', 'time' => $c2['time'], 'price' => $c2['close']];
                    }
                }
                if ($targetBroken && $consecutiveRed === 0 && $i >= $cooldownEndIndex)
                    return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
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
                            return ['result' => 'win', 'reason' => 'Direct Win.', 'time' => $c1['time'], 'price' => $c1['close']];
                        if (!$c2) return ['result' => 'pending', 'reason' => 'Waiting for C2.'];
                        if ($c2['close'] < $c2['open'])
                            return ['result' => 'win', 'reason' => 'MTG1 Win.', 'time' => $c2['time'], 'price' => $c2['close']];
                        return ['result' => 'loss', 'reason' => 'Failed.', 'time' => $c2['time'], 'price' => $c2['close']];
                    }
                }
                if ($targetBroken && $consecutiveGreen === 0 && $i >= $cooldownEndIndex)
                    return ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.'];
            }
        }
    }

    if ($targetBroken) {
        $lastCandle = end($candles);
        return ($lastCandle && $lastCandle['time'] >= $sessionEndTimestamp)
            ? ['result' => 'setup_not_formed', 'reason' => 'Invalid streak.']
            : ['result' => 'pending',           'reason' => 'Waiting for setup streak.'];
    }

    return ['result' => 'pending', 'reason' => 'Target not broken.'];
}

// -------------------------------------------------------
// TARGET CALCULATOR — NEW
//
// Implements the full spec decision tree.
//
// Parameters
//   $setupCandles  : 5-element array, index 0=C1 (oldest) … 4=C5 (newest alert)
//                    Each element: ['O'=>float, 'H'=>float, 'L'=>float, 'C'=>float]
//                    Sourced from raw_trade_data.O1/H1/L1/C1 … O5/H5/L5/C5
//
//   $direction     : 'UP'  → SELL spec (C1/C2/C3 bullish, C4/C5 bearish pullback)
//                            target is BELOW current price
//                    'DOWN'→ BUY  spec (C1/C2/C3 bearish, C4/C5 bullish pullback)
//                            target is ABOVE current price
//
//   $signalTimestamp : unix timestamp derived from last_alert_time (IST string → getTimestamp())
//
//   $newsEvents    : array of ['impact'=>int, 'time'=>int(unix)]
//                    event_date+event_time combined and stored as IST unix timestamps
//
// Returns
//   metadata array (always); check ['valid'] before using ['final_target']
//
//   Possible target_reason values:
//     STRONG_PULLBACK_C3_MIDPOINT
//     WEAK_PULLBACK_C1_OPEN
//     WEAK_PULLBACK_NEWS_PROMINENT_C3_WICK
//     WEAK_PULLBACK_NEWS_NONPROMINENT_C2_C3_EXTREME
//     SKIPPED_INSUFFICIENT_CANDLES
//     SKIPPED_INVALID_C1C2C3_FOR_SELL
//     SKIPPED_INVALID_C1C2C3_FOR_BUY
//     SKIPPED_UNKNOWN_DIRECTION
// -------------------------------------------------------
function calculateTarget(
    array  $setupCandles,
    string $direction,
    int    $signalTimestamp,
    array  $newsEvents
): array {
    $meta = [
        'valid'             => false,
        'direction'         => $direction,
        'pullback_strength' => null,
        'c3_midpoint'       => null,
        'standard_target'   => null,
        'final_target'      => null,
        'news_found'        => false,
        'news_impact'       => 0,
        'news_time_ist'     => null,
        'avg_size'          => null,
        'wick_size'         => null,
        'wick_prominent'    => false,
        'target_reason'     => 'SKIPPED_UNKNOWN',
    ];

    if (count($setupCandles) < 5) {
        $meta['target_reason'] = 'SKIPPED_INSUFFICIENT_CANDLES';
        return $meta;
    }

    [$c1, $c2, $c3, $c4, $c5] = $setupCandles;

    // ----------------------------------------------------------------
    // Validate C1/C2/C3 direction — doji candles (open == close) fail.
    // Strict inequality only: no epsilon, matching the spec's "strictly".
    // ----------------------------------------------------------------
    if ($direction === 'UP') {
        // SELL setup: C1, C2, C3 must all be bullish (close > open)
        if (!($c1['C'] > $c1['O'] && $c2['C'] > $c2['O'] && $c3['C'] > $c3['O'])) {
            $meta['target_reason'] = 'SKIPPED_INVALID_C1C2C3_FOR_SELL';
            return $meta;
        }
    } elseif ($direction === 'DOWN') {
        // BUY setup: C1, C2, C3 must all be bearish (close < open)
        if (!($c1['C'] < $c1['O'] && $c2['C'] < $c2['O'] && $c3['C'] < $c3['O'])) {
            $meta['target_reason'] = 'SKIPPED_INVALID_C1C2C3_FOR_BUY';
            return $meta;
        }
    } else {
        $meta['target_reason'] = 'SKIPPED_UNKNOWN_DIRECTION';
        return $meta;
    }

    $meta['valid'] = true;

    // ----------------------------------------------------------------
    // C3 midpoint — formula identical for both directions per spec §3
    // SELL: C3_Low + ((C3_High - C3_Low) / 2)  = (C3_H + C3_L) / 2
    // BUY : C3_High - ((C3_High - C3_Low) / 2) = (C3_H + C3_L) / 2
    // ----------------------------------------------------------------
    $c3Midpoint          = ($c3['H'] + $c3['L']) / 2.0;
    $meta['c3_midpoint'] = $c3Midpoint;

    // ----------------------------------------------------------------
    // Pullback strength check (§4)
    // SELL/UP  : STRONG if any of C4, C5 HIGH >= C3 midpoint (inclusive)
    // BUY /DOWN: STRONG if any of C4, C5 LOW  <= C3 midpoint (inclusive)
    // ----------------------------------------------------------------
    if ($direction === 'UP') {
        $strong = ($c4['H'] >= $c3Midpoint || $c5['H'] >= $c3Midpoint);
    } else {
        $strong = ($c4['L'] <= $c3Midpoint || $c5['L'] <= $c3Midpoint);
    }

    $meta['pullback_strength'] = $strong ? 'STRONG' : 'WEAK';

    // ----------------------------------------------------------------
    // STRONG branch (§5): target = C3 midpoint, no news check
    // ----------------------------------------------------------------
    if ($strong) {
        $meta['standard_target'] = $c3Midpoint;
        $meta['final_target']    = $c3Midpoint;
        $meta['target_reason']   = 'STRONG_PULLBACK_C3_MIDPOINT';
        return $meta;
    }

    // ----------------------------------------------------------------
    // WEAK branch (§5): default target = C1 open
    // ----------------------------------------------------------------
    $meta['standard_target'] = $c1['O'];

    // ----------------------------------------------------------------
    // News check (§6, §7): Impact ≥ 2 event within next 10 IST minutes.
    // "Next 10 minutes" = [signalTimestamp, signalTimestamp + 600).
    // Comparison is between unix timestamps — timezone-independent.
    // Impact 1 does NOT qualify; Impact 2 or 3 does.
    // ----------------------------------------------------------------
    $newsWindowEnd  = $signalTimestamp + 600;
    $qualifyingNews = null;

    foreach ($newsEvents as $news) {
        if ((int)$news['impact'] >= 2
            && $news['time'] >= $signalTimestamp
            && $news['time'] <  $newsWindowEnd
        ) {
            // Keep the earliest qualifying event for logging
            if ($qualifyingNews === null || $news['time'] < $qualifyingNews['time']) {
                $qualifyingNews = $news;
            }
        }
    }

    if ($qualifyingNews === null) {
        // No qualifying news — keep C1 open
        $meta['final_target']  = $c1['O'];
        $meta['target_reason'] = 'WEAK_PULLBACK_C1_OPEN';
        return $meta;
    }

    // News found — record metadata
    $newsDt = (new DateTime('@' . $qualifyingNews['time']))
                ->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $meta['news_found']    = true;
    $meta['news_impact']   = (int)$qualifyingNews['impact'];
    $meta['news_time_ist'] = $newsDt->format('Y-m-d H:i:s');

    // ----------------------------------------------------------------
    // Average candle size (§8): full HIGH-LOW range of C1, C2, C3
    // ----------------------------------------------------------------
    $avgSize          = (($c1['H'] - $c1['L']) + ($c2['H'] - $c2['L']) + ($c3['H'] - $c3['L'])) / 3.0;
    $meta['avg_size'] = $avgSize;

    if ($direction === 'UP') {
        // ----------------------------------------------------------------
        // SELL wick override (§9)
        // Upper wick = C3_High − max(C3_Open, C3_Close)
        // Prominent   = Upper_Wick  >  Avg_Size * 0.15  (strictly greater)
        // Prominent   → target = C3_High
        // Not prom.   → target = max(C2_High, C3_High)
        // ----------------------------------------------------------------
        $upperWick              = $c3['H'] - max($c3['O'], $c3['C']);
        $meta['wick_size']      = $upperWick;
        $prominent              = $upperWick > ($avgSize * 0.15);  // strictly >
        $meta['wick_prominent'] = $prominent;

        if ($prominent) {
            $meta['final_target']  = $c3['H'];
            $meta['target_reason'] = 'WEAK_PULLBACK_NEWS_PROMINENT_C3_WICK';
        } else {
            $meta['final_target']  = max($c2['H'], $c3['H']);
            $meta['target_reason'] = 'WEAK_PULLBACK_NEWS_NONPROMINENT_C2_C3_EXTREME';
        }
    } else {
        // ----------------------------------------------------------------
        // BUY wick override (§10)
        // Lower wick = min(C3_Open, C3_Close) − C3_Low
        // Prominent   = Lower_Wick  >  Avg_Size * 0.15  (strictly greater)
        // Prominent   → target = C3_Low
        // Not prom.   → target = min(C2_Low, C3_Low)
        // ----------------------------------------------------------------
        $lowerWick              = min($c3['O'], $c3['C']) - $c3['L'];
        $meta['wick_size']      = $lowerWick;
        $prominent              = $lowerWick > ($avgSize * 0.15);  // strictly >
        $meta['wick_prominent'] = $prominent;

        if ($prominent) {
            $meta['final_target']  = $c3['L'];
            $meta['target_reason'] = 'WEAK_PULLBACK_NEWS_PROMINENT_C3_WICK';
        } else {
            $meta['final_target']  = min($c2['L'], $c3['L']);
            $meta['target_reason'] = 'WEAK_PULLBACK_NEWS_NONPROMINENT_C2_C3_EXTREME';
        }
    }

    return $meta;
}

// -------------------------------------------------------
// SELF-TESTS — run via ?mode=test (no DB required for target logic)
// Tests the calculateTarget() decision tree against known inputs.
// -------------------------------------------------------
if (isset($_GET['mode']) && $_GET['mode'] === 'test') {
    echo '<pre>';
    echo "====================================================\n";
    echo " calculateTarget() SELF-TESTS\n";
    echo "====================================================\n\n";

    $pass = 0; $fail = 0;
    $fakeTs = strtotime('2024-01-10 14:00:00'); // IST timestamp for signal

    // Helper
    $check = function(string $label, $got, $expected) use (&$pass, &$fail) {
        $ok = (abs((float)$got - (float)$expected) < 0.00001);
        echo "  " . ($ok ? "✓" : "✗") . " {$label}\n";
        if (!$ok) echo "       got={$got} expected={$expected}\n";
        $ok ? $pass++ : $fail++;
    };
    $checkStr = function(string $label, $got, $expected) use (&$pass, &$fail) {
        $ok = ($got === $expected);
        echo "  " . ($ok ? "✓" : "✗") . " {$label}\n";
        if (!$ok) echo "       got='{$got}' expected='{$expected}'\n";
        $ok ? $pass++ : $fail++;
    };

    // --- T1: Strong SELL (UP) — C4/C5 high crosses C3 midpoint ---
    echo "T1: Strong SELL (UP) — C4/C5 HIGH >= C3 midpoint → target = C3 midpoint\n";
    $c = [
        ['O'=>1.1000,'H'=>1.1020,'L'=>1.0990,'C'=>1.1015], // C1 bullish
        ['O'=>1.1015,'H'=>1.1035,'L'=>1.1010,'C'=>1.1030], // C2 bullish
        ['O'=>1.1030,'H'=>1.1060,'L'=>1.1025,'C'=>1.1055], // C3 bullish; H=1.1060, L=1.1025 → midpoint=1.10425
        ['O'=>1.1050,'H'=>1.1055,'L'=>1.1035,'C'=>1.1038], // C4 bearish; H=1.1055 >= 1.10425 ✓ STRONG
        ['O'=>1.1038,'H'=>1.1040,'L'=>1.1020,'C'=>1.1022], // C5 bearish
    ];
    $r = calculateTarget($c, 'UP', $fakeTs, []);
    $checkStr("T1 valid",            $r['valid'] ? 'true' : 'false', 'true');
    $checkStr("T1 pullback",         $r['pullback_strength'], 'STRONG');
    $check("T1 final_target",        $r['final_target'], ($c[2]['H'] + $c[2]['L']) / 2.0);
    $checkStr("T1 reason",           $r['target_reason'], 'STRONG_PULLBACK_C3_MIDPOINT');

    // --- T2: Weak SELL (UP) — no news → target = C1 open ---
    echo "\nT2: Weak SELL (UP) — weak pullback, no news → target = C1 open\n";
    $c2t = [
        ['O'=>1.1000,'H'=>1.1020,'L'=>1.0995,'C'=>1.1015], // C1 bullish; C1 open=1.1000
        ['O'=>1.1015,'H'=>1.1035,'L'=>1.1010,'C'=>1.1030], // C2 bullish
        ['O'=>1.1030,'H'=>1.1060,'L'=>1.1025,'C'=>1.1055], // C3 bullish; midpoint=1.10425
        ['O'=>1.1050,'H'=>1.1048,'L'=>1.1040,'C'=>1.1042], // C4 bearish; H=1.1048 < 1.10425 WEAK
        ['O'=>1.1042,'H'=>1.1041,'L'=>1.1030,'C'=>1.1032], // C5 bearish
    ];
    // C4 H=1.1048, C3 midpoint=1.10425 → 1.1048 >= 1.10425 → actually STRONG
    // Let me adjust so it's weak: C4 H must be < 1.10425
    $c2t[2] = ['O'=>1.1030,'H'=>1.1080,'L'=>1.1025,'C'=>1.1055]; // midpoint=(1.1080+1.1025)/2=1.10525
    $c2t[3] = ['O'=>1.1052,'H'=>1.1050,'L'=>1.1040,'C'=>1.1042]; // C4 H=1.1050 < 1.10525 WEAK
    $c2t[4] = ['O'=>1.1042,'H'=>1.1041,'L'=>1.1030,'C'=>1.1032]; // C5 H=1.1041 < 1.10525 WEAK
    $r2 = calculateTarget($c2t, 'UP', $fakeTs, []);
    $checkStr("T2 valid",     $r2['valid'] ? 'true' : 'false', 'true');
    $checkStr("T2 pullback",  $r2['pullback_strength'], 'WEAK');
    $checkStr("T2 news",      $r2['news_found'] ? 'true' : 'false', 'false');
    $check("T2 final_target", $r2['final_target'], $c2t[0]['O']); // C1 open
    $checkStr("T2 reason",    $r2['target_reason'], 'WEAK_PULLBACK_C1_OPEN');

    // --- T3: Weak SELL (UP) + news + prominent upper wick → target = C3 high ---
    echo "\nT3: Weak SELL (UP) + news + C3 upper wick >15% avg → target = C3 high\n";
    // avg_size = ((H1-L1)+(H2-L2)+(H3-L3))/3; wick = C3_H - max(C3_O, C3_C)
    // C1 range=0.0025, C2 range=0.0025, C3 range=0.0055; avg=0.00350
    // C3: O=1.1030, H=1.1085, L=1.1030, C=1.1055 → upper_wick=1.1085-max(1.1030,1.1055)=1.1085-1.1055=0.003
    // 15% of 0.00350 = 0.000525; 0.003 > 0.000525 → prominent ✓
    $c3t = [
        ['O'=>1.1000,'H'=>1.1025,'L'=>1.1000,'C'=>1.1020], // C1 bullish; range=0.0025
        ['O'=>1.1020,'H'=>1.1045,'L'=>1.1020,'C'=>1.1040], // C2 bullish; range=0.0025
        ['O'=>1.1030,'H'=>1.1085,'L'=>1.1030,'C'=>1.1055], // C3 bullish; range=0.0055; midpoint=(1.1085+1.1030)/2=1.10575
        ['O'=>1.1050,'H'=>1.1053,'L'=>1.1040,'C'=>1.1042], // C4 bearish; H=1.1053 < 1.10575 → WEAK
        ['O'=>1.1042,'H'=>1.1044,'L'=>1.1030,'C'=>1.1032], // C5 bearish
    ];
    $newsWithEvent = [['impact' => 2, 'time' => $fakeTs + 120]]; // 2 min after signal
    $r3 = calculateTarget($c3t, 'UP', $fakeTs, $newsWithEvent);
    $checkStr("T3 valid",     $r3['valid'] ? 'true' : 'false', 'true');
    $checkStr("T3 pullback",  $r3['pullback_strength'], 'WEAK');
    $checkStr("T3 news",      $r3['news_found'] ? 'true' : 'false', 'true');
    $checkStr("T3 prominent", $r3['wick_prominent'] ? 'true' : 'false', 'true');
    $check("T3 final_target", $r3['final_target'], $c3t[2]['H']); // C3 high
    $checkStr("T3 reason",    $r3['target_reason'], 'WEAK_PULLBACK_NEWS_PROMINENT_C3_WICK');

    // --- T4: Weak SELL (UP) + news + non-prominent upper wick → target = max(C2_H, C3_H) ---
    echo "\nT4: Weak SELL (UP) + news + C3 upper wick <=15% avg → target = max(C2_H, C3_H)\n";
    // avg_size = 0.00350 (same as T3); wick must be <= 0.000525
    // C3: O=1.1030, H=1.1056, L=1.1030, C=1.1055 → upper_wick=1.1056-1.1055=0.0001; 0.0001 < 0.000525 not prominent
    $c4t = [
        ['O'=>1.1000,'H'=>1.1025,'L'=>1.1000,'C'=>1.1020], // C1
        ['O'=>1.1020,'H'=>1.1070,'L'=>1.1020,'C'=>1.1040], // C2; H=1.1070
        ['O'=>1.1030,'H'=>1.1056,'L'=>1.1030,'C'=>1.1055], // C3; midpoint=(1.1056+1.1030)/2=1.1043; H=1.1056
        ['O'=>1.1040,'H'=>1.1042,'L'=>1.1030,'C'=>1.1032], // C4 bearish; H=1.1042 < 1.1043 → WEAK
        ['O'=>1.1032,'H'=>1.1033,'L'=>1.1020,'C'=>1.1022], // C5 bearish
    ];
    // avg = ((0.0025)+(0.0050)+(0.0026))/3 = 0.0101/3 ≈ 0.003367; wick=0.0001; 15%=0.000505; 0.0001 < 0.000505 ✓
    $r4 = calculateTarget($c4t, 'UP', $fakeTs, $newsWithEvent);
    $checkStr("T4 prominent",   $r4['wick_prominent'] ? 'true' : 'false', 'false');
    $check("T4 final_target",   $r4['final_target'], max($c4t[1]['H'], $c4t[2]['H']));
    $checkStr("T4 reason",      $r4['target_reason'], 'WEAK_PULLBACK_NEWS_NONPROMINENT_C2_C3_EXTREME');

    // --- T5: Strong BUY (DOWN) → target = C3 midpoint ---
    echo "\nT5: Strong BUY (DOWN) — C4/C5 LOW <= C3 midpoint → target = C3 midpoint\n";
    $c5t = [
        ['O'=>1.1050,'H'=>1.1055,'L'=>1.1020,'C'=>1.1025], // C1 bearish
        ['O'=>1.1025,'H'=>1.1030,'L'=>1.1000,'C'=>1.1005], // C2 bearish
        ['O'=>1.1005,'H'=>1.1010,'L'=>1.0970,'C'=>1.0975], // C3 bearish; midpoint=(1.1010+1.0970)/2=1.099
        ['O'=>1.0978,'H'=>1.0990,'L'=>1.0970,'C'=>1.0985], // C4 bullish; L=1.0970 <= 1.099? 1.097<1.099 → STRONG
        ['O'=>1.0985,'H'=>1.0995,'L'=>1.0982,'C'=>1.0992], // C5 bullish
    ];
    $r5 = calculateTarget($c5t, 'DOWN', $fakeTs, []);
    $checkStr("T5 valid",    $r5['valid'] ? 'true' : 'false', 'true');
    $checkStr("T5 pullback", $r5['pullback_strength'], 'STRONG');
    $check("T5 final_target",$r5['final_target'], ($c5t[2]['H'] + $c5t[2]['L']) / 2.0);
    $checkStr("T5 reason",   $r5['target_reason'], 'STRONG_PULLBACK_C3_MIDPOINT');

    // --- T6: Weak BUY (DOWN) — no news → target = C1 open ---
    echo "\nT6: Weak BUY (DOWN) — weak pullback, no news → target = C1 open\n";
    $c6t = [
        ['O'=>1.1050,'H'=>1.1055,'L'=>1.1020,'C'=>1.1025], // C1 bearish; C1 open=1.1050
        ['O'=>1.1025,'H'=>1.1030,'L'=>1.1000,'C'=>1.1005], // C2 bearish
        ['O'=>1.1005,'H'=>1.1010,'L'=>1.0960,'C'=>1.0965], // C3 bearish; midpoint=(1.1010+1.0960)/2=1.0985
        ['O'=>1.0968,'H'=>1.0975,'L'=>1.0965,'C'=>1.0972], // C4 bullish; L=1.0965 > 1.0985? NO, 1.0965 < 1.0985 → STRONG
        ['O'=>1.0972,'H'=>1.0980,'L'=>1.0970,'C'=>1.0978], // C5 bullish
    ];
    // Adjust to make WEAK: C4 L must be > 1.0985
    $c6t[2] = ['O'=>1.1005,'H'=>1.1008,'L'=>1.0980,'C'=>1.0985]; // midpoint=(1.1008+1.0980)/2=1.0994
    $c6t[3] = ['O'=>1.0988,'H'=>1.0995,'L'=>1.0988,'C'=>1.0992]; // C4 L=1.0988 > 1.0994? NO → 1.0988 < 1.0994 STRONG
    // Adjust again
    $c6t[2] = ['O'=>1.1005,'H'=>1.1006,'L'=>1.0990,'C'=>1.0995]; // midpoint=(1.1006+1.0990)/2=1.0998
    $c6t[3] = ['O'=>1.0998,'H'=>1.1002,'L'=>1.0998,'C'=>1.1000]; // C4 L=1.0998 >= 1.0998 → STRONG (L<=midpoint)
    // Make L strictly > midpoint
    $c6t[3] = ['O'=>1.1000,'H'=>1.1004,'L'=>1.1000,'C'=>1.1002]; // C4 L=1.1000 > 1.0998 → WEAK
    $c6t[4] = ['O'=>1.1002,'H'=>1.1006,'L'=>1.1002,'C'=>1.1004]; // C5 L=1.1002 > 1.0998 → WEAK
    $r6 = calculateTarget($c6t, 'DOWN', $fakeTs, []);
    $checkStr("T6 pullback",  $r6['pullback_strength'], 'WEAK');
    $checkStr("T6 news",      $r6['news_found'] ? 'true' : 'false', 'false');
    $check("T6 final_target", $r6['final_target'], $c6t[0]['O']); // C1 open
    $checkStr("T6 reason",    $r6['target_reason'], 'WEAK_PULLBACK_C1_OPEN');

    // --- T7: Weak BUY (DOWN) + news + prominent lower wick → target = C3 low ---
    echo "\nT7: Weak BUY (DOWN) + news + C3 lower wick >15% avg → target = C3 low\n";
    $c7t = [
        ['O'=>1.1050,'H'=>1.1055,'L'=>1.1025,'C'=>1.1030], // C1 bearish; range=0.003
        ['O'=>1.1030,'H'=>1.1035,'L'=>1.1005,'C'=>1.1010], // C2 bearish; range=0.003
        ['O'=>1.1010,'H'=>1.1012,'L'=>1.0950,'C'=>1.1005], // C3 bearish; range=0.0062
        // midpoint=(1.1012+1.0950)/2=1.0981; lower_wick=min(1.1010,1.1005)-1.0950=1.1005-1.0950=0.0055
        // avg_size=((0.003)+(0.003)+(0.0062))/3=0.012/3=0.004; 15%=0.0006; 0.0055>0.0006 → prominent
        ['O'=>1.1006,'H'=>1.1010,'L'=>1.1004,'C'=>1.1008], // C4 bullish; L=1.1004 > 1.0981 → WEAK
        ['O'=>1.1008,'H'=>1.1012,'L'=>1.1006,'C'=>1.1010], // C5 bullish; L=1.1006 > 1.0981 → WEAK
    ];
    $r7 = calculateTarget($c7t, 'DOWN', $fakeTs, $newsWithEvent);
    $checkStr("T7 prominent",   $r7['wick_prominent'] ? 'true' : 'false', 'true');
    $check("T7 final_target",   $r7['final_target'], $c7t[2]['L']); // C3 low
    $checkStr("T7 reason",      $r7['target_reason'], 'WEAK_PULLBACK_NEWS_PROMINENT_C3_WICK');

    // --- T8: Weak BUY (DOWN) + news + non-prominent lower wick → target = min(C2_L, C3_L) ---
    echo "\nT8: Weak BUY (DOWN) + news + C3 lower wick <=15% avg → target = min(C2_L, C3_L)\n";
    $c8t = [
        ['O'=>1.1050,'H'=>1.1055,'L'=>1.1025,'C'=>1.1030], // C1; range=0.003
        ['O'=>1.1030,'H'=>1.1035,'L'=>1.0990,'C'=>1.1010], // C2; L=1.0990; range=0.0045
        ['O'=>1.1010,'H'=>1.1012,'L'=>1.1006,'C'=>1.1008], // C3; L=1.1006; range=0.0006
        // midpoint=(1.1012+1.1006)/2=1.1009; lower_wick=min(1.1010,1.1008)-1.1006=1.1008-1.1006=0.0002
        // avg_size=((0.003)+(0.0045)+(0.0006))/3=0.0081/3=0.0027; 15%=0.000405; 0.0002<0.000405 not prominent
        ['O'=>1.1010,'H'=>1.1015,'L'=>1.1010,'C'=>1.1013], // C4 bullish; L=1.1010 > 1.1009 → WEAK
        ['O'=>1.1013,'H'=>1.1018,'L'=>1.1013,'C'=>1.1016], // C5 bullish; L=1.1013 > 1.1009 → WEAK
    ];
    $r8 = calculateTarget($c8t, 'DOWN', $fakeTs, $newsWithEvent);
    $checkStr("T8 prominent",   $r8['wick_prominent'] ? 'true' : 'false', 'false');
    $check("T8 final_target",   $r8['final_target'], min($c8t[1]['L'], $c8t[2]['L']));
    $checkStr("T8 reason",      $r8['target_reason'], 'WEAK_PULLBACK_NEWS_NONPROMINENT_C2_C3_EXTREME');

    // --- T9: Doji C2 in SELL → invalid setup ---
    echo "\nT9: Doji C2 in SELL setup → SKIPPED_INVALID_C1C2C3_FOR_SELL\n";
    $c9t = [
        ['O'=>1.1000,'H'=>1.1020,'L'=>1.0995,'C'=>1.1015], // C1 bullish
        ['O'=>1.1015,'H'=>1.1030,'L'=>1.1010,'C'=>1.1015], // C2 doji (close==open)
        ['O'=>1.1015,'H'=>1.1040,'L'=>1.1010,'C'=>1.1035], // C3 bullish
        ['O'=>1.1030,'H'=>1.1035,'L'=>1.1020,'C'=>1.1022], // C4
        ['O'=>1.1022,'H'=>1.1025,'L'=>1.1010,'C'=>1.1012], // C5
    ];
    $r9 = calculateTarget($c9t, 'UP', $fakeTs, []);
    $checkStr("T9 valid",  $r9['valid'] ? 'true' : 'false', 'false');
    $checkStr("T9 reason", $r9['target_reason'], 'SKIPPED_INVALID_C1C2C3_FOR_SELL');

    // --- T10: News at exactly t+600 (exclusive end) — must NOT qualify ---
    echo "\nT10: News at signal+600s (boundary) → must NOT qualify (exclusive end)\n";
    $newsAtBoundary = [['impact' => 2, 'time' => $fakeTs + 600]]; // = newsWindowEnd → not < newsWindowEnd
    $r10 = calculateTarget($c2t, 'UP', $fakeTs, $newsAtBoundary);
    $checkStr("T10 news",      $r10['news_found'] ? 'true' : 'false', 'false');
    $checkStr("T10 reason",    $r10['target_reason'], 'WEAK_PULLBACK_C1_OPEN');

    // --- T11: Impact 1 news — must NOT qualify ---
    echo "\nT11: Impact-1 news → must NOT qualify\n";
    $newsImpact1 = [['impact' => 1, 'time' => $fakeTs + 60]];
    $r11 = calculateTarget($c2t, 'UP', $fakeTs, $newsImpact1);
    $checkStr("T11 news",   $r11['news_found'] ? 'true' : 'false', 'false');
    $checkStr("T11 reason", $r11['target_reason'], 'WEAK_PULLBACK_C1_OPEN');

    echo "\n====================================================\n";
    echo " Results: {$pass} passed, {$fail} failed\n";
    echo "====================================================\n";
    echo '</pre>';
    die();
}

// -------------------------------------------------------
// BOOTSTRAP trade_outcome_details — UNCHANGED
// -------------------------------------------------------
$conn->query("
    CREATE TABLE IF NOT EXISTS trade_outcome_details (
        raw_trade_id   INT(11)        NOT NULL,
        pair_name      VARCHAR(20)    DEFAULT NULL,
        trade_result   VARCHAR(20)    DEFAULT NULL,
        win_loss_time  DATETIME       DEFAULT NULL,
        win_loss_price DECIMAL(10,5)  DEFAULT NULL,
        created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (raw_trade_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1
");

// -------------------------------------------------------
// LOAD NEWS EVENTS — UPDATED
//
// Schema assumption (verified from existing WHERE clause):
//   economic_events.event_date  = DATE field
//   economic_events.event_time  = TIME (HH:MM:SS) or DATETIME string, stored/interpreted as IST
//
// If event_time is a TIME-only string (≤ 8 chars), combine with event_date.
// If event_time is a full DATETIME string, use directly.
// All news unix timestamps are derived from IST interpretation.
// -------------------------------------------------------
$newsEvents = [];
$newsRes = $conn->query(
    "SELECT impact, event_date, event_time
     FROM   economic_events
     WHERE  event_date >= '{$startDate}' AND event_date <= '{$endDate}'"
);
if ($newsRes) {
    while ($row = $newsRes->fetch_assoc()) {
        $rawTime = trim($row['event_time']);
        // Pure TIME string = ≤8 chars (HH:MM:SS or HH:MM); combine with event_date
        $timeStr = (strlen($rawTime) <= 8)
            ? $row['event_date'] . ' ' . $rawTime
            : $rawTime; // full DATETIME string

        try {
            $dt = new DateTime($timeStr, $IST);
            $newsEvents[] = ['impact' => (int)$row['impact'], 'time' => $dt->getTimestamp()];
        } catch (Exception $e) {
            // skip malformed rows silently
        }
    }
    $newsRes->free();
}

// -------------------------------------------------------
// UPSERT — UNCHANGED
// NOTE: price_target is intentionally NOT updated here.
//       Stored target is kept for audit; only trade_result
//       and win_loss details are written.
// -------------------------------------------------------
$saveStmt = $conn->prepare("
    INSERT INTO trade_outcome_details (raw_trade_id, pair_name, trade_result, win_loss_time, win_loss_price)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        trade_result   = VALUES(trade_result),
        win_loss_time  = VALUES(win_loss_time),
        win_loss_price = VALUES(win_loss_price)
");

$resultFilter = $forceReval
    ? ""
    : "AND (p.trade_result = 'pending' OR p.trade_result IS NULL OR p.trade_result = 'recovered')";

// -------------------------------------------------------
// OUTPUT HEADER
// -------------------------------------------------------
echo '<pre>';
echo "====================================================\n";
echo " HISTORICAL EVALUATOR v2: {$startDate} → {$endDate}\n";
echo " Batch size  : {$batchSize} | after_id: {$afterId}\n";
echo " Force reval : " . ($forceReval ? "YES" : "NO") . "\n";
echo " Debug mode  : " . ($debugMode  ? "YES (dry-run — NO DB writes)" : "NO") . "\n";
echo " News loaded : " . count($newsEvents) . " events\n";
echo "====================================================\n\n";

$counts = [
    'win'                   => 0,
    'loss'                  => 0,
    'setup_not_formed'      => 0,
    'pending'               => 0,
    'invalid_window'        => 0,   // win/loss but resolution outside valid IST session
    'skipped_time_window'   => 0,   // signal outside 12:30–21:30 IST (no DB write)
    'skipped_invalid_setup' => 0,   // C1/C2/C3 don't match direction (no DB write)
    'error'                 => 0,   // CSV missing or no candles in window
];
$processed = 0;
$lastId    = $afterId;
$batch     = 0;

// -------------------------------------------------------
// MAIN LOOP
// -------------------------------------------------------
do {
    // ---- SQL: add all 20 OHLC columns; alias stored target; keep created_at for audit ----
    $sql = "
        SELECT p.raw_trade_id,
               p.pair_name,
               p.price_target              AS stored_target,
               p.trade_direction,
               p.last_alert_time,
               UNIX_TIMESTAMP(r.created_at) AS created_at_unixtime,
               r.O1, r.H1, r.L1, r.C1,
               r.O2, r.H2, r.L2, r.C2,
               r.O3, r.H3, r.L3, r.C3,
               r.O4, r.H4, r.L4, r.C4,
               r.O5, r.H5, r.L5, r.C5
        FROM   prediction_trade_data p
        INNER JOIN raw_trade_data r ON p.raw_trade_id = r.id
        WHERE  p.last_alert_time >= '{$startDate} 00:00:00'
        AND    p.last_alert_time <= '{$endDate} 23:59:59'
        AND    p.raw_trade_id > {$lastId}
        {$resultFilter}
        ORDER BY p.raw_trade_id ASC
        LIMIT  {$batchSize}
    ";

    $result = $conn->query($sql);
    if (!$result) {
        echo "Query failed: " . $conn->error . "\n";
        break;
    }

    $fetched = $result->num_rows;
    if ($fetched === 0) break;

    $batch++;
    echo "--- Batch #{$batch} ({$fetched} records, cursor: {$lastId}) ---\n";

    while ($trade = $result->fetch_assoc()) {
        $pair          = $trade['pair_name'];
        $lastAlertTime = $trade['last_alert_time'];  // IST datetime string
        $direction     = $trade['trade_direction'];  // 'UP' or 'DOWN'
        $storedTarget  = (float)$trade['stored_target'];
        $lastId        = (int)$trade['raw_trade_id'];
        $createdAtUnix = (int)$trade['created_at_unixtime'];

        // ---- Signal timestamp — authoritative source: last_alert_time (IST) ----
        // last_alert_time was stored by recover_historical.php as:
        //   $candle['dt']->format('Y-m-d H:i:s')  where $candle['dt'] is in IST
        // So: new DateTime($lastAlertTime, $IST)->getTimestamp() gives the correct unix ts.
        $signalDt  = new DateTime($lastAlertTime, $IST);
        $signalTs  = $signalDt->getTimestamp();

        // Audit log: compare last_alert_time unix vs created_at unix (expect ≤ a few seconds diff)
        $tsDiff = abs($signalTs - $createdAtUnix);
        if ($debugMode && $tsDiff > 5) {
            echo "  ⚠ TS_DIFF [{$pair}] #{$lastId}: "
               . "last_alert_time-based={$signalTs}  created_at-based={$createdAtUnix}  diff={$tsDiff}s\n"
               . "    Using last_alert_time as authoritative signal timestamp.\n";
        }

        // ---- IST date of signal ----
        $signalDateIST = $signalDt->format('Y-m-d');

        // ----------------------------------------------------------------
        // GATE 1: Signal time validation — 12:30:00–21:30:00 IST (inclusive)
        // Skipped signals: no DB write, not counted in win/loss stats.
        // ----------------------------------------------------------------
        $sigSecs = istSecondsOfDay($signalDt);
        if ($sigSecs < SESSION_START_SECS || $sigSecs > SESSION_END_SECS) {
            $hhmiss = $signalDt->format('H:i:s');
            echo "  SKIP_TIME [{$pair}] #{$lastId} | {$lastAlertTime} → {$hhmiss} IST outside 12:30–21:30\n";
            $counts['skipped_time_window']++;
            $processed++;
            continue; // no DB write
        }

        // ----------------------------------------------------------------
        // Build 5-candle setup array from raw_trade_data OHLC columns.
        // Index 0 = C1 (oldest, candle i-4), Index 4 = C5 (alert candle i).
        // This matches the ordering in recover_historical.php:
        //   for ($j = 4; $j >= 0; $j--) { ohlc[] = allCandles[$i - $j]; }
        // ----------------------------------------------------------------
        $setupCandles = [
            ['O' => (float)$trade['O1'], 'H' => (float)$trade['H1'], 'L' => (float)$trade['L1'], 'C' => (float)$trade['C1']],
            ['O' => (float)$trade['O2'], 'H' => (float)$trade['H2'], 'L' => (float)$trade['L2'], 'C' => (float)$trade['C2']],
            ['O' => (float)$trade['O3'], 'H' => (float)$trade['H3'], 'L' => (float)$trade['L3'], 'C' => (float)$trade['C3']],
            ['O' => (float)$trade['O4'], 'H' => (float)$trade['H4'], 'L' => (float)$trade['L4'], 'C' => (float)$trade['C4']],
            ['O' => (float)$trade['O5'], 'H' => (float)$trade['H5'], 'L' => (float)$trade['L5'], 'C' => (float)$trade['C5']],
        ];

        // ----------------------------------------------------------------
        // Recalculate target — full spec decision tree.
        // NEVER uses stored prediction_trade_data.price_target for evaluation.
        // ----------------------------------------------------------------
        $tMeta = calculateTarget($setupCandles, $direction, $signalTs, $newsEvents);

        // ---- GATE 2: Setup validity ----
        if (!$tMeta['valid']) {
            echo "  SKIP_SETUP [{$pair}] #{$lastId} | {$lastAlertTime} | {$tMeta['target_reason']}\n";
            $counts['skipped_invalid_setup']++;
            $processed++;
            continue; // no DB write
        }

        $recalcTarget = (float)$tMeta['final_target'];

        // ---- Session end (21:30:00 IST on the signal's IST date) ----
        // Built from signal's IST date to avoid UTC-boundary errors (§18).
        $sessionEndDt = new DateTime($signalDateIST . ' 21:30:00', $IST);
        $sessionEndTs = $sessionEndDt->getTimestamp();

        // ---- Load CSV candles ----
        $csvPath = getHistoricalCsvPath($pair, $lastAlertTime);

        if (!file_exists($csvPath)) {
            echo "  ❌ [{$pair}] #{$lastId} — CSV not found: {$csvPath}\n";
            $counts['error']++;
            $processed++;
            continue;
        }

        $candles = fetchHistoricalCandles($csvPath, $signalTs, $sessionEndTs);

        if (empty($candles)) {
            echo "  ❌ [{$pair}] #{$lastId} — no candles in window\n";
            $counts['error']++;
            $processed++;
            continue;
        }

        // ---- Evaluate with RECALCULATED target ----
        $eval = evaluatePatternTrade(
            $candles,
            $direction,
            $recalcTarget,
            $signalTs,
            $sessionEndTs,
            $newsEvents
        );

        $resType        = $eval['result'];
        $winLossTimeIST = null;
        $winLossPrice   = null;

        // ----------------------------------------------------------------
        // GATE 3: Same-day resolution validation (§17, §18, §19)
        // A win or loss is only valid if:
        //   (a) resolution IST date == signal IST date, AND
        //   (b) resolution unix timestamp <= sessionEndTs (21:30 IST same day)
        // If either fails → mark 'invalid_window'; do NOT record as win/loss.
        // ----------------------------------------------------------------
        if (($resType === 'win' || $resType === 'loss') && isset($eval['time'])) {
            $resDt      = (new DateTime('@' . $eval['time']))->setTimezone($IST);
            $resDateIST = $resDt->format('Y-m-d');
            $resTs      = $resDt->getTimestamp();

            if ($resDateIST !== $signalDateIST) {
                echo "  ⚠ INVALID_WIN [{$pair}] #{$lastId}: resolution on {$resDateIST}"
                   . " (signal was {$signalDateIST}) → invalid_window\n";
                $resType = 'invalid_window';
            } elseif ($resTs > $sessionEndTs) {
                echo "  ⚠ INVALID_WIN [{$pair}] #{$lastId}: resolution at "
                   . $resDt->format('H:i:s') . " IST > 21:30:00 → invalid_window\n";
                $resType = 'invalid_window';
            } else {
                $winLossTimeIST = $resDt->format('Y-m-d H:i:s');
                $winLossPrice   = (float)$eval['price'];
            }
        }

        // ----------------------------------------------------------------
        // DEBUG output (dry-run; no DB writes when $debugMode = true)
        // ----------------------------------------------------------------
        if ($debugMode) {
            $sc = $setupCandles;
            echo "\n  ══ DEBUG #{$lastId} {$pair} ══════════════════════════════════\n";
            echo "  Signal IST       : {$lastAlertTime}\n";
            echo "  Signal Unix      : {$signalTs}  (source: last_alert_time)\n";
            echo "  created_at Unix  : {$createdAtUnix}  (diff: {$tsDiff}s)\n";
            echo "  Signal date IST  : {$signalDateIST}\n";
            echo "  Direction        : {$direction}\n";
            printf("  C1 O/H/L/C      : %.5f / %.5f / %.5f / %.5f\n",
                $sc[0]['O'], $sc[0]['H'], $sc[0]['L'], $sc[0]['C']);
            printf("  C2 O/H/L/C      : %.5f / %.5f / %.5f / %.5f\n",
                $sc[1]['O'], $sc[1]['H'], $sc[1]['L'], $sc[1]['C']);
            printf("  C3 O/H/L/C      : %.5f / %.5f / %.5f / %.5f\n",
                $sc[2]['O'], $sc[2]['H'], $sc[2]['L'], $sc[2]['C']);
            printf("  C4 O/H/L/C      : %.5f / %.5f / %.5f / %.5f\n",
                $sc[3]['O'], $sc[3]['H'], $sc[3]['L'], $sc[3]['C']);
            printf("  C5 O/H/L/C      : %.5f / %.5f / %.5f / %.5f\n",
                $sc[4]['O'], $sc[4]['H'], $sc[4]['L'], $sc[4]['C']);
            printf("  C3 midpoint     : %.5f\n", $tMeta['c3_midpoint']);
            echo "  Pullback        : {$tMeta['pullback_strength']}\n";
            printf("  Stored target   : %.5f\n", $storedTarget);
            printf("  Recalc target   : %.5f  (diff: %.5f)\n",
                $recalcTarget, abs($recalcTarget - $storedTarget));
            echo "  Target reason   : {$tMeta['target_reason']}\n";

            if ($tMeta['news_found']) {
                echo "  News (qualifying): Impact {$tMeta['news_impact']} @ {$tMeta['news_time_ist']} IST\n";
            } else {
                echo "  News (qualifying): none within 10min\n";
            }
            if ($tMeta['avg_size'] !== null) {
                printf("  Avg candle range: %.5f\n", $tMeta['avg_size']);
                printf("  C3 wick size    : %.5f\n", $tMeta['wick_size']);
                printf("  Wick 15%% thresh : %.5f\n", $tMeta['avg_size'] * 0.15);
                echo "  Wick prominent  : " . ($tMeta['wick_prominent'] ? 'YES' : 'NO') . "\n";
            }
            echo "  Outcome         : " . strtoupper($resType);
            if ($winLossTimeIST) echo " @ {$winLossTimeIST} IST";
            echo "\n";
            echo "  Eval reason     : {$eval['reason']}\n";
            echo "  (DRY-RUN: no DB writes)\n";
            echo "  ═══════════════════════════════════════════════════════\n\n";
        }

        // ----------------------------------------------------------------
        // DATABASE WRITES — skipped entirely in debug mode.
        // IMPORTANT: prediction_trade_data.price_target is NOT updated.
        // Only trade_result (prediction_trade_data) and
        // trade_outcome_details are written.
        // ----------------------------------------------------------------
        if (!$debugMode) {
            $saveStmt->bind_param('isssd', $lastId, $pair, $resType, $winLossTimeIST, $winLossPrice);
            $saveStmt->execute();

            $conn->query(
                "UPDATE prediction_trade_data
                 SET    trade_result = '{$resType}', updated_at = NOW()
                 WHERE  raw_trade_id = {$lastId}"
            );
        }

        $counts[$resType] = ($counts[$resType] ?? 0) + 1;
        $processed++;

        // One-line summary per trade (always shown, even in debug mode)
        $tgtDiff = number_format(abs($recalcTarget - $storedTarget), 5);
        echo "  {$pair} | #{$lastId} | {$direction} | {$lastAlertTime}"
           . " | {$tMeta['pullback_strength']}"
           . " | {$storedTarget}→{$recalcTarget} (Δ{$tgtDiff})"
           . " | {$tMeta['target_reason']}"
           . " → " . strtoupper($resType)
           . ($winLossTimeIST ? " @ {$winLossTimeIST}" : '')
           . " | {$eval['reason']}\n";

        unset($candles);

        if ($processed % 50 === 0) {
            if (ob_get_level()) ob_flush();
            flush();
        }
    }

    $result->free();

} while ($fetched === $batchSize);

$saveStmt->close();
$conn->close();

echo "\n====================================================\n";
echo " COMPLETE — {$processed} records | {$batch} batch(es)\n";
echo "----------------------------------------------------\n";
echo " Win                  : {$counts['win']}\n";
echo " Loss                 : {$counts['loss']}\n";
echo " Setup not formed     : {$counts['setup_not_formed']}\n";
echo " Pending              : " . ($counts['pending'] ?? 0) . "\n";
echo " Invalid res. window  : {$counts['invalid_window']}\n";
echo " Skipped (time gate)  : {$counts['skipped_time_window']}\n";
echo " Skipped (bad setup)  : {$counts['skipped_invalid_setup']}\n";
echo " Errors (no CSV)      : {$counts['error']}\n";
echo "====================================================\n";
echo "\n✅ All done. Last processed raw_trade_id: {$lastId}\n";
if ($debugMode) {
    echo "\n⚠  DEBUG MODE: nothing was written to the database.\n";
}
echo '</pre>';
?>
