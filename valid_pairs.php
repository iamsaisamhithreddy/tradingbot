<?php
// Detect if the script is being run by a Cron Job (CLI)
$isCron = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));

// Only start sessions and output buffering if accessed via a web browser
if (!$isCron) {
    ob_start();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

include 'db.php'; // database connection
header("Refresh:15"); // refresh after 15 seconds
//  ERROR REPORTING 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


//  FUNCTIONS
function is_bullish($open, $close){ return floatval($close) > floatval($open); }
function is_bearish($open, $close){ return floatval($close) < floatval($open); }

function calculate_price_target_from_candles($candles){
    if(count($candles) < 5) return null;
    
    $first3_bullish = true; 
    $first3_bearish = true;
    
    // Check the trend of the first 3 candles
    for($i = 0; $i < 3; $i++){
        if(!is_bullish($candles[$i]['O'], $candles[$i]['C'])) $first3_bullish = false;
        if(!is_bearish($candles[$i]['O'], $candles[$i]['C'])) $first3_bearish = false;
    }
    
    if(!$first3_bullish && !$first3_bearish) return null;

    $price_target = null;
    
    // The Last Trend Candle is C3 (index 2)
    $c3 = $candles[2];
    $c3_range = floatval($c3['H']) - floatval($c3['L']);
    $c3_half_range = $c3_range / 2.0;

    // SCENARIO 1: DOWNTREND
    if($first3_bearish){
        $c3_midpoint = floatval($c3['L']) + $c3_half_range;
        $touches_half = false;
        $highest_opp_high = null;
        $opp_candles_exist = false;

        for($i = 3; $i < 5; $i++){
            if(is_bullish($candles[$i]['O'], $candles[$i]['C'])){
                $opp_candles_exist = true;
                if(floatval($candles[$i]['H']) >= $c3_midpoint) {
                    $touches_half = true;
                }
                if($highest_opp_high === null || floatval($candles[$i]['H']) > $highest_opp_high) {
                    $highest_opp_high = floatval($candles[$i]['H']);
                }
            }
        }

        if($opp_candles_exist) {
            if(!$touches_half) {
                $price_target = $highest_opp_high;
            } else {
                $c_prev1 = $candles[2]; 
                $c_prev2 = $candles[1]; 
                $u_wick1 = floatval($c_prev1['H']) - max(floatval($c_prev1['O']), floatval($c_prev1['C']));
                
                $recent_candles = [$candles[0], $candles[1], $candles[2]];
                $total_size = 0;
                foreach($recent_candles as $c) {
                    $total_size += (floatval($c['H']) - floatval($c['L']));
                }
                $avg_candle_size = $total_size / 3.0;
                
                if($avg_candle_size > 0 && $u_wick1 > ($avg_candle_size * 0.15)) {
                    $price_target = floatval($c_prev1['H']);
                } else {
                    if(floatval($c_prev1['H']) > floatval($c_prev2['H'])) {
                        $price_target = floatval($c_prev1['H']);
                    } else {
                        $price_target = floatval($c_prev2['H']);
                    }
                }
            }
        }
        return $price_target;
    }

    // SCENARIO 2: UPTREND
    if($first3_bullish){
        $c3_midpoint = floatval($c3['H']) - $c3_half_range;
        $touches_half = false;
        $lowest_opp_low = null;
        $opp_candles_exist = false;

        for($i = 3; $i < 5; $i++){
            if(is_bearish($candles[$i]['O'], $candles[$i]['C'])){
                $opp_candles_exist = true;
                if(floatval($candles[$i]['L']) <= $c3_midpoint) {
                    $touches_half = true;
                }
                if($lowest_opp_low === null || floatval($candles[$i]['L']) < $lowest_opp_low) {
                    $lowest_opp_low = floatval($candles[$i]['L']);
                }
            }
        }

        if($opp_candles_exist) {
            if(!$touches_half) {
                $price_target = $lowest_opp_low;
            } else {
                $c_prev1 = $candles[2]; 
                $c_prev2 = $candles[1]; 
                $l_wick1 = min(floatval($c_prev1['O']), floatval($c_prev1['C'])) - floatval($c_prev1['L']);
                
                $recent_candles = [$candles[0], $candles[1], $candles[2]];
                $total_size = 0;
                foreach($recent_candles as $c) {
                    $total_size += (floatval($c['H']) - floatval($c['L']));
                }
                $avg_candle_size = $total_size / 3.0;
                
                if($avg_candle_size > 0 && $l_wick1 > ($avg_candle_size * 0.15)) {
                    $price_target = floatval($c_prev1['L']);
                } else {
                    if(floatval($c_prev1['L']) < floatval($c_prev2['L'])) {
                        $price_target = floatval($c_prev1['L']);
                    } else {
                        $price_target = floatval($c_prev2['L']);
                    }
                }
            }
        }
        return $price_target;
    }
    
    return null;
}

// FETCH AND UPDATE DATA 

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

// HTML DISPLAY
$result = $conn->query($sql); // re-fetch
?>
<!DOCTYPE html>
<html>
<head>
<title>Prediction Trade Data</title>
<style>
body { font-family: Arial; margin:30px; background-color:#fafafa; }
h2 { text-align:center; margin-bottom:20px; color:#222; }
table { border-collapse:collapse; width:95%; margin:0 auto; font-size:14px; background:white; }
th, td { border:1px solid #ddd; padding:10px 12px; text-align:center; }
th { background-color:#222; color:white; }
tr:nth-child(even){ background-color:#f9f9f9; }
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