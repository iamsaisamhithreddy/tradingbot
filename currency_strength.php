<?php

/**
 * Currency Strength Meter — auto-loading version
 * ------------------------------------------------
 * Reads every FX_XXXYYY.csv it finds in /dataset/dataset (no upload step),
 * computes per-currency strength + a pair correlation matrix in PHP, and
 * serves the same numbers back over AJAX whenever the lookback slider moves
 * (?ajax=1&hours=N) so we're never re-parsing 20 CSVs synchronously in the
 * browser.
 *
 * Paths (adjust the two constants below if your layout differs):
 *   $datasetDiskPath : where the FX_*.csv files live
 *   $flagsDiskPath   : where the flag images live (matched by country-name
 *                      keyword, e.g. united-states-flag-icon.png)
 */

// Always fetch fresh — never let the browser, a proxy, or the host cache
// this page or the AJAX responses. Dataset files change underneath it.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');

$currencies = ['USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'NZD'];

$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');

$flagsWebPath   = '/flags';
$flagsDiskPath  = $docRoot . $flagsWebPath;
$datasetDiskPath = $docRoot . '/dataset/dataset'; // <-- change here if the folder moves

// ---------- flag resolution (by country-name keyword in filename) ----------
$keywords = [
    'USD' => ['united-states', 'united_states', 'usa', 'us-flag'],
    'EUR' => ['european-union', 'european_union', 'eu-flag', 'europe'],
    'GBP' => ['united-kingdom', 'united_kingdom', 'uk-flag', 'britain', 'england'],
    'JPY' => ['japan'],
    'AUD' => ['australia'],
    'CAD' => ['canada'],
    'CHF' => ['switzerland', 'swiss'],
    'NZD' => ['new-zealand', 'new_zealand'],
];

function resolve_flags($flagsDiskPath, $flagsWebPath, $currencies, $keywords) {
    $diskFiles = is_dir($flagsDiskPath) ? scandir($flagsDiskPath) : [];
    $flagUrls = [];
    foreach ($currencies as $code) {
        $found = null;
        foreach ($keywords[$code] as $kw) {
            foreach ($diskFiles as $file) {
                if ($file === '.' || $file === '..') continue;
                if (stripos($file, $kw) !== false) { $found = $flagsWebPath . '/' . $file; break 2; }
            }
        }
        $flagUrls[$code] = $found;
    }
    return $flagUrls;
}

// ---------- dataset parsing ----------
function extract_pair($filename, $known) {
    $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', $filename));
    for ($i = 0; $i <= strlen($clean) - 6; $i++) {
        $six = substr($clean, $i, 6);
        $base = substr($six, 0, 3);
        $quote = substr($six, 3, 3);
        if (in_array($base, $known) && in_array($quote, $known) && $base !== $quote) {
            return [$base, $quote];
        }
    }
    return null;
}

function read_pair_closes($filepath) {
    $rows = [];
    $handle = @fopen($filepath, 'r');
    if ($handle === false) return $rows;
    $header = fgetcsv($handle);
    if ($header === false) { fclose($handle); return $rows; }
    $header = array_map('strtolower', array_map('trim', $header));
    $timeIdx = array_search('time', $header);
    $closeIdx = array_search('close', $header);
    if ($timeIdx === false || $closeIdx === false) { fclose($handle); return $rows; }
    while (($data = fgetcsv($handle)) !== false) {
        if (!isset($data[$timeIdx], $data[$closeIdx])) continue;
        $t = $data[$timeIdx]; $c = $data[$closeIdx];
        if ($t === '' || $c === '') continue;
        $rows[] = [(int)$t, (float)$c];
    }
    fclose($handle);
    // guard against unsorted files
    usort($rows, function($a, $b) { return $a[0] <=> $b[0]; });
    return $rows;
}

function pearson($a, $b) {
    $n = min(count($a), count($b));
    if ($n < 3) return null;
    $a = array_slice($a, -$n); $b = array_slice($b, -$n);
    $meanA = array_sum($a) / $n; $meanB = array_sum($b) / $n;
    $num = 0; $da = 0; $db = 0;
    for ($i = 0; $i < $n; $i++) {
        $xa = $a[$i] - $meanA; $xb = $b[$i] - $meanB;
        $num += $xa * $xb; $da += $xa * $xa; $db += $xb * $xb;
    }
    if ($da == 0 || $db == 0) return null;
    return $num / sqrt($da * $db);
}

function returns_series($rows) {
    $out = [];
    for ($i = 1; $i < count($rows); $i++) {
        $prev = $rows[$i - 1][1];
        if ($prev != 0) $out[] = ($rows[$i][1] - $prev) / $prev;
    }
    return $out;
}

function compute_strength($datasetDiskPath, $currencies, $hours) {
    $files = is_dir($datasetDiskPath) ? glob($datasetDiskPath . '/*.csv') : [];
    $contributions = [];
    $usedPairs = [];
    $skipped = [];

    foreach ($files as $f) {
        $pairInfo = extract_pair(basename($f), $currencies);
        if (!$pairInfo) { $skipped[] = basename($f); continue; }
        list($base, $quote) = $pairInfo;
        $rows = read_pair_closes($f);
        if (count($rows) < 2) { $skipped[] = basename($f); continue; }

        $lastT = $rows[count($rows) - 1][0];
        $cutoff = $lastT - $hours * 3600;
        $windowRows = array_values(array_filter($rows, function($r) use ($cutoff) { return $r[0] >= $cutoff; }));
        if (count($windowRows) < 2) $windowRows = array_slice($rows, -2);

        $first = $windowRows[0][1];
        $last = $windowRows[count($windowRows) - 1][1];
        if (!$first) continue;
        $pctChange = ($last - $first) / $first * 100;

        $usedPairs[] = [
            'label' => $base . '/' . $quote,
            'base' => $base, 'quote' => $quote,
            'rows' => $windowRows,
            'totalBars' => count($rows),
        ];

        $contributions[$base][] = $pctChange;
        $contributions[$quote][] = -$pctChange;
    }

    $scores = [];
    foreach ($contributions as $cur => $vals) {
        $avg = array_sum($vals) / count($vals);
        $scores[] = ['cur' => $cur, 'avg' => $avg, 'n' => count($vals)];
    }
    $maxAbs = 0.0001;
    foreach ($scores as $s) $maxAbs = max($maxAbs, abs($s['avg']));
    foreach ($scores as &$s) { $s['norm'] = $s['avg'] / $maxAbs; } // scaled to -1..1
    unset($s);
    usort($scores, function($a, $b) { return $b['norm'] <=> $a['norm']; });

    $labels = array_map(function($p) { return $p['label']; }, $usedPairs);
    $retSeries = array_map(function($p) { return returns_series($p['rows']); }, $usedPairs);
    $corr = [];
    for ($i = 0; $i < count($usedPairs); $i++) {
        $row = [];
        for ($j = 0; $j < count($usedPairs); $j++) {
            $row[] = $i === $j ? 1 : pearson($retSeries[$i], $retSeries[$j]);
        }
        $corr[] = $row;
    }

    $ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));

return [
    'scores' => $scores,
    'labels' => $labels,
    'corr' => $corr,
    'pairCount' => count($usedPairs),
    'skipped' => $skipped,
    'generatedAt' => $ist->format('F j, Y g:i A'),
];
}

// ---------- AJAX endpoint (slider re-fetches this) ----------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $hours = isset($_GET['hours']) ? max(1, (int)$_GET['hours']) : 24;
    echo json_encode(compute_strength($datasetDiskPath, $currencies, $hours));
    exit;
}

$flagUrls = resolve_flags($flagsDiskPath, $flagsWebPath, $currencies, $keywords);
$initialHours = 24;
$initial = compute_strength($datasetDiskPath, $currencies, $initialHours);

$flagUrlsJson = json_encode($flagUrls);
$initialJson = json_encode($initial);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Currency Strength Meter</title>
</head>
<body style="margin:0; background:#ECEDF1;">

<div id="csm-root"></div>
<style>
  #csm-root, #csm-root * { box-sizing: border-box; }
  #csm-root {
    --bg-outer: #ECEDF1;
    --card: #FFFFFF;
    --text: #3A3F4B;
    --muted: #9AA1AB;
    --line: #F1F1F3;
    --green: #6BC72E;
    --pink: #ED1566;
    --purple: #7C4DE0;
    --blue: #3E7CF0;
    --track: #F4F4F6;
    font-family: 'Nunito', 'Segoe UI', -apple-system, sans-serif;
    background: var(--bg-outer);
    padding: 22px;
    border-radius: 20px;
  }
  .csm-card {
    background: var(--card);
    border-radius: 18px;
    padding: 26px 30px 20px;
    position: relative;
    box-shadow: 0 1px 3px rgba(20,20,40,0.06);
  }
  .csm-card::before {
    content: '';
    position: absolute; top: -22px; left: -22px; right: -22px; height: 6px;
    background: linear-gradient(90deg, var(--purple), var(--blue));
    border-radius: 20px 20px 0 0;
  }
  .csm-title-row { display: flex; align-items: baseline; gap: 6px; margin-bottom: 6px; }
  .csm-title { font-size: 25px; font-weight: 800; color: var(--text); margin: 0; }
  .csm-chevron { color: var(--text); font-size: 20px; font-weight: 700; opacity: .8; }
  .csm-sub { color: var(--muted); font-size: 14.5px; margin: 0 0 14px; }
  .csm-sub b { color: #5A606B; }

  .csm-toolbar {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap;
    gap: 10px; margin-bottom: 6px; padding-bottom: 14px; border-bottom: 1px solid var(--line);
  }
  .csm-meta { font-size: 12px; color: var(--muted); }
  .csm-meta b { color: #5A606B; }
  .csm-refresh-btn {
    font-size: 12.5px; font-weight: 700; background: var(--track); color: #5A606B;
    border: none; border-radius: 20px; padding: 8px 16px; cursor: pointer; white-space: nowrap;
  }
  .csm-refresh-btn:hover { background: #E9E9EC; }
  .csm-refresh-btn[disabled] { opacity: .5; cursor: default; }

  .csm-lookback-row {
    display: flex; align-items: center; gap: 10px; margin: 12px 0 4px; font-size: 12px; color: var(--muted);
  }
  .csm-lookback-row input[type=range] { accent-color: var(--purple); width: 150px; }
  .csm-lookback-row .val { color: var(--purple); font-weight: 800; }

  .csm-chart { margin-top: 10px; position: relative; min-height: 60px; }
  .csm-chart.loading { opacity: .45; pointer-events: none; }
  .csm-row {
    display: grid; grid-template-columns: 108px 1fr; align-items: center;
    gap: 14px; padding: 13px 0; border-bottom: 1px solid var(--line);
  }
  .csm-row:last-child { border-bottom: none; }
  .csm-code-wrap { display: flex; align-items: center; gap: 12px; }
  .csm-flag {
    width: 36px; height: 36px; border-radius: 50%; display: inline-flex;
    align-items: center; justify-content: center; font-size: 18px; flex: none;
    background: var(--track); overflow: hidden;
  }
  .csm-flag img { width: 100%; height: 100%; object-fit: cover; }
  .csm-code { font-weight: 800; font-size: 16px; color: var(--text); }
  .csm-bar-track { position: relative; height: 30px; }
  .csm-center-line {
    position: absolute; top: -10px; bottom: -10px; left: 55%; width: 2px;
    background: #D8DADF;
  }
  .csm-bar {
    position: absolute; top: 0; bottom: 0; border-radius: 20px;
    transition: width 0.5s cubic-bezier(.2,.8,.2,1), left 0.5s cubic-bezier(.2,.8,.2,1);
  }
  .csm-bar.pos { background: var(--green); left: 55%; }
  .csm-bar.neg { background: var(--pink); }
  .csm-bar-score {
    position: absolute; top: 50%; transform: translateY(-50%);
    font-size: 11px; font-weight: 800; color: rgba(255,255,255,0.92); white-space: nowrap;
  }

  .csm-empty { padding: 46px 10px; text-align: center; color: var(--muted); font-size: 13.5px; }

  .csm-section-title {
    font-size: 12px; font-weight: 800; letter-spacing: .04em; color: var(--muted);
    text-transform: uppercase; margin: 22px 0 10px;
  }
  .csm-corr-wrap { overflow-x: auto; }
  .csm-corr { border-collapse: collapse; font-size: 11px; width: 100%; }
  .csm-corr th, .csm-corr td { padding: 7px 8px; text-align: center; min-width: 46px; }
  .csm-corr th { color: var(--muted); font-weight: 700; }
  .csm-corr td { color: #2B2F38; font-weight: 700; border-radius: 6px; }

  .csm-foot { margin-top: 16px; display: flex; justify-content: space-between; color: var(--muted); font-size: 12px; }
  .csm-foot .asof { font-style: italic; }
  .csm-warn { font-size: 11.5px; color: #C77B00; margin-top: 8px; }
</style>

<script>
(function(){
  const root = document.getElementById('csm-root');
  const FLAG_URLS = <?php echo $flagUrlsJson; ?>;
  let data = <?php echo $initialJson; ?>;
  let lookbackHours = <?php echo $initialHours; ?>;
  let debounceTimer = null;

  function flagMarkup(code) {
    const url = FLAG_URLS[code];
    return url ? `<img src="${url}" alt="${code}">` : '';
  }

  root.innerHTML = `
    <div class="csm-card">
      <div class="csm-title-row">
        <h2 class="csm-title">Currency Strength Meter</h2>
        <span class="csm-chevron">›</span>
      </div>
      <p class="csm-sub">What is the overall strength or weakness of individual major currencies <b>today</b>?</p>

      <div class="csm-toolbar">
        <span class="csm-meta" id="csm-meta"></span>
        <button class="csm-refresh-btn" id="csm-refresh">↻ Rescan dataset</button>
      </div>

      <div class="csm-lookback-row">
        <span>LOOKBACK</span>
        <input type="range" id="csm-lookback" min="1" max="720" value="${lookbackHours}">
        <span>last <span class="val" id="csm-lookback-val">${lookbackHours}h</span></span>
      </div>

      <div class="csm-chart" id="csm-chart"></div>
      <div class="csm-warn" id="csm-warn" style="display:none;"></div>

      <div class="csm-section-title" id="csm-corr-title" style="display:none;">Pair Correlation (return-based)</div>
      <div class="csm-corr-wrap" id="csm-corr"></div>

      <div class="csm-foot">
        <span></span>
        <span class="asof" id="csm-asof"></span>
      </div>
    </div>
  `;

  function renderData(d) {
    const chartEl = root.querySelector('#csm-chart');
    const corrTitle = root.querySelector('#csm-corr-title');
    const corrEl = root.querySelector('#csm-corr');
    const asofEl = root.querySelector('#csm-asof');
    const metaEl = root.querySelector('#csm-meta');
    const warnEl = root.querySelector('#csm-warn');

    metaEl.innerHTML = `<b>${d.pairCount}</b> pair${d.pairCount!==1?'s':''} loaded from dataset · <b>${d.scores.length}</b> currenc${d.scores.length!==1?'ies':'y'} resolved`;

    if (!d.scores.length) {
      chartEl.innerHTML = '<div class="csm-empty">No pairs found in dataset/dataset yet, or none matched a recognizable currency code (expects filenames like FX_EURUSD.csv).</div>';
    } else {
      chartEl.innerHTML = d.scores.map(s => {
        const isPos = s.norm >= 0;
        const width = Math.abs(s.norm) * 42; // norm is -1..1, track fits 42% each side
        const scoreLabel = (s.norm >= 0 ? '+' : '') + s.norm.toFixed(2);
        return `
        <div class="csm-row">
          <div class="csm-code-wrap">
            <span class="csm-flag">${flagMarkup(s.cur)}</span>
            <span class="csm-code">${s.cur}</span>
          </div>
          <div class="csm-bar-track">
            <div class="csm-center-line"></div>
            <div class="csm-bar ${isPos ? 'pos' : 'neg'}"
                 style="width:${width}%; ${isPos ? 'left:55%;' : `left:${55-width}%;`}">
              <span class="csm-bar-score" style="${isPos ? 'right:8px;' : 'left:8px;'}">${scoreLabel}</span>
            </div>
          </div>
        </div>`;
      }).join('');
    }

    if (d.labels.length >= 2) {
      corrTitle.style.display = 'block';
      let html = '<table class="csm-corr"><thead><tr><th></th>' +
        d.labels.map(l => `<th>${l}</th>`).join('') + '</tr></thead><tbody>';
      for (let i = 0; i < d.labels.length; i++) {
        html += `<tr><th>${d.labels[i]}</th>`;
        for (let j = 0; j < d.labels.length; j++) {
          const r = d.corr[i][j];
          let bg = '#F1F1F3';
          if (r !== null) bg = r >= 0 ? `rgba(107,199,46,${0.12 + r*0.55})` : `rgba(237,21,102,${0.12 + (-r)*0.55})`;
          html += `<td style="background:${bg}">${r===null?'—':r.toFixed(2)}</td>`;
        }
        html += '</tr>';
      }
      html += '</tbody></table>';
      corrEl.innerHTML = html;
    } else {
      corrTitle.style.display = 'none';
      corrEl.innerHTML = '';
    }

    if (d.skipped && d.skipped.length) {
      warnEl.style.display = 'block';
      warnEl.textContent = `Skipped (couldn't read or match a pair code): ${d.skipped.join(', ')}`;
    } else {
      warnEl.style.display = 'none';
    }

    asofEl.textContent = 'Computed ' + d.generatedAt;
  }

  renderData(data);

  function fetchData(hours) {
    const chartEl = root.querySelector('#csm-chart');
    chartEl.classList.add('loading');
    fetch(`?ajax=1&hours=${hours}`)
      .then(r => r.json())
      .then(d => { data = d; renderData(d); })
      .catch(() => {})
      .finally(() => { chartEl.classList.remove('loading'); });
  }

  root.querySelector('#csm-lookback').addEventListener('input', (e) => {
    lookbackHours = Number(e.target.value);
    const label = lookbackHours % 24 === 0 && lookbackHours >= 24 ? (lookbackHours/24) + 'd' : lookbackHours + 'h';
    root.querySelector('#csm-lookback-val').textContent = label;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => fetchData(lookbackHours), 350);
  });

  root.querySelector('#csm-refresh').addEventListener('click', (e) => {
    e.target.setAttribute('disabled', 'true');
    fetchData(lookbackHours);
    setTimeout(() => e.target.removeAttribute('disabled'), 600);
  });
})();
</script>

</body>
</html>