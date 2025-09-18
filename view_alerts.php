<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection

if(php_sapi_name()!=='cli')
    { 
        session_start(); 
    }

// ------------------ ERROR REPORTING ------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ------------------ ACCESS CONTROL ------------------
$access_granted = false;

// Admin session access
if(isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']===true){
    $access_granted = true;
}

// Token access via GET parameter or CLI
$provided_token = trim($_GET['token'] ?? '');
if(!$access_granted && $provided_token!==''){
    $stmt = $conn->prepare("SELECT id, expires_at FROM access_tokens WHERE token=? LIMIT 1");
    $stmt->bind_param("s", $provided_token);
    $stmt->execute();
    $stmt->bind_result($id, $expires_at);
    $found = false;
    if($stmt->fetch()){
        if($expires_at===null || $expires_at>date('Y-m-d H:i:s')){
            $found = true;
        }
    }
    if($found) $access_granted = true;
    $stmt->close();
}

// CLI token access
if(!$access_granted && php_sapi_name()==='cli'){
    $cli_token = $argv[1] ?? '';
    if($cli_token!==''){
        $stmt = $conn->prepare("SELECT id, expires_at FROM access_tokens WHERE token=? LIMIT 1");
        $stmt->bind_param("s",$cli_token);
        $stmt->execute();
        $stmt->bind_result($id,$expires_at);
        $found=false;
        if($stmt->fetch()){
            if($expires_at===null || $expires_at>date('Y-m-d H:i:s')) $found=true;
        }
        if($found) $access_granted=true;
        $stmt->close();
    }
}

// Deny access if not allowed
if(!$access_granted){
    if(php_sapi_name()==='cli') exit("Access denied.\n");
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied.");
}

// ------------------ HELPER FUNCTIONS ------------------
function is_bullish($open,$close){ return floatval($close)>floatval($open); }
function is_bearish($open,$close){ return floatval($close)<floatval($open); }
function calculate_price_target_from_candles($candles){
    if(count($candles)<5) return null;
    $EPS=0.0000001;
    $first3_bullish=true; $first3_bearish=true;
    for($i=0;$i<3;$i++){
        if(!is_bullish($candles[$i]['O'],$candles[$i]['C'])) $first3_bullish=false;
        if(!is_bearish($candles[$i]['O'],$candles[$i]['C'])) $first3_bearish=false;
    }
    if(!$first3_bullish && !$first3_bearish) return null;

    $opposite_candles=array_slice($candles,3,2);
    $last_trend_candle=$candles[2];
    $range=floatval($last_trend_candle['H'])-floatval($last_trend_candle['L']);
    $half_range=$range/2;
    $next_candle=$candles[3];

    if($first3_bullish){
        $half_level=floatval($last_trend_candle['L'])+$half_range;
        $touches_half=false; $lowest_low=null;
        foreach($opposite_candles as $c){
            if(is_bearish($c['O'],$c['C'])){
                if(floatval($c['L'])<=$half_level) $touches_half=true;
                if($lowest_low===null || floatval($c['L'])<$lowest_low) $lowest_low=floatval($c['L']);
            }
        }
        $price_target=$touches_half?floatval($last_trend_candle['L']):$lowest_low;
        if($price_target!==null && abs($price_target-floatval($next_candle['O']))<$EPS){
            if(abs(floatval($next_candle['O'])-floatval($next_candle['L']))<$EPS) $price_target=floatval($candles[2]['L']);
        }
        return $price_target;
    }

    if($first3_bearish){
        $half_level=floatval($last_trend_candle['H'])-$half_range;
        $touches_half=false; $highest_high=null;
        foreach($opposite_candles as $c){
            if(is_bullish($c['O'],$c['C'])){
                if(floatval($c['H'])>=$half_level) $touches_half=true;
                if($highest_high===null || floatval($c['H'])>$highest_high) $highest_high=floatval($c['H']);
            }
        }
        $price_target=$touches_half?floatval($last_trend_candle['H']):$highest_high;
        if($price_target!==null && abs($price_target-floatval($next_candle['O']))<$EPS){
            if(abs(floatval($next_candle['O'])-floatval($next_candle['H']))<$EPS) $price_target=floatval($candles[2]['H']);
        }
        return $price_target;
    }
    return null;
}

// ------------------ FETCH AND UPDATE DATA ------------------
$sql = "
SELECT  
    p.*,  
    r.O1, r.H1, r.L1, r.C1,
    r.O2, r.H2, r.L2, r.C2,
    r.O3, r.H3, r.L3, r.C3,
    r.O4, r.H4, r.L4, r.C4,
    r.O5, r.H5, r.L5, r.C5,
    r.created_at
FROM prediction_trade_data p
LEFT JOIN raw_trade_data r ON p.raw_trade_id = r.id
ORDER BY p.raw_trade_id DESC
";

$result = $conn->query($sql);
if($result && $result->num_rows>0){
    while($row=$result->fetch_assoc()){
        $candles = [
            ['O'=>$row['O1'],'H'=>$row['H1'],'L'=>$row['L1'],'C'=>$row['C1']],
            ['O'=>$row['O2'],'H'=>$row['H2'],'L'=>$row['L2'],'C'=>$row['C2']],
            ['O'=>$row['O3'],'H'=>$row['H3'],'L'=>$row['L3'],'C'=>$row['C3']],
            ['O'=>$row['O4'],'H'=>$row['H4'],'L'=>$row['L4'],'C'=>$row['C4']],
            ['O'=>$row['O5'],'H'=>$row['H5'],'L'=>$row['L5'],'C'=>$row['C5']]
        ];
        $price_target_calculated = calculate_price_target_from_candles($candles);
        if($price_target_calculated!==null){
            $stmt=$conn->prepare("UPDATE prediction_trade_data SET price_target=? WHERE raw_trade_id=?");
            $stmt->bind_param("di",$price_target_calculated,$row['raw_trade_id']);
            $stmt->execute();
            $stmt->close();
        }
    }
    $result->free();
}

// ------------------ HTML DISPLAY ------------------
$result = $conn->query($sql); // re-fetch updated data
?>
<!DOCTYPE html>
<html>
<head>
<title>Prediction Trade Data</title>
<style>
body { font-family: Arial; margin:30px; }
h2 { text-align:center; margin-bottom:20px; }
table { border-collapse:collapse; width:95%; margin:0 auto; font-size:14px; }
th, td { border:1px solid #444; padding:8px 12px; text-align:center; }
th { background-color:#222; color:white; }
tr:nth-child(even){ background-color:#f2f2f2; }
</style>
</head>
<body>

<h2>Prediction Trade Data Table</h2>
<table>
<thead>
<tr>
<th>Raw Trade ID</th>
<th>Pair Name</th>
<th>Price Target</th>
<th>Trade Direction</th>
<th>O1</th>
<th>Created At (IST)</th>
</tr>
</thead>
<tbody>
<?php
if($result && $result->num_rows>0){
    while($row=$result->fetch_assoc()){
        $price_target_display = $row['price_target']!==null ? number_format(floatval($row['price_target']),5) : "N/A";
        $utc_time = new DateTime($row['created_at'], new DateTimeZone('UTC'));
        $utc_time->setTimezone(new DateTimeZone('Asia/Kolkata'));
        $ist_time = $utc_time->format('Y-m-d H:i:s');
        echo "<tr>
            <td>".htmlspecialchars($row['raw_trade_id'])."</td>
            <td>".htmlspecialchars($row['pair_name'])."</td>
            <td>$price_target_display</td>
            <td>".htmlspecialchars($row['trade_direction'])."</td>
            <td>".htmlspecialchars($row['O1'])."</td>
            <td>$ist_time</td>
        </tr>";
    }
}else{
    echo "<tr><td colspan='6'>No prediction data found.</td></tr>";
}
$conn->close();
?>
</tbody>
</table>
</body>
</html>
