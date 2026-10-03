<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==========================================
// DYNAMIC DATABASE CONFIGURATION LOADER
// ==========================================
$dbPath = '';
if (file_exists(__DIR__ . '/db.php')) {
    $dbPath = __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/../db.php')) {
    $dbPath = __DIR__ . '/../db.php';
} else {
    $dir = __DIR__;
    for ($i = 0; $i < 3; $i++) {
        $dir = dirname($dir);
        if (file_exists($dir . '/db.php')) {
            $dbPath = $dir . '/db.php';
            break;
        }
    }
}

if ($dbPath) {
    require_once $dbPath;
} else {
    die("Error: db.php not found on server.");
}

if (!isset($conn) || (isset($conn) && $conn->connect_error)) {
    die("Database connection failed: " . ($conn->connect_error ?? 'Connection object not set'));
}

// Get Filters
$selectedDate = isset($_GET['filter_date']) ? preg_replace('/[^0-9\-]/', '', $_GET['filter_date']) : date('Y-m-d');
$selectedImpact = isset($_GET['filter_impact']) ? $_GET['filter_impact'] : 'all';

// Build dynamic query for Economic Events
$whereClauses = [];
$params = [];
$types = "";

if (!empty($selectedDate)) {
    $whereClauses[] = "event_date = ?";
    $params[] = $selectedDate;
    $types .= "s";
}

if ($selectedImpact !== 'all') {
    $whereClauses[] = "impact = ?";
    $params[] = (int)$selectedImpact;
    $types .= "i";
}

$whereSql = "";
if (count($whereClauses) > 0) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

$eventQuery = "SELECT id, event_name, impact, event_time, event_date 
               FROM economic_events 
               $whereSql 
               ORDER BY event_time ASC";

$rawEvents = [];
if ($stmt = $conn->prepare($eventQuery)) {
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($eId, $eName, $eImpact, $eTimeStr, $eDateStr);
    
    while ($stmt->fetch()) {
        $rawEvents[] = [
            'id' => $eId,
            'event_name' => $eName,
            'impact' => $eImpact,
            'event_time' => $eTimeStr,
            'event_date' => $eDateStr
        ];
    }
    $stmt->close();
}

// ==========================================
// GROUPING LOGIC: Merge simultaneous events
// ==========================================
$groupedEvents = [];
foreach ($rawEvents as $ev) {
    $timeKey = $ev['event_time'];
    if (!isset($groupedEvents[$timeKey])) {
        $groupedEvents[$timeKey] = [];
    }
    $groupedEvents[$timeKey][] = $ev;
}

$finalEvents = [];
foreach ($groupedEvents as $timeKey => $group) {
    // Find the event with the highest impact in this time slot
    $highestImpactEvent = $group[0];
    foreach ($group as $ev) {
        if ($ev['impact'] > $highestImpactEvent['impact']) {
            $highestImpactEvent = $ev;
        }
    }
    
    // Append a notice if there are other events merged into this one
    $othersCount = count($group) - 1;
    if ($othersCount > 0) {
        $highestImpactEvent['event_name'] .= " <span style='color: var(--text-dim); font-size: 13px; font-weight: 600; margin-left: 8px; padding: 2px 8px; background: rgba(255,255,255,0.05); border-radius: 4px;'>+ {$othersCount} other event" . ($othersCount > 1 ? 's' : '') . "</span>";
    }
    
    $finalEvents[] = $highestImpactEvent;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Impact & Trade Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-base: #050810;
            --bg-primary: #0b0f19;
            --bg-secondary: #131b2e;
            --bg-tertiary: #1e293b;
            
            --accent-blue: #3b82f6;
            --accent-blue-glow: rgba(59, 130, 246, 0.4);
            --accent-green: #10b981;
            --accent-green-glow: rgba(16, 185, 129, 0.4);
            --accent-red: #ef4444;
            --accent-red-glow: rgba(239, 68, 68, 0.4);
            --accent-yellow: #fbbf24;
            --accent-yellow-glow: rgba(251, 191, 36, 0.4);
            
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            
            --border-light: rgba(255, 255, 255, 0.08);
            --border-focus: rgba(59, 130, 246, 0.5);
            
            --gradient-primary: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
            --glass-bg: rgba(19, 27, 46, 0.65);
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(59, 130, 246, 0.04) 0%, transparent 50%),
                radial-gradient(circle at 85% 30%, rgba(16, 185, 129, 0.03) 0%, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            margin: 0;
            padding: 40px 20px;
            line-height: 1.5;
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-base); }
        ::-webkit-scrollbar-thumb { background: var(--bg-tertiary); border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent-blue); }

        .container { max-width: 1050px; margin: 0 auto; }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 1px solid var(--border-light);
        }

        h1 {
            font-size: 32px;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin: 0;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        h1::before {
            content: "📰";
            font-size: 28px;
            -webkit-text-fill-color: initial; 
        }

        .back-link {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            padding: 8px 16px;
            border-radius: 8px;
            background: var(--border-light);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .back-link:hover {
            background: var(--bg-tertiary);
            color: var(--text-main);
            transform: translateY(-2px);
        }

        .filter-bar {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 24px 30px;
            margin-bottom: 40px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-label {
            font-weight: 600;
            color: var(--text-muted);
            font-size: 13px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        input[type="date"], select {
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-light);
            border-radius: 10px;
            color: var(--text-main);
            padding: 12px 18px;
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 500;
            outline: none;
            transition: all 0.3s ease;
            cursor: pointer;
            min-width: 200px;
        }
        
        input[type="date"]:focus, select:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1) opacity(0.7);
            cursor: pointer;
            transition: 0.3s;
        }
        
        select option {
            background-color: var(--bg-secondary);
            color: var(--text-main);
        }

        .btn-submit {
            background: var(--gradient-primary);
            border: none;
            color: white;
            padding: 13px 28px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            letter-spacing: 0.5px;
        }

        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(59, 130, 246, 0.5); }
        .btn-submit:active { transform: translateY(1px); }

        .btn-clear {
            background: transparent;
            border: 1px solid var(--border-light);
            color: var(--text-muted);
            padding: 13px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-clear:hover { background: rgba(255,255,255,0.05); color: var(--text-main); }

        .events-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .event-card {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            transition: transform 0.3s ease, border-color 0.3s ease;
            animation: fadeUp 0.5s ease backwards;
        }
        
        .event-card:hover { border-color: rgba(255, 255, 255, 0.15); }

        .event-card:nth-child(1) { animation-delay: 0.1s; }
        .event-card:nth-child(2) { animation-delay: 0.2s; }
        .event-card:nth-child(3) { animation-delay: 0.3s; }
        .event-card:nth-child(4) { animation-delay: 0.4s; }
        .event-card:nth-child(5) { animation-delay: 0.5s; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .event-header {
            padding: 18px 24px;
            background: linear-gradient(180deg, rgba(255,255,255,0.03) 0%, transparent 100%);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .event-title-group {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .event-time {
            font-size: 14px;
            color: var(--accent-blue);
            font-weight: 700;
            background: rgba(59, 130, 246, 0.1);
            padding: 6px 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .event-name {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
        }

        .impact-badge {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .impact-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }

        .impact-high { background-color: rgba(239, 68, 68, 0.1); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.2); box-shadow: 0 0 10px var(--accent-red-glow); }
        .impact-high::before { background-color: var(--accent-red); box-shadow: 0 0 8px var(--accent-red); }

        .impact-medium { background-color: rgba(251, 191, 36, 0.1); color: var(--accent-yellow); border: 1px solid rgba(251, 191, 36, 0.2); box-shadow: 0 0 10px var(--accent-yellow-glow); }
        .impact-medium::before { background-color: var(--accent-yellow); box-shadow: 0 0 8px var(--accent-yellow); }

        .impact-low { background-color: rgba(148, 163, 184, 0.1); color: var(--text-muted); border: 1px solid rgba(148, 163, 184, 0.2); }
        .impact-low::before { background-color: var(--text-muted); }

        .trades-container {
            padding: 20px 24px;
            background-color: var(--bg-primary);
        }

        .trade-row {
            display: grid;
            grid-template-columns: 100px 70px 90px 1fr auto;
            align-items: center;
            gap: 20px;
            padding: 14px 20px;
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: 12px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }

        .trade-row:hover { 
            border-color: var(--border-focus); 
            transform: translateX(4px);
            background-color: rgba(59, 130, 246, 0.03);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .trade-row:last-child { margin-bottom: 0; }

        .trade-pair { font-weight: 800; font-size: 16px; color: var(--text-main); }
        .trade-dir { font-weight: 800; font-size: 13px; }
        .dir-up { color: var(--accent-green); }
        .dir-down { color: var(--accent-red); }

        .outcome-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            text-align: center;
            letter-spacing: 0.5px;
        }
        .outcome-win { background-color: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.3); }
        .outcome-loss { background-color: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.3); }

        .trade-details { 
            font-size: 14px; 
            color: var(--text-muted); 
            display: flex;
            gap: 20px;
        }
        .trade-details strong { color: var(--text-main); font-weight: 600; }

        .view-chart-btn {
            color: var(--accent-blue);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 6px;
            background: rgba(59, 130, 246, 0.1);
            transition: all 0.2s;
        }
        
        .trade-row:hover .view-chart-btn {
            background: var(--accent-blue);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--glass-bg);
            border: 1px dashed var(--border-light);
            border-radius: 16px;
            color: var(--text-muted);
        }
        
        .empty-state-icon { font-size: 40px; margin-bottom: 15px; opacity: 0.5; }
        .empty-state-title { font-size: 18px; font-weight: 600; color: var(--text-main); margin-bottom: 5px; }

        .no-trades {
            padding: 20px;
            text-align: center;
            color: var(--text-dim);
            font-size: 14px;
            font-style: italic;
            background: rgba(0,0,0,0.1);
            border-radius: 8px;
            border: 1px dashed var(--border-light);
        }
        
        @media (max-width: 800px) {
            .trade-row { grid-template-columns: 1fr auto; grid-template-rows: auto auto; gap: 10px; }
            .trade-pair, .trade-dir, .outcome-badge { grid-row: 1; }
            .trade-details { grid-column: 1 / -1; grid-row: 2; flex-direction: column; gap: 5px; }
            .view-chart-btn { display: none; }
            .event-header { flex-direction: column; align-items: flex-start; gap: 15px; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>News Impact & Resolution Tracker</h1>
        <a href="plotter.php" class="back-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back to Plotter
        </a>
    </header>

    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <span class="filter-label">Target Date (Optional)</span>
            <input type="date" id="filter_date" name="filter_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </div>
        
        <div class="filter-group">
            <span class="filter-label">Impact Level</span>
            <select name="filter_impact" id="filter_impact">
                <option value="all" <?php echo $selectedImpact === 'all' ? 'selected' : ''; ?>>All Impacts</option>
                <option value="2" <?php echo $selectedImpact === '2' ? 'selected' : ''; ?>>High Impact (Red)</option>
                <option value="1" <?php echo $selectedImpact === '1' ? 'selected' : ''; ?>>Medium Impact (Yellow)</option>
                <option value="0" <?php echo $selectedImpact === '0' ? 'selected' : ''; ?>>Low Impact (Grey)</option>
            </select>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn-submit">Scan Events</button>
            <a href="?" class="btn-clear">Reset Filters</a>
        </div>
    </form>

    <div class="events-list">
        <?php
        if (empty($finalEvents)) {
            echo "
            <div class='empty-state'>
                <div class='empty-state-icon'>📅</div>
                <div class='empty-state-title'>No Data Available</div>
                <div>There are no economic events matching your current filters.</div>
            </div>";
        } else {
            foreach ($finalEvents as $event) {
                // Determine Impact Styling
                $impactClass = 'impact-low';
                $impactLabel = 'LOW';
                if ($event['impact'] == 1) { $impactClass = 'impact-medium'; $impactLabel = 'MEDIUM'; }
                elseif ($event['impact'] == 2) { $impactClass = 'impact-high'; $impactLabel = 'HIGH'; }

                // Format Event Time based on if a specific date was searched
                $eTime = new DateTime($event['event_time']);
                $displayTime = empty($selectedDate) ? $eTime->format('d M, H:i') : $eTime->format('H:i');

                echo "
                <div class='event-card'>
                    <div class='event-header'>
                        <div class='event-title-group'>
                            <span class='event-time'>
                                <svg width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='10'></circle><polyline points='12 6 12 12 16 14'></polyline></svg>
                                {$displayTime} IST
                            </span>
                            <span class='event-name'>{$event['event_name']}</span>
                        </div>
                        <span class='impact-badge {$impactClass}'>{$impactLabel}</span>
                    </div>
                    <div class='trades-container'>
                ";

                // Query for trades resolving up to 30 mins after this exact event time
                $tradeQuery = "
                    SELECT o.raw_trade_id, o.pair_name, o.trade_result, o.win_loss_time, o.win_loss_price, p.trade_direction 
                    FROM trade_outcome_details o
                    LEFT JOIN prediction_trade_data p ON o.raw_trade_id = p.raw_trade_id
                    WHERE o.win_loss_time >= ? 
                      AND o.win_loss_time <= DATE_ADD(?, INTERVAL 30 MINUTE)
                      AND o.trade_result IN ('win', 'loss')
                    ORDER BY o.win_loss_time ASC
                ";

                if ($tStmt = $conn->prepare($tradeQuery)) {
                    $tStmt->bind_param("ss", $event['event_time'], $event['event_time']);
                    $tStmt->execute();
                    $tStmt->bind_result($tId, $tPair, $tResult, $tTimeStr, $tPrice, $tDirection);
                    
                    $matchedTrades = [];
                    while ($tStmt->fetch()) {
                        $matchedTrades[] = [
                            'raw_trade_id' => $tId,
                            'pair_name' => $tPair,
                            'trade_result' => $tResult,
                            'win_loss_time' => $tTimeStr,
                            'win_loss_price' => $tPrice,
                            'trade_direction' => $tDirection
                        ];
                    }
                    $tStmt->close();
                    
                    if (count($matchedTrades) > 0) {
                        foreach ($matchedTrades as $trade) {
                            $direction = strtoupper($trade['trade_direction'] ?? 'N/A');
                            $dirClass = ($direction === 'UP') ? 'dir-up' : 'dir-down';
                            $resColorClass = (strtolower($trade['trade_result']) === 'win') ? 'outcome-win' : 'outcome-loss';
                            
                            $tTime = new DateTime($trade['win_loss_time']);
                            $tDisplayTime = $tTime->format('H:i:s');
                            
                            echo "
                            <a href='plot/plotter.php?search-id={$trade['raw_trade_id']}' target='_blank' class='trade-row'>
                                <span class='trade-pair'>{$trade['pair_name']}</span>
                                <span class='trade-dir {$dirClass}'>{$direction}</span>
                                <span class='outcome-badge {$resColorClass}'>" . strtoupper($trade['trade_result']) . "</span>
                                
                                <div class='trade-details'>
                                    <span>Resolution: <strong>{$tDisplayTime}</strong></span>
                                    <span>Price: <strong>" . number_format($trade['win_loss_price'], 5) . "</strong></span>
                                    <span>ID: <strong>#{$trade['raw_trade_id']}</strong></span>
                                </div>
                                
                                <span class='view-chart-btn'>
                                    Chart
                                    <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><line x1='7' y1='17' x2='17' y2='7'></line><polyline points='7 7 17 7 17 17'></polyline></svg>
                                </span>
                            </a>
                            ";
                        }
                    } else {
                        echo "<div class='no-trades'>No correlated trade resolutions found within the 30-minute window.</div>";
                    }
                }

                echo "
                    </div>
                </div>
                ";
            }
        }
        ?>
    </div>
</div>

</body>
</html>