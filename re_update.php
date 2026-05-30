<?php
session_start();
require 'db.php';

// ERROR REPORTING 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// BATCH SETTINGS
$batch_size = 20;

// Handle manual reset of the queue
if(isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['current_offset']);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Initialize session offset if it doesn't exist
if(!isset($_SESSION['current_offset'])) {
    $_SESSION['current_offset'] = 0;
}
$current_offset = (int)$_SESSION['current_offset'];

// Get total rows in the database to track progress
$count_result = $conn->query("SELECT COUNT(*) as total FROM prediction_trade_data");
$total_rows = $count_result->fetch_assoc()['total'];
$is_done = ($current_offset >= $total_rows);

// Auto-refresh the page every 3 seconds if we are not done
if(!$is_done) {
    header("Refresh: 7"); 
}

// FUNCTIONS
function is_bullish($open, $close){ return floatval($close) > floatval($open); }
function is_bearish($open, $close){ return floatval($close) < floatval($open); }

function calculate_price_target_from_candles($candles){
    if(count($candles) < 5) return null;
    
    $first3_bullish = true; 
    $first3_bearish = true;
    
    for($i = 0; $i < 3; $i++){
        if(!is_bullish($candles[$i]['O'], $candles[$i]['C'])) $first3_bullish = false;
        if(!is_bearish($candles[$i]['O'], $candles[$i]['C'])) $first3_bearish = false;
    }
    
    if(!$first3_bullish && !$first3_bearish) return null;

    $price_target = null;
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

// FETCH AND UPDATE BATCH DATA
$updated_ids_this_batch = [];

if(!$is_done) {
    // Bypassing get_result() by safely appending the integer variables directly to the query
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
    LIMIT " . (int)$batch_size . " OFFSET " . (int)$current_offset;
    
    $result = $conn->query($sql);

    if($result && $result->num_rows > 0){
        // Prepare the update statement once to use inside the loop (better performance)
        $update_stmt = $conn->prepare("UPDATE prediction_trade_data SET price_target=? WHERE raw_trade_id=?");
        
        while($row = $result->fetch_assoc()){
            $candles = [
                ['O'=>$row['O1'],'H'=>$row['H1'],'L'=>$row['L1'],'C'=>$row['C1']],
                ['O'=>$row['O2'],'H'=>$row['H2'],'L'=>$row['L2'],'C'=>$row['C2']],
                ['O'=>$row['O3'],'H'=>$row['H3'],'L'=>$row['L3'],'C'=>$row['C3']],
                ['O'=>$row['O4'],'H'=>$row['H4'],'L'=>$row['L4'],'C'=>$row['C4']],
                ['O'=>$row['O5'],'H'=>$row['H5'],'L'=>$row['L5'],'C'=>$row['C5']]
            ];
            
            $price_target_calculated = calculate_price_target_from_candles($candles);
            
            if($price_target_calculated !== null){
                $update_stmt->bind_param("di", $price_target_calculated, $row['raw_trade_id']);
                $update_stmt->execute();
                
                // Track the ID that was successfully updated
                $updated_ids_this_batch[] = $row['raw_trade_id'];
            }
        }
        $update_stmt->close();
    }
    
    // Advance the queue by 20 for the next page load
    $_SESSION['current_offset'] += $batch_size;
}

?>
<!DOCTYPE html>
<html>
<head>
<title>Batch Processor - Prediction Trade Data</title>
<style>
body { font-family: Arial, sans-serif; margin:30px; background-color: #f9f9f9; }
.container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.1); }
h2 { text-align:center; color: #333; margin-bottom:10px; }
.progress-bar { width: 100%; background-color: #e0e0e0; border-radius: 8px; margin: 25px 0; overflow: hidden; }
.progress-fill { height: 25px; background-color: #2ca02c; width: <?php echo $total_rows > 0 ? min(100, ($current_offset / $total_rows) * 100) : 0; ?>%; transition: width 0.3s; }
.stats { text-align: center; font-size: 16px; margin-bottom: 20px; color: #555; }
.reset-btn { display: inline-block; padding: 10px 18px; background: #d62728; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; transition: 0.2s;}
.reset-btn:hover { background: #b02022; }
.done-msg { background: #d4edda; color: #155724; padding: 15px; text-align: center; border-radius: 5px; font-weight: bold; font-size: 18px; margin-bottom: 20px;}
.results-box { background: #e9ecef; border-left: 4px solid #007bff; padding: 20px; border-radius: 4px; margin-top: 20px;}
.id-list { font-family: monospace; font-size: 16px; color: #0056b3; word-wrap: break-word; line-height: 1.6;}
</style>
</head>
<body>

<div class="container">
    <h2>Batch Processing Data</h2>
    
    <div class="stats">
        <?php if($is_done): ?>
            <div class="done-msg">Process Complete! All <?php echo $total_rows; ?> rows have been processed.</div>
        <?php else: ?>
            Processing rows <strong><?php echo $current_offset - $batch_size + 1; ?></strong> to <strong><?php echo min($current_offset, $total_rows); ?></strong> 
            of <strong><?php echo $total_rows; ?></strong> (Auto-refreshing in 7s...)
        <?php endif; ?>
    </div>

    <div class="progress-bar">
        <div class="progress-fill"></div>
    </div>
    
    <div style="text-align: right;">
        <a href="?reset=1" class="reset-btn">Reset Queue & Start Over</a>
    </div>

    <?php if(!$is_done): ?>
    <div class="results-box">
        <h3>Results for Current Batch</h3>
        <?php if(count($updated_ids_this_batch) > 0): ?>
            <p><strong>Successfully Updated IDs:</strong></p>
            <p class="id-list">
                <?php echo implode(', ', $updated_ids_this_batch); ?>
            </p>
        <?php else: ?>
            <p style="color: #6c757d; font-style: italic;">No rows in this batch met the strategy criteria. Nothing was updated.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php $conn->close(); ?>