<?php
// PROJECT BY : SAI SAMHITH REDDY
// Ensure db.php exists and contains valid $conn credentials
require 'db.php'; 

date_default_timezone_set('Asia/Kolkata');

// --- SECTION 1: SEAMLESS 5-MINUTE BUCKET AGGREGATION ---
$targetFolder = "trail/";
$targetFile = $targetFolder . "DATA.CSV";
if (!is_dir($targetFolder)) { mkdir($targetFolder, 0777, true); }

// Fetch the latest GBPJPY price from your live_price_data
$log_query = "SELECT current_price, updated_at FROM live_price_data WHERE pair_name = 'GBPJPY' LIMIT 1";
$log_res = $conn->query($log_query);

$debug_info = "";

if ($log_res && $log_res->num_rows > 0) {
    $row = $log_res->fetch_assoc();
    $current_price = (float)$row['current_price'];
    $current_time = strtotime($row['updated_at']);
    
    // Aligns to 00, 05, 10... buckets
    $five_min_bucket = floor($current_time / 300) * 300; 

    $all_data = [];
    if (file_exists($targetFile)) {
        $all_data = array_map('str_getcsv', file($targetFile));
        $header = array_shift($all_data); 
    } else {
        $header = ['time', 'open', 'high', 'low', 'close', 'Pattern Alert', 'Volume'];
    }

    $last_idx = count($all_data) - 1;
    $last_candle = $last_idx >= 0 ? $all_data[$last_idx] : null;
    
    if ($last_candle && (int)$last_candle[0] == $five_min_bucket) {
        // Update existing bucket
        $all_data[$last_idx][2] = max((float)$last_candle[2], $current_price); // High
        $all_data[$last_idx][3] = min((float)$last_candle[3], $current_price); // Low
        $all_data[$last_idx][4] = $current_price; // Close
        $debug_info = "Updating current 5m candle.";
    } else {
        // SEAMLESS TRANSITION: Use previous Close as the new Open
        $new_open = $last_candle ? (float)$last_candle[4] : $current_price;
        $new_high = max($new_open, $current_price);
        $new_low = min($new_open, $current_price);

        $all_data[] = [$five_min_bucket, $new_open, $new_high, $new_low, $current_price, 0, 0];
        $debug_info = "Started new 5m bucket.";
    }

    // Save strictly to DATA.CSV
    $fp = fopen($targetFile, 'w');
    fputcsv($fp, $header);
    foreach ($all_data as $line) { fputcsv($fp, $line); }
    fclose($fp);
} else {
    $debug_info = "Error: No GBPJPY data found in live_price_data table.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seamless 5M GBPJPY Plotter</title>
    <meta http-equiv="refresh" content="10">
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #121212; margin: 0; padding: 20px; text-align: center; color: #fff; }
        .header-container { background: #1e1e1e; color: #00ff88; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #333; }
        .live-clock { font-size: 20px; font-weight: bold; font-family: monospace; }
        #chart-container { width: 95%; height: 650px; margin: auto; background: #1e1e1e; border: 1px solid #333; position: relative; border-radius: 8px; }
        #ohlc-display { position: absolute; top: 15px; left: 15px; z-index: 10; background: rgba(30, 30, 30, 0.9); padding: 10px; border-radius: 4px; border: 1px solid #444; font-family: monospace; color: #00ff88; text-align: left; }
        .debug-bar { font-size: 11px; color: #888; margin-top: 10px; }
    </style>
</head>
<body>

    <div class="header-container">
        <div>
            <div style="font-size: 18px; font-weight: bold;">📊 GBPJPY 5M SEAMLESS LIVE</div>
            <div style="font-size: 11px; color: #888;">No-Gap Transitions | 5-Minute Buckets</div>
        </div>
        <div class="live-clock" id="digital-clock">00:00:00</div>
    </div>

    

    <div id="chart-container">
        <div id="ohlc-display">Checking Data Status...</div>
    </div>

    <div class="debug-bar">Status: <?php echo $debug_info; ?> | File: trail/DATA.CSV</div>

<script>
    // 1. Digital Clock
    function updateClock() {
        document.getElementById('digital-clock').innerText = new Date().toLocaleTimeString() + " IST";
    }
    setInterval(updateClock, 1000);
    updateClock();

    // 2. Chart Setup
    const container = document.getElementById('chart-container');
    const chart = LightweightCharts.createChart(container, {
        width: container.clientWidth,
        height: container.clientHeight,
        layout: { backgroundColor: '#1e1e1e', textColor: '#d1d4dc' },
        grid: { vertLines: { color: '#2b2b43' }, horzLines: { color: '#2b2b43' } },
        timeScale: { 
            timeVisible: true, 
            secondsVisible: false,
            borderColor: '#485c7b',
        },
        crosshair: { mode: LightweightCharts.CrosshairMode.Normal }
    });

    const candleSeries = chart.addCandlestickSeries({
        upColor: '#00ff88', downColor: '#ff3355', 
        borderVisible: false, wickUpColor: '#00ff88', wickDownColor: '#ff3355',
        priceFormat: { type: 'custom', formatter: (p) => p.toFixed(5) }
    });

    // 3. Data Fetching
    const formData = new FormData();
    formData.append('server_file', 'trail/DATA.CSV');

    fetch('process_csv.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.error) throw new Error(data.error);
            
            candleSeries.setData(data.candlesticks);

            // Visibility fix: Center on last candles
            if (data.candlesticks.length > 0) {
                chart.timeScale().setVisibleLogicalRange({
                    from: data.candlesticks.length - 30,
                    to: data.candlesticks.length + 3
                });
            }
            
            document.getElementById('ohlc-display').innerHTML = "GBPJPY Stream Active<br>Hover for OHLC Details";
        })
        .catch(err => {
            document.getElementById('ohlc-display').innerHTML = "Chart Ready: " + err.message;
        });

    // 4. OHLC Display
    chart.subscribeCrosshairMove(param => {
        if (param.time) {
            const d = param.seriesPrices.get(candleSeries);
            if (d) document.getElementById('ohlc-display').innerHTML = 
                `O: ${d.open.toFixed(5)} H: ${d.high.toFixed(5)}<br>L: ${d.low.toFixed(5)} C: ${d.close.toFixed(5)}`;
        }
    });

    window.onresize = () => chart.applyOptions({ width: container.clientWidth, height: container.clientHeight });
</script>
</body>
</html>