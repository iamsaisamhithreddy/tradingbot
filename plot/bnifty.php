<!DOCTYPE html>
<html>
<head>
    <title>CSV Chart Plotter</title>
    
    <script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>
    
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; text-align: center; }
        
        #chart-controls, #range-controls, #replay-toolbar {
            margin: 10px auto;
            padding: 10px;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 5px;
            display: none; 
            font-size: 14px;
        }
        #chart-controls button, #range-controls button, #replay-toolbar button {
            margin: 0 4px;
            padding: 5px 8px;
            border: 1px solid #ccc;
            background-color: #f9f9f9;
            border-radius: 4px;
            cursor: pointer;
        }
        #chart-controls button:hover, #range-controls button:hover, #replay-toolbar button:hover {
            background-color: #eee;
        }
        #chart-controls button.active-tool {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        #range-controls input { font-size: 14px; padding: 4px; }
        #filter-btn, #start-replay-btn {
            background-color: #28a745;
            color: white;
            border: none;
        }
        #reset-view-btn {
            background-color: #6c757d;
            color: white;
            border: none;
        }
        
        #replay-toolbar { border-color: #dc3545; }
        #replay-play-pause-btn { background-color: #17a2b8; color: white; }
        #replay-stop-btn { background-color: #dc3545; color: white; }
        
        #ohlc-display {
            position: absolute; top: 15px; left: 15px;
            background: rgba(255, 255, 255, 0.85);
            padding: 8px; border-radius: 4px;
            font-family: monospace; font-size: 14px;
            z-index: 10; display: none; 
            border: 1px solid #eee;
            pointer-events: none; text-align: left;
        }
        
        #chart-container {
            width: 90%; max-width: 1200px; height: 600px;
            margin: 20px auto; border: 1px solid #ccc;
            background-color: #ffffff;
            position: relative; cursor: default;
        }
        #chart-message {
            padding-top: 50px; color: #777; font-size: 16px;
        }
        #loading-overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.8);
            font-size: 24px; padding-top: 250px;
            display: none; 
        }
        form { margin: 20px; }

        /* Alert List Styles */
        #alert-list-container {
            width: 90%; max-width: 1200px; margin: 10px auto;
            text-align: left;
            display: none;
        }
        #alert-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 10px;
            background: #fff;
            border: 1px solid #ddd;
            max-height: 150px;
            overflow-y: auto;
        }
        .alert-btn {
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
        }
        .alert-btn:hover { opacity: 0.8; }
    </style>
</head>
<body>

    <h2>Upload Your TradingView CSV</h2>
    
    <form id="upload-form" method="post" enctype="multipart/form-data">
        <label for="csv_file">Select CSV file:</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
        <input type="submit" value="Plot Chart">
    </form>

    <div id="alert-list-container">
        <strong>Detected Patterns (Click to view levels):</strong>
        <div id="alert-list"></div>
    </div>
    
    <div id="range-controls">
        <b>From:</b> 
        <input type="datetime-local" id="datetime-input-start">
        <b>To:</b> 
        <input type="datetime-local" id="datetime-input-end">
        
        <button type="button" id="filter-btn">Filter</button>
        <button type="button" id="reset-view-btn">Reset View</button>
        <button type="button" id="start-replay-btn" style="background-color: #ffc107; color: #333;">Start Replay</button>
    </div>
    
    <div id="replay-toolbar">
        <b>Replay Mode Active</b>
        <button type="button" id="replay-play-pause-btn">▶️ Play</button>
        <button type="button" id="replay-next-bar-btn">Next Bar &raquo;</button>
        <button type="button" id="replay-stop-btn">Stop Replay</button>
        <label>Speed (ms): <input type="number" id="replay-speed" value="800" style="width: 60px;"></label>
    </div>
    
    <div id="chart-controls">
        <b>Go to Date:</b> 
        <input type="date" id="date-input">
        <button type="button" id="go-to-date-btn">Go</button>
        
        <span style="border-left: 1px solid #ccc; margin: 0 8px;"></span>
        
        <button type="button" id="draw-hline-btn">Draw H-Line</button>
        <button type="button" id="remove-last-line-btn">Remove Last</button>
        <button type="button" id="remove-all-lines-btn">Remove All</button>
    </div>

    <div id="chart-container">
        <div id="ohlc-display">Time: -<br>O: - H: - L: - C: - | Move: -</div>
        <div id="chart-message">Please upload a CSV file to see the chart.</div>
        <div id="loading-overlay">Loading data...</div>
    </div>

<script>
    const chartContainer = document.getElementById('chart-container');
    const uploadForm = document.getElementById('upload-form');
    const csvFileInput = document.getElementById('csv_file');
    const chartMessage = document.getElementById('chart-message');
    const loadingOverlay = document.getElementById('loading-overlay');
    const ohlcDisplay = document.getElementById('ohlc-display');
    const goToDateBtn = document.getElementById('go-to-date-btn');
    const dateInput = document.getElementById('date-input');
    const chartControls = document.getElementById('chart-controls'); 
    const drawHLineBtn = document.getElementById('draw-hline-btn');
    const removeLastLineBtn = document.getElementById('remove-last-line-btn');
    const removeAllLinesBtn = document.getElementById('remove-all-lines-btn');
    const rangeControls = document.getElementById('range-controls');
    const dtInputStart = document.getElementById('datetime-input-start');
    const dtInputEnd = document.getElementById('datetime-input-end');
    const filterBtn = document.getElementById('filter-btn');
    const resetViewBtn = document.getElementById('reset-view-btn');
    const replayToolbar = document.getElementById('replay-toolbar');
    const startReplayBtn = document.getElementById('start-replay-btn');
    const replayPlayPauseBtn = document.getElementById('replay-play-pause-btn');
    const replayNextBarBtn = document.getElementById('replay-next-bar-btn');
    const replayStopBtn = document.getElementById('replay-stop-btn');
    const replaySpeedInput = document.getElementById('replay-speed');
    
    const alertListContainer = document.getElementById('alert-list-container');
    const alertList = document.getElementById('alert-list');

    let chart = null;
    let candlestickSeries = null;
    let volumeSeries = null;

    let isDrawingLine = false;
    let drawnLines = [];
    let isDraggingLine = false;
    let selectedLine = null;
    const dragTolerance = 10;

    let fullChartData = null;
    let activePandLLines = []; // Tracks dynamic Entry/Target/Stop lines

    // Replay state
    let isReplaying = false;
    let replayMasterData = { candlesticks: [], volume: [], alerts: [] };
    let replayCurrentIndex = 0;
    let replayTimer = null;
    let replayInitialAlerts = [];     
    let replayAccumulatedAlerts = []; 

    function toDateTimeLocalString(timestamp) {
        const date = new Date(timestamp * 1000);
        const tzoffset = (new Date()).getTimezoneOffset() * 60000;
        const localDate = new Date(date - tzoffset);
        return new Date(localDate).toISOString().slice(0, 16);
    }
    
    function formatTimeForLabel(timestamp) {
        const date = new Date(timestamp * 1000);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }

    uploadForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!csvFileInput.files.length) return;

        chartMessage.style.display = 'none';
        loadingOverlay.style.display = 'block';

        const formData = new FormData();
        formData.append('csv_file', csvFileInput.files[0]);

        fetch('process_csv.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                loadingOverlay.style.display = 'none';
                if (data.error) throw new Error(data.error);

                fullChartData = data;

                if (data.startDate && data.endDate) {
                    const startDate = new Date(data.startDate * 1000).toISOString().split('T')[0];
                    const endDate = new Date(data.endDate * 1000).toISOString().split('T')[0];
                    dateInput.min = startDate;
                    dateInput.max = endDate;
                    dateInput.value = startDate;

                    dtInputStart.value = toDateTimeLocalString(data.startDate);
                    dtInputEnd.value = toDateTimeLocalString(data.endDate);

                    chartControls.style.display = 'inline-block';
                    rangeControls.style.display = 'inline-block';
                    alertListContainer.style.display = 'block';
                }

                plotChart(fullChartData);
                generateAlertButtons(fullChartData);
            })
            .catch(err => {
                loadingOverlay.style.display = 'none';
                chartMessage.style.display = 'block';
                chartMessage.style.color = 'red';
                chartMessage.innerHTML = `<b>Error:</b> ${err.message}`;
                console.error(err);
            });
    });
    
    function generateAlertButtons(data) {
        alertList.innerHTML = ''; 
        
        const patternAlerts = data.alerts.filter(alert => alert.shape === 'arrowDown' || alert.shape === 'arrowUp');
        
        if (patternAlerts.length === 0) {
            alertList.innerHTML = '<span style="color:#777; padding:5px;">No patterns detected.</span>';
            return;
        }

        patternAlerts.forEach(alert => {
            const btn = document.createElement('button');
            btn.className = 'alert-btn';
            
            if(alert.shape === 'arrowDown') {
                btn.style.backgroundColor = '#ff5252';
                btn.innerHTML = `⬇ ${formatTimeForLabel(alert.time)}: ${alert.text}`;
            } else {
                btn.style.backgroundColor = '#009688';
                btn.innerHTML = `⬆ ${formatTimeForLabel(alert.time)}: ${alert.text}`;
            }
            
            btn.onclick = function() {
                jumpToTimestamp(alert.time, alert.shape);
            };
            
            alertList.appendChild(btn);
        });
    }

    function jumpToTimestamp(targetTime, alertShape = 'arrowDown') {
        if (!chart || !fullChartData) return;
        
        const candles = fullChartData.candlesticks;
        let idx = candles.findIndex(c => c.time === targetTime);
        
        if (idx === -1) {
            let minDiff = Infinity;
            candles.forEach((c, i) => {
                const diff = Math.abs(c.time - targetTime);
                if(diff < minDiff) { minDiff = diff; idx = i; }
            });
        }

        // 1. Clear previous P&L lines
        activePandLLines.forEach(line => candlestickSeries.removePriceLine(line));
        activePandLLines = [];

        // 2. Find the Daily Open
        let dailyOpen = candles[idx].open; 
        const targetDate = new Date(candles[idx].time * 1000).toDateString();
        for (let i = idx; i >= 0; i--) {
            const cDate = new Date(candles[i].time * 1000).toDateString();
            if (cDate === targetDate) {
                dailyOpen = candles[i].open; 
            } else {
                break;
            }
        }

        // 3. Apply Math Logic
        const reversalCandle = candles[idx];
        const candleRange = Math.abs(reversalCandle.high - reversalCandle.low);
        const points = (Math.sqrt(dailyOpen) / 10) * candleRange;

        let targetPrice, stopPrice, entryPrice;

        // 4. Calculate Levels
        if (alertShape === 'arrowDown') {
            entryPrice = reversalCandle.close; 
            stopPrice = reversalCandle.high; 
            targetPrice = reversalCandle.high - points; 
        } else {
            entryPrice = reversalCandle.close;
            stopPrice = reversalCandle.low; 
            targetPrice = reversalCandle.low + points; 
        }

        // 5. Draw Levels
        const entryLine = candlestickSeries.createPriceLine({
            price: parseFloat(entryPrice.toFixed(5)),
            color: '#1a73e8', lineWidth: 2, lineStyle: LightweightCharts.LineStyle.Solid,
            axisLabelVisible: true, title: 'Entry'
        });
        
        const targetLine = candlestickSeries.createPriceLine({
            price: parseFloat(targetPrice.toFixed(5)),
            color: '#0f9d58', lineWidth: 2, lineStyle: LightweightCharts.LineStyle.Dashed,
            axisLabelVisible: true, title: 'Target'
        });

        const stopLine = candlestickSeries.createPriceLine({
            price: parseFloat(stopPrice.toFixed(5)),
            color: '#d93025', lineWidth: 2, lineStyle: LightweightCharts.LineStyle.Dotted,
            axisLabelVisible: true, title: 'Stop'
        });

        activePandLLines.push(entryLine, targetLine, stopLine);

        // 6. Zoom
        const visibleRange = 60; 
        const fromIndex = Math.max(0, idx - Math.floor(visibleRange / 3)); 
        const toIndex = Math.min(candles.length - 1, idx + Math.floor((visibleRange * 2) / 3)); 
        
        chart.timeScale().setVisibleRange({
            from: candles[fromIndex].time,
            to: candles[toIndex].time
        });
    }

    // Filter
    filterBtn.addEventListener('click', function () {
        if (!fullChartData) return alert("No data loaded");
        const s = new Date(dtInputStart.value).getTime() / 1000;
        const e = new Date(dtInputEnd.value).getTime() / 1000;
        if (isNaN(s) || isNaN(e)) return alert("Invalid date/time format.");

        const filtered = {
            candlesticks: fullChartData.candlesticks.filter(d => d.time >= s && d.time <= e),
            volume: fullChartData.volume.filter(d => d.time >= s && d.time <= e),
            alerts: fullChartData.alerts.filter(d => d.time >= s && d.time <= e)
        };
        updateChartData(filtered);
        generateAlertButtons(filtered);
    });

    // Reset view
    resetViewBtn.addEventListener('click', () => {
        if (!fullChartData) return;
        dtInputStart.value = toDateTimeLocalString(fullChartData.startDate);
        dtInputEnd.value = toDateTimeLocalString(fullChartData.endDate);
        updateChartData(fullChartData);
        generateAlertButtons(fullChartData);
    });

    function updateChartData(data) {
        if (!chart || !candlestickSeries || !volumeSeries) return;
        
        // Clear P&L lines on generic updates
        activePandLLines.forEach(line => candlestickSeries.removePriceLine(line));
        activePandLLines = [];

        candlestickSeries.setData(data.candlesticks);
        volumeSeries.setData(data.volume);
        candlestickSeries.setMarkers(data.alerts || []);
        chart.timeScale().fitContent();
    }

    goToDateBtn.addEventListener('click', function () {
        if (!fullChartData?.candlesticks?.length) return;
        const dateString = dateInput.value;
        if (!dateString) return;
        const ts = new Date(dateString).getTime() / 1000;
        
        // Find closest date index to jump to without full P&L logic
        const candles = fullChartData.candlesticks;
        let idx = candles.findIndex(c => c.time >= ts);
        if (idx !== -1) {
            const visibleRange = 60;
            const fromIndex = Math.max(0, idx - Math.floor(visibleRange / 2));
            const toIndex = Math.min(candles.length - 1, idx + Math.floor(visibleRange / 2));
            chart.timeScale().setVisibleRange({
                from: candles[fromIndex].time,
                to: candles[toIndex].time
            });
        }
    });

    // ============= REPLAY START =============
    startReplayBtn.addEventListener('click', function () {
        if (!fullChartData?.candlesticks?.length) return alert("No data loaded.");

        const startTimestamp = new Date(dtInputStart.value).getTime() / 1000;
        if (isNaN(startTimestamp)) return alert("Invalid 'From' date/time.");

        const idx = fullChartData.candlesticks.findIndex(d => d.time >= startTimestamp);
        if (idx === -1) return alert("No data found after the specified 'From' date.");

        const initialData = {
            candlesticks: fullChartData.candlesticks.slice(0, idx),
            volume: fullChartData.volume.slice(0, idx),
            alerts: fullChartData.alerts.filter(d => d.time < startTimestamp)
        };

        replayMasterData = {
            candlesticks: fullChartData.candlesticks.slice(idx),
            volume: fullChartData.volume.slice(idx),
            alerts: fullChartData.alerts.filter(d => d.time >= startTimestamp)
        };

        replayCurrentIndex = 0;
        isReplaying = true;
        replayInitialAlerts = initialData.alerts.slice();
        replayAccumulatedAlerts = [];

        updateChartData(initialData);
        chart.timeScale().scrollToPosition(0, true);

        alertListContainer.style.display = 'none';
        rangeControls.style.display = 'none';
        chartControls.style.display = 'none';
        replayToolbar.style.display = 'inline-block';
        replayPlayPauseBtn.innerHTML = '▶️ Play';
    });

    function applyReplayStep() {
        if (!isReplaying) return;
        if (replayCurrentIndex >= replayMasterData.candlesticks.length) {
            stopReplay();
            return;
        }
        const nextCandle = replayMasterData.candlesticks[replayCurrentIndex];
        const nextVolume = replayMasterData.volume[replayCurrentIndex];

        candlestickSeries.update(nextCandle);
        volumeSeries.update(nextVolume);

        const alertsNow = replayMasterData.alerts.filter(a => a.time === nextCandle.time);
        if (alertsNow.length) {
            replayAccumulatedAlerts.push(...alertsNow);
            candlestickSeries.setMarkers([...replayInitialAlerts, ...replayAccumulatedAlerts]);
        }

        chart.timeScale().scrollToPosition(0, true);
        replayCurrentIndex++;
    }

    replayNextBarBtn.addEventListener('click', applyReplayStep);

    replayPlayPauseBtn.addEventListener('click', function () {
        if (!isReplaying) return;
        if (replayTimer) {
            clearInterval(replayTimer);
            replayTimer = null;
            replayPlayPauseBtn.innerHTML = '▶️ Play';
        } else {
            replayPlayPauseBtn.innerHTML = '⏸️ Pause';
            const speed = Math.max(50, parseInt(replaySpeedInput.value) || 800);
            replayTimer = setInterval(applyReplayStep, speed);
        }
    });

    function stopReplay() {
        if (replayTimer) {
            clearInterval(replayTimer);
            replayTimer = null;
        }
        isReplaying = false;
        if (fullChartData) {
            updateChartData(fullChartData);
        }
        rangeControls.style.display = 'inline-block';
        chartControls.style.display = 'inline-block';
        alertListContainer.style.display = 'block';
        replayToolbar.style.display = 'none';
        replayPlayPauseBtn.innerHTML = '▶️ Play';
    }
    replayStopBtn.addEventListener('click', stopReplay);
    // ============= REPLAY END =============

    // Line tools
    drawHLineBtn.addEventListener('click', () => {
        isDrawingLine = !isDrawingLine;
        drawHLineBtn.innerHTML = isDrawingLine ? 'Drawing... (Click Chart)' : 'Draw H-Line';
        drawHLineBtn.classList.toggle('active-tool');
    });

    removeLastLineBtn.addEventListener('click', () => {
        if (!drawnLines.length) return;
        candlestickSeries.removePriceLine(drawnLines.pop());
    });

    removeAllLinesBtn.addEventListener('click', () => {
        while (drawnLines.length) candlestickSeries.removePriceLine(drawnLines.pop());
        // Also clear our dynamic P&L lines
        activePandLLines.forEach(line => candlestickSeries.removePriceLine(line));
        activePandLLines = [];
    });

    function getMouseY(event) {
        const rect = chartContainer.getBoundingClientRect();
        return event.clientY - rect.top;
    }

    chartContainer.addEventListener('mousedown', function (event) {
        if (chart === null || isDrawingLine) return;
        const mouseY = getMouseY(event);
        for (const line of drawnLines) {
            const linePrice = line.options().price;
            const lineY = candlestickSeries.priceToCoordinate(linePrice);
            if (Math.abs(mouseY - lineY) < dragTolerance) {
                isDraggingLine = true;
                selectedLine = line;
                chartContainer.style.cursor = 'ns-resize';
                selectedLine.options({ color: 'red', lineWidth: 3 });
                return;
            }
        }
    });

    window.addEventListener('mousemove', function (event) {
        if (!isDraggingLine || !selectedLine) return;
        const mouseY = getMouseY(event);
        const newPrice = candlestickSeries.coordinateToPrice(mouseY);
        if (newPrice !== null) {
            selectedLine.options({ price: parseFloat(newPrice.toFixed(5)) });
        }
    });

    window.addEventListener('mouseup', function () {
        if (!isDraggingLine || !selectedLine) return;
        isDraggingLine = false;
        chartContainer.style.cursor = 'default';
        selectedLine.options({ color: '#007bff', lineWidth: 2 });
        selectedLine = null;
    });

    // Chart + precision
    function plotChart(data) {
        try {
            if (!chart) {
                chart = LightweightCharts.createChart(chartContainer, {
                    width: chartContainer.clientWidth,
                    height: chartContainer.clientHeight,
                    layout: { backgroundColor: '#ffffff', textColor: '#333' },
                    grid: { vertLines: { color: '#e6e6e6' }, horzLines: { color: '#e6e6e6' } },
                    crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                    timeScale: { borderColor: '#ccc', timeVisible: true }
                });

                candlestickSeries = chart.addCandlestickSeries({
                    upColor: '#009688', downColor: '#FF5252',
                    borderUpColor: '#009688', borderDownColor: '#FF5252',
                    wickUpColor: '#009688', wickDownColor: '#FF5252',
                    priceFormat: { type: 'custom', formatter: (p) => p.toFixed(5) }
                });

                volumeSeries = chart.addHistogramSeries({
                    color: '#26a69a',
                    priceFormat: { type: 'volume' },
                    priceScaleId: 'volume_scale'
                });
                chart.priceScale('volume_scale').applyOptions({
                    scaleMargins: { top: 0.8, bottom: 0 }
                });

                ohlcDisplay.style.display = 'block';
                const istOpts = {
                    day: '2-digit', month: '2-digit', year: 'numeric',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    hour12: false, timeZone: 'Asia/Kolkata'
                };

                chart.subscribeCrosshairMove(param => {
                    if (param.time && param.seriesPrices.size > 0) {
                        const d = param.seriesPrices.get(candlestickSeries);
                        if (d) {
                            const date = new Date(param.time * 1000);
                            const t = date.toLocaleString('en-IN', istOpts);
                            ohlcDisplay.innerHTML = `<b>${t} (IST)</b><br>
                                O: ${d.open.toFixed(5)} H: ${d.high.toFixed(5)} 
                                L: ${d.low.toFixed(5)} C: ${d.close.toFixed(5)} 
                                | Move: ${Math.abs(d.open - d.close).toFixed(5)}`;
                        }
                    } else {
                        ohlcDisplay.innerHTML = 'Time: -<br>O: - H: - L: - C: - | Move: -';
                    }
                });

                chart.subscribeClick(param => {
                    if (isDrawingLine && param.point) {
                        const price = candlestickSeries.coordinateToPrice(param.point.y);
                        const line = candlestickSeries.createPriceLine({
                            price: parseFloat(price.toFixed(5)),
                            color: '#007bff', lineWidth: 2, axisLabelVisible: true,
                            title: price.toFixed(5)
                        });
                        drawnLines.push(line);
                        isDrawingLine = false;
                        drawHLineBtn.classList.remove('active-tool');
                        drawHLineBtn.innerHTML = 'Draw H-Line';
                    }
                });
            }

            updateChartData(data);

            if (data.candlesticks?.length) {
                const last = data.candlesticks[data.candlesticks.length - 1];
                candlestickSeries.createPriceLine({
                    price: parseFloat(last.close.toFixed(5)),
                    color: '#ffa500', lineWidth: 2, lineStyle: LightweightCharts.LineStyle.Dotted,
                    axisLabelVisible: true, title: `Current: ${last.close.toFixed(5)}`
                });
            }

        } catch (err) {
            chartMessage.style.display = 'block';
            chartMessage.style.color = 'red';
            chartMessage.innerHTML = `<b>JavaScript Plotting Error:</b> ${err.message}`;
            console.error(err);
        }
    }
</script>

</body>
</html>