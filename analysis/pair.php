<?php
/**
 * ANALYSIS 2: Pair Correlation Clusters vs Win Rate
 * Schema: prediction_trade_data
 * PHP 7 compatible | Uses db.php (mysqli)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once __DIR__ . '/../db.php'; // Root public_html/db.php — provides $conn

// ── PARAMETERS ────────────────────────────────────────────────────────────────
$WINDOW_MINUTES = 5; // "simultaneous" = signals within this many minutes of each other

// ── CORRELATION CLUSTERS ──────────────────────────────────────────────────────
$clusters = array(
    'AUD' => array('AUDUSD','AUDCAD','AUDCHF','AUDJPY','EURAUD','GBPAUD'),
    'JPY' => array('USDJPY','EURJPY','GBPJPY','AUDJPY','CADJPY','CHFJPY'),
    'GBP' => array('GBPUSD','GBPCAD','GBPCHF','GBPJPY','GBPAUD','EURGBP'),
    'EUR' => array('EURUSD','EURJPY','EURCHF','EURAUD','EURCAD','EURGBP'),
    'CAD' => array('USDCAD','AUDCAD','GBPCAD','EURCAD','CADJPY'),
    'CHF' => array('USDCHF','AUDCHF','GBPCHF','EURCHF','CHFJPY'),
    'USD' => array('EURUSD','GBPUSD','AUDUSD','USDCAD','USDCHF','USDJPY'),
);

function getPairClusters($pair, $clusters) {
    $found = array();
    foreach ($clusters as $name => $pairs) {
        if (in_array($pair, $pairs)) $found[] = $name;
    }
    return $found;
}

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

function clusterImplication($d) {
    if ($d > 3)  return '✅ Cluster boosts edge — direction confirmed by multiple pairs';
    if ($d < -3) return '⚠️ Cluster hurts edge — correlated noise, prefer solo signals';
    return '➡️ Neutral — co-signals do not meaningfully change the edge';
}

// ── FETCH TRADES ──────────────────────────────────────────────────────────────
$sql = "SELECT raw_trade_id AS id,
               pair_name,
               last_alert_time AS signal_time,
               trade_result
        FROM prediction_trade_data
        WHERE trade_result IN ('win', 'loss')
        ORDER BY last_alert_time ASC";

$res = $conn->query($sql);
if (!$res) die("<b>Query failed:</b> " . $conn->error);

$rawTrades = array();
while ($row = $res->fetch_assoc()) $rawTrades[] = $row;
$res->free();

$total = count($rawTrades);

// Build list with timestamps and cluster membership
$tradeList = array();
foreach ($rawTrades as $t) {
    $tradeList[] = array(
        'id'       => $t['id'],
        'pair'     => $t['pair_name'],
        'ts'       => strtotime($t['signal_time']),
        'time'     => $t['signal_time'],
        'isWin'    => ($t['trade_result'] === 'win'),
        'clusters' => getPairClusters($t['pair_name'], $clusters),
    );
}

$windowSec = $WINDOW_MINUTES * 60;

// ── TAG EACH TRADE AS SOLO OR CLUSTER ────────────────────────────────────────
$taggedTrades = array();
foreach ($tradeList as $i => $trade) {
    $coSignals = array();
    foreach ($tradeList as $j => $other) {
        if ($i === $j) continue;
        if ($other['pair'] === $trade['pair']) continue;
        if (abs($trade['ts'] - $other['ts']) > $windowSec) continue;
        $shared = array_intersect($trade['clusters'], $other['clusters']);
        if (!empty($shared)) {
            $coSignals[] = array(
                'pair'     => $other['pair'],
                'isWin'    => $other['isWin'],
                'clusters' => array_values($shared),
            );
        }
    }
    $tagged               = $trade;
    $tagged['mode']       = empty($coSignals) ? 'Solo' : 'Cluster';
    $tagged['co_count']   = count($coSignals);
    $tagged['co_signals'] = $coSignals;
    $taggedTrades[]       = $tagged;
}

// ── AGGREGATE: Solo vs Cluster ────────────────────────────────────────────────
$modes = array(
    'Solo'    => array('w' => 0, 'l' => 0),
    'Cluster' => array('w' => 0, 'l' => 0),
);
foreach ($taggedTrades as $t) {
    if ($t['isWin']) $modes[$t['mode']]['w']++;
    else             $modes[$t['mode']]['l']++;
}

// ── AGGREGATE: Per currency cluster ──────────────────────────────────────────
$byCluster = array();
foreach ($clusters as $clName => $dummy) {
    $byCluster[$clName] = array(
        'solo'    => array('w' => 0, 'l' => 0),
        'cluster' => array('w' => 0, 'l' => 0),
    );
}
foreach ($taggedTrades as $t) {
    $modeKey = strtolower($t['mode']);
    foreach ($t['clusters'] as $cl) {
        if (!isset($byCluster[$cl])) continue;
        if ($t['isWin']) $byCluster[$cl][$modeKey]['w']++;
        else             $byCluster[$cl][$modeKey]['l']++;
    }
}

// ── AGGREGATE: Per pair ───────────────────────────────────────────────────────
$byPair = array();
foreach ($taggedTrades as $t) {
    $pair    = $t['pair'];
    $modeKey = strtolower($t['mode']);
    if (!isset($byPair[$pair])) {
        $byPair[$pair] = array(
            'solo'    => array('w' => 0, 'l' => 0),
            'cluster' => array('w' => 0, 'l' => 0),
        );
    }
    if ($t['isWin']) $byPair[$pair][$modeKey]['w']++;
    else             $byPair[$pair][$modeKey]['l']++;
}

// ── HEAD-TO-HEAD ──────────────────────────────────────────────────────────────
$h2h  = array();
$seen = array();
foreach ($taggedTrades as $t) {
    if ($t['mode'] !== 'Cluster') continue;
    foreach ($t['co_signals'] as $co) {
        $pA      = $t['pair'];
        $pB      = $co['pair'];
        $uniqKey = $t['id'] . '_' . $pB;
        if (isset($seen[$uniqKey])) continue;
        $seen[$uniqKey] = true;
        if (!isset($h2h[$pA][$pB])) {
            $h2h[$pA][$pB] = array('a_w' => 0, 'a_l' => 0, 'b_w' => 0, 'b_l' => 0, 'total' => 0);
        }
        $h2h[$pA][$pB]['total']++;
        if ($t['isWin'])     $h2h[$pA][$pB]['a_w']++;
        else                 $h2h[$pA][$pB]['a_l']++;
        if ($co['isWin'])    $h2h[$pA][$pB]['b_w']++;
        else                 $h2h[$pA][$pB]['b_l']++;
    }
}

// Solo baseline WR
$soloN  = $modes['Solo']['w'] + $modes['Solo']['l'];
$soloWR = $soloN > 0 ? $modes['Solo']['w'] / $soloN : null;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Pair Correlation Clusters vs Win Rate</title>
<style>
  body  { font-family:Arial,sans-serif; margin:30px; background:#f8f9fa; color:#222; }
  h1    { color:#1b5e20; }
  h2    { color:#2e7d32; margin-top:40px; border-bottom:2px solid #4caf50; padding-bottom:6px; }
  table { border-collapse:collapse; width:100%; margin:16px 0 30px; background:#fff; }
  th    { background:#4caf50; color:#fff; padding:10px 12px; text-align:left; font-size:13px; }
  td    { padding:8px 12px; border-bottom:1px solid #e0e0e0; font-size:13px; }
  tr:hover td { background:#f1f8e9; }
  .win  { color:#1b5e20; font-weight:bold; }
  .loss { color:#b71c1c; font-weight:bold; }
  .wr   { font-weight:bold; }
  .pos  { color:#2e7d32; font-weight:bold; }
  .neg  { color:#c62828; font-weight:bold; }
  .note { background:#f9fbe7; border-left:4px solid #aed581;
          padding:12px 16px; margin:20px 0; font-size:13px; }
  .box  { background:#fff; border:1px solid #ccc; border-radius:6px;
          padding:16px 24px; margin-bottom:24px; display:inline-block; }
  .tag  { display:inline-block; background:#e8f5e9; border:1px solid #a5d6a7;
          border-radius:3px; padding:1px 6px; margin:1px; font-size:11px; }
</style>
</head>
<body>
<h1>🔗 Pair Correlation Clusters vs Win Rate</h1>

<div class="box">
  <strong>Total trades (win+loss):</strong> <?= $total ?> &nbsp;|&nbsp;
  <strong>Simultaneous window:</strong> ±<?= $WINDOW_MINUTES ?> min
</div>

<div class="note">
  <strong>Cluster</strong> = another correlated pair fired a signal within ±<?= $WINDOW_MINUTES ?> minutes.<br>
  <strong>Solo</strong> = no correlated pair fired at the same time.<br>
  Correlation defined by shared currency (e.g. AUDJPY and AUDUSD → both in AUD cluster).
</div>

<h2>1. Solo vs Cluster — Overall Win Rate</h2>
<table>
  <tr>
    <th>Mode</th><th>Trades</th><th>Wins</th><th>Losses</th>
    <th>Win Rate</th><th>95% CI</th><th>vs Solo</th>
  </tr>
<?php foreach ($modes as $mode => $m):
    $n   = $m['w'] + $m['l'];
    $wrv = $n > 0 ? $m['w'] / $n : 0;
    if ($mode !== 'Solo' && $soloWR !== null && $n > 0) {
        $d    = ($wrv - $soloWR) * 100;
        $dStr = sprintf('%+.1f pp', $d);
        $dCls = $d >= 0 ? 'pos' : 'neg';
    } else {
        $dStr = ($mode === 'Solo') ? 'baseline' : '—';
        $dCls = '';
    }
?>
  <tr>
    <td><strong><?= $mode ?></strong></td>
    <td><?= $n ?></td>
    <td class="win"><?= $m['w'] ?></td>
    <td class="loss"><?= $m['l'] ?></td>
    <td class="wr"><?= wrPct($m['w'], $n) ?></td>
    <td><?= wilsonCI($m['w'], $n) ?></td>
    <td class="<?= $dCls ?>"><?= $dStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>2. Per Currency Cluster — Solo vs Cluster Win Rate</h2>
<table>
  <tr>
    <th>Cluster</th><th>Pairs</th>
    <th>Solo N</th><th>Solo WR</th>
    <th>Cluster N</th><th>Cluster WR</th>
    <th>Diff</th><th>Implication</th>
  </tr>
<?php foreach ($byCluster as $cl => $data):
    $sn = $data['solo']['w']    + $data['solo']['l'];
    $cn = $data['cluster']['w'] + $data['cluster']['l'];
    $d  = ($sn > 0 && $cn > 0)
          ? ($data['cluster']['w'] / $cn - $data['solo']['w'] / $sn) * 100
          : null;
    $dStr = $d !== null ? sprintf('%+.1f pp', $d) : '—';
    $dCls = ($d !== null && $d >= 0) ? 'pos' : 'neg';
    $impl = $d !== null ? clusterImplication($d) : '—';
?>
  <tr>
    <td><strong><?= $cl ?></strong></td>
    <td style="font-size:11px;color:#555"><?= implode(', ', $clusters[$cl]) ?></td>
    <td><?= $sn ?></td>
    <td class="wr"><?= wrPct($data['solo']['w'], $sn) ?></td>
    <td><?= $cn ?></td>
    <td class="wr"><?= wrPct($data['cluster']['w'], $cn) ?></td>
    <td class="<?= $dCls ?>"><?= $dStr ?></td>
    <td style="font-size:12px"><?= $impl ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>3. Per Pair — Solo vs Cluster Win Rate</h2>
<table>
  <tr>
    <th>Pair</th><th>Clusters</th>
    <th>Solo N</th><th>Solo WR</th><th>Solo CI</th>
    <th>Cluster N</th><th>Cluster WR</th><th>Cluster CI</th>
    <th>Diff</th>
  </tr>
<?php
ksort($byPair);
foreach ($byPair as $pair => $data):
    $sn   = $data['solo']['w']    + $data['solo']['l'];
    $cn   = $data['cluster']['w'] + $data['cluster']['l'];
    $d    = ($sn > 0 && $cn > 0)
            ? ($data['cluster']['w'] / $cn - $data['solo']['w'] / $sn) * 100
            : null;
    $dStr = $d !== null ? sprintf('%+.1f pp', $d) : '—';
    $dCls = ($d !== null && $d >= 0) ? 'pos' : 'neg';
    $tagHtml = '';
    foreach (getPairClusters($pair, $clusters) as $cName) {
        $tagHtml .= "<span class='tag'>$cName</span>";
    }
?>
  <tr>
    <td><strong><?= $pair ?></strong></td>
    <td><?= $tagHtml ?></td>
    <td><?= $sn ?></td>
    <td class="wr"><?= wrPct($data['solo']['w'], $sn) ?></td>
    <td><?= wilsonCI($data['solo']['w'], $sn) ?></td>
    <td><?= $cn ?></td>
    <td class="wr"><?= wrPct($data['cluster']['w'], $cn) ?></td>
    <td><?= wilsonCI($data['cluster']['w'], $cn) ?></td>
    <td class="<?= $dCls ?>"><?= $dStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>4. Head-to-Head: When Two Correlated Pairs Both Fire</h2>
<p style="font-size:13px;color:#555">
  When Pair A and Pair B both signal within ±<?= $WINDOW_MINUTES ?> min, which one wins more?
  Only combinations with ≥3 co-occurrences shown.
</p>
<table>
  <tr>
    <th>Pair A</th><th>Pair B</th><th>Co-signals</th>
    <th>A Win Rate</th><th>B Win Rate</th>
    <th>~Both Win</th><th>~Both Lose</th><th>Prefer</th>
  </tr>
<?php
$h2hRows = array();
foreach ($h2h as $pA => $others) {
    foreach ($others as $pB => $d) {
        if ($d['total'] < 3) continue;
        $row       = $d;
        $row['pA'] = $pA;
        $row['pB'] = $pB;
        $h2hRows[] = $row;
    }
}
usort($h2hRows, function($a, $b) { return $b['total'] - $a['total']; });

if (empty($h2hRows)) {
    echo "<tr><td colspan='8' style='text-align:center;color:#999'>
        No co-occurring pairs with ≥3 observations in ±{$WINDOW_MINUTES} min window.
        Try increasing \$WINDOW_MINUTES above.
    </td></tr>";
} else {
    foreach ($h2hRows as $r) {
        $n    = $r['total'];
        $awrv = $n > 0 ? $r['a_w'] / $n : 0;
        $bwrv = $n > 0 ? $r['b_w'] / $n : 0;
        $diff = abs($awrv - $bwrv);
        if ($diff >= 0.05) {
            $prefer = $awrv > $bwrv
                ? "<strong style='color:#2e7d32'>{$r['pA']}</strong>"
                : "<strong style='color:#2e7d32'>{$r['pB']}</strong>";
        } elseif ($diff < 0.02) {
            $prefer = 'Equivalent';
        } else {
            $prefer = $awrv > $bwrv ? $r['pA'] : $r['pB'];
        }
        echo "<tr>
            <td><strong>{$r['pA']}</strong></td>
            <td><strong>{$r['pB']}</strong></td>
            <td>{$n}</td>
            <td class='wr'>" . round($awrv * 100, 1) . "%</td>
            <td class='wr'>" . round($bwrv * 100, 1) . "%</td>
            <td style='color:#555'>~" . round($awrv * $bwrv * 100, 1) . "%</td>
            <td style='color:#555'>~" . round((1 - $awrv) * (1 - $bwrv) * 100, 1) . "%</td>
            <td>{$prefer}</td>
        </tr>";
    }
}
?>
</table>

<h2>5. Portfolio Risk Rules</h2>
<table>
  <tr><th>Scenario</th><th>Risk Level</th><th>Recommended Action</th></tr>
  <tr>
    <td>Cluster WR significantly &gt; Solo WR</td>
    <td style="color:#2e7d32">Lower</td>
    <td>Both trades acceptable — multi-pair confirmation adds conviction</td>
  </tr>
  <tr>
    <td>Cluster WR significantly &lt; Solo WR</td>
    <td style="color:#e65100">Medium</td>
    <td>Prefer the higher-performing pair; skip the weaker co-signal</td>
  </tr>
  <tr>
    <td>2+ correlated pairs fire same direction</td>
    <td style="color:#e65100">Medium</td>
    <td>Halve stake on each — correlated losses compound drawdown</td>
  </tr>
  <tr>
    <td>2+ correlated pairs fire opposite directions</td>
    <td style="color:#c62828">High</td>
    <td>Skip both — no dominant direction in the cluster</td>
  </tr>
</table>

<h2>6. Cluster Trade Log (first 150)</h2>
<table>
  <tr>
    <th>#</th><th>ID</th><th>Pair</th><th>Signal Time</th>
    <th>Mode</th><th>Co-Signals</th><th>Outcome</th>
  </tr>
<?php
$shown = 0;
foreach ($taggedTrades as $i => $t):
    if ($shown >= 150) break;
    $coStr = '';
    foreach ($t['co_signals'] as $co) {
        $oStr   = $co['isWin']
            ? "<span class='win'>W</span>"
            : "<span class='loss'>L</span>";
        $clStr  = implode(',', $co['clusters']);
        $coStr .= "<span style='font-size:11px'>{$co['pair']}[$clStr]=$oStr</span> ";
    }
    $oCell  = $t['isWin']
        ? "<span class='win'>WIN</span>"
        : "<span class='loss'>LOSS</span>";
    $mStyle = $t['mode'] === 'Cluster' ? 'color:#1565c0;font-weight:bold' : '';
?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= $t['id'] ?></td>
    <td><strong><?= $t['pair'] ?></strong></td>
    <td><?= $t['time'] ?></td>
    <td style="<?= $mStyle ?>"><?= $t['mode'] ?></td>
    <td><?= $coStr ?: '—' ?></td>
    <td><?= $oCell ?></td>
  </tr>
<?php $shown++; endforeach; ?>
<?php if (count($taggedTrades) > 150): ?>
  <tr><td colspan="7" style="text-align:center;color:#888">
    ... <?= count($taggedTrades) - 150 ?> more rows not shown ...
  </td></tr>
<?php endif; ?>
</table>

<p style="font-size:12px;color:#999;margin-top:40px;">
  Generated: <?= date('Y-m-d H:i:s') ?> UTC &nbsp;|&nbsp; CSP Research — Internal Use Only
</p>
</body>
</html>