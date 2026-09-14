<?php


if (function_exists('ini_set')) { @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0'); }
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600);
ini_set('memory_limit', '512M');

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

function nearestPrice(array &$data, array &$keys, int $target, int &$pos): ?float {
    $n = count($keys);
    if ($n === 0) return null;

    while ($pos < $n && $keys[$pos] < $target) {
        $pos++;
    }

    if ($pos >= $n) {
        $j = $n - 1;
        return (abs($keys[$j] - $target) <= TOLERANCE) ? $data[$keys[$j]] : null;
    }

    if ($pos === 0) {
        return (abs($keys[0] - $target) <= TOLERANCE) ? $data[$keys[0]] : null;
    }

    $cur = $keys[$pos];
    $prev = $keys[$pos - 1];

    if (abs($prev - $target) <= abs($cur - $target)) {
        return (abs($prev - $target) <= TOLERANCE) ? $data[$prev] : null;
    }

    return (abs($cur - $target) <= TOLERANCE) ? $data[$cur] : null;
}

function computeNearestForEvents(array &$data, array &$keys, array &$events, int $cutoff): array {
    $out = [];
    $posBefore = 0;
    $posAfter  = 0;
    $nKeys = count($keys);
    if ($nKeys === 0) return $out;

    foreach ($events as $i => $ev) {
        $ts = $ev['ts'];
        $beforeTarget = $ts + WIN_BEFORE * 60;
        $afterTarget  = $ts + WIN_AFTER * 60;

        while ($posBefore < $nKeys && $keys[$posBefore] < $beforeTarget) $posBefore++;
        while ($posAfter  < $nKeys && $keys[$posAfter]  < $afterTarget)  $posAfter++;

        if ($posBefore >= $nKeys || $posAfter >= $nKeys) continue;

        $b0 = $posBefore;
        $b1 = max(0, $posBefore - 1);
        $a0 = $posAfter;
        $a1 = max(0, $posAfter - 1);

        $bIdx = (abs($keys[$b1] - $beforeTarget) <= abs($keys[$b0] - $beforeTarget)) ? $b1 : $b0;
        $aIdx = (abs($keys[$a1] - $afterTarget) <= abs($keys[$a0] - $afterTarget)) ? $a1 : $a0;

        if (abs($keys[$bIdx] - $beforeTarget) > TOLERANCE || abs($keys[$aIdx] - $afterTarget) > TOLERANCE) continue;

        $pb = $data[$keys[$bIdx]];
        $pa = $data[$keys[$aIdx]];
        if ($pb <= 0) continue;

        $out[$i] = ($pa - $pb) / $pb * 100.0;
    }

    return $out;
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

// ── CORRELATION HELPERS / SAME-FILE JSON ENDPOINT ─────────────────────────────
function cMean(array $v): float { $v=array_values(array_filter($v,'is_numeric')); return count($v)?array_sum($v)/count($v):0.0; }
function cMedian(array $v): float { $v=array_values(array_filter($v,'is_numeric')); $n=count($v); if(!$n)return 0.0; sort($v,SORT_NUMERIC); $m=intdiv($n,2); return $n%2?$v[$m]:($v[$m-1]+$v[$m])/2; }
function cStd(array $v): float { $n=count($v); if($n<2)return 0.0; $m=cMean($v); $ss=0.0; foreach($v as $x)$ss+=($x-$m)**2; return sqrt($ss/($n-1)); }
function cPearson(array $x,array $y): ?float { $n=min(count($x),count($y)); if($n<3)return null; $x=array_slice($x,0,$n);$y=array_slice($y,0,$n);$mx=cMean($x);$my=cMean($y);$num=0.0;$sx=0.0;$sy=0.0;for($i=0;$i<$n;$i++){ $dx=$x[$i]-$mx;$dy=$y[$i]-$my;$num+=$dx*$dy;$sx+=$dx*$dx;$sy+=$dy*$dy;} $den=sqrt($sx*$sy); return $den>0?$num/$den:null; }
function cRanks(array $v): array { $a=[]; foreach($v as $i=>$x)$a[]=['i'=>$i,'v'=>$x]; usort($a,fn($l,$r)=>$l['v']<=>$r['v']); $r=array_fill(0,count($v),0.0); for($i=0,$n=count($a);$i<$n;){$j=$i;while($j+1<$n && $a[$j+1]['v']==$a[$i]['v'])$j++;$rank=(($i+1)+($j+1))/2;for($k=$i;$k<=$j;$k++)$r[$a[$k]['i']]=$rank;$i=$j+1;} return $r; }
function cSpearman(array $x,array $y): ?float { $n=min(count($x),count($y)); if($n<3)return null; return cPearson(cRanks(array_slice($x,0,$n)),cRanks(array_slice($y,0,$n))); }
function cMatrix(array $rows,array $pairs): array { $m=[]; foreach($pairs as $a){$m[$a]=[];foreach($pairs as $b){if($a===$b){$m[$a][$b]=1.0;continue;}$x=[];$y=[];foreach($rows as $row){if(isset($row[$a],$row[$b])){$x[]=$row[$a];$y[]=$row[$b];}}$c=cSpearman($x,$y);$m[$a][$b]=$c===null?null:round($c,6);}} return $m; }
function correlationResponse(array $PAIRS): void {
    require_once __DIR__ . '/db.php';

    $res = $conn->query("SELECT event_name,event_time,impact FROM economic_events WHERE event_time IS NOT NULL ORDER BY event_time ASC");
    if (!$res) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Event query failed: '.$conn->error]);
        exit;
    }

    $events=[];
    $seen=[];
    $counts=[1=>0,2=>0,3=>0];

    while($row=$res->fetch_assoc()){
        $ts=strtotime($row['event_time']);
        $imp=(int)$row['impact'];
        if(!$ts || $ts<MIN_TS || !in_array($imp,[1,2,3],true)) continue;
        $k=$row['event_name'].'|'.intdiv($ts,60);
        if(isset($seen[$k])) continue;
        $seen[$k]=1;
        $events[]=['ts'=>$ts,'impact'=>$imp];
        $counts[$imp]++;
    }
    $res->free();
    unset($seen);

    $allImp=[];
    $allAbs=[];
    $groups=[1=>[],2=>[],3=>[]];
    $rows=['all'=>[],1=>[],2=>[],3=>[]];
    $pi=[];
    $pr=[];
    $pairHits=[];

    foreach($PAIRS as $pair){
        $pi[$pair]=[];
        $pr[$pair]=[];
        $pairHits[$pair]=0;
    }

    /*
     * Process one pair at a time. This avoids loading all 20
     * large CSV datasets into RAM simultaneously.
     * $rowStore[eventIndex][pair] = signed return.
     */
    $rowStore=[];

    foreach($PAIRS as $pIdx=>$pair){
        $pathOld=DS_OLD.'FX_'.$pair.'.csv';
        $pathNew=DS_NEW.'FX_'.$pair.'.csv';
        $oldData=loadCompact($pathOld);
        $newData=loadCompact($pathNew);
        $oldKeys=array_keys($oldData);
        $newKeys=array_keys($newData);

        foreach($events as $i=>$ev){
            $data = ($ev['ts']<CUTOFF) ? $oldData : $newData;
            $keys = ($ev['ts']<CUTOFF) ? $oldKeys : $newKeys;
            $before=$ev['ts']+WIN_BEFORE*60;
            $after =$ev['ts']+WIN_AFTER*60;
            $a=findPrice($data,$keys,$before);
            $b=findPrice($data,$keys,$after);
            if($a===null||$b===null||$a==0) continue;

            $ret=(($b-$a)/$a)*100.0;
            $abs=abs($ret);
            $imp=$ev['impact'];

            $allImp[]=$imp;
            $allAbs[]=$abs;
            $pi[$pair][]=$imp;
            $pr[$pair][]=$abs;
            $groups[$imp][]=$abs;
            $pairHits[$pair]++;

            if(!isset($rowStore[$i])) $rowStore[$i]=[];
            $rowStore[$i][$pair]=$ret;
        }

        unset($oldData,$newData,$oldKeys,$newKeys,$data,$keys);
        gc_collect_cycles();
    }

    /* Rebuild event-level rows for pair-to-pair correlations. */
    foreach($rowStore as $i=>$signed){
        if(!$signed) continue;
        $imp=$events[$i]['impact'];
        $rows['all'][]=$signed;
        $rows[$imp][]=$signed;
    }
    unset($rowStore);

    $summary=[];
    foreach([1,2,3] as $imp){
        $v=$groups[$imp];
        $n=count($v);
        $large=0;
        foreach($v as $x) if($x>=SIGNIFICANT_THRESHOLD) $large++;
        $summary[]=[
            'impact'=>$imp,
            'stars'=>str_repeat('★',$imp),
            'observations'=>$n,
            'mean_abs_reaction'=>round(cMean($v),6),
            'median_abs_reaction'=>round(cMedian($v),6),
            'std_abs_reaction'=>round(cStd($v),6),
            'large_move_count'=>$large,
            'large_move_percentage'=>$n?round($large/$n*100,2):0
        ];
    }

    $pairResults=[];
    foreach($PAIRS as $p){
        $sp=cSpearman($pi[$p],$pr[$p]);
        $pe=cPearson($pi[$p],$pr[$p]);
        $pairResults[]=[
            'pair'=>$p,
            'observations'=>count($pi[$p]),
            'spearman'=>$sp===null?null:round($sp,6),
            'pearson'=>$pe===null?null:round($pe,6),
            'mean_abs_reaction'=>round(cMean($pr[$p]),6)
        ];
    }
    usort($pairResults,fn($a,$b)=>($b['spearman']??-999)<=>($a['spearman']??-999));

    $overallSp=cSpearman($allImp,$allAbs);
    $overallPe=cPearson($allImp,$allAbs);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'=>'success',
        'settings'=>[
            'window_before_minutes'=>WIN_BEFORE,
            'window_after_minutes'=>WIN_AFTER,
            'significant_threshold_percent'=>SIGNIFICANT_THRESHOLD
        ],
        'dataset'=>[
            'total_event_occurrences'=>count($events),
            'impact_1_events'=>$counts[1],
            'impact_2_events'=>$counts[2],
            'impact_3_events'=>$counts[3],
            'pairs_analysed'=>count($PAIRS),
            'total_pair_event_observations'=>count($allImp),
            'pair_hits'=>$pairHits
        ],
        'overall_correlation'=>[
            'spearman_impact_vs_absolute_reaction'=>$overallSp===null?null:round($overallSp,6),
            'pearson_impact_vs_absolute_reaction'=>$overallPe===null?null:round($overallPe,6)
        ],
        'impact_summary'=>$summary,
        'pair_impact_correlation'=>$pairResults,
        'pair_reaction_correlation'=>[
            'all'=>cMatrix($rows['all'],$PAIRS),
            'impact_1'=>cMatrix($rows[1],$PAIRS),
            'impact_2'=>cMatrix($rows[2],$PAIRS),
            'impact_3'=>cMatrix($rows[3],$PAIRS)
        ]
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if(isset($_GET['correlation'])) correlationResponse($PAIRS);

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
/* Correlation dashboard */
.corr-wrap{background:#fff;border:1px solid #ccc;border-radius:0 4px 4px 4px;padding:18px}.corr-loading{text-align:center;padding:50px;color:#777}.corr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.corr-card,.impact-box{background:#fff;border:1px solid #ddd;border-radius:6px;padding:14px}.corr-card .label,.impact-stat-label{font-size:10px;color:#777;text-transform:uppercase}.corr-card .value{font-size:25px;font-weight:bold;margin-top:5px;color:#2a6496}.corr-card .small{font-size:11px;color:#777;margin-top:5px}.corr-section{margin-bottom:30px}.corr-section h2{font-size:15px;margin:0 0 12px;padding-bottom:8px;border-bottom:2px solid #2a6496}.impact-visual{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.impact-title{font-size:18px;font-weight:bold;margin-bottom:12px}.impact-stat{margin-bottom:12px}.impact-stat-value{font-size:18px;font-weight:bold}.bar-track{width:100%;height:9px;background:#eee;border-radius:10px;overflow:hidden}.bar-fill{height:100%;background:#2a6496}.pair-ranking{width:100%;border-collapse:collapse}.pair-ranking th{background:#2a6496;color:#fff;padding:8px;font-size:11px}.pair-ranking td{padding:8px;border-bottom:1px solid #eee}.rank-bar{width:150px;height:8px;background:#eee;border-radius:10px;overflow:hidden}.rank-fill{height:100%;background:#2f855a}.matrix-controls{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.matrix-btn{padding:7px 13px;border:1px solid #ccc;background:#f4f4f4;cursor:pointer;border-radius:4px;font-size:12px;font-weight:bold}.matrix-btn.active{background:#2a6496;color:#fff;border-color:#2a6496}.matrix-wrap{overflow:auto;max-height:750px;border:1px solid #ccc}.corr-matrix{border-collapse:collapse;font-size:10px;width:auto}.corr-matrix th{position:sticky;top:0;z-index:2;background:#2a6496;color:#fff;padding:7px}.corr-matrix th:first-child{left:0;z-index:3}.corr-matrix td{min-width:58px;height:34px;padding:4px;text-align:center;border:1px solid #fff}.corr-matrix .row-name{position:sticky;left:0;z-index:1;background:#f5f5f5;font-weight:bold}.corr-positive{color:#155724!important}.corr-negative{color:#721c24!important}@media(max-width:700px){.impact-visual{grid-template-columns:1fr}}

</style>
</head><body>
<h1>Economic News Impact on Forex Pairs</h1>
<p class="subtitle">Methodology: Balduzzi, Elton &amp; Green (2001) · Window: T−5min → T+30min · All occurrences of each event across all dates</p>

<div id="progress">
  <h3>⏳ Processing pairs (fast scan)...</h3>
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
// Fast version: each CSV is scanned once with moving pointers instead of
// doing a binary-search + array_keys() call for every event.
$stats = [];   // event => pair => [n,sum,sumSq]
$pairCount = count($PAIRS);

// Split events by dataset folder once.
$oldEvents = [];
$newEvents = [];
foreach ($events as $i => $ev) {
    $ev['_i'] = $i;
    if ($ev['_ts'] < CUTOFF) $oldEvents[] = ['ts'=>$ev['_ts'], 'impact'=>$ev['impact'], '_i'=>$i];
    else                     $newEvents[] = ['ts'=>$ev['_ts'], 'impact'=>$ev['impact'], '_i'=>$i];
}

foreach ($PAIRS as $pIdx => $pair) {
    $pct  = round(($pIdx / max(1,$pairCount)) * 100);
    echo "<script>
      document.getElementById('pbar').style.width='{$pct}%';
      document.getElementById('pstatus').textContent='Processing {$pair} (".($pIdx+1)."/{$pairCount})...';
      document.getElementById('plog').innerHTML+='<div>→ Loading {$pair}...</div>';
      document.getElementById('plog').scrollTop=9999;
    </script>\n";
    flush();

    $pathOld = DS_OLD . 'FX_' . $pair . '.csv';
    $pathNew = DS_NEW . 'FX_' . $pair . '.csv';
    $oldData = loadCompact($pathOld);
    $newData = loadCompact($pathNew);
    $oldKeys = array_keys($oldData);
    $newKeys = array_keys($newData);

    $hits = 0;

    if (!empty($oldData) && !empty($oldEvents)) {
        $rets = computeNearestForEvents($oldData, $oldKeys, $oldEvents, CUTOFF);
        foreach ($rets as $idx => $ret) {
            $evName = $events[$idx]['event_name'];
            if (!isset($stats[$evName][$pair])) $stats[$evName][$pair] = ['n'=>0,'sum'=>0.0,'sumSq'=>0.0];
            $stats[$evName][$pair]['n']++;
            $stats[$evName][$pair]['sum'] += $ret;
            $stats[$evName][$pair]['sumSq'] += $ret*$ret;
            $hits++;
        }
        unset($rets);
    }

    if (!empty($newData) && !empty($newEvents)) {
        $rets = computeNearestForEvents($newData, $newKeys, $newEvents, CUTOFF);
        foreach ($rets as $idx => $ret) {
            $evName = $events[$idx]['event_name'];
            if (!isset($stats[$evName][$pair])) $stats[$evName][$pair] = ['n'=>0,'sum'=>0.0,'sumSq'=>0.0];
            $stats[$evName][$pair]['n']++;
            $stats[$evName][$pair]['sum'] += $ret;
            $stats[$evName][$pair]['sumSq'] += $ret*$ret;
            $hits++;
        }
        unset($rets);
    }

    $mem = round(memory_get_usage(true)/1048576, 1);
    echo "<script>
      document.getElementById('plog').innerHTML+='<div>  ✓ {$pair}: {$hits} obs | mem {$mem}MB</div>';
      document.getElementById('plog').scrollTop=9999;
    </script>\n";
    flush();

    unset($oldData,$newData,$oldKeys,$newKeys);
    gc_collect_cycles();
}

unset($oldEvents,$newEvents);

echo "<script>
  document.getElementById('pbar').style.width='100%';
  document.getElementById('pstatus').textContent='Computing statistics...';
</script>\n";
flush();

// ── STATISTICS ────────────────────────────────────────────────────────────────
$summary = [];
foreach ($stats as $evName => $pairData) {
    foreach ($pairData as $pair => $z) {
        $n = (int)$z['n'];
        if ($n < MIN_OBS) continue;
        $m = $z['sum'] / $n;
        $var = $n > 1 ? max(0.0, ($z['sumSq'] - $n*$m*$m) / ($n-1)) : 0.0;
        $se = ($n > 1 && $var > 0) ? sqrt($var / $n) : 0.0;
        $t  = $se > 0 ? $m / $se : 0.0;
        $r2 = $var > 0 ? min(1.0, ($m*$m)/$var) : 0.0;
        $summary[$evName][$pair] = [
            'mean'=>$m,
            't'=>$t,
            'n'=>$n,
            'r2'=>$r2,
            'stars'=>stars($t)
        ];
    }
}
unset($stats);

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
  <div class="tab" onclick="showTab('correlation',this);loadCorrelation();" style="background:#f3e8ff;border-color:#805ad5;color:#553c9a">📊 Correlation Analysis</div>
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


<!-- TAB 6: CORRELATION ANALYSIS -->
<div id="tab-correlation" class="tab-content">
<div class="corr-wrap">
  <div id="corr-loading" class="corr-loading">
    <div style="font-size:28px;margin-bottom:10px">📊</div>
    Loading correlation analysis...
    <div style="font-size:11px;color:#999;margin-top:6px">This uses the same T−5 → T+30 window as the main analysis.</div>
  </div>
  <div id="corr-error" class="corr-error"></div>
  <div id="corr-content" style="display:none">
    <div class="corr-section"><h2>Overall News Impact Relationship</h2>
      <div class="corr-grid">
        <div class="corr-card"><div class="label">Spearman</div><div class="value" id="corr-spearman">—</div><div class="small">Impact vs absolute movement</div></div>
        <div class="corr-card"><div class="label">Pearson</div><div class="value" id="corr-pearson">—</div><div class="small">Linear relationship</div></div>
        <div class="corr-card"><div class="label">News Occurrences</div><div class="value" id="corr-events">—</div><div class="small">Unique event timestamps</div></div>
        <div class="corr-card"><div class="label">Pair Observations</div><div class="value" id="corr-observations">—</div><div class="small">Event × pair reactions</div></div>
      </div>
    </div>
    <div class="corr-section"><h2>1★ vs 2★ vs 3★</h2><div class="impact-visual" id="impact-visual"></div></div>
    <div class="corr-section"><h2>Pairs Most Sensitive to News Impact</h2>
      <div class="tbl-wrap"><table class="pair-ranking"><thead><tr><th>Rank</th><th>Pair</th><th>Spearman</th><th>Strength</th><th>Obs</th><th>Mean |Move|</th></tr></thead><tbody id="pair-ranking-body"></tbody></table></div>
    </div>
    <div class="corr-section"><h2>Forex Pair Reaction Correlation</h2>
      <p style="font-size:12px;color:#666">Positive = same-direction movement. Negative = opposite-direction movement.</p>
      <div class="matrix-controls">
        <button class="matrix-btn active" onclick="showCorrelationMatrix('all',this)">All News</button>
        <button class="matrix-btn" onclick="showCorrelationMatrix('impact_1',this)">★ Low</button>
        <button class="matrix-btn" onclick="showCorrelationMatrix('impact_2',this)">★★ Medium</button>
        <button class="matrix-btn" onclick="showCorrelationMatrix('impact_3',this)">★★★ High</button>
      </div>
      <div class="matrix-wrap" id="correlation-matrix"></div>
    </div>
    <div class="corr-section"><h2>Interpretation</h2><div id="corr-interpretation" style="padding:16px;background:#f0f5fb;border:1px solid #c8dff0;border-radius:5px;line-height:1.7;font-size:13px"></div></div>
  </div>
</div>
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


/* CORRELATION DASHBOARD */
var correlationLoaded=false,correlationData=null;
function corrNum(v,d){return v===null||v===undefined||isNaN(v)?'—':Number(v).toFixed(d);}
function showCorrelationMatrix(type,btn){document.querySelectorAll('.matrix-btn').forEach(function(b){b.classList.remove('active');});if(btn)btn.classList.add('active');renderCorrelationMatrix(type);}
function loadCorrelation(){if(correlationLoaded)return;var l=document.getElementById('corr-loading'),c=document.getElementById('corr-content'),e=document.getElementById('corr-error');l.style.display='block';c.style.display='none';e.style.display='none';fetch(window.location.pathname+'?correlation=1',{cache:'no-store'}).then(function(r){return r.text();}).then(function(t){var d;try{d=JSON.parse(t);}catch(x){throw new Error('Server returned non-JSON: '+t.slice(0,250));}if(d.error)throw new Error(d.error);correlationData=d;correlationLoaded=true;renderCorrelationDashboard(d);l.style.display='none';c.style.display='block';}).catch(function(x){l.style.display='none';e.style.display='block';e.textContent='⚠ Correlation analysis failed: '+x.message;});}
function renderCorrelationDashboard(d){var o=d.overall_correlation||{},ds=d.dataset||{},sp=o.spearman_impact_vs_absolute_reaction,pe=o.pearson_impact_vs_absolute_reaction;var sEl=document.getElementById('corr-spearman');sEl.textContent=corrNum(sp,4);sEl.className='value '+(sp>=.1?'corr-positive':sp<=-.1?'corr-negative':'');var pEl=document.getElementById('corr-pearson');pEl.textContent=corrNum(pe,4);pEl.className='value '+(pe>=.1?'corr-positive':pe<=-.1?'corr-negative':'');document.getElementById('corr-events').textContent=ds.total_event_occurrences||'—';document.getElementById('corr-observations').textContent=ds.total_pair_event_observations||'—';renderImpactVisual(d.impact_summary||[]);renderPairRanking(d.pair_impact_correlation||[]);renderCorrelationMatrix('all');renderCorrInterpretation(sp);}
function renderImpactVisual(items){var c=document.getElementById('impact-visual');c.innerHTML='';var maxR=0,maxL=0;items.forEach(function(x){maxR=Math.max(maxR,Number(x.mean_abs_reaction||0));maxL=Math.max(maxL,Number(x.large_move_percentage||0));});items.forEach(function(x){var r=Number(x.mean_abs_reaction||0),m=Number(x.median_abs_reaction||0),l=Number(x.large_move_percentage||0),rw=maxR?r/maxR*100:0,lw=maxL?l/maxL*100:0,star=x.impact==3?'#b83232':x.impact==2?'#b7791f':'#2a6496';c.innerHTML+='<div class="impact-box"><div class="impact-title" style="color:'+star+'">'+x.stars+'</div><div class="impact-stat"><div class="impact-stat-label">Mean Absolute Movement</div><div class="impact-stat-value">'+r.toFixed(5)+'%</div><div class="bar-track"><div class="bar-fill" style="width:'+rw+'%;background:'+star+'"></div></div></div><div class="impact-stat"><div class="impact-stat-label">Median Movement</div><div class="impact-stat-value">'+m.toFixed(5)+'%</div></div><div class="impact-stat"><div class="impact-stat-label">Large Movement Probability</div><div class="impact-stat-value">'+l.toFixed(2)+'%</div><div class="bar-track"><div class="bar-fill" style="width:'+lw+'%;background:#b83232"></div></div></div><div style="font-size:11px;color:#777">Observations: <b>'+x.observations+'</b></div></div>';});}
function renderPairRanking(items){var tb=document.getElementById('pair-ranking-body');tb.innerHTML='';items.slice().sort(function(a,b){return Number(b.spearman||-999)-Number(a.spearman||-999);}).slice(0,15).forEach(function(x,i){var v=Number(x.spearman||0),w=Math.min(100,Math.abs(v)*100),bc=v>=0?'#2f855a':'#b83232';tb.innerHTML+='<tr><td>'+String(i+1)+'</td><td><b>'+x.pair+'</b></td><td class="'+(v>=.1?'corr-positive':v<=-.1?'corr-negative':'')+'"><b>'+corrNum(v,4)+'</b></td><td><div class="rank-bar"><div class="rank-fill" style="width:'+w+'%;background:'+bc+'"></div></div></td><td>'+x.observations+'</td><td>'+corrNum(x.mean_abs_reaction,5)+'%</td></tr>';});}
function renderCorrelationMatrix(type){if(!correlationData)return;var m=correlationData.pair_reaction_correlation[type];if(!m)return;var ps=Object.keys(m),h='<table class="corr-matrix"><thead><tr><th>Pair</th>';ps.forEach(function(p){h+='<th>'+p+'</th>';});h+='</tr></thead><tbody>';ps.forEach(function(a){h+='<tr><td class="row-name">'+a+'</td>';ps.forEach(function(b){var v=m[a][b],d=v===null||v===undefined?'—':Number(v).toFixed(2),style=correlationCellStyle(v);h+='<td style="'+style+'" title="'+a+' × '+b+': '+d+'">'+d+'</td>';});h+='</tr>';});h+='</tbody></table>';document.getElementById('correlation-matrix').innerHTML=h;}
function correlationCellStyle(v){if(v===null||v===undefined||isNaN(v))return'background:#f5f5f5;color:#aaa';v=Math.max(-1,Math.min(1,Number(v)));var a=Math.min(.9,Math.abs(v)),bg=v>0?'rgba(21,87,36,'+a+')':v<0?'rgba(114,28,36,'+a+')':'#eee',tc=Math.abs(v)>.45?'#fff':'#111';return'background:'+bg+';color:'+tc+';font-weight:'+(Math.abs(v)>.6?'bold':'normal');}
function renderCorrInterpretation(v){var el=document.getElementById('corr-interpretation'),t='';if(v===null||v===undefined)t='Correlation could not be calculated.';else if(v>=.5)t='<b>Strong positive relationship:</b> higher-impact news is strongly associated with larger absolute Forex movement.';else if(v>=.3)t='<b>Moderate positive relationship:</b> higher-star news generally produces larger reactions.';else if(v>=.1)t='<b>Weak positive relationship:</b> higher-star news tends to produce slightly larger reactions.';else if(v>-.1)t='<b>Little overall relationship:</b> the 1★ / 2★ / 3★ rating does not strongly predict movement size in this dataset.';else t='<b>Negative relationship:</b> higher-star news is not associated with larger absolute movement in this dataset.';el.innerHTML='<b>Overall Spearman: '+corrNum(v,4)+'</b><br><br>'+t+'<br><br><span style="font-size:11px;color:#666">Primary test: impact level (1/2/3) versus absolute T−5 to T+30 return.</span>';}
</script>
</body></html>