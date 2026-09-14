<?php
/**
 * rate_day_analysis.php
 * Simple, PHP 7.2+ compatible. No arrow functions, no complex loops.
 * Shows:
 *  A) Explicit W/L for Day –1 / Day 0 / Day +1 per central bank
 *  B) Resolution time vs news event proximity (wins vs losses near news)
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(120);
ini_set('memory_limit', '256M');

// ── DB ───────────────────────────────────────────────────────────────────────
$dbPath = '';
$candidates = [
    __DIR__ . '/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
];
foreach ($candidates as $p) {
    if (file_exists($p)) { $dbPath = $p; break; }
}
if ($dbPath) {
    require_once $dbPath;
} else {
    die('<h2 style="color:red;font-family:monospace">db.php not found. Place this file next to db.php.</h2>');
}
if (!isset($conn) || $conn->connect_error) {
    die('<h2 style="color:red;font-family:monospace">DB error: ' . ($conn->connect_error ?? 'no $conn') . '</h2>');
}

$istTZ = new DateTimeZone('Asia/Kolkata');

// ── Rate event names & currency map ─────────────────────────────────────────
$RATE_NAMES = array(
    'USD Federal Funds Rate'    => 'USD',
    'GBP Official Bank Rate'    => 'GBP',
    'EUR Main Refinancing Rate' => 'EUR',
    'CAD Overnight Rate'        => 'CAD',
    'AUD Cash Rate'             => 'AUD',
    'Cash Rate'                 => 'AUD',
    'Official Cash Rate'        => 'NZD',
    'NZD Official Cash Rate'    => 'NZD',
    'CHF SNB Policy Rate'       => 'CHF',
    'JPY BOJ Policy Rate'       => 'JPY',
);
$CB_LABEL = array(
    'USD' => 'Fed (FOMC)', 'GBP' => 'BOE', 'EUR' => 'ECB',
    'CAD' => 'BOC',        'AUD' => 'RBA', 'NZD' => 'RBNZ',
    'CHF' => 'SNB',        'JPY' => 'BOJ',
);

// Build escaped name list
$nameParts = array();
foreach (array_keys($RATE_NAMES) as $n) {
    $nameParts[] = "'" . $conn->real_escape_string($n) . "'";
}
$nameList = implode(',', $nameParts);

// ── Pull rate events ─────────────────────────────────────────────────────────
$rateEvents = array();   // [ date_ist => [ ['cb'=>'GBP','name'=>'...'], ... ] ]
$allRateDates = array(); // flat set of date strings

$rRes = $conn->query(
    "SELECT event_name, event_time FROM economic_events
     WHERE event_name IN ($nameList) AND impact='3'
     ORDER BY event_time ASC"
);
if (!$rRes) die('Query error (rate events): ' . $conn->error);

while ($row = $rRes->fetch_assoc()) {
    $dt  = new DateTime($row['event_time'], $istTZ);
    $d   = $dt->format('Y-m-d');
    $cur = isset($RATE_NAMES[$row['event_name']]) ? $RATE_NAMES[$row['event_name']] : 'UNK';
    if (!isset($rateEvents[$d])) $rateEvents[$d] = array();
    // avoid duplicates per currency per day
    $already = false;
    foreach ($rateEvents[$d] as $ev) {
        if ($ev['cb'] === $cur) { $already = true; break; }
    }
    if (!$already) {
        $rateEvents[$d][] = array('cb' => $cur, 'name' => $row['event_name'], 'time' => $row['event_time']);
    }
    $allRateDates[$d] = true;
}

// ── Pull ALL trades with outcomes ────────────────────────────────────────────
$trades = array();
$tRes = $conn->query(
    "SELECT p.raw_trade_id, p.pair_name, o.trade_result, o.win_loss_time
     FROM prediction_trade_data p
     INNER JOIN trade_outcome_details o ON p.raw_trade_id = o.raw_trade_id
     WHERE o.trade_result IN ('win','loss') AND o.win_loss_time IS NOT NULL
     ORDER BY o.win_loss_time ASC"
);
if (!$tRes) die('Query error (trades): ' . $conn->error);

while ($row = $tRes->fetch_assoc()) {
    $dt   = new DateTime($row['win_loss_time'], $istTZ);
    $pair = strtoupper($row['pair_name']);
    $trades[] = array(
        'id'     => (int)$row['raw_trade_id'],
        'pair'   => $pair,
        'base'   => strtoupper(substr($pair, 0, 3)),
        'quote'  => strtoupper(substr($pair, 3, 3)),
        'win'    => ($row['trade_result'] === 'win') ? 1 : 0,
        'date'   => $dt->format('Y-m-d'),
        'time'   => $dt->format('H:i'),
        'ts'     => $dt->getTimestamp(),
        'dow'    => (int)$dt->format('N'),
        'dow_name' => $dt->format('D'),
        'wlt_full' => $row['win_loss_time'],
    );
}

// ── Pull economic news for proximity analysis ─────────────────────────────────
// We need event_time (IST) for all events to find nearest news to each trade resolution
$newsEvents = array();
$nRes = $conn->query(
    "SELECT event_name, impact, event_time FROM economic_events
     WHERE impact IN ('2','3')
     ORDER BY event_time ASC"
);
if ($nRes) {
    while ($row = $nRes->fetch_assoc()) {
        $dt = new DateTime($row['event_time'], $istTZ);
        $newsEvents[] = array(
            'name'   => $row['event_name'],
            'impact' => (int)$row['impact'],
            'ts'     => $dt->getTimestamp(),
            'time'   => $dt->format('H:i'),
            'date'   => $dt->format('Y-m-d'),
        );
    }
}

// ── Helper: stats ────────────────────────────────────────────────────────────
function calcStats(array $arr) {
    $n = count($arr);
    if ($n === 0) return array('n' => 0, 'w' => 0, 'l' => 0, 'wr' => null);
    $w = array_sum(array_column($arr, 'win'));
    return array('n' => $n, 'w' => $w, 'l' => $n - $w,
                 'wr' => round($w / $n * 100, 1));
}

function wrColor($wr) {
    if ($wr === null) return '#94a3b8';
    if ($wr >= 75) return '#10b981';
    if ($wr >= 60) return '#f0a93b';
    return '#ef4444';
}

function wrCell($s, $min = 5) {
    if ($s['n'] === 0 || $s['wr'] === null) {
        return '<td colspan="4" style="color:#94a3b8;text-align:center">—</td>';
    }
    $wr  = $s['wr'];
    $col = wrColor($wr);
    $sm  = $s['n'] < $min ? '<sup style="color:#94a3b8">*</sup>' : '';
    return "<td style='text-align:right;color:#10b981;font-weight:600'>{$s['w']}W</td>"
         . "<td style='text-align:right;color:#ef4444;font-weight:600'>{$s['l']}L</td>"
         . "<td style='text-align:right;color:#94a3b8'>{$s['n']}</td>"
         . "<td style='text-align:right;color:{$col};font-weight:700'>{$wr}%{$sm}</td>";
}

// ════════════════════════════════════════════════════════════════════════════
// SECTION A: –1d / 0d / +1d per central bank
// For each rate event date, bucket trades into:
//   pre1  = trades whose win_loss_time date = rate_date – 1 day
//   event = trades whose win_loss_time date = rate_date
//   post1 = trades whose win_loss_time date = rate_date + 1 day
// ════════════════════════════════════════════════════════════════════════════

// Build lookup: date => list of trades
$tradesByDate = array();
foreach ($trades as $t) {
    $tradesByDate[$t['date']][] = $t;
}

// For each currency: collect pre1/event/post1 trades
// A trade is "relevant" if its pair contains the CB currency
$cbBuckets = array(); // cb => ['pre1'=>[],'event'=>[],'post1'=>[]]
foreach ($RATE_NAMES as $name => $cur) {
    if (!isset($cbBuckets[$cur])) {
        $cbBuckets[$cur] = array('pre1'=>array(),'event'=>array(),'post1'=>array());
    }
}

foreach ($rateEvents as $rDate => $events) {
    $pre1Date  = (new DateTime($rDate))->modify('-1 day')->format('Y-m-d');
    $post1Date = (new DateTime($rDate))->modify('+1 day')->format('Y-m-d');

    foreach ($events as $ev) {
        $cur = $ev['cb'];
        if (!isset($cbBuckets[$cur])) continue;

        // pre1
        if (isset($tradesByDate[$pre1Date])) {
            foreach ($tradesByDate[$pre1Date] as $t) {
                if ($t['base'] === $cur || $t['quote'] === $cur) {
                    $cbBuckets[$cur]['pre1'][] = $t;
                }
            }
        }
        // event day
        if (isset($tradesByDate[$rDate])) {
            foreach ($tradesByDate[$rDate] as $t) {
                if ($t['base'] === $cur || $t['quote'] === $cur) {
                    $cbBuckets[$cur]['event'][] = $t;
                }
            }
        }
        // post1
        if (isset($tradesByDate[$post1Date])) {
            foreach ($tradesByDate[$post1Date] as $t) {
                if ($t['base'] === $cur || $t['quote'] === $cur) {
                    $cbBuckets[$cur]['post1'][] = $t;
                }
            }
        }
    }
}

// Remove duplicate trade IDs per bucket per CB
foreach ($cbBuckets as $cur => &$buckets) {
    foreach ($buckets as $key => &$arr) {
        $seen = array();
        $unique = array();
        foreach ($arr as $t) {
            if (!isset($seen[$t['id']])) {
                $seen[$t['id']] = true;
                $unique[] = $t;
            }
        }
        $arr = $unique;
    }
}
unset($buckets, $arr);

// Also compute overall across all CBs
$overallPre1 = $overallEvent = $overallPost1 = $overallOther = array();
$seenPre = $seenEv = $seenPost = array();

// Build flat sets of date ranges
$rateDatePre1  = array(); // date => true (one day before any rate event)
$rateDateEvent = array(); // date => true
$rateDatePost1 = array(); // date => true

foreach ($rateEvents as $rDate => $events) {
    $pre1Date  = (new DateTime($rDate))->modify('-1 day')->format('Y-m-d');
    $post1Date = (new DateTime($rDate))->modify('+1 day')->format('Y-m-d');
    $rateDatePre1[$pre1Date]   = true;
    $rateDateEvent[$rDate]     = true;
    $rateDatePost1[$post1Date] = true;
}

foreach ($trades as $t) {
    $d = $t['date'];
    if (isset($rateDatePre1[$d]))  $overallPre1[]  = $t;
    elseif (isset($rateDateEvent[$d])) $overallEvent[] = $t;
    elseif (isset($rateDatePost1[$d])) $overallPost1[] = $t;
    else $overallOther[] = $t;
}

// ════════════════════════════════════════════════════════════════════════════
// SECTION B: Resolution time vs News proximity
// For each trade, find the nearest impact 2/3 news event within ±4 hours
// Bucket by minutes-from-news: 0-15, 15-30, 30-60, 60-120, 120-240, >240
// ════════════════════════════════════════════════════════════════════════════
$newsProximityBuckets = array(
    '0-15 min'   => array('label'=>'0–15 min',   'trades'=>array()),
    '15-30 min'  => array('label'=>'15–30 min',  'trades'=>array()),
    '30-60 min'  => array('label'=>'30–60 min',  'trades'=>array()),
    '60-120 min' => array('label'=>'1–2 hours',  'trades'=>array()),
    '120-240 min'=> array('label'=>'2–4 hours',  'trades'=>array()),
    'far'        => array('label'=>'>4 hours',   'trades'=>array()),
);

// Sort news by ts for binary search
usort($newsEvents, function($a, $b) { return $a['ts'] - $b['ts']; });
$newsTsArr = array_column($newsEvents, 'ts');
$newsCount = count($newsTsArr);

// For each trade, find nearest news event (binary search for closest)
$tradeNewsDetails = array(); // trade_id => {nearest_news, offset_min, win}

foreach ($trades as $t) {
    if ($newsCount === 0) break;
    $ts = $t['ts'];

    // Binary search for insertion point
    $lo = 0; $hi = $newsCount - 1; $mid = 0;
    while ($lo <= $hi) {
        $mid = (int)(($lo + $hi) / 2);
        if ($newsTsArr[$mid] < $ts) $lo = $mid + 1;
        else $hi = $mid - 1;
    }
    // $lo is the insertion point; check $lo-1 and $lo for nearest
    $best = PHP_INT_MAX;
    $bestIdx = 0;
    foreach (array($lo - 1, $lo) as $idx) {
        if ($idx >= 0 && $idx < $newsCount) {
            $diff = abs($newsTsArr[$idx] - $ts);
            if ($diff < $best) { $best = $diff; $bestIdx = $idx; }
        }
    }

    $offsetMin = (int)round($best / 60);
    $nearestNews = $newsEvents[$bestIdx];

    // Store detail
    $tradeNewsDetails[$t['id']] = array(
        'offset_min'  => $offsetMin,
        'news_name'   => $nearestNews['name'],
        'news_impact' => $nearestNews['impact'],
        'news_time'   => $nearestNews['time'],
        'news_date'   => $nearestNews['date'],
        'win'         => $t['win'],
        'trade_time'  => $t['time'],
        'pair'        => $t['pair'],
    );

    // Only count if within 4 hours (240 min)
    if ($offsetMin <= 15)       $newsProximityBuckets['0-15 min']['trades'][]   = $t;
    elseif ($offsetMin <= 30)   $newsProximityBuckets['15-30 min']['trades'][]  = $t;
    elseif ($offsetMin <= 60)   $newsProximityBuckets['30-60 min']['trades'][]  = $t;
    elseif ($offsetMin <= 120)  $newsProximityBuckets['60-120 min']['trades'][] = $t;
    elseif ($offsetMin <= 240)  $newsProximityBuckets['120-240 min']['trades'][]= $t;
    else                        $newsProximityBuckets['far']['trades'][]         = $t;
}

// Also split wins vs losses in 0-30 min bucket for close inspection
$closeWins   = array_filter($newsProximityBuckets['0-15 min']['trades'],   function($t){ return $t['win'] === 1; });
$closeLosses = array_filter($newsProximityBuckets['0-15 min']['trades'],   function($t){ return $t['win'] === 0; });

// ════════════════════════════════════════════════════════════════════════════
// DAY-OF-WEEK baseline
// ════════════════════════════════════════════════════════════════════════════
$dowTrades = array();
$dowNames  = array(1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri');
foreach ($trades as $t) {
    if ($t['dow'] <= 5) $dowTrades[$t['dow']][] = $t;
}

// ════════════════════════════════════════════════════════════════════════════
// HTML OUTPUT
// ════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rate Day & News Proximity Analysis</title>
<style>
:root{--bg:#0b0f19;--bg2:#131b2e;--bg3:#1e293b;--blue:#3b82f6;--green:#10b981;--red:#ef4444;--amber:#f0a93b;--text:#f8fafc;--muted:#94a3b8;--border:#334155}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Outfit',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:24px;font-size:14px;line-height:1.5}
h1{font-size:22px;font-weight:800;background:linear-gradient(90deg,#60a5fa,#3b82f6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:4px}
.sub{color:var(--muted);font-size:12px;margin-bottom:26px}
h2{font-size:15px;font-weight:700;color:var(--blue);margin:28px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
h3{font-size:12px;font-weight:600;color:var(--muted);margin:16px 0 6px;text-transform:uppercase;letter-spacing:.5px}
table{width:100%;border-collapse:collapse;background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px;font-size:13px}
thead th{background:var(--bg3);color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px;padding:9px 12px;text-align:left;border-bottom:1px solid var(--border)}
tbody tr:hover{background:rgba(255,255,255,.025)}
td{padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
td sup{font-size:9px;color:var(--muted)}
.lb{font-weight:700}
.na{color:var(--muted);text-align:center}
.box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:18px}
.insight{background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.22);border-radius:9px;padding:13px 16px;margin:6px 0 20px;font-size:13px;line-height:1.8}
.insight b{color:var(--blue)}
.verdict{background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.22);border-radius:9px;padding:14px 18px;margin:10px 0 24px;font-size:13px;line-height:1.9}
.verdict b{color:var(--green)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:9px;padding:14px}
.cv{font-size:26px;font-weight:800;margin:4px 0}
.cl{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
.cs{font-size:11px;color:var(--muted);margin-top:3px}
.note{font-size:11px;color:var(--muted);margin:-12px 0 16px}
sup{font-size:9px}
.hr-g{background:rgba(16,185,129,.06)}
.hr-r{background:rgba(239,68,68,.06)}
.hr-y{background:rgba(240,169,59,.06)}
.bar{height:8px;background:rgba(255,255,255,.07);border-radius:4px;overflow:hidden;margin-top:6px}
.bar-fill{height:100%;border-radius:4px}
</style>
</head>
<body>

<h1>📊 Rate Day &amp; News Proximity Analysis</h1>
<div class="sub">
    <?= count($rateEvents) ?> rate event dates &nbsp;·&nbsp;
    <?= count($trades) ?> trades &nbsp;·&nbsp;
    <?= count($newsEvents) ?> impact 2–3 news events &nbsp;·&nbsp;
    All times IST
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION A: OVERALL –1d / 0d / +1d
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>A — Overall: Win/Loss on Day –1, Day 0, Day +1 of Any Rate Decision</h2>
<div class="grid">
<?php
$overallCards = array(
    array('label'=>'⬅ Day –1 Before Decision', 'data'=>calcStats($overallPre1),  'sub'=>count($overallPre1).' trades'),
    array('label'=>'🏦 Day 0 — Decision Day',   'data'=>calcStats($overallEvent), 'sub'=>count($overallEvent).' trades'),
    array('label'=>'➡ Day +1 After Decision',   'data'=>calcStats($overallPost1), 'sub'=>count($overallPost1).' trades'),
    array('label'=>'📊 Normal Days (>±1d)',      'data'=>calcStats($overallOther), 'sub'=>count($overallOther).' trades'),
);
foreach ($overallCards as $card) {
    $s   = $card['data'];
    $wr  = $s['wr'];
    $col = wrColor($wr);
    $base = calcStats($overallOther);
    $diff = ($wr !== null && $base['wr'] !== null) ? round($wr - $base['wr'], 1) : null;
    $diffStr = '';
    if ($diff !== null && $card['label'] !== '📊 Normal Days (>±1d)') {
        $dc = $diff >= 0 ? '#10b981' : '#ef4444';
        $diffStr = "<div style='font-size:12px;color:{$dc};margin-top:4px'>" . ($diff>=0?'+':'') . "{$diff}pp vs baseline</div>";
    }
    echo "<div class='card'>
        <div class='cl'>{$card['label']}</div>
        <div class='cv' style='color:{$col}'>" . ($wr ?? '—') . "%</div>
        <div style='font-size:13px;margin-top:2px'>
            <span style='color:#10b981;font-weight:700'>{$s['w']}W</span> &nbsp;
            <span style='color:#ef4444;font-weight:700'>{$s['l']}L</span>
        </div>
        <div class='cs'>{$card['sub']}</div>
        {$diffStr}
    </div>";
}
?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION B: PER CENTRAL BANK — explicit W/L for –1d / 0d / +1d
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>B — Per Central Bank: Explicit W/L for Day –1 / Day 0 / Day +1</h2>
<p class="note">Relevant pairs only (e.g. GBP pairs for BOE). <sup>*</sup> = fewer than 5 trades.</p>
<table>
<thead>
<tr>
    <th rowspan="2">CB</th>
    <th rowspan="2">CCY</th>
    <th colspan="4" style="text-align:center;border-left:1px solid var(--border)">Day –1 (Before)</th>
    <th colspan="4" style="text-align:center;border-left:1px solid var(--border)">Day 0 (Decision Day)</th>
    <th colspan="4" style="text-align:center;border-left:1px solid var(--border)">Day +1 (After)</th>
    <th colspan="4" style="text-align:center;border-left:1px solid var(--border)">Normal Days</th>
</tr>
<tr>
    <th style="border-left:1px solid var(--border)">W</th><th>L</th><th>N</th><th>WR</th>
    <th style="border-left:1px solid var(--border)">W</th><th>L</th><th>N</th><th>WR</th>
    <th style="border-left:1px solid var(--border)">W</th><th>L</th><th>N</th><th>WR</th>
    <th style="border-left:1px solid var(--border)">W</th><th>L</th><th>N</th><th>WR</th>
</tr>
</thead>
<tbody>
<?php
// "Normal days" per CB = trades on that pair NOT within ±1d of its CB rate decision
$cbNormal = array();
foreach (array_keys($CB_LABEL) as $cur) {
    $cbNormal[$cur] = array();
}

// Build set of "within ±1d" dates per currency
$cbEventWindow = array(); // cur => set of date strings
foreach ($rateEvents as $rDate => $events) {
    foreach ($events as $ev) {
        $cur = $ev['cb'];
        $pre  = (new DateTime($rDate))->modify('-1 day')->format('Y-m-d');
        $post = (new DateTime($rDate))->modify('+1 day')->format('Y-m-d');
        $cbEventWindow[$cur][$pre]  = true;
        $cbEventWindow[$cur][$rDate]= true;
        $cbEventWindow[$cur][$post] = true;
    }
}

foreach ($trades as $t) {
    foreach (array('base','quote') as $side) {
        $cur = $t[$side];
        if (!isset($cbNormal[$cur])) continue;
        if (!isset($cbEventWindow[$cur][$t['date']])) {
            $cbNormal[$cur][] = $t;
        }
    }
}
// deduplicate normal
foreach ($cbNormal as $cur => &$arr) {
    $seen = array(); $u = array();
    foreach ($arr as $t) {
        if (!isset($seen[$t['id']])) { $seen[$t['id']]=true; $u[]=$t; }
    }
    $arr = $u;
}
unset($arr);

$cbOrder = array('USD','GBP','EUR','CAD','AUD','JPY','CHF','NZD');
foreach ($cbOrder as $cur) {
    if (!isset($cbBuckets[$cur])) continue;
    $sPre  = calcStats($cbBuckets[$cur]['pre1']);
    $sEv   = calcStats($cbBuckets[$cur]['event']);
    $sPost = calcStats($cbBuckets[$cur]['post1']);
    $sNorm = calcStats($cbNormal[$cur] ?? array());
    $label = isset($CB_LABEL[$cur]) ? $CB_LABEL[$cur] : $cur;

    // Highlight rows where post1 is notably bad
    $rowCls = '';
    if ($sPost['wr'] !== null && $sNorm['wr'] !== null) {
        $rowCls = ($sPost['wr'] < $sNorm['wr'] - 10) ? ' class="hr-r"' : '';
    }

    echo "<tr{$rowCls}>
        <td class='lb'>{$label}</td>
        <td style='color:var(--blue)'>{$cur}</td>
        " . wrCell($sPre, 5)
        . wrCell($sEv, 5)
        . wrCell($sPost, 5)
        . wrCell($sNorm, 15)
        . "</tr>";
}
?>
</tbody>
</table>

<div class="insight">
<?php
// Find biggest post1 losers
$biggestDrop = array();
foreach ($cbOrder as $cur) {
    if (!isset($cbBuckets[$cur])) continue;
    $sPost = calcStats($cbBuckets[$cur]['post1']);
    $sNorm = calcStats($cbNormal[$cur] ?? array());
    if ($sPost['wr'] !== null && $sNorm['wr'] !== null && $sPost['n'] >= 5) {
        $drop = round($sPost['wr'] - $sNorm['wr'], 1);
        $biggestDrop[$cur] = $drop;
    }
}
arsort($biggestDrop); // most negative last
$worst = array_slice($biggestDrop, 0, 3, true);
$lines = array();
foreach ($worst as $cur => $drop) {
    $label = isset($CB_LABEL[$cur]) ? $CB_LABEL[$cur] : $cur;
    $sPost = calcStats($cbBuckets[$cur]['post1']);
    $sNorm = calcStats($cbNormal[$cur] ?? array());
    $sign  = $drop >= 0 ? '+' : '';
    $lines[] = "<b>{$label} ({$cur}):</b> Day+1 = {$sPost['wr']}% vs normal {$sNorm['wr']}% → {$sign}{$drop}pp";
}
echo implode('<br>', $lines);
?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION C: DAY-OF-WEEK BASELINE
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>C — Day-of-Week Baseline (Is Friday Already Weak?)</h2>
<p class="note">Key confound check: BOE and ECB announce on Thursdays → day +1 = Friday.</p>
<table>
<thead>
<tr>
    <th>Day</th><th style="text-align:right">Wins</th>
    <th style="text-align:right">Losses</th>
    <th style="text-align:right">Total</th>
    <th style="text-align:right">Win Rate</th>
    <th>Typical CB Announcers</th>
</tr>
</thead>
<tbody>
<?php
$dowCBs = array(
    1 => '—',
    2 => 'RBA (Tue)',
    3 => 'BOC, Fed, RBNZ (Wed)',
    4 => 'BOE, ECB, SNB (Thu)',
    5 => 'BOJ (rare, Fri)',
);
$overallWR = calcStats($trades);
foreach ($dowNames as $d => $name) {
    $s   = calcStats($dowTrades[$d] ?? array());
    $wr  = $s['wr'];
    $col = wrColor($wr);
    $diff = ($wr !== null && $overallWR['wr'] !== null) ? round($wr - $overallWR['wr'], 1) : null;
    $diffStr = $diff !== null
        ? "<span style='color:" . ($diff>=0?'#10b981':'#ef4444') . ";font-size:12px'>" . ($diff>=0?'+':'') . "{$diff}pp</span>"
        : '';
    $trCls = $d === 5 ? ' class="hr-y"' : '';
    echo "<tr{$trCls}>
        <td class='lb'>{$name}</td>
        <td style='text-align:right;color:#10b981;font-weight:600'>{$s['w']}</td>
        <td style='text-align:right;color:#ef4444;font-weight:600'>{$s['l']}</td>
        <td style='text-align:right;color:var(--muted)'>{$s['n']}</td>
        <td style='text-align:right;color:{$col};font-weight:700'>{$wr}% {$diffStr}</td>
        <td style='color:var(--muted);font-size:12px'>{$dowCBs[$d]}</td>
    </tr>";
}
?>
</tbody>
</table>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION D: FRIDAY CONFOUND DECOMPOSITION
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>D — Friday Decomposition: Post-Rate-Decision vs Normal Friday</h2>
<p class="note">Isolates whether "day after rate decision" weakness = rate effect or just a general Friday effect.</p>

<?php
// Build Thursday rate date list → next day = Friday
$thuRateDates = array();
foreach ($rateEvents as $rDate => $events) {
    $dt  = new DateTime($rDate);
    if ((int)$dt->format('N') === 4) { // Thursday
        $nextFri = $dt->modify('+1 day')->format('Y-m-d');
        $thuRateDates[$nextFri] = true;
    }
}

$friAll = $friAfterRate = $friNormal = array();
foreach ($trades as $t) {
    if ($t['dow'] !== 5) continue;
    $friAll[] = $t;
    if (isset($thuRateDates[$t['date']])) $friAfterRate[] = $t;
    else                                  $friNormal[]    = $t;
}

$sFA  = calcStats($friAll);
$sFAR = calcStats($friAfterRate);
$sFN  = calcStats($friNormal);
$sAll = calcStats($trades);
?>
<div class="grid">
<?php
$friCards = array(
    array('All Fridays',                       $sFA,  ''),
    array('Fridays After Thursday CB Decision',$sFAR, 'BOE / ECB / SNB'),
    array('Normal Fridays (no CB Thur before)',$sFN,  'Pure Friday baseline'),
    array('Overall Baseline (all days)',        $sAll, ''),
);
foreach ($friCards as $fc) {
    list($label, $s, $sub) = $fc;
    $col = wrColor($s['wr']);
    echo "<div class='card'>
        <div class='cl'>{$label}</div>
        <div class='cv' style='color:{$col}'>{$s['wr']}%</div>
        <div style='font-size:13px;margin-top:2px'>
            <span style='color:#10b981;font-weight:700'>{$s['w']}W</span> &nbsp;
            <span style='color:#ef4444;font-weight:700'>{$s['l']}L</span> &nbsp;
            <span style='color:var(--muted);font-size:12px'>{$s['n']} trades</span>
        </div>
        " . ($sub ? "<div class='cs'>{$sub}</div>" : '') . "
    </div>";
}
?>
</div>

<div class="verdict">
<?php
$fridayNaturalEffect = ($sFN['wr'] !== null && $sAll['wr'] !== null)
    ? round($sFN['wr'] - $sAll['wr'], 1) : null;
$rateOnTopFri = ($sFAR['wr'] !== null && $sFN['wr'] !== null)
    ? round($sFAR['wr'] - $sFN['wr'], 1) : null;

echo "<b>Normal Friday effect vs baseline:</b> {$sFN['wr']}% vs {$sAll['wr']}% → ";
echo ($fridayNaturalEffect !== null ? ($fridayNaturalEffect>=0?'+':'').$fridayNaturalEffect.'pp' : '—') . "<br>";
echo "<b>Additional rate-event increment on those Fridays:</b> {$sFAR['wr']}% vs normal Friday {$sFN['wr']}% → ";
echo ($rateOnTopFri !== null ? ($rateOnTopFri>=0?'+':'').$rateOnTopFri.'pp' : '—') . "<br><br>";

if ($fridayNaturalEffect !== null && $rateOnTopFri !== null) {
    if (abs($fridayNaturalEffect) > 3 && abs($rateOnTopFri) <= 2) {
        echo "<b>VERDICT 🔶 Primarily a Friday confound.</b> Friday is already naturally weak by {$fridayNaturalEffect}pp. The additional rate-event effect ({$rateOnTopFri}pp) is negligible. Rule to apply: monitor Friday performance in general rather than filtering by rate event calendar.";
    } elseif (abs($fridayNaturalEffect) > 3 && $rateOnTopFri < -3) {
        echo "<b>VERDICT 🔴 Both effects are real and additive.</b> Fridays are naturally {$fridayNaturalEffect}pp weaker, AND Thursday rate decisions add another {$rateOnTopFri}pp suppression. Combined effect is genuine — original rule stands AND a general Friday caution applies.";
    } elseif (abs($fridayNaturalEffect) <= 2 && $rateOnTopFri < -4) {
        echo "<b>VERDICT 🟢 Rate-event effect is genuine, NOT a Friday confound.</b> Normal Fridays are essentially at baseline ({$fridayNaturalEffect}pp), but Fridays after a Thursday CB decision drop {$rateOnTopFri}pp further. Pure rate-event signal confirmed.";
    } else {
        echo "<b>VERDICT 🔵 Both effects are small.</b> Friday: {$fridayNaturalEffect}pp, rate-event increment: {$rateOnTopFri}pp. Neither crosses an actionable threshold individually.";
    }
}
?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION E: NEWS PROXIMITY — Win Rate by Distance from News Event
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>E — Resolution Time vs News Proximity: Does Resolving Near a News Event Hurt Win Rate?</h2>
<p class="note">
    For each trade, the time between its binary resolution and the nearest impact 2–3 news event is computed.
    Trades resolving <em>very close</em> to news may be caught in event-driven price spikes.
</p>
<table>
<thead>
<tr>
    <th>Distance to Nearest News (Impact 2–3)</th>
    <th style="text-align:right">Wins</th>
    <th style="text-align:right">Losses</th>
    <th style="text-align:right">Total</th>
    <th style="text-align:right">Win Rate</th>
    <th style="text-align:right">vs Baseline</th>
    <th>Interpretation</th>
</tr>
</thead>
<tbody>
<?php
$proximityLabels = array(
    '0-15 min'    => 'Within 0–15 min of news',
    '15-30 min'   => '15–30 min from news',
    '30-60 min'   => '30–60 min from news',
    '60-120 min'  => '1–2 hours from news',
    '120-240 min' => '2–4 hours from news',
    'far'         => 'More than 4 hours from news',
);
$proximityInterp = array(
    '0-15 min'    => 'Resolution during/right after news spike',
    '15-30 min'   => 'Post-news volatility window',
    '30-60 min'   => 'News aftermath, elevated vol',
    '60-120 min'  => 'Market digesting news',
    '120-240 min' => 'News influence fading',
    'far'         => 'Clean technical environment',
);
$sBase = calcStats($trades);
foreach ($newsProximityBuckets as $key => $bucket) {
    $s    = calcStats($bucket['trades']);
    $wr   = $s['wr'];
    $col  = wrColor($wr);
    $diff = ($wr !== null && $sBase['wr'] !== null) ? round($wr - $sBase['wr'], 1) : null;
    $diffStr = $diff !== null
        ? "<span style='color:".($diff>=0?'#10b981':'#ef4444')."'>" . ($diff>=0?'+':'') . "{$diff}pp</span>"
        : '—';
    $trCls = ($key === '0-15 min') ? ' class="hr-r"' : (($key === 'far') ? ' class="hr-g"' : '');
    echo "<tr{$trCls}>
        <td class='lb'>{$proximityLabels[$key]}</td>
        <td style='text-align:right;color:#10b981;font-weight:600'>{$s['w']}</td>
        <td style='text-align:right;color:#ef4444;font-weight:600'>{$s['l']}</td>
        <td style='text-align:right;color:var(--muted)'>{$s['n']}</td>
        <td style='text-align:right;color:{$col};font-weight:700'>" . ($wr ?? '—') . "%</td>
        <td style='text-align:right'>{$diffStr}</td>
        <td style='color:var(--muted);font-size:12px'>{$proximityInterp[$key]}</td>
    </tr>";
}
?>
</tbody>
</table>

<!-- News proximity + impact breakdown -->
<h3>Impact-Specific Proximity (Impact 3 events only)</h3>
<?php
// Recompute but only for impact-3 news
$newsImp3 = array_filter($newsEvents, function($n){ return $n['impact'] === 3; });
$newsImp3 = array_values($newsImp3);
$imp3Ts   = array_column($newsImp3, 'ts');
$imp3Count= count($imp3Ts);

$imp3Buckets = array(
    '0-30 min'   => array('label'=>'0–30 min of Impact-3','trades'=>array()),
    '30-60 min'  => array('label'=>'30–60 min','trades'=>array()),
    '60-120 min' => array('label'=>'1–2 hours','trades'=>array()),
    '120+ min'   => array('label'=>'>2 hours', 'trades'=>array()),
);

foreach ($trades as $t) {
    if ($imp3Count === 0) break;
    $ts = $t['ts'];
    $lo = 0; $hi = $imp3Count - 1;
    while ($lo <= $hi) {
        $mid = (int)(($lo+$hi)/2);
        if ($imp3Ts[$mid] < $ts) $lo=$mid+1; else $hi=$mid-1;
    }
    $best = PHP_INT_MAX;
    foreach (array($lo-1, $lo) as $idx) {
        if ($idx>=0 && $idx<$imp3Count) {
            $diff = abs($imp3Ts[$idx]-$ts);
            if ($diff < $best) $best = $diff;
        }
    }
    $om = (int)round($best/60);
    if ($om <= 30)       $imp3Buckets['0-30 min']['trades'][]  = $t;
    elseif ($om <= 60)   $imp3Buckets['30-60 min']['trades'][] = $t;
    elseif ($om <= 120)  $imp3Buckets['60-120 min']['trades'][]= $t;
    else                 $imp3Buckets['120+ min']['trades'][]   = $t;
}
?>
<div class="grid">
<?php
foreach ($imp3Buckets as $key => $bucket) {
    $s = calcStats($bucket['trades']);
    $col = wrColor($s['wr']);
    $diff = ($s['wr'] !== null && $sBase['wr'] !== null) ? round($s['wr'] - $sBase['wr'], 1) : null;
    $dc = ($diff !== null && $diff < 0) ? '#ef4444' : '#10b981';
    echo "<div class='card'>
        <div class='cl'>{$bucket['label']}</div>
        <div class='cv' style='color:{$col}'>" . ($s['wr'] ?? '—') . "%</div>
        <div style='font-size:13px;margin-top:2px'>
            <span style='color:#10b981;font-weight:700'>{$s['w']}W</span> &nbsp;
            <span style='color:#ef4444;font-weight:700'>{$s['l']}L</span>
        </div>
        <div class='cs'>{$s['n']} trades</div>"
        . ($diff !== null ? "<div style='font-size:11px;color:{$dc};margin-top:4px'>" . ($diff>=0?'+':'') . "{$diff}pp vs baseline</div>" : '')
        . "</div>";
}
?>
</div>

<div class="insight">
<?php
$s015 = calcStats($newsProximityBuckets['0-15 min']['trades']);
$sFar = calcStats($newsProximityBuckets['far']['trades']);
$gapStr = ($s015['wr'] !== null && $sFar['wr'] !== null)
    ? round($s015['wr'] - $sFar['wr'], 1) . 'pp' : '—';
echo "<b>Core finding:</b> Trades resolving within 15 min of a news event = <b style='color:" . wrColor($s015['wr']) . "'>{$s015['wr']}%</b> "
   . "({$s015['w']}W / {$s015['l']}L). Trades resolving >4 hours from any news = "
   . "<b style='color:" . wrColor($sFar['wr']) . "'>{$sFar['wr']}%</b> ({$sFar['w']}W / {$sFar['l']}L). "
   . "Gap: <b>{$gapStr}</b>.<br><br>";

if ($s015['wr'] !== null && $sFar['wr'] !== null) {
    $gap = round($s015['wr'] - $sFar['wr'], 1);
    if ($gap < -5) {
        echo "Trades resolving <em>near</em> news events produce materially worse outcomes. This supports avoiding binary entries whose likely expiry window overlaps with scheduled high-impact news — the news spike disrupts the pullback continuation the CSP pattern depends on.";
    } elseif ($gap > 5) {
        echo "Trades resolving near news actually perform <em>better</em>, suggesting post-news momentum assists CSP continuation signals. This is consistent with the post-announcement directional clarity discussed in the rate correlation analysis.";
    } else {
        echo "The gap ({$gapStr}) is within noise range — news proximity does not materially affect win rate in this dataset. The CSP pattern appears robust to the news calendar at this level of analysis.";
    }
}
?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION F: FINAL SUMMARY TABLE
     ══════════════════════════════════════════════════════════════════════════ -->
<h2>F — Summary: All Key Win Rates in One View</h2>
<table>
<thead>
<tr>
    <th>Scenario</th>
    <th style="text-align:right">W</th><th style="text-align:right">L</th>
    <th style="text-align:right">N</th><th style="text-align:right">WR</th>
    <th style="text-align:right">vs Baseline</th>
    <th>Actionable?</th>
</tr>
</thead>
<tbody>
<?php
$summaryRows = array(
    array('Overall Baseline (all trades)',      calcStats($trades),        true,  'Reference'),
    array('Day –1 before any rate decision',    calcStats($overallPre1),   false, 'Monitor'),
    array('Day 0 — rate decision day',          calcStats($overallEvent),  false, 'No rule needed'),
    array('Day +1 after any rate decision',     calcStats($overallPost1),  false, 'See CB breakdown'),
    array('Normal days (>±1d from rate)',       calcStats($overallOther),  false, 'Best environment'),
    array('BOE rate week — GBP pairs',          calcStats($cbBuckets['GBP']['post1'] ?? array()), false, 'Day+1: Avoid GBP?'),
    array('ECB rate week — EUR pairs',          calcStats($cbBuckets['EUR']['post1'] ?? array()), false, 'Day+1: Monitor EUR'),
    array('BOJ rate week — JPY pairs',          calcStats($cbBuckets['JPY']['post1'] ?? array()), false, 'Day+1: Avoid JPY?'),
    array('All Fridays',                        calcStats($friAll),        false, 'See confound result'),
    array('Fridays after Thu CB decision',      calcStats($friAfterRate),  false, 'Confound check'),
    array('Normal Fridays (no Thu CB before)',  calcStats($friNormal),     false, 'Pure Friday effect'),
    array('Resolution within 0–15 min of news',calcStats($newsProximityBuckets['0-15 min']['trades']), false, 'Avoid if possible'),
    array('Resolution >4 hours from news',      calcStats($newsProximityBuckets['far']['trades']),     false, 'Cleanest condition'),
);
$baseline = calcStats($trades);
foreach ($summaryRows as $row) {
    list($label, $s, $isRef, $action) = $row;
    $wr  = $s['wr'];
    $col = wrColor($wr);
    $diff = (!$isRef && $wr !== null && $baseline['wr'] !== null)
        ? round($wr - $baseline['wr'], 1) : null;
    $diffStr = $diff !== null
        ? "<span style='color:".($diff>=0?'#10b981':'#ef4444')."'>" . ($diff>=0?'+':'') . "{$diff}pp</span>"
        : ($isRef ? '<span style="color:var(--muted)">baseline</span>' : '—');
    $trCls = $isRef ? ' style="background:rgba(59,130,246,.06)"' : '';
    echo "<tr{$trCls}>
        <td class='lb'>{$label}</td>
        <td style='text-align:right;color:#10b981;font-weight:600'>{$s['w']}</td>
        <td style='text-align:right;color:#ef4444;font-weight:600'>{$s['l']}</td>
        <td style='text-align:right;color:var(--muted)'>{$s['n']}</td>
        <td style='text-align:right;color:{$col};font-weight:700'>" . ($wr ?? '—') . "%</td>
        <td style='text-align:right'>{$diffStr}</td>
        <td style='color:var(--muted);font-size:12px'>{$action}</td>
    </tr>";
}
?>
</tbody>
</table>

<div class="note" style="margin-top:12px">
    <sup>*</sup> &lt;5 trades = directional only &nbsp;|&nbsp;
    win_loss_time IST used for all resolution timestamps &nbsp;|&nbsp;
    News proximity uses impact 2–3 events only
</div>

</body>
</html>