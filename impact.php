<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(300); // CSV loading can take a while


/**
 * Economic News Impact on Forex Pairs
 * Replicating Balduzzi, Elton & Green (2001) Table 2 methodology
 *
 * For each economic event × forex pair:
 *   - Price 5 min BEFORE announcement  → P_before
 *   - Price 30 min AFTER announcement  → P_after
 *   - Return = (P_after - P_before) / P_before × 100
 *
 * Aggregated by event_name across all occurrences.
 */

// ─── DB CONNECTION via your existing db.php ───────────────────────────────────
require_once __DIR__ . '/db.php';
// $conn is now a mysqli object

// ─── DATASET PATHS ────────────────────────────────────────────────────────────
// Adjust these to your actual cPanel absolute paths if needed
define('DS_OLD',  __DIR__ . '/dataset/JUN-2025 TO FEB-2026/');  // Aug 2025 – Feb 2026
define('DS_NEW',  __DIR__ . '/dataset/dataset/');               // May 2026 onwards

// Pairs to analyse
$PAIRS = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY',
];

// Window (minutes)
define('WIN_BEFORE', -5);   // 5 min before
define('WIN_AFTER',  30);   // 30 min after

// Min observations needed to show a result
define('MIN_OBS', 5);

// Impact filter: 1=Low, 2=Medium, 3=High  (null = all)
$IMPACT_FILTER = null; // show all, we'll group in output

// ─── HELPERS ─────────────────────────────────────────────────────────────────

/**
 * Convert CSV time string to Unix timestamp.
 * Handles formats like:
 *   "2025-08-11 14:30:00"
 *   "2025.08.11 14:30"
 *   Unix integer
 */
function toUnix(string $t): int {
    $t = trim($t);
    if (ctype_digit($t)) return (int)$t;
    // replace dots with dashes for date part
    $t = preg_replace('/^(\d{4})\.(\d{2})\.(\d{2})/', '$1-$2-$3', $t);
    return strtotime($t) ?: 0;
}

/**
 * Load a CSV file into a sorted array: [ unix_timestamp => close_price ]
 * Returns [] on failure.
 */
function loadCSV(string $path): array {
    if (!file_exists($path)) return [];
    $data = [];
    $fh = fopen($path, 'r');
    if (!$fh) return [];
    $header = fgetcsv($fh, 0, "\t");   // try tab first
    if (!$header || count($header) < 5) {
        rewind($fh);
        $header = fgetcsv($fh, 0, ",");
    }
    if (!$header) { fclose($fh); return []; }

    // Normalise header names
    $header = array_map('strtolower', array_map('trim', $header));
    $iTime  = array_search('time',  $header);
    $iClose = array_search('close', $header);
    if ($iTime === false || $iClose === false) { fclose($fh); return []; }

    // Detect delimiter by checking first data line
    $pos = ftell($fh);
    $line = fgets($fh);
    fseek($fh, $pos);
    $delim = (substr_count($line, "\t") >= 4) ? "\t" : ",";

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if (!isset($row[$iTime], $row[$iClose])) continue;
        $ts  = toUnix($row[$iTime]);
        $cl  = (float)$row[$iClose];
        if ($ts > 0 && $cl > 0) $data[$ts] = $cl;
    }
    fclose($fh);
    ksort($data);
    return $data;
}

/**
 * Find the closing price nearest to a given Unix timestamp (within $tolerance seconds).
 * Uses a simple linear scan on sorted keys — good enough for ~10k rows per file.
 */
function priceAt(array &$data, int $targetTs, int $tolerance = 300): ?float {
    if (empty($data)) return null;
    $keys = array_keys($data);
    $best = null;
    $bestDiff = PHP_INT_MAX;
    // Binary-search approximate position
    $lo = 0; $hi = count($keys) - 1;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        $diff = abs($keys[$mid] - $targetTs);
        if ($diff < $bestDiff) { $bestDiff = $diff; $best = $mid; }
        if ($keys[$mid] < $targetTs) $lo = $mid + 1;
        else $hi = $mid - 1;
    }
    if ($bestDiff <= $tolerance) return $data[$keys[$best]];
    return null;
}

/**
 * Given an event Unix timestamp, return which CSV file(s) to look in.
 * Old folder: Aug 2025 – Feb 2026  (< 2026-05-22)
 * New folder: May 2026 onwards     (≥ 2026-05-22)
 */
function csvPath(string $pair, int $ts): string {
    $cutoff = strtotime('2026-05-22');
    $dir    = ($ts < $cutoff) ? DS_OLD : DS_NEW;
    return $dir . 'FX_' . $pair . '.csv';
}

/** Compute t-statistic for mean ≠ 0 */
function tStat(array $vals): float {
    $n = count($vals);
    if ($n < 2) return 0.0;
    $mean = array_sum($vals) / $n;
    $var  = 0.0;
    foreach ($vals as $v) $var += ($v - $mean) ** 2;
    $var /= ($n - 1);
    $se   = sqrt($var / $n);
    return ($se > 0) ? $mean / $se : 0.0;
}

/** Significance stars: |t|≥2.58 → **, |t|≥1.96 → *, else '' */
function stars(float $t): string {
    $at = abs($t);
    if ($at >= 2.576) return '**';
    if ($at >= 1.960) return '*';
    return '';
}

// ─── MAIN LOGIC ──────────────────────────────────────────────────────────────

// Pull all events (with optional impact filter)
$sql = "SELECT id, event_name, impact, event_time FROM economic_events";
if ($IMPACT_FILTER !== null) $sql .= " WHERE impact = " . (int)$IMPACT_FILTER;
$sql .= " ORDER BY event_time ASC";

$result = $conn->query($sql);
if (!$result) die("Query error: " . $conn->error);

$events = [];
while ($row = $result->fetch_assoc()) {
    $events[] = $row;
}

// CSV cache: pair → [ ts => close ]  (loaded lazily)
$csvCache = [];

// Result store: [ event_name => [ pair => [returns...] ] ]
$results = [];
// Impact lookup: event_name → impact level
$impactMap = [];

$totalEvents = count($events);
$processed   = 0;

foreach ($events as $ev) {
    $evName  = $ev['event_name'];
    $impact  = (int)$ev['impact'];
    $evTs    = strtotime($ev['event_time']);
    if (!$evTs) continue;

    $impactMap[$evName] = $impact; // last seen impact (usually stable)

    $tsB = $evTs + WIN_BEFORE * 60;  // T−5 min
    $tsA = $evTs + WIN_AFTER  * 60;  // T+30 min

    foreach ($PAIRS as $pair) {
        // Determine which CSV to use based on event date
        $path = csvPath($pair, $evTs);

        // Load & cache CSV
        if (!isset($csvCache[$path])) {
            $csvCache[$path] = loadCSV($path);
        }
        $csv = &$csvCache[$path];

        $pBefore = priceAt($csv, $tsB, 360);  // ±6 min tolerance
        $pAfter  = priceAt($csv, $tsA, 360);

        if ($pBefore === null || $pAfter === null || $pBefore == 0) continue;

        $ret = ($pAfter - $pBefore) / $pBefore * 100.0;

        $results[$evName][$pair][] = $ret;
    }

    $processed++;
}

// ─── FREE CSV CACHE ───────────────────────────────────────────────────────────
unset($csvCache);

// ─── BUILD OUTPUT TABLE ───────────────────────────────────────────────────────

// Sort event_names by impact desc, then name asc
uksort($results, function($a, $b) use ($impactMap) {
    $ia = $impactMap[$a] ?? 0;
    $ib = $impactMap[$b] ?? 0;
    if ($ib !== $ia) return $ib - $ia;
    return strcmp($a, $b);
});

// Compute summary per event × pair
// Summary: [ event_name => [ pair => [mean, tStat, n, meanAbsRet, R2proxy] ] ]
$summary = [];
foreach ($results as $evName => $pairData) {
    foreach ($pairData as $pair => $rets) {
        $n = count($rets);
        if ($n < MIN_OBS) continue;
        $mean   = array_sum($rets) / $n;
        $t      = tStat($rets);
        $absRets = array_map('abs', $rets);
        $meanAbs = array_sum($absRets) / $n;
        // R² proxy: corr between surprise (not available) so use % var explained
        // We use mean² / variance as a proxy signal-to-noise
        $var = 0; foreach ($rets as $r) $var += ($r - $mean) ** 2;
        $var = ($n > 1) ? $var / ($n - 1) : 0;
        $r2proxy = ($var > 0) ? min(1.0, ($mean ** 2) / $var) : 0.0;
        $summary[$evName][$pair] = [
            'mean'    => $mean,
            't'       => $t,
            'n'       => $n,
            'absRet'  => $meanAbs,
            'r2'      => $r2proxy,
            'stars'   => stars($t),
        ];
    }
}

// Only keep events that have at least one pair with significant result
$summaryFiltered = array_filter($summary, function($pairData) {
    foreach ($pairData as $d) {
        if ($d['stars'] !== '') return true;
    }
    return false;
});

// Selected pairs for the compact table (major pairs only for readability)
$DISPLAY_PAIRS = ['EURUSD','GBPUSD','USDJPY','USDCAD','AUDUSD','USDCHF','EURJPY','GBPJPY'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Economic News Impact on Forex Pairs</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'Georgia', serif;
    font-size: 13px;
    background: #f9f9f9;
    color: #111;
    padding: 20px;
  }
  h1 { font-size: 18px; margin-bottom: 4px; }
  .subtitle { color: #555; font-size: 12px; margin-bottom: 20px; }

  /* ── SUMMARY CARDS ── */
  .cards { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:28px; }
  .card {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:14px 20px; min-width:160px;
  }
  .card .val { font-size:22px; font-weight:bold; color:#2a6496; }
  .card .lbl { font-size:11px; color:#777; margin-top:3px; }

  /* ── TABS ── */
  .tabs { display:flex; gap:0; margin-bottom:-1px; }
  .tab {
    padding:7px 16px; cursor:pointer; border:1px solid #ccc;
    border-bottom:none; background:#eee; border-radius:4px 4px 0 0;
    font-size:12px; font-weight:bold; color:#555;
    transition: background .15s;
  }
  .tab.active { background:#fff; color:#111; }
  .tab-content { display:none; }
  .tab-content.active { display:block; }

  /* ── TABLE ── */
  .tbl-wrap { overflow-x:auto; background:#fff; border:1px solid #ccc; border-radius:0 4px 4px 4px; }
  table { border-collapse:collapse; width:100%; font-size:12px; }
  th {
    background:#2a6496; color:#fff; padding:8px 10px;
    text-align:center; white-space:nowrap; font-weight:normal;
  }
  th.left { text-align:left; }
  td { padding:6px 10px; border-bottom:1px solid #eee; text-align:center; white-space:nowrap; }
  td.name { text-align:left; font-weight:bold; max-width:220px; white-space:normal; }
  tr:hover td { background:#f0f5fb; }

  /* Impact badge */
  .imp { display:inline-block; border-radius:3px; padding:1px 5px; font-size:10px; font-weight:bold; }
  .imp-1 { background:#d4edda; color:#155724; }
  .imp-2 { background:#fff3cd; color:#856404; }
  .imp-3 { background:#f8d7da; color:#721c24; }

  /* Cell coloring */
  .pos   { color:#155724; }
  .neg   { color:#721c24; }
  .sig   { font-weight:bold; }
  .insig { color:#aaa; }
  .na    { color:#ccc; font-size:11px; }

  /* Stars */
  sup { font-size:9px; color:#c00; }

  /* ── LEGEND ── */
  .legend {
    margin-top:12px; font-size:11px; color:#555;
    background:#fff; border:1px solid #e0e0e0;
    padding:10px 14px; border-radius:4px; display:inline-block;
  }
  .legend b { color:#111; }

  /* ── HEATMAP TABLE ── */
  .heat-positive { background:rgba(21,87,36,VAL); color:#fff; }
  .heat-negative { background:rgba(114,28,36,VAL); color:#fff; }

  /* ── FULL TABLE (all pairs) ── */
  .full-table th, .full-table td { font-size:11px; padding:4px 7px; }

  /* Responsive */
  @media (max-width:700px) {
    .cards { flex-direction:column; }
    .card { min-width:auto; }
  }
</style>
</head>
<body>

<h1>Economic News Impact on Forex Pairs</h1>
<p class="subtitle">
  Methodology: Balduzzi, Elton &amp; Green (2001) · Window: T−5min to T+30min ·
  Data: <?= date('Y-m-d', strtotime('2025-08-11')) ?> – <?= date('Y-m-d') ?> ·
  Events processed: <?= $processed ?>
</p>

<?php
// ── SUMMARY CARDS
$totalSig = 0; $totalCells = 0;
foreach ($summary as $evName => $pd) {
    foreach ($pd as $pair => $d) {
        $totalCells++;
        if ($d['stars'] !== '') $totalSig++;
    }
}
$sigEvents = count($summaryFiltered);
$totalEvNames = count($summary);
?>
<div class="cards">
  <div class="card">
    <div class="val"><?= $totalEvNames ?></div>
    <div class="lbl">Unique event types</div>
  </div>
  <div class="card">
    <div class="val"><?= $sigEvents ?></div>
    <div class="lbl">Events with ≥1 significant pair</div>
  </div>
  <div class="card">
    <div class="val"><?= $totalSig ?>/<?= $totalCells ?></div>
    <div class="lbl">Significant event×pair cells</div>
  </div>
  <div class="card">
    <div class="val"><?= count($PAIRS) ?></div>
    <div class="lbl">Forex pairs analysed</div>
  </div>
</div>

<!-- ── TABS ── -->
<div class="tabs">
  <div class="tab active" onclick="showTab('main')">Main Pairs (8)</div>
  <div class="tab" onclick="showTab('all')">All Pairs (<?= count($PAIRS) ?>)</div>
  <div class="tab" onclick="showTab('heatmap')">Heatmap</div>
  <div class="tab" onclick="showTab('byimpact')">By Impact Level</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     TAB 1 — MAIN PAIRS (compact Table 2 replica)
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-main" class="tab-content active">
<div class="tbl-wrap">
<table>
<thead>
<tr>
  <th class="left" rowspan="2" style="width:220px">Event Name</th>
  <th rowspan="2">Impact</th>
  <th rowspan="2">N</th>
  <?php foreach ($DISPLAY_PAIRS as $p): ?>
  <th colspan="2"><?= $p ?></th>
  <?php endforeach; ?>
</tr>
<tr>
  <?php foreach ($DISPLAY_PAIRS as $p): ?>
  <th title="Mean % return T−5 to T+30">Coeff</th>
  <th title="R² proxy">R²</th>
  <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php
$impactLabels = [1=>'Low', 2=>'Med', 3=>'High'];
$impactClass  = [1=>'imp-1', 2=>'imp-2', 3=>'imp-3'];

foreach ($summaryFiltered as $evName => $pairData):
    $imp = $impactMap[$evName] ?? 1;
    // Count max N across display pairs
    $maxN = 0;
    foreach ($DISPLAY_PAIRS as $p) {
        if (isset($pairData[$p])) $maxN = max($maxN, $pairData[$p]['n']);
    }
?>
<tr>
  <td class="name"><?= htmlspecialchars($evName) ?></td>
  <td><span class="imp <?= $impactClass[$imp] ?>"><?= $impactLabels[$imp] ?></span></td>
  <td><?= $maxN ?></td>
  <?php foreach ($DISPLAY_PAIRS as $p):
      if (!isset($pairData[$p])):
  ?>
    <td class="na">—</td><td class="na">—</td>
  <?php else:
      $d = $pairData[$p];
      $cls = ($d['mean'] >= 0 ? 'pos' : 'neg') . ($d['stars'] ? ' sig' : ' insig');
  ?>
    <td class="<?= $cls ?>">
      <?= number_format($d['mean'], 4) ?><sup><?= $d['stars'] ?></sup>
    </td>
    <td><?= number_format($d['r2'], 3) ?></td>
  <?php endif; endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="legend">
  <b>Coeff</b> = Mean % price change (T−5min to T+30min) ·
  <b>R²</b> = Signal-to-noise proxy ·
  <sup>**</sup> p&lt;0.01 &nbsp; <sup>*</sup> p&lt;0.05 &nbsp; (two-tailed t-test, H₀: mean=0) ·
  Positive = pair went UP after event · Negative = pair went DOWN
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     TAB 2 — ALL PAIRS
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-all" class="tab-content">
<div class="tbl-wrap">
<table class="full-table">
<thead>
<tr>
  <th class="left" style="width:180px">Event Name</th>
  <th>Imp</th>
  <?php foreach ($PAIRS as $p): ?><th><?= $p ?></th><?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($summaryFiltered as $evName => $pairData):
    $imp = $impactMap[$evName] ?? 1;
?>
<tr>
  <td class="name"><?= htmlspecialchars($evName) ?></td>
  <td><span class="imp <?= $impactClass[$imp] ?>"><?= $imp ?></span></td>
  <?php foreach ($PAIRS as $p):
      if (!isset($pairData[$p])): ?>
    <td class="na">—</td>
  <?php else:
      $d = $pairData[$p];
      $cls = ($d['mean'] >= 0 ? 'pos' : 'neg') . ($d['stars'] ? ' sig' : ' insig');
  ?>
    <td class="<?= $cls ?>" title="n=<?= $d['n'] ?>, t=<?= number_format($d['t'],2) ?>">
      <?= number_format($d['mean'], 4) ?><sup><?= $d['stars'] ?></sup>
    </td>
  <?php endif; endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     TAB 3 — HEATMAP (absolute return, colour intensity = magnitude)
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-heatmap" class="tab-content">
<p style="font-size:12px;color:#555;margin-bottom:8px;">
  Color intensity = magnitude of mean return. <span style="color:#155724">■</span> Positive &nbsp; <span style="color:#721c24">■</span> Negative
</p>
<div class="tbl-wrap">
<table>
<thead>
<tr>
  <th class="left" style="width:200px">Event</th>
  <th>Imp</th>
  <?php foreach ($DISPLAY_PAIRS as $p): ?><th><?= $p ?></th><?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php
// Find global max abs mean for scaling
$globalMax = 0.001;
foreach ($summaryFiltered as $evName => $pairData) {
    foreach ($DISPLAY_PAIRS as $p) {
        if (isset($pairData[$p])) {
            $globalMax = max($globalMax, abs($pairData[$p]['mean']));
        }
    }
}

foreach ($summaryFiltered as $evName => $pairData):
    $imp = $impactMap[$evName] ?? 1;
?>
<tr>
  <td class="name"><?= htmlspecialchars($evName) ?></td>
  <td><span class="imp <?= $impactClass[$imp] ?>"><?= $imp ?></span></td>
  <?php foreach ($DISPLAY_PAIRS as $p):
      if (!isset($pairData[$p])): ?>
    <td class="na">—</td>
  <?php else:
      $d = $pairData[$p];
      $intensity = min(0.85, abs($d['mean']) / $globalMax);
      $r = round($intensity * 255);
      if ($d['mean'] >= 0) {
          $bg = "rgba(21,87,36,{$intensity})";
      } else {
          $bg = "rgba(114,28,36,{$intensity})";
      }
      $textColor = $intensity > 0.4 ? '#fff' : '#111';
  ?>
    <td style="background:<?= $bg ?>;color:<?= $textColor ?>;font-weight:<?= $d['stars'] ? 'bold' : 'normal' ?>;"
        title="<?= $evName ?> × <?= $p ?>: mean=<?= number_format($d['mean'],4) ?>, n=<?= $d['n'] ?>, t=<?= number_format($d['t'],2) ?>">
      <?= number_format($d['mean'], 4) ?><?= $d['stars'] ?>
    </td>
  <?php endif; endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     TAB 4 — BY IMPACT LEVEL (count of significant pairs per event)
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-byimpact" class="tab-content">
<p style="font-size:12px;color:#555;margin-bottom:8px;">
  Counts how many pairs are significantly affected by each event (p&lt;0.05).
  Mirrors Balduzzi et al. Table 1 style summary.
</p>
<div class="tbl-wrap">
<table>
<thead>
<tr>
  <th class="left">Event Name</th>
  <th>Impact</th>
  <th title="Occurrences in data">N (obs)</th>
  <th>Sig pairs (p&lt;0.05)</th>
  <th>Sig pairs (p&lt;0.01)</th>
  <th>Total pairs tested</th>
  <th>Strongest pair</th>
  <th>Strongest coeff</th>
</tr>
</thead>
<tbody>
<?php
// Sort by significance count desc, then impact desc
$sigCount = [];
foreach ($summary as $evName => $pairData) {
    $s05 = $s01 = $tot = 0;
    $bestPair = ''; $bestCoeff = 0;
    $maxN = 0;
    foreach ($pairData as $pair => $d) {
        if ($d['n'] < MIN_OBS) continue;
        $tot++;
        $maxN = max($maxN, $d['n']);
        if ($d['stars'] !== '')    $s05++;
        if ($d['stars'] === '**')  $s01++;
        if (abs($d['mean']) > abs($bestCoeff)) {
            $bestCoeff = $d['mean'];
            $bestPair  = $pair;
        }
    }
    $sigCount[$evName] = [
        's05' => $s05, 's01' => $s01, 'tot' => $tot,
        'bestPair' => $bestPair, 'bestCoeff' => $bestCoeff,
        'n' => $maxN, 'imp' => $impactMap[$evName] ?? 1,
    ];
}
uasort($sigCount, function($a, $b) {
    if ($b['s05'] !== $a['s05']) return $b['s05'] - $a['s05'];
    return $b['imp'] - $a['imp'];
});

foreach ($sigCount as $evName => $sc):
    if ($sc['tot'] === 0) continue;
    $imp = $sc['imp'];
?>
<tr>
  <td class="name"><?= htmlspecialchars($evName) ?></td>
  <td><span class="imp <?= $impactClass[$imp] ?>"><?= $impactLabels[$imp] ?></span></td>
  <td><?= $sc['n'] ?></td>
  <td><?= $sc['s05'] ?> / <?= $sc['tot'] ?></td>
  <td><?= $sc['s01'] ?> / <?= $sc['tot'] ?></td>
  <td><?= $sc['tot'] ?></td>
  <td><?= $sc['bestPair'] ?></td>
  <td class="<?= $sc['bestCoeff'] >= 0 ? 'pos' : 'neg' ?>">
    <?= number_format($sc['bestCoeff'], 4) ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}
</script>

</body>
</html>