<?php
/**
 * Trade Plotter UI shell.
 * Expects in scope: $conn, $utcTimezone, $istTimezone (needed by trade_list_partial.php).
 * All assets (css/js/partial) live in this same folder.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Trade Plotter & Chart Viewer</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="trade-plotter.css?v=<?php echo @filemtime(__DIR__ . '/trade-plotter.css') ?: time(); ?>">
    <style>
    .filter-pill {
        flex: 1; min-width: 60px;
        padding: 5px 8px; border-radius: 20px; border: 1px solid var(--border-color, #334155);
        background: transparent; color: var(--text-secondary, #94a3b8);
        font-size: 11px; font-weight: 600; cursor: pointer; text-align: center;
        transition: all .15s; white-space: nowrap;
    }
    .filter-pill:hover { border-color: #60a5fa; color: #e2e8f0; }
    .filter-pill.active { background: #1e3a5f; border-color: #3b82f6; color: #93c5fd; }
    .filter-pill.win.active  { background: rgba(16,185,129,.15); border-color: #10b981; color: #34d399; }
    .filter-pill.loss.active { background: rgba(239,68,68,.15);  border-color: #ef4444; color: #f87171; }
    .filter-pill.snf.active  { background: rgba(245,158,11,.15); border-color: #f59e0b; color: #fbbf24; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1>ðŸ“ˆ Advanced Trade Plotter</h1>
        <a href="../admin_dashboard.php" class="back-link">Â« Back to Dashboard</a>
    </header>
    <div class="workspace">
        <div class="sidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                <h2>Select Trade</h2>
                <div style="font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" checked style="cursor: pointer;"> Select All (<span id="selected-count">0</span>)
                </div>
            </div>
            <div style="margin-bottom: 8px;">
                <input type="text" id="search" class="search-box" style="width: 100%;" placeholder="&#128269; Search pair (e.g. EURUSD)..." oninput="filterTrades()">
            </div>
            <div id="outcome-filter-btns" style="display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap;">
                <button class="filter-pill active" data-outcome="all"    onclick="setOutcomeFilter(this)">All</button>
                <button class="filter-pill win"    data-outcome="win"    onclick="setOutcomeFilter(this)">&#9650; Wins</button>
                <button class="filter-pill loss"   data-outcome="loss"   onclick="setOutcomeFilter(this)">&#9660; Losses</button>
                <button class="filter-pill snf"    data-outcome="setup_not_formed" onclick="setOutcomeFilter(this)">&#8212; Not Formed</button>
            </div>
            <div style="display: flex; gap: 6px; margin-bottom: 15px;">
                <input type="number" id="search-id" class="search-box" style="margin-bottom: 0; width: 90px;" placeholder="Trade ID..." onkeydown="if(event.key === 'Enter') loadTradeByIdInput()">
                <button class="chart-btn" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px; flex: 1;" onclick="loadTradeByIdInput()">Load ID</button>
                <button class="chart-btn" id="btn-generate-pdf" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px;" onclick="generateCheckedPdf()">ðŸ“„ PDF</button>
            </div>
            <div class="trade-list" id="trade-list">
                <?php require __DIR__ . '/trade_list_partial.php'; ?>
            </div>
        </div>
        <div class="chart-panel">
            <div class="chart-header">
                <div class="active-trade-info" id="active-info">
                    <span class="active-trade-pair" id="active-pair">Select a Trade</span>
                    <span class="active-trade-meta" id="active-meta">Select a trade from the sidebar to view the interactive chart.</span>
                </div>
                <div class="chart-actions" id="chart-actions" style="display: none;">
                    <button class="chart-btn" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-green);" onclick="exportCurrentScreenshot()">ðŸ“· Export Image</button>
                </div>
            </div>
            <div id="chart-container">
                <div id="chart-placeholder">Select a trade signal from the sidebar to load the chart...</div>
                <div id="loading-overlay">Loading Chart Data...</div>
            </div>
        </div>
    </div>
</div>
<script src="trade-plotter.js?v=<?php echo @filemtime(__DIR__ . '/trade-plotter.js') ?: time(); ?>"></script>
</body>
</html>
