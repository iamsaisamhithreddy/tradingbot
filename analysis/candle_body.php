<?php
/**
 * ANALYSIS 1: Candle Body Size vs Win Rate
 * Schema: prediction_trade_data + raw_trade_data
 * PHP 7 compatible | Uses db.php (mysqli)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

require_once __DIR__ . '/../db.php'; // Root public_html/db.php — provides $conn

// ── PARAMETERS ────────────────────────────────────────────────────────────────
$SMALL_THRESHOLD = 0.33;  // body/ATR <= this => Small
$LARGE_THRESHOLD = 0.67;  // body/ATR >= this => Large

// ── HELPERS ───────────────────────────────────────────────────────────────────
function getBodyBin($ratio, $small, $large) {
    if ($ratio <= $small) return 'Small';
    if ($ratio >= $large) return 'Large';
    return 'Medium';
}

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

function interpBin($bin) {
    if ($bin === 'Small')  return 'Weak confirmation — lower conviction continuation';
    if ($bin === 'Medium') return 'Baseline confirmation signal';
    if ($bin === 'Large')  return 'Strong confirmation candle — high conviction move';
    return '';
}

// ── FETCH TRADES (join raw candle data) ───────────────────────────────────────
// O1/C1/H1/L1 = confirmation candle (Phase C)
// H2-L5       = earlier candles used for ATR proxy
$sql = "SELECT p.raw_trade_id AS id,
               p.pair_name,
               p.last_alert_time AS signal_time,
               p.trade_result,
               r.O1 AS conf_open,
               r.C1 AS conf_close,
               r.H1, r.L1,
               r.H2, r.L2,
               r.H3, r.L3,
               r.H4, r.L4,
               r.H5, r.L5
        FROM prediction_trade_data p
        JOIN raw_trade_data r ON p.raw_trade_id = r.id
        WHERE p.trade_result IN ('win', 'loss')
        ORDER BY p.last_alert_time DESC";

$res = $conn->query($sql);
if (!$res) die("<b>Query failed:</b> " . $conn->error);

$trades = array();
while ($row = $res->fetch_assoc()) $trades[] = $row;
$res->free();

// ── MAIN LOOP ─────────────────────────────────────────────────────────────────
$bins   = array(
    'Small'  => array('w' => 0, 'l' => 0),
    'Medium' => array('w' => 0, 'l' => 0),
    'Large'  => array('w' => 0, 'l' => 0),
);
$byPair = array();
$rows   = array();
$ratios = array();

foreach ($trades as $trade) {
    $pair     = $trade['pair_name'];
    $sigTime  = $trade['signal_time'];
    $isWin    = ($trade['trade_result'] === 'win');

    // Confirmation candle body (Phase C = candle 1)
    $confBody = abs((float)$trade['conf_close'] - (float)$trade['conf_open']);

    // 5-candle ATR proxy: average of high-low ranges across all stored candles
    $ranges = array(
        (float)$trade['H1'] - (float)$trade['L1'],
        (float)$trade['H2'] - (float)$trade['L2'],
        (float)$trade['H3'] - (float)$trade['L3'],
        (float)$trade['H4'] - (float)$trade['L4'],
        (float)$trade['H5'] - (float)$trade['L5'],
    );
    $atr = array_sum($ranges) / count($ranges);

    if ($atr <= 0) continue;

    $bodyToAtr = $confBody / $atr;
    $bin       = getBodyBin($bodyToAtr, $SMALL_THRESHOLD, $LARGE_THRESHOLD);

    // Bins
    if ($isWin) $bins[$bin]['w']++;
    else        $bins[$bin]['l']++;

    // Per pair
    if (!isset($byPair[$pair])) {
        $byPair[$pair] = array(
            'Small'  => array('w' => 0, 'l' => 0),
            'Medium' => array('w' => 0, 'l' => 0),
            'Large'  => array('w' => 0, 'l' => 0),
        );
    }
    if ($isWin) $byPair[$pair][$bin]['w']++;
    else        $byPair[$pair][$bin]['l']++;

    $ratios[] = $bodyToAtr;

    $rows[] = array(
        'id'        => $trade['id'],
        'pair'      => $pair,
        'time'      => $sigTime,
        'conf_body' => round($confBody, 5),
        'atr'       => round($atr, 5),
        'body_atr'  => round($bodyToAtr, 3),
        'bin'       => $bin,
        'outcome'   => $isWin ? 'WIN' : 'LOSS',
    );
}

// Percentiles
sort($ratios);
$cnt = count($ratios);
$p33 = ($cnt > 0 && isset($ratios[(int)round($cnt * 0.33)])) ? $ratios[(int)round($cnt * 0.33)] : null;
$p67 = ($cnt > 0 && isset($ratios[(int)round($cnt * 0.67)])) ? $ratios[(int)round($cnt * 0.67)] : null;

// Medium baseline
$medN  = $bins['Medium']['w'] + $bins['Medium']['l'];
$medWR = $medN > 0 ? $bins['Medium']['w'] / $medN : null;

$binOrder = array('Small', 'Medium', 'Large');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Candle Body Size vs Win Rate</title>
<style>
  body  { font-family:Arial,sans-serif; margin:30px; background:#f8f9fa; color:#222; }
  h1    { color:#1a237e; }
  h2    { color:#283593; margin-top:40px; border-bottom:2px solid #3f51b5; padding-bottom:6px; }
  table { border-collapse:collapse; width:100%; margin:16px 0 30px; background:#fff; }
  th    { background:#3f51b5; color:#fff; padding:10px 12px; text-align:left; font-size:13px; }
  td    { padding:8px 12px; border-bottom:1px solid #e0e0e0; font-size:13px; }
  tr:hover td { background:#e8eaf6; }
  .win  { color:#2e7d32; font-weight:bold; }
  .loss { color:#c62828; font-weight:bold; }
  .wr   { font-weight:bold; }
  .pos  { color:#2e7d32; font-weight:bold; }
  .neg  { color:#c62828; font-weight:bold; }
  .small-bin  { background:#fff3e0; }
  .medium-bin { background:#e8f5e9; }
  .large-bin  { background:#e3f2fd; }
  .note { background:#fffde7; border-left:4px solid #f9a825;
          padding:12px 16px; margin:20px 0; font-size:13px; }
  .box  { background:#fff; border:1px solid #ccc; border-radius:6px;
          padding:16px 24px; margin-bottom:24px; display:inline-block; }
</style>
</head>
<body>

<h1>📊 Candle Body Size vs Win Rate</h1>

<div class="box">
  <strong>Trades processed:</strong> <?= count($rows) ?> &nbsp;|&nbsp;
  <strong>Candle source:</strong> raw_trade_data (O1/C1 = confirmation candle) &nbsp;|&nbsp;
  <strong>ATR:</strong> 5-candle high-low average
</div>

<div class="note">
  <strong>Body size</strong> = ABS(C1 − O1) of confirmation candle (Phase C).<br>
  <strong>ATR proxy</strong> = average of (H−L) across all 5 stored candles.<br>
  <strong>Small</strong> = body/ATR ≤ <?= $SMALL_THRESHOLD ?> &nbsp;|&nbsp;
  <strong>Large</strong> = body/ATR ≥ <?= $LARGE_THRESHOLD ?> &nbsp;|&nbsp;
  <strong>Medium</strong> = between.<br>
  <?php if ($p33 !== null && $p67 !== null): ?>
  <strong>Actual P33:</strong> <?= round($p33, 3) ?> &nbsp;|&nbsp;
  <strong>Actual P67:</strong> <?= round($p67, 3) ?> — use these to recalibrate thresholds.
  <?php endif; ?>
</div>

<h2>1. Overall Win Rate by Body Size</h2>
<table>
  <tr>
    <th>Bin</th><th>Trades (N)</th><th>Wins</th><th>Losses</th>
    <th>Win Rate</th><th>95% CI</th><th>vs Medium</th>
  </tr>
<?php foreach ($binOrder as $bin):
    $w  = $bins[$bin]['w'];
    $l  = $bins[$bin]['l'];
    $n  = $w + $l;
    $wrv = $n > 0 ? $w / $n : 0;
    if ($bin !== 'Medium' && $medWR !== null && $n > 0) {
        $d    = ($wrv - $medWR) * 100;
        $dStr = sprintf('%+.1f pp', $d);
        $dCls = $d >= 0 ? 'pos' : 'neg';
    } else {
        $dStr = ($bin === 'Medium') ? 'baseline' : '—';
        $dCls = '';
    }
    $cls = strtolower($bin) . '-bin';
?>
  <tr class="<?= $cls ?>">
    <td><strong><?= $bin ?></strong></td>
    <td><?= $n ?></td>
    <td class="win"><?= $w ?></td>
    <td class="loss"><?= $l ?></td>
    <td class="wr"><?= wrPct($w, $n) ?></td>
    <td><?= wilsonCI($w, $n) ?></td>
    <td class="<?= $dCls ?>"><?= $dStr ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>2. Interpretation</h2>
<table>
  <tr><th>Bin</th><th>N</th><th>Win Rate</th><th>95% CI</th><th>What it means</th></tr>
<?php foreach ($binOrder as $bin):
    $w = $bins[$bin]['w']; $l = $bins[$bin]['l']; $n = $w + $l;
?>
  <tr>
    <td><strong><?= $bin ?></strong></td>
    <td><?= $n ?></td>
    <td class="wr"><?= wrPct($w, $n) ?></td>
    <td><?= wilsonCI($w, $n) ?></td>
    <td><?= interpBin($bin) ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>3. Per-Pair Breakdown by Body Size</h2>
<table>
  <tr>
    <th>Pair</th>
    <th>Small N</th><th>Small WR</th>
    <th>Medium N</th><th>Medium WR</th>
    <th>Large N</th><th>Large WR</th>
    <th>Best Bin (n≥5)</th>
  </tr>
<?php
ksort($byPair);
foreach ($byPair as $pair => $pairBins):
    $bestBin = '—'; $bestWR = -1;
    $cells = '';
    foreach ($binOrder as $bin) {
        $w  = $pairBins[$bin]['w'];
        $l  = $pairBins[$bin]['l'];
        $n  = $w + $l;
        $wr = $n > 0 ? round($w / $n * 100, 1) : null;
        $cells .= '<td>' . $n . '</td><td>' .
                  ($wr !== null ? "<span class='wr'>{$wr}%</span>" : '—') .
                  '</td>';
        if ($wr !== null && $n >= 5 && $wr > $bestWR) {
            $bestWR  = $wr;
            $bestBin = "$bin ({$wr}%)";
        }
    }
?>
  <tr>
    <td><strong><?= $pair ?></strong></td>
    <?= $cells ?>
    <td><?= $bestBin ?></td>
  </tr>
<?php endforeach; ?>
</table>

<h2>4. Body/ATR Ratio — Percentile Distribution</h2>
<table><tr>
<?php for ($pct = 10; $pct <= 100; $pct += 10) echo "<th>P{$pct}</th>"; ?>
</tr><tr>
<?php
for ($pct = 10; $pct <= 100; $pct += 10) {
    $idx = (int)round($cnt * $pct / 100) - 1;
    if ($idx < 0) $idx = 0;
    $val = isset($ratios[$idx]) ? round($ratios[$idx], 3) : '—';
    echo "<td>$val</td>";
}
?>
</tr></table>
<p style="font-size:13px;color:#555;">
  Re-calibrate thresholds using P33 and P67 from your actual data above
  instead of the fixed 0.33 / 0.67 defaults.
</p>

<h2>5. Trade Detail</h2>
<table>
  <tr>
    <th>#</th><th>ID</th><th>Pair</th><th>Signal Time</th>
    <th>Body</th><th>ATR</th><th>Body/ATR</th><th>Bin</th><th>Outcome</th>
  </tr>
<?php
foreach ($rows as $i => $r):
    $cls  = strtolower($r['bin']) . '-bin';
    $oCls = $r['outcome'] === 'WIN' ? 'win' : 'loss';
?>
  <tr class="<?= $cls ?>">
    <td><?= $i + 1 ?></td>
    <td><?= $r['id'] ?></td>
    <td><strong><?= $r['pair'] ?></strong></td>
    <td><?= $r['time'] ?></td>
    <td><?= $r['conf_body'] ?></td>
    <td><?= $r['atr'] ?></td>
    <td><?= $r['body_atr'] ?></td>
    <td><strong><?= $r['bin'] ?></strong></td>
    <td class="<?= $oCls ?>"><?= $r['outcome'] ?></td>
  </tr>
<?php endforeach; ?>
</table>

<p style="font-size:12px;color:#999;margin-top:40px;">
  Generated: <?= date('Y-m-d H:i:s') ?> UTC &nbsp;|&nbsp; CSP Research — Internal Use Only
</p>
</body>
</html>