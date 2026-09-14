<?php
/**
 * FX Liquidity Dashboard — Optimized v3
 * Clean insights, proper candlestick, PDF export, full range support
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(180);

$BASE = '/home/sairedd1/public_html/dataset';
$SOURCES = [
    'hist' => ['dir'=>$BASE.'/JUN-2025 TO FEB-2026/', 'date_from'=>'2025-06-23','date_to'=>'2026-02-27','label'=>'Jun 2025 – Feb 2026'],
    '1min' => ['dir'=>$BASE.'/1min/',                  'date_from'=>'2025-06-23','date_to'=>'2099-12-31','label'=>'1-min Live'],
    '5min' => ['dir'=>$BASE.'/dataset/',               'date_from'=>'2026-04-22','date_to'=>'2099-12-31','label'=>'5-min (Apr 2026+)'],
    'all'  => ['dir'=>'MULTI',                         'date_from'=>'2025-06-23','date_to'=>'2099-12-31','label'=>'All Data Combined'],
];

function parse_ts($r){$r=trim($r);if($r===''||$r==='0')return false;if(ctype_digit($r)&&strlen($r)>=9)return(int)$r;$t=strtotime($r);return($t!==false&&$t>0)?$t:false;}
function scan_pairs($dir){$p=[];$f=glob($dir.'*.csv');if(!$f)return $p;foreach($f as $fp){$pair=strtoupper(preg_replace('/^FX_|\.csv$/i','',basename($fp)));$p[$pair]=$fp;}ksort($p);return $p;}

function read_csv($fp,$ts_from,$ts_to,$limit,$stride=1){
    // stride: read every Nth row for memory efficiency on large ranges
    $h=@fopen($fp,'r');if(!$h)return false;
    $rows=[];$hdr=null;$n=0;$buf=$limit>0?$limit:0;
    $hard_cap=60000; // never load more than 60k rows regardless of limit
    while(!feof($h)){
        $line=fgets($h,256);if($line===false)break;
        $line=rtrim($line,"\r\n");if($line==='')continue;
        $cols=explode(',',$line);
        if($hdr===null){$hdr=true;continue;}
        if(count($cols)<5)continue;
        $ts=parse_ts($cols[0]);
        if($ts===false)continue;if($ts<$ts_from)continue;if($ts>$ts_to)break;
        $n++;
        if($stride>1&&$n%$stride!==0)continue;
        $o=(float)$cols[1];$hv=(float)$cols[2];$l=(float)$cols[3];$c=(float)$cols[4];
        if($o<=0||$c<=0||$hv<$l)continue;
        $row=['t'=>$ts,'o'=>round($o,6),'h'=>round($hv,6),'l'=>round($l,6),'c'=>round($c,6)];
        if($buf>0){if(count($rows)>=$buf)array_shift($rows);}
        elseif(count($rows)>=$hard_cap)break; // hard cap for unlimited reads
        $rows[]=$row;
    }
    fclose($h);return $rows;
}

// ── API MODE ──
if(!empty($_GET['api'])){
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $src_key=trim($_GET['src']??'hist');
    $src=$SOURCES[$src_key]??$SOURCES['hist'];

    if(!empty($_GET['list'])){
        $pairs=scan_pairs($src['dir']);
        echo json_encode(['pairs'=>array_keys($pairs)]);exit;
    }

    $req_pairs=isset($_GET['pairs'])?array_map('strtoupper',array_map('trim',explode(',',$_GET['pairs']))):[];
    if(empty($req_pairs)&&!empty($_GET['pair']))$req_pairs=[strtoupper(trim($_GET['pair']))];

    // limit=0 means full range; stride controls density
    $limit  = max(0,(int)($_GET['limit']??0));
    $stride = max(1,(int)($_GET['stride']??1));
    // Safety: cap rows to prevent memory exhaustion
    // For full-range loads (limit=0), enforce a minimum stride
    // 1-min data for ~15 months ≈ 475k rows; we cap effective rows at 50k
    $MAX_ROWS = 50000;
    if($limit === 0 && $stride === 1) {
        // Estimate date span in minutes
        $span_days = max(1, ($ts_to - $ts_from) / 86400);
        $est_rows  = (int)($span_days * 24 * 60 * 0.7); // ~70% trading minutes
        if($est_rows > $MAX_ROWS) {
            $stride = max(1, (int)ceil($est_rows / $MAX_ROWS));
        }
    }
    $req_from=!empty($_GET['from'])?$_GET['from']:$src['date_from'];
    $req_to  =!empty($_GET['to'])  ?$_GET['to']  :$src['date_to'];
    $ts_from =max(strtotime($src['date_from']),strtotime($req_from));
    $ts_to   =min(strtotime($src['date_to'])+86399,strtotime($req_to)+86399);

    $available=scan_pairs($src['dir']);

    // ── COMBINED SOURCE: stitch hist + 1min seamlessly ──
    if($src_key === 'all') {
        // hist: up to Feb 27 2026
        // 1min: from Feb 28 2026 onwards (or from ts_from if later)
        $hist_dir  = $BASE.'/JUN-2025 TO FEB-2026/';
        $live_dir  = $BASE.'/1min/';
        $hist_pairs = scan_pairs($hist_dir);
        $live_pairs = scan_pairs($live_dir);

        // list mode
        if(!empty($_GET['list'])){
            $all_pairs = array_unique(array_merge(array_keys($hist_pairs), array_keys($live_pairs)));
            sort($all_pairs);
            echo json_encode(['pairs'=>$all_pairs]); exit;
        }

        $result=[]; $meta=[];
        foreach($req_pairs as $pair){
            $rows = [];
            // Part 1: hist
            if(isset($hist_pairs[$pair])){
                $h_ts_from = $ts_from;
                $h_ts_to   = min($ts_to, strtotime('2026-02-27')+86399);
                $r = read_csv($hist_pairs[$pair], $h_ts_from, $h_ts_to, $limit, $stride);
                if($r) $rows = array_merge($rows, $r);
            }
            // Part 2: 1min live
            if(isset($live_pairs[$pair])){
                $l_ts_from = max($ts_from, strtotime('2026-02-28'));
                if($l_ts_from <= $ts_to){
                    $r = read_csv($live_pairs[$pair], $l_ts_from, $ts_to, $limit, $stride);
                    if($r) $rows = array_merge($rows, $r);
                }
            }
            if(empty($rows)){ $meta[$pair]=['error'=>'no data']; continue; }
            // sort by timestamp
            usort($rows, function($a,$b){ return $a['t'] - $b['t']; });
            $meta[$pair]=['rows'=>count($rows),
                'first'=>date('Y-m-d H:i',$rows[0]['t']+19800).' IST',
                'last' =>date('Y-m-d H:i',$rows[count($rows)-1]['t']+19800).' IST'];
            $result[$pair] = $rows;
            unset($rows);
            gc_collect_cycles();
        }
        echo json_encode(['data'=>$result,'meta'=>$meta]); exit;
    }

    // ── 1MIN vs 5MIN COMPARE MODE ──
    if($src_key === 'compare') {
        $dir1 = $BASE.'/1min/';
        $dir5 = $BASE.'/dataset/';
        $pairs1 = scan_pairs($dir1);
        $pairs5 = scan_pairs($dir5);
        if(!empty($_GET['list'])){
            $common = array_intersect(array_keys($pairs1), array_keys($pairs5));
            echo json_encode(['pairs'=>array_values($common)]); exit;
        }
        $result=[]; $meta=[];
        foreach($req_pairs as $pair){
            $r1 = isset($pairs1[$pair]) ? read_csv($pairs1[$pair],$ts_from,$ts_to,$limit,$stride) : [];
            $r5 = isset($pairs5[$pair]) ? read_csv($pairs5[$pair],$ts_from,$ts_to,$limit,$stride) : [];
            if($r1) { $result['1min_'.$pair]=$r1; $meta['1min_'.$pair]=['rows'=>count($r1),'first'=>date('Y-m-d H:i',$r1[0]['t']+19800).' IST','last'=>date('Y-m-d H:i',$r1[count($r1)-1]['t']+19800).' IST']; }
            if($r5) { $result['5min_'.$pair]=$r5; $meta['5min_'.$pair]=['rows'=>count($r5),'first'=>date('Y-m-d H:i',$r5[0]['t']+19800).' IST','last'=>date('Y-m-d H:i',$r5[count($r5)-1]['t']+19800).' IST']; }
            gc_collect_cycles();
        }
        echo json_encode(['data'=>$result,'meta'=>$meta]); exit;
    }

    // All-pairs lightweight mode
    if(!empty($_GET['allpairs'])){
        $result=[];
        foreach($available as $pair=>$fp){
            $rows=read_csv($fp,$ts_from,$ts_to,0,10); // every 10th row
            if(!$rows||count($rows)<5)continue;
            $hl=array_map(function($r){return(($r['h']-$r['l'])/$r['c'])*10000;},$rows);
            $ts=array_map(function($r){return $r['t'];},$rows);
            $result[$pair]=['ts'=>$ts,'hl'=>$hl,'n'=>count($rows)*10,
                'avg'=>array_sum($hl)/count($hl),
                'first'=>date('Y-m-d',$rows[0]['t']+19800),'last'=>date('Y-m-d',$rows[count($rows)-1]['t']+19800)];
            unset($rows, $hl, $ts);
            gc_collect_cycles();
        }
        echo json_encode(['data'=>$result]);exit;
    }

    $result=[];$meta=[];
    foreach($req_pairs as $pair){
        if(!isset($available[$pair])){$meta[$pair]=['error'=>'not found'];continue;}
        $rows=read_csv($available[$pair],$ts_from,$ts_to,$limit,$stride);
        if(!$rows){$meta[$pair]=['error'=>'no data'];continue;}
        $meta[$pair]=['rows'=>count($rows),'first'=>date('Y-m-d H:i',$rows[0]['t']+19800).' IST','last'=>date('Y-m-d H:i',$rows[count($rows)-1]['t']+19800).' IST'];
        $result[$pair]=$rows;
        unset($rows);
        gc_collect_cycles();
    }
    // Stream JSON directly to avoid double-buffering large responses
    header('X-Rows: '.array_sum(array_column($meta,'rows')));
    echo json_encode(['data'=>$result,'meta'=>$meta]);
    unset($result);
    exit;
}

$default_pairs=scan_pairs($SOURCES['hist']['dir']);
$pair_opts='';
foreach($default_pairs as $p=>$fp)
    $pair_opts.='<option value="'.htmlspecialchars($p).'">'.htmlspecialchars(substr($p,0,3).'/'.substr($p,3)).'</option>';
$self_url='/'.ltrim(str_replace($_SERVER['DOCUMENT_ROOT'],'',$_SERVER['SCRIPT_FILENAME']),'/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FX Liquidity Dashboard</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-zoom/2.0.1/chartjs-plugin-zoom.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0d1117;--bg2:#161b22;--bg3:#21262d;--border:#30363d;--text:#e6edf3;--muted:#8b949e;
  --blue:#58a6ff;--green:#3fb950;--red:#f85149;--orange:#f0883e;--purple:#a371f7;--yellow:#e3b341;--cyan:#39d353}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif}

/* TOPBAR */
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:10px 20px;
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:sticky;top:0;z-index:100}
.logo{font-size:.95rem;font-weight:700;color:var(--blue);white-space:nowrap;margin-right:4px}
.logo span{color:var(--muted);font-weight:400;font-size:.7rem}
select,input[type=date],input[type=number]{background:var(--bg3);border:1px solid var(--border);
  color:var(--text);padding:5px 8px;border-radius:6px;font-size:.8rem;outline:none}
select:focus,input:focus{border-color:var(--blue)}
.ctrl{display:flex;flex-direction:column;gap:2px}
.lbl{font-size:.65rem;color:var(--muted);white-space:nowrap}
.btn{padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-size:.8rem;font-weight:600}
.btn:hover{opacity:.85}
.btn-blue{background:var(--blue);color:#0d1117}
.btn-green{background:var(--green);color:#0d1117}
.btn-purple{background:var(--purple);color:#fff}
.btn-sm{background:var(--bg3);border:1px solid var(--border);color:var(--text);padding:5px 10px}
.btn-danger{background:#f8514922;border:1px solid var(--red);color:var(--red);padding:5px 10px}
.sep{width:1px;height:26px;background:var(--border)}

/* TABS */
.tabs{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 20px;display:flex;gap:0;overflow-x:auto}
.tab{padding:9px 16px;font-size:.8rem;cursor:pointer;color:var(--muted);border-bottom:2px solid transparent;white-space:nowrap}
.tab:hover{color:var(--text)}
.tab.active{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}

/* STATUS */
#sbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:5px 20px;font-size:.75rem;
  color:var(--muted);display:flex;align-items:center;gap:8px}
.spin{width:11px;height:11px;border:2px solid var(--border);border-top-color:var(--blue);
  border-radius:50%;animation:spin .7s linear infinite;display:none}
@keyframes spin{to{transform:rotate(360deg)}}

/* PANELS */
.panel{display:none;padding:16px 20px 28px}
.panel.active{display:block}

/* INSIGHTS BAR */
.insights-bar{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:14px}
.ic{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;position:relative;overflow:hidden}
.ic::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.ic.blue::before{background:var(--blue)}.ic.green::before{background:var(--green)}
.ic.orange::before{background:var(--orange)}.ic.red::before{background:var(--red)}
.ic.purple::before{background:var(--purple)}.ic.yellow::before{background:var(--yellow)}
.ic.cyan::before{background:var(--cyan)}
.ic .val{font-size:1.15rem;font-weight:700;margin-bottom:2px}
.ic.blue .val{color:var(--blue)}.ic.green .val{color:var(--green)}.ic.orange .val{color:var(--orange)}
.ic.red .val{color:var(--red)}.ic.purple .val{color:var(--purple)}.ic.yellow .val{color:var(--yellow)}
.ic.cyan .val{color:var(--cyan)}
.ic .lbl{font-size:.65rem;color:var(--muted)}
.ic .sub{font-size:.62rem;color:var(--muted);margin-top:3px;opacity:.7}

/* INSIGHT CARDS */
.insight-strip{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.ins-card{background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px 14px}
.ins-card h4{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.ins-card p{font-size:.8rem;color:var(--text);line-height:1.6}
.ins-card p b{color:var(--blue)}
.ins-card p .red{color:var(--red)}.ins-card p .green{color:var(--green)}.ins-card p .orange{color:var(--orange)}

/* GRID */
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px}
.row-full{margin-bottom:12px}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px}
.ct{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);
  margin-bottom:8px;display:flex;align-items:center;gap:6px}
.dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.cw{position:relative}
.h140{height:140px}.h180{height:180px}.h220{height:220px}.h260{height:260px}.h300{height:300px}

/* ZOOM ROW */
.zr{display:flex;gap:6px;margin-bottom:6px;align-items:center}
.zr span{font-size:.68rem;color:var(--muted);margin-left:auto}

/* TABLE */
.tw{overflow-x:auto;max-height:280px;overflow-y:auto}
table{width:100%;border-collapse:collapse;font-size:.76rem}
th{background:var(--bg3);color:var(--muted);padding:6px 10px;text-align:left;
  position:sticky;top:0;font-weight:600;font-size:.67rem;text-transform:uppercase}
td{padding:5px 10px;border-bottom:1px solid var(--bg3)}
tr:hover td{background:var(--bg3)}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.67rem;font-weight:700}
.b-red{background:#f8514922;color:var(--red)}.b-orange{background:#f0883e22;color:var(--orange)}
.b-yellow{background:#e3b34122;color:var(--yellow)}.b-green{background:#3fb95022;color:var(--green)}

/* HEATMAP */
.hm{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;margin-top:8px}
.hmc{border-radius:3px;padding:4px 0;display:flex;align-items:center;justify-content:center;
  font-size:.5rem;cursor:pointer;transition:transform .1s;color:rgba(255,255,255,.85)}
.hmc:hover{transform:scale(1.3);z-index:10;position:relative}
.hml{display:grid;grid-template-columns:repeat(24,1fr);gap:2px;margin-top:2px}
.hmll{font-size:.5rem;color:var(--muted);text-align:center}

/* RANK BAR */
.rb{background:var(--bg3);border-radius:3px;height:6px;overflow:hidden;margin-top:3px}
.rf{height:100%;border-radius:3px}

/* CORR */
.corr-grid{display:grid;gap:2px;margin-top:8px}
.cc{border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:.52rem;font-weight:600;cursor:pointer}

/* PCA */
.pca-box{background:var(--bg3);border-radius:6px;padding:10px 12px;font-size:.75rem;color:var(--muted);margin-top:10px;line-height:1.7}
.pca-box b{color:var(--text)}

/* EMPTY */
.empty{display:flex;align-items:center;justify-content:center;height:100px;color:var(--muted);font-size:.8rem}

/* ── PRINT / PDF ── */
@media print{
  .topbar,.tabs,#sbar,.zr,.btn,.btn-sm,.btn-danger,.btn-blue,.btn-green,.btn-purple{display:none!important}
  body{background:#fff!important;color:#000!important}
  .panel{display:block!important;padding:8px!important}
  .card,.ic,.ins-card{background:#fff!important;border:1px solid #ddd!important;break-inside:avoid}
  .ic .val,.ins-card p b{color:#1a1a2e!important}
  .ic .lbl,.ins-card h4,.ct{color:#555!important}
  .insights-bar{grid-template-columns:repeat(4,1fr)!important}
  .insight-strip{grid-template-columns:repeat(2,1fr)!important}
  .row3{grid-template-columns:1fr 1fr!important}
  canvas{max-height:180px!important}
  h1,h2{color:#000!important}
  .pdf-header{display:block!important}
}
.pdf-header{display:none;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #ddd}
.pdf-header h2{font-size:1.2rem;color:#1a1a2e}
.pdf-header p{font-size:.8rem;color:#555;margin-top:4px}

@media(max-width:960px){.insights-bar{grid-template-columns:repeat(4,1fr)}.row2,.row3{grid-template-columns:1fr}.insight-strip{grid-template-columns:1fr}}
@media(max-width:600px){.insights-bar{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="logo">FX Liquidity <span>Advanced · Mancini et al. 2013</span></div>
  <div class="ctrl"><span class="lbl">Source</span>
    <select id="sel-src" onchange="onSrcChange()">
      <option value="all">🔗 All Data Combined (Jun 2025→Now)</option>
      <option value="hist">📁 Jun 2025 – Feb 2026 (hist)</option>
      <option value="1min">⚡ 1-min Live</option>
      <option value="5min">📊 5-min (Apr 2026+)</option>
      <option value="compare">🔀 1min vs 5min Compare</option>
    </select>
  </div>
  <div class="ctrl"><span class="lbl">Pair</span>
    <select id="sel-pairs" style="min-width:130px"><?= $pair_opts ?></select>
  </div>
  <div class="ctrl"><span class="lbl">From</span>
    <input type="date" id="inp-from" value="2025-06-23">
  </div>
  <div class="ctrl"><span class="lbl">To</span>
    <input type="date" id="inp-to" value="2026-02-27">
  </div>
  <div class="ctrl"><span class="lbl">Density</span>
    <select id="sel-density">
      <option value="1">Full (slow)</option>
      <option value="5" selected>1-of-5</option>
      <option value="15">1-of-15</option>
      <option value="30">1-of-30</option>
    </select>
  </div>
  <button class="btn btn-blue" onclick="loadData()">▶ Load</button>
  <button class="btn btn-green" onclick="loadAllPairs()">⚡ All Pairs</button>
  <div class="sep"></div>
  <button class="btn btn-purple" onclick="exportPDF()">📄 PDF</button>
  <button class="btn btn-sm" onclick="resetZoom()">🔍 Reset</button>
  <button class="btn btn-danger" onclick="clearAll()">✕</button>
</div>

<!-- TABS -->
<div class="tabs">
  <div class="tab active" onclick="showTab('overview',this)">📊 Overview</div>
  <div class="tab" onclick="showTab('insights',this)">💡 Insights</div>
  <div class="tab" onclick="showTab('regimes',this)">🚦 Regimes</div>
  <div class="tab" onclick="showTab('commonality',this)">🔗 Commonality / PCA</div>
  <div class="tab" onclick="showTab('rank',this)">🏆 Pair Rank</div>
  <div class="tab" onclick="showTab('intraday',this)">⏰ Intraday</div>
  <div class="tab" onclick="openCompareTab(this)">🔀 1min vs 5min</div>
</div>

<!-- STATUS -->
<div id="sbar"><div class="spin" id="spin"></div><span id="smsg">Select a pair and click Load.</span></div>

<!-- ════════ OVERVIEW ════════ -->
<div class="panel active" id="panel-overview">
  <!-- PDF header (only shows in print) -->
  <div class="pdf-header">
    <h2>FX Liquidity Analysis Report</h2>
    <p id="pdf-subtitle">Generated: <?= date('Y-m-d H:i', strtotime('+5 hours 30 minutes')) ?> IST</p>
  </div>

  <div class="insights-bar">
    <div class="ic blue"><div class="val" id="sv-price">—</div><div class="lbl">Last Close</div></div>
    <div class="ic green"><div class="val" id="sv-rows">—</div><div class="lbl">Candles</div><div class="sub" id="sv-range">—</div></div>
    <div class="ic orange"><div class="val" id="sv-spread">—</div><div class="lbl">Avg HL Spread (bps)</div></div>
    <div class="ic red"><div class="val" id="sv-peak">—</div><div class="lbl">Peak Spread (bps)</div></div>
    <div class="ic purple"><div class="val" id="sv-roll">—</div><div class="lbl">Avg Roll Spread</div></div>
    <div class="ic yellow"><div class="val" id="sv-spikes">—</div><div class="lbl">Crisis Spikes (>p90)</div></div>
    <div class="ic cyan"><div class="val" id="sv-regime">—</div><div class="lbl">Current Regime</div></div>
  </div>

  <div class="row2">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--blue)"></div>Candlestick — Price
        <span style="margin-left:auto;font-size:.65rem;color:var(--muted)">scroll=zoom · drag=pan</span>
      </div>
      <div class="zr">
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="zoomC('c-candle','in')">+</button>
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="zoomC('c-candle','out')">−</button>
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="resetZoom()">↺</button>
      </div>
      <div class="cw h260"><canvas id="c-candle"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--orange)"></div>HL Spread Proxy (bps) — Bid-Ask Estimate
        <span id="hl-avg-line" style="margin-left:auto;font-size:.68rem;color:var(--orange)"></span>
      </div>
      <div class="zr">
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="zoomC('c-hl','in')">+</button>
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="zoomC('c-hl','out')">−</button>
        <button class="btn btn-sm" style="padding:3px 8px;font-size:.72rem" onclick="resetZoom()">↺</button>
      </div>
      <div class="cw h260"><canvas id="c-hl"></canvas></div>
    </div>
  </div>

  <div class="row3">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--purple)"></div>Roll Spread — Bid-Ask Bounce (bps)</div>
      <div class="cw h180"><canvas id="c-roll"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--red)"></div>Amihud Illiquidity (normalised 0–1)</div>
      <div class="cw h180"><canvas id="c-amihud"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--yellow)"></div>Return Volatility — Rolling 20 (bps)</div>
      <div class="cw h180"><canvas id="c-vol"></canvas></div>
    </div>
  </div>

  <div class="row2">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--green)"></div>Intraday Heatmap — Avg HL Spread by Hour (IST)</div>
      <div class="hm" id="hm-ov"></div>
      <div class="hml" id="hml-ov"></div>
      <div style="display:flex;gap:14px;margin-top:6px;font-size:.68rem;color:var(--muted);flex-wrap:wrap">
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;background:#1a6bdb;border-radius:2px;display:inline-block"></span>Liquid (IST hrs)</span>
        <span style="display:flex;align-items:center;gap:4px"><span style="width:10px;height:10px;background:#dc2626;border-radius:2px;display:inline-block"></span>Illiquid</span>
        <span style="margin-left:auto" id="hm-best"></span>
        <span id="hm-worst"></span>
      </div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--red)"></div>Top 15 Most Illiquid Candles</div>
      <div class="tw">
        <table><thead><tr><th>#</th><th>Time (IST)</th><th>Close</th><th>HL Spread (bps)</th><th>Regime</th></tr></thead>
        <tbody id="tbl-body"><tr><td colspan="5"><div class="empty">Load data to see top illiquid candles</div></td></tr></tbody></table>
      </div>
    </div>
  </div>

  <div class="card row-full">
    <div class="ct"><div class="dot" style="background:var(--blue)"></div>Daily Avg HL Spread (bps) — Full Period
      <span id="daily-note" style="margin-left:auto;font-size:.67rem;color:var(--muted)"></span>
    </div>
    <div class="cw h140"><canvas id="c-daily"></canvas></div>
  </div>
</div>

<!-- ════════ INSIGHTS ════════ -->
<div class="panel" id="panel-insights">
  <div class="insight-strip" id="insight-cards">
    <div class="ins-card"><h4>📋 Summary</h4><p>Load a pair to see insights.</p></div>
  </div>
  <div class="row2" style="margin-bottom:12px">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--orange)"></div>HL Spread Distribution (Histogram)</div>
      <div class="cw h220"><canvas id="c-hist"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--purple)"></div>Liquidity vs Volatility Scatter</div>
      <div class="cw h220"><canvas id="c-lv-scatter"></canvas></div>
    </div>
  </div>
  <div class="card row-full">
    <div class="ct"><div class="dot" style="background:var(--cyan)"></div>Cumulative Illiquidity Score Over Time</div>
    <div class="cw h200"><canvas id="c-cumil"></canvas></div>
  </div>
</div>

<!-- ════════ REGIMES ════════ -->
<div class="panel" id="panel-regimes">
  <div class="card row-full" style="margin-bottom:12px">
    <div class="ct"><div class="dot" style="background:var(--cyan)"></div>Liquidity Regime Timeline
      <span style="margin-left:8px;font-size:.67rem;color:var(--muted)">
        🟢 Normal (&lt;p33) &nbsp; 🟡 Stressed (p33–p67) &nbsp; 🟠 Illiquid (p67–p90) &nbsp; 🔴 Crisis (&gt;p90)
      </span>
    </div>
    <div class="cw h300"><canvas id="c-regime"></canvas></div>
  </div>
  <div class="row3">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--green)"></div>Time in Each Regime (%)</div>
      <div class="cw h200"><canvas id="c-rpie"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--orange)"></div>Avg Spread per Regime (bps)</div>
      <div class="cw h200"><canvas id="c-ravg"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--red)"></div>Crisis Episodes per Day</div>
      <div class="cw h200"><canvas id="c-rtrans"></canvas></div>
    </div>
  </div>
</div>

<!-- ════════ COMMONALITY ════════ -->
<div class="panel" id="panel-commonality">
  <div class="row2" style="margin-bottom:12px">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--blue)"></div>Correlation Matrix — HL Spread Across Pairs</div>
      <div id="corr-container"><div class="empty">Click ⚡ All Pairs first</div></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--purple)"></div>PCA — Variance Explained by Component</div>
      <div class="cw h220"><canvas id="c-pca-var"></canvas></div>
      <div class="pca-box" id="pca-info">Click ⚡ All Pairs to run PCA.</div>
    </div>
  </div>
  <div class="row2">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--cyan)"></div>PC1 — Market-Wide Liquidity Factor</div>
      <div class="cw h220"><canvas id="c-pc1"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--orange)"></div>Commonality R² per Pair (regression on PC1)</div>
      <div class="cw h220"><canvas id="c-r2"></canvas></div>
    </div>
  </div>
</div>

<!-- ════════ RANK ════════ -->
<div class="panel" id="panel-rank">
  <div class="card row-full" style="margin-bottom:12px">
    <div class="ct"><div class="dot" style="background:var(--red)"></div>Pair Liquidity Ranking — Most Illiquid → Most Liquid</div>
    <div id="rank-container"><div class="empty">Click ⚡ All Pairs to load ranking</div></div>
  </div>
  <div class="card row-full">
    <div class="ct"><div class="dot" style="background:var(--orange)"></div>Avg HL Spread (bps) by Pair</div>
    <div class="cw h260"><canvas id="c-rank-bar"></canvas></div>
  </div>
</div>

<!-- ════════ INTRADAY ════════ -->
<div class="panel" id="panel-intraday">
  <div class="row2" style="margin-bottom:12px">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--green)"></div>Avg HL Spread by Hour (IST) — Session Pattern</div>
      <div class="cw h220"><canvas id="c-hourly"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--purple)"></div>Avg HL Spread by Day of Week</div>
      <div class="cw h220"><canvas id="c-dow"></canvas></div>
    </div>
  </div>
  <div class="card row-full">
    <div class="ct"><div class="dot" style="background:var(--blue)"></div>Price vs HL Spread — Liquidity at Each Price Level</div>
    <div class="cw h260"><canvas id="c-scatter"></canvas></div>
  </div>
</div>

<!-- ════════ COMPARE PANEL ════════ -->
<div class="panel" id="panel-compare">
  <div class="insights-bar" style="grid-template-columns:repeat(4,1fr);margin-bottom:14px">
    <div class="ic blue" ><div class="val" id="cmp-rows1">—</div><div class="lbl">1-min Candles</div></div>
    <div class="ic green"><div class="val" id="cmp-rows5">—</div><div class="lbl">5-min Candles</div></div>
    <div class="ic orange"><div class="val" id="cmp-avg1">—</div><div class="lbl">1-min Avg HL (bps)</div></div>
    <div class="ic purple"><div class="val" id="cmp-avg5">—</div><div class="lbl">5-min Avg HL (bps)</div></div>
  </div>
  <div class="card row-full" style="margin-bottom:12px">
    <div class="ct"><div class="dot" style="background:var(--blue)"></div>1-min HL Spread
      <span style="display:inline-flex;align-items:center;gap:5px;margin-left:10px"><span style="width:20px;height:2px;background:#58a6ff;display:inline-block"></span>1-min</span>
      <span style="display:inline-flex;align-items:center;gap:5px;margin-left:8px"><span style="width:20px;height:2px;background:#f0883e;display:inline-block"></span>5-min (resampled)</span>
    </div>
    <div class="cw h280"><canvas id="c-cmp-hl"></canvas></div>
  </div>
  <div class="row2" style="margin-bottom:12px">
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--purple)"></div>1-min Roll Spread vs 5-min Roll Spread (bps)</div>
      <div class="cw h220"><canvas id="c-cmp-roll"></canvas></div>
    </div>
    <div class="card">
      <div class="ct"><div class="dot" style="background:var(--yellow)"></div>1-min Volatility vs 5-min Volatility (bps)</div>
      <div class="cw h220"><canvas id="c-cmp-vol"></canvas></div>
    </div>
  </div>
  <div class="card row-full">
    <div class="ct"><div class="dot" style="background:var(--cyan)"></div>Intraday Pattern Comparison — 1-min vs 5-min Avg HL Spread by Hour (IST)</div>
    <div class="cw h220"><canvas id="c-cmp-hourly"></canvas></div>
  </div>
  <div class="card row-full" style="margin-top:12px">
    <div class="ct"><div class="dot" style="background:var(--green)"></div>Key Insights — 1-min vs 5-min Liquidity</div>
    <div id="cmp-insights" style="padding:4px 0;font-size:.82rem;color:var(--muted);line-height:1.8">
      Select <b style="color:var(--text)">🔀 1min vs 5min Compare</b> source, pick a pair, click Load.
    </div>
  </div>
</div>

<script>
const API='<?= htmlspecialchars($self_url) ?>';
const COLORS=['#58a6ff','#f0883e','#3fb950','#a371f7','#f85149','#e3b341','#39d353','#ff7b72'];
let charts={},loadedData={},allData={};

// ── SAFE CHART DESTROY ──
const MAX_PTS = 800;  // never render more than this many points per series
function safeDestroy(id){
  try{ if(charts[id]){ charts[id].destroy(); delete charts[id]; } }catch(e){ delete charts[id]; }
}

// ── IST HELPERS (UTC+5:30) ──
const IST_OFFSET = 5.5 * 60 * 60 * 1000; // 5h30m in ms
function toIST(date){ return new Date(date.getTime() + IST_OFFSET); }
function fmtIST(ts, fmt='datetime'){
  const d = toIST(new Date(ts * 1000));
  const Y=d.getUTCFullYear(), M=String(d.getUTCMonth()+1).padStart(2,'0');
  const D=String(d.getUTCDate()).padStart(2,'0');
  const h=String(d.getUTCHours()).padStart(2,'0'), m=String(d.getUTCMinutes()).padStart(2,'0');
  if(fmt==='date') return `${Y}-${M}-${D}`;
  if(fmt==='hour') return d.getUTCHours();
  if(fmt==='dow')  return d.getUTCDay();
  return `${Y}-${M}-${D} ${h}:${m}`;
}
function fmtISTfromDate(d, fmt='datetime'){
  const di = toIST(d);
  const Y=di.getUTCFullYear(), M=String(di.getUTCMonth()+1).padStart(2,'0');
  const DD=String(di.getUTCDate()).padStart(2,'0');
  const h=String(di.getUTCHours()).padStart(2,'0'), mn=String(di.getUTCMinutes()).padStart(2,'0');
  if(fmt==='date') return `${Y}-${M}-${DD}`;
  if(fmt==='hour') return di.getUTCHours();
  if(fmt==='dow')  return di.getUTCDay();
  return `${Y}-${M}-${DD} ${h}:${mn}`;
}

// ── TABS ──
function showTab(n,el){
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.getElementById('panel-'+n).classList.add('active');
  el.classList.add('active');
}

// ── STATUS ──
function setS(t,m){
  document.getElementById('smsg').textContent=m;
  document.getElementById('spin').style.display=t==='loading'?'block':'none';
  document.getElementById('sbar').style.borderBottomColor=t==='error'?'var(--red)':t==='ok'?'var(--green)':'var(--border)';
}

// ── BASE CHART OPTIONS ──
function bOpts(zoom=true){
  return{responsive:true,maintainAspectRatio:false,animation:{duration:200},
    plugins:{
      legend:{display:false},
      tooltip:{callbacks:{label:c=>' '+(typeof c.parsed.y==='number'?c.parsed.y.toFixed(5):'')},
        backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,titleColor:'#8b949e',bodyColor:'#e6edf3'},
      zoom:zoom?{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}}:{}
    },
    scales:{
      x:{ticks:{color:'#8b949e',maxTicksLimit:10,font:{size:9}},grid:{color:'#21262d'},border:{color:'#30363d'}},
      y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'},border:{color:'#30363d'}}
    }
  };
}

function mkLine(id,datasets,labels,zoom=true){
  const ctx=document.getElementById(id);if(!ctx)return;
  safeDestroy(id);
  charts[id]=new Chart(ctx,{type:'line',
    data:{labels,datasets:datasets.map(d=>({
      data:d.data,label:d.label||'',borderColor:d.color,
      backgroundColor:d.fill?(d.color+'18'):'transparent',
      borderWidth:d.w||1.5,pointRadius:0,fill:!!d.fill
    }))},
    options:{...bOpts(zoom),plugins:{...bOpts(zoom).plugins,
      legend:{display:datasets.length>1,labels:{color:'#8b949e',font:{size:9},boxWidth:10}}}}
  });
}

function mkBar(id,labels,data,bgColors,horiz=false,annot=null){
  const ctx=document.getElementById(id);if(!ctx)return;
  safeDestroy(id);
  const opts={indexAxis:horiz?'y':'x',responsive:true,maintainAspectRatio:false,
    animation:{duration:200},plugins:{legend:{display:false},
      tooltip:{backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,titleColor:'#8b949e',bodyColor:'#e6edf3'}},
    scales:{x:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'},border:{color:'#30363d'}},
            y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'},border:{color:'#30363d'}}}};
  charts[id]=new Chart(ctx,{type:'bar',
    data:{labels,datasets:[{data,backgroundColor:bgColors||data.map(()=>'#58a6ff55'),borderWidth:0}]},options:opts});
}

function mkPie(id,labels,data,colors){
  const ctx=document.getElementById(id);if(!ctx)return;
  safeDestroy(id);
  charts[id]=new Chart(ctx,{type:'doughnut',
    data:{labels,datasets:[{data,backgroundColor:colors,borderWidth:0,hoverOffset:6}]},
    options:{responsive:true,maintainAspectRatio:false,animation:{duration:200},cutout:'55%',
      plugins:{legend:{display:true,position:'right',labels:{color:'#8b949e',font:{size:9},boxWidth:10}},
        tooltip:{backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1}}}});
}

function zoomC(id,d){const c=charts[id];if(!c)return;d==='in'?c.zoom(1.3):c.zoom(0.7);}
function resetZoom(){Object.values(charts).forEach(c=>{try{c.resetZoom();}catch(e){}});}

// ── CANDLESTICK (proper OHLC floating bars) ──
function mkCandle(id,rows,step){
  const ctx=document.getElementById(id);if(!ctx)return;
  safeDestroy(id);
  const sr=rows.filter((_,i)=>i%step===0);
  const labels=sr.map(r=>fmtIST(r.t,'datetime'));
  const wickD=sr.map(r=>[r.l,r.h]);          // full wick
  const bodyD=sr.map(r=>[Math.min(r.o,r.c),Math.max(r.o,r.c)]);  // body
  const bull=sr.map(r=>r.c>=r.o);
  const bodyBg=bull.map(b=>b?'#3fb95088':'#f8514988');
  const bodyBd=bull.map(b=>b?'#3fb950':'#f85149');
  const wickBd=bull.map(b=>b?'#3fb95066':'#f8514966');

  charts[id]=new Chart(ctx,{type:'bar',
    data:{labels,datasets:[
      {data:wickD,backgroundColor:'transparent',borderColor:wickBd,borderWidth:1,barPercentage:.08,label:'Wick'},
      {data:bodyD,backgroundColor:bodyBg,borderColor:bodyBd,borderWidth:1,barPercentage:.5,label:'Body'}
    ]},
    options:{responsive:true,maintainAspectRatio:false,animation:false,
      plugins:{legend:{display:false},
        tooltip:{callbacks:{
          title:c=>labels[c[0].dataIndex],
          label:c=>{const r=sr[c.dataIndex];return `O:${r.o.toFixed(5)}  H:${r.h.toFixed(5)}  L:${r.l.toFixed(5)}  C:${r.c.toFixed(5)}`;}
        },backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,titleColor:'#8b949e',bodyColor:'#e6edf3'},
        zoom:{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}}
      },
      scales:{
        x:{ticks:{color:'#8b949e',maxTicksLimit:10,font:{size:9}},grid:{color:'#21262d'},border:{color:'#30363d'},stacked:false},
        y:{ticks:{color:'#8b949e',font:{size:9},callback:v=>v.toFixed(4)},grid:{color:'#21262d'},border:{color:'#30363d'},stacked:false}
      }
    }
  });
}

// ── COMPUTE METRICS ──
function compute(rows){
  const n=rows.length;
  const closes=rows.map(r=>r.c),times=rows.map(r=>new Date(r.t*1000));
  const hlBps=rows.map(r=>((r.h-r.l)/r.c)*10000);

  const rets=[0];
  for(let i=1;i<n;i++)rets.push((closes[i]-closes[i-1])/closes[i-1]);

  const W=20,rollBps=new Array(n).fill(0);
  // Use typed array for memory efficiency
  for(let i=W;i<n;i++){
    let s1=0,s2=0;
    for(let j=i-W;j<i-1;j++){s1+=rets[j];s2+=rets[j+1];}
    const m1=s1/(W-1),m2=s2/(W-1);
    let cov=0;
    for(let j=i-W;j<i-1;j++) cov+=(rets[j]-m1)*(rets[j+1]-m2);
    cov/=(W-1);
    rollBps[i]=2*Math.sqrt(Math.max(-cov,0))*10000;
  }

  const amRaw=rows.map((r,i)=>Math.abs(rets[i])/Math.max(r.h-r.l,1e-10));
  const amMax=Math.max(...amRaw.filter(v=>isFinite(v)));
  const amihud=amRaw.map(v=>Math.min(v/amMax,1));

  const volBps=new Array(n).fill(0);
  for(let i=W;i<n;i++){
    const sl=rets.slice(i-W,i),mn=sl.reduce((a,b)=>a+b,0)/W;
    volBps[i]=Math.sqrt(sl.reduce((a,b)=>a+(b-mn)**2,0)/W)*10000;
  }

  const sorted=[...hlBps].sort((a,b)=>a-b);
  const pct=p=>sorted[Math.floor(n*p)];
  const p25=pct(.25),p33=pct(.33),p50=pct(.5),p67=pct(.67),p75=pct(.75),p90=pct(.9),p95=pct(.95);
  const regimes=hlBps.map(v=>v>=p90?3:v>=p67?2:v>=p33?1:0);
  const rNames=['Normal','Stressed','Illiquid','Crisis'];

  // Hourly
  const hSum=new Array(24).fill(0),hCnt=new Array(24).fill(0);
  times.forEach((d,i)=>{const h=fmtISTfromDate(d,'hour');hSum[h]+=hlBps[i];hCnt[h]++;});
  const hourlyAvg=hSum.map((s,i)=>hCnt[i]>0?s/hCnt[i]:0);

  // DOW
  const dSum=new Array(7).fill(0),dCnt=new Array(7).fill(0);
  times.forEach((d,i)=>{const dow=fmtISTfromDate(d,'dow');dSum[dow]+=hlBps[i];dCnt[dow]++;});
  const dowAvg=dSum.map((s,i)=>dCnt[i]>0?s/dCnt[i]:0);

  // Daily
  const dMap={};
  times.forEach((d,i)=>{const day=fmtISTfromDate(d,'date');if(!dMap[day])dMap[day]={s:0,c:0};dMap[day].s+=hlBps[i];dMap[day].c++;});
  const dLabels=Object.keys(dMap).sort(),dVals=dLabels.map(d=>dMap[d].s/dMap[d].c);

  // Cumulative illiquidity
  const cumIl=[];let cum=0;
  hlBps.forEach(v=>{cum+=v;cumIl.push(cum/10000);});

  const avg=hlBps.reduce((a,b)=>a+b,0)/n;
  const peak=Math.max(...hlBps);
  const avgRoll=rollBps.reduce((a,b)=>a+b,0)/n;
  const spikes=hlBps.filter(v=>v>p90).length;
  const curReg=rNames[regimes[regimes.length-1]];
  const labels=times.map(d=>fmtISTfromDate(d,'datetime'));
  const skew=(avg-p50)/Math.max(p95-p25,1e-10); // rough skew

  return{labels,closes,hlBps,rollBps,amihud,volBps,regimes,rNames,
    p25,p33,p50,p67,p75,p90,p95,hourlyAvg,dowAvg,dLabels,dVals,cumIl,
    avg,peak,avgRoll,spikes,curReg,times,rets,skew,n};
}

// ── STATS ──
function updateStats(rows,m,pair,first,last){
  const r=rows[rows.length-1];
  document.getElementById('sv-price').textContent=r.c.toFixed(5);
  document.getElementById('sv-rows').textContent=rows.length.toLocaleString();
  document.getElementById('sv-range').textContent=first.slice(0,10)+' → '+last.slice(0,10);
  document.getElementById('sv-spread').textContent=m.avg.toFixed(3);
  document.getElementById('sv-peak').textContent=m.peak.toFixed(3);
  document.getElementById('sv-roll').textContent=m.avgRoll.toFixed(3);
  document.getElementById('sv-spikes').textContent=m.spikes+' ('+((m.spikes/m.n)*100).toFixed(1)+'%)';
  const re=document.getElementById('sv-regime');
  re.textContent=m.curReg;
  re.style.color={Normal:'var(--green)',Stressed:'var(--yellow)',Illiquid:'var(--orange)',Crisis:'var(--red)'}[m.curReg];
  document.getElementById('hl-avg-line').textContent='avg='+m.avg.toFixed(3)+' bps';
  document.getElementById('daily-note').textContent=m.dLabels.length+' trading days';
  document.getElementById('pdf-subtitle').textContent=`${pair} | ${first.slice(0,10)} → ${last.slice(0,10)} | Generated: ${new Date().toLocaleString()}`;
}

// ── HEATMAP ──
function mkHeatmap(ha,hmId,lblId,bestId,worstId){
  const mn=Math.min(...ha.filter(v=>v>0)),mx=Math.max(...ha);
  const hm=document.getElementById(hmId),hl=document.getElementById(lblId);
  hm.innerHTML='';hl.innerHTML='';
  const bestH=ha.indexOf(mn),worstH=ha.indexOf(mx);
  ha.forEach((val,h)=>{
    const p=(val-mn)/(mx-mn+1e-10);
    // Blue (liquid) → Red (illiquid) with proper contrast
    const r=Math.round(26+p*(220-26));
    const g=Math.round(107*(1-p));
    const b=Math.round(219*(1-p)+38*p);
    const cell=document.createElement('div');
    cell.className='hmc';
    cell.style.background=`rgb(${r},${g},${b})`;
    cell.style.opacity=val===0?.3:1;
    cell.title=`${h}:00–${h+1}:00 IST\n${val>0?val.toFixed(3)+' bps':'No data'}`;
    cell.textContent=h;
    hm.appendChild(cell);
    const lbl=document.createElement('div');lbl.className='hmll';
    lbl.textContent=h%6===0?h+'h':'';hl.appendChild(lbl);
  });
  if(bestId)document.getElementById(bestId).textContent=`Best: ${bestH}:00 IST (${mn.toFixed(3)} bps)`;
  if(worstId)document.getElementById(worstId).textContent=`Worst: ${worstH}:00 IST (${mx.toFixed(3)} bps)`;
}

// ── TOP ILLIQUID TABLE ──
function mkTable(rows,hlBps,p90,rNames,regimes){
  const top=hlBps.map((v,i)=>({v,i})).sort((a,b)=>b.v-a.v).slice(0,15);
  document.getElementById('tbl-body').innerHTML=top.map((item,rank)=>{
    const r=rows[item.i];
    const dt=fmtIST(r.t,'datetime')+' IST';
    const reg=rNames[regimes[item.i]];
    const cls={Normal:'b-green',Stressed:'b-yellow',Illiquid:'b-orange',Crisis:'b-red'}[reg];
    return `<tr><td><b>${rank+1}</b></td><td>${dt}</td><td>${r.c.toFixed(5)}</td>
      <td><b>${item.v.toFixed(4)}</b></td><td><span class="badge ${cls}">${reg}</span></td></tr>`;
  }).join('');
}

// ── INSIGHTS PANEL ──
function mkInsights(rows,m,pair){
  const worstDay=m.dLabels[m.dVals.indexOf(Math.max(...m.dVals))];
  const bestDay =m.dLabels[m.dVals.indexOf(Math.min(...m.dVals))];
  const bestHour=m.hourlyAvg.indexOf(Math.min(...m.hourlyAvg.filter(v=>v>0)));
  const worstHour=m.hourlyAvg.indexOf(Math.max(...m.hourlyAvg));
  const crisisPct=((m.spikes/m.n)*100).toFixed(1);
  const spreadRatio=(m.peak/m.avg).toFixed(1);
  const dow=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const worstDow=dow[m.dowAvg.indexOf(Math.max(...m.dowAvg))];
  const bestDow =dow[m.dowAvg.indexOf(Math.min(...m.dowAvg.filter(v=>v>0)))];

  document.getElementById('insight-cards').innerHTML=`
  <div class="ins-card"><h4>📋 Summary</h4><p>
    <b>${pair}</b> shows avg bid-ask spread of <b>${m.avg.toFixed(3)} bps</b> with peak at
    <span class="red">${m.peak.toFixed(3)} bps</span> — a <b>${spreadRatio}×</b> ratio.
    Currently in <b style="color:${m.curReg==='Normal'?'var(--green)':m.curReg==='Crisis'?'var(--red)':'var(--orange)'}">${m.curReg}</b> regime.
    ${m.skew>0?'Right-skewed: occasional extreme illiquidity events dominate.':'Distribution is relatively symmetric.'}
  </p></div>

  <div class="ins-card"><h4>⏰ Best Trading Windows</h4><p>
    Most liquid hour: <span class="green">${bestHour}:00–${bestHour+1}:00 IST</span>
    (${m.hourlyAvg[bestHour].toFixed(3)} bps avg).<br>
    Most liquid day: <span class="green">${bestDow}</span>.<br>
    Best date: <span class="green">${bestDay}</span> (${Math.min(...m.dVals).toFixed(3)} bps).
  </p></div>

  <div class="ins-card"><h4>⚠️ Avoid These Periods</h4><p>
    Most illiquid hour: <span class="red">${worstHour}:00–${worstHour+1}:00 IST</span>
    (${m.hourlyAvg[worstHour].toFixed(3)} bps avg).<br>
    Most illiquid day: <span class="red">${worstDow}</span>.<br>
    Worst date: <span class="red">${worstDay}</span> (${Math.max(...m.dVals).toFixed(3)} bps).
  </p></div>

  <div class="ins-card"><h4>🚨 Crisis Analysis</h4><p>
    <span class="red">${m.spikes}</span> crisis candles (${crisisPct}% of total) — spread &gt; p90 threshold of
    <b>${m.p90.toFixed(3)} bps</b>. Roll spread confirms bid-ask bounce averaging
    <b>${m.avgRoll.toFixed(3)} bps</b>.
  </p></div>

  <div class="ins-card"><h4>📈 Paper Link</h4><p>
    Mancini et al. (2013) find FX liquidity varies <b>temporally</b> and shows
    <b>commonality</b> across pairs. Your data: ${m.n.toLocaleString()} candles,
    spread range ${m.p25.toFixed(3)}–${m.p75.toFixed(3)} bps (IQR). This matches
    the paper's finding of <span class="orange">significant temporal variation</span>.
  </p></div>

  <div class="ins-card"><h4>💡 Trading Tip</h4><p>
    For <b>${pair}</b>, enter trades between <span class="green">${bestHour}:00–${bestHour+2}:00 IST</span>.
    Avoid <span class="red">${worstHour}:00–${worstHour+1}:00 IST</span> — spread is
    <b>${(m.hourlyAvg[worstHour]/m.hourlyAvg[bestHour]).toFixed(1)}×</b> wider.
    Crisis regime occurs ${crisisPct}% of time.
  </p></div>`;

  // Histogram
  const bins=20,mn=0,mx=Math.min(m.p95*1.5,m.peak);
  const bw=(mx-mn)/bins;
  const counts=new Array(bins).fill(0);
  m.hlBps.forEach(v=>{const bi=Math.min(Math.floor((v-mn)/bw),bins-1);if(bi>=0)counts[bi]++;});
  const bLabels=Array.from({length:bins},(_,i)=>(mn+i*bw).toFixed(2));
  mkBar('c-hist',bLabels,counts,counts.map((_,i)=>{const p=i/bins;return p>.9?'#f8514966':p>.67?'#f0883e66':p>.33?'#e3b34166':'#58a6ff55';}));

  // LV Scatter
  const step=Math.max(1,Math.ceil(rows.length/400));
  const pts=[];
  for(let i=0;i<rows.length;i+=step) pts.push({x:+(m.volBps[i]||0).toFixed(4),y:+(m.hlBps[i]||0).toFixed(4)});
  const ctx=document.getElementById('c-lv-scatter');
  if(ctx){safeDestroy('c-lv-scatter');
    charts['c-lv-scatter']=new Chart(ctx,{type:'scatter',data:{datasets:[{data:pts,backgroundColor:'#a371f733',pointRadius:2,pointBorderWidth:0}]},
      options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},
        tooltip:{backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,callbacks:{label:c=>`HL:${c.parsed.y.toFixed(3)} Vol:${c.parsed.x.toFixed(3)}`}}},
        scales:{x:{title:{display:true,text:'Volatility (bps)',color:'#8b949e',font:{size:9}},ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}},
                y:{title:{display:true,text:'HL Spread (bps)',color:'#8b949e',font:{size:9}},ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});}

  // Cumulative
  const step2=Math.max(1,Math.ceil(m.labels.length/MAX_PTS));
  const cumIdx=[]; for(let i=0;i<m.cumIl.length;i+=step2) cumIdx.push(i);
  mkLine('c-cumil',[{data:cumIdx.map(i=>+m.cumIl[i].toFixed(4)),color:'#39d353',fill:true}],cumIdx.map(i=>m.labels[i]));
}

// ── REGIME CHART ──
function mkRegimes(rows,m){
  const step=Math.max(1,Math.ceil(m.labels.length/MAX_PTS));
  const rIdx=[]; for(let i=0;i<m.labels.length;i+=step) rIdx.push(i);
  const sl=rIdx.map(i=>m.labels[i]);
  const sh=rIdx.map(i=>+(m.hlBps[i]||0).toFixed(4));
  const sr=rIdx.map(i=>m.regimes[i]);
  const rColors=['#3fb95044','#e3b34144','#f0883e44','#f8514944'];
  const rBorder=['#3fb950','#e3b341','#f0883e','#f85149'];

  const ctx=document.getElementById('c-regime');if(!ctx)return;
  safeDestroy('c-regime');
  charts['c-regime']=new Chart(ctx,{type:'bar',
    data:{labels:sl,datasets:[
      {data:sh,backgroundColor:sr.map(r=>rColors[r]),borderColor:sr.map(r=>rBorder[r]),borderWidth:.5,label:'HL Spread'},
      {type:'line',data:sh.map(()=>m.p33),borderColor:'#3fb95066',borderWidth:1,borderDash:[3,3],pointRadius:0,label:'p33'},
      {type:'line',data:sh.map(()=>m.p67),borderColor:'#e3b34166',borderWidth:1,borderDash:[3,3],pointRadius:0,label:'p67'},
      {type:'line',data:sh.map(()=>m.p90),borderColor:'#f8514966',borderWidth:1,borderDash:[3,3],pointRadius:0,label:'p90'},
    ]},
    options:{responsive:true,maintainAspectRatio:false,animation:false,
      plugins:{legend:{display:false},
        zoom:{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}},
        tooltip:{backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,titleColor:'#8b949e',bodyColor:'#e6edf3'}},
      scales:{x:{ticks:{color:'#8b949e',maxTicksLimit:12,font:{size:9}},grid:{color:'#21262d'}},
              y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});

  const rc=[0,0,0,0];m.regimes.forEach(r=>rc[r]++);
  const tot=m.n;
  mkPie('c-rpie',m.rNames,rc.map(c=>+(c/tot*100).toFixed(1)),['#3fb950','#e3b341','#f0883e','#f85149']);
  const rAvgSum=[0,0,0,0],rAvgCnt=[0,0,0,0];
  m.hlBps.forEach((v,i)=>{rAvgSum[m.regimes[i]]+=v;rAvgCnt[m.regimes[i]]++;});
  mkBar('c-ravg',m.rNames,rAvgSum.map((s,i)=>rAvgCnt[i]>0?+(s/rAvgCnt[i]).toFixed(3):0),
    ['#3fb95088','#e3b34188','#f0883e88','#f8514988']);

  // Crisis per day
  const dayC={};
  m.times.forEach((d,i)=>{const day=fmtISTfromDate(d,'date');if(!dayC[day])dayC[day]=0;if(m.regimes[i]===3)dayC[day]++;});
  const dK=Object.keys(dayC).sort(),dV=dK.map(d=>dayC[d]);
  mkBar('c-rtrans',dK,dV,dV.map(v=>v>10?'#f8514988':v>5?'#f0883e88':'#58a6ff55'));
}

// ── INTRADAY ──
function mkIntraday(rows,m){
  const dow=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const hLabels=Array.from({length:24},(_,i)=>i+':00');
  mkBar('c-hourly',hLabels,m.hourlyAvg,m.hourlyAvg.map(v=>v>m.avg*1.3?'#f0883e88':v>m.avg?'#e3b34188':'#3fb95066'));
  mkBar('c-dow',dow,m.dowAvg,m.dowAvg.map(v=>v>m.avg*1.1?'#f0883e88':'#58a6ff55'));
  const step=Math.max(1,Math.ceil(rows.length/400));
  const pts=[];
  for(let i=0;i<rows.length;i+=step) pts.push({x:rows[i].c,y:+(m.hlBps[i]||0).toFixed(5)});
  const ctx=document.getElementById('c-scatter');
  if(ctx){safeDestroy('c-scatter');
    charts['c-scatter']=new Chart(ctx,{type:'scatter',data:{datasets:[{data:pts,backgroundColor:'#58a6ff33',pointRadius:2,pointBorderWidth:0}]},
      options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false},
        tooltip:{backgroundColor:'#161b22',borderColor:'#30363d',borderWidth:1,callbacks:{label:c=>`Price:${c.parsed.x.toFixed(5)} Spread:${c.parsed.y.toFixed(3)} bps`}}},
        scales:{x:{title:{display:true,text:'Price',color:'#8b949e',font:{size:9}},ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}},
                y:{title:{display:true,text:'HL Spread (bps)',color:'#8b949e',font:{size:9}},ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});}
}

// ── LOAD DATA ──
async function loadData(){
  const src=document.getElementById('sel-src').value;
  const pair=document.getElementById('sel-pairs').value;
  const from=document.getElementById('inp-from').value;
  const to=document.getElementById('inp-to').value;
  const stride=document.getElementById('sel-density').value;

  // ── COMPARE MODE ──
  if(src==='compare'){
    await loadCompare(pair,from,to,stride);
    return;
  }

  setS('loading',`Fetching ${pair} — ${src} (1-of-${stride} rows)...`);

  try{
    const url=`${API}?api=1&src=${src}&pair=${pair}&from=${from}&to=${to}&limit=0&stride=${stride}`;
    const res=await fetch(url);
    const json=await res.json();
    if(json.error){setS('error','⚠ '+json.error);return;}
    const rows=json.data?.[pair];
    if(!rows||!rows.length){setS('error','No data for '+pair);return;}

    // Free any previous data for this pair first
    if(loadedData[pair]) { loadedData[pair]=null; }
    loadedData[pair]={rows,metrics:compute(rows)};
    const {metrics:m}=loadedData[pair];
    const meta=json.meta?.[pair]||{};
    const step=Math.max(1,Math.ceil(rows.length/MAX_PTS));
    // Build subsampled arrays once — never pass full arrays to Chart.js
    const sIdx=[]; for(let i=0;i<rows.length;i+=step) sIdx.push(i);
    const sRows=sIdx.map(i=>rows[i]);
    const sL   =sIdx.map(i=>fmtIST(rows[i].t,'datetime'));
    const sHL  =sIdx.map(i=>+(m.hlBps[i]||0).toFixed(4));
    const sRoll=sIdx.map(i=>+(m.rollBps[i]||0).toFixed(4));
    const sAm  =sIdx.map(i=>+(m.amihud[i]||0).toFixed(4));
    const sVol =sIdx.map(i=>+(m.volBps[i]||0).toFixed(4));
    const sAvg =sIdx.map(()=>+m.avg.toFixed(4));

    updateStats(rows,m,pair,meta.first||'',meta.last||'');
    // Catch any render error per chart so one failure doesn't kill the rest
    const renders = [
      ()=>mkCandle('c-candle',rows,step),
      ()=>mkLine('c-hl',  [{data:sHL,color:'#f0883e',fill:true},{data:sAvg,color:'#f0883e66',fill:false,w:.8}],sL),
      ()=>mkLine('c-roll',  [{data:sRoll,color:'#a371f7',fill:true}],sL),
      ()=>mkLine('c-amihud',[{data:sAm,  color:'#f85149',fill:true}],sL),
      ()=>mkLine('c-vol',   [{data:sVol, color:'#e3b341',fill:true}],sL),
      ()=>mkBar('c-daily',m.dLabels,m.dVals,m.dVals.map(v=>v>m.avg*1.5?'#f8514966':v>m.avg?'#f0883e66':'#58a6ff55')),
      ()=>mkHeatmap(m.hourlyAvg,'hm-ov','hml-ov','hm-best','hm-worst'),
      ()=>mkTable(rows,m.hlBps,m.p90,m.rNames,m.regimes),
      ()=>mkInsights(rows,m,pair),
      ()=>mkRegimes(rows,m),
      ()=>mkIntraday(rows,m),
    ];
    // Render one at a time using setTimeout to avoid stack overflow
    let ri=0;
    function renderNext(){
      if(ri>=renders.length){ setS('ok',`✓ ${pair} — ${rows.length.toLocaleString()} candles · ${meta.first||''} → ${meta.last||''}`); return; }
      try{ renders[ri](); } catch(e){ console.warn('Chart render error:',renders[ri].toString().slice(0,60),e.message); }
      ri++; setTimeout(renderNext,0);
    }
    renderNext();

  }catch(e){setS('error','✕ '+e.message);}
}

// ── ALL PAIRS ──
async function loadAllPairs(){
  const src=document.getElementById('sel-src').value;
  const from=document.getElementById('inp-from').value;
  const to=document.getElementById('inp-to').value;
  setS('loading','Fetching all pairs (lightweight)...');
  try{
    const res=await fetch(`${API}?api=1&allpairs=1&src=${src}&from=${from}&to=${to}`);
    const json=await res.json();
    if(json.error){setS('error','⚠ '+json.error);return;}
    allData=json.data;
    renderRank();renderCorr();renderPCA();
    setS('ok',`✓ All pairs: ${Object.keys(allData).join(', ')}`);
  }catch(e){setS('error','✕ '+e.message);}
}

// ── RANK ──
function renderRank(){
  const pairs=Object.keys(allData);if(!pairs.length)return;
  const ranked=pairs.map(p=>({p,avg:allData[p].avg,n:allData[p].n})).sort((a,b)=>b.avg-a.avg);
  const mx=ranked[0].avg;
  document.getElementById('rank-container').innerHTML=
    `<table><thead><tr><th>#</th><th>Pair</th><th>Avg HL Spread (bps)</th><th>Est. Candles</th><th>Illiquidity Bar</th><th>Level</th></tr></thead><tbody>`+
    ranked.map((item,i)=>{
      const pct=(item.avg/mx*100).toFixed(1);
      const col=i/ranked.length<.25?'#f85149':i/ranked.length<.5?'#f0883e':i/ranked.length<.75?'#e3b341':'#3fb950';
      const badge=i===0?'<span class="badge b-red">Most Illiquid</span>':i===ranked.length-1?'<span class="badge b-green">Most Liquid</span>':'<span class="badge b-yellow">—</span>';
      return `<tr><td><b>${i+1}</b></td><td><b>${item.p}</b></td><td>${item.avg.toFixed(4)}</td>
        <td>${(item.n||0).toLocaleString()}</td>
        <td><div class="rb"><div class="rf" style="width:${pct}%;background:${col}"></div></div></td>
        <td>${badge}</td></tr>`;
    }).join('')+'</tbody></table>';

  const rL=ranked.map(r=>r.p),rV=ranked.map(r=>r.avg);
  mkBar('c-rank-bar',rL,rV,rV.map(v=>`rgba(${v>allData[rL[0]].avg*.8?220:88},${v>allData[rL[0]].avg*.8?50:166},${v>allData[rL[0]].avg*.8?50:255},0.5)`),true);
}

// ── CORRELATION ──
function renderCorr(){
  const pairs=Object.keys(allData);if(pairs.length<2)return;
  const n=pairs.length;
  const allTs=new Set();pairs.forEach(p=>allData[p].ts.forEach(t=>allTs.add(t)));
  const sortedTs=[...allTs].sort((a,b)=>a-b);
  const tsMap={};sortedTs.forEach((t,i)=>tsMap[t]=i);
  const aligned={};
  pairs.forEach(p=>{
    const m2={};allData[p].ts.forEach((t,i)=>m2[t]=allData[p].hl[i]);
    aligned[p]=sortedTs.map(t=>m2[t]??null);
  });
  function corr(a,b){
    const v=[];for(let i=0;i<a.length;i++)if(a[i]!==null&&b[i]!==null)v.push([a[i],b[i]]);
    if(v.length<2)return 0;
    const nn=v.length,ma=v.reduce((s,x)=>s+x[0],0)/nn,mb=v.reduce((s,x)=>s+x[1],0)/nn;
    let num=0,da=0,db=0;
    v.forEach(([x,y])=>{const dx=x-ma,dy=y-mb;num+=dx*dy;da+=dx*dx;db+=dy*dy;});
    return Math.sqrt(da*db)===0?0:num/Math.sqrt(da*db);
  }
  const matrix=pairs.map(pa=>pairs.map(pb=>+corr(aligned[pa],aligned[pb]).toFixed(2)));
  const cs=Math.min(40,Math.floor(320/n));
  const cont=document.getElementById('corr-container');
  const g=document.createElement('div');g.className='corr-grid';
  g.style.gridTemplateColumns=`${cs}px repeat(${n},${cs}px)`;
  const blank=document.createElement('div');blank.style.cssText=`width:${cs}px;height:${cs}px`;g.appendChild(blank);
  pairs.forEach(p=>{const h=document.createElement('div');h.style.cssText=`width:${cs}px;height:${cs}px;display:flex;align-items:center;justify-content:center;font-size:.52rem;color:var(--muted);font-weight:600`;h.textContent=p.slice(0,5);g.appendChild(h);});
  pairs.forEach((pa,i)=>{
    const rl=document.createElement('div');rl.style.cssText=`width:${cs}px;height:${cs}px;display:flex;align-items:center;justify-content:center;font-size:.52rem;color:var(--muted);font-weight:600`;rl.textContent=pa.slice(0,5);g.appendChild(rl);
    pairs.forEach((_,j)=>{
      const c=matrix[i][j];const abs=Math.abs(c);
      const cell=document.createElement('div');cell.className='cc';cell.style.width=cs+'px';cell.style.height=cs+'px';
      if(i===j){cell.style.background='#21262d';cell.style.color='#8b949e';}
      else{const r=c>0?Math.round(abs*180):20,gv=c>0?50:20,b=c<0?Math.round(abs*180):20;
        cell.style.background=`rgba(${r},${gv},${b},${.2+abs*.6})`;cell.style.color='#e6edf3';}
      cell.textContent=i===j?'1.0':c.toFixed(2);cell.title=`${pa}×${pairs[j]}: ${c}`;
      g.appendChild(cell);
    });
  });
  cont.innerHTML='';cont.appendChild(g);
  let sum=0,cnt=0;matrix.forEach((row,i)=>row.forEach((v,j)=>{if(i!==j){sum+=v;cnt++;}}));
  const avgC=(sum/cnt).toFixed(3);
  const info=document.createElement('div');info.className='pca-box';info.style.marginTop='8px';
  info.innerHTML=`<b>Avg pairwise correlation: ${avgC}</b> — ${parseFloat(avgC)>.5?'<b style="color:var(--green)">Strong commonality confirmed</b> (like paper)':'Moderate commonality'}`;
  cont.appendChild(info);
}

// ── PCA ──
function renderPCA(){
  const pairs=Object.keys(allData);if(pairs.length<2)return;
  const allTs=new Set();pairs.forEach(p=>allData[p].ts.forEach(t=>allTs.add(t)));
  const sortedTs=[...allTs].sort((a,b)=>a-b).slice(0,3000);
  const tsMap={};sortedTs.forEach((t,i)=>tsMap[t]=i);
  const T=sortedTs.length,P=pairs.length;
  const X=Array.from({length:T},()=>new Array(P).fill(0));
  pairs.forEach((pair,j)=>{
    const m2={};allData[pair].ts.forEach((t,i)=>m2[t]=allData[pair].hl[i]);
    sortedTs.forEach((t,i)=>{if(m2[t]!==undefined)X[i][j]=m2[t];});
    const col=X.map(r=>r[j]),mn=col.reduce((a,b)=>a+b,0)/T;
    const std=Math.sqrt(col.reduce((a,b)=>a+(b-mn)**2,0)/T)||1;
    X.forEach(r=>r[j]=(r[j]-mn)/std);
  });
  let v=new Array(P).fill(1/Math.sqrt(P));
  for(let it=0;it<30;it++){
    const Xv=X.map(row=>row.reduce((s,x,j)=>s+x*v[j],0));
    const XtXv=new Array(P).fill(0);
    Xv.forEach((val,i)=>X[i].forEach((x,j)=>XtXv[j]+=val*x));
    const norm=Math.sqrt(XtXv.reduce((s,x)=>s+x*x,0))||1;
    v=XtXv.map(x=>x/norm);
  }
  const pc1=X.map(row=>row.reduce((s,x,j)=>s+x*v[j],0));
  const varPc1=pc1.reduce((s,x)=>s+x*x,0)/T;
  const explVar=(varPc1/P*100).toFixed(1);
  const r2=pairs.map((_,j)=>{
    const col=X.map(r=>r[j]);
    const mn=col.reduce((a,b)=>a+b,0)/T,pc1mn=pc1.reduce((a,b)=>a+b,0)/T;
    let num=0,d1=0,d2=0;
    col.forEach((v2,i)=>{const dx=v2-mn,dy=pc1[i]-pc1mn;num+=dx*dy;d1+=dx*dx;d2+=dy*dy;});
    return +(Math.sqrt(d1*d2)===0?0:(num/Math.sqrt(d1*d2))**2).toFixed(3);
  });
  mkBar('c-pca-var',['PC1','PC2 (est.)','PC3+ (est.)'],
    [parseFloat(explVar),Math.max(0,parseFloat((100-parseFloat(explVar))*.55).toFixed(1)),Math.max(0,(100-parseFloat(explVar))*.45).toFixed(1)],
    ['#58a6ff88','#a371f788','#3fb95088']);
  const step2=Math.max(1,Math.floor(pc1.length/400));
  const pc1Labels=sortedTs.filter((_,i)=>i%step2===0).map(t=>fmtIST(t,'date'));
  mkLine('c-pc1',[{data:pc1.filter((_,i)=>i%step2===0),color:'#58a6ff',fill:true}],pc1Labels);
  mkBar('c-r2',pairs,r2,r2.map(v=>v>.7?'#3fb95088':v>.4?'#58a6ff66':'#f0883e55'));
  document.getElementById('pca-info').innerHTML=
    `<b>PC1 explains ${explVar}% of total liquidity variation</b> across ${P} pairs.<br>`+
    `Paper benchmark: 60–90%. Your result: <b style="color:${parseFloat(explVar)>60?'var(--green)':'var(--orange)'}">` +
    `${parseFloat(explVar)>60?'✓ Consistent':'Below benchmark — try All Pairs with more data'}</b><br>`+
    `Loadings: ${pairs.map((p,i)=>`<b>${p}</b>:${v[i].toFixed(2)}`).join(' · ')}`;
}

// ── SOURCE CHANGE ──
async function onSrcChange(){
  const src=document.getElementById('sel-src').value;
  const dates={
    'all'    :['2025-06-23','2026-09-05'],
    'hist'   :['2025-06-23','2026-02-27'],
    '1min'   :['2025-06-23','2026-09-05'],
    '5min'   :['2026-04-22','2026-09-05'],
    'compare':['2026-04-22','2026-09-05'],
  };
  document.getElementById('inp-from').value=dates[src][0];
  document.getElementById('inp-to').value=dates[src][1];
  try{
    const res=await fetch(`${API}?api=1&list=1&src=${src}`);
    const json=await res.json();
    const sel=document.getElementById('sel-pairs');
    const prev=sel.value;
    sel.innerHTML='';
    (json.pairs||[]).forEach(p=>{
      const o=document.createElement('option');
      o.value=p;o.textContent=p.slice(0,3)+'/'+p.slice(3);
      if(p===prev)o.selected=true;
      sel.appendChild(o);
    });
  }catch(e){}
}

// ── OPEN COMPARE TAB ──
// The compare panel must use the Compare source. If the user clicks the tab
// while another source (e.g. All Data Combined) is selected, switch it
// automatically and load the comparison.
async function openCompareTab(el){
  const srcEl=document.getElementById('sel-src');
  if(srcEl) srcEl.value='compare';

  const dates={
    'compare':['2026-04-22','2026-09-05']
  };
  const fromEl=document.getElementById('inp-from');
  const toEl=document.getElementById('inp-to');
  if(fromEl) fromEl.value=dates.compare[0];
  if(toEl) toEl.value=dates.compare[1];

  // Refresh the pair list for the compare source.
  try{
    const res=await fetch(`${API}?api=1&list=1&src=compare`);
    const json=await res.json();
    const sel=document.getElementById('sel-pairs');
    const prev=sel ? sel.value : '';
    if(sel){
      sel.innerHTML='';
      (json.pairs||[]).forEach(p=>{
        const o=document.createElement('option');
        o.value=p;
        o.textContent=p.slice(0,3)+'/'+p.slice(3);
        if(p===prev)o.selected=true;
        sel.appendChild(o);
      });
    }
  }catch(e){}

  const pair=document.getElementById('sel-pairs').value;
  const stride=document.getElementById('sel-density').value;
  await loadCompare(pair,dates.compare[0],dates.compare[1],stride);
}

// ── LOAD COMPARE (1min vs 5min) ──
async function loadCompare(pair,from,to,stride){
  setS('loading',`Comparing 1-min vs 5-min for ${pair}...`);
  // Show compare tab
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('panel-compare').classList.add('active');
  // Find and activate compare tab
  document.querySelectorAll('.tab').forEach(t=>{if(t.textContent.includes('1min vs'))t.classList.add('active');});

  try{
    // Fetch 1min data
    const url1=`${API}?api=1&src=1min&pair=${pair}&from=${from}&to=${to}&limit=0&stride=${stride}`;
    // Fetch 5min data
    const url5=`${API}?api=1&src=5min&pair=${pair}&from=${from}&to=${to}&limit=0&stride=1`;

    const [res1,res5]=await Promise.all([fetch(url1),fetch(url5)]);
    const [j1,j5]=await Promise.all([res1.json(),res5.json()]);

    const rows1=j1.data?.[pair]||[];
    const rows5=j5.data?.[pair]||[];

    if(!rows1.length&&!rows5.length){setS('error','No data for either timeframe');return;}

    renderCompare(pair,rows1,rows5);
    setS('ok',`✓ Compare ${pair} — 1min:${rows1.length.toLocaleString()} candles · 5min:${rows5.length.toLocaleString()} candles`);
  }catch(e){setS('error','✕ '+e.message);}
}

function renderCompare(pair,rows1,rows5){
  const m1=rows1.length?compute(rows1):null;
  const m5=rows5.length?compute(rows5):null;

  // Update stat cards
  document.getElementById('cmp-rows1').textContent=rows1.length.toLocaleString();
  document.getElementById('cmp-rows5').textContent=rows5.length.toLocaleString();
  document.getElementById('cmp-avg1').textContent=m1?m1.avg.toFixed(3):'—';
  document.getElementById('cmp-avg5').textContent=m5?m5.avg.toFixed(3):'—';

  // Build subsampled series
  const cap=MAX_PTS;
  function sub(rows,m,key){
    const step=Math.max(1,Math.ceil(rows.length/cap));
    const idx=[]; for(let i=0;i<rows.length;i+=step) idx.push(i);
    return {
      labels: idx.map(i=>fmtIST(rows[i].t,'datetime')),
      data:   idx.map(i=>+(m[key][i]||0).toFixed(4))
    };
  }

  const s1hl  = m1?sub(rows1,m1,'hlBps'):null;
  const s5hl  = m5?sub(rows5,m5,'hlBps'):null;
  const s1r   = m1?sub(rows1,m1,'rollBps'):null;
  const s5r   = m5?sub(rows5,m5,'rollBps'):null;
  const s1v   = m1?sub(rows1,m1,'volBps'):null;
  const s5v   = m5?sub(rows5,m5,'volBps'):null;

  // HL Spread comparison chart — dual series on same chart
  const ds_hl=[];
  const labels_hl = s1hl?s1hl.labels:(s5hl?s5hl.labels:[]);
  if(s1hl) ds_hl.push({data:s1hl.data,color:'#58a6ff',label:'1-min HL Spread',fill:false,w:1.5});
  if(s5hl) {
    // Resample 5min labels to align with 1min x-axis for overlay
    ds_hl.push({data:s5hl.data,color:'#f0883e',label:'5-min HL Spread',fill:false,w:1.5});
  }

  // Use separate axes if both exist
  const chlCtx=document.getElementById('c-cmp-hl');
  safeDestroy('c-cmp-hl');
  if(chlCtx&&(s1hl||s5hl)){
    const datasets=[];
    if(s1hl) datasets.push({data:s1hl.data,label:'1-min',borderColor:'#58a6ff',backgroundColor:'#58a6ff18',borderWidth:1.5,pointRadius:0,fill:true,yAxisID:'y'});
    if(s5hl) datasets.push({data:s5hl.data,label:'5-min',borderColor:'#f0883e',backgroundColor:'#f0883e18',borderWidth:1.5,pointRadius:0,fill:true,yAxisID:'y2'});
    charts['c-cmp-hl']=new Chart(chlCtx,{
      type:'line',
      data:{labels:s1hl?s1hl.labels:s5hl.labels,datasets},
      options:{responsive:true,maintainAspectRatio:false,animation:false,
        plugins:{legend:{display:true,labels:{color:'#8b949e',font:{size:9},boxWidth:12}},
          zoom:{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}}},
        scales:{
          x:{ticks:{color:'#8b949e',maxTicksLimit:10,font:{size:9}},grid:{color:'#21262d'}},
          y:{position:'left', ticks:{color:'#58a6ff',font:{size:9}},grid:{color:'#21262d'},title:{display:true,text:'1-min (bps)',color:'#58a6ff',font:{size:9}}},
          y2:{position:'right',ticks:{color:'#f0883e',font:{size:9}},grid:{drawOnChartArea:false},title:{display:true,text:'5-min (bps)',color:'#f0883e',font:{size:9}}}
        }
      }
    });
  }

  // Roll spread comparison
  safeDestroy('c-cmp-roll');
  const rollCtx=document.getElementById('c-cmp-roll');
  if(rollCtx){
    const ds=[];
    if(s1r) ds.push({data:s1r.data,label:'1-min Roll',borderColor:'#a371f7',backgroundColor:'transparent',borderWidth:1.5,pointRadius:0});
    if(s5r) ds.push({data:s5r.data,label:'5-min Roll',borderColor:'#e3b341',backgroundColor:'transparent',borderWidth:1.5,pointRadius:0});
    charts['c-cmp-roll']=new Chart(rollCtx,{type:'line',data:{labels:s1r?s1r.labels:s5r.labels,datasets:ds},
      options:{responsive:true,maintainAspectRatio:false,animation:false,
        plugins:{legend:{display:true,labels:{color:'#8b949e',font:{size:9},boxWidth:12}},
          zoom:{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}}},
        scales:{x:{ticks:{color:'#8b949e',maxTicksLimit:10,font:{size:9}},grid:{color:'#21262d'}},
                y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});
  }

  // Volatility comparison
  safeDestroy('c-cmp-vol');
  const volCtx=document.getElementById('c-cmp-vol');
  if(volCtx){
    const ds=[];
    if(s1v) ds.push({data:s1v.data,label:'1-min Vol',borderColor:'#3fb950',backgroundColor:'transparent',borderWidth:1.5,pointRadius:0});
    if(s5v) ds.push({data:s5v.data,label:'5-min Vol',borderColor:'#f85149',backgroundColor:'transparent',borderWidth:1.5,pointRadius:0});
    charts['c-cmp-vol']=new Chart(volCtx,{type:'line',data:{labels:s1v?s1v.labels:s5v.labels,datasets:ds},
      options:{responsive:true,maintainAspectRatio:false,animation:false,
        plugins:{legend:{display:true,labels:{color:'#8b949e',font:{size:9},boxWidth:12}},
          zoom:{zoom:{wheel:{enabled:true},pinch:{enabled:true},mode:'x'},pan:{enabled:true,mode:'x'}}},
        scales:{x:{ticks:{color:'#8b949e',maxTicksLimit:10,font:{size:9}},grid:{color:'#21262d'}},
                y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});
  }

  // Hourly intraday comparison bar
  safeDestroy('c-cmp-hourly');
  const hCtx=document.getElementById('c-cmp-hourly');
  if(hCtx){
    const hLabels=Array.from({length:24},(_,i)=>i+':00 IST');
    const ds=[];
    if(m1) ds.push({label:'1-min',data:m1.hourlyAvg.map(v=>+v.toFixed(4)),backgroundColor:'#58a6ff66',borderColor:'#58a6ff',borderWidth:1});
    if(m5) ds.push({label:'5-min',data:m5.hourlyAvg.map(v=>+v.toFixed(4)),backgroundColor:'#f0883e66',borderColor:'#f0883e',borderWidth:1});
    charts['c-cmp-hourly']=new Chart(hCtx,{type:'bar',data:{labels:hLabels,datasets:ds},
      options:{responsive:true,maintainAspectRatio:false,animation:false,
        plugins:{legend:{display:true,labels:{color:'#8b949e',font:{size:9},boxWidth:12}}},
        scales:{x:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}},
                y:{ticks:{color:'#8b949e',font:{size:9}},grid:{color:'#21262d'}}}}});
  }

  // Insights
  const insEl=document.getElementById('cmp-insights');
  if(insEl){
    const avg1=m1?m1.avg:0, avg5=m5?m5.avg:0;
    const ratio=avg1&&avg5?(avg1/avg5).toFixed(2):null;
    const bh1=m1?m1.hourlyAvg.indexOf(Math.min(...m1.hourlyAvg.filter(v=>v>0))):null;
    const wh1=m1?m1.hourlyAvg.indexOf(Math.max(...m1.hourlyAvg)):null;
    const bh5=m5?m5.hourlyAvg.indexOf(Math.min(...m5.hourlyAvg.filter(v=>v>0))):null;
    const wh5=m5?m5.hourlyAvg.indexOf(Math.max(...m5.hourlyAvg)):null;
    insEl.innerHTML=`
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
        <div class="ins-card"><h4>📊 Spread Comparison</h4><p>
          1-min avg: <b style="color:var(--blue)">${avg1.toFixed(4)} bps</b><br>
          5-min avg: <b style="color:var(--orange)">${avg5.toFixed(4)} bps</b><br>
          ${ratio?`Ratio: <b>${ratio}×</b> — ${parseFloat(ratio)>1?'<span class="red">1-min shows more noise</span>':'<span class="green">5-min shows more spread</span>'}`:''}<br>
          <span style="font-size:.72rem;color:var(--muted)">Higher bps on 5-min = aggregated spread. Lower on 1-min = granular noise.</span>
        </p></div>
        <div class="ins-card"><h4>⏰ Best Hours (IST)</h4><p>
          <b style="color:var(--blue)">1-min best:</b> ${bh1!==null?bh1+':00 IST ('+m1.hourlyAvg[bh1].toFixed(3)+' bps)':'—'}<br>
          <b style="color:var(--orange)">5-min best:</b> ${bh5!==null?bh5+':00 IST ('+m5.hourlyAvg[bh5].toFixed(3)+' bps)':'—'}
        </p></div>
        <div class="ins-card"><h4>⚠️ Worst Hours (IST)</h4><p>
          <b style="color:var(--blue)">1-min worst:</b> ${wh1!==null?wh1+':00 IST ('+m1.hourlyAvg[wh1].toFixed(3)+' bps)':'—'}<br>
          <b style="color:var(--orange)">5-min worst:</b> ${wh5!==null?wh5+':00 IST ('+m5.hourlyAvg[wh5].toFixed(3)+' bps)':'—'}
        </p></div>
      </div>`;
  }
}

// ── CLEAR ──
function clearAll(){
  Object.values(charts).forEach(c=>c.destroy());charts={};loadedData={};allData={};
  ['sv-price','sv-rows','sv-spread','sv-peak','sv-roll','sv-spikes','sv-regime','sv-range','hl-avg-line','daily-note']
    .forEach(id=>{const el=document.getElementById(id);if(el)el.textContent='—';});
  document.getElementById('tbl-body').innerHTML='<tr><td colspan="5"><div class="empty">Load data to see top illiquid candles</div></td></tr>';
  document.getElementById('corr-container').innerHTML='<div class="empty">Click ⚡ All Pairs first</div>';
  document.getElementById('rank-container').innerHTML='<div class="empty">Click ⚡ All Pairs to load ranking</div>';
  document.getElementById('pca-info').textContent='Click ⚡ All Pairs to run PCA.';
  document.getElementById('hm-ov').innerHTML='';document.getElementById('hml-ov').innerHTML='';
  document.getElementById('insight-cards').innerHTML='<div class="ins-card"><h4>📋 Summary</h4><p>Load a pair to see insights.</p></div>';
  const cmpEl=document.getElementById('cmp-insights');
  if(cmpEl) cmpEl.innerHTML='Select <b>🔀 1min vs 5min Compare</b> source, pick a pair, click Load.';
  ['cmp-rows1','cmp-rows5','cmp-avg1','cmp-avg5'].forEach(id=>{const el=document.getElementById(id);if(el)el.textContent='—';});
  ['c-cmp-hl','c-cmp-roll','c-cmp-vol','c-cmp-hourly'].forEach(id=>safeDestroy(id));
  setS('idle','Cleared.');
}

// ── PDF EXPORT ──
function exportPDF(){
  // Make all panels visible for print
  document.querySelectorAll('.panel').forEach(p=>p.style.display='block');
  window.print();
  // Restore
  setTimeout(()=>{
    document.querySelectorAll('.panel').forEach(p=>p.style.display='');
    document.querySelectorAll('.panel.active').forEach(p=>p.style.display='block');
  },1000);
}
</script>
</body>
</html>