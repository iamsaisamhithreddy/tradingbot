<?php
/**
 * ANALYSIS 3: Volatility Regime vs Win Rate
 * Schema: prediction_trade_data + economic_events
 * PHP 7 compatible | Uses db.php (mysqli)
 *
 * NOTE: economic_events has no currency column, so pair-relevant
 * proximity is matched by keywords in event_name.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once __DIR__ . '/../db.php'; // provides $conn (mysqli)

// ── PARAMETERS ────────────────────────────────────────────────────────────────
$MIN_IMPACT     = 2;  // count events with impact >= this
$LOW_THRESHOLD  = 2;  // <= N events on the day  => Low volatility
$HIGH_THRESHOLD = 5;  // >= N events on the day  => High volatility
                      // between                  => Medium

$PROXIMITY_BINS = array(
    '0-15 min'  => array(0,   15),
    '15-30 min' => array(15,  30),
    '30-60 min' => array(30,  60),
    '1-2 hrs'   => array(60,  120),
    '2-4 hrs'   => array(120, 240),
    '>4 hrs'    => array(240, PHP_INT_MAX),
);
$BASE_PROX_KEY = '>4 hrs';

// ── KEYWORD MAP for pair-relevant event matching ──────────────────────────────
// (used because economic_events has no currency column)
$keywordMap = array(
    'AUD' => array('AUD','RBA','Australian','Australia','Cash Rate'),
    'USD' => array('USD','Fed','FOMC','Federal','NFP','Nonfarm','CPI','GDP','Unemployment'),
    'GBP' => array('GBP','BOE','BoE','UK ','British','Sterling','Official Bank Rate'),
    'EUR' => array('EUR','ECB','German','Euro','ZEW','Refinancing'),
    'JPY' => array('JPY','BOJ','BoJ','Japan','Japanese'),
    'CAD' => array('CAD','BOC','BoC','Canada','Canadian','Overnight Rate'),
    'CHF' => array('CHF','SNB','Swiss'),
    'NZD' => array('NZD','RBNZ','New Zealand'),
);

// ── HELPERS ───────────────────────────────────────────────────────────────────
function wrPct($w, $n) {
    return $n > 0 ? round($w / $n * 100, 1) . '%' : '—';
}

function wilsonCI($w, $n) {
    if ($n < 5) return '<small style="color:#999">n&lt;5</small>';
    $p     = $w / $n;
    $z     = 1.96;
    $denom = 1 + $z * $z / $n;
    $c     = ($p + $z * $z / (2 * $n)) / $denom;
    $m     = ($z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n))) / $denom;
    return sprintf('[%.1f%%, %.1f%%]', ($c - $m) * 100, ($c + $m) * 100);
}

function getRegime($count, $low, $high) {
    if ($count <= $low)  return 'Low';
    if ($count >= $high) return 'High';
    return 'Medium';
}

function getProxBin($minutes, $bins) {
    foreach ($bins as $label => $range) {
        if ($minutes >= $range[0] && $minutes < $range[1]) return $label;
    }
    return '>4 hrs';
}

// Binary search for nearest event timestamp
function nearestMinAll($tradeTs, $sortedTs) {
    if (empty($sortedTs)) return null;
    $lo = 0;
    $hi = count($sortedTs) - 1;
    while ($lo < $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($sortedTs[$mid] < $tradeTs) $lo = $mid + 1;
        else $hi = $mid;
    }
    $best = abs($tradeTs - $sortedTs[$lo]);
    if ($lo > 0) $best = min($best, abs($tradeTs - $sortedTs[$lo - 1]));
    return (int)round($best / 60);
}

// Keyword-based pair-relevant proximity
function nearestMinRelevant($tradeTs, $pair, $eventsByDate, $date, $keywordMap) {
    $base  = substr($pair, 0, 3);
    $quote = substr($pair, 3, 3);

    $keywords = array();
    if (isset($keywordMap[$base]))  $keywords = array_merge($keywords, $keywordMap[$base]);
    if (isset($keywordMap[$quote])) $keywords = array_merge($keywords, $keywordMap[$quote]);

    if (empty($keywords) || !isset($eventsByDate[$date])) return null;

    $best = null;
    foreach ($eventsByDate[$date] as $ev) {
        $matched = false;
        foreach ($keywords as $kw) {
            if (stripos($ev['event_name'], $kw) !== false) {
                $matched = true;
                break;
            }
        }
        if (!$matched) continue;
        $diff = abs($tradeTs - strtotime($ev['event_time']));
        if ($best === null || $diff < $best) $best = $diff;
    }
    return $best !== null ? (int)round($best / 60) : null;
}

// ── FETCH ECONOMIC EVENTS ─────────────────────────────────────────────────────
$minImp = (int)$MIN_IMPACT;
$eSql   = "SELECT id, event_name, impact, event_time, event_date
           FROM economic_events
           WHERE impact >= $minImp
           ORDER BY event_time ASC";

$eRes = $conn->query($eSql);
if (!$eRes) die("<b>Event query failed:</b> " . $conn->error);

$events          = array();
$eventsByDate    = array();
$eventTimestamps = array();

while ($row = $eRes->fetch_assoc()) {
    $events[] = $row;
    $date     = substr($row['event_time'], 0, 10);
    if (!isset($eventsByDate[$date])) $eventsByDate[$date] = array();
    $eventsByDate[$date][] = $row;
    $eventTimestamps[]     = strtotime($row['event_time']);
}
$eRes->free();
sort($eventTimestamps);

// Daily event counts
$dailyCounts = array();
foreach ($eventsByDate as $date => $evs) {
    $dailyCounts[$date] = count($evs);
}

// ── FETCH TRADES ──────────────────────────────────────────────────────────────
$tSql = "SELECT raw_trade_id AS id,
                pair_name,
                last_alert_time AS signal_time,
                trade_result
         FROM prediction_trade_data
         WHERE trade_result IN ('win', 'loss')
         ORDER BY last_alert_time ASC";

$tRes = $conn->query($tSql);
if (!$tRes) die("<b>Trade query failed:</b> " . $conn->error);

$rawTrades = array();
while ($row = $tRes->fetch_assoc()) $rawTrades[] = $row;
$tRes->free();

// ── MAIN LOOP ─────────────────────────────────────────────────────────────────
$regimeBins = array(
    'Low'    => array('w' => 0, 'l' => 0),
    'Medium' => array('w' => 0, 'l' => 0),
    'High'   => array('w' => 0, 'l' => 0),
);

$proxAll = array();
$proxRel = array();
foreach ($PROXIMITY_BINS as $label => $dummy) {
    $proxAll[$label] = array('w' => 0, 'l' => 0);
    $proxRel[$label] = array('w' => 0, 'l' => 0);
}

$byPair    = array();
$byMonth   = array();
$countDist = array();
$rows      = array();

foreach ($rawTrades as $trade) {
    $ts      = strtotime($trade['signal_time']);
    $date    = substr($trade['signal_time'], 0, 10);
    $month   = substr($trade['signal_time'], 0, 7);
    $pair    = $trade['pair_name'];
    $isWin   = ($trade['trade_result'] === 'win');

    $dayCount = isset($dailyCounts[$date]) ? $dailyCounts[$date] : 0;
    $regime   = getRegime($dayCount, $LOW_THRESHOLD, $HIGH_THRESHOLD);

    $nearAll = nearestMinAll($ts, $eventTimestamps);
    $nearRel = nearestMinRelevant($ts, $pair, $eventsByDate, $date, $keywordMap);

    // Regime bins
    if ($isWin) $regimeBins[$regime]['w']++;
    else        $regimeBins[$regime]['l']++;

    // Daily count distribution
    if (!isset($countDist[$dayCount])) $countDist[$dayCount] = array('w' => 0, 'l' => 0);
    if ($isWin) $countDist[$dayCount]['w']++;
    else        $countDist[$dayCount]['l']++;

    // Proximity — all events
    if ($nearAll !== null) {
        $bl = getProxBin($nearAll, $PROXIMITY_BINS);
        if ($isWin) $proxAll[$bl]['w']++;
        else        $proxAll[$bl]['l']++;
    }

    // Proximity — pair-relevant events
    if ($nearRel !== null) {
        $bl = getProxBin($nearRel, $PROXIMITY_BINS);
        if ($isWin) $proxRel[$bl]['w']++;
        else        $proxRel[$bl]['l']++;
    }

    // Per pair
    if (!isset($byPair[$pair])) {
        $byPair[$pair] = array(
            'Low'    => array('w' => 0, 'l' => 0),
            'Medium' => array('w' => 0, 'l' => 0),
            'High'   => array('w' => 0, 'l' => 0),
        );
    }
    if ($isWin) $byPair[$pair][$regime]['w']++;
    else        $byPair[$pair][$regime]['l']++;

    // Per month
    if (!isset($byMonth[$month])) $byMonth[$month] = array('w' => 0, 'l' => 0, 'events' => 0);
    if ($isWin) $byMonth[$month]['w']++;
    else        $byMonth[$month]['l']++;

    $rows[] = array(
        'id'        => $trade['id'],
        'pair'      => $pair,
        'time'      => $trade['signal_time'],
        'date'      => $date,
        'day_events'=> $dayCount,
        'regime'    => $regime,
        'near_all'  => $nearAll,
        'near_rel'  => $nearRel,
        'outcome'   => $isWin ? 'WIN' : 'LOSS',
    );
}

// Monthly event totals
foreach ($events as $ev) {
    $month = substr($ev['event_time'], 0, 7);
    if (isset($byMonth[$month])) $byMonth[$month]['events']++;
}

ksort($countDist);
ksort($byMonth);
ksort($byPair);

// Baselines
$medN      = $regimeBins['Medium']['w'] + $regimeBins['Medium']['l'];
$medWR     = $medN > 0 ? $regimeBins['Medium']['w'] / $medN : null;

$baseAllN  = $proxAll[$BASE_PROX_KEY]['w'] + $proxAll[$BASE_PROX_KEY]['l'];
$baseAllWR = $baseAllN > 0 ? $proxAll[$BASE_PROX_KEY]['w'] / $baseAllN : null;

$baseRelN  = $proxRel[$BASE_PROX_KEY]['w'] + $proxRel[$BASE_PROX_KEY]['l'];
$baseRelWR = $baseRelN > 0 ? $proxRel[$BASE_PROX_KEY]['w'] / $baseRelN : null;

$regOrder = array('Low', 'Medium', 'High');
$regCls   = array('Low' => 'low-row', 'Medium' => 'med-row', 'High' => 'high-row');
$regThresh = array(
    'Low'    => '≤ ' . $LOW_THRESHOLD . ' events/day',
    'Medium' => ($LOW_THRESHOLD + 1) . '–' . ($HIGH_THRESHOLD - 1) . ' events/day',
    'High'   => '≥ ' . $HIGH_THRESHOLD . ' events/day',
);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Volatility Regime vs Win Rate</title>
<style>
  body  { font-family:Arial,sans-serif; margin:30px; background:#f8f9fa; color:#222; }
  h1    { color:#4a148c; }
  h2    { color:#6a1b9a; margin-top:40px; border-bottom:2px solid #9c27b0; padding-bottom:6px; }
  table { border-collapse:collapse; width:100%; margin:16px 0 30px; background:#fff; }
  th    { background:#9c27b0; color:#fff; padding:10px 12px; text-align:left; font-size:13px; }
  td    { padding:8px 12px; border-bottom:1px solid #e0e0e0; font-size:13px; }
  tr:hover td { background:#f3e5f5; }
  .win  { color:#1b5e20; font-weight:bold; }
  .loss { color:#b71c1c; font-weight:bold; }
  .wr   { font-weight:bold; }
  .pos  { color:#2e7d32; font-weight:bold; }
  .neg  { color:#c62828; font-weight:bold; }
  .low-row  { background:#e8f5e9; }
  .med-row  { background:#fff3e0; }
  .high-row { background:#fce4ec; }
  .warn { background:#fff3e0; }
  .note { background:#f3e5f5; border-left:4px solid #ce93d8;
          padding:12px 16px; margin:20px 0; font-size:13px; }
  .box  { background:#fff; border:1px solid #ccc; border-radius:6px;
          padding:16px 24px; margin-bottom:24px; display:inline-block; }
</style>
</head>
<body>
<h1>⚡ Volatility Regime vs Win Rate</h1>

<div class="box">
  <strong>Trades (win+loss):</strong> <?= count($rows) ?> &nbsp;|&nbsp;
  <strong>Events indexed (impact≥<?= $MIN_IMPACT ?>):</strong> <?= count($events) ?><br>
  <strong>Low day:</strong> ≤<?= $LOW_THRESHOLD ?> events &nbsp;|&nbsp;
  <strong>High day:</strong> ≥<?= $HIGH_THRESHOLD ?> events &nbsp;|&nbsp;
  <strong>Medium:</strong> between
</div>

<div class="note">
  <strong>Volatility proxy:</strong> count of impact-<?= $MIN_IMPACT ?>+ events in <code>economic_events</code>
  on the same calendar date (UTC) as the trade.<br>
  <strong>Proximity:</strong> minutes from signal time to nearest high-impact event.<br>
  <strong>Pair-relevant</strong> proximity matches event names by currency keyword
  (e.g. AUDCAD → looks for "AUD", "RBA", "CAD", "BOC" etc in event_name).
</div>

<h2>1. Regime Summary — Low / Medium / High Volatility Days</h2>
<table>
  <tr>
    <th>Regime</th><th>Threshold</th><th>Trades</th><th>Wins</th><th>Losses</th>
    <th>Win Rate</th><th>95% CI</th><th>vs Medium</th>
  </tr>
<?php foreach ($regOrder as $reg):
    $w   = $regimeBins[$reg]['w'];
    $l   = $regimeBins[$reg]['l'];
    $n   = $w + $l;
    $wrv = $n > 0 ? $w / $n : 0;
    if ($reg !== 'Medium' && $medWR !== null && $n > 0) {
        $d    = ($wrv - $medWR) * 100;
        $dStr = sprintf('%+.1f pp', $d);
        $dCls = $d >= 0 ? 'pos' : 'neg';
    } else {
        $dStr = ($reg === 'Medium') ? 'baseline' : '—';
        $dCls = '';
    }
?>
  <tr class="<?= $regCls[$reg] ?>">
    <td><strong><?= $reg ?></strong></td>
    <td><?= $regThresh[$reg] ?></td>
    <td><?= $n ?></td>
    <td class="win"><?= $w ?></td>
    <td class="loss"><?= $l ?></td>
    <td class="wr"><?= wrPct($w, $n) ?></td>
    <td><?= wilsonCI($w, $n) ?></td>
    <td class="<?= $dCls ?>"><?= $dStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>2. Win Rate by Exact Daily Event Count</h2>
<table>
  <tr><th>Events on Day</th><th>Regime</th><th>Trades</th><th>Wins</th><th>Losses</th><th>Win Rate</th></tr>
<?php foreach ($countDist as $cnt => $d):
    $n = $d['w'] + $d['l'];
    $r = getRegime($cnt, $LOW_THRESHOLD, $HIGH_THRESHOLD);
?>
  <tr class="<?= $regCls[$r] ?>">
    <td><strong><?= $cnt ?></strong></td>
    <td><?= $r ?></td>
    <td><?= $n ?></td>
    <td class="win"><?= $d['w'] ?></td>
    <td class="loss"><?= $d['l'] ?></td>
    <td class="wr"><?= wrPct($d['w'], $n) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>3. Proximity to Nearest Event — All High-Impact Events</h2>
<table>
  <tr>
    <th>Window</th><th>Trades</th><th>Wins</th><th>Losses</th>
    <th>Win Rate</th><th>95% CI</th><th>vs &gt;4 hr Baseline</th>
  </tr>
<?php foreach ($proxAll as $label => $d):
    $n   = $d['w'] + $d['l'];
    $wrv = $n > 0 ? $d['w'] / $n : 0;
    if ($label !== $BASE_PROX_KEY && $baseAllWR !== null && $n > 0) {
        $diff    = ($wrv - $baseAllWR) * 100;
        $diffStr = sprintf('%+.1f pp', $diff);
        $diffCls = $diff >= 0 ? 'pos' : 'neg';
        $bgWarn  = ($diff <= -3) ? 'class="warn"' : '';
    } else {
        $diffStr = ($label === $BASE_PROX_KEY) ? 'baseline' : '—';
        $diffCls = '';
        $bgWarn  = '';
    }
?>
  <tr <?= $bgWarn ?>>
    <td><strong><?= $label ?></strong></td>
    <td><?= $n ?></td>
    <td class="win"><?= $d['w'] ?></td>
    <td class="loss"><?= $d['l'] ?></td>
    <td class="wr"><?= wrPct($d['w'], $n) ?></td>
    <td><?= wilsonCI($d['w'], $n) ?></td>
    <td class="<?= $diffCls ?>"><?= $diffStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>4. Proximity to Nearest PAIR-RELEVANT Event</h2>
<p style="font-size:13px;color:#555">
  Matched by currency keywords in event_name
  (e.g. AUDCAD: "AUD", "RBA", "CAD", "BOC", "Canada" etc).
  More precise measure of direct news risk than Section 3.
</p>
<table>
  <tr>
    <th>Window</th><th>Trades</th><th>Wins</th><th>Losses</th>
    <th>Win Rate</th><th>95% CI</th><th>vs &gt;4 hr Baseline</th>
  </tr>
<?php foreach ($proxRel as $label => $d):
    $n   = $d['w'] + $d['l'];
    $wrv = $n > 0 ? $d['w'] / $n : 0;
    if ($label !== $BASE_PROX_KEY && $baseRelWR !== null && $n > 0) {
        $diff    = ($wrv - $baseRelWR) * 100;
        $diffStr = sprintf('%+.1f pp', $diff);
        $diffCls = $diff >= 0 ? 'pos' : 'neg';
        $bgWarn  = ($diff <= -3) ? 'class="warn"' : '';
    } else {
        $diffStr = ($label === $BASE_PROX_KEY) ? 'baseline' : '—';
        $diffCls = '';
        $bgWarn  = '';
    }
?>
  <tr <?= $bgWarn ?>>
    <td><strong><?= $label ?></strong></td>
    <td><?= $n ?></td>
    <td class="win"><?= $d['w'] ?></td>
    <td class="loss"><?= $d['l'] ?></td>
    <td class="wr"><?= wrPct($d['w'], $n) ?></td>
    <td><?= wilsonCI($d['w'], $n) ?></td>
    <td class="<?= $diffCls ?>"><?= $diffStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>5. Per-Pair Volatility Sensitivity</h2>
<table>
  <tr>
    <th>Pair</th>
    <th>Low N</th><th>Low WR</th>
    <th>Med N</th><th>Med WR</th>
    <th>High N</th><th>High WR</th>
    <th>WR Range</th><th>Sensitivity</th>
  </tr>
<?php foreach ($byPair as $pair => $regData):
    $cells    = '';
    $wrValues = array();
    foreach ($regOrder as $reg) {
        $w = $regData[$reg]['w'];
        $l = $regData[$reg]['l'];
        $n = $w + $l;
        $wrv = ($n >= 5) ? round($w / $n * 100, 1) : null;
        if ($wrv !== null) $wrValues[] = $wrv;
        $cells .= '<td>' . $n . '</td><td class="wr">' .
                  ($wrv !== null ? $wrv . '%' : '—') . '</td>';
    }
    if (count($wrValues) >= 2) {
        $range = round(max($wrValues) - min($wrValues), 1);
        if ($range >= 10) {
            $sens = '<span style="color:#c62828;font-weight:bold">High</span>';
        } elseif ($range >= 5) {
            $sens = '<span style="color:#e65100">Medium</span>';
        } else {
            $sens = '<span style="color:#2e7d32">Low</span>';
        }
        $rangeStr = $range . ' pp';
    } else {
        $rangeStr = '—';
        $sens     = '—';
    }
?>
  <tr>
    <td><strong><?= $pair ?></strong></td>
    <?= $cells ?>
    <td><?= $rangeStr ?></td>
    <td><?= $sens ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>6. Monthly Win Rate vs Event Count</h2>
<table>
  <tr><th>Month</th><th>Events</th><th>Trades</th><th>Wins</th><th>Losses</th><th>Win Rate</th></tr>
<?php foreach ($byMonth as $month => $m):
    $n = $m['w'] + $m['l'];
    if ($n === 0) continue;
?>
  <tr>
    <td><strong><?= $month ?></strong></td>
    <td><?= $m['events'] ?></td>
    <td><?= $n ?></td>
    <td class="win"><?= $m['w'] ?></td>
    <td class="loss"><?= $m['l'] ?></td>
    <td class="wr"><?= wrPct($m['w'], $n) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>7. Trade Detail (first 200)</h2>
<table>
  <tr>
    <th>#</th><th>ID</th><th>Pair</th><th>Signal Time</th>
    <th>Day Events</th><th>Regime</th>
    <th>Nearest Any</th><th>Nearest Relevant</th><th>Outcome</th>
  </tr>
<?php
$shown = 0;
foreach ($rows as $i => $r):
    if ($shown >= 200) break;
    $cls  = $regCls[$r['regime']];
    $oCls = $r['outcome'] === 'WIN' ? 'win' : 'loss';
?>
  <tr class="<?= $cls ?>">
    <td><?= $i + 1 ?></td>
    <td><?= $r['id'] ?></td>
    <td><strong><?= $r['pair'] ?></strong></td>
    <td><?= $r['time'] ?></td>
    <td><?= $r['day_events'] ?></td>
    <td><strong><?= $r['regime'] ?></strong></td>
    <td><?= $r['near_all'] !== null ? $r['near_all'] . ' min' : '—' ?></td>
    <td><?= $r['near_rel'] !== null ? $r['near_rel'] . ' min' : '—' ?></td>
    <td class="<?= $oCls ?>"><?= $r['outcome'] ?></td>
  </tr>
<?php $shown++; endforeach; ?>
<?php if (count($rows) > 200): ?>
  <tr><td colspan="9" style="text-align:center;color:#888">
    ... <?= count($rows) - 200 ?> more rows not shown ...
  </td></tr>
<?php endif; ?>
</table>

<h2>8. Interpretation Guide</h2>
<table>
  <tr><th>Finding</th><th>If True</th><th>Action</th></tr>
  <tr>
    <td>Low-vol days WR significantly higher</td>
    <td>Pattern needs clean trending conditions</td>
    <td>Add filter: avoid days with ≥<?= $HIGH_THRESHOLD ?> high-impact events</td>
  </tr>
  <tr>
    <td>WR drops within 0–15 min of any event</td>
    <td>Immediate news distortion disrupts pattern</td>
    <td>Add 15-min blackout window before/after any impact-2+ event</td>
  </tr>
  <tr>
    <td>WR drops within 30 min of pair-relevant event</td>
    <td>Currency-specific news is most damaging</td>
    <td>Extend blackout to 30 min for pair-relevant events</td>
  </tr>
  <tr>
    <td>High-vol and low-vol WR are equal</td>
    <td>Pattern is regime-robust</td>
    <td>No event filter needed — strengthens the paper's robustness claim</td>
  </tr>
  <tr>
    <td>Specific pairs show &gt;10 pp WR swing across regimes</td>
    <td>Those pairs are news-sensitive</td>
    <td>Add pair-specific filter: skip sensitive pairs on high-event days</td>
  </tr>
</table>

<p style="font-size:12px;color:#999;margin-top:40px;">
  Generated: <?= date('Y-m-d H:i:s') ?> UTC &nbsp;|&nbsp; CSP Research — Internal Use Only
</p>
</body>
</html>