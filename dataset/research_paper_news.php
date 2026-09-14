<?php
// ══════════════════════════════════════════════════════════════
//  research_report.php
//  Auto-fetches all pairs, matches high-impact news, computes
//  news-candle % of 5min range, outputs full HTML report
// ══════════════════════════════════════════════════════════════

ini_set('display_errors', 1);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');
error_reporting(E_ALL);

// ══════════════════════════════════════════════════════════════
//  ?proof=1  →  generate & stream PDF proof (no HTML rendered)
//  ?proof_data=1  →  return JSON of randomly selected events
// ══════════════════════════════════════════════════════════════
if (isset($_GET['proof']) || isset($_GET['proof_data'])) {
    // Re-run the full data pipeline, then pick random events
    define('PROOF_MODE', true);
}

// ── DB ───────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/db.php';
if (!$conn || $conn->connect_error) die(json_encode(['error'=>'DB failed']));

// ── Config ───────────────────────────────────────────────────
$URL_5M   = 'https://tradeedify.in/dataset/dataset/';
$URL_1M   = 'https://tradeedify.in/dataset/1min/';
$IST      = 19800;
$PRE_1M   = 10;   // candles before news in 1min
$PRE_5M   = 2;    // candles before news in 5min
$POST_1M  = 15;   // candles after  news in 1min
$POST_5M  = 3;    // candles after  news in 5min

$PAIRS = [
    'AUDCAD','AUDCHF','AUDJPY','AUDUSD',
    'CADJPY','CHFJPY',
    'EURAUD','EURCAD','EURCHF','EURGBP','EURJPY','EURUSD',
    'GBPAUD','GBPCAD','GBPCHF','GBPJPY','GBPUSD',
    'USDCAD','USDCHF','USDJPY'
];
$JPY_PAIRS = ['AUDJPY','CADJPY','CHFJPY','EURJPY','GBPJPY','USDJPY'];

// ── Helpers ──────────────────────────────────────────────────
function isJpy($pair) { return strpos($pair,'JPY') !== false; }
function calcPips($diff, $pair) {
    return isJpy($pair) ? abs($diff)*100 : abs($diff)*10000;
}
function fmtPrice($p, $pair) {
    return isJpy($pair) ? number_format($p,3) : number_format($p,5);
}

// ── Fetch & parse CSV ────────────────────────────────────────
function fetchCSV($url) {
    $ctx = stream_context_create(['http'=>[
        'timeout' => 30,
        'user_agent' => 'Mozilla/5.0'
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $lines = array_filter(explode("\n", trim($raw)), 'strlen');
    if (count($lines) < 2) return null;

    $headers = array_map('trim', str_getcsv(array_shift($lines)));
    $headers = array_map('strtolower', $headers);

    $find = function($names) use ($headers) {
        foreach ($names as $n) {
            $i = array_search($n, $headers);
            if ($i !== false) return $i;
        }
        foreach ($names as $n) {
            foreach ($headers as $i=>$h) {
                if (strpos($h,$n)!==false) return $i;
            }
        }
        return false;
    };

    $iT = $find(['timestamp','unix','time','datetime','date']);
    $iO = $find(['open','o']);
    $iH = $find(['high','h']);
    $iL = $find(['low','l']);
    $iC = $find(['close','c']);

    if ($iT===false||$iO===false||$iH===false||$iL===false||$iC===false) return null;

    $candles = [];
    foreach ($lines as $line) {
        $c = array_map('trim', str_getcsv($line));
        if (count($c) < 5) continue;
        $raw = $c[$iT];
        $ts  = is_numeric($raw) && $raw > 1e9 ? (int)$raw : strtotime($raw);
        if (!$ts) continue;
        $o=floatval($c[$iO]); $h=floatval($c[$iH]);
        $l=floatval($c[$iL]); $cl=floatval($c[$iC]);
        if (!$o||!$h||!$l||!$cl) continue;
        $candles[] = ['time'=>$ts,'open'=>$o,'high'=>$h,'low'=>$l,'close'=>$cl];
    }
    if (!$candles) return null;
    usort($candles, function($a,$b){ return $a['time']-$b['time']; });
    return $candles;
}

// ── Fetch high-impact news in UTC range ──────────────────────
function fetchNews($conn, $fromTs, $toTs) {
    global $IST;
    $fromIST = (new DateTime('@'.($fromTs+$IST)))->format('Y-m-d H:i:s');
    $toIST   = (new DateTime('@'.($toTs  +$IST)))->format('Y-m-d H:i:s');
    $fromIST = $conn->real_escape_string($fromIST);
    $toIST   = $conn->real_escape_string($toIST);
    $res = $conn->query(
        "SELECT id, event_name, impact, event_time
         FROM economic_events
         WHERE impact = 3
           AND event_time >= '$fromIST'
           AND event_time <= '$toIST'
         ORDER BY event_time ASC"
    );
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $dt    = new DateTime($r['event_time'], new DateTimeZone('Asia/Kolkata'));
        $utcTs = $dt->getTimestamp();
        $out[] = ['id'=>(int)$r['id'],'name'=>$r['event_name'],
                  'time'=>$utcTs,'timeStr'=>$r['event_time']];
    }
    return $out;
}

// ── Find candle window around news ──────────────────────────
function getWindow($candles, $newsTs, $pre, $post) {
    $newsIdx = -1;
    foreach ($candles as $i=>$c) {
        if ($c['time'] >= $newsTs) { $newsIdx = $i; break; }
    }
    if ($newsIdx === -1) $newsIdx = count($candles)-1;
    $from = max(0, $newsIdx - $pre);
    $to   = min(count($candles), $newsIdx + 1 + $post);
    return [
        'candles'   => array_slice($candles, $from, $to-$from),
        'newsIdx'   => $newsIdx - $from,
        'newsCandle'=> $candles[$newsIdx],
    ];
}

// ── MAIN: loop all pairs ─────────────────────────────────────
$results      = [];
$pairStats    = [];
$allPcts      = [];
$proofWindows = [];
$log = [];

foreach ($PAIRS as $pair) {
    $fname = "FX_{$pair}.csv";
    $url1m = $URL_1M . $fname;
    $url5m = $URL_5M . $fname;

    $c1m = fetchCSV($url1m);
    $c5m = fetchCSV($url5m);

    if (!$c1m || !$c5m) {
        $log[] = "⚠ $pair: CSV fetch failed";
        continue;
    }

    $comStart = max($c1m[0]['time'], $c5m[0]['time']);
    $comEnd   = min($c1m[count($c1m)-1]['time'], $c5m[count($c5m)-1]['time']);
    if ($comStart >= $comEnd) { $log[] = "⚠ $pair: no overlap"; continue; }

    $c1m = array_values(array_filter($c1m, function($c) use ($comStart,$comEnd){ return $c['time']>=$comStart && $c['time']<=$comEnd; }));
    $c5m = array_values(array_filter($c5m, function($c) use ($comStart,$comEnd){ return $c['time']>=$comStart && $c['time']<=$comEnd; }));

    $news = fetchNews($conn, $comStart, $comEnd);
    if (!$news) { $log[] = "ℹ $pair: 0 high-impact events"; continue; }

    $pairPcts = [];

    foreach ($news as $ev) {
        $w1m = getWindow($c1m, $ev['time'], $PRE_1M, $POST_1M);
        $w5m = getWindow($c5m, $ev['time'], $PRE_5M, $POST_5M);

        $win1m = $w1m['candles'];
        $win5m = $w5m['candles'];
        $nc    = $w1m['newsCandle'];

        if (!$win1m || !$win5m) continue;

        $hi5 = max(array_column($win5m,'high'));
        $lo5 = min(array_column($win5m,'low'));
        $rng5 = calcPips($hi5-$lo5, $pair);

        $ncRng = calcPips($nc['high']-$nc['low'], $pair);
        $pct = $rng5 > 0 ? round(($ncRng/$rng5)*100, 1) : null;
        $dir = $nc['close'] >= $nc['open'] ? 'Bull' : 'Bear';

        $row = [
            'pair'     => $pair,
            'event'    => $ev['name'],
            'timeIST'  => $ev['timeStr'],
            'timeUTC'  => date('Y-m-d H:i', $ev['time']),
            'ncOpen'   => fmtPrice($nc['open'],  $pair),
            'ncHigh'   => fmtPrice($nc['high'],  $pair),
            'ncLow'    => fmtPrice($nc['low'],   $pair),
            'ncClose'  => fmtPrice($nc['close'], $pair),
            'ncPips'   => round($ncRng, 1),
            'rng5Pips' => round($rng5,  1),
            'pct'      => $pct,
            'dir'      => $dir,
        ];
        $results[] = $row;
        if ($pct !== null) { $pairPcts[] = $pct; $allPcts[] = $pct; }

        if ($pct !== null && defined('PROOF_MODE')) {
            $proofWindows[] = array_merge($row, [
                'candles1m' => array_slice($win1m, 0, 30),
                'newsIdx'   => $w1m['newsIdx'],
                'candles5m' => array_slice($win5m, 0, 8),
            ]);
        }
    }

    if ($pairPcts) {
        $pairStats[$pair] = [
            'count'  => count($pairPcts),
            'avg'    => round(array_sum($pairPcts)/count($pairPcts),1),
            'median' => calcMedian($pairPcts),
            'min'    => min($pairPcts),
            'max'    => max($pairPcts),
        ];
    }
    $log[] = "✓ $pair: ".count($pairPcts)." events processed";
}

function calcMedian($arr) {
    sort($arr); $n=count($arr);
    return $n%2===0 ? round(($arr[$n/2-1]+$arr[$n/2])/2,1) : round($arr[floor($n/2)],1);
}
function _pctGte50($p){ return $p >= 50; }
function _pctGte80($p){ return $p >= 80; }
function _pctIsHigh($r){ return isset($r['pct']) && $r['pct'] >= 50; }
function _pctIsLow($r){ return isset($r['pct']) && $r['pct'] < 50; }
function _sortByPctDesc($a,$b){
    $pa = isset($a['pct']) ? $a['pct'] : -1;
    $pb = isset($b['pct']) ? $b['pct'] : -1;
    return ($pb > $pa) ? 1 : (($pb < $pa) ? -1 : 0);
}

$globalAvg    = $allPcts ? round(array_sum($allPcts)/count($allPcts),1) : 0;
$globalMedian = $allPcts ? calcMedian($allPcts) : 0;
$above50      = $allPcts ? count(array_filter($allPcts,'_pctGte50')) : 0;
$above80      = $allPcts ? count(array_filter($allPcts,'_pctGte80')) : 0;
$pctAbove50   = count($allPcts) ? round($above50/count($allPcts)*100,1) : 0;
$pctAbove80   = count($allPcts) ? round($above80/count($allPcts)*100,1) : 0;

usort($results, '_sortByPctDesc');
$genTime = date('Y-m-d H:i:s');

// ── PROOF PDF ENDPOINT ─────────────────────────────────────────
if (defined('PROOF_MODE')) {

    $N = min(12, count($proofWindows));
    $highImpact = array_values(array_filter($proofWindows, '_pctIsHigh'));
    $lowImpact  = array_values(array_filter($proofWindows, '_pctIsLow'));
    shuffle($highImpact); shuffle($lowImpact);
    $pick = array_slice($highImpact, 0, min(8, $N));
    $need = $N - count($pick);
    if ($need > 0) $pick = array_merge($pick, array_slice($lowImpact, 0, $need));
    shuffle($pick);

    if (isset($_GET['proof_data'])) {
        header('Content-Type: application/json');
        echo json_encode($pick, JSON_PRETTY_PRINT);
        exit;
    }

    define('FPDF_FONTPATH', '');

    function makeChartPng($candles, $newsIdx, $pair, $pct) {
        $W = 760; $H = 300;
        $img = imagecreatetruecolor($W, $H);

        // Solid colors (NO ALPHA - protects against JPEG conversion artifacts)
        $cBg    = imagecolorallocate($img,  13, 17, 23);
        $cGrid  = imagecolorallocate($img,  48, 54, 61);
        $cGreen = imagecolorallocate($img,  63,185, 80);
        $cRed   = imagecolorallocate($img, 248, 81, 73);
        $cGold  = imagecolorallocate($img, 240,192,  0);
        $cText  = imagecolorallocate($img, 170,175,182); // Brighter grey text
        $cHiTxt = imagecolorallocate($img, 230,237,243);
        $cGoldBg= imagecolorallocate($img,  47, 43, 20); // Solid dark gold instead of alpha

        imagefilledrectangle($img, 0, 0, $W-1, $H-1, $cBg);

        $ml=70; $mr=12; $mt=26; $mb=26;
        $cw=$W-$ml-$mr; $ch=$H-$mt-$mb;

        $highs = array_column($candles,'high');
        $lows  = array_column($candles,'low');
        $pMax  = max($highs); $pMin = min($lows);
        $pRng  = $pMax-$pMin; if($pRng<1e-9) $pRng=1e-5;
        $pad   = $pRng*0.12;
        $pTop  = $pMax+$pad; $pBot=$pMin-$pad; $pSpan=$pTop-$pBot;

        $isJpy = strpos($pair,'JPY')!==false;
        for($gi=0;$gi<=5;$gi++){
            $gp=$pBot+($pSpan*$gi/5);
            $gy=(int)($mt+($pTop-$gp)/$pSpan*$ch);
            imageline($img,$ml,$gy,$W-$mr,$gy,$cGrid);
            $lbl=$isJpy?number_format($gp,3):number_format($gp,5);
            imagestring($img,1,2,$gy-5,$lbl,$cText);
        }

        $n=count($candles); $slotW=$cw/($n+1);
        $bodyW=max((int)($slotW*0.6),2);

        foreach($candles as $i=>$cd){
            $cx=(int)($ml+($i+1)*$slotW);
            $isNews=($i===$newsIdx);
            $bull=$cd['close']>=$cd['open'];
            $col=$isNews?$cGold:($bull?$cGreen:$cRed);
            $yH=(int)($mt+($pTop-$cd['high'])/$pSpan*$ch);
            $yL=(int)($mt+($pTop-$cd['low'])/$pSpan*$ch);
            $yO=(int)($mt+($pTop-$cd['open'])/$pSpan*$ch);
            $yC=(int)($mt+($pTop-$cd['close'])/$pSpan*$ch);
            $yB1=min($yO,$yC); $yB2=max($yO,$yC);
            if($yB2===$yB1) $yB2=$yB1+1;

            if($isNews){
                imagefilledrectangle($img,$cx-$bodyW-3,$mt,$cx+$bodyW+3,$mt+$ch,$cGoldBg);
            }
            imageline($img,$cx,$yH,$cx,$yL,$col);
            imagefilledrectangle($img,$cx-(int)($bodyW/2),$yB1,$cx+(int)($bodyW/2),$yB2,$col);
            imagerectangle($img,$cx-(int)($bodyW/2),$yB1,$cx+(int)($bodyW/2),$yB2,$col);

            if($i%4===0||$isNews){
                $lbl=gmdate('H:i',$cd['time']+19800);
                $lx=$cx-(int)(strlen($lbl)*3);
                imagestring($img,1,$lx,$H-$mb+4,$lbl,$isNews?$cGold:$cText);
            }
            if($isNews){
                imagestring($img,2,$cx-(int)(strlen('NEWS')*3),$yH-22,'NEWS',$cGold);
                imagestring($img,2,$cx-(int)(strlen($pct.'%')*3),$yH-11,$pct.'%',$cGold);
            }
        }
        imagerectangle($img,$ml,$mt,$W-$mr,$mt+$ch,$cGrid);
        imagestring($img,2,$ml+2,4,'1-min candles — gold = news candle',$cHiTxt);

        ob_start(); imagepng($img); $png=ob_get_clean(); imagedestroy($img);
        return $png;
    }

    function pdfEscape($s){
        return str_replace(array('\\','(',')'), array('\\\\','\\(','\\)'), $s);
    }

    function buildProofPdf($pick, $globalAvg, $globalMedian, $above50, $allPcts, $pctAbove50, $PAIRS) {
        $objects = array(); 
        $nextId  = 1;
        $pageIds = array();

        $newObj = function($content) use (&$objects, &$nextId) {
            $id = $nextId++;
            $objects[$id] = $content;
            return $id;
        };

        $fontId = $newObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $fontBId= $newObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");

        $makePage = function($stream, $imgObjId) use ($newObj, $fontId, $fontBId, &$pageIds) {
            $len = strlen($stream);
            if($imgObjId){
                $streamId = $newObj("<< /Length $len >>\nstream\n".$stream."\nendstream");
                $pageId   = $newObj("<< /Type /Page /MediaBox [0 0 595 842]\n".
                    "/Resources << /Font << /F1 $fontId 0 R /F2 $fontBId 0 R >>\n".
                    "              /XObject << /Img1 $imgObjId 0 R >> >>\n".
                    "/Contents $streamId 0 R /Parent 9999 0 R >>");
            } else {
                $streamId = $newObj("<< /Length $len >>\nstream\n".$stream."\nendstream");
                $pageId   = $newObj("<< /Type /Page /MediaBox [0 0 595 842]\n".
                    "/Resources << /Font << /F1 $fontId 0 R /F2 $fontBId 0 R >> >>\n".
                    "/Contents $streamId 0 R /Parent 9999 0 R >>");
            }
            $pageIds[] = $pageId;
        };

        // ── COVER PAGE (Dark Mode Styled) ────────────────────────────────────────
        $s  = "q 0.051 0.067 0.090 rg 0 0 595 842 re f Q\n"; // Fill page with #0d1117 Background
        $s .= "BT\n";
        
        $s .= "0.902 0.929 0.953 rg\n"; // White text
        $s .= "/F2 18 Tf\n 50 790 Td\n(Andersen & Bollerslev \\(1998\\)) Tj\n";
        
        $s .= "0.545 0.580 0.620 rg\n"; // Grey text
        $s .= "/F2 13 Tf\n 0 -22 Td\n(Empirical Proof -- News Shock Candle Analysis) Tj\n";
        $s .= "/F1 10 Tf\n 0 -30 Td\n(Hypothesis: Price shock is absorbed within the first minute) Tj\n";
        $s .= "0 -14 Td\n(after a scheduled macroeconomic announcement.) Tj\n";
        
        $s .= "0.788 0.820 0.851 rg\n"; // Light Grey text
        $s .= "0 -28 Td\n(Dataset Summary:) Tj\n";
        $s .= "0 -16 Td\n(  Total events analyzed: ".pdfEscape(count($allPcts).' across '.count($PAIRS).' currency pairs').") Tj\n";
        $s .= "0 -14 Td\n(  Global average NC% of 5-min range: ".pdfEscape($globalAvg.'%').") Tj\n";
        $s .= "0 -14 Td\n(  Global median NC%: ".pdfEscape($globalMedian.'%').") Tj\n";
        $s .= "0 -14 Td\n(  Events where NC >= 50% of 5-min range: ".pdfEscape($pctAbove50.'% ('.$above50.'/'.count($allPcts).')').") Tj\n";
        
        $s .= "0.545 0.580 0.620 rg\n"; // Grey text
        $s .= "0 -28 Td\n(Each page below shows one randomly selected real news event.) Tj\n";
        $s .= "0 -14 Td\n(The gold-highlighted candle is the 1-min news candle.) Tj\n";
        $s .= "0 -14 Td\n(The percentage = news candle range / 5-min window range x 100.) Tj\n";
        $s .= "0 -28 Td\n(Generated: ".pdfEscape(date('Y-m-d H:i:s').' IST').") Tj\n";
        $s .= "ET\n";
        $makePage($s, 0);

        // ── EVENT PAGES (Dark Mode Styled) ───────────────────────────────────────
        foreach($pick as $item){
            $pair    = $item['pair'];
            $event   = substr($item['event'],0,60);
            $timeIST = $item['timeIST'];
            $pct     = $item['pct'];
            $dir     = $item['dir'];
            $ncPips  = $item['ncPips'];
            $rng5    = $item['rng5Pips'];
            $candles = $item['candles1m'];
            $newsIdx = $item['newsIdx'];
            $conf    = $pct>=20 ? 'CONFIRMS' : 'PARTIAL SUPPORT FOR';

            $png    = makeChartPng($candles, $newsIdx, $pair, $pct);
            $pngW   = 760; $pngH = 300;

            ob_start();
            $imgRes = imagecreatefromstring($png);
            imagejpeg($imgRes, null, 90);
            $jpg = ob_get_clean();
            imagedestroy($imgRes);
            $jpgLen = strlen($jpg);

            $imgId = $newObj("<< /Type /XObject /Subtype /Image\n".
                "/Width $pngW /Height $pngH\n".
                "/ColorSpace /DeviceRGB /BitsPerComponent 8\n".
                "/Filter /DCTDecode\n".
                "/Length $jpgLen >>\nstream\n".$jpg."\nendstream");

            $imgX=28; $imgY=350; $imgWpt=539; $imgHpt=212;

            $s  = "q 0.051 0.067 0.090 rg 0 0 595 842 re f Q\n"; // Fill page with #0d1117 Background
            $s .= "q\n";
            $s .= "$imgWpt 0 0 $imgHpt $imgX $imgY cm\n";
            $s .= "/Img1 Do\n";
            $s .= "Q\n";
            
            $s .= "BT\n";
            $s .= "0.902 0.929 0.953 rg\n"; // White Title
            $s .= "/F2 14 Tf\n 28 808 Td\n(".pdfEscape($pair.' -- '.substr($event,0,55)).") Tj\n";
            
            $s .= "0.545 0.580 0.620 rg\n"; // Grey Subtext
            $s .= "/F1 9 Tf\n 0 -16 Td\n(".pdfEscape($timeIST.' IST  |  Direction: '.$dir).") Tj\n";
            
            $s .= "0.788 0.820 0.851 rg\n"; // Light Grey Info Line
            $s .= "/F2 10 Tf\n 0 -20 Td\n";
            $s .= "(".pdfEscape("NC% of 5min: {$pct}%    NC Pips: {$ncPips}    5min Range: {$rng5} pips    A&B: {$conf}").") Tj\n";
            
            $s .= "0.902 0.929 0.953 rg\n"; // White Subheader
            $s .= "/F2 9 Tf\n 0 -".($imgHpt+16)." Td\n(News Candle OHLC:) Tj\n";
            
            $s .= "0.788 0.820 0.851 rg\n"; // Light Grey Stats
            $s .= "/F1 9 Tf\n 0 -13 Td\n";
            $s .= "(".pdfEscape("O: ".$item['ncOpen']."   H: ".$item['ncHigh']."   L: ".$item['ncLow']."   C: ".$item['ncClose']).") Tj\n";
            
            $s .= "0.902 0.929 0.953 rg\n"; // White Subheader
            $s .= "/F2 9 Tf\n 0 -20 Td\n(Andersen & Bollerslev \\(1998\\) -- What the paper says:) Tj\n";
            
            $s .= "0.788 0.820 0.851 rg\n"; // Light Grey
            $s .= "/F1 8 Tf\n 0 -13 Td\n(Scheduled macro announcements generate immediate price adjustments.) Tj\n";
            $s .= "0 -11 Td\n(The main move occurs within the first few minutes of the announcement.) Tj\n";
            
            $s .= "0.345 0.651 1.000 rg\n"; // Blue highlight (#58a6ff) for confirmation result
            $s .= "/F2 9 Tf\n 0 -16 Td\n";
            $confLine = "This event: {$pct}% of the 5-min range in the 1-min news candle. {$conf} hypothesis.";
            $s .= "(".pdfEscape($confLine).") Tj\n";
            
            $s .= "0.545 0.580 0.620 rg\n"; // Grey Footer
            $s .= "/F1 7 Tf\n 0 -30 Td\n";
            $s .= "(Methodology: 1-min NC range / 5-min window range x 100. High % = shock in first minute.) Tj\n";
            $s .= "ET\n";

            $makePage($s, $imgId);
        }

        $kidList = implode(' 0 R ', $pageIds).' 0 R';
        $pagesId = $newObj("<< /Type /Pages /Kids [$kidList] /Count ".count($pageIds)." >>");
        $catalogId=$newObj("<< /Type /Catalog /Pages $pagesId 0 R >>");

        foreach($objects as $id=>$content){
            $objects[$id] = str_replace('/Parent 9999 0 R', '/Parent '.$pagesId.' 0 R', $content);
        }

        $header  = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $body    = '';
        $offsets = array();
        foreach($objects as $id=>$content){
            $offsets[$id] = strlen($header)+strlen($body);
            $body .= "$id 0 obj\n".$content."\nendobj\n";
        }

        $xrefOffset = strlen($header)+strlen($body);
        $n = count($objects)+1;
        $xref = "xref\n0 $n\n0000000000 65535 f \n";
        for($id=1;$id<$n;$id++){
            $xref .= str_pad(isset($offsets[$id])?$offsets[$id]:0, 10,'0',STR_PAD_LEFT)." 00000 n \n";
        }
        $xref .= "trailer\n<< /Size $n /Root $catalogId 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";

        return $header.$body.$xref;
    }

    $pdfBytes = buildProofPdf($pick, $globalAvg, $globalMedian, $above50, $allPcts, $pctAbove50, $PAIRS);
    $filename = 'AB1998_Proof_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store');
    header('Content-Length: '.strlen($pdfBytes));
    echo $pdfBytes;
    exit;

} // end if(defined('PROOF_MODE'))
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>News Shock Report — Research</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#c9d1d9;font-family:'Segoe UI',sans-serif;font-size:13px}
a{color:#58a6ff}
#hero{background:linear-gradient(135deg,#0d1117 0%,#161b22 100%);border-bottom:1px solid #30363d;padding:24px 32px}
#hero h1{font-size:22px;color:#e6edf3;font-weight:700;margin-bottom:4px}
#hero .sub{color:#8b949e;font-size:12px}
#hero .badge{display:inline-block;background:#1f6feb22;border:1px solid #1f6feb;color:#58a6ff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;margin-left:8px}
#kpis{display:flex;gap:16px;padding:20px 32px;flex-wrap:wrap;border-bottom:1px solid #21262d}
.kpi{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:14px 20px;min-width:150px;flex:1}
.kpi .kval{font-size:26px;font-weight:700;line-height:1}
.kpi .klbl{font-size:11px;color:#8b949e;margin-top:4px}
.kpi.blue .kval{color:#58a6ff}
.kpi.green .kval{color:#3fb950}
.kpi.yellow .kval{color:#e3b341}
.kpi.cyan .kval{color:#39c3ef}
.kpi.red .kval{color:#f85149}
.section{padding:20px 32px}
.section h2{font-size:15px;font-weight:700;color:#e6edf3;margin-bottom:14px;border-left:3px solid #1f6feb;padding-left:10px}
#pair-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
.pair-card{background:#161b22;border:1px solid #30363d;border-radius:6px;padding:12px 14px}
.pair-card .pname{font-weight:700;font-size:13px;color:#e6edf3;margin-bottom:6px}
.pair-card .prow{display:flex;justify-content:space-between;font-size:11px;margin-top:3px}
.pair-card .prow .pl{color:#8b949e}
.pair-card .prow .pv{font-weight:600;font-family:monospace}
.pv.hi{color:#3fb950} .pv.lo{color:#f85149} .pv.med{color:#e3b341}
.tbl-wrap{overflow-x:auto;border-radius:6px;border:1px solid #21262d}
table{width:100%;border-collapse:collapse}
thead th{background:#161b22;padding:8px 10px;text-align:left;font-size:11px;font-weight:700;color:#8b949e;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #30363d;white-space:nowrap}
tbody tr{border-bottom:1px solid #12171e;transition:background .1s}
tbody tr:hover{background:#161b22}
tbody td{padding:7px 10px;font-size:11px;white-space:nowrap}
.td-pair{font-weight:700;color:#58a6ff}
.td-event{color:#c9d1d9;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.td-time{color:#8b949e;font-family:monospace}
.td-pips{font-family:monospace;color:#e6edf3}
.td-pct{font-family:monospace;font-weight:700;text-align:right}
.td-dir-bull{color:#3fb950;font-weight:700}
.td-dir-bear{color:#f85149;font-weight:700}
.pct-hi{color:#3fb950}
.pct-med{color:#e3b341}
.pct-lo{color:#f85149}
#filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
#filters input,#filters select{background:#0d1117;border:1px solid #30363d;border-radius:4px;color:#c9d1d9;padding:5px 9px;font-size:12px;outline:none}
#filters input:focus,#filters select:focus{border-color:#58a6ff}
.fbtn{background:#21262d;border:1px solid #30363d;color:#c9d1d9;padding:5px 12px;border-radius:4px;cursor:pointer;font-size:12px}
.fbtn:hover{background:#30363d}
#log-box{background:#0d1117;border:1px solid #21262d;border-radius:5px;padding:10px 14px;font-family:monospace;font-size:11px;max-height:180px;overflow-y:auto;color:#484f58}
#footer{padding:16px 32px;border-top:1px solid #21262d;font-size:11px;color:#484f58}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:#0d1117}
::-webkit-scrollbar-thumb{background:#30363d;border-radius:3px}
</style>
</head>
<body>

<div id="hero">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1>📊 News Shock Report
        <span class="badge">High Impact (★★★) Only</span>
      </h1>
      <div class="sub">
        Research: Andersen &amp; Bollerslev hypothesis — price shock absorbed in 1st minute after announcement &nbsp;·&nbsp;
        Generated <?= $genTime ?> IST &nbsp;·&nbsp;
        <?= count($PAIRS) ?> pairs &nbsp;·&nbsp; <?= count($results) ?> events analyzed
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end">
      <button id="proof-btn" onclick="downloadProof()"
        style="background:linear-gradient(135deg,#1f6feb,#388bfd);border:none;
               color:#fff;padding:10px 20px;border-radius:6px;cursor:pointer;
               font-size:13px;font-weight:700;letter-spacing:.3px;
               display:flex;align-items:center;gap:8px;white-space:nowrap;
               box-shadow:0 2px 12px #1f6feb55">
        <span>📄</span>
        <span id="proof-btn-lbl">Download PDF Proof</span>
      </button>
      <div id="proof-note" style="font-size:10px;color:#8b949e;text-align:right;max-width:220px">
        Randomly selects &amp; plots 12 real events as candlestick charts —
        proves A&amp;B (1998) with your own data
      </div>
    </div>
  </div>
</div>

<div id="kpis">
  <div class="kpi blue">
    <div class="kval"><?= count($results) ?></div>
    <div class="klbl">Total Events Analyzed</div>
  </div>
  <div class="kpi yellow">
    <div class="kval"><?= $globalAvg ?>%</div>
    <div class="klbl">Avg News Candle % of 5min Range</div>
  </div>
  <div class="kpi cyan">
    <div class="kval"><?= $globalMedian ?>%</div>
    <div class="klbl">Median %</div>
  </div>
  <div class="kpi green">
    <div class="kval"><?= $pctAbove50 ?>%</div>
    <div class="klbl">Events where shock ≥ 50% of 5min range<br><span style="font-size:10px;color:#3fb950">(<?= $above50 ?>/<?= count($allPcts) ?> events)</span></div>
  </div>
  <div class="kpi red">
    <div class="kval"><?= $pctAbove80 ?>%</div>
    <div class="klbl">Events where shock ≥ 80% of 5min range<br><span style="font-size:10px;color:#f85149">(<?= $above80 ?>/<?= count($allPcts) ?> events)</span></div>
  </div>
</div>

<div class="section">
  <h2>Per-Pair Summary</h2>
  <div id="pair-grid">
    <?php foreach($pairStats as $pair=>$s): ?>
    <div class="pair-card">
      <div class="pname"><?= $pair ?></div>
      <div class="prow"><span class="pl">Events</span><span class="pv med"><?= $s['count'] ?></span></div>
      <div class="prow"><span class="pl">Avg %</span><span class="pv <?= $s['avg']>=50?'hi':($s['avg']>=25?'med':'lo') ?>"><?= $s['avg'] ?>%</span></div>
      <div class="prow"><span class="pl">Median</span><span class="pv med"><?= $s['median'] ?>%</span></div>
      <div class="prow"><span class="pl">Min / Max</span><span class="pv lo"><?= $s['min'] ?>%</span>&nbsp;<span style="color:#30363d">/</span>&nbsp;<span class="pv hi"><?= $s['max'] ?>%</span></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="section">
  <h2>All Events — News Candle Analysis</h2>

  <div id="filters">
    <input type="text" id="srch" placeholder="🔍 Search event / pair…" oninput="filterTable()">
    <select id="dirFilter" onchange="filterTable()">
      <option value="">All Directions</option>
      <option value="Bull">Bullish only</option>
      <option value="Bear">Bearish only</option>
    </select>
    <select id="pctFilter" onchange="filterTable()">
      <option value="">All %</option>
      <option value="80">≥ 80%</option>
      <option value="50">≥ 50%</option>
      <option value="25">≥ 25%</option>
    </select>
    <select id="pairFilter" onchange="filterTable()">
      <option value="">All Pairs</option>
      <?php foreach($PAIRS as $p): ?><option><?= $p ?></option><?php endforeach; ?>
    </select>
    <button class="fbtn" onclick="resetFilters()">Reset</button>
    <span id="row-count" style="font-size:11px;color:#8b949e"></span>
  </div>

  <div class="tbl-wrap">
    <table id="main-table">
      <thead>
        <tr>
          <th>Pair</th>
          <th>Event</th>
          <th>Time (IST)</th>
          <th>NC Open</th>
          <th>NC High</th>
          <th>NC Low</th>
          <th>NC Close</th>
          <th>NC Pips</th>
          <th>5min Range</th>
          <th style="text-align:right">NC % of 5min</th>
          <th>Dir</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($results as $r):
          $pc = $r['pct'];
          $cls = $pc===null?'':($pc>=80?'pct-hi':($pc>=50?'pct-med':'pct-lo'));
          $dirCls = $r['dir']==='Bull'?'td-dir-bull':'td-dir-bear';
        ?>
        <tr
          data-pair="<?= $r['pair'] ?>"
          data-dir="<?= $r['dir'] ?>"
          data-pct="<?= $pc ?? 0 ?>"
          data-search="<?= strtolower($r['pair'].' '.$r['event']) ?>">
          <td class="td-pair"><?= $r['pair'] ?></td>
          <td class="td-event" title="<?= htmlspecialchars($r['event']) ?>"><?= htmlspecialchars(strlen($r['event'])>28?substr($r['event'],0,26).'…':$r['event']) ?></td>
          <td class="td-time"><?= $r['timeIST'] ?></td>
          <td class="td-pips"><?= $r['ncOpen'] ?></td>
          <td class="td-pips" style="color:#3fb950"><?= $r['ncHigh'] ?></td>
          <td class="td-pips" style="color:#f85149"><?= $r['ncLow'] ?></td>
          <td class="td-pips"><?= $r['ncClose'] ?></td>
          <td class="td-pips"><?= $r['ncPips'] ?></td>
          <td class="td-pips"><?= $r['rng5Pips'] ?></td>
          <td class="td-pct <?= $cls ?>"><?= $pc!==null ? $pc.'%' : '—' ?></td>
          <td class="<?= $dirCls ?>"><?= $r['dir'] ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="section">
  <h2>Processing Log</h2>
  <div id="log-box"><?= implode("\n", $log) ?></div>
</div>

<div id="footer">
  Methodology: For each high-impact news event, 1min news candle range ÷ 5min window range (<?= $PRE_5M ?> before + news + <?= $POST_5M ?> after) × 100.
  A high % confirms price shock is concentrated in the first minute post-announcement, supporting Andersen &amp; Bollerslev (1998).
</div>

<script>
function filterTable() {
    const srch   = document.getElementById('srch').value.toLowerCase();
    const dir    = document.getElementById('dirFilter').value;
    const minPct = parseFloat(document.getElementById('pctFilter').value)||0;
    const pair   = document.getElementById('pairFilter').value;
    let vis = 0;
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        const ok =
            (!srch   || tr.dataset.search.includes(srch)) &&
            (!dir    || tr.dataset.dir === dir) &&
            (!minPct || parseFloat(tr.dataset.pct) >= minPct) &&
            (!pair   || tr.dataset.pair === pair);
        tr.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });
    document.getElementById('row-count').textContent = vis + ' rows shown';
}
function resetFilters() {
    ['srch','dirFilter','pctFilter','pairFilter'].forEach(id=>{
        const el=document.getElementById(id);
        el.tagName==='INPUT'?el.value='':el.selectedIndex=0;
    });
    filterTable();
}
filterTable();

async function downloadProof() {
    const btn  = document.getElementById('proof-btn');
    const lbl  = document.getElementById('proof-btn-lbl');
    const note = document.getElementById('proof-note');

    btn.disabled = true;
    btn.style.opacity = '0.7';
    lbl.textContent = '⏳ Generating PDF…';
    note.textContent = 'Randomly selecting events & drawing candlestick charts…';

    let dots = 0;
    const timer = setInterval(() => {
        dots = (dots + 1) % 4;
        lbl.textContent = '⏳ Generating PDF' + '.'.repeat(dots);
    }, 400);

    try {
        const url = window.location.pathname + '?proof=1';
        const res = await fetch(url);
        clearInterval(timer);

        if (!res.ok) {
            const txt = await res.text();
            alert('PDF generation failed:\n\n' + txt.substring(0, 300));
            throw new Error('non-ok');
        }

        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('pdf')) {
            const txt = await res.text();
            alert('Unexpected response (not PDF):\n\n' + txt.substring(0, 400));
            throw new Error('not-pdf');
        }

        const blob = await res.blob();
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = 'AB1998_Proof_' + new Date().toISOString().slice(0,19).replace(/[:T]/g,'-') + '.pdf';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(a.href);

        lbl.textContent = '✅ Downloaded!';
        note.textContent = 'Each run picks different random events. Click again for a new proof set.';
        setTimeout(() => {
            lbl.textContent = '📄 Download PDF Proof';
            note.textContent = 'Randomly selects & plots 12 real events as candlestick charts — proves A&B (1998) with your own data';
            btn.disabled = false;
            btn.style.opacity = '1';
        }, 3000);

    } catch(e) {
        clearInterval(timer);
        lbl.textContent = '❌ Failed — try again';
        note.textContent = 'Check server logs. Python + reportlab must be installed.';
        btn.disabled = false;
        btn.style.opacity = '1';
        console.error(e);
    }
}
</script>
</body>
</html>