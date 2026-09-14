<?php

require 'db.php'; 

$coreWatchlist = ['EURCHF', 'EURAUD', 'CHFJPY', 'CADJPY', 'AUDUSD', 'AUDCAD', 'AUDJPY', 'GBPCHF'];

$selectedPair = isset($_GET['pair']) ? $_GET['pair'] : 'All';
$safePair = $conn->real_escape_string($selectedPair);

$pairFilter = ($selectedPair !== 'All') ? " AND pair_name = '$safePair'" : "";
$timeFilter = " AND TIME(COALESCE(win_loss_time, created_at)) BETWEEN '13:00:00' AND '21:30:00'";

// ==========================================
// CSV EXPORT HANDLER
// ==========================================
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$type.'_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');

    if ($type === 'trades') {
        fputcsv($out, ['id','pair_name','trade_result','created_at','win_loss_time']);
        $q = $conn->query("SELECT id,pair_name,trade_result,created_at,win_loss_time
                           FROM trade_outcome_details WHERE 1=1 $pairFilter $timeFilter
                           ORDER BY COALESCE(win_loss_time, created_at) DESC");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
    }
    elseif ($type === 'pairs') {
        fputcsv($out, ['pair_name','total','wins','losses','raw_win_rate','normalized_edge']);
        $q = $conn->query("SELECT pair_name,
            COUNT(*) t,
            SUM(trade_result='win') w,
            SUM(trade_result='loss') l,
            ROUND(SUM(trade_result='win')/NULLIF(SUM(trade_result IN ('win','loss')),0)*100,2) raw,
            ROUND((SUM(trade_result='win')+2)/(SUM(trade_result IN ('win','loss'))+4)*100,2) norm
            FROM trade_outcome_details WHERE pair_name!='' $pairFilter $timeFilter
            GROUP BY pair_name ORDER BY norm DESC");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
    }
    elseif ($type === 'matrix') {
        fputcsv($out, ['pair_name','day_name','wins','losses','raw_win_rate','normalized_edge']);
        $q = $conn->query("SELECT pair_name, DAYNAME(COALESCE(win_loss_time,created_at)) d,
            SUM(trade_result='win') w, SUM(trade_result='loss') l
            FROM trade_outcome_details WHERE pair_name!='' $pairFilter $timeFilter
            GROUP BY pair_name, d");
        while ($r = $q->fetch_assoc()) {
            $v = $r['w']+$r['l']; if(!$v) continue;
            $raw = round($r['w']/$v*100,2);
            $norm = round(($r['w']+2)/($v+4)*100,2);
            fputcsv($out, [$r['pair_name'],$r['d'],$r['w'],$r['l'],$raw,$norm]);
        }
    }
    fclose($out); exit;
}

// ==========================================
// AI INSIGHTS ENGINE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_ai_insights'])) {

    function getActiveAPI() {
        global $conn;
        $res = mysqli_query($conn, "SELECT * FROM api_keys WHERE status='active' LIMIT 1");
        return ($row = mysqli_fetch_assoc($res)) ? $row : null;
    }

    function callAI($messages) {
        global $API_KEY, $BASE_URL, $MODEL, $api;
        $provider = strtolower($api['provider']);

        if ($provider === 'gemini') {
            $text = "";
            foreach ($messages as $m) {
                $text .= strtoupper($m['role']) . ": " . $m['content'] . "\n";
            }
            $url = rtrim($BASE_URL, '/') . "/models/" . $MODEL . ":generateContent?key=" . $API_KEY;
            $payload = ["contents" => [["parts" => [["text" => $text]]]]];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_TIMEOUT => 45
            ]);
            $response = curl_exec($ch);
            if (curl_errno($ch)) return "&#9888; Gemini Curl Error: " . curl_error($ch);
            curl_close($ch);

            $json = json_decode($response, true);
            if (!isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return "&#9888; Gemini failed: " . substr($response, 0, 300);
            }
            return $json['candidates'][0]['content']['parts'][0]['text'];

        } else {
            // Groq / OpenAI-compatible
            $payload = [
                "model"       => $MODEL,
                "messages"    => $messages,
                "temperature" => 0.3,
                "max_tokens"  => 4000
            ];

            // Cap reasoning tokens for models that think internally
            if (stripos($MODEL, 'r1') !== false
                || stripos($MODEL, 'gpt-oss') !== false
                || stripos($MODEL, 'reasoning') !== false) {
                $payload['reasoning_effort'] = 'low';
            }

            $ch = curl_init(rtrim($BASE_URL, '/') . "/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $API_KEY,
                    "Content-Type: application/json"
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 90
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) return "Curl Error: " . curl_error($ch);
            curl_close($ch);

            file_put_contents(__DIR__ . '/groq_raw.txt', $response);

            $json = json_decode($response, true);

            if (isset($json['error'])) {
                return "&#9888; API Error: " . $json['error']['message'];
            }

            $content = $json['choices'][0]['message']['content'] ?? '';
            if (trim($content) === '') {
                $finishReason = $json['choices'][0]['finish_reason'] ?? 'unknown';
                $reasonTokens = $json['usage']['completion_tokens_details']['reasoning_tokens'] ?? 'N/A';
                return "&#9888; Model returned empty response. finish_reason: `$finishReason`, reasoning_tokens_used: `$reasonTokens`. Try a non-reasoning model or increase max_tokens.";
            }

            return $content;
        }
    }

    $api = getActiveAPI();
    if (!$api) {
        echo "&#10060; No active API key found in the database.";
        exit;
    }

    $API_KEY  = $api['api_key'];
    $BASE_URL = $api['base_url'];
    $MODEL    = $api['model'];

    $rawStatsPayload = trim($_POST['fetch_ai_insights']);

    if (empty($rawStatsPayload)) {
        echo "&#9888; No trading data available to analyze.";
        exit;
    }

    $messages = [
        [
            "role"    => "system",
            "content" => "You are an elite quantitative trading analyst. Analyze the user's trading algorithm performance based on Laplace smoothed metrics. Identify overall best pairs, macro day-of-the-week trends, monthly trajectory, session-based edges (London vs NY vs Overlap vs Asian), BEST and WORST TIME-OF-DAY WINDOWS in IST, and if any specific pair-day combinations have enough volume to warrant a strategy adjustment. For timing, prioritize 1-hour windows with at least 10 valid trades and ignore tiny samples. Ignore micro-anomalies with fewer than 10 trades."
        ],
        [
            "role"    => "user",
            "content" => "Here are my trading dashboard metrics (Filtered for 1:00 PM to 9:30 PM):\n" . $rawStatsPayload
        ]
    ];

    $reply = callAI($messages);

    // DEBUG LOG
    file_put_contents(__DIR__ . '/ai_debug.txt', date('H:i:s') . "\n" . $reply . "\n---\n", FILE_APPEND);

    echo $reply;
    exit;
}

// ==========================================
// DASHBOARD DATA QUERIES
// ==========================================

$pairsResult = $conn->query("SELECT DISTINCT pair_name FROM trade_outcome_details WHERE pair_name != '' ORDER BY pair_name ASC");
$availablePairs = [];
if ($pairsResult) {
    while ($p = $pairsResult->fetch_assoc()) {
        $availablePairs[] = $p['pair_name'];
    }
}

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
$wins         = $stats['total_wins'] ?? 0;
$losses       = $stats['total_losses'] ?? 0;
$invalid      = $stats['total_invalid'] ?? 0;

$validTrades        = $wins + $losses;
$winRate            = ($validTrades > 0) ? round(($wins / $validTrades) * 100, 2) : 0;
$systemNormalizedEdge = ($validTrades > 0) ? round((($wins + 2) / ($validTrades + 4)) * 100, 2) : 0;

$aiMatrixString = "";

// Monthly Data
$monthlySql = "SELECT 
    DATE_FORMAT(COALESCE(win_loss_time, created_at), '%Y-%m') as sort_month,
    DATE_FORMAT(COALESCE(win_loss_time, created_at), '%M %Y') AS month_year,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as monthly_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as monthly_losses,
    ((SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) + 2) / (SUM(CASE WHEN trade_result IN ('win', 'loss') THEN 1 ELSE 0 END) + 4)) * 100 as normalized_edge
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY sort_month, month_year
ORDER BY sort_month ASC";

$monthlyResult = $conn->query($monthlySql);

$monthlyLabels   = [];
$monthlyWins     = [];
$monthlyLosses   = [];
$monthlyNorm     = [];
$monthlyTableHtml = "";

if ($monthlyResult && $monthlyResult->num_rows > 0) {
    $aiMatrixString .= "--- MONTHLY METRICS ---\n";
    $monthlyRows = [];
    while ($mRow = $monthlyResult->fetch_assoc()) $monthlyRows[] = $mRow;

    foreach ($monthlyRows as $mRow) {
        $mWins   = $mRow['monthly_wins'] ?? 0;
        $mLosses = $mRow['monthly_losses'] ?? 0;
        $mValid  = $mWins + $mLosses;
        if ($mValid == 0) continue;

        $mRawRate        = round(($mWins / $mValid) * 100, 2);
        $mNormalizedEdge = round($mRow['normalized_edge'], 2);

        $monthlyLabels[] = $mRow['month_year'];
        $monthlyWins[]   = $mWins;
        $monthlyLosses[] = $mLosses;
        $monthlyNorm[]   = $mNormalizedEdge;

        $aiMatrixString .= "Month: {$mRow['month_year']} | Normalized: $mNormalizedEdge% | Trades: $mValid.\n";

        $rateColor = ($mNormalizedEdge >= 80) ? '#a78bfa' : (($mNormalizedEdge >= 70) ? '#10b981' : '#ef4444');
        $rowHtml  = "<tr>";
        $rowHtml .= "<td><span class='month-badge'>" . htmlspecialchars($mRow['month_year']) . "</span></td>";
        $rowHtml .= "<td style='color:{$rateColor};font-weight:bold;font-size:16px;'>" . $mNormalizedEdge . "%</td>";
        $rowHtml .= "<td style='color:#94a3b8;'>" . $mRawRate . "%</td>";
        $rowHtml .= "<td>" . $mValid . "</td>";
        $rowHtml .= "<td><span class='text-green'>" . $mWins . "</span> / <span class='text-red'>" . $mLosses . "</span></td>";
        $rowHtml .= "</tr>";
        $monthlyTableHtml = $rowHtml . $monthlyTableHtml;
    }
}

// Session Data
$sessionSql = "SELECT 
    CASE 
        WHEN TIME(COALESCE(win_loss_time, created_at)) BETWEEN '17:30:00' AND '21:30:00' THEN 'London / New York Overlap'
        WHEN TIME(COALESCE(win_loss_time, created_at)) BETWEEN '12:30:00' AND '17:29:59' THEN 'London Session'
        WHEN (TIME(COALESCE(win_loss_time, created_at)) >= '21:30:01' OR TIME(COALESCE(win_loss_time, created_at)) <= '02:29:59') THEN 'New York Session'
        WHEN TIME(COALESCE(win_loss_time, created_at)) BETWEEN '02:30:00' AND '12:29:59' THEN 'Asian / Tokyo Session'
        ELSE 'Other'
    END AS trading_session,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as session_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as session_losses,
    ((SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) + 2) / (SUM(CASE WHEN trade_result IN ('win', 'loss') THEN 1 ELSE 0 END) + 4)) * 100 as normalized_edge
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY trading_session
ORDER BY normalized_edge DESC";
$sessionResult = $conn->query($sessionSql);

// Global Day Data
$globalDaySql = "SELECT 
    DAYNAME(COALESCE(win_loss_time, created_at)) as day_name,
    DAYOFWEEK(COALESCE(win_loss_time, created_at)) as day_num,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as day_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as day_losses,
    ((SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) + 2) / (SUM(CASE WHEN trade_result IN ('win', 'loss') THEN 1 ELSE 0 END) + 4)) * 100 as normalized_edge
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY day_name, day_num
ORDER BY day_num ASC";
$globalDayResult = $conn->query($globalDaySql);

// Pair-Day Matrix
$pairDaySql = "SELECT 
    pair_name,
    DAYNAME(COALESCE(win_loss_time, created_at)) as day_name,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as pd_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as pd_losses
FROM trade_outcome_details
WHERE pair_name != '' $pairFilter $timeFilter
GROUP BY pair_name, day_name";
$pairDayResult = $conn->query($pairDaySql);

$pairDayMatrix = [];
if ($pairDayResult && $pairDayResult->num_rows > 0) {
    while ($row = $pairDayResult->fetch_assoc()) {
        $pairDayMatrix[$row['pair_name']][$row['day_name']] = [
            'wins'   => $row['pd_wins'],
            'losses' => $row['pd_losses']
        ];
    }
}

// Pair-wise Table
$tableSql = "SELECT 
    pair_name,
    COUNT(*) as total_signals,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) as pair_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) as pair_losses,
    ((SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) + 2) / (SUM(CASE WHEN trade_result IN ('win', 'loss') THEN 1 ELSE 0 END) + 4)) * 100 as normalized_edge
FROM trade_outcome_details
WHERE pair_name != '' $pairFilter $timeFilter
GROUP BY pair_name
ORDER BY normalized_edge DESC";

$tableResult = $conn->query($tableSql);

// ==========================================================
// BEST VS WORST TIMING - 1-HOUR IST WINDOWS
// ==========================================================
$timingSql = "SELECT
    HOUR(COALESCE(win_loss_time, created_at)) AS hour_num,
    SUM(CASE WHEN trade_result = 'win' THEN 1 ELSE 0 END) AS timing_wins,
    SUM(CASE WHEN trade_result = 'loss' THEN 1 ELSE 0 END) AS timing_losses
FROM trade_outcome_details
WHERE COALESCE(win_loss_time, created_at) IS NOT NULL $pairFilter $timeFilter
GROUP BY hour_num
ORDER BY hour_num ASC";

$timingResult = $conn->query($timingSql);
$timingRows = [];
$bestTiming = null;
$worstTiming = null;

if ($timingResult && $timingResult->num_rows > 0) {
    while ($tRow = $timingResult->fetch_assoc()) {
        $tw = (int)$tRow['timing_wins'];
        $tl = (int)$tRow['timing_losses'];
        $tv = $tw + $tl;
        if ($tv <= 0) continue;

        $tn = round((($tw + 2) / ($tv + 4)) * 100, 2);
        $tr = round(($tw / $tv) * 100, 2);
        $hr = (int)$tRow['hour_num'];

        $timingRows[] = [
            'label'   => sprintf('%02d:00 - %02d:00', $hr, ($hr + 1) % 24),
            'wins'    => $tw,
            'losses'  => $tl,
            'valid'   => $tv,
            'raw'     => $tr,
            'norm'    => $tn
        ];
    }

    // Require at least 10 valid trades before a window can be called best/worst.
    $qualifiedTiming = array_values(array_filter($timingRows, function($r) {
        return $r['valid'] >= 10;
    }));

    if (!empty($qualifiedTiming)) {
        usort($qualifiedTiming, function($a, $b) {
            if ($a['norm'] == $b['norm']) return $b['valid'] <=> $a['valid'];
            return $b['norm'] <=> $a['norm'];
        });
        $bestTiming = $qualifiedTiming[0];

        usort($qualifiedTiming, function($a, $b) {
            if ($a['norm'] == $b['norm']) return $b['valid'] <=> $a['valid'];
            return $a['norm'] <=> $b['norm'];
        });
        $worstTiming = $qualifiedTiming[0];
    }

    $aiMatrixString .= "--- TIME-OF-DAY TIMING METRICS (IST) ---\n";
    foreach ($timingRows as $tr) {
        $aiMatrixString .= "Time: {$tr['label']} | Normalized: {$tr['norm']}% | Raw WR: {$tr['raw']}% | Trades: {$tr['valid']} ({$tr['wins']} W / {$tr['losses']} L).\n";
    }
    if ($bestTiming) {
        $aiMatrixString .= "BEST TIMING: {$bestTiming['label']} | {$bestTiming['norm']}% normalized | {$bestTiming['valid']} valid trades.\n";
    }
    if ($worstTiming) {
        $aiMatrixString .= "WORST TIMING: {$worstTiming['label']} | {$worstTiming['norm']}% normalized | {$worstTiming['valid']} valid trades.\n";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quantitative Algorithm Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 40px; }
        .dashboard { max-width: 1300px; margin: 0 auto; }
        .header-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        h2 { margin: 0; }
        .subtitle { color: #94a3b8; font-size: 14px; margin-top: 5px; }
        .export-bar { display: flex; gap: 8px; margin-bottom: 30px; }
        .export-bar a { color: #fff; padding: 8px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; }
        .filter-form select { background-color: #1e293b; color: #f8fafc; border: 1px solid #3b82f6; padding: 10px 16px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; outline: none; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; margin-bottom: 40px; }
        .stat-card { background-color: #1e293b; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid #334155; }
        .stat-card.highlight { border: 1px solid #8b5cf6; background: linear-gradient(180deg, #1e293b 0%, #1e1b4b 100%); }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 28px; font-weight: bold; margin: 0; }
        .text-green { color: #10b981; } .text-red { color: #ef4444; } .text-blue { color: #3b82f6; } .text-purple { color: #a78bfa; } .text-gray { color: #64748b; }
        .ai-insights-panel { background-color: #1e293b; padding: 24px; border-radius: 12px; border-left: 5px solid #28a745; margin-bottom: 40px; }
        .ai-insights-panel h3 { margin-top: 0; margin-bottom: 15px; color: #28a745; display: flex; align-items: center; gap: 8px; }
        .ai-content { font-size: 15px; line-height: 1.7; color: #e2e8f0; }
        .ai-content h2, .ai-content h3 { color: #3b82f6; margin-top: 20px; margin-bottom: 10px; font-size: 18px; }
        .ai-content ul { margin-left: 20px; margin-bottom: 15px; }
        .table-container { background-color: #1e293b; padding: 24px; border-radius: 12px; border: 1px solid #334155; margin-bottom: 40px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; white-space: nowrap; }
        th, td { padding: 14px 16px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 1px; }
        tr:hover { background-color: #0f172a; }
        .pair-badge, .day-badge, .month-badge, .session-badge, .status-badge { color: white; padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 13px; }
        .pair-badge { background-color: #3b82f6; } .day-badge { background-color: #f59e0b; } .month-badge { background-color: #0284c7; } .session-badge { background-color: #8b5cf6; }
        .status-core { background-color: #10b981; } .status-paused { background-color: #ef4444; }
        .matrix-cell { text-align: center; } .matrix-edge { font-size: 16px; font-weight: bold; } .matrix-sub { font-size: 11px; color: #64748b; margin-top: 4px; }
        .loader { color: #94a3b8; font-style: italic; }
        .timing-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:40px; }
        .timing-card { background-color:#1e293b; padding:24px; border-radius:12px; border:1px solid #334155; }
        .timing-card.best { border-left:5px solid #10b981; }
        .timing-card.worst { border-left:5px solid #ef4444; }
        .timing-card h3 { margin:0 0 12px 0; }
        .timing-time { font-size:30px; font-weight:800; margin:8px 0; }
        .timing-meta { color:#94a3b8; font-size:13px; line-height:1.7; }
        .timing-note { color:#64748b; font-size:12px; margin-top:12px; }
        .monthly-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 40px; }
        @media (max-width: 1024px) { .monthly-grid { grid-template-columns: 1fr; } .timing-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="dashboard">

    <div class="header-controls">
        <div>
            <h2>Quantitative Algorithm Dashboard</h2>
            <div class="subtitle">Laplace-Smoothed Metrics | Filter: 1:00 PM - 9:30 PM (IST)</div>
        </div>
        <form class="filter-form" method="GET" action="">
            <select name="pair" onchange="this.form.submit()">
                <option value="All" <?php if($selectedPair === 'All') echo 'selected'; ?>>All Pairs</option>
                <optgroup label="Core Watchlist">
                <?php foreach($availablePairs as $pair): ?>
                    <?php if(in_array($pair, $coreWatchlist)): ?>
                        <option value="<?php echo htmlspecialchars($pair); ?>" <?php if($selectedPair === $pair) echo 'selected'; ?>><?php echo htmlspecialchars($pair); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
                </optgroup>
                <optgroup label="Secondary / Paused">
                <?php foreach($availablePairs as $pair): ?>
                    <?php if(!in_array($pair, $coreWatchlist)): ?>
                        <option value="<?php echo htmlspecialchars($pair); ?>" <?php if($selectedPair === $pair) echo 'selected'; ?>><?php echo htmlspecialchars($pair); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
                </optgroup>
            </select>
        </form>
    </div>

    <div class="export-bar">
        <a href="?pair=<?=urlencode($selectedPair)?>&export=trades" style="background:#10b981;">&#11015; Trades CSV</a>
        <a href="?pair=<?=urlencode($selectedPair)?>&export=pairs" style="background:#3b82f6;">&#11015; Pairs CSV</a>
        <a href="?pair=<?=urlencode($selectedPair)?>&export=matrix" style="background:#8b5cf6;">&#11015; Matrix CSV</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Raw Win Rate</h3>
            <p class="value text-blue"><?php echo $winRate; ?>%</p>
        </div>
        <div class="stat-card highlight">
            <h3>Normalized Edge</h3>
            <p class="value text-purple"><?php echo $systemNormalizedEdge; ?>%</p>
        </div>
        <div class="stat-card">
            <h3>Total Trades</h3>
            <p class="value text-gray"><?php echo $validTrades; ?></p>
        </div>
        <div class="stat-card">
            <h3>Wins / Losses</h3>
            <p class="value text-green"><?php echo $wins; ?> <span style="color:#64748b;font-size:18px;">/</span> <span class="text-red"><?php echo $losses; ?></span></p>
        </div>
    </div>

    <div class="ai-insights-panel">
        <h3>&#129302; Quantitative Data Insights</h3>
        <div id="aiRunSpace" class="ai-content">
            <span class="loader">&#8987; Analyzing matrix tables via quantitative Laplace algorithms...</span>
        </div>
    </div>

    <div class="monthly-grid">
        <div class="table-container" style="margin-bottom:0;">
            <h3>&#128200; Monthly Performance Trend</h3>
            <canvas id="monthlyChart" style="max-height:350px;"></canvas>
        </div>
        <div class="table-container" style="margin-bottom:0;">
            <h3>&#128197; Month-over-Month Edge</h3>
            <div style="max-height:350px;overflow-y:auto;">
                <table>
                    <thead><tr><th>Month</th><th>Norm Edge</th><th>Raw WR</th><th>Trades</th><th>W / L</th></tr></thead>
                    <tbody>
                        <?php echo !empty($monthlyTableHtml) ? $monthlyTableHtml : "<tr><td colspan='5' style='text-align:center;'>No data.</td></tr>"; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

        <div class="timing-grid">
        <div class="timing-card best">
            <h3>&#128994; Best Timing Window</h3>
            <?php if ($bestTiming): ?>
                <div class="timing-time text-green"><?php echo htmlspecialchars($bestTiming['label']); ?></div>
                <div class="timing-meta">
                    Normalized Edge: <strong class="text-green"><?php echo $bestTiming['norm']; ?>%</strong><br>
                    Raw Win Rate: <?php echo $bestTiming['raw']; ?>%<br>
                    Valid Trades: <?php echo $bestTiming['valid']; ?> |
                    Wins / Losses: <?php echo $bestTiming['wins']; ?> / <?php echo $bestTiming['losses']; ?>
                </div>
                <div class="timing-note">Qualified only with at least 10 valid trades.</div>
            <?php else: ?>
                <div class="timing-time text-gray">Insufficient data</div>
                <div class="timing-meta">No 1-hour window has at least 10 valid trades.</div>
            <?php endif; ?>
        </div>

        <div class="timing-card worst">
            <h3>&#128308; Worst Timing Window</h3>
            <?php if ($worstTiming): ?>
                <div class="timing-time text-red"><?php echo htmlspecialchars($worstTiming['label']); ?></div>
                <div class="timing-meta">
                    Normalized Edge: <strong class="text-red"><?php echo $worstTiming['norm']; ?>%</strong><br>
                    Raw Win Rate: <?php echo $worstTiming['raw']; ?>%<br>
                    Valid Trades: <?php echo $worstTiming['valid']; ?> |
                    Wins / Losses: <?php echo $worstTiming['wins']; ?> / <?php echo $worstTiming['losses']; ?>
                </div>
                <div class="timing-note">Qualified only with at least 10 valid trades.</div>
            <?php else: ?>
                <div class="timing-time text-gray">Insufficient data</div>
                <div class="timing-meta">No 1-hour window has at least 10 valid trades.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-container">
        <h3>&#9201; Time-of-Day Performance (IST)</h3>
        <p style="color:#94a3b8;font-size:13px;margin-top:0;">
            1-hour buckets | Best/Worst ranking requires &gt;=10 valid trades
        </p>
        <table>
            <thead>
                <tr>
                    <th>Time (IST)</th>
                    <th>Normalized Edge</th>
                    <th>Raw Win Rate</th>
                    <th>Valid Trades</th>
                    <th>Wins / Losses</th>
                    <th>Sample Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($timingRows)): ?>
                    <?php foreach ($timingRows as $tr): ?>
                        <?php
                            $timingColor = ($tr['norm'] >= 80)
                                ? '#a78bfa'
                                : (($tr['norm'] >= 70) ? '#10b981' : '#ef4444');
                        ?>
                        <tr>
                            <td><span class="session-badge"><?php echo htmlspecialchars($tr['label']); ?></span></td>
                            <td style="color:<?php echo $timingColor; ?>;font-weight:bold;font-size:16px;">
                                <?php echo $tr['norm']; ?>%
                            </td>
                            <td style="color:#94a3b8;"><?php echo $tr['raw']; ?>%</td>
                            <td><?php echo $tr['valid']; ?></td>
                            <td>
                                <span class="text-green"><?php echo $tr['wins']; ?></span> /
                                <span class="text-red"><?php echo $tr['losses']; ?></span>
                            </td>
                            <td>
                                <?php if ($tr['valid'] >= 10): ?>
                                    <span class="status-badge status-core">QUALIFIED</span>
                                <?php else: ?>
                                    <span class="status-badge" style="background:#475569;">LOW SAMPLE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;">No timing data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<div class="table-container">
        <h3>&#127757; Trading Session Edge (IST)</h3>
        <table>
            <thead><tr><th>Trading Session</th><th>Normalized Edge</th><th>Raw Win Rate</th><th>Valid Trades</th><th>Wins / Losses</th></tr></thead>
            <tbody>
                <?php
                $aiMatrixString .= "--- TRADING SESSION METRICS ---\n";
                if ($sessionResult && $sessionResult->num_rows > 0) {
                    while ($sRow = $sessionResult->fetch_assoc()) {
                        $sWins   = $sRow['session_wins'] ?? 0;
                        $sLosses = $sRow['session_losses'] ?? 0;
                        $sValid  = $sWins + $sLosses;
                        if ($sValid == 0) continue;
                        $sRawRate        = round(($sWins / $sValid) * 100, 2);
                        $sNormalizedEdge = round($sRow['normalized_edge'], 2);
                        $rateColor       = ($sNormalizedEdge >= 80) ? '#a78bfa' : (($sNormalizedEdge >= 70) ? '#10b981' : '#ef4444');
                        $aiMatrixString .= "Session: {$sRow['trading_session']} | Normalized: $sNormalizedEdge% | Trades: $sValid.\n";
                        echo "<tr>";
                        echo "<td><span class='session-badge'>" . htmlspecialchars($sRow['trading_session']) . "</span></td>";
                        echo "<td style='color:{$rateColor};font-weight:bold;font-size:16px;'>" . $sNormalizedEdge . "%</td>";
                        echo "<td style='color:#94a3b8;'>" . $sRawRate . "%</td>";
                        echo "<td>" . $sValid . "</td>";
                        echo "<td><span class='text-green'>" . $sWins . "</span> / <span class='text-red'>" . $sLosses . "</span></td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="table-container">
        <h3>&#128197; Global Day-of-the-Week Edge (Aggregated)</h3>
        <table>
            <thead><tr><th>Day of the Week</th><th>Normalized Edge</th><th>Raw Win Rate</th><th>Valid Trades</th><th>Wins / Losses</th></tr></thead>
            <tbody>
                <?php
                $aiMatrixString .= "--- GLOBAL DAY OF THE WEEK METRICS ---\n";
                if ($globalDayResult && $globalDayResult->num_rows > 0) {
                    while ($dRow = $globalDayResult->fetch_assoc()) {
                        $dWins   = $dRow['day_wins'] ?? 0;
                        $dLosses = $dRow['day_losses'] ?? 0;
                        $dValid  = $dWins + $dLosses;
                        if ($dValid == 0) continue;
                        $dRawRate        = round(($dWins / $dValid) * 100, 2);
                        $dNormalizedEdge = round($dRow['normalized_edge'], 2);
                        $rateColor       = ($dNormalizedEdge >= 80) ? '#a78bfa' : (($dNormalizedEdge >= 70) ? '#10b981' : '#ef4444');
                        $aiMatrixString .= "Day: {$dRow['day_name']} | Normalized: $dNormalizedEdge% | Trades: $dValid.\n";
                        echo "<tr>";
                        echo "<td><span class='day-badge'>" . htmlspecialchars($dRow['day_name']) . "</span></td>";
                        echo "<td style='color:{$rateColor};font-weight:bold;font-size:16px;'>" . $dNormalizedEdge . "%</td>";
                        echo "<td style='color:#94a3b8;'>" . $dRawRate . "%</td>";
                        echo "<td>" . $dValid . "</td>";
                        echo "<td><span class='text-green'>" . $dWins . "</span> / <span class='text-red'>" . $dLosses . "</span></td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="table-container">
        <h3>&#128197; Micro Day-of-the-Week Matrix (By Pair)</h3>
        <p style="color:#94a3b8;font-size:13px;margin-top:0;">Main % = Laplace Normalized Edge. Bottom = [Raw WR | W/L]</p>
        <table>
            <thead>
                <tr>
                    <th>Currency Pair</th>
                    <th class="matrix-cell">Monday</th><th class="matrix-cell">Tuesday</th><th class="matrix-cell">Wednesday</th>
                    <th class="matrix-cell">Thursday</th><th class="matrix-cell">Friday</th><th class="matrix-cell">Saturday</th><th class="matrix-cell">Sunday</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tradingDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                if (!empty($pairDayMatrix)) {
                    ksort($pairDayMatrix);
                    $aiMatrixString .= "--- MICRO DAY MATRIX ---\n";
                    foreach ($pairDayMatrix as $pair => $daysData) {
                        echo "<tr><td><span class='pair-badge'>" . htmlspecialchars($pair) . "</span></td>";
                        foreach ($tradingDays as $day) {
                            if (isset($daysData[$day])) {
                                $w  = $daysData[$day]['wins'];
                                $l  = $daysData[$day]['losses'];
                                $pv = $w + $l;
                                if ($pv > 0) {
                                    $rawRate  = round(($w / $pv) * 100, 2);
                                    $normRate = round((($w + 2) / ($pv + 4)) * 100, 2);
                                    $rateColor = ($normRate >= 75) ? '#10b981' : (($normRate >= 50) ? '#ffb703' : '#ef4444');
                                    if ($pv >= 5) $aiMatrixString .= "$pair on $day: Norm $normRate% ($w W / $l L). ";
                                    echo "<td class='matrix-cell'>";
                                    echo "<div class='matrix-edge' style='color:{$rateColor};'>{$normRate}%</div>";
                                    echo "<div class='matrix-sub'>{$rawRate}% | <span class='text-green'>{$w}</span>/<span class='text-red'>{$l}</span></div>";
                                    echo "</td>";
                                } else {
                                    echo "<td class='matrix-cell' style='color:#334155;'>-</td>";
                                }
                            } else {
                                echo "<td class='matrix-cell' style='color:#334155;'>-</td>";
                            }
                        }
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="table-container">
        <h3>&#127942; Overall Pair Performance Matrix (Ranked by Edge)</h3>
        <table>
            <thead><tr><th>Rank</th><th>Currency Pair</th><th>Status</th><th>Normalized Edge</th><th>Raw Win Rate</th><th>Valid Trades</th></tr></thead>
            <tbody>
                <?php
                if ($tableResult && $tableResult->num_rows > 0) {
                    $rank = 1;
                    while ($tRow = $tableResult->fetch_assoc()) {
                        $pWins   = $tRow['pair_wins'] ?? 0;
                        $pLosses = $tRow['pair_losses'] ?? 0;
                        $pValid  = $pWins + $pLosses;
                        if ($pValid == 0) continue;
                        $pRawRate        = round(($pWins / $pValid) * 100, 2);
                        $pNormalizedEdge = round($tRow['normalized_edge'], 2);
                        $rateColor       = ($pNormalizedEdge >= 80) ? '#a78bfa' : (($pNormalizedEdge >= 70) ? '#10b981' : '#ef4444');
                        $isCore      = in_array($tRow['pair_name'], $coreWatchlist);
                        $statusText  = $isCore ? 'CORE' : 'PAUSED';
                        $statusClass = $isCore ? 'status-core' : 'status-paused';
                        echo "<tr>";
                        echo "<td>#{$rank}</td>";
                        echo "<td><span class='pair-badge'>" . htmlspecialchars($tRow['pair_name']) . "</span></td>";
                        echo "<td><span class='status-badge {$statusClass}'>{$statusText}</span></td>";
                        echo "<td style='color:{$rateColor};font-weight:bold;font-size:16px;'>" . $pNormalizedEdge . "%</td>";
                        echo "<td style='color:#94a3b8;'>" . $pRawRate . "%</td>";
                        echo "<td>" . $pValid . "</td>";
                        echo "</tr>";
                        $rank++;
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

</div>

<script>
function formatInsights(text) {
    return text
        .replace(/### (.*)/g, "<h3>$1</h3>")
        .replace(/## (.*)/g, "<h2>$1</h2>")
        .replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>")
        .replace(/\* (.*)/g, "<li>$1</li>")
        .replace(/\n/g, "<br>")
        .replace(/(<li>.*<\/li>)/g, "<ul>$1</ul>");
}

document.addEventListener("DOMContentLoaded", function () {
    let rawStatsDump = `<?php echo addslashes(trim($aiMatrixString)); ?>\nOverall System Normalized WR: <?php echo $systemNormalizedEdge; ?>% based on <?php echo $validTrades; ?> valid trades.`;
    let runSpace = document.getElementById("aiRunSpace");

    fetch("", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "fetch_ai_insights=" + encodeURIComponent(rawStatsDump)
    })
    .then(res => res.text())
    .then(data => {
        console.log("RAW AI RESPONSE:", data);
        if (data.trim() !== "") {
            runSpace.innerHTML = formatInsights(data);
        } else {
            runSpace.innerHTML = "&#9888; API completed processing but returned an empty response.";
        }
    })
    .catch(err => {
        runSpace.innerHTML = "<span style='color:#ef4444;'>&#10060; Fetch error: " + err + "</span>";
    });

    // Monthly Chart
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    const chartLabels   = <?php echo json_encode($monthlyLabels); ?>;
    const chartWins     = <?php echo json_encode($monthlyWins); ?>;
    const chartLosses   = <?php echo json_encode($monthlyLosses); ?>;
    const chartNormEdge = <?php echo json_encode($monthlyNorm); ?>;

    if (chartLabels.length > 0) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [
                    { label: 'Wins', data: chartWins, backgroundColor: '#10b981', borderRadius: 4, order: 2 },
                    { label: 'Losses', data: chartLosses, backgroundColor: '#ef4444', borderRadius: 4, order: 3 },
                    {
                        label: 'Normalized Edge (%)', data: chartNormEdge, type: 'line',
                        borderColor: '#a78bfa', backgroundColor: '#a78bfa', borderWidth: 2,
                        pointRadius: 4, pointBackgroundColor: '#a78bfa', tension: 0.3,
                        yAxisID: 'yEdge', order: 1
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { stacked: true, ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
                    yCount: {
                        type: 'linear', display: true, position: 'left', stacked: true,
                        title: { display: true, text: 'Total Trades', color: '#94a3b8' },
                        ticks: { color: '#94a3b8' }, grid: { color: '#334155' }
                    },
                    yEdge: {
                        type: 'linear', display: true, position: 'right',
                        title: { display: true, text: 'Norm Edge (%)', color: '#a78bfa' },
                        min: 0, max: 100,
                        ticks: { color: '#a78bfa', callback: v => v + '%' },
                        grid: { drawOnChartArea: false }
                    }
                },
                plugins: {
                    legend: { labels: { color: '#f8fafc', font: { size: 13 } } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                let label = context.dataset.label + ': ';
                                label += context.dataset.type === 'line' ? context.parsed.y + '%' : context.parsed.y;
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>
</body>
</html>
