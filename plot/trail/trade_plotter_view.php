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
    <title>Advanced Trade Plotter &amp; Chart Viewer</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="trade-plotter.css?v=<?php echo @filemtime(__DIR__ . '/trade-plotter.css') ?: time(); ?>">
</head>
<body>
<div class="container">
    <header>
        <h1>&#128200; Advanced Trade Plotter</h1>
        <a href="../admin_dashboard.php" class="back-link">&laquo; Back to Dashboard</a>
    </header>
    <div class="workspace">
        <div class="sidebar">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                <h2>Select Trade</h2>
                <div style="font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll(this)" checked style="cursor: pointer;"> Select All (<span id="selected-count">0</span>)
                </div>
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <input type="text" id="search" class="search-box" style="margin-bottom: 0; flex: 1;" placeholder="Search pair (e.g. EURUSD)..." oninput="filterTrades()">
                <select id="outcome-filter" class="search-box" style="margin-bottom: 0; width: 120px; cursor: pointer;" onchange="filterTrades()">
                    <option value="all">All Trades</option>
                    <option value="win">Wins Only</option>
                    <option value="loss">Losses Only</option>
                </select>
            </div>
            <select id="pair-filter" class="search-box" style="margin-bottom: 10px; width: 100%; cursor: pointer;" onchange="filterTrades()">
                <option value="all">All Pairs</option>
                <!-- populated by populatePairFilter() on DOMContentLoaded -->
            </select>
            <div style="display: flex; gap: 6px; margin-bottom: 10px;">
                <input type="number" id="search-id" class="search-box" style="margin-bottom: 0; width: 90px;" placeholder="Trade ID..." onkeydown="if(event.key === 'Enter') loadTradeByIdInput()">
                <button class="chart-btn" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px; flex: 1;" onclick="loadTradeByIdInput()">Load ID</button>
                <button class="chart-btn" id="btn-generate-pdf" style="background: var(--gradient-blue); border-color: var(--accent-blue); padding: 8px 10px;" onclick="generateCheckedPdf()">&#128196; PDF</button>
                <button class="chart-btn" id="btn-candle-table" style="background: rgba(251,191,36,0.1); border-color: #f0a93b; padding: 8px 10px; color: #f0a93b;" onclick="showCandleCountTable()" title="Candle count table — signal to resolution for all visible trades">🕯️</button>
            </div>

            <!-- Streak Filter Row -->
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 15px; padding: 9px 12px; background: rgba(59,130,246,0.06); border: 1px solid rgba(59,130,246,0.18); border-radius: 8px;">
                <input type="checkbox" id="streak-filter-enable" onchange="applyStreakFilter()"
                       style="accent-color: var(--accent-blue); width: 16px; height: 16px; cursor: pointer; flex-shrink: 0;">
                <label for="streak-filter-enable" style="font-size: 13px; color: var(--text-secondary); cursor: pointer; white-space: nowrap; user-select: none;">
                    Streak &ge;
                </label>
                <input type="number" id="streak-min" class="search-box" min="1" max="50" value="3"
                       style="margin-bottom: 0; width: 58px; padding: 6px 8px; font-size: 13px;"
                       onchange="applyStreakFilter()" onkeydown="if(event.key==='Enter') applyStreakFilter()">
                <span style="font-size: 11px; color: var(--text-secondary); flex: 1; text-align: right; white-space: nowrap;" id="streak-status"></span>
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
                    <button class="chart-btn" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-green);" onclick="exportCurrentScreenshot()">&#128247; Export Image</button>
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