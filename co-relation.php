<?php
/**
 * ============================================================================
 *  correlation.php  —  Forex Pair Correlation Matrix
 * ============================================================================
 *  Query params:
 *      ?focus=EURUSD   single-column view ranking every pair vs EURUSD
 *      ?days=90        lookback window in calendar days (default 90)
 *      ?debug=1        per-pair diagnostics (rows read / parsed / kept)
 * ============================================================================
 */
declare(strict_types=1);

// Show real errors instead of a blank 500.
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo '<pre style="background:#fee;color:#900;padding:16px;font:13px monospace;">';
    echo "UNCAUGHT: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "at " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "\n\n";
    echo htmlspecialchars($e->getTraceAsString());
    echo '</pre>';
});

// ---------------------------------------------------------------------------
// 1. CONFIGURATION
// ---------------------------------------------------------------------------

const WATCHLIST = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY',
];

const DEFAULT_LOOKBACK_DAYS = 90;
const MIN_OVERLAP_DAYS = 20;

// ---------------------------------------------------------------------------
// 2. REQUEST PARAMS
// ---------------------------------------------------------------------------

$focusPair = strtoupper(trim((string)($_GET['focus'] ?? '')));
$lookback  = max(5, min(365, (int)($_GET['days'] ?? DEFAULT_LOOKBACK_DAYS)));
$debug     = !empty($_GET['debug']);

$endDate   = new DateTimeImmutable('today');
$startDate = $endDate->modify("-{$lookback} days");

// ---------------------------------------------------------------------------
// 3. CSV PATH RESOLVER
// ---------------------------------------------------------------------------

function getCsvPathCandidates(string $pair, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $cleanPair = strtoupper(str_replace(['/', '_', ' '], '', $pair));

    $fileNames = array_values(array_unique([
        "FX_{$cleanPair}.csv",
        "FX_{$cleanPair}_M1.csv",
        "FX_{$cleanPair}_1min.csv",
        "{$cleanPair}.csv",
        "{$cleanPair}_M1.csv",
        "{$cleanPair}_1min.csv",
    ]));

    $timestamp = $to->getTimestamp();
    $cutoffEnd = strtotime('2026-03-01 00:00:00');

    $newDir  = __DIR__ . '/dataset/dataset';
    $oldDir  = __DIR__ . '/dataset/JUN-2025 TO FEB-2026';
    $rootDir = __DIR__ . '/dataset';

    $dirs = ($timestamp < $cutoffEnd)
        ? [$oldDir, $newDir, $rootDir]
        : [$newDir, $oldDir, $rootDir];

    $paths = [];
    foreach ($dirs as $dir) {
        foreach ($fileNames as $fileName) {
            $paths[] = $dir . '/' . $fileName;
        }
    }
    return array_values(array_unique($paths));
}

function getCsvPath(string $pair, DateTimeImmutable $from, DateTimeImmutable $to, array &$triedPaths = []): ?string
{
    $triedPaths = getCsvPathCandidates($pair, $from, $to);
    foreach ($triedPaths as $path) {
        if (is_file($path)) return $path;
    }
    return null;
}

// ---------------------------------------------------------------------------
// 4. CSV LOADER  —  returns [ 'YYYY-MM-DD' => closePrice ]
// ---------------------------------------------------------------------------

function loadDailyCloses(string $path, DateTimeImmutable $from, DateTimeImmutable $to, array &$diag): array
{
    $diag = ['rows_read' => 0, 'rows_parsed' => 0, 'rows_kept' => 0, 'error' => null];

    $raw = @file_get_contents($path);
    if ($raw === false) { $diag['error'] = 'file_get_contents failed'; return []; }

    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_filter(explode("\n", $raw), fn($l) => $l !== '');
    if (!$lines) { $diag['error'] = 'empty file'; return []; }

    $header = str_getcsv(array_shift($lines));
    $header = array_map(fn($h) => strtolower(trim($h)), $header);

    $timeIdx  = array_search('time', $header, true);
    if ($timeIdx === false) $timeIdx = array_search('datetime', $header, true);
    if ($timeIdx === false) $timeIdx = array_search('date', $header, true);

    $closeIdx = array_search('close', $header, true);
    if ($closeIdx === false) $closeIdx = array_search('bidclose', $header, true);
    if ($closeIdx === false) $closeIdx = array_search('c', $header, true);

    if ($timeIdx === false || $closeIdx === false) {
        $diag['error'] = 'time/close column not found in header: ' . implode('|', $header);
        return [];
    }

    $fromTs = $from->getTimestamp();
    $toTs   = $to->getTimestamp();
    $dailyCloses = [];

    foreach ($lines as $line) {
        $diag['rows_read']++;
        $row = str_getcsv($line);
        if (!isset($row[$timeIdx], $row[$closeIdx])) continue;

        $date = normaliseToDate(trim($row[$timeIdx]));
        if ($date === null) continue;

        $close = (float)$row[$closeIdx];
        if ($close <= 0) continue;

        $diag['rows_parsed']++;

        $ts = strtotime($date . ' 00:00:00');
        if ($ts < $fromTs || $ts > $toTs) continue;

        $dailyCloses[$date] = $close;
        $diag['rows_kept']++;
    }

    ksort($dailyCloses);
    return $dailyCloses;
}

function normaliseToDate(string $t): ?string
{
    if ($t === '') return null;
    if (ctype_digit($t)) {
        $n = (int)$t;
        if ($n > 10_000_000_000) $n = intdiv($n, 1000);
        return date('Y-m-d', $n);
    }
    $ts = strtotime($t);
    return $ts ? date('Y-m-d', $ts) : null;
}

// ---------------------------------------------------------------------------
// 5. STATISTICS
// ---------------------------------------------------------------------------

function logReturns(array $dailyCloses): array
{
    $out = []; $prev = null;
    foreach ($dailyCloses as $date => $price) {
        if ($prev !== null && $prev > 0) $out[$date] = log($price / $prev);
        $prev = $price;
    }
    return $out;
}

function pearson(array $a, array $b): ?float
{
    $common = array_intersect_key($a, $b);
    $n = count($common);
    if ($n < MIN_OVERLAP_DAYS) return null;

    $x = $y = [];
    foreach ($common as $date => $_) { $x[] = $a[$date]; $y[] = $b[$date]; }

    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;

    $num = $dx2 = $dy2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $mx; $dy = $y[$i] - $my;
        $num += $dx * $dy; $dx2 += $dx * $dx; $dy2 += $dy * $dy;
    }
    $den = sqrt($dx2 * $dy2);
    return $den > 0 ? $num / $den : null;
}

// ---------------------------------------------------------------------------
// 6. PIPELINE
// ---------------------------------------------------------------------------

$diagnostics = [];
$returns     = [];

foreach (WATCHLIST as $pair) {
    $triedPaths = [];
    $path = getCsvPath($pair, $startDate, $endDate, $triedPaths);
    if (!$path) {
        $diagnostics[$pair] = [
            'error' => 'no CSV file found',
            'expected_main_file' => 'FX_' . $pair . '.csv',
            'tried_paths' => $triedPaths,
        ];
        continue;
    }

    $diag = [];
    $closes = loadDailyCloses($path, $startDate, $endDate, $diag);
    $diag['path']      = $path;
    $diag['days_kept'] = count($closes);
    $diagnostics[$pair] = $diag;

    if (count($closes) >= MIN_OVERLAP_DAYS) {
        $returns[$pair] = logReturns($closes);
    }
}

$loadedPairs = array_keys($returns);

$matrix = [];
if ($focusPair && isset($returns[$focusPair])) {
    foreach ($loadedPairs as $p) {
        $matrix[$focusPair][$p] = ($p === $focusPair) ? 1.0 : pearson($returns[$focusPair], $returns[$p]);
    }
} else {
    foreach ($loadedPairs as $a) {
        foreach ($loadedPairs as $b) {
            $matrix[$a][$b] = ($a === $b) ? 1.0 : pearson($returns[$a], $returns[$b]);
        }
    }
}

// Build focus-pair bar list (sorted most positive → most negative, excluding self).
$focusBars = [];
if ($focusPair && isset($matrix[$focusPair])) {
    foreach ($matrix[$focusPair] as $p => $r) {
        if ($p === $focusPair || $r === null) continue;
        $focusBars[] = ['pair' => $p, 'r' => $r];
    }
    usort($focusBars, fn($a, $b) => $b['r'] <=> $a['r']);
}

// ---------------------------------------------------------------------------
// 7. VIEW HELPERS
// ---------------------------------------------------------------------------

function corrColor(?float $r): string
{
    if ($r === null) return '#f3f4f6';
    $r = max(-1.0, min(1.0, $r));
    if ($r >= 0) {
        $g = 255; $rc = $bc = (int)round(255 * (1 - $r));
    } else {
        $rc = 255; $g = $bc = (int)round(255 * (1 + $r));
    }
    return sprintf('#%02x%02x%02x', $rc, $g, $bc);
}

function corrLabel(?float $r): string
{
    if ($r === null) return 'n/a';
    $a = abs($r);
    if ($a >= 0.8) return $r > 0 ? 'very high +' : 'very high −';
    if ($a >= 0.5) return $r > 0 ? 'high +'      : 'high −';
    if ($a >= 0.3) return $r > 0 ? 'moderate +'  : 'moderate −';
    return 'weak';
}

function fmtPair(string $p): string
{
    return strlen($p) === 6 ? substr($p, 0, 3) . '/' . substr($p, 3) : $p;
}

$dayTabs = [5, 10, 30, 60, 90, 180, 250];

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Pair Correlation Matrix</title>
    <style>
        :root { --border:#e5e7eb; --muted:#6b7280; --card:#ffffff; --bg:#f9fafb;
                --pos:#22c55e; --neg:#ec4899; --ink:#111827; }
        * { box-sizing: border-box; }
        body { font: 14px/1.4 -apple-system, Segoe UI, Roboto, sans-serif;
               background: var(--bg); color: var(--ink); margin: 0; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .sub { color: var(--muted); margin-bottom: 20px; }
        .card { background: var(--card); border: 1px solid var(--border);
                border-radius: 12px; padding: 18px; margin-bottom: 20px;
                animation: scaleIn .35s ease both; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid var(--border); padding: 6px 8px;
                 text-align: center; font-variant-numeric: tabular-nums; }
        th { background: #f3f4f6; font-weight: 600; }
        td.pair { background: #f3f4f6; font-weight: 600; text-align: left; }
        td.corr { transition: transform .15s ease; }
        td.corr:hover { transform: scale(1.15); position: relative; z-index: 2;
                        box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .legend { display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
                  color: var(--muted); font-size: 12px; margin-top: 10px; }
        .swatch { display: inline-block; width: 14px; height: 14px;
                  border: 1px solid var(--border); vertical-align: middle;
                  margin-right: 4px; border-radius: 3px; }
        form { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
        label { display: block; font-size: 12px; color: var(--muted); }
        input, select, button, .btn { font: inherit; padding: 6px 12px;
                                border: 1px solid var(--border); border-radius: 6px;
                                background:#fff; color:var(--ink); cursor:pointer;
                                text-decoration:none; display:inline-block; }
        button.primary { background: var(--ink); color: #fff; border-color: var(--ink); }
        .empty { color: var(--muted); text-align: center; padding: 30px; }
        pre { background: #0f172a; color: #e2e8f0; padding: 12px;
              border-radius: 8px; overflow: auto; font-size: 12px; }
        .actions { display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; }

        /* Day tabs */
        .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
        .tab { padding:6px 14px; border-radius:999px; border:1px solid var(--border);
               background:#fff; color:var(--ink); text-decoration:none; font-size:13px; }
        .tab.active { background:var(--ink); color:#fff; border-color:var(--ink); }

        /* Focus bars */
        .focus-title { display:flex; align-items:center; gap:10px; margin:0 0 14px; font-size:18px; }
        .dot { width:14px; height:14px; border-radius:999px;
               background:linear-gradient(135deg,var(--pos),var(--neg)); }
        .bar-row { display:grid; grid-template-columns: 90px 1fr 70px;
                   align-items:center; gap:10px; padding:6px 0;
                   border-bottom:1px solid #f1f5f9;
                   animation: slideIn .35s ease both; }
        .bar-pair { font-weight:600; }
        .bar-track { position:relative; height:22px; background:#f8fafc;
                     border-radius:4px; overflow:hidden; }
        .bar-track::before { content:""; position:absolute; left:50%; top:0; bottom:0;
                             width:1px; background:#cbd5e1; }
        .bar-fill { position:absolute; top:3px; bottom:3px; border-radius:3px; }
        .chip { text-align:right; font-variant-numeric:tabular-nums; font-weight:600;
                padding:2px 8px; border-radius:999px; font-size:12px; }
        .chip.pos { color:#065f46; background:#d1fae5; }
        .chip.neg { color:#9d174d; background:#fce7f3; }
        .axis { display:flex; justify-content:space-between; color:var(--muted);
                font-size:11px; margin-top:8px; padding: 0 90px 0 100px; }

        @keyframes scaleIn { from { opacity:0; transform:scale(.98); } to { opacity:1; transform:scale(1); } }
        @keyframes slideIn { from { opacity:0; transform:translateX(-10px); } to { opacity:1; transform:none; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>

<h1>Pair Correlation Matrix</h1>
<div class="sub">
    Window: <?= $startDate->format('Y-m-d') ?> → <?= $endDate->format('Y-m-d') ?>
    (<?= $lookback ?> days) &nbsp;·&nbsp;
    <?= count($loadedPairs) ?> / <?= count(WATCHLIST) ?> pairs loaded
</div>

<!-- Controls -->
<div class="card">
    <form method="get">
        <div>
            <label for="focus">Focus pair (optional)</label>
            <select name="focus" id="focus">
                <option value="">— full matrix —</option>
                <?php foreach (WATCHLIST as $p): ?>
                    <option value="<?= $p ?>" <?= $p === $focusPair ? 'selected' : '' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="days">Lookback (days)</label>
            <input type="number" name="days" id="days" min="5" max="365" value="<?= $lookback ?>">
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit" class="primary">Recalculate</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a class="btn" href="?debug=1<?= $focusPair ? '&focus='.$focusPair : '' ?>&days=<?= $lookback ?>">Diagnostics</a>
        </div>
    </form>
</div>

<!-- Day tabs -->
<div class="tabs">
    <?php foreach ($dayTabs as $d):
        $qs = http_build_query(array_filter([
            'days' => $d,
            'focus' => $focusPair ?: null,
        ])); ?>
        <a class="tab <?= $d === $lookback ? 'active' : '' ?>" href="?<?= $qs ?>"><?= $d ?>D</a>
    <?php endforeach; ?>
</div>

<?php if ($focusPair && $focusBars): ?>
<!-- Focus-pair bar view -->
<div class="card">
    <h2 class="focus-title"><span class="dot"></span> <?= fmtPair($focusPair) ?> · correlations</h2>
    <?php foreach ($focusBars as $i => $b):
        $r = $b['r']; $abs = abs($r);
        $color = $r >= 0 ? 'var(--pos)' : 'var(--neg)';
        $w = ($abs * 50);
        $leftOrRight = $r >= 0 ? "left:50%; width:{$w}%;" : "right:50%; width:{$w}%;";
        $cls = $r >= 0 ? 'pos' : 'neg';
        $sign = $r >= 0 ? '+' : ''; ?>
        <div class="bar-row" style="animation-delay: <?= $i * 25 ?>ms;">
            <div class="bar-pair"><?= fmtPair($b['pair']) ?></div>
            <div class="bar-track">
                <div class="bar-fill" style="background:<?= $color ?>; <?= $leftOrRight ?>"></div>
            </div>
            <div class="chip <?= $cls ?>"><?= $sign . number_format($r, 2) ?></div>
        </div>
    <?php endforeach; ?>
    <div class="axis"><span>-1.0</span><span>0</span><span>+1.0</span></div>
</div>
<?php endif; ?>

<!-- Matrix -->
<div class="card">
<?php if (!$loadedPairs): ?>
    <div class="empty">
        No pair data could be loaded. Open <a href="?debug=1">?debug=1</a> to see why.
    </div>
<?php else: ?>
    <div class="actions">
        <button type="button" class="btn" id="dlSvgBtn">Download SVG</button>
        <button type="button" class="btn" id="dlPngBtn">Download PNG</button>
    </div>
    <table id="corrTable">
        <thead>
            <tr>
                <th></th>
                <?php foreach ($loadedPairs as $col): ?>
                    <th><?= $col ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_keys($matrix) as $rowPair): ?>
            <tr>
                <td class="pair"><?= $rowPair ?></td>
                <?php foreach ($loadedPairs as $col):
                    $r = $matrix[$rowPair][$col] ?? null; ?>
                    <td class="corr" style="background: <?= corrColor($r) ?>"
                        title="<?= corrLabel($r) ?>">
                        <?= $r === null ? '—' : number_format($r, 2) ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="legend">
        <span><span class="swatch" style="background:#ef4444"></span>−1 (inverse)</span>
        <span><span class="swatch" style="background:#ffffff"></span>0 (independent)</span>
        <span><span class="swatch" style="background:#22c55e"></span>+1 (identical)</span>
        <span><span class="swatch" style="background:#f3f4f6"></span>insufficient overlap</span>
    </div>
<?php endif; ?>
</div>

<?php if ($debug): ?>
<div class="card">
    <h2 style="margin-top:0;font-size:16px;">Diagnostics</h2>
    <pre><?= htmlspecialchars(print_r($diagnostics, true)) ?></pre>
</div>
<?php endif; ?>

<script>
(() => {
    const PAIRS  = <?= json_encode($loadedPairs) ?>;
    const ROWS   = <?= json_encode(array_keys($matrix)) ?>;
    const MATRIX = <?= json_encode($matrix) ?>;
    const DAYS   = <?= (int)$lookback ?>;
    if (!PAIRS.length) return;

    const corrColor = (r) => {
        if (r === null || r === undefined) return '#f3f4f6';
        r = Math.max(-1, Math.min(1, r));
        if (r >= 0) { const v = Math.round(255*(1-r)); return `rgb(${v},255,${v})`; }
        const v = Math.round(255*(1+r)); return `rgb(255,${v},${v})`;
    };

    const CELL_W = 70, CELL_H = 32, LABEL_W = 90, HEADER_H = 34;
    const W = LABEL_W + PAIRS.length * CELL_W + 20;
    const H = HEADER_H + ROWS.length  * CELL_H + 20;

    const buildSvg = () => {
        const svgNS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('xmlns', svgNS);
        svg.setAttribute('width',  W);
        svg.setAttribute('height', H);
        svg.setAttribute('viewBox', `0 0 ${W} ${H}`);

        const bg = document.createElementNS(svgNS, 'rect');
        bg.setAttribute('width', W); bg.setAttribute('height', H); bg.setAttribute('fill', '#ffffff');
        svg.appendChild(bg);

        const mkText = (x, y, txt, opts = {}) => {
            const t = document.createElementNS(svgNS, 'text');
            t.setAttribute('x', x); t.setAttribute('y', y);
            t.setAttribute('font-family', 'Arial, sans-serif');
            t.setAttribute('font-size', opts.size || 12);
            t.setAttribute('fill', opts.fill || '#111827');
            t.setAttribute('text-anchor', opts.anchor || 'middle');
            t.setAttribute('dominant-baseline', 'middle');
            if (opts.weight) t.setAttribute('font-weight', opts.weight);
            t.textContent = txt;
            return t;
        };

        PAIRS.forEach((p, i) => {
            svg.appendChild(mkText(LABEL_W + i*CELL_W + CELL_W/2, HEADER_H/2, p, {weight:600}));
        });

        ROWS.forEach((rowPair, ri) => {
            const y = HEADER_H + ri*CELL_H;
            svg.appendChild(mkText(LABEL_W - 8, y + CELL_H/2, rowPair, {anchor:'end', weight:600}));
            PAIRS.forEach((col, ci) => {
                const r = (MATRIX[rowPair] || {})[col];
                const x = LABEL_W + ci*CELL_W;
                const rect = document.createElementNS(svgNS, 'rect');
                rect.setAttribute('x', x); rect.setAttribute('y', y);
                rect.setAttribute('width', CELL_W); rect.setAttribute('height', CELL_H);
                rect.setAttribute('fill', corrColor(r));
                rect.setAttribute('stroke', '#e5e7eb');
                svg.appendChild(rect);
                svg.appendChild(mkText(x + CELL_W/2, y + CELL_H/2,
                    (r === null || r === undefined) ? '—' : r.toFixed(2)));
            });
        });
        return svg;
    };

    const download = (blob, name) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = name;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };

    document.getElementById('dlSvgBtn')?.addEventListener('click', () => {
        const svg = buildSvg();
        const src = new XMLSerializer().serializeToString(svg);
        download(new Blob([src], {type:'image/svg+xml;charset=utf-8'}),
                 `correlation-matrix-${DAYS}d.svg`);
    });

    document.getElementById('dlPngBtn')?.addEventListener('click', () => {
        const svg = buildSvg();
        const src = new XMLSerializer().serializeToString(svg);
        const svgBlob = new Blob([src], {type:'image/svg+xml;charset=utf-8'});
        const url = URL.createObjectURL(svgBlob);
        const img = new Image();
        img.onload = () => {
            const scale = 2;
            const canvas = document.createElement('canvas');
            canvas.width  = W * scale;
            canvas.height = H * scale;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.setTransform(scale, 0, 0, scale, 0, 0);
            ctx.drawImage(img, 0, 0);
            URL.revokeObjectURL(url);
            canvas.toBlob((blob) => {
                if (blob) download(blob, `correlation-matrix-${DAYS}d.png`);
            }, 'image/png');
        };
        img.onerror = () => { URL.revokeObjectURL(url); alert('PNG export failed.'); };
        img.src = url;
    });
})();
</script>

</body>
</html>
