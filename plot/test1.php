<?php
// Increase resources so the server doesn't crash with large CSVs
ini_set('memory_limit', '512M');
set_time_limit(300);

$all_data = [null, null, null, null];
$names = ["Empty", "Empty", "Empty", "Empty"];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_files'])) {
    foreach ($_FILES['csv_files']['tmp_name'] as $key => $tmpName) {
        if ($key > 3 || empty($tmpName)) continue;

        $names[$key] = $_FILES['csv_files']['name'][$key];
        $handle = fopen($tmpName, "r");
        $is_header = true;
        $cols = ['time'=>0, 'open'=>1, 'high'=>2, 'low'=>3, 'close'=>4, 'vol'=>-1, 'alert'=>-1];
        
        $rows = [];
        while (($row = fgetcsv($handle)) !== FALSE) {
            if ($is_header) {
                foreach($row as $idx => $val) {
                    $v = strtolower(trim($val));
                    if (strpos($v, 'volume') !== false) $cols['vol'] = $idx;
                    if (strpos($v, 'alert') !== false) $cols['alert'] = $idx;
                }
                $is_header = false; continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        // Increased limit to 5000 candles for more history
        if (count($rows) > 5000) { $rows = array_slice($rows, -5000); }

        $candles = []; $volumes = []; $markers = [];
        foreach ($rows as $r) {
            $t = (int)$r[$cols['time']];
            $o = (float)$r[$cols['open']];
            $h = (float)$r[$cols['high']];
            $l = (float)$r[$cols['low']];
            $c = (float)$r[$cols['close']];
            $candles[] = ['time' => $t, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c];

            if ($cols['vol'] !== -1) {
                $volumes[] = ['time' => $t, 'value' => (float)$r[$cols['vol']], 'color' => $c >= $o ? '#26a69a80' : '#ef535080'];
            }
            if ($cols['alert'] !== -1 && !empty($r[$cols['alert']]) && $r[$cols['alert']] !== '0') {
                $markers[] = ['time' => $t, 'position' => 'aboveBar', 'color' => '#2196F3', 'shape' => 'arrowDown', 'text' => $r[$cols['alert']]];
            }
        }
        $all_data[$key] = ['c' => $candles, 'v' => $volumes, 'm' => $markers];
        unset($rows); // Clear RAM
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>TradingView 4-Chart Grid</title>
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        body { margin: 0; background: #131722; color: #d1d4dc; font-family: sans-serif; overflow: hidden; }
        .top-nav { height: 50px; background: #1e222d; display: flex; align-items: center; padding: 0 15px; border-bottom: 1px solid #363c4e; }
        
        /* 2x2 GRID SYSTEM */
        .grid-container { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            grid-template-rows: 1fr 1fr; 
            height: calc(100vh - 50px); 
            width: 100vw;
            gap: 2px;
            background: #363c4e; /* Grid line color */
        }
        
        .chart-pane { 
            background: #131722; 
            position: relative; 
            display: flex;
            flex-direction: column;
        }
        .pane-header {
            background: #1e222d;
            font-size: 11px;
            padding: 4px 8px;
            border-bottom: 1px solid #2a2e39;
            color: #2196f3;
        }
        .chart-holder { flex-grow: 1; }
        
        form { font-size: 13px; }
        button { background: #2196f3; color: white; border: none; padding: 5px 12px; border-radius: 3px; cursor: pointer; }
    </style>
</head>
<body>

<div class="top-nav">
    <form method="POST" enctype="multipart/form-data">
        <b style="margin-right:10px">Upload 4 CSVs:</b>
        <input type="file" name="csv_files[]" multiple>
        <button type="submit">Update Grid</button>
    </form>
</div>

<div class="grid-container">
    <?php for($i=0; $i<4; $i++): ?>
    <div class="chart-pane">
        <div class="pane-header">CHART <?php echo $i+1 ?>: <?php echo $names[$i] ?></div>
        <div id="chart<?php echo $i ?>" class="chart-holder"></div>
    </div>
    <?php endfor; ?>
</div>

<script>
    const allData = <?php echo json_encode($all_data); ?>;
    
    allData.forEach((data, i) => {
        const container = document.getElementById(`chart${i}`);
        const chart = LightweightCharts.createChart(container, {
            layout: { backgroundColor: '#131722', textColor: '#d1d4dc' },
            grid: { vertLines: { color: '#2b2b43' }, horzLines: { color: '#2b2b43' } },
            timeScale: { timeVisible: true, borderColor: '#363c4e' },
            rightPriceScale: { borderColor: '#363c4e' },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal }
        });

        if (data && data.c.length > 0) {
            const candles = chart.addCandlestickSeries({
                upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
                wickUpColor: '#26a69a', wickDownColor: '#ef5350'
            });
            candles.setData(data.c);

            const volume = chart.addHistogramSeries({
                priceFormat: { type: 'volume' },
                priceScaleId: '',
                scaleMargins: { top: 0.8, bottom: 0 }
            });
            volume.setData(data.v);

            candles.setMarkers(data.m);
            chart.timeScale().fitContent();
        }

        // Resize observer to keep charts perfect in the grid
        new ResizeObserver(() => {
            chart.applyOptions({ 
                width: container.clientWidth, 
                height: container.clientHeight 
            });
        }).observe(container);
    });
</script>
</body>
</html>