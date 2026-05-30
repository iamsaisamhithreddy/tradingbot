<!DOCTYPE html>
<html>
<head>
    <title>TradingView Dynamic Visualizer</title>
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; text-align: center; margin: 0; padding: 15px; }
        #chart-container { width: 95%; max-width: 1200px; height: 600px; margin: 15px auto; border: 1px solid #ccc; background: #fff; position: relative; }
        #ohlc-display { position: absolute; top: 10px; left: 10px; background: rgba(255,255,255,0.9); padding: 8px; border: 1px solid #ccc; z-index: 10; font-family: monospace; text-align: left; }
        
        #trade-details-panel { 
            position: absolute; top: 10px; right: 10px; 
            background: rgba(255, 255, 255, 0.95); padding: 15px; 
            border: 2px solid #007bff; border-radius: 8px; 
            z-index: 100; font-family: sans-serif; text-align: left;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 240px;
            display: none;
        }
        .detail-row { margin-bottom: 6px; font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 4px; }
        .detail-label { font-weight: bold; color: #555; width: 90px; display: inline-block; }
        .detail-val { font-weight: bold; color: #000; }
        .buy-label { color: #28a745; }
        .sell-label { color: #dc3545; }
        .nav-link { display: block; margin-bottom: 10px; color: #007bff; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

    <a href="index.php" class="nav-link">« Back to Trade List</a>

    <div id="chart-container">
        <div id="ohlc-display">Scanning data...</div>
        <div id="trade-details-panel">
            <div style="font-weight: bold; border-bottom: 2px solid #007bff; margin-bottom: 10px; padding-bottom: 5px; color: #007bff;">SIGNAL DETAILS (IST)</div>
            <div id="details-content"></div>
        </div>
    </div>

<script>
    let chart, candleSeries, volSeries, chartData = null;
    const container = document.getElementById('chart-container');

    window.onload = function() {
        // 1. Get the parameters from the URL
        const params = new URLSearchParams(window.location.search);
        const autoFile = params.get('file');
        const gotoTs = params.get('goto');
        const targetPrice = params.get('price');

        // 2. Load the data into the chart and panel
        if(params.get('id')) showDetails(params);
        if (autoFile) autoLoadCSV(autoFile, gotoTs, targetPrice);

        // 3. MASK THE URL: Remove parameters from address bar immediately
        // This changes the URL to just 'plot.php' without reloading the page
        if (window.location.search) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    };

    function showDetails(params) {
        const panel = document.getElementById('trade-details-panel');
        const content = document.getElementById('details-content');
        panel.style.display = 'block';
        const dir = params.get('dir');
        content.innerHTML = `
            <div class="detail-row"><span class="detail-label">Trade ID:</span> <span class="detail-val">#${params.get('id')}</span></div>
            <div class="detail-row"><span class="detail-label">Asset:</span> <span class="detail-val">${params.get('pair')}</span></div>
            <div class="detail-row"><span class="detail-label">Signal IST:</span> <span class="detail-val">${params.get('time')}</span></div>
            <div class="detail-row"><span class="detail-label">Target:</span> <span class="detail-val">${params.get('price')}</span></div>
            <div class="detail-row"><span class="detail-label">Direction:</span> <span class="detail-val ${dir === 'BUY' ? 'buy-label' : 'sell-label'}">${dir}</span></div>
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
                setTimeout(() => focusAndJump(parseInt(timestamp), parseFloat(targetPrice)), 800);
            })
            .catch(err => { document.getElementById('ohlc-display').innerHTML = "Error: " + err.message; });
    }

    function initChart(data) {
        chartData = data;
        if(!chart) {
            chart = LightweightCharts.createChart(container, {
                width: container.clientWidth, height: container.clientHeight,
                timeScale: { timeVisible: true },
                crosshair: { mode: LightweightCharts.CrosshairMode.Normal }
            });
            candleSeries = chart.addCandlestickSeries({
                upColor: '#26a69a', downColor: '#ef5350', borderVisible: false,
                priceFormat: { type: 'custom', formatter: (p) => p.toFixed(5) }
            });
            volSeries = chart.addHistogramSeries({ priceScaleId: '', scaleMargins: { top: 0.8, bottom: 0 } });
            chart.subscribeCrosshairMove(param => {
                if (param.time) {
                    const d = param.seriesPrices.get(candleSeries);
                    if (d) document.getElementById('ohlc-display').innerHTML = `O: ${d.open.toFixed(5)} H: ${d.high.toFixed(5)} L: ${d.low.toFixed(5)} C: ${d.close.toFixed(5)}`;
                }
            });
        }
        candleSeries.setData(data.candlesticks);
        volSeries.setData(data.volume);
        candleSeries.setMarkers(data.alerts);
    }

    function focusAndJump(targetTs, targetPrice) {
        if(!chart || !chartData) return;
        
        candleSeries.createPriceLine({
            price: targetPrice, color: '#007bff', lineWidth: 2, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'Target'
        });

        const candles = chartData.candlesticks;
        const idx = candles.findIndex(c => c.time >= targetTs);
        
        if(idx !== -1) {
            const startIdx = Math.max(0, idx - 40);
            const endIdx = Math.min(candles.length - 1, idx + 40);
            
            chart.timeScale().setVisibleRange({ 
                from: candles[startIdx].time, 
                to: candles[endIdx].time 
            });
        }

        document.getElementById('ohlc-display').innerHTML = "View Initialized. Zoom available.";
    }
</script>
</body>
</html>