<?php
/**
 * ADVANCED ANALYSIS — Combined Report
 * ─────────────────────────────────────────────────────────────
 * Section 1 : Trade Duration & Time-of-Day
 * Section 2 : Re-entry Frequency vs Win Rate
 * Section 3 : Candle Body Ratio — Streak vs Confirmation
 * Section 4 : Rolling 30-Day Win Rate Trend
 * Section 5 : Candle Count Analysis  ← NEW
 * ─────────────────────────────────────────────────────────────
 * STANDALONE — only requires db.php. Never require chart_generator.php,
 * bootstrap.php, or anything from plot/trail/ (those need globals/GD).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(600);

// Root public_html/db.php — provides shared configuration and $conn
require_once __DIR__ . '/../db.php';

// ═══════════════════════════════════════════════════════════════
// INLINE getCsvPath — never require chart_generator.php
// ═══════════════════════════════════════════════════════════════
if (!function_exists('getCsvPath')) {
    function getCsvPath(string $pairName, string $alertTimeStr): string {
        $cleanPair  = str_replace(['/', '_', ' '], '', $pairName);
        $fileName   = "FX_" . $cleanPair . ".csv";
        $timestamp  = strtotime($alertTimeStr);
        $cutoffEnd  = strtotime('2026-03-01 00:00:00');
        $baseDir    = __DIR__;
        $datasetDir = '';
        if (is_dir($baseDir . '/dataset'))        { $datasetDir = $baseDir . '/dataset'; }
        elseif (is_dir($baseDir . '/../dataset')) { $datasetDir = $baseDir . '/../dataset'; }
        else {
            $dir = $baseDir;
            for ($i = 0; $i < 4; $i++) {
                $dir = dirname($dir);
                if (is_dir($dir . '/dataset')) { $datasetDir = $dir . '/dataset'; break; }
            }
            if (!$datasetDir) $datasetDir = $baseDir . '/../dataset';
        }
        $path1 = $datasetDir . "/JUN-2025 TO FEB-2026/" . $fileName;
        $path2 = $datasetDir . "/dataset/" . $fileName;
        $path3 = $datasetDir . "/" . $fileName;
        if ($timestamp < $cutoffEnd) {
            if (file_exists($path1)) return $path1;
        } else {
            if (file_exists($path2)) return $path2;
        }
        if (file_exists($path1)) return $path1;
        if (file_exists($path2)) return $path2;
        return $path3;
    }
}

// ═══════════════════════════════════════════════════════════════
// PARAMETERS
// ═══════════════════════════════════════════════════════════════
$IST_OFFSET_MIN  = 330;
$REENTRY_WINDOWS = array(
    '<30 min'    => array(0,   30),
    '30-60 min'  => array(30,  60),
    '60-120 min' => array(60,  120),
    '>120 min'   => array(120, PHP_INT_MAX),
);
$ROLLING_DAYS    = 30;
$ROLLING_STEP    = 7;

// ═══════════════════════════════════════════════════════════════
// SHARED HELPERS
// ═══════════════════════════════════════════════════════════════
function wrPct($w, $n) {
    return $n > 0 ? round($w / $n * 100, 1) . '%' : '—';
}

function wrColor($wr) {
    if ($wr === null) return '#8b949e';
    if ($wr >= 80) return '#10b981';
    if ($wr >= 70) return '#3b82f6';
    if ($wr >= 60) return '#f59e0b';
    return '#ef4444';
}

function wrPill($w, $n) {
    if ($n <= 0) return '<span style="color:var(--muted)">—</span>';
    $pct = round($w / $n * 100, 1);
    $col = wrColor($pct);
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;background:' . $col . '22;color:' . $col . ';border:1px solid ' . $col . '44">' . $pct . '%</span>';
}

function wilsonCI($w, $n) {
    if ($n < 5) return '<small style="color:var(--muted)">n&lt;5</small>';
    $p     = $w / $n;
    $z     = 1.96;
    $denom = 1 + $z * $z / $n;
    $c     = ($p + $z * $z / (2 * $n)) / $denom;
    $m     = ($z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n))) / $denom;
    return sprintf('<span style="color:var(--muted);font-size:11px">[%.1f%%, %.1f%%]</span>',
                   ($c - $m) * 100, ($c + $m) * 100);
}

function diffBadge($wr, $baseline) {
    if ($baseline === null) return '—';
    $d   = ($wr - $baseline) * 100;
    $cls = $d >= 0 ? 'pos' : 'neg';
    return "<span class='{$cls}'>" . sprintf('%+.1f pp', $d) . "</span>";
}

function utcToIst($utcDatetime, $offsetMin) {
    $ts = strtotime($utcDatetime) + $offsetMin * 60;
    return date('H:i', $ts);
}

function slot30(string $hhmm): string {
    list($h, $m) = explode(':', $hhmm);
    $h  = (int)$h;
    $m  = (int)$m;
    $ms = $m < 30 ? '00' : '30';
    $me = $m < 30 ? '30' : '00';
    $he = $m < 30 ? $h : $h + 1;
    return sprintf('%02d:%s–%02d:%s', $h, $ms, $he, $me);
}

function candlesToMin($cc) {
    return $cc !== null ? ($cc * 5) . ' min' : '—';
}

function candleChip($cc) {
    if ($cc === null) return '<span style="color:var(--muted)">—</span>';
    return '<span style="display:inline-block;padding:1px 7px;border-radius:4px;border:1px solid var(--cyan);color:var(--cyan);background:rgba(34,211,238,0.08);font-family:monospace;font-size:12px">' . $cc . 'c</span>';
}

function timeChip($min) {
    if ($min === null) return '<span style="color:var(--muted)">—</span>';
    return '<span style="display:inline-block;padding:1px 7px;border-radius:4px;background:var(--bg3);color:var(--text);font-family:monospace;font-size:12px">' . $min . ' min</span>';
}

function resultTag($result) {
    if ($result === 'win')
        return '<span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#10b98122;color:#10b981;border:1px solid #10b98144">WIN</span>';
    return '<span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#ef444422;color:#ef4444;border:1px solid #ef444444">LOSS</span>';
}

// ═══════════════════════════════════════════════════════════════
// SECTION 1 — TRADE DURATION
// ═══════════════════════════════════════════════════════════════
$s1sql = "SELECT p.raw_trade_id AS id,
                 p.pair_name,
                 p.last_alert_time,
                 p.trade_result,
                 t.win_loss_time,
                 TIMESTAMPDIFF(MINUTE, p.last_alert_time, t.win_loss_time) AS duration_min
          FROM (
              SELECT p0.* FROM prediction_trade_data p0
              INNER JOIN (
                  SELECT pair_name, last_alert_time, MIN(raw_trade_id) AS keep_id
                  FROM prediction_trade_data
                  WHERE trade_result IN ('win','loss')
                  GROUP BY pair_name, last_alert_time
              ) d0 ON p0.raw_trade_id = d0.keep_id
              WHERE p0.trade_result IN ('win','loss')
          ) p
          JOIN trade_outcome_details t ON p.raw_trade_id = t.raw_trade_id
          WHERE p.trade_result IN ('win','loss')
            AND t.win_loss_time IS NOT NULL
            AND t.win_loss_time > p.last_alert_time
          ORDER BY p.last_alert_time DESC";
$s1res = $conn->query($s1sql);
if (!$s1res) die("<b>Section 1 query failed:</b> " . $conn->error);
$s1rows = array();
while ($row = $s1res->fetch_assoc()) $s1rows[] = $row;
$s1res->free();

$durByOutcome = array(
    'win'  => array('total' => 0, 'count' => 0),
    'loss' => array('total' => 0, 'count' => 0),
);
$durBins = array(
    '<5 min'    => array('w' => 0, 'l' => 0),
    '5-15 min'  => array('w' => 0, 'l' => 0),
    '15-30 min' => array('w' => 0, 'l' => 0),
    '30-60 min' => array('w' => 0, 'l' => 0),
    '>60 min'   => array('w' => 0, 'l' => 0),
);
$slotDur = array();
$pairDur = array();

foreach ($s1rows as $r) {
    $dur   = (int)$r['duration_min'];
    $isWin = ($r['trade_result'] === 'win');
    $pair  = $r['pair_name'];
    $durByOutcome[$r['trade_result']]['total'] += $dur;
    $durByOutcome[$r['trade_result']]['count']++;
    if      ($dur < 5)  $bin = '<5 min';
    elseif  ($dur < 15) $bin = '5-15 min';
    elseif  ($dur < 30) $bin = '15-30 min';
    elseif  ($dur < 60) $bin = '30-60 min';
    else                $bin = '>60 min';
    if ($isWin) $durBins[$bin]['w']++; else $durBins[$bin]['l']++;
    $ist  = utcToIst($r['last_alert_time'], $IST_OFFSET_MIN);
    $slot = slot30($ist);
    if (!isset($slotDur[$slot]))
        $slotDur[$slot] = array('win_total'=>0,'win_n'=>0,'loss_total'=>0,'loss_n'=>0,'all_total'=>0,'all_n'=>0);
    $slotDur[$slot]['all_total'] += $dur;
    $slotDur[$slot]['all_n']++;
    if ($isWin) { $slotDur[$slot]['win_total']  += $dur; $slotDur[$slot]['win_n']++;  }
    else        { $slotDur[$slot]['loss_total'] += $dur; $slotDur[$slot]['loss_n']++; }
    if (!isset($pairDur[$pair]))
        $pairDur[$pair] = array('win_total'=>0,'win_n'=>0,'loss_total'=>0,'loss_n'=>0);
    if ($isWin) { $pairDur[$pair]['win_total']  += $dur; $pairDur[$pair]['win_n']++;  }
    else        { $pairDur[$pair]['loss_total'] += $dur; $pairDur[$pair]['loss_n']++; }
}
uksort($slotDur, function($a, $b) { return strcmp(substr($a,0,5), substr($b,0,5)); });
ksort($pairDur);

// ═══════════════════════════════════════════════════════════════
// SECTION 2 — RE-ENTRY FREQUENCY
// ═══════════════════════════════════════════════════════════════
$s2sql = "SELECT raw_trade_id AS id, pair_name, last_alert_time, trade_result
          FROM prediction_trade_data
          WHERE trade_result IN ('win','loss')
          ORDER BY last_alert_time DESC";
$s2res = $conn->query($s2sql);
if (!$s2res) die("<b>Section 2 query failed:</b> " . $conn->error);
$s2rows = array();
while ($row = $s2res->fetch_assoc()) $s2rows[] = $row;
$s2res->free();

$reentryBinData = array();
foreach (array_keys($REENTRY_WINDOWS) as $lbl) $reentryBinData[$lbl] = array('w'=>0,'l'=>0);
$reentryBinData['Fresh (first ever)'] = array('w'=>0,'l'=>0);

$lastPairTime = array();
$s2detail     = array();
foreach ($s2rows as $r) {
    $pair  = $r['pair_name'];
    $ts    = strtotime($r['last_alert_time']);
    $isWin = ($r['trade_result'] === 'win');
    $minsSince = null;
    if (isset($lastPairTime[$pair])) $minsSince = (int)round(($ts - $lastPairTime[$pair]) / 60);
    $lastPairTime[$pair] = $ts;
    if ($minsSince === null) { $bin = 'Fresh (first ever)'; }
    else {
        $bin = '>120 min';
        foreach ($REENTRY_WINDOWS as $lbl => $range) {
            if ($minsSince >= $range[0] && $minsSince < $range[1]) { $bin = $lbl; break; }
        }
    }
    if ($isWin) $reentryBinData[$bin]['w']++; else $reentryBinData[$bin]['l']++;
    $s2detail[] = array('id'=>$r['id'],'pair'=>$pair,'time'=>$r['last_alert_time'],
                        'mins_since'=>$minsSince,'bin'=>$bin,'outcome'=>$isWin?'WIN':'LOSS');
}

$pairReentry = array();
foreach ($s2detail as $r) {
    $pair  = $r['pair'];
    $fresh = ($r['bin'] === 'Fresh (first ever)' || $r['mins_since'] === null || $r['mins_since'] > 120);
    $type  = $fresh ? 'fresh' : 'reentry';
    if (!isset($pairReentry[$pair]))
        $pairReentry[$pair] = array('fresh'=>array('w'=>0,'l'=>0),'reentry'=>array('w'=>0,'l'=>0));
    if ($r['outcome'] === 'WIN') $pairReentry[$pair][$type]['w']++;
    else                         $pairReentry[$pair][$type]['l']++;
}
ksort($pairReentry);

$freshW = 0; $freshL = 0;
foreach (array('>120 min','Fresh (first ever)') as $bl) {
    if (isset($reentryBinData[$bl])) { $freshW += $reentryBinData[$bl]['w']; $freshL += $reentryBinData[$bl]['l']; }
}
$freshN  = $freshW + $freshL;
$freshWR = $freshN > 0 ? $freshW / $freshN : null;

// ═══════════════════════════════════════════════════════════════
// SECTION 3 — CANDLE BODY RATIO
// ═══════════════════════════════════════════════════════════════
$s3sql = "SELECT p.raw_trade_id AS id, p.pair_name, p.trade_result, p.trade_direction,
                 r.O1, r.C1, r.O2, r.C2, r.O3, r.C3, r.O4, r.C4, r.O5, r.C5,
                 r.H1, r.L1, r.H2, r.L2, r.H3, r.L3
          FROM (
              SELECT p0.* FROM prediction_trade_data p0
              INNER JOIN (
                  SELECT pair_name, last_alert_time, MIN(raw_trade_id) AS keep_id
                  FROM prediction_trade_data
                  WHERE trade_result IN ('win','loss')
                  GROUP BY pair_name, last_alert_time
              ) d0 ON p0.raw_trade_id = d0.keep_id
              WHERE p0.trade_result IN ('win','loss')
          ) p
          JOIN raw_trade_data r ON p.raw_trade_id = r.id
          WHERE p.trade_result IN ('win','loss')
          ORDER BY p.last_alert_time DESC";
$s3res = $conn->query($s3sql);
if (!$s3res) die("<b>Section 3 query failed:</b> " . $conn->error);
$s3rows = array();
while ($row = $s3res->fetch_assoc()) $s3rows[] = $row;
$s3res->free();

$ratioBins = array(
    'Small (<0.8)'    => array('w'=>0,'l'=>0,'ratios'=>array()),
    'Equal (0.8-1.2)' => array('w'=>0,'l'=>0,'ratios'=>array()),
    'Large (>1.2)'    => array('w'=>0,'l'=>0,'ratios'=>array()),
);
$byPairRatio = array();
$s3detail    = array();

foreach ($s3rows as $r) {
    $isWin = ($r['trade_result'] === 'win');
    $pair  = $r['pair_name'];
    $confBody  = abs((float)$r['C1'] - (float)$r['O1']);
    $streak3   = abs((float)$r['C3'] - (float)$r['O3']);
    $streak4   = abs((float)$r['C4'] - (float)$r['O4']);
    $streak5   = abs((float)$r['C5'] - (float)$r['O5']);
    $streakAvg = ($streak3 + $streak4 + $streak5) / 3;
    if ($streakAvg <= 0) continue;
    $ratio = $confBody / $streakAvg;
    if      ($ratio < 0.8)  $bin = 'Small (<0.8)';
    elseif  ($ratio <= 1.2) $bin = 'Equal (0.8-1.2)';
    else                    $bin = 'Large (>1.2)';
    if ($isWin) $ratioBins[$bin]['w']++; else $ratioBins[$bin]['l']++;
    $ratioBins[$bin]['ratios'][] = $ratio;
    if (!isset($byPairRatio[$pair]))
        $byPairRatio[$pair] = array('Small (<0.8)'=>array('w'=>0,'l'=>0),
                                    'Equal (0.8-1.2)'=>array('w'=>0,'l'=>0),
                                    'Large (>1.2)'=>array('w'=>0,'l'=>0));
    if ($isWin) $byPairRatio[$pair][$bin]['w']++; else $byPairRatio[$pair][$bin]['l']++;
    $pullBody  = abs((float)$r['C2'] - (float)$r['O2']);
    $pullRatio = $streakAvg > 0 ? round($pullBody / $streakAvg, 3) : 0;
    $s3detail[] = array('id'=>$r['id'],'pair'=>$pair,'dir'=>$r['trade_direction'],
                        'conf_body'=>round($confBody,5),'streak_avg'=>round($streakAvg,5),
                        'ratio'=>round($ratio,3),'pull_ratio'=>$pullRatio,
                        'bin'=>$bin,'outcome'=>$isWin?'WIN':'LOSS');
}
ksort($byPairRatio);
$eqBin = $ratioBins['Equal (0.8-1.2)'];
$eqN   = $eqBin['w'] + $eqBin['l'];
$eqWR  = $eqN > 0 ? $eqBin['w'] / $eqN : null;

// ═══════════════════════════════════════════════════════════════
// SECTION 4 — ROLLING 30-DAY WIN RATE
// ═══════════════════════════════════════════════════════════════
$s4rows      = $s2rows;
$rollingData = array();
$windowDays  = $ROLLING_DAYS * 86400;

// Pre-compute timestamps to avoid O(n²) strtotime() in inner loop
$s4timestamps = array();
foreach ($s4rows as $r4) $s4timestamps[] = strtotime($r4['last_alert_time']);

foreach ($s4rows as $i => $r) {
    if ($i % $ROLLING_STEP !== 0) continue;
    $anchorTs = $s4timestamps[$i];
    $winStart = $anchorTs - $windowDays;
    $w = 0; $l = 0;
    foreach ($s4rows as $j => $r2) {
        $ts2 = $s4timestamps[$j];
        if ($ts2 < $winStart || $ts2 > $anchorTs) continue;
        if ($r2['trade_result'] === 'win') $w++; else $l++;
    }
    $n = $w + $l;
    if ($n < 10) continue;
    $rollingData[] = array('date'=>substr($r['last_alert_time'],0,10),'trade'=>$i+1,
                           'w'=>$w,'l'=>$l,'n'=>$n,'wr'=>round($w/$n*100,1));
}

$overallW = 0; $overallL = 0;
foreach ($s4rows as $r) { if ($r['trade_result']==='win') $overallW++; else $overallL++; }
$overallN  = $overallW + $overallL;
$overallWR = $overallN > 0 ? round($overallW/$overallN*100,1) : 0;
$rollingWRs   = array_column($rollingData,'wr');
$minRolling   = !empty($rollingWRs) ? min($rollingWRs) : 0;
$maxRolling   = !empty($rollingWRs) ? max($rollingWRs) : 0;
$rangeRolling = round($maxRolling - $minRolling, 1);

// ═══════════════════════════════════════════════════════════════
// SECTION 5 — CANDLE COUNT ANALYSIS
// ═══════════════════════════════════════════════════════════════

// Main query: join prediction_trade_data + trade_outcome_details + raw_trade_data
$s5sql = "SELECT p.raw_trade_id AS id,
                 p.pair_name,
                 p.last_alert_time,
                 p.trade_result,
                 t.win_loss_time,
                 r.created_at AS raw_created_at,
                 UNIX_TIMESTAMP(r.created_at) AS alert_ts_raw
          FROM (
              SELECT p0.* FROM prediction_trade_data p0
              INNER JOIN (
                  SELECT pair_name, last_alert_time, MIN(raw_trade_id) AS keep_id
                  FROM prediction_trade_data
                  WHERE trade_result IN ('win','loss')
                  GROUP BY pair_name, last_alert_time
              ) d0 ON p0.raw_trade_id = d0.keep_id
              WHERE p0.trade_result IN ('win','loss')
          ) p
          JOIN trade_outcome_details t ON p.raw_trade_id = t.raw_trade_id
          JOIN raw_trade_data r ON p.raw_trade_id = r.id
          WHERE p.trade_result IN ('win','loss')
            AND t.win_loss_time IS NOT NULL
          ORDER BY p.last_alert_time ASC";

$s5res = $conn->query($s5sql);
if (!$s5res) die("<b>Section 5 query failed:</b> " . $conn->error);
$s5rows = array();
while ($row = $s5res->fetch_assoc()) $s5rows[] = $row;
$s5res->free();

// IST timezone for win_loss_time parsing
$istTz = new DateTimeZone('Asia/Kolkata');
$utcTz = new DateTimeZone('UTC');

// Candle count logic helper (inline, PHP 7.4 compatible)
// Each CSV candle = 5 minutes (300 seconds)
// Signal candle: candle where alertTs >= candle_time && alertTs < candle_time + 300
// Resolution candle: same logic with winLossTs
// Count = resolution_index - signal_index + 1
function calcCandleCount(string $csvPath, int $alertTs, int $winLossTs) {
    if (!file_exists($csvPath)) return null;
    $fh = fopen($csvPath, 'r');
    if (!$fh) return null;

    $signalIdx     = null;
    $resolutionIdx = null;
    $idx           = 0;
    $header        = null;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if ($header === null) {
            // first non-empty line: check if header or data
            if (!is_numeric($cols[0])) { $header = $cols; continue; }
        }
        // cols[0] = unix timestamp (or datetime string)
        $rawTs = $cols[0];
        $candleTs = is_numeric($rawTs) ? (int)$rawTs : strtotime($rawTs);
        if ($candleTs === false || $candleTs === 0) { $idx++; continue; }

        if ($signalIdx === null && $alertTs >= $candleTs && $alertTs < $candleTs + 300) {
            $signalIdx = $idx;
        }
        if ($winLossTs >= $candleTs && $winLossTs < $candleTs + 300) {
            $resolutionIdx = $idx;
        }

        // Both found — no need to keep reading IF resolution comes after signal
        if ($signalIdx !== null && $resolutionIdx !== null && $resolutionIdx >= $signalIdx) {
            break;
        }
        $idx++;
    }
    fclose($fh);

    if ($signalIdx === null || $resolutionIdx === null) return null;
    if ($resolutionIdx < $signalIdx) return null;
    return $resolutionIdx - $signalIdx + 1;
}

// Build per-trade candle count records
$s5trades    = array(); // full detail rows
$ccByCount   = array(); // [count => ['w'=>n,'l'=>n]]
$ccByPair    = array(); // [pair => ['win_sum'=>n,'win_n'=>n,'loss_sum'=>n,'loss_n'=>n]]
$ccBySlot    = array(); // [slot => ['w'=>n,'l'=>n,'win_sum'=>n,'loss_sum'=>n,...]]

$allCounts   = array();
$winCounts   = array();
$lossCounts  = array();
$resolvedN   = 0;

foreach ($s5rows as $r) {
    $pair   = $r['pair_name'];
    $isWin  = ($r['trade_result'] === 'win');
    $result = $r['trade_result'];

    // Alert timestamp: prefer raw_trade_data.created_at, fallback to last_alert_time
    $alertTs = (int)$r['alert_ts_raw'];
    if ($alertTs <= 0) $alertTs = strtotime($r['last_alert_time']);

    // win_loss_time is IST in trade_outcome_details
    $wltTs = null;
    try {
        $wltDt = new DateTime($r['win_loss_time'], $istTz);
        $wltTs = $wltDt->getTimestamp();
    } catch (Exception $e) {
        $wltTs = null;
    }

    if (!$wltTs || $wltTs <= $alertTs) {
        // Can't compute candle count
        $s5trades[] = array('id'=>$r['id'],'pair'=>$pair,'result'=>$result,
                            'alert_ist'=>null,'wlt_ist'=>$r['win_loss_time'],
                            'candle_count'=>null,'csv_found'=>false);
        continue;
    }

    $csvPath = getCsvPath($pair, $r['last_alert_time']);
    $cc      = calcCandleCount($csvPath, $alertTs, $wltTs);

    // Alert IST display
    $alertIst = date('d M H:i', $alertTs + $IST_OFFSET_MIN * 60);
    // win_loss IST from stored value directly
    $wltIst   = $r['win_loss_time'];

    $s5trades[] = array(
        'id'          => $r['id'],
        'pair'        => $pair,
        'result'      => $result,
        'alert_ist'   => $alertIst,
        'wlt_ist'     => $wltIst,
        'candle_count'=> $cc,
        'csv_found'   => file_exists($csvPath),
    );

    if ($cc !== null) {
        $resolvedN++;
        $allCounts[] = $cc;
        if ($isWin)  $winCounts[]  = $cc;
        else         $lossCounts[] = $cc;

        // By count
        if (!isset($ccByCount[$cc])) $ccByCount[$cc] = array('w'=>0,'l'=>0);
        if ($isWin) $ccByCount[$cc]['w']++; else $ccByCount[$cc]['l']++;

        // By pair
        if (!isset($ccByPair[$pair]))
            $ccByPair[$pair] = array('win_sum'=>0,'win_n'=>0,'loss_sum'=>0,'loss_n'=>0);
        if ($isWin) { $ccByPair[$pair]['win_sum']  += $cc; $ccByPair[$pair]['win_n']++;  }
        else        { $ccByPair[$pair]['loss_sum'] += $cc; $ccByPair[$pair]['loss_n']++; }

        // By IST slot
        $ist  = date('H:i', $alertTs + $IST_OFFSET_MIN * 60);
        $slot = slot30($ist);
        if (!isset($ccBySlot[$slot]))
            $ccBySlot[$slot] = array('w'=>0,'l'=>0,'win_sum'=>0,'loss_sum'=>0,'win_n'=>0,'loss_n'=>0,'all_sum'=>0,'all_n'=>0);
        if ($isWin) {
            $ccBySlot[$slot]['w']++;
            $ccBySlot[$slot]['win_sum']  += $cc;
            $ccBySlot[$slot]['win_n']++;
        } else {
            $ccBySlot[$slot]['l']++;
            $ccBySlot[$slot]['loss_sum'] += $cc;
            $ccBySlot[$slot]['loss_n']++;
        }
        $ccBySlot[$slot]['all_sum'] += $cc;
        $ccBySlot[$slot]['all_n']++;
    }
}

ksort($ccByCount);
ksort($ccByPair);
uksort($ccBySlot, function($a, $b) { return strcmp(substr($a,0,5), substr($b,0,5)); });

// KPI calculations
function safeAvg(array $arr) {
    return count($arr) > 0 ? round(array_sum($arr) / count($arr), 1) : null;
}
function safeMedian(array $arr) {
    if (empty($arr)) return null;
    sort($arr);
    $n = count($arr);
    $mid = (int)floor($n / 2);
    if ($n % 2 === 0) return round(($arr[$mid - 1] + $arr[$mid]) / 2, 1);
    return round($arr[$mid], 1);
}

$avgAll   = safeAvg($allCounts);
$avgWin   = safeAvg($winCounts);
$avgLoss  = safeAvg($lossCounts);
$medAll   = safeMedian($allCounts);
$minCount = !empty($allCounts) ? min($allCounts) : null;
$maxCount = !empty($allCounts) ? max($allCounts) : null;

// Build bucket data for Table 5a and Chart 2
$buckets = array(
    '1-3c'    => array('min'=>1,  'max'=>3,  'label'=>'Early (1-3c)',    'w'=>0,'l'=>0),
    '4-6c'    => array('min'=>4,  'max'=>6,  'label'=>'Sweet Spot (4-6c)','w'=>0,'l'=>0),
    '7-9c'    => array('min'=>7,  'max'=>9,  'label'=>'Mid (7-9c)',      'w'=>0,'l'=>0),
    '10-15c'  => array('min'=>10, 'max'=>15, 'label'=>'Extended (10-15c)','w'=>0,'l'=>0),
    '16-20c'  => array('min'=>16, 'max'=>20, 'label'=>'Long (16-20c)',   'w'=>0,'l'=>0),
    '>20c'    => array('min'=>21, 'max'=>9999,'label'=>'Very Long (>20c)','w'=>0,'l'=>0),
);
foreach ($allCounts as $idx => $cc2) {
    $isW2 = isset($winCounts[$idx]); // Not right — use s5trades approach
}
// Re-build buckets from ccByCount
foreach ($ccByCount as $cnt => $data) {
    foreach ($buckets as $key => &$bkt) {
        if ($cnt >= $bkt['min'] && $cnt <= $bkt['max']) {
            $bkt['w'] += $data['w'];
            $bkt['l'] += $data['l'];
            break;
        }
    }
    unset($bkt);
}

// Chart.js data arrays (candle count 4–30)
$chartLabels   = array();
$chartWins     = array();
$chartLosses   = array();
$chartWR       = array();
for ($cnt = 4; $cnt <= 30; $cnt++) {
    $chartLabels[] = $cnt;
    $w2 = isset($ccByCount[$cnt]) ? $ccByCount[$cnt]['w'] : 0;
    $l2 = isset($ccByCount[$cnt]) ? $ccByCount[$cnt]['l'] : 0;
    $n2 = $w2 + $l2;
    $chartWins[]   = $w2;
    $chartLosses[] = $l2;
    $chartWR[]     = $n2 > 0 ? round($w2/$n2*100,1) : null;
}

// Chart 2 bucket WR
$bucketLabels = array();
$bucketWRs    = array();
$bucketColors = array();
foreach ($buckets as $bkt) {
    $n2 = $bkt['w'] + $bkt['l'];
    $wr2 = $n2 > 0 ? round($bkt['w']/$n2*100,1) : 0;
    $bucketLabels[] = $bkt['label'];
    $bucketWRs[]    = $wr2;
    $col = '#10b981'; // green
    if ($wr2 < 70) $col = '#3b82f6';
    if ($wr2 < 60) $col = '#f59e0b';
    if ($wr2 < 50) $col = '#ef4444';
    $bucketColors[] = $col;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Advanced CSP Analysis</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
  :root {
    --bg:     #0d1117;
    --bg2:    #161b22;
    --bg3:    #21262d;
    --border: #30363d;
    --text:   #e6edf3;
    --muted:  #8b949e;
    --green:  #10b981;
    --red:    #ef4444;
    --blue:   #3b82f6;
    --amber:  #f59e0b;
    --purple: #a78bfa;
    --cyan:   #22d3ee;
  }

  * { box-sizing: border-box; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    margin: 0;
    font-size: 14px;
  }

  /* ── NAV ── */
  .nav {
    position: sticky; top: 0; z-index: 99;
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    display: flex; flex-wrap: wrap; gap: 0;
  }
  .nav a {
    color: var(--muted); text-decoration: none;
    padding: 12px 16px; font-size: 12px; font-weight: 500;
    border-right: 1px solid var(--border);
    transition: color .15s, background .15s;
    white-space: nowrap;
  }
  .nav a:hover { color: var(--text); background: var(--bg3); }
  .nav a.s1 { border-top: 2px solid var(--blue); }
  .nav a.s2 { border-top: 2px solid var(--green); }
  .nav a.s3 { border-top: 2px solid var(--purple); }
  .nav a.s4 { border-top: 2px solid var(--amber); }
  .nav a.s5 { border-top: 2px solid var(--cyan); }

  .page-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 20px 60px; }

  h1 {
    font-size: 22px; font-weight: 700; margin: 0 0 4px;
    background: linear-gradient(90deg,var(--blue),var(--cyan));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  }
  .subtitle { color: var(--muted); font-size: 12px; margin-bottom: 32px; }
  .subtitle code { background: var(--bg3); padding: 1px 5px; border-radius: 3px; border: 1px solid var(--border); color: var(--cyan); font-size: 11px; }

  /* ── SECTION CARD ── */
  .section {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-bottom: 36px;
    overflow: hidden;
  }
  .section-header {
    display: flex; align-items: center; gap: 10px;
    padding: 16px 20px; border-bottom: 1px solid var(--border);
  }
  .section-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
  }
  .section-header h2 {
    margin: 0; font-size: 15px; font-weight: 600;
  }
  .section-body { padding: 20px 24px; }

  /* Section accent colours */
  .s1 .section-dot  { background: var(--blue); }
  .s1 .section-header h2 { color: var(--blue); }
  .s2 .section-dot  { background: var(--green); }
  .s2 .section-header h2 { color: var(--green); }
  .s3 .section-dot  { background: var(--purple); }
  .s3 .section-header h2 { color: var(--purple); }
  .s4 .section-dot  { background: var(--amber); }
  .s4 .section-header h2 { color: var(--amber); }
  .s5 .section-dot  { background: var(--cyan); }
  .s5 .section-header h2 { color: var(--cyan); }

  /* ── KPI GRID ── */
  .kpi-grid { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 24px; }
  .kpi-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px 18px;
    flex: 1; min-width: 140px;
  }
  .kpi-val {
    font-size: 28px; font-weight: 700; line-height: 1.1;
    letter-spacing: -0.5px;
  }
  .kpi-lbl {
    font-size: 11px; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: .4px;
  }

  /* ── NOTE ── */
  .note {
    background: var(--bg3);
    border-left: 3px solid var(--border);
    border-radius: 0 6px 6px 0;
    padding: 10px 14px; margin-bottom: 20px;
    font-size: 12px; color: var(--muted); line-height: 1.6;
  }
  .note strong { color: var(--text); }
  .note code { color: var(--cyan); background: rgba(34,211,238,.08); padding: 1px 5px; border-radius: 3px; }

  /* ── SUB-HEADING ── */
  .sub-heading {
    font-size: 13px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: .6px;
    margin: 24px 0 10px; border-bottom: 1px solid var(--border); padding-bottom: 6px;
  }

  /* ── TABLES ── */
  .tbl-wrap { overflow-x: auto; margin-bottom: 24px; }
  table {
    border-collapse: collapse; width: 100%;
    font-size: 13px;
  }
  thead th {
    background: var(--bg3);
    color: var(--muted); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .4px;
    padding: 8px 12px; text-align: left;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  tbody td {
    padding: 8px 12px; border-bottom: 1px solid var(--border);
    vertical-align: middle;
  }
  tbody tr:hover td { background: var(--bg3); }
  tbody tr:last-child td { border-bottom: none; }

  /* ── SPLIT BAR ── */
  .split-bar { display: flex; height: 6px; border-radius: 3px; overflow: hidden; min-width: 60px; }
  .split-win  { background: var(--green); }
  .split-loss { background: var(--red); }

  /* ── UTILS ── */
  .pos { color: var(--green); font-weight: 600; }
  .neg { color: var(--red); font-weight: 600; }
  .neu { color: var(--muted); }
  strong { font-weight: 600; }
  code { font-family: 'Courier New', monospace; font-size: 12px; }

  /* ── CHART CONTAINER ── */
  .chart-container {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 24px;
  }
  .chart-row { display: flex; gap: 16px; flex-wrap: wrap; }
  .chart-row .chart-container { flex: 1; min-width: 300px; }

  /* ── OBS CARDS ── */
  .obs-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
  .obs-card {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px 16px;
    flex: 1; min-width: 200px;
  }
  .obs-card .obs-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; color: var(--muted); }
  .obs-card .obs-val { font-size: 22px; font-weight: 700; line-height: 1.1; margin-bottom: 4px; }
  .obs-card .obs-desc { font-size: 11px; color: var(--muted); line-height: 1.5; }
  .obs-cyan  { border-color: rgba(34,211,238,.3); }
  .obs-cyan .obs-val { color: var(--cyan); }
  .obs-green  { border-color: rgba(16,185,129,.3); }
  .obs-green .obs-val { color: var(--green); }
  .obs-amber  { border-color: rgba(245,158,11,.3); }
  .obs-amber .obs-val { color: var(--amber); }
  .obs-red    { border-color: rgba(239,68,68,.3); }
  .obs-red .obs-val { color: var(--red); }

  /* ── BADGES ── */
  .badge-100 {
    display: inline-block; padding: 1px 6px; border-radius: 4px;
    background: rgba(16,185,129,.15); color: var(--green);
    border: 1px solid rgba(16,185,129,.4); font-size: 10px; font-weight: 700;
    vertical-align: middle;
  }
  .badge-peak {
    display: inline-block; padding: 1px 6px; border-radius: 4px;
    background: rgba(245,158,11,.15); color: var(--amber);
    border: 1px solid rgba(245,158,11,.4); font-size: 10px; font-weight: 700;
    vertical-align: middle;
  }

  @media(max-width:700px){
    .kpi-val { font-size: 20px; }
    thead th, tbody td { padding: 6px 8px; font-size: 12px; }
    .obs-card { min-width: 150px; }
  }
</style>
</head>
<body>

<div class="nav">
  <a href="#s1" class="s1">⏱ Duration & Time-of-Day</a>
  <a href="#s2" class="s2">🔄 Re-entry Frequency</a>
  <a href="#s3" class="s3">📐 Body Ratio</a>
  <a href="#s4" class="s4">📈 Rolling 30-Day WR</a>
  <a href="#s5" class="s5">🕯 Candle Count</a>
</div>

<div class="page-wrap">
<h1>🔬 CSP Advanced Analysis</h1>
<div class="subtitle">
  Dataset: <code>prediction_trade_data</code> · Inline deduplication (lowest <code>raw_trade_id</code> per pair+time) ·
  Generated: <?= date('Y-m-d H:i:s') ?> UTC
</div>

<!-- ═══════════════════════════════════════════════════ S1 ══ -->
<a id="s1"></a>
<div class="section s1">
  <div class="section-header">
    <div class="section-dot"></div>
    <h2>⏱ Section 1 — Trade Duration & Time-of-Day Analysis</h2>
  </div>
  <div class="section-body">

    <div class="note">
      Duration = <code>win_loss_time − last_alert_time</code> in minutes.
      Time slots displayed in <strong>IST (UTC+5:30)</strong>. Only trades with valid <code>win_loss_time</code> after alert are included.
    </div>

    <?php
    $wDur   = $durByOutcome['win'];
    $lDur   = $durByOutcome['loss'];
    $avgWin  = $wDur['count']  > 0 ? round($wDur['total']  / $wDur['count'],  1) : null;
    $avgLoss = $lDur['count'] > 0 ? round($lDur['total'] / $lDur['count'], 1) : null;
    $totalDurN = $wDur['count'] + $lDur['count'];
    $allAvgDur = $totalDurN > 0 ? round(($wDur['total'] + $lDur['total']) / $totalDurN, 1) : null;
    ?>
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--green)"><?= $avgWin !== null ? $avgWin.' min' : '—' ?></div>
        <div class="kpi-lbl">Avg Win Duration (<?= $wDur['count'] ?> trades)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--red)"><?= $avgLoss !== null ? $avgLoss.' min' : '—' ?></div>
        <div class="kpi-lbl">Avg Loss Duration (<?= $lDur['count'] ?> trades)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val"><?= $allAvgDur !== null ? $allAvgDur.' min' : '—' ?></div>
        <div class="kpi-lbl">Overall Avg Duration (<?= $totalDurN ?> trades)</div>
      </div>
      <?php if ($avgWin !== null && $avgLoss !== null): ?>
      <div class="kpi-card">
        <div class="kpi-val" style="color:<?= $avgWin < $avgLoss ? 'var(--green)' : 'var(--amber)' ?>">
          <?= $avgWin < $avgLoss ? '✅ Wins faster' : '⚠️ Losses faster' ?>
        </div>
        <div class="kpi-lbl">Diff: <?= round(abs($avgWin - $avgLoss),1) ?> min</div>
      </div>
      <?php endif; ?>
    </div>

    <div class="sub-heading">1a. Win Rate by Duration Bin</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Duration</th><th>Trades</th><th>Wins</th><th>Losses</th>
        <th>Win Rate</th><th>95% CI</th><th>Distribution</th>
      </tr></thead>
      <tbody>
      <?php
      $maxDurN = 0;
      foreach ($durBins as $b) { $n = $b['w']+$b['l']; if ($n > $maxDurN) $maxDurN = $n; }
      foreach ($durBins as $label => $b):
        $n = $b['w'] + $b['l'];
        $wpct = $n > 0 ? round($b['w']/$n*100) : 0;
        $lpct = 100 - $wpct;
      ?>
      <tr>
        <td><strong><?= $label ?></strong></td>
        <td><?= $n ?></td>
        <td style="color:var(--green)"><?= $b['w'] ?></td>
        <td style="color:var(--red)"><?= $b['l'] ?></td>
        <td><?= wrPill($b['w'], $n) ?></td>
        <td><?= wilsonCI($b['w'], $n) ?></td>
        <td><div class="split-bar" style="width:<?= $maxDurN > 0 ? max(20, (int)round($n/$maxDurN*120)) : 20 ?>px">
          <div class="split-win" style="width:<?= $wpct ?>%"></div>
          <div class="split-loss" style="width:<?= $lpct ?>%"></div>
        </div></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="sub-heading">1b. Avg Duration by IST 30-Min Time Slot</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Time Slot (IST)</th><th>Trades</th>
        <th>Avg (All)</th><th>Avg (Wins)</th><th>Avg (Losses)</th><th>Win−Loss Diff</th>
      </tr></thead>
      <tbody>
      <?php foreach ($slotDur as $slot => $d):
        $allAvgS  = $d['all_n']  > 0 ? round($d['all_total']  / $d['all_n'],  1) : null;
        $winAvgS  = $d['win_n']  > 0 ? round($d['win_total']  / $d['win_n'],  1) : null;
        $lossAvgS = $d['loss_n'] > 0 ? round($d['loss_total'] / $d['loss_n'], 1) : null;
        $durDiff = '—'; $durCls = 'neu';
        if ($winAvgS !== null && $lossAvgS !== null) {
            $dd = $winAvgS - $lossAvgS;
            $durDiff = ($dd > 0 ? '+' : '') . round($dd,1) . ' min';
            $durCls  = $dd < 0 ? 'pos' : ($dd > 0 ? 'neg' : 'neu');
        }
      ?>
      <tr>
        <td><strong><?= $slot ?></strong></td>
        <td><?= $d['all_n'] ?></td>
        <td><?= $allAvgS !== null ? $allAvgS.' min' : '—' ?></td>
        <td style="color:var(--green)"><?= $winAvgS  !== null ? $winAvgS.' min' : '—' ?></td>
        <td style="color:var(--red)"><?= $lossAvgS !== null ? $lossAvgS.' min' : '—' ?></td>
        <td class="<?= $durCls ?>"><?= $durDiff ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="sub-heading">1c. Avg Duration by Pair</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Pair</th><th>Win N</th><th>Avg Win</th><th>Loss N</th><th>Avg Loss</th><th>Diff (W−L)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pairDur as $pair => $d):
        $wAvg = $d['win_n']  > 0 ? round($d['win_total']  / $d['win_n'],  1) : null;
        $lAvg = $d['loss_n'] > 0 ? round($d['loss_total'] / $d['loss_n'], 1) : null;
        $diff = '—'; $diffCls = 'neu';
        if ($wAvg !== null && $lAvg !== null) {
            $dd = $wAvg - $lAvg;
            $diff    = ($dd > 0 ? '+' : '') . round($dd,1) . ' min';
            $diffCls = $dd < 0 ? 'pos' : 'neg';
        }
      ?>
      <tr>
        <td><strong><?= $pair ?></strong></td>
        <td style="color:var(--green)"><?= $d['win_n'] ?></td>
        <td><?= $wAvg !== null ? $wAvg.' min' : '—' ?></td>
        <td style="color:var(--red)"><?= $d['loss_n'] ?></td>
        <td><?= $lAvg !== null ? $lAvg.' min' : '—' ?></td>
        <td class="<?= $diffCls ?>"><?= $diff ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════ S2 ══ -->
<a id="s2"></a>
<div class="section s2">
  <div class="section-header">
    <div class="section-dot"></div>
    <h2>🔄 Section 2 — Re-entry Frequency vs Win Rate</h2>
  </div>
  <div class="section-body">

    <div class="note">
      <strong>Re-entry</strong> = same pair signalled again within 120 min of its previous trade.
      <strong>Fresh</strong> = pair's first-ever trade, or last trade was &gt;120 min ago. Baseline = Fresh + &gt;120 min combined.
    </div>

    <?php
    $reentryW = 0; $reentryL = 0;
    foreach (array('<30 min','30-60 min','60-120 min') as $rb) {
        if (isset($reentryBinData[$rb])) { $reentryW += $reentryBinData[$rb]['w']; $reentryL += $reentryBinData[$rb]['l']; }
    }
    $reentryN  = $reentryW + $reentryL;
    $reentryWR = $reentryN > 0 ? round($reentryW / $reentryN * 100, 1) : null;
    $freshWRpct = $freshN > 0 ? round($freshWR * 100, 1) : null;
    ?>
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--green)"><?= $freshWRpct !== null ? $freshWRpct.'%' : '—' ?></div>
        <div class="kpi-lbl">Fresh Signal WR (<?= $freshN ?> trades)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--amber)"><?= $reentryWR !== null ? $reentryWR.'%' : '—' ?></div>
        <div class="kpi-lbl">Re-entry WR (<?= $reentryN ?> trades)</div>
      </div>
      <?php if ($freshWRpct !== null && $reentryWR !== null): ?>
      <div class="kpi-card">
        <div class="kpi-val" style="color:<?= $reentryWR < $freshWRpct ? 'var(--red)' : 'var(--green)' ?>">
          <?= sprintf('%+.1f pp', $reentryWR - $freshWRpct) ?>
        </div>
        <div class="kpi-lbl">Re-entry vs Fresh Gap</div>
      </div>
      <?php endif; ?>
    </div>

    <div class="sub-heading">2a. Win Rate by Time Since Last Same-Pair Trade</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Gap Since Last Trade</th><th>Trades</th><th>Wins</th><th>Losses</th>
        <th>Win Rate</th><th>95% CI</th><th>vs Fresh Baseline</th>
      </tr></thead>
      <tbody>
      <?php
      $allBinLabels = array_merge(array('Fresh (first ever)'), array_keys($REENTRY_WINDOWS));
      foreach ($allBinLabels as $label):
        if (!isset($reentryBinData[$label])) continue;
        $b   = $reentryBinData[$label];
        $n   = $b['w'] + $b['l'];
        $wrv = $n > 0 ? $b['w'] / $n : null;
        $vs  = ($wrv !== null && $freshWR !== null) ? diffBadge($wrv, $freshWR) : '—';
        $rowBg = ($wrv !== null && $freshWR !== null && ($wrv - $freshWR) < -0.03) ? 'rgba(239,68,68,.06)' : '';
      ?>
      <tr style="background:<?= $rowBg ?>">
        <td><strong><?= $label ?></strong></td>
        <td><?= $n ?></td>
        <td style="color:var(--green)"><?= $b['w'] ?></td>
        <td style="color:var(--red)"><?= $b['l'] ?></td>
        <td><?= wrPill($b['w'], $n) ?></td>
        <td><?= wilsonCI($b['w'], $n) ?></td>
        <td><?= $vs ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="sub-heading">2b. Per-Pair: Fresh vs Re-entry Win Rate</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Pair</th>
        <th>Fresh N</th><th>Fresh WR</th>
        <th>Re-entry N</th><th>Re-entry WR</th>
        <th>Diff</th><th>Signal Independence</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pairReentry as $pair => $d):
        $fn  = $d['fresh']['w']   + $d['fresh']['l'];
        $rn  = $d['reentry']['w'] + $d['reentry']['l'];
        $fwr = $fn > 0 ? $d['fresh']['w']   / $fn : null;
        $rwr = $rn > 0 ? $d['reentry']['w'] / $rn : null;
        $diff = '—'; $diffCls = 'neu'; $independence = '—';
        if ($fwr !== null && $rwr !== null) {
            $dd      = ($rwr - $fwr) * 100;
            $diff    = sprintf('%+.1f pp', $dd);
            $diffCls = $dd >= 0 ? 'pos' : 'neg';
            if (abs($dd) < 3)  $independence = '✅ Independent — re-entry safe';
            elseif ($dd < -5)  $independence = '⚠️ Dependent — avoid rapid re-entry';
            else               $independence = '➡️ Slight effect — monitor';
        }
      ?>
      <tr>
        <td><strong><?= $pair ?></strong></td>
        <td><?= $fn ?></td>
        <td><?= wrPill($d['fresh']['w'], $fn) ?></td>
        <td><?= $rn ?></td>
        <td><?= wrPill($d['reentry']['w'], $rn) ?></td>
        <td class="<?= $diffCls ?>"><?= $diff ?></td>
        <td style="font-size:12px;color:var(--muted)"><?= $independence ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════ S3 ══ -->
<a id="s3"></a>
<div class="section s3">
  <div class="section-header">
    <div class="section-dot"></div>
    <h2>📐 Section 3 — Candle Body Ratio: Streak vs Confirmation</h2>
  </div>
  <div class="section-body">

    <div class="note">
      <strong>Ratio</strong> = Confirmation candle body (C1) ÷ Avg streak candle body (C3, C4, C5).
      <strong>Hypothesis:</strong> Ratio &gt; 1.2 → stronger momentum acceleration → higher WR.
      Equal bin (0.8–1.2) is baseline.
    </div>

    <div class="kpi-grid">
    <?php foreach ($ratioBins as $label => $b):
      $n  = $b['w'] + $b['l'];
      $wr = $n > 0 ? round($b['w'] / $n * 100, 1) : null;
      $avgR = count($b['ratios']) > 0 ? round(array_sum($b['ratios']) / count($b['ratios']), 2) : '—';
    ?>
      <div class="kpi-card">
        <div class="kpi-val" style="color:<?= wrColor($wr) ?>"><?= $wr !== null ? $wr.'%' : '—' ?></div>
        <div class="kpi-lbl"><?= $label ?> · n=<?= $n ?> · avg=<?= $avgR ?></div>
      </div>
    <?php endforeach; ?>
    </div>

    <div class="sub-heading">3a. Win Rate by Ratio Bin</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Ratio Bin</th><th>Trades</th><th>Wins</th><th>Losses</th>
        <th>Win Rate</th><th>95% CI</th><th>Avg Ratio</th><th>vs Equal Baseline</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ratioBins as $label => $b):
        $n    = $b['w'] + $b['l'];
        $wrv  = $n > 0 ? $b['w'] / $n : null;
        $avgR = count($b['ratios']) > 0 ? round(array_sum($b['ratios']) / count($b['ratios']), 2) : '—';
        $vs   = ($wrv !== null && $eqWR !== null) ? diffBadge($wrv, $eqWR) : '<span class="neu">baseline</span>';
      ?>
      <tr>
        <td><strong><?= $label ?></strong></td>
        <td><?= $n ?></td>
        <td style="color:var(--green)"><?= $b['w'] ?></td>
        <td style="color:var(--red)"><?= $b['l'] ?></td>
        <td><?= wrPill($b['w'], $n) ?></td>
        <td><?= wilsonCI($b['w'], $n) ?></td>
        <td><?= $avgR ?></td>
        <td><?= $vs ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <div class="sub-heading">3b. Per-Pair Ratio Breakdown</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Pair</th>
        <th>Small N</th><th>Small WR</th>
        <th>Equal N</th><th>Equal WR</th>
        <th>Large N</th><th>Large WR</th>
        <th>Best Bin (n≥5)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($byPairRatio as $pair => $bins3):
        $bestBin = '—'; $bestWRv = -1;
        $cells = '';
        foreach (array('Small (<0.8)','Equal (0.8-1.2)','Large (>1.2)') as $b3):
          $bdata = isset($bins3[$b3]) ? $bins3[$b3] : array('w'=>0,'l'=>0);
          $n3 = $bdata['w'] + $bdata['l'];
          $wr3 = $n3 > 0 ? round($bdata['w']/$n3*100,1) : null;
          $cells .= '<td style="color:var(--muted)">'.$n3.'</td><td>'.($n3>0?wrPill($bdata['w'],$n3):'—').'</td>';
          if ($wr3 !== null && $n3 >= 5 && $wr3 > $bestWRv) {
              $bestWRv = $wr3;
              $short = str_replace(array('Small (<0.8)','Equal (0.8-1.2)','Large (>1.2)'),array('Small','Equal','Large'),$b3);
              $bestBin = $short.' ('.$wr3.'%)';
          }
        endforeach;
      ?>
      <tr>
        <td><strong><?= $pair ?></strong></td>
        <?= $cells ?>
        <td style="font-size:12px"><?= $bestBin ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════ S4 ══ -->
<a id="s4"></a>
<div class="section s4">
  <div class="section-header">
    <div class="section-dot"></div>
    <h2>📈 Section 4 — Rolling <?= $ROLLING_DAYS ?>-Day Win Rate Trend</h2>
  </div>
  <div class="section-body">

    <div class="note">
      Each row = WR of all trades in the <?= $ROLLING_DAYS ?> calendar days ending on that anchor date.
      Sampled every <?= $ROLLING_STEP ?> trades. Windows &lt;10 trades excluded.
      Timestamps pre-computed to avoid O(n²) <code>strtotime()</code> calls.
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-val"><?= $overallWR ?>%</div>
        <div class="kpi-lbl">Overall WR (<?= $overallN ?> trades)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--red)"><?= $minRolling ?>%</div>
        <div class="kpi-lbl">Lowest 30-Day Rolling WR</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--green)"><?= $maxRolling ?>%</div>
        <div class="kpi-lbl">Highest 30-Day Rolling WR</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:<?= $rangeRolling > 15 ? 'var(--red)' : ($rangeRolling > 8 ? 'var(--amber)' : 'var(--green)') ?>">
          <?= $rangeRolling ?> pp
        </div>
        <div class="kpi-lbl">WR Range (max − min)</div>
      </div>
    </div>

    <div class="sub-heading">4a. Rolling Win Rate Table</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Anchor Date</th><th>Trade #</th><th>N</th>
        <th>Wins</th><th>Losses</th><th>Rolling WR</th><th>vs Overall</th><th>Bar</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rollingData as $d):
        $diff   = $d['wr'] - $overallWR;
        $dStr   = sprintf('%+.1f pp', $diff);
        $dCls   = $diff > 2 ? 'pos' : ($diff < -2 ? 'neg' : 'neu');
        $barCol = $d['wr'] >= $overallWR + 2 ? 'var(--green)' : ($d['wr'] <= $overallWR - 2 ? 'var(--red)' : 'var(--amber)');
        $barW   = max(2, (int)round($d['wr'] * 1.4));
      ?>
      <tr>
        <td><strong><?= $d['date'] ?></strong></td>
        <td style="color:var(--muted)"><?= $d['trade'] ?></td>
        <td><?= $d['n'] ?></td>
        <td style="color:var(--green)"><?= $d['w'] ?></td>
        <td style="color:var(--red)"><?= $d['l'] ?></td>
        <td><?= wrPill($d['w'], $d['n']) ?></td>
        <td class="<?= $dCls ?>"><?= $dStr ?></td>
        <td><div style="height:8px;width:<?= $barW ?>px;background:<?= $barCol ?>;border-radius:4px;display:inline-block"></div></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($rollingData)): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">Not enough data for rolling windows.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <div class="sub-heading">4b. Stability Assessment</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr><th>Metric</th><th>Value</th><th>Interpretation</th></tr></thead>
      <tbody>
      <tr><td>Overall WR</td><td><?= wrPill($overallW, $overallN) ?></td><td style="color:var(--muted)">Full-period average</td></tr>
      <tr>
        <td>Min rolling WR</td>
        <td style="color:var(--red)"><?= $minRolling ?>%</td>
        <td style="color:var(--muted)"><?= $minRolling >= 70 ? '✅ Even worst period above break-even' : ($minRolling >= 60 ? '⚠️ Some periods near break-even' : '❌ Some periods likely unprofitable') ?></td>
      </tr>
      <tr><td>Max rolling WR</td><td style="color:var(--green)"><?= $maxRolling ?>%</td><td style="color:var(--muted)">Best 30-day window</td></tr>
      <tr>
        <td>WR range</td>
        <td class="<?= $rangeRolling > 15 ? 'neg' : ($rangeRolling > 8 ? '' : 'pos') ?>"><?= $rangeRolling ?> pp</td>
        <td style="color:var(--muted)"><?= $rangeRolling <= 8 ? '✅ Stable edge across time' : ($rangeRolling <= 15 ? '➡️ Moderate variance — acceptable' : '⚠️ High variance — add regime filter') ?></td>
      </tr>
      <tr>
        <td>Break-even (80% payout)</td>
        <td>55.6%</td>
        <td style="color:var(--muted)"><?= $minRolling > 55.6 ? '✅ All windows above break-even' : '❌ Some windows dip below break-even' ?></td>
      </tr>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════ S5 ══ -->
<a id="s5"></a>
<div class="section s5">
  <div class="section-header">
    <div class="section-dot"></div>
    <h2>🕯 Section 5 — Candle Count Analysis</h2>
  </div>
  <div class="section-body">

    <div class="note">
      Each CSV candle = <strong>5 minutes</strong>.
      <strong>Signal candle</strong> = bar where <code>alert_ts ≥ candle_time &lt; candle_time + 300</code>.
      <strong>Resolution candle</strong> = same logic with <code>win_loss_time</code> (stored as IST in <code>trade_outcome_details</code>).
      Candle count = resolution_index − signal_index + 1 (inclusive). Only trades with resolved CSV paths counted.
    </div>

    <!-- KPI Row -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--cyan)"><?= $avgAll !== null ? $avgAll.'c' : '—' ?></div>
        <div class="kpi-lbl">Avg Candles (All Trades)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--green)"><?= $avgWin !== null ? $avgWin.'c' : '—' ?></div>
        <div class="kpi-lbl">Avg Candles (Wins)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--red)"><?= $avgLoss !== null ? $avgLoss.'c' : '—' ?></div>
        <div class="kpi-lbl">Avg Candles (Losses)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--purple)"><?= $medAll !== null ? $medAll.'c' : '—' ?></div>
        <div class="kpi-lbl">Median Candles (All)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val"><?= $minCount !== null ? $minCount.'–'.$maxCount.'c' : '—' ?></div>
        <div class="kpi-lbl">Range (min–max)</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-val" style="color:var(--amber)"><?= $resolvedN ?></div>
        <div class="kpi-lbl">Resolved Count (CSV matched)</div>
      </div>
    </div>

    <!-- Observation Cards -->
    <div class="sub-heading">Key Observations</div>
    <div class="obs-grid">
      <?php
      $c4w = isset($ccByCount[4]) ? $ccByCount[4]['w'] : 0;
      $c4l = isset($ccByCount[4]) ? $ccByCount[4]['l'] : 0;
      $c4n = $c4w + $c4l;
      $c4wr = $c4n > 0 ? round($c4w/$c4n*100,1) : null;

      $c6w = isset($ccByCount[6]) ? $ccByCount[6]['w'] : 0;
      $c6l = isset($ccByCount[6]) ? $ccByCount[6]['l'] : 0;
      $c6n = $c6w + $c6l;
      $c6wr = $c6n > 0 ? round($c6w/$c6n*100,1) : null;

      $c7w = isset($ccByCount[7]) ? $ccByCount[7]['w'] : 0;
      $c7l = isset($ccByCount[7]) ? $ccByCount[7]['l'] : 0;
      $c7n = $c7w + $c7l;
      $c7wr = $c7n > 0 ? round($c7w/$c7n*100,1) : null;

      $earlyW = 0; $earlyL = 0;
      for ($ci = 1; $ci <= 10; $ci++) {
          if (isset($ccByCount[$ci])) { $earlyW += $ccByCount[$ci]['w']; $earlyL += $ccByCount[$ci]['l']; }
      }
      $earlyN = $earlyW + $earlyL;
      $earlyWR = $earlyN > 0 ? round($earlyW/$earlyN*100,1) : null;
      ?>

      <div class="obs-card obs-green">
        <div class="obs-title">4-Candle Trades</div>
        <div class="obs-val"><?= $c4wr !== null ? $c4wr.'%' : '—' ?> WR</div>
        <div class="obs-desc">n=<?= $c4n ?> (<?= $c4w ?> W / <?= $c4l ?> L) · <?= candlesToMin(4) ?> resolution<?= $c4wr !== null && $c4wr == 100.0 ? ' · 100% perfect entry zone' : '' ?></div>
      </div>

      <div class="obs-card obs-cyan">
        <div class="obs-title">6-Candle Sweet Spot</div>
        <div class="obs-val"><?= $c6wr !== null ? $c6wr.'%' : '—' ?> WR</div>
        <div class="obs-desc">n=<?= $c6n ?> (<?= $c6w ?> W / <?= $c6l ?> L) · <?= candlesToMin(6) ?> resolution · highest-volume zone</div>
      </div>

      <div class="obs-card obs-amber">
        <div class="obs-title">7-Candle Anomaly</div>
        <div class="obs-val"><?= $c7wr !== null ? $c7wr.'%' : '—' ?> WR</div>
        <div class="obs-desc">n=<?= $c7n ?> · <?= candlesToMin(7) ?> · check for structural drop vs 6c</div>
      </div>

      <div class="obs-card obs-cyan">
        <div class="obs-title">Median Resolution</div>
        <div class="obs-val"><?= $medAll !== null ? $medAll.'c' : '—' ?></div>
        <div class="obs-desc"><?= $medAll !== null ? candlesToMin((int)$medAll).' median resolution time' : 'No data' ?></div>
      </div>

      <div class="obs-card <?= ($avgWin !== null && $avgLoss !== null && $avgWin < $avgLoss) ? 'obs-green' : 'obs-amber' ?>">
        <div class="obs-title">Win Avg vs Loss Avg</div>
        <div class="obs-val">
          <?php
          if ($avgWin !== null && $avgLoss !== null) {
              echo $avgWin.'c vs '.$avgLoss.'c';
          } else { echo '—'; }
          ?>
        </div>
        <div class="obs-desc">
          <?php
          if ($avgWin !== null && $avgLoss !== null) {
              $diff5 = round($avgLoss - $avgWin, 1);
              if ($avgWin < $avgLoss) echo 'Wins resolve '.$diff5.'c faster — clean momentum';
              else echo 'Losses resolve faster by '.abs($diff5).'c — investigate';
          }
          ?>
        </div>
      </div>

      <div class="obs-card obs-green">
        <div class="obs-title">Early Zone (≤10c)</div>
        <div class="obs-val"><?= $earlyWR !== null ? $earlyWR.'%' : '—' ?> WR</div>
        <div class="obs-desc">n=<?= $earlyN ?> trades resolved ≤50 min · <?= $earlyW ?> W / <?= $earlyL ?> L</div>
      </div>
    </div>

    <!-- Chart 1: Stacked bar + WR line (4–30 candles) -->
    <div class="sub-heading">Chart 1 — Win/Loss Distribution & Win Rate by Candle Count (4–30)</div>
    <div class="chart-container">
      <canvas id="chart5a" height="90"></canvas>
    </div>

    <!-- Chart 2: Bucket WR% bar -->
    <div class="sub-heading">Chart 2 — Win Rate by Resolution Bucket</div>
    <div class="chart-container" style="max-width:700px">
      <canvas id="chart5b" height="100"></canvas>
    </div>

    <!-- Table 5a — Buckets -->
    <div class="sub-heading">Table 5a — Bucket Summary</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Bucket</th><th>Candle Range</th><th>Time Range</th>
        <th>Trades</th><th>Wins</th><th>Losses</th><th>Win Rate</th><th>95% CI</th>
      </tr></thead>
      <tbody>
      <?php foreach ($buckets as $key => $bkt):
        $n5 = $bkt['w'] + $bkt['l'];
        $maxC = $bkt['max'] > 100 ? '∞' : $bkt['max'];
        $timeMin = $bkt['min'] * 5;
        $timeMax = $bkt['max'] > 100 ? '∞' : $bkt['max'] * 5;
      ?>
      <tr>
        <td><strong><?= $bkt['label'] ?></strong></td>
        <td><?= candleChip($bkt['min']) ?> – <?= $bkt['max'] > 100 ? '<span style="color:var(--muted)">∞</span>' : candleChip($bkt['max']) ?></td>
        <td><?= timeChip($timeMin) ?> – <?= $bkt['max'] > 100 ? '<span style="color:var(--muted)">∞</span>' : timeChip($timeMax) ?></td>
        <td><?= $n5 ?></td>
        <td style="color:var(--green)"><?= $bkt['w'] ?></td>
        <td style="color:var(--red)"><?= $bkt['l'] ?></td>
        <td><?= $n5 > 0 ? wrPill($bkt['w'], $n5) : '—' ?></td>
        <td><?= wilsonCI($bkt['w'], $n5) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Table 5b — Per-count 4–30 -->
    <div class="sub-heading">Table 5b — Per Candle Count (4–30)</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Candle Count</th><th>Time</th><th>Trades</th>
        <th>Wins</th><th>Losses</th><th>Win Rate</th><th>95% CI</th><th>Bar</th>
      </tr></thead>
      <tbody>
      <?php for ($cnt = 4; $cnt <= 30; $cnt++):
        $w5 = isset($ccByCount[$cnt]) ? $ccByCount[$cnt]['w'] : 0;
        $l5 = isset($ccByCount[$cnt]) ? $ccByCount[$cnt]['l'] : 0;
        $n5 = $w5 + $l5;
        if ($n5 === 0) continue;
        $is4 = ($cnt === 4 && $n5 > 0 && $l5 === 0);
        $is6 = ($cnt === 6 && $n5 >= 3);
        $wr5 = round($w5/$n5*100,1);
        $wpct = $wr5; $lpct = 100 - $wpct;
      ?>
      <tr>
        <td>
          <?= candleChip($cnt) ?>
          <?php if ($is4): ?> <span class="badge-100">100%</span><?php endif; ?>
          <?php if ($is6): ?> <span class="badge-peak">★ peak</span><?php endif; ?>
        </td>
        <td><?= timeChip($cnt * 5) ?></td>
        <td><?= $n5 ?></td>
        <td style="color:var(--green)"><?= $w5 ?></td>
        <td style="color:var(--red)"><?= $l5 ?></td>
        <td><?= wrPill($w5, $n5) ?></td>
        <td><?= wilsonCI($w5, $n5) ?></td>
        <td>
          <div class="split-bar" style="width:<?= max(20,min(120,$n5*2)) ?>px">
            <div class="split-win" style="width:<?= $wpct ?>%"></div>
            <div class="split-loss" style="width:<?= $lpct ?>%"></div>
          </div>
        </td>
      </tr>
      <?php endfor; ?>
      <?php if (empty($ccByCount)): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">No candle count data — check CSV paths.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Table 5c — Per pair candle counts -->
    <div class="sub-heading">Table 5c — Per Pair: Avg Candles</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Pair</th>
        <th>Win N</th><th>Avg Win Candles</th><th>Avg Win Time</th>
        <th>Loss N</th><th>Avg Loss Candles</th><th>Avg Loss Time</th>
        <th>Δ (W−L)</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ccByPair as $pair => $d5):
        $wAvgC = $d5['win_n']  > 0 ? round($d5['win_sum']  / $d5['win_n'],  1) : null;
        $lAvgC = $d5['loss_n'] > 0 ? round($d5['loss_sum'] / $d5['loss_n'], 1) : null;
        $delta = '—'; $deltaCls = 'neu';
        if ($wAvgC !== null && $lAvgC !== null) {
            $dd5 = $wAvgC - $lAvgC;
            $delta    = ($dd5 > 0 ? '+' : '') . round($dd5,1) . 'c';
            $deltaCls = $dd5 < 0 ? 'pos' : ($dd5 > 0 ? 'neg' : 'neu');
        }
      ?>
      <tr>
        <td><strong><?= $pair ?></strong></td>
        <td style="color:var(--green)"><?= $d5['win_n'] ?></td>
        <td><?= $wAvgC !== null ? candleChip((int)round($wAvgC)) : '—' ?> <?= $wAvgC ?></td>
        <td><?= $wAvgC !== null ? timeChip((int)round($wAvgC * 5)) : '—' ?></td>
        <td style="color:var(--red)"><?= $d5['loss_n'] ?></td>
        <td><?= $lAvgC !== null ? candleChip((int)round($lAvgC)) : '—' ?> <?= $lAvgC ?></td>
        <td><?= $lAvgC !== null ? timeChip((int)round($lAvgC * 5)) : '—' ?></td>
        <td class="<?= $deltaCls ?>"><?= $delta ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($ccByPair)): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:20px">No data.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Table 5d — By IST slot -->
    <div class="sub-heading">Table 5d — By Alert Time Slot (IST)</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>Time Slot (IST)</th><th>Win Rate</th><th>Avg Candles</th><th>Avg Time</th>
        <th>Win Avg Candles</th><th>Loss Avg Candles</th><th>Trades</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ccBySlot as $slot => $d5):
        $slotN  = $d5['w'] + $d5['l'];
        $avgC   = $d5['all_n']  > 0 ? round($d5['all_sum']  / $d5['all_n'],  1) : null;
        $wAvgC5 = $d5['win_n']  > 0 ? round($d5['win_sum']  / $d5['win_n'],  1) : null;
        $lAvgC5 = $d5['loss_n'] > 0 ? round($d5['loss_sum'] / $d5['loss_n'], 1) : null;
      ?>
      <tr>
        <td><strong><?= $slot ?></strong></td>
        <td><?= wrPill($d5['w'], $slotN) ?></td>
        <td><?= $avgC !== null ? candleChip((int)round($avgC)) : '—' ?></td>
        <td><?= $avgC !== null ? timeChip((int)round($avgC * 5)) : '—' ?></td>
        <td style="color:var(--green)"><?= $wAvgC5 !== null ? $wAvgC5.'c' : '—' ?></td>
        <td style="color:var(--red)"><?= $lAvgC5 !== null ? $lAvgC5.'c' : '—' ?></td>
        <td style="color:var(--muted)"><?= $slotN ?> (<?= $d5['w'] ?>W / <?= $d5['l'] ?>L)</td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($ccBySlot)): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">No data.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

    <!-- Table 5e — Full per-trade log (last 200) -->
    <div class="sub-heading">Table 5e — Per-Trade Log (most recent 200)</div>
    <div class="tbl-wrap">
    <table>
      <thead><tr>
        <th>#</th><th>ID</th><th>Pair</th>
        <th>Alert (IST)</th><th>Resolution (IST)</th>
        <th>Candles</th><th>Time</th><th>Result</th>
      </tr></thead>
      <tbody>
      <?php
      $logRows = array_reverse($s5trades); // most recent first
      $shown5  = 0;
      foreach ($logRows as $idx5 => $r5):
        if ($shown5 >= 200) break;
        $cc5  = $r5['candle_count'];
        $RowBg = '';
        if ($cc5 === null) $RowBg = 'opacity:.55';
      ?>
      <tr style="<?= $RowBg ?>">
        <td style="color:var(--muted);font-size:11px"><?= $idx5+1 ?></td>
        <td style="color:var(--muted)"><?= $r5['id'] ?></td>
        <td><strong><?= $r5['pair'] ?></strong></td>
        <td style="font-size:12px"><?= $r5['alert_ist'] !== null ? $r5['alert_ist'] : '<span style="color:var(--muted)">—</span>' ?></td>
        <td style="font-size:12px"><?= $r5['wlt_ist'] ?></td>
        <td><?= candleChip($cc5) ?></td>
        <td><?= $cc5 !== null ? timeChip($cc5 * 5) : '<span style="color:var(--muted)">—</span>' ?></td>
        <td><?= resultTag($r5['result']) ?></td>
      </tr>
      <?php $shown5++; endforeach; ?>
      <?php if (count($s5trades) > 200): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:12px;font-size:12px">
        … <?= count($s5trades) - 200 ?> more rows not shown
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>

  </div><!-- /section-body -->
</div><!-- /section s5 -->

</div><!-- /page-wrap -->

<div style="text-align:center;font-size:11px;color:var(--muted);padding:20px;border-top:1px solid var(--border)">
  CSP Research — Internal Use Only &nbsp;|&nbsp; Generated <?= date('Y-m-d H:i:s') ?> UTC
</div>

<!-- ═══ CHART.JS INIT ═══ -->
<script>
(function(){
  'use strict';

  const LABELS  = <?= json_encode($chartLabels) ?>;
  const WINS    = <?= json_encode($chartWins) ?>;
  const LOSSES  = <?= json_encode($chartLosses) ?>;
  const WR_LINE = <?= json_encode($chartWR) ?>;
  const B_LABELS= <?= json_encode($bucketLabels) ?>;
  const B_WRS   = <?= json_encode($bucketWRs) ?>;
  const B_COLS  = <?= json_encode($bucketColors) ?>;

  const darkGrid = { color: 'rgba(48,54,61,0.8)' };
  const darkTick = { color: '#8b949e', font: { size: 11 } };

  // Chart 1 — Stacked bar + WR% line
  const ctx1 = document.getElementById('chart5a').getContext('2d');
  new Chart(ctx1, {
    data: {
      labels: LABELS,
      datasets: [
        {
          type: 'bar',
          label: 'Wins',
          data: WINS,
          backgroundColor: 'rgba(16,185,129,0.55)',
          borderColor: 'rgba(16,185,129,0.9)',
          borderWidth: 1,
          stack: 'trades',
          yAxisID: 'yCount',
        },
        {
          type: 'bar',
          label: 'Losses',
          data: LOSSES,
          backgroundColor: 'rgba(239,68,68,0.45)',
          borderColor: 'rgba(239,68,68,0.8)',
          borderWidth: 1,
          stack: 'trades',
          yAxisID: 'yCount',
        },
        {
          type: 'line',
          label: 'Win Rate %',
          data: WR_LINE,
          borderColor: '#22d3ee',
          backgroundColor: 'rgba(34,211,238,0.12)',
          borderWidth: 2,
          pointRadius: 3,
          pointBackgroundColor: '#22d3ee',
          tension: 0.35,
          fill: false,
          yAxisID: 'yWR',
          spanGaps: true,
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { color: '#8b949e', font: { size: 12 } } },
        tooltip: { callbacks: {
          label: function(ctx) {
            if (ctx.dataset.label === 'Win Rate %')
              return 'WR: ' + (ctx.raw !== null ? ctx.raw + '%' : '—');
            return ctx.dataset.label + ': ' + ctx.raw;
          }
        }}
      },
      scales: {
        x: { grid: darkGrid, ticks: darkTick, stacked: true,
             title: { display: true, text: 'Candle Count', color: '#8b949e', font: { size: 12 } } },
        yCount: {
          grid: darkGrid, ticks: darkTick, stacked: true,
          position: 'left',
          title: { display: true, text: 'Trade Count', color: '#8b949e', font: { size: 12 } }
        },
        yWR: {
          grid: { display: false },
          ticks: { color: '#22d3ee', font: { size: 11 },
                   callback: function(v){ return v + '%'; } },
          position: 'right',
          min: 0, max: 100,
          title: { display: true, text: 'Win Rate %', color: '#22d3ee', font: { size: 12 } }
        }
      }
    }
  });

  // Chart 2 — Bucket WR
  const ctx2 = document.getElementById('chart5b').getContext('2d');
  new Chart(ctx2, {
    type: 'bar',
    data: {
      labels: B_LABELS,
      datasets: [{
        label: 'Win Rate %',
        data: B_WRS,
        backgroundColor: B_COLS.map(function(c){ return c + '55'; }),
        borderColor: B_COLS,
        borderWidth: 2,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: {
          label: function(ctx){ return 'WR: ' + ctx.raw + '%'; }
        }}
      },
      scales: {
        x: { grid: darkGrid, ticks: darkTick },
        y: {
          grid: darkGrid, ticks: { color: '#8b949e', font: { size: 11 },
                                   callback: function(v){ return v + '%'; } },
          min: 0, max: 100,
          title: { display: true, text: 'Win Rate %', color: '#8b949e', font: { size: 12 } }
        }
      }
    }
  });

})();
</script>

</body>
</html>