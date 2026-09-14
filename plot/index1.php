<!DOCTYPE html>
<html>
<head>
<title>CSV Chart Plotter</title>

<script src="https://unpkg.com/lightweight-charts@3.8.0/dist/lightweight-charts.standalone.production.js"></script>

<style>
body {
    font-family: sans-serif;
    background-color: #f4f4f4;
    text-align: center;
}

#chart-controls {
    margin: 10px auto;
    padding: 10px;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 5px;
    display: none;
}

#chart-controls button {
    margin: 0 4px;
    padding: 5px 8px;
    cursor: pointer;
}

#chart-controls button.active-tool {
    background: #007bff;
    color: #fff;
}

#chart-container {
    width: 90%;
    max-width: 1200px;
    height: 600px;
    margin: 20px auto;
    background: #fff;
    border: 1px solid #ccc;
    position: relative;
}

/* ===== DATE RANGE OVERLAY ===== */
#range-overlay {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.range-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: red;
}

.range-box {
    position: absolute;
    top: 0;
    bottom: 0;
    background: rgba(33,150,243,0.2);
}
/* ============================= */

#ohlc-display {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(255,255,255,0.9);
    padding: 6px;
    font-family: monospace;
    font-size: 13px;
    display: none;
    pointer-events: none;
}
</style>
</head>

<body>

<h2>Upload Your TradingView CSV</h2>

<form id="upload-form" enctype="multipart/form-data">
    <input type="file" id="csv_file" accept=".csv" required>
    <input type="submit" value="Plot Chart">
</form>

<div id="chart-controls">
    <button id="date-range-btn">Date Range</button>
</div>

<div id="chart-container">
    <div id="ohlc-display"></div>
    <div id="range-overlay"></div>
</div>

<script>
let chart, candleSeries, volumeSeries;
let fullChartData = null;

/* ===== DATE RANGE STATE ===== */
const rangeOverlay = document.getElementById('range-overlay');
const dateRangeBtn = document.getElementById('date-range-btn');

let isDateRangeMode = false;
let rangeStartX = null;
let rangeStartTime = null;
let rangeEndTime = null;

let startLineEl = null;
let endLineEl = null;
let rangeBoxEl = null;
/* =========================== */

document.getElementById('upload-form').addEventListener('submit', e => {
    e.preventDefault();
    const f = document.getElementById('csv_file').files[0];
    if (!f) return;

    const fd = new FormData();
    fd.append('csv_file', f);

    fetch('process_csv.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            fullChartData = d;
            initChart(d);
            document.getElementById('chart-controls').style.display = 'inline-block';
        });
});

function initChart(data) {
    if (!chart) {
        chart = LightweightCharts.createChart(
            document.getElementById('chart-container'),
            {
                timeScale: { timeVisible: true },
                crosshair: { mode: LightweightCharts.CrosshairMode.Normal }
            }
        );

        candleSeries = chart.addCandlestickSeries();
        volumeSeries = chart.addHistogramSeries({ priceScaleId: '' });

        document.getElementById('ohlc-display').style.display = 'block';

        chart.subscribeCrosshairMove(param => {
            if (!param.time || !param.seriesPrices.get(candleSeries)) return;
            const d = param.seriesPrices.get(candleSeries);
            document.getElementById('ohlc-display').innerHTML =
                `Time: ${new Date(param.time * 1000).toLocaleString()}<br>
                 O:${d.open} H:${d.high} L:${d.low} C:${d.close}`;
        });
    }

    candleSeries.setData(data.candlesticks);
    volumeSeries.setData(data.volume);
    candleSeries.setMarkers(data.alerts || []);
}

/* ===== DATE RANGE TOOL ===== */

dateRangeBtn.addEventListener('click', () => {
    isDateRangeMode = !isDateRangeMode;
    clearRange();
    rangeStartX = null;
    rangeStartTime = null;
    rangeEndTime = null;
    dateRangeBtn.classList.toggle('active-tool');
});

document.getElementById('chart-container').addEventListener('click', e => {
    if (!isDateRangeMode || !chart) return;

    const rect = chart.timeScale().getVisibleRange();
    const box = e.currentTarget.getBoundingClientRect();
    const x = e.clientX - box.left;

    const time = chart.timeScale().coordinateToTime(x);
    if (!time) return;

    if (!rangeStartTime) {
        rangeStartTime = time;
        rangeStartX = x;
        startLineEl = drawVLine(x, '#4caf50');
    } else {
        rangeEndTime = time;
        endLineEl = drawVLine(x, '#f44336');
        drawRangeBox();
        logRangeInfo();
        isDateRangeMode = false;
        dateRangeBtn.classList.remove('active-tool');
    }
});

function drawVLine(x, color) {
    const el = document.createElement('div');
    el.className = 'range-line';
    el.style.left = `${x}px`;
    el.style.background = color;
    rangeOverlay.appendChild(el);
    return el;
}

function drawRangeBox() {
    const x1 = rangeStartX;
    const x2 = parseInt(endLineEl.style.left);

    const left = Math.min(x1, x2);
    const width = Math.abs(x1 - x2);

    rangeBoxEl = document.createElement('div');
    rangeBoxEl.className = 'range-box';
    rangeBoxEl.style.left = `${left}px`;
    rangeBoxEl.style.width = `${width}px`;
    rangeOverlay.appendChild(rangeBoxEl);
}

function clearRange() {
    rangeOverlay.innerHTML = '';
}

function logRangeInfo() {
    const from = Math.min(rangeStartTime, rangeEndTime);
    const to = Math.max(rangeStartTime, rangeEndTime);

    const bars = fullChartData.candlesticks.filter(
        c => c.time >= from && c.time <= to
    ).length;

    console.log(
        'FROM:', new Date(from * 1000).toLocaleString(),
        'TO:', new Date(to * 1000).toLocaleString(),
        'BARS:', bars
    );
}
/* ============================ */
</script>

</body>
</html>
