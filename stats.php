<?php
require 'db.php'; 

$selectedPair = isset($_GET['pair']) ? $_GET['pair'] : 'All';
$safePair = $conn->real_escape_string($selectedPair);

// Build the SQL filter for the specific pair
$pairFilter = ($selectedPair !== 'All') ? " AND pair_name = '$safePair'" : "";

// Build the SQL filter for the specific time window (1:00 PM to 9:30 PM)
$timeFilter = " AND TIME(COALESCE(win_loss_time, created_at)) BETWEEN '13:00:00' AND '21:30:00'";

// Fetch all unique pairs for the dropdown filter
$pairsResult = $conn->query("SELECT DISTINCT pair_name FROM trade_outcome_details WHERE pair_name != '' ORDER BY pair_name ASC");
$availablePairs = [];
if ($pairsResult) {
    while ($p = $pairsResult->fetch_assoc()) {
        $availablePairs[] = $p['pair_name'];
    }
}

// Calculate Overall Stats (Filtered by Time)
$statsSql = "SELECT 
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as total_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as total_losses,
    SUM(CASE WHEN trade_result = 'setup_not_formed' THEN 1 ELSE 0 END) as total_invalid
FROM trade_outcome_details
WHERE 1=1 $pairFilter $timeFilter";

$statsResult = $conn->query($statsSql);
$stats = $statsResult->fetch_assoc();

$totalSignals = $stats['total_signals'] ?? 0;
$wins = $stats['total_wins'] ?? 0;
$losses = $stats['total_losses'] ?? 0;
$invalid = $stats['total_invalid'] ?? 0;

$validTrades = $wins + $losses;
$winRate = ($validTrades > 0) ? round(($wins / $validTrades) * 100, 2) : 0;

// Calculate Maximum Streak Analysis
$streakSql = "SELECT trade_result 
              FROM trade_outcome_details 
              WHERE trade_result IN ('win', 'loss') $pairFilter $timeFilter 
              ORDER BY COALESCE(win_loss_time, created_at) ASC";
$streakResult = $conn->query($streakSql);

$maxWinsStreak = 0;
$maxLossesStreak = 0;
$currentWinsStreak = 0;
$currentLossesStreak = 0;

if ($streakResult && $streakResult->num_rows > 0) {
    while ($sRow = $streakResult->fetch_assoc()) {
        if ($sRow['trade_result'] === 'win') {
            $currentWinsStreak++;
            $currentLossesStreak = 0;
            if ($currentWinsStreak > $maxWinsStreak) $maxWinsStreak = $currentWinsStreak;
        } else if ($sRow['trade_result'] === 'loss') {
            $currentLossesStreak++;
            $currentWinsStreak = 0;
            if ($currentLossesStreak > $maxLossesStreak) $maxLossesStreak = $currentLossesStreak;
        }
    }
}

// Fetch Time-Series Data for the Graph (Filtered by Time)
$graphSql = "SELECT 
    (HOUR(COALESCE(win_loss_time, created_at)) * 60) + (FLOOR(MINUTE(COALESCE(win_loss_time, created_at)) / 30) * 30) as sort_minutes,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as interval_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as interval_losses,
    SUM(CASE WHEN trade_result = 'setup_not_formed' THEN 1 ELSE 0 END) as interval_invalid
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY sort_minutes
ORDER BY sort_minutes ASC";

$graphResult = $conn->query($graphSql);
    
$times = [];
$timeRanges = []; // New array to hold "5:30 to 5:59" format
$intervalWinRates = [];
$intervalWinsArr = [];
$intervalLossesArr = [];
$intervalInvalidArr = [];

// Session Timings (IST)
$marketSessions = [
    "Sydney"   => ["02:30", "11:30"],
    "Tokyo"    => ["05:30", "14:30"],
    "London"   => ["12:30", "21:30"],
    "New York" => ["17:30", "02:30"],
];

// Dynamic array to hold aggregated session data (including overlaps)
$sessionPerformance = [];

if ($graphResult) {
    while ($row = $graphResult->fetch_assoc()) {
        $intervalTotal = $row['interval_wins'] + $row['interval_losses'];
        $intervalRate = ($intervalTotal > 0) ? round(($row['interval_wins'] / $intervalTotal) * 100, 2) : 0;
        
        $intervalWinRates[] = $intervalRate;
        $intervalWinsArr[] = $row['interval_wins'];
        $intervalLossesArr[] = $row['interval_losses'];
        $intervalInvalidArr[] = $row['interval_invalid'];

        // TIME RANGE CALCULATION (e.g., 5:30 PM to 5:59 PM)
        $h = floor($row['sort_minutes'] / 60);
        $m = $row['sort_minutes'] % 60;
        
        // Start Time String
        $startH_12 = ($h % 12 == 0) ? 12 : ($h % 12);
        $startAmPm = ($h >= 12 && $h < 24) ? "PM" : "AM";
        $startTimeStr = sprintf("%d:%02d %s", $startH_12, $m, $startAmPm);
        $times[] = $startTimeStr; // X-axis label

        // End Time String (+29 minutes)
        $endM = $m + 29;
        $endH = $h;
        if ($endM >= 60) {
            $endM -= 60;
            $endH += 1;
        }
        $endH_12 = ($endH % 12 == 0) ? 12 : ($endH % 12);
        $endAmPm = ($endH >= 12 && $endH < 24) ? "PM" : "AM";
        $endTimeStr = sprintf("%d:%02d %s", $endH_12, $endM, $endAmPm);
        
        // Full Range String for Tooltip
        $timeRanges[] = "$startTimeStr to $endTimeStr";

        // SESSION CALCULATION (24-hour format for logic)
        $time24 = sprintf("%02d:%02d", $h, $m);
        $activeSessions = [];
        
        foreach ($marketSessions as $name => [$start, $end]) {
            $inSession = false;
            if ($start < $end) {
                if ($time24 >= $start && $time24 < $end) $inSession = true;
            } else {
                if ($time24 >= $start || $time24 < $end) $inSession = true;
            }

            if ($inSession) {
                $activeSessions[] = $name;
            }
        }

        // Create a unique key for the overlap combination
        $sessionKey = empty($activeSessions) ? "No Major Session" : implode(" + ", $activeSessions);

        if (!isset($sessionPerformance[$sessionKey])) {
            $sessionPerformance[$sessionKey] = ["wins" => 0, "losses" => 0, "invalid" => 0];
        }
        $sessionPerformance[$sessionKey]['wins'] += $row['interval_wins'];
        $sessionPerformance[$sessionKey]['losses'] += $row['interval_losses'];
        $sessionPerformance[$sessionKey]['invalid'] += $row['interval_invalid'];
    }
}

// Fetch Pair-wise Table Data (Filtered by Time)
$tableSql = "SELECT 
    pair_name,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as pair_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as pair_losses,
    SUM(CASE WHEN trade_result = 'setup_not_formed' THEN 1 ELSE 0 END) as pair_invalid
FROM trade_outcome_details
WHERE pair_name != '' $pairFilter $timeFilter
GROUP BY pair_name
ORDER BY pair_wins DESC, pair_losses ASC";

$tableResult = $conn->query($tableSql);

// Fetch Day of the Week Table Data (Filtered by Time)
$daySql = "SELECT 
    DAYNAME(COALESCE(win_loss_time, created_at)) as day_name,
    DAYOFWEEK(COALESCE(win_loss_time, created_at)) as day_num,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as day_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as day_losses,
    SUM(CASE WHEN trade_result = 'setup_not_formed' THEN 1 ELSE 0 END) as day_invalid
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY day_name, day_num
ORDER BY day_num ASC";

$dayResult = $conn->query($daySql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trading Performance Stats (1 PM - 9:30 PM)</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            margin: 0;
            padding: 40px;
        }
        .dashboard {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        h2 { margin: 0; }
        .subtitle {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .filter-form select {
            background-color: #1e293b;
            color: #f8fafc;
            border: 1px solid #3b82f6;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            outline: none;
        }
        .filter-form select:focus {
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background-color: #1e293b;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            text-align: center;
            border: 1px solid #334155;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
            margin: 0;
        }
        .text-green { color: #10b981; }
        .text-red { color: #ef4444; }
        .text-blue { color: #3b82f6; }
        .text-gray { color: #64748b; }
        
        .chart-container, .table-container {
            background-color: #1e293b;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #334155;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 40px;
        }

        .table-container h3 {
            margin-top: 0;
            margin-bottom: 20px;
            color: #f8fafc;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid #334155;
        }
        th {
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 1px;
        }
        tr:hover {
            background-color: #0f172a;
        }
        .pair-badge, .day-badge, .session-badge {
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 13px;
        }
        .pair-badge { background-color: #3b82f6; }
        .day-badge { background-color: #8b5cf6; }
        .session-badge { background-color: #ec4899; }
    </style>
</head>
<body>

<div class="dashboard">
    
    <div class="header-controls">
        <div>
            <h2>Algorithm Performance Dashboard</h2>
            <div class="subtitle">Time Window Constraint: 1:00 PM - 9:30 PM (IST)</div>
        </div>
        <form class="filter-form" method="GET" action="">
            <select name="pair" onchange="this.form.submit()">
                <option value="All" <?php if($selectedPair === 'All') echo 'selected'; ?>>All Pairs</option>
                <?php foreach($availablePairs as $pair): ?>
                    <option value="<?php echo htmlspecialchars($pair); ?>" <?php if($selectedPair === $pair) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($pair); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Win Rate</h3>
            <p class="value text-blue"><?php echo $winRate; ?>%</p>
        </div>
        <div class="stat-card">
            <h3>Wins</h3>
            <p class="value text-green"><?php echo $wins; ?></p>
        </div>
        <div class="stat-card">
            <h3>Losses</h3>
            <p class="value text-red"><?php echo $losses; ?></p>
        </div>
        <div class="stat-card">
            <h3>Ignored</h3>
            <p class="value text-gray"><?php echo $invalid; ?></p>
        </div>
        <div class="stat-card">
            <h3>Max Win Streak</h3>
            <p class="value text-green"><?php echo $maxWinsStreak; ?>🔥</p>
        </div>
        <div class="stat-card">
            <h3>Max Loss Streak</h3>
            <p class="value text-red"><?php echo $maxLossesStreak; ?>⚠️</p>
        </div>
    </div>

    <div class="chart-container">
        <canvas id="winRateChart"></canvas>
    </div>

    <div class="table-container">
        <h3>🌍 Global Market Session Performance (Aggregated)</h3>
        <table>
            <thead>
                <tr>
                    <th>Session Region</th>
                    <th>Win Rate</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Ignored (No Setup)</th>
                    <th>Total Signals</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($sessionPerformance as $sessionName => $data) {
                    $sValid = $data['wins'] + $data['losses'];
                    $sWinRate = ($sValid > 0) ? round(($data['wins'] / $sValid) * 100, 2) : 0;
                    $sTotal = $data['wins'] + $data['losses'] + $data['invalid'];

                    if ($sTotal == 0) continue; 

                    $rateColor = ($sWinRate >= 70) ? '#10b981' : (($sWinRate >= 50) ? '#ffb703' : '#ef4444');

                    echo "<tr>";
                    echo "<td><span class='session-badge'>" . $sessionName . "</span></td>";
                    echo "<td style='color: {$rateColor}; font-weight: bold;'>" . $sWinRate . "%</td>";
                    echo "<td class='text-green' style='font-weight: bold;'>" . $data['wins'] . "</td>";
                    echo "<td class='text-red' style='font-weight: bold;'>" . $data['losses'] . "</td>";
                    echo "<td class='text-gray'>" . $data['invalid'] . "</td>";
                    echo "<td>" . $sTotal . "</td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="table-container">
        <h3>📊 Pair Performance Breakdown</h3>
        <table>
            <thead>
                <tr>
                    <th>Currency Pair</th>
                    <th>Win Rate</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Ignored (No Setup)</th>
                    <th>Total Signals</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($tableResult && $tableResult->num_rows > 0) {
                    while ($tRow = $tableResult->fetch_assoc()) {
                        $pWins = $tRow['pair_wins'] ?? 0;
                        $pLosses = $tRow['pair_losses'] ?? 0;
                        $pInvalid = $tRow['pair_invalid'] ?? 0;
                        $pTotal = $tRow['total_signals'] ?? 0;
                        
                        $pValid = $pWins + $pLosses;
                        $pWinRate = ($pValid > 0) ? round(($pWins / $pValid) * 100, 2) : 0;

                        $rateColor = ($pWinRate >= 70) ? '#10b981' : (($pWinRate >= 50) ? '#ffb703' : '#ef4444');

                        echo "<tr>";
                        echo "<td><span class='pair-badge'>" . htmlspecialchars($tRow['pair_name']) . "</span></td>";
                        echo "<td style='color: {$rateColor}; font-weight: bold;'>" . $pWinRate . "%</td>";
                        echo "<td class='text-green' style='font-weight: bold;'>" . $pWins . "</td>";
                        echo "<td class='text-red' style='font-weight: bold;'>" . $pLosses . "</td>";
                        echo "<td class='text-gray'>" . $pInvalid . "</td>";
                        echo "<td>" . $pTotal . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' style='text-align: center; color: #64748b;'>No trade data available in this time frame.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="table-container">
        <h3>📅 Day of the Week Performance</h3>
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Win Rate</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Ignored (No Setup)</th>
                    <th>Total Signals</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($dayResult && $dayResult->num_rows > 0) {
                    while ($dRow = $dayResult->fetch_assoc()) {
                        $dWins = $dRow['day_wins'] ?? 0;
                        $dLosses = $dRow['day_losses'] ?? 0;
                        $dInvalid = $dRow['day_invalid'] ?? 0;
                        $dTotal = $dRow['total_signals'] ?? 0;
                        
                        $dValid = $dWins + $dLosses;
                        $dWinRate = ($dValid > 0) ? round(($dWins / $dValid) * 100, 2) : 0;

                        $rateColor = ($dWinRate >= 70) ? '#10b981' : (($dWinRate >= 50) ? '#ffb703' : '#ef4444');

                        echo "<tr>";
                        echo "<td><span class='day-badge'>" . htmlspecialchars($dRow['day_name'] ?? 'Unknown') . "</span></td>";
                        echo "<td style='color: {$rateColor}; font-weight: bold;'>" . $dWinRate . "%</td>";
                        echo "<td class='text-green' style='font-weight: bold;'>" . $dWins . "</td>";
                        echo "<td class='text-red' style='font-weight: bold;'>" . $dLosses . "</td>";
                        echo "<td class='text-gray'>" . $dInvalid . "</td>";
                        echo "<td>" . $dTotal . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' style='text-align: center; color: #64748b;'>No day data available in this time frame.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

</div>

<script>
const labels = <?php echo json_encode($times); ?>;
const hoverRanges = <?php echo json_encode($timeRanges); ?>; 

const dataPoints = <?php echo json_encode($intervalWinRates); ?>;
const winsData = <?php echo json_encode($intervalWinsArr); ?>;
const lossesData = <?php echo json_encode($intervalLossesArr); ?>;
const invalidData = <?php echo json_encode($intervalInvalidArr); ?>;

const ctx = document.getElementById('winRateChart').getContext('2d');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: '30-Minute Interval Win Rate (%)',
            data: dataPoints,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 3,
            pointBackgroundColor: '#10b981',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7,
            fill: true,
            tension: 0.4 
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                labels: { color: '#f8fafc' }
            },
            tooltip: {
                callbacks: {
                    title: function(context) {
                        // Replaces the single time label with the specific "X to Y" range
                        return '🕒 ' + hoverRanges[context[0].dataIndex];
                    },
                    label: function(context) {
                        return context.parsed.y + '% Win Rate';
                    },
                    afterLabel: function(context) {
                        const idx = context.dataIndex;
                        return [
                            '✅ Wins: ' + winsData[idx],
                            '❌ Losses: ' + lossesData[idx],
                            '⚠️ Not Formed: ' + invalidData[idx]
                        ];
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                grid: { color: '#334155' },
                ticks: {
                    color: '#94a3b8', 
                    callback: function(value) { return value + '%' } 
                }
            },
            x: {
                grid: { color: '#334155', display: false },
                ticks: { color: '#94a3b8' }
            }
        }
    }
});
</script>

</body>
</html>