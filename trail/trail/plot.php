<!DOCTYPE html>
<html>
<head>
    <title>Trade Visualizer & Terminal</title>
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f4f4; margin: 0; padding: 10px; overflow: hidden; }
        #chart-container { width: 100%; height: 95vh; margin: 0 auto; border: 1px solid #ccc; background: #fff; position: relative; border-radius: 8px; }
        #ohlc-display { position: absolute; top: 10px; left: 10px; background: rgba(255,255,255,0.9); padding: 8px; border: 1px solid #ccc; z-index: 10; font-family: monospace; border-radius: 4px; }
        
        /* Signal & Update Panel */
        #trade-details-panel { 
            position: absolute; top: 10px; right: 10px; 
            background: rgba(255, 255, 255, 0.98); padding: 15px; 
            border: 2px solid #007bff; border-radius: 8px; 
            z-index: 100; min-width: 250px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .panel-title { font-weight: bold; border-bottom: 2px solid #007bff; margin-bottom: 10px; color: #007bff; font-size: 14px; }
        .detail-row { margin-bottom: 8px; font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 4px; display: flex; justify-content: space-between; }
        .detail-label { font-weight: bold; color: #555; }
        .buy-label { color: #28a745; font-weight: bold; }
        .sell-label { color: #dc3545; font-weight: bold; }

        /* Update Action Buttons */
        .update-section { margin-top: 15px; padding-top: 10px; border-top: 2px solid #eee; }
        .btn-group { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .btn-action { border: none; padding: 10px; border-radius: 4px; color: white; cursor: pointer; font-weight: bold; font-size: 12px; transition: 0.2s; }
        .btn-win { background: #28a745; }
        .btn-win:hover { background: #218838; }
        .btn-loss { background: #dc3545; }
        .btn-loss:hover { background: #c82333; }
        .btn-avoid { background: #6c757d; grid-column: span 2; margin-top: 5px; }
        .btn-avoid:hover { background: #5a6268; }
    </style>
</head>
<body>

    <div id="chart-container">
        <div id="ohlc-display">Initializing Chart...</div>
        
        <div id="trade-details-panel">
            <div class="panel-title">VERIFY SIGNAL</div>
            <div id="details-content"></div>
            
            <div class="update-section">
                <form method="POST" action="index.php" target="_parent">
                    <input type="hidden" name="trade_id" id="form_trade_id">
                    <div class="btn-group">
                        <button type="submit" name="update_result" value="win" class="btn-action btn-win">Mark WIN</button>
                        <button type="submit" name="update_result" value="loss" class="btn-action btn-loss">Mark LOSS</button>
                        <button type="submit" name="update_result" value="setup_not_formed" class="btn-action btn-avoid">Mark AVOID</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
    let chart, candleSeries, volSeries, chartData = null;
    const container = document.getElementById('chart-container');

    window.onload = function() {
        const params = new URLSearchParams(window.location.search);
        
        if(params.get('id')) {
            showDetails(params);
            document.getElementById('form_trade_id').value = params.get('id');
        }
        
        if (params.get('file')) {
            autoLoadCSV(params.get('file'), params.get('goto'), params.get('price'));
        }
    };

    function showDetails(params) {
        const content = document.getElementById('details-content');
        const dir = params.get('dir');
        content.innerHTML = `
            <div class="detail-row"><span class="detail-label">Asset:</span> <span>${params.get('pair')}</span></div>
            <div class="detail-row"><span class="detail-label">Entry:</span> <span>${params.get('price')}</span></div>
            <div class="detail-row"><span class="detail-label">Dir:</span> <span class="${dir === 'BUY' ? 'buy-label' : 'sell-label'}">${dir}</span></div>
            <div class="detail-row"><span class="detail-label">Time:</span> <span style="font-size:11px;">${params.get('time')}</span></div>
        `;
    }

    function autoLoadCSV(filePath, timestamp, targetPrice) {
        const formData = new FormData();
        formData.append('server_file', filePath);
        
        fetch('process_csv.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.error) throw new Error(data.error);
                initChart(data);
                setTimeout(() => focusAndJump(parseInt(timestamp), parseFloat(targetPrice)), 500);
            })
            .catch(err => { document.getElementById('ohlc-display').innerHTML = "Error: " + err.message; });
    }

    function initChart(data) {
        chartData = data;
        chart = LightweightCharts.createChart(container, {
            width: container.clientWidth,
            height: container.clientHeight,
            timeScale: { timeVisible: true, secondsVisible: false },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal }
        });

        candleSeries = chart.addCandlestickSeries({
            upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
            priceFormat: { type: 'custom', formatter: (p) => p.toFixed(5) }
        });

        volSeries = chart.addHistogramSeries({ priceScaleId: '', scaleMargins: { top: 0.8, bottom: 0 } });
        
        candleSeries.setData(data.candlesticks);
        volSeries.setData(data.volume);
        candleSeries.setMarkers(data.alerts);

        // --- IST LOGIC START ---
        const istOpts = {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false, timeZone: 'Asia/Kolkata'
        };

        chart.subscribeCrosshairMove(param => {
            if (param.time) {
                const date = new Date(param.time * 1000);
                const istTime = date.toLocaleString('en-IN', istOpts);
                const d = param.seriesPrices.get(candleSeries);
                if (d) {
                    document.getElementById('ohlc-display').innerHTML = 
                        `<b>${istTime} (IST)</b><br>O: ${d.open.toFixed(5)} H: ${d.high.toFixed(5)} L: ${d.low.toFixed(5)} C: ${d.close.toFixed(5)}`;
                }
            }
        });
        // --- IST LOGIC END ---
    }

    function focusAndJump(targetTs, targetPrice) {
        if(!chart || !chartData) return;

        candleSeries.createPriceLine({
            price: targetPrice, color: '#007bff', lineWidth: 2, lineStyle: 2, title: 'ENTRY'
        });

        const idx = chartData.candlesticks.findIndex(c => c.time >= targetTs);
        if(idx !== -1) {
            const range = 40; 
            const start = chartData.candlesticks[Math.max(0, idx - range)].time;
            const end = chartData.candlesticks[Math.min(chartData.candlesticks.length - 1, idx + range)].time;
            chart.timeScale().setVisibleRange({ from: start, to: end });
        }
        document.getElementById('ohlc-display').innerHTML = "Signal Located";
    }
</script>
</body>
</html>