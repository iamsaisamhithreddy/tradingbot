<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600);
ini_set('memory_limit', '256M');

define('DS_OLD',    '/home/sairedd1/public_html/dataset/JUN-2025 TO FEB-2026/');
define('DS_NEW',    '/home/sairedd1/public_html/dataset/dataset/');
define('WIN_BEFORE', -5);
define('WIN_AFTER',   30);
define('MIN_OBS',      3);
define('MIN_TS',  1700000000);
define('CUTOFF',  1779408000);
define('TOLERANCE',      360);

$PAIRS = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY',
];
$DISPLAY_PAIRS = ['EURUSD','GBPUSD','USDJPY','USDCAD','AUDUSD','USDCHF','EURJPY','GBPJPY'];

// ── CSV helpers ───────────────────────────────────────────────────────────────
function loadCompact(string $path): array {
    $out = [];
    if (!file_exists($path)) return $out;
    $fh = fopen($path, 'r');
    if (!$fh) return $out;
    fgets($fh);
    while (!feof($fh)) {
        $line = fgets($fh);
        if (!$line) continue;
        $p1 = strpos($line, ','); if ($p1===false) continue;
        $ts = (int)substr($line, 0, $p1);
        if ($ts < MIN_TS) continue;
        $p2 = strpos($line,',',$p1+1);
        $p3 = strpos($line,',',$p2+1);
        $p4 = strpos($line,',',$p3+1);
        $p5 = strpos($line,',',$p4+1);
        if ($p5===false) continue;
        $cl = (float)substr($line,$p4+1,$p5-$p4-1);
        if ($cl > 0) $out[$ts] = $cl;
    }
    fclose($fh);
    return $out;
}

function findPrice(array &$data, int $target): ?float {
    if (empty($data)) return null;
    $keys = array_keys($data);
    $lo=0; $hi=count($keys)-1; $best=null; $bestD=PHP_INT_MAX;
    while ($lo<=$hi) {
        $mid=($lo+$hi)>>1;
        $d=abs($keys[$mid]-$target);
        if ($d<$bestD){$bestD=$d;$best=$mid;}
        if ($keys[$mid]<$target) $lo=$mid+1; else $hi=$mid-1;
    }
    return ($bestD<=TOLERANCE) ? $data[$keys[$best]] : null;
}

function tStat(array $v): float {
    $n=count($v); if($n<2) return 0.0;
    $m=array_sum($v)/$n; $var=0.0;
    foreach($v as $x) $var+=($x-$m)**2;
    $se=sqrt($var/($n-1)/$n);
    return $se>0?$m/$se:0.0;
}
function stars(float $t): string {
    $a=abs($t);
    if($a>=2.576) return '**';
    if($a>=1.960) return '*';
    return '';
}

// ── START STREAMING HTML ──────────────────────────────────────────────────────
ob_implicit_flush(true);
if(ob_get_level()) ob_end_clean();

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Economic News Impact on Forex</title>
<style>
*{box-sizing:border-box}
body{font-family:Georgia,serif;font-size:13px;background:#f9f9f9;color:#111;padding:20px;max-width:1600px;margin:0 auto}
h1{font-size:20px;margin-bottom:4px}
.subtitle{color:#555;font-size:12px;margin-bottom:20px}
/* Progress */
#progress{background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px 20px;margin-bottom:20px}
#progress h3{margin:0 0 10px;font-size:14px}
.pbar-wrap{background:#eee;border-radius:8px;height:14px;overflow:hidden}
.pbar{background:#2a6496;height:14px;width:0%;transition:width .3s;border-radius:8px}
#pstatus{margin-top:8px;font-size:12px;color:#555;font-family:monospace}
#plog{margin-top:8px;font-size:11px;color:#777;font-family:monospace;max-height:80px;overflow-y:auto}
/* Cards */
.cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:24px}
.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 18px;min-width:150px}
.card .val{font-size:22px;font-weight:bold;color:#2a6496}
.card .lbl{font-size:11px;color:#777;margin-top:2px}
/* Tabs */
.tabs{display:flex;gap:0;margin-bottom:-1px;flex-wrap:wrap}
.tab{padding:7px 14px;cursor:pointer;border:1px solid #ccc;border-bottom:none;
     background:#eee;border-radius:4px 4px 0 0;font-size:12px;font-weight:bold;color:#555}
.tab.active{background:#fff;color:#111}
.tab-content{display:none}.tab-content.active{display:block}
/* Table */
.tbl-wrap{overflow-x:auto;background:#fff;border:1px solid #ccc;border-radius:0 4px 4px 4px}
table{border-collapse:collapse;width:100%;font-size:12px}
th{background:#2a6496;color:#fff;padding:7px 9px;text-align:center;white-space:nowrap;font-weight:normal;font-size:11px}
th.left{text-align:left}
td{padding:5px 9px;border-bottom:1px solid #eee;text-align:center;white-space:nowrap;font-size:11px}
td.name{text-align:left;font-weight:bold;max-width:200px;white-space:normal;font-size:12px}
tr:nth-child(even) td{background:#fafafa}
tr:hover td{background:#eef4fb}
.imp{display:inline-block;border-radius:3px;padding:1px 5px;font-size:10px;font-weight:bold}
.imp-1{background:#d4edda;color:#155724}.imp-2{background:#fff3cd;color:#856404}.imp-3{background:#f8d7da;color:#721c24}
.pos{color:#155724}.neg{color:#721c24}.sig{font-weight:bold}.insig{color:#bbb}.na{color:#ddd}
sup{font-size:9px;color:#c00;font-weight:bold}
.legend{margin-top:10px;font-size:11px;color:#666;background:#fff;border:1px solid #e0e0e0;
        padding:8px 12px;border-radius:4px;display:inline-block}
.ft th,.ft td{font-size:11px;padding:3px 6px}
/* Heatmap */
.heat td{font-size:11px;padding:4px 7px}
</style>
</head><body>
<h1>Economic News Impact on Forex Pairs</h1>
<p class="subtitle">Methodology: Balduzzi, Elton &amp; Green (2001) · Window: T−5min → T+30min · All occurrences of each event across all dates</p>

<div id="progress">
  <h3>⏳ Processing pairs...</h3>
  <div class="pbar-wrap"><div class="pbar" id="pbar"></div></div>
  <div id="pstatus">Starting...</div>
  <div id="plog"></div>
</div>
';
flush();

// ── LOAD EVENTS (keep ALL occurrences, just dedup within same exact minute per day) ──
require_once __DIR__ . '/db.php';

$res = $conn->query("SELECT event_name, impact, event_time FROM economic_events ORDER BY event_time ASC");
if (!$res) die("<b>DB error:</b> " . $conn->error);

// Dedup: same event_name at exact same unix minute = keep once (handles duplicate DB rows)
// But same event_name on DIFFERENT days = keep ALL (that's the whole point)
$seen    = []; // "event_name|minute_bucket" => true
$events  = [];
$impactMap = [];

while ($row = $res->fetch_assoc()) {
    $ts = strtotime($row['event_time']);
    if (!$ts || $ts < MIN_TS) continue;
    $bucket  = (int)($ts / 60);
    $dedupKey = $row['event_name'] . '|' . $bucket;
    if (isset($seen[$dedupKey])) continue; // exact duplicate row
    $seen[$dedupKey] = true;
    $imp = (int)$row['impact'];
    $events[] = ['event_name'=>$row['event_name'],'impact'=>$imp,'_ts'=>$ts];
    // Keep highest impact seen for this event_name
    if (!isset($impactMap[$row['event_name']]) || $imp > $impactMap[$row['event_name']]) {
        $impactMap[$row['event_name']] = $imp;
    }
}
unset($seen);

$totalEvents = count($events);
$uniqueNames = count($impactMap);

// Precompute before/after ts
$tsBefore = [];
$tsAfter  = [];
foreach ($events as $i => $ev) {
    $tsBefore[$i] = $ev['_ts'] + WIN_BEFORE * 60;
    $tsAfter[$i]  = $ev['_ts'] + WIN_AFTER  * 60;
}

// Separate by folder
$oldIdxs = $newIdxs = [];
foreach ($events as $i => $ev) {
    if ($ev['_ts'] < CUTOFF) $oldIdxs[] = $i;
    else                     $newIdxs[] = $i;
}

// ── PROCESS PAIRS ONE BY ONE, STREAMING PROGRESS ─────────────────────────────
// results[evName][pair] = [ret1, ret2, ...]
$results   = [];
$pairCount = count($PAIRS);

foreach ($PAIRS as $pIdx => $pair) {
    $pct  = round(($pIdx / $pairCount) * 100);
    echo "<script>
      document.getElementById('pbar').style.width='{$pct}%';
      document.getElementById('pstatus').textContent='Processing {$pair} ({$pIdx}/{$pairCount})...';
      document.getElementById('plog').innerHTML+='<div>→ Loading {$pair}...</div>';
      document.getElementById('plog').scrollTop=9999;
    </script>\n";
    flush();

    $pathOld = DS_OLD . 'FX_' . $pair . '.csv';
    $pathNew = DS_NEW . 'FX_' . $pair . '.csv';
    $oldData = loadCompact($pathOld);
    $newData = loadCompact($pathNew);
    $hits    = 0;

    foreach ($events as $i => $ev) {
        $data = ($ev['_ts'] < CUTOFF) ? $oldData : $newData;
        $pB   = findPrice($data, $tsBefore[$i]);
        $pA   = findPrice($data, $tsAfter[$i]);
        if ($pB===null || $pA===null || $pB==0) continue;
        $results[$ev['event_name']][$pair][] = ($pA - $pB) / $pB * 100.0;
        $hits++;
    }

    $mem = round(memory_get_usage(true)/1048576, 1);
    echo "<script>
      document.getElementById('plog').innerHTML+='<div>  ✓ {$pair}: {$hits} obs | mem {$mem}MB</div>';
      document.getElementById('plog').scrollTop=9999;
    </script>\n";
    flush();

    unset($oldData, $newData);
    gc_collect_cycles();
}

echo "<script>
  document.getElementById('pbar').style.width='100%';
  document.getElementById('pstatus').textContent='Computing statistics...';
</script>\n";
flush();

// ── STATISTICS ────────────────────────────────────────────────────────────────
$summary = [];
foreach ($results as $evName => $pairData) {
    foreach ($pairData as $pair => $rets) {
        $n = count($rets); if ($n < MIN_OBS) continue;
        $m = array_sum($rets) / $n;
        $t = tStat($rets);
        $var=0; foreach($rets as $r) $var+=($r-$m)**2;
        $var = $n>1 ? $var/($n-1) : 0;
        $r2  = $var>0 ? min(1.0,$m**2/$var) : 0.0;
        $summary[$evName][$pair] = ['mean'=>$m,'t'=>$t,'n'=>$n,'r2'=>$r2,'stars'=>stars($t)];
    }
}
unset($results);

uksort($summary, function($a,$b) use($impactMap){
    $d=($impactMap[$b]??0)-($impactMap[$a]??0);
    return $d!==0?$d:strcmp($a,$b);
});

$summaryFiltered = array_filter($summary, function($pd){
    foreach($pd as $d) if($d['stars']!=='') return true; return false;
});

// Impact summary
$sigCount=[];
foreach($summary as $evName=>$pd){
    $s05=$s01=$tot=$maxN=0;$bP='';$bC=0;
    foreach($pd as $pair=>$d){
        $tot++;$maxN=max($maxN,$d['n']);
        if($d['stars']!=='')$s05++;
        if($d['stars']==='**')$s01++;
        if(abs($d['mean'])>abs($bC)){$bC=$d['mean'];$bP=$pair;}
    }
    $sigCount[$evName]=['s05'=>$s05,'s01'=>$s01,'tot'=>$tot,'n'=>$maxN,
                        'bestPair'=>$bP,'bestCoeff'=>$bC,'imp'=>$impactMap[$evName]??1];
}
uasort($sigCount,fn($a,$b)=>$b['s05']!==$a['s05']?$b['s05']-$a['s05']:$b['imp']-$a['imp']);

$totalSig=0;$totalCells=0;
foreach($summary as $pd) foreach($pd as $d){$totalCells++;if($d['stars']!=='')$totalSig++;}

$iL=[1=>'Low',2=>'Med',3=>'High'];
$iC=[1=>'imp-1',2=>'imp-2',3=>'imp-3'];

// Hide progress, show results
echo "<script>
  document.getElementById('progress').innerHTML='<b>✅ Done!</b> All " . $pairCount . " pairs processed.';
  document.getElementById('progress').style.background='#d4edda';
  document.getElementById('progress').style.border='1px solid #c3e6cb';
</script>\n";
flush();
?>

<!-- CARDS -->
<div class="cards">
  <div class="card"><div class="val"><?=$uniqueNames?></div><div class="lbl">Unique event types</div></div>
  <div class="card"><div class="val"><?=$totalEvents?></div><div class="lbl">Total event occurrences</div></div>
  <div class="card"><div class="val"><?=count($summaryFiltered)?></div><div class="lbl">Events with ≥1 sig pair</div></div>
  <div class="card"><div class="val"><?=$totalSig?>/<?=$totalCells?></div><div class="lbl">Sig event×pair cells</div></div>
</div>

<div class="tabs">
  <div class="tab active" onclick="showTab('main',this)">Main Pairs (8)</div>
  <div class="tab" onclick="showTab('all',this)">All Pairs (<?=count($PAIRS)?>)</div>
  <div class="tab" onclick="showTab('heatmap',this)">Heatmap</div>
  <div class="tab" onclick="showTab('byimpact',this)">By Impact Level</div>
  <div class="tab" onclick="showTab('detail',this)" style="background:#e8f4fd;border-color:#2a6496;color:#2a6496">🔍 Drill Down</div>
</div>

<!-- TAB 1: MAIN PAIRS -->
<div id="tab-main" class="tab-content active">
<div class="tbl-wrap"><table>
<thead>
<tr>
  <th class="left" rowspan="2" style="min-width:180px">Event Name</th>
  <th rowspan="2">Impact</th><th rowspan="2">N</th>
  <?php foreach($DISPLAY_PAIRS as $p):?><th colspan="2"><?=$p?></th><?php endforeach;?>
</tr>
<tr><?php foreach($DISPLAY_PAIRS as $p):?><th>Coeff%</th><th>R²</th><?php endforeach;?></tr>
</thead>
<tbody>
<?php if(empty($summaryFiltered)):?>
<tr><td colspan="<?=3+count($DISPLAY_PAIRS)*2?>" style="text-align:center;padding:20px;color:#888">
  No significant results — event timestamps may not overlap with CSV data range.
</td></tr>
<?php endif;?>
<?php foreach($summaryFiltered as $evName=>$pd):
  $imp=$impactMap[$evName]??1;
  $maxN=0;foreach($DISPLAY_PAIRS as $p)if(isset($pd[$p]))$maxN=max($maxN,$pd[$p]['n']);?>
<tr>
  <td class="name"><?=htmlspecialchars($evName)?></td>
  <td><span class="imp <?=$iC[$imp]?>"><?=$iL[$imp]?></span></td>
  <td><?=$maxN?></td>
  <?php foreach($DISPLAY_PAIRS as $p):
    if(!isset($pd[$p])):?><td class="na" colspan="2">—</td>
    <?php else:$d=$pd[$p];$cls=($d['mean']>=0?'pos':'neg').($d['stars']?' sig':' insig');?>
    <td class="<?=$cls?>"><?=number_format($d['mean'],4)?><sup><?=$d['stars']?></sup></td>
    <td><?=number_format($d['r2'],3)?></td>
  <?php endif;endforeach;?>
</tr>
<?php endforeach;?>
</tbody></table></div>
<div class="legend">
  <b>Coeff%</b> = Mean % change T−5→T+30 across all occurrences ·
  <b>N</b> = number of dates ·
  <b>R²</b> = signal-to-noise ·
  <sup>**</sup>p&lt;0.01 &nbsp;<sup>*</sup>p&lt;0.05 (two-tailed t-test, H₀: mean=0)
</div>
</div>

<!-- TAB 2: ALL PAIRS -->
<div id="tab-all" class="tab-content">
<div class="tbl-wrap"><table class="ft">
<thead><tr>
  <th class="left">Event Name</th><th>Imp</th><th>N</th>
  <?php foreach($PAIRS as $p):?><th><?=$p?></th><?php endforeach;?>
</tr></thead>
<tbody>
<?php foreach($summaryFiltered as $evName=>$pd):$imp=$impactMap[$evName]??1;
  $maxN=0;foreach($PAIRS as $p)if(isset($pd[$p]))$maxN=max($maxN,$pd[$p]['n']);?>
<tr>
  <td class="name"><?=htmlspecialchars($evName)?></td>
  <td><span class="imp <?=$iC[$imp]?>"><?=$imp?></span></td>
  <td><?=$maxN?></td>
  <?php foreach($PAIRS as $p):
    if(!isset($pd[$p])):?><td class="na">—</td>
    <?php else:$d=$pd[$p];$cls=($d['mean']>=0?'pos':'neg').($d['stars']?' sig':' insig');?>
    <td class="<?=$cls?>" title="n=<?=$d['n']?> | t=<?=number_format($d['t'],2)?> | R²=<?=number_format($d['r2'],3)?>">
      <?=number_format($d['mean'],4)?><sup><?=$d['stars']?></sup>
    </td>
  <?php endif;endforeach;?>
</tr>
<?php endforeach;?>
</tbody></table></div>
<div class="legend">Hover a cell for details (n, t-stat, R²)</div>
</div>

<!-- TAB 3: HEATMAP -->
<div id="tab-heatmap" class="tab-content">
<p style="font-size:12px;color:#555;margin-bottom:8px">
  Color = direction &amp; magnitude across all pairs · <span style="color:#155724">■</span> Pair went UP ·
  <span style="color:#721c24">■</span> Pair went DOWN · darker = stronger effect
</p>
<div class="tbl-wrap"><table class="heat">
<thead><tr>
  <th class="left">Event Name</th><th>Imp</th><th>N</th>
  <?php foreach($PAIRS as $p):?><th><?=$p?></th><?php endforeach;?>
</tr></thead>
<tbody>
<?php
$gMax=0.001;
foreach($summaryFiltered as $evName=>$pd)
  foreach($PAIRS as $p)
    if(isset($pd[$p])) $gMax=max($gMax,abs($pd[$p]['mean']));

foreach($summaryFiltered as $evName=>$pd):
  $imp=$impactMap[$evName]??1;
  $maxN=0;foreach($PAIRS as $p)if(isset($pd[$p]))$maxN=max($maxN,$pd[$p]['n']);?>
<tr>
  <td class="name"><?=htmlspecialchars($evName)?></td>
  <td><span class="imp <?=$iC[$imp]?>"><?=$imp?></span></td>
  <td><?=$maxN?></td>
  <?php foreach($PAIRS as $p):
    if(!isset($pd[$p])):?><td class="na">—</td>
    <?php else:$d=$pd[$p];
      $in=min(0.88,abs($d['mean'])/$gMax);
      $bg=$d['mean']>=0?"rgba(21,87,36,{$in})":"rgba(114,28,36,{$in})";
      $tc=$in>0.35?'#fff':'#111';
      $fw=$d['stars']?'bold':'normal';?>
    <td style="background:<?=$bg?>;color:<?=$tc?>;font-weight:<?=$fw?>"
        title="<?=htmlspecialchars($evName)?> × <?=$p?>&#10;Mean: <?=number_format($d['mean'],4)?>%&#10;N: <?=$d['n']?>&#10;t: <?=number_format($d['t'],2)?>&#10;stars: <?=$d['stars']?:'-'?>">
      <?=number_format($d['mean'],4)?><?=$d['stars']?>
    </td>
  <?php endif;endforeach;?>
</tr>
<?php endforeach;?>
</tbody></table></div>
</div>

<!-- TAB 4: BY IMPACT LEVEL -->
<div id="tab-byimpact" class="tab-content">
<p style="font-size:12px;color:#555;margin-bottom:8px">
  Sorted by number of significantly affected pairs (p&lt;0.05). Each row = one event type compiled across all dates.
</p>
<div class="tbl-wrap"><table>
<thead><tr>
  <th class="left">Event Name</th><th>Impact</th>
  <th title="Number of dates this event occurred">Dates (N)</th>
  <th>Sig pairs<br>p&lt;0.05</th><th>Sig pairs<br>p&lt;0.01</th>
  <th>Total pairs<br>tested</th>
  <th>Strongest<br>pair</th>
  <th>Mean coeff%</th>
</tr></thead>
<tbody>
<?php foreach($sigCount as $evName=>$sc):if($sc['tot']===0)continue;$imp=$sc['imp'];?>
<tr>
  <td class="name"><?=htmlspecialchars($evName)?></td>
  <td><span class="imp <?=$iC[$imp]?>"><?=$iL[$imp]?></span></td>
  <td><?=$sc['n']?></td>
  <td><?=$sc['s05']?>/<?=$sc['tot']?></td>
  <td><?=$sc['s01']?>/<?=$sc['tot']?></td>
  <td><?=$sc['tot']?></td>
  <td><b><?=$sc['bestPair']?></b></td>
  <td class="<?=$sc['bestCoeff']>=0?'pos':'neg'?> sig"><?=number_format($sc['bestCoeff'],4)?>%</td>
</tr>
<?php endforeach;?>
</tbody></table></div>
</div>

<!-- TAB 5: DRILL DOWN -->
<div id="tab-detail" class="tab-content">
<div style="background:#fff;border:1px solid #ccc;border-radius:0 4px 4px 4px;padding:16px">

  <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px">
    <div>
      <label style="font-size:12px;font-weight:bold;display:block;margin-bottom:4px">Forex Pair</label>
      <select id="d-pair" style="padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px">
        <?php foreach($PAIRS as $p): echo "<option value='$p'>$p</option>"; endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:240px">
      <label style="font-size:12px;font-weight:bold;display:block;margin-bottom:4px">Economic Event</label>
      <input id="d-event" list="ev-list" type="text"
             placeholder="Type to search (e.g. CPI, NFP, GDP)..."
             style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px" />
      <datalist id="ev-list">
        <?php arsort($impactMap); foreach($impactMap as $n=>$i): ?>
        <option value="<?=htmlspecialchars($n,ENT_QUOTES)?>"><?=$iL[$i]?></option>
        <?php endforeach; ?>
      </datalist>
    </div>
    <button id="d-btn" style="padding:8px 22px;background:#2a6496;color:#fff;border:none;border-radius:4px;font-size:14px;cursor:pointer;font-weight:bold">
      Load &#9654;
    </button>
  </div>

  <div id="d-summary" style="display:none;margin-bottom:12px;padding:12px 16px;border-radius:4px;background:#f0f5fb;border:1px solid #c8dff0;font-size:12px"></div>
  <div id="d-loading" style="display:none;text-align:center;padding:30px;color:#888;font-size:13px">⏳ Loading...</div>
  <div id="d-error"   style="display:none;padding:10px 14px;background:#f8d7da;color:#721c24;border-radius:4px;font-size:12px;margin-bottom:10px"></div>
  <div id="d-table"  style="overflow-x:auto"></div>

</div>
</div>

<script>
// showTab — tab switching
function showTab(n,el){
  document.querySelectorAll('.tab-content').forEach(e=>e.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(e=>e.classList.remove('active'));
  document.getElementById('tab-'+n).classList.add('active');
  el.classList.add('active');
}

// Wire Load button after DOM is fully ready
window.addEventListener('load', function() {
  var btn = document.getElementById('d-btn');
  if (btn) btn.addEventListener('click', loadDetail);

  var pairSel = document.getElementById('d-pair');
  if (pairSel) pairSel.addEventListener('change', function(){
    if (document.getElementById('d-table').innerHTML.trim()) loadDetail();
  });
});

function loadDetail() {
  var pair  = document.getElementById('d-pair').value.trim();
  var event = document.getElementById('d-event').value.trim();
  if (!pair)  { alert('Select a Forex Pair'); return; }
  if (!event) { alert('Type or select an Economic Event'); return; }

  document.getElementById('d-loading').style.display = 'block';
  document.getElementById('d-table').innerHTML        = '';
  document.getElementById('d-summary').style.display  = 'none';
  document.getElementById('d-error').style.display    = 'none';

  var url = 'impact_detail.php?pair=' + encodeURIComponent(pair)
                              + '&event=' + encodeURIComponent(event);
  fetch(url)
    .then(function(r){ return r.text(); })
    .then(function(txt){
      document.getElementById('d-loading').style.display = 'none';
      var data;
      try { data = JSON.parse(txt); }
      catch(e) {
        showErr('Server returned non-JSON: ' + txt.slice(0,300));
        return;
      }
      if (data.error) { showErr(data.error); return; }
      renderDetail(data);
    })
    .catch(function(e){
      document.getElementById('d-loading').style.display = 'none';
      showErr('Fetch failed: ' + e);
    });
}

function showErr(msg) {
  var el = document.getElementById('d-error');
  el.style.display = 'block';
  el.textContent   = '⚠ ' + msg;
}

function renderDetail(data) {
  var s      = data.summary;
  var upPct  = s.pct_up;
  var dnPct  = 100 - upPct;
  var sig    = s.stars ? s.stars + ' significant' : 'not significant';

  document.getElementById('d-summary').style.display = 'block';
  document.getElementById('d-summary').innerHTML =
    '<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;margin-bottom:8px">' +
      '<span style="font-size:16px;font-weight:bold;color:#2a6496">' + data.pair + '</span>' +
      '<span style="color:#aaa">×</span>' +
      '<span style="font-weight:bold">' + data.event + '</span>' +
      '<span>N = <b>' + s.n + '</b> / ' + s.total + ' with price data</span>' +
      '<span>Mean = <b style="color:' + (s.mean>=0?'#155724':'#721c24') + '">' +
        (s.mean>=0?'+':'') + s.mean.toFixed(5) + '%</b></span>' +
      '<span>t = ' + s.tstat + ' → ' + sig + '</span>' +
      '<span>▲ UP <b>' + s.ups + '</b> (' + upPct + '%)</span>' +
      '<span>▼ DOWN <b>' + s.dns + '</b> (' + dnPct + '%)</span>' +
    '</div>' +
    '<div style="display:flex;height:10px;border-radius:5px;overflow:hidden;max-width:300px;border:1px solid #ddd">' +
      '<div style="width:' + upPct + '%;background:#155724" title="' + upPct + '% UP"></div>' +
      '<div style="width:' + dnPct + '%;background:#721c24" title="' + dnPct + '% DOWN"></div>' +
    '</div>';

  var maxAbs = 0.0001;
  data.rows.forEach(function(r){ if(r.ret!==null && Math.abs(r.ret)>maxAbs) maxAbs=Math.abs(r.ret); });

  var html = '<table style="border-collapse:collapse;width:100%;font-size:12px">' +
    '<thead><tr>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px;width:32px">#</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px;text-align:left">Date</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px">Time</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px">P T−5min</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px">P T+30min</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px">Change</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px">% Move</th>' +
    '<th style="background:#2a6496;color:#fff;padding:7px 9px;min-width:150px">Bar</th>' +
    '</tr></thead><tbody>';

  data.rows.forEach(function(r, i) {
    var h   = r.ret !== null;
    var col = h ? (r.ret>=0 ? '#155724' : '#721c24') : '#999';
    var rS  = h ? (r.ret>=0?'+':'')+r.ret.toFixed(5)+'%' : '—';
    var cS  = h ? (r.chg>=0?'+':'')+r.chg.toFixed(5) : '—';
    var dS  = r.dir==='UP' ? '▲ UP' : r.dir==='DOWN' ? '▼ DOWN' : '—';
    var bg  = i%2===0 ? '#fff' : '#f9f9f9';
    var bar = '—';
    if (h) {
      var w = Math.max(4, Math.round(Math.abs(r.ret)/maxAbs*110));
      bar = '<span style="display:inline-block;height:11px;width:'+w+'px;background:'+col+';border-radius:2px;vertical-align:middle"></span>' +
            '<span style="margin-left:6px;color:'+col+';font-weight:bold">'+dS+'</span>';
    }
    html +=
      '<tr style="background:'+bg+'">' +
      '<td style="padding:5px 8px;text-align:center;color:#bbb">'+(i+1)+'</td>' +
      '<td style="padding:5px 8px;font-weight:bold">'+r.date+'</td>' +
      '<td style="padding:5px 8px;text-align:center;color:#666">'+r.time+'</td>' +
      '<td style="padding:5px 8px;text-align:center">'+(r.pBefore!==null?r.pBefore.toFixed(5):'—')+'</td>' +
      '<td style="padding:5px 8px;text-align:center">'+(r.pAfter!==null?r.pAfter.toFixed(5):'—')+'</td>' +
      '<td style="padding:5px 8px;text-align:center;color:'+col+'">'+cS+'</td>' +
      '<td style="padding:5px 8px;text-align:center;color:'+col+';font-weight:bold">'+rS+'</td>' +
      '<td style="padding:5px 8px">'+bar+'</td>' +
      '</tr>';
  });

  html += '</tbody></table>';
  document.getElementById('d-table').innerHTML = html;
}
</script>
</body></html>