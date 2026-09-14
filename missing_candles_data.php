<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

const WATCHLIST = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY',
];

const TS_MIN = 1262304000; // 2010-01-01
const TS_MAX = 1893456000; // 2030-01-01

// --------------------------------------------------------------------------
// Dataset source layout (all paths relative to __DIR__):
//
//  SOURCE A  dataset/JUN-2025 TO FEB-2026/FX_{PAIR}.csv
//            Single file, covers Jun 23 2025 – Feb 27 2026
//
//  SOURCE B  dataset/dataset/FX_{PAIR}.csv
//            Single file, covers Apr 2026 – Sep 10 2026
//
//  SOURCE C  dataset/dataset/{PAIR}/FX_{PAIR}-{YYYY-MM-DD}.csv
//            Per-day files, covers Sep 11 2026 onwards
//
// The analyser reads all available sources for a pair, merges the rows in
// memory and analyses the combined timeline.
// --------------------------------------------------------------------------

/**
 * Return every local CSV path that exists for $pair, across all three sources.
 * No HTTP fetching — files must already be on disk.
 */
function getAllCsvPaths(string $pair): array
{
    $p    = strtoupper(str_replace(['/', '_', ' '], '', $pair));
    $root = __DIR__;
    $found = [];

    // SOURCE A — single merged file for Jun 2025 – Feb 2026
    $pathA = "{$root}/dataset/JUN-2025 TO FEB-2026/FX_{$p}.csv";
    if (is_file($pathA)) $found[] = $pathA;

    // SOURCE B — single merged file for Apr 2026 – Sep 10 2026
    $pathB = "{$root}/dataset/dataset/FX_{$p}.csv";
    if (is_file($pathB)) $found[] = $pathB;

    // SOURCE C — per-day files for Sep 11 2026 onwards
    //   directory: dataset/dataset/{PAIR}/
    //   files:     FX_{PAIR}-YYYY-MM-DD.csv
    $dirC = "{$root}/dataset/dataset/{$p}";
    if (is_dir($dirC)) {
        $pattern = "{$dirC}/FX_{$p}-*.csv";
        $dayFiles = glob($pattern);
        if ($dayFiles) {
            // Only include files from 2026-09-11 onwards (filename date >= threshold)
            $threshold = '2026-09-11';
            foreach ($dayFiles as $f) {
                // Extract date from filename: FX_AUDCAD-2026-09-11.csv → 2026-09-11
                if (preg_match('/FX_' . $p . '-(\d{4}-\d{2}-\d{2})\.csv$/i', basename($f), $m)) {
                    if ($m[1] >= $threshold) {
                        $found[] = $f;
                    }
                }
            }
            sort($found); // chronological
        }
    }

    return $found;
}

// --------------------------------------------------------------------------
// CSV helpers
// --------------------------------------------------------------------------

function getColIndex(array $header, array $names): int
{
    foreach ($names as $col) {
        $i = array_search($col, $header, true);
        if ($i !== false) return (int)$i;
    }
    return -1;
}

function normaliseTs(string $t): ?int
{
    if ($t === '') return null;
    if (ctype_digit($t)) {
        $n = (int)$t;
        if ($n > 10000000000) $n = intdiv($n, 1000);
        return ($n >= TS_MIN && $n <= TS_MAX) ? $n : null;
    }
    $ts = strtotime($t);
    if (!$ts) return null;
    return ($ts >= TS_MIN && $ts <= TS_MAX) ? $ts : null;
}

function isWeekend(int $ts): bool
{
    return (int)date('N', $ts) >= 6;
}

function detectTimeframe(array $timestamps): int
{
    $diffs  = [];
    $sorted = $timestamps;
    sort($sorted);
    for ($i = 1; $i < min(200, count($sorted)); $i++) {
        $d = ($sorted[$i] - $sorted[$i - 1]) / 60;
        if ($d > 0 && $d <= 60) $diffs[] = $d;
    }
    if (!$diffs) return 1;
    $counts = array_count_values(array_map('intval', $diffs));
    arsort($counts);
    return (int)array_key_first($counts);
}

function isWeekendGap(int $fromTs, int $toTs): bool
{
    for ($t = $fromTs; $t <= $toTs; $t += 86400) {
        if (isWeekend($t)) return true;
    }
    return false;
}

// --------------------------------------------------------------------------
// Core analyser — reads ALL files for a pair and merges into one timeline
// --------------------------------------------------------------------------

function analyseFiles(string $pair, array $paths): array
{
    $result = [
        'pair'         => $pair,
        'paths'        => $paths,
        'sources'      => [],          // per-source row counts
        'total_rows'   => 0,
        'corrupt_rows' => 0,
        'timeframe'    => 0,
        'date_range'   => '',
        'missing_days' => [],
        'gap_summary'  => [],
        'largest_gap'  => null,
        'error'        => null,
    ];

    $byDate  = [];
    $allTs   = [];
    $corrupt = 0;

    foreach ($paths as $path) {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $result['sources'][basename($path)] = 'cannot read';
            continue;
        }

        $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = array_filter(explode("\n", $raw), fn($l) => trim($l) !== '');
        if (!$lines) {
            $result['sources'][basename($path)] = 'empty';
            continue;
        }

        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $tIdx   = getColIndex($header, ['time', 'datetime', 'date']);
        if ($tIdx < 0) {
            $result['sources'][basename($path)] = 'no time column';
            continue;
        }

        $fileRows = 0;
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (!isset($row[$tIdx])) continue;
            $ts = normaliseTs(trim($row[$tIdx]));
            if ($ts === null) { $corrupt++; continue; }
            if (isWeekend($ts)) continue;
            // Skip March and April entirely (no data for those months)
            $month = (int)date('n', $ts);
            if ($month === 3 || $month === 4) continue;
            $date = date('Y-m-d', $ts);
            $byDate[$date][$ts] = true; // deduplicate by exact timestamp
            $allTs[$ts] = true;
            $fileRows++;
        }

        $result['sources'][basename($path)] = $fileRows . ' rows';
    }

    $result['corrupt_rows'] = $corrupt;

    if (!$allTs) {
        $result['error'] = 'No valid rows found across all sources';
        return $result;
    }

    // Flatten & sort
    $allTs = array_keys($allTs);
    sort($allTs);
    $result['total_rows'] = count($allTs);
    $result['date_range'] = date('Y-m-d', $allTs[0]) . ' to ' . date('Y-m-d', end($allTs));

    // Detect timeframe from merged data
    $tf = detectTimeframe($allTs);
    $result['timeframe'] = $tf;

    // Per-date arrays (sorted)
    foreach ($byDate as $date => $tsMap) {
        $byDate[$date] = array_keys($tsMap);
        sort($byDate[$date]);
    }

    // ---- Missing DAYS ----
    $firstDay    = strtotime(date('Y-m-d', $allTs[0]));
    $lastDay     = strtotime(date('Y-m-d', end($allTs)));
    $missingDays = [];
    for ($d = $firstDay; $d <= $lastDay; $d += 86400) {
        if (isWeekend($d)) continue;
        // March and April are intentionally excluded — skip them
        if (in_array((int)date('n', $d), [3, 4], true)) continue;
        $dateStr = date('Y-m-d', $d);
        if (!isset($byDate[$dateStr])) $missingDays[] = $dateStr;
    }
    $result['missing_days'] = $missingDays;

    // ---- Intraday GAPS ----
    $gapSummary = [];
    $largestGap = null;

    foreach ($byDate as $date => $timestamps) {
        $gaps        = 0;
        $missingMins = 0;

        for ($i = 1; $i < count($timestamps); $i++) {
            $diffMins = ($timestamps[$i] - $timestamps[$i - 1]) / 60;
            if ($diffMins <= $tf) continue; // normal consecutive candle

            $missing = (int)$diffMins - $tf;

            if (isWeekendGap($timestamps[$i - 1], $timestamps[$i])) continue;

            // Skip broker downtime window (20:00–22:30 UTC) for gaps <= 3 hours
            $hour = (int)date('H', $timestamps[$i - 1]);
            if ($missing <= 180 && ($hour >= 20 || $hour < 1)) continue;

            $gaps++;
            $missingMins += $missing;

            if ($largestGap === null || $missing > $largestGap['minutes']) {
                $largestGap = [
                    'date'    => $date,
                    'from'    => date('H:i', $timestamps[$i - 1]),
                    'to'      => date('H:i', $timestamps[$i]),
                    'minutes' => $missing,
                ];
            }
        }

        if ($gaps > 0) {
            $gapSummary[$date] = ['gap_count' => $gaps, 'missing_minutes' => $missingMins];
        }
    }

    $result['gap_summary'] = $gapSummary;
    $result['largest_gap'] = $largestGap;
    return $result;
}

// --------------------------------------------------------------------------
// Run
// --------------------------------------------------------------------------

$focusPair = strtoupper(trim((string)($_GET['pair'] ?? '')));
$pairs     = ($focusPair && in_array($focusPair, WATCHLIST, true)) ? [$focusPair] : WATCHLIST;

$results = [];
foreach ($pairs as $pair) {
    $paths = getAllCsvPaths($pair);
    if (!$paths) {
        $results[$pair] = [
            'pair'    => $pair,
            'paths'   => [],
            'sources' => [],
            'error'   => 'No CSV files found on disk for this pair',
        ];
        continue;
    }
    $results[$pair] = analyseFiles($pair, $paths);
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Missing Candles Report</title>
<style>
    :root{--border:#e5e7eb;--muted:#6b7280;--bg:#f9fafb;--card:#fff;
          --ink:#111827;--red:#ef4444;--green:#22c55e;--yellow:#f59e0b;}
    *{box-sizing:border-box;}
    body{font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;
         background:var(--bg);color:var(--ink);margin:0;padding:24px;}
    h1{margin:0 0 4px;font-size:22px;}
    .sub{color:var(--muted);margin-bottom:20px;font-size:13px;}
    .card{background:var(--card);border:1px solid var(--border);
          border-radius:12px;padding:18px;margin-bottom:16px;}
    .pair-header{display:flex;align-items:center;gap:10px;
                 font-size:16px;font-weight:700;margin-bottom:12px;flex-wrap:wrap;}
    .badge{padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;}
    .ok{background:#d1fae5;color:#065f46;}
    .warn{background:#fef3c7;color:#92400e;}
    .fail{background:#fee2e2;color:#991b1b;}
    .info{background:#dbeafe;color:#1e40af;}
    .purple{background:#ede9fe;color:#5b21b6;}
    table{border-collapse:collapse;width:100%;font-size:13px;}
    th,td{border:1px solid var(--border);padding:5px 8px;text-align:left;}
    th{background:#f3f4f6;font-weight:600;}
    td.num{text-align:right;font-variant-numeric:tabular-nums;}
    .stat-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
    .stat{background:#f8fafc;border:1px solid var(--border);
          border-radius:8px;padding:8px 14px;min-width:120px;}
    .stat-label{font-size:11px;color:var(--muted);}
    .stat-val{font-size:18px;font-weight:700;}
    .stat-val.red{color:var(--red);}
    .stat-val.green{color:var(--green);}
    .stat-val.yellow{color:var(--yellow);}
    .stat-val.blue{color:#2563eb;}
    .file-path{font-size:11px;color:var(--muted);margin-bottom:10px;word-break:break-all;}
    .section-title{font-weight:600;margin:12px 0 6px;font-size:13px;}
    .missing-days{display:flex;flex-wrap:wrap;gap:6px;}
    .day-chip{background:#fee2e2;color:#991b1b;border-radius:4px;
              padding:2px 8px;font-size:12px;font-family:monospace;}
    .none{color:var(--muted);font-size:13px;}
    .alert{border-radius:8px;padding:8px 12px;font-size:12px;margin-bottom:10px;}
    .alert-yellow{background:#fef3c7;border:1px solid #fde68a;color:#92400e;}
    .alert-red{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;}
    form{display:flex;gap:10px;align-items:end;margin-bottom:20px;flex-wrap:wrap;}
    label{font-size:12px;color:var(--muted);display:block;}
    select,button,a.btn{font:inherit;padding:6px 12px;border:1px solid var(--border);
                        border-radius:6px;background:#fff;cursor:pointer;
                        text-decoration:none;color:var(--ink);display:inline-block;}
    button{background:var(--ink);color:#fff;border-color:var(--ink);}
    .scroll-table{overflow-x:auto;max-height:320px;overflow-y:auto;}
    .sources-list{font-size:11px;color:var(--muted);margin-bottom:8px;}
    .sources-list span{display:inline-block;background:#f3f4f6;border:1px solid var(--border);
                       border-radius:4px;padding:1px 7px;margin:2px 4px 2px 0;font-family:monospace;}
</style>
</head>
<body>
<h1>Missing Candles Report</h1>
<p class="sub">
    Merges 3 dataset sources per pair and analyses the combined timeline.<br>
    <strong>Source A:</strong> <code>dataset/JUN-2025 TO FEB-2026/FX_PAIR.csv</code> &nbsp;|&nbsp;
    <strong>Source B:</strong> <code>dataset/dataset/FX_PAIR.csv</code> (Apr–Sep 10 2026) &nbsp;|&nbsp;
    <strong>Source C:</strong> <code>dataset/dataset/PAIR/FX_PAIR-DATE.csv</code> (Sep 11 2026+)<br>
    Weekend gaps, broker downtime (20:00–22:30 UTC), and <strong>March &amp; April</strong> (no data) are excluded. Duplicate timestamps are deduplicated.
</p>

<form method="get">
    <div>
        <label>Filter by pair</label>
        <select name="pair">
            <option value="">— all pairs —</option>
            <?php foreach (WATCHLIST as $p): ?>
                <option value="<?= $p ?>" <?= $p === $focusPair ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit">Run</button>
    <?php if ($focusPair): ?><a class="btn" href="?">Show All</a><?php endif; ?>
</form>

<!-- Summary table -->
<div class="card">
    <div class="section-title" style="font-size:15px;margin-top:0;">Summary</div>
    <div class="scroll-table">
    <table>
        <thead><tr>
            <th>Pair</th><th>Sources Found</th><th>TF</th><th>Date Range</th>
            <th>Valid Rows</th><th>Corrupt</th>
            <th>Missing Days</th><th>Days w/ Gaps</th>
            <th>Missing Mins</th><th>Largest Gap</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($results as $pair => $r): ?>
        <?php
            $totalMins   = array_sum(array_column($r['gap_summary'] ?? [], 'missing_minutes'));
            $daysGaps    = count($r['gap_summary'] ?? []);
            $missingDays = count($r['missing_days'] ?? []);
            $corrupt     = $r['corrupt_rows'] ?? 0;
            $tf          = $r['timeframe'] ?? 0;
            $hasError    = !empty($r['error']);
            $srcCount    = count($r['paths'] ?? []);
            $bad         = $missingDays > 3 || $totalMins > 200;
            $status      = $hasError ? 'fail' : ($bad ? 'warn' : 'ok');
            $label       = $hasError ? 'ERROR' : ($bad ? 'GAPS' : 'OK');
        ?>
        <tr>
            <td><strong><?= $pair ?></strong></td>
            <td><span class="badge purple"><?= $srcCount ?> file<?= $srcCount !== 1 ? 's' : '' ?></span></td>
            <td><?php if ($tf): ?><span class="badge info">M<?= $tf ?></span><?php else: ?>—<?php endif; ?></td>
            <td style="font-size:12px;"><?= htmlspecialchars($r['date_range'] ?? '—') ?></td>
            <td class="num"><?= number_format($r['total_rows'] ?? 0) ?></td>
            <td class="num" style="color:<?= $corrupt > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= number_format($corrupt) ?></td>
            <td class="num" style="color:<?= $missingDays > 0 ? 'var(--red)' : 'var(--green)' ?>"><?= $missingDays ?></td>
            <td class="num" style="color:<?= $daysGaps > 0 ? 'var(--yellow)' : 'var(--green)' ?>"><?= $daysGaps ?></td>
            <td class="num" style="color:<?= $totalMins > 0 ? 'var(--yellow)' : 'var(--green)' ?>"><?= number_format($totalMins) ?></td>
            <td style="font-size:11px;"><?= $r['largest_gap']
                ? $r['largest_gap']['date'] . ' ' . $r['largest_gap']['from'] . '–' . $r['largest_gap']['to'] . ' (' . $r['largest_gap']['minutes'] . 'm)'
                : '—' ?></td>
            <td><span class="badge <?= $status ?>"><?= $label ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Detail cards -->
<?php foreach ($results as $pair => $r): ?>
<?php
    $totalMins   = array_sum(array_column($r['gap_summary'] ?? [], 'missing_minutes'));
    $daysGaps    = count($r['gap_summary'] ?? []);
    $missingDays = count($r['missing_days'] ?? []);
    $corrupt     = $r['corrupt_rows'] ?? 0;
    $tf          = $r['timeframe'] ?? 1;
    $hasError    = !empty($r['error']);
    $bad         = $missingDays > 3 || $totalMins > 200;
    $status      = $hasError ? 'fail' : ($bad ? 'warn' : 'ok');
?>
<div class="card">
    <div class="pair-header">
        <?= $pair ?>
        <?php if ($tf): ?><span class="badge info">M<?= $tf ?></span><?php endif; ?>
        <span class="badge <?= $status ?>"><?= $hasError ? 'ERROR' : ($bad ? 'HAS GAPS' : 'CLEAN') ?></span>
    </div>

    <!-- Source files used -->
    <?php if ($r['sources']): ?>
    <div class="sources-list">
        <strong>Sources:</strong>
        <?php foreach ($r['sources'] as $fname => $info): ?>
            <span title="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?> (<?= htmlspecialchars($info) ?>)</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($hasError): ?>
        <div class="alert alert-red"><?= htmlspecialchars($r['error']) ?></div>
    <?php else: ?>

    <?php if ($corrupt > 0): ?>
    <div class="alert alert-yellow">
        &#9888; <?= number_format($corrupt) ?> rows skipped — timestamps outside 2010–2030 (corrupt/placeholder).
    </div>
    <?php endif; ?>

    <?php if ($missingDays > 10): ?>
    <div class="alert alert-red">
        &#9888; <?= $missingDays ?> missing trading days — CSV is incomplete for <strong><?= $r['date_range'] ?></strong>.
    </div>
    <?php endif; ?>

    <div class="stat-row">
        <div class="stat">
            <div class="stat-label">Timeframe</div>
            <div class="stat-val blue">M<?= $tf ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Valid Rows</div>
            <div class="stat-val"><?= number_format($r['total_rows']) ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Date Range</div>
            <div class="stat-val" style="font-size:12px;line-height:1.3;"><?= htmlspecialchars($r['date_range']) ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Corrupt Rows</div>
            <div class="stat-val <?= $corrupt > 0 ? 'red' : 'green' ?>"><?= number_format($corrupt) ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Missing Days</div>
            <div class="stat-val <?= $missingDays > 0 ? 'red' : 'green' ?>"><?= $missingDays ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Days w/ Real Gaps</div>
            <div class="stat-val <?= $daysGaps > 0 ? 'yellow' : 'green' ?>"><?= $daysGaps ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Total Missing Mins</div>
            <div class="stat-val <?= $totalMins > 0 ? 'yellow' : 'green' ?>"><?= number_format($totalMins) ?></div>
        </div>
        <?php if ($r['largest_gap']): ?>
        <div class="stat">
            <div class="stat-label">Largest Real Gap</div>
            <div class="stat-val yellow" style="font-size:14px;">
                <?= $r['largest_gap']['minutes'] ?>m
                <span style="font-size:11px;color:var(--muted);display:block;">
                    <?= $r['largest_gap']['date'] ?> <?= $r['largest_gap']['from'] ?>–<?= $r['largest_gap']['to'] ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Missing Days -->
    <div class="section-title">Missing Trading Days (<?= $missingDays ?>)</div>
    <?php if (!$r['missing_days']): ?>
        <div class="none">None — all weekdays have data &#10003;</div>
    <?php else: ?>
        <div class="missing-days">
            <?php foreach ($r['missing_days'] as $d): ?>
                <span class="day-chip"><?= $d ?> <?= date('D', strtotime($d)) ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Gap detail -->
    <?php if ($r['gap_summary']): ?>
    <div class="section-title">Real Intraday Gaps by Day (<?= $daysGaps ?> days)</div>
    <div class="scroll-table">
    <table>
        <thead><tr>
            <th>Date</th><th>Day</th>
            <th class="num">Gap Count</th><th class="num">Missing Minutes</th>
        </tr></thead>
        <tbody>
        <?php foreach ($r['gap_summary'] as $date => $g): ?>
        <tr>
            <td><?= $date ?></td>
            <td><?= date('l', strtotime($date)) ?></td>
            <td class="num"><?= $g['gap_count'] ?></td>
            <td class="num" style="color:var(--yellow);"><?= number_format($g['missing_minutes']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="section-title">Real Intraday Gaps</div>
    <div class="none">None detected after filtering weekend/illiquid-hour gaps &#10003;</div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?php endforeach; ?>

</body>
</html>