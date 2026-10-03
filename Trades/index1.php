<?php
session_start();

// Directory for storing CSVs securely
$upload_dir = __DIR__ . '/uploads/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

$success_msg = ""; $error_msg = "";

// Initialize Filter Sessions
if (!isset($_SESSION['filters'])) {
    $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
}

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Upload File
    if ($action === 'upload' && isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'csv') {
            $filename = time() . '_' . basename($file['name']);
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $_SESSION['current_file'] = $target_path;
                $_SESSION['display_name'] = basename($file['name']);
                $success_msg = "File uploaded successfully!";
            } else {
                $error_msg = "Upload failed. Check folder permissions.";
            }
        } else {
            $error_msg = "Invalid file. Please upload a valid .csv file.";
        }
    }
    // 2. Reset/Close File
    elseif ($action === 'reset') {
        unset($_SESSION['current_file']);
        unset($_SESSION['display_name']);
        $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
    // 3. Apply Filters
    elseif ($action === 'filter') {
        $_SESSION['filters']['start_date'] = $_POST['start_date'] ?? '';
        $_SESSION['filters']['end_date'] = $_POST['end_date'] ?? '';
        $_SESSION['filters']['asset'] = $_POST['asset'] ?? 'All';
        $_SESSION['filters']['market'] = $_POST['market'] ?? 'All';
    }
    // 4. Clear Filters
    elseif ($action === 'clear_filters') {
        $_SESSION['filters'] = ['start_date' => '', 'end_date' => '', 'asset' => 'All', 'market' => 'All'];
    }
}

// Variables for Data
$data = [];
$headers = [];
$filtered_data = [];
$unique_assets = [];
$asset_stats = [];

// Advanced Statistics Variables
$total_trades = 0; $wins = 0; $losses = 0; $ties = 0;
$gross_profit = 0; $gross_loss = 0; 
$net_profit = 0; $total_wagered = 0;
$peak_balance = 0; $max_drawdown = 0; $current_balance = 0;
$current_win_streak = 0; $max_win_streak = 0;
$current_loss_streak = 0; $max_loss_streak = 0;

// Duration Analytics
$duration_stats = []; 

// Read and Process CSV if loaded
if (isset($_SESSION['current_file']) && file_exists($_SESSION['current_file'])) {
    $file_path = $_SESSION['current_file'];
    
    // Read the File
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ",");
        if (!in_array('Remarks', $headers)) $headers[] = 'Remarks';

        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (empty(array_filter($row))) continue;
            while (count($row) < count($headers)) $row[] = '';
            $data[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }
        fclose($handle);
    }

    // Handle Export Request
    if (isset($_GET['export']) && $_GET['export'] == '1') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="journal_export_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        foreach ($data as $row) fputcsv($output, $row);
        fclose($output);
        exit;
    }

    // Handle Save Remarks
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_remarks') {
        foreach ($_POST['remarks'] as $original_index => $remark) {
            if (isset($data[$original_index])) {
                $data[$original_index]['Remarks'] = htmlspecialchars($remark);
            }
        }
        $handle = fopen($file_path, 'w');
        fputcsv($handle, $headers);
        foreach ($data as $row) fputcsv($handle, array_values($row));
        fclose($handle);
        $success_msg = "Journal remarks saved successfully!";
    }

    // --- STEP 1: SORT CHRONOLOGICALLY ---
    // (Oldest to Newest required for accurate Strategy Tagging)
    uasort($data, function($a, $b) {
        return strtotime($a['Open time']) <=> strtotime($b['Open time']);
    });

    // --- STEP 2: STRATEGY TAGGING ENGINE ---
    $last_trade_by_pair = [];
    $last_win_income = 0;
    $compound_step = 0;

    foreach ($data as $index => &$row) {
        $asset = $row['Info'] ?? 'Unknown';
        $open_time = isset($row['Open time']) ? strtotime($row['Open time']) : 0;
        $amount = isset($row['Amount']) ? (float)$row['Amount'] : 0;
        $income = isset($row['Income']) ? (float)$row['Income'] : 0;
        $net = $income - $amount;
        
        $row['Tags'] = [];

        // 1. Compounding Check (Did they wager the exact payout of the last win?)
        // Allowed $1.5 variance for dropped decimals (e.g. payout 347.76 -> wager 347)
        if ($last_win_income > 0 && abs($amount - $last_win_income) <= 1.5) {
            $compound_step++;
            $row['Tags'][] = "Compound Step " . $compound_step;
        } else {
            $compound_step = 0; // Chain broken
        }

        // Update last win income state
        if ($net > 0) {
            $last_win_income = $income;
        } else {
            $last_win_income = 0; // Break chain on loss or tie
        }

        // 2. Martingale Check
        if (!isset($last_trade_by_pair[$asset])) {
            $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => 0];
        } else {
            $prev = $last_trade_by_pair[$asset];
            $time_diff_mins = ($open_time - $prev['open_time']) / 60;
            
            if ($time_diff_mins == 0) {
                // Trades opened at the exact same second (Split Wager) - Combine net logically
                $last_trade_by_pair[$asset]['net'] += $net; 
            } else {
                // If previous trade was a loss AND current is within 15 minutes of it
                if ($prev['net'] < 0 && $time_diff_mins <= 15) {
                    $step = $prev['mtg_step'] + 1;
                    $row['Tags'][] = "MTG Step " . $step;
                    $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => $step];
                } else {
                    // Reset martingale counter for this asset
                    $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => 0];
                }
            }
        }
    }
    unset($row); // Break reference

    // --- STEP 3: APPLY FILTERS & CALCULATE STATS ---
    $f_start = $_SESSION['filters']['start_date'];
    $f_end = $_SESSION['filters']['end_date'];
    $f_asset = $_SESSION['filters']['asset'];
    $f_market = $_SESSION['filters']['market'];

    foreach ($data as $index => $row) {
        $asset = $row['Info'] ?? 'Unknown';
        $unique_assets[$asset] = true;
        
        $open_timestamp = isset($row['Open time']) ? strtotime($row['Open time']) : 0;
        $close_timestamp = isset($row['Close Time']) ? strtotime($row['Close Time']) : 0;
        $date_only = date('Y-m-d', $open_timestamp);
        $is_otc = stripos($asset, 'OTC') !== false;
        
        // Apply Filters
        if ($f_start && $date_only < $f_start) continue;
        if ($f_end && $date_only > $f_end) continue;
        if ($f_asset !== 'All' && $asset !== $f_asset) continue;
        if ($f_market === 'OTC' && !$is_otc) continue;
        if ($f_market === 'Regular' && $is_otc) continue;

        // Maintain original index for saving remarks properly
        $filtered_data[$index] = $row; 

        // Financial Math
        $amount = isset($row['Amount']) ? (float)$row['Amount'] : 0;
        $income = isset($row['Income']) ? (float)$row['Income'] : 0;
        $net = $income - $amount;
        $net_profit += $net;
        $total_wagered += $amount;
        $total_trades++;

        // Duration Math (in minutes) - Merging 4 mins to 5 mins
        if ($open_timestamp && $close_timestamp) {
            $duration_mins = round(($close_timestamp - $open_timestamp) / 60);
            if ($duration_mins == 4) $duration_mins = 5;
            
            $dur_key = $duration_mins . ' min';
            if (!isset($duration_stats[$dur_key])) $duration_stats[$dur_key] = ['trades'=>0, 'wins'=>0];
            $duration_stats[$dur_key]['trades']++;
            if ($net > 0) $duration_stats[$dur_key]['wins']++;
        }

        // Peak-to-Valley Drawdown Calculation
        $current_balance += $net;
        if ($current_balance > $peak_balance) {
            $peak_balance = $current_balance;
        }
        $drawdown = $peak_balance - $current_balance;
        if ($drawdown > $max_drawdown) {
            $max_drawdown = $drawdown;
        }

        // Asset Stats
        if (!isset($asset_stats[$asset])) {
            $asset_stats[$asset] = ['trades'=>0, 'wins'=>0, 'losses'=>0, 'net'=>0];
        }
        $asset_stats[$asset]['trades']++;
        $asset_stats[$asset]['net'] += $net;

        // Streaks & Win/Loss Math
        if ($net > 0) {
            $wins++;
            $gross_profit += $net;
            $asset_stats[$asset]['wins']++;
            
            $current_win_streak++;
            $current_loss_streak = 0;
            if ($current_win_streak > $max_win_streak) $max_win_streak = $current_win_streak;
            
        } elseif ($net < 0) {
            $losses++;
            $gross_loss += abs($net);
            $asset_stats[$asset]['losses']++;
            
            $current_loss_streak++;
            $current_win_streak = 0;
            if ($current_loss_streak > $max_loss_streak) $max_loss_streak = $current_loss_streak;
            
        } else {
            $ties++;
            $current_win_streak = 0;
            $current_loss_streak = 0;
        }
    }

    // Advanced Metrics Formulas
    $win_rate = ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) : 0;
    $profit_factor = $gross_loss > 0 ? round($gross_profit / $gross_loss, 2) : ($gross_profit > 0 ? '∞' : 0);
    $avg_win = $wins > 0 ? ($gross_profit / $wins) : 0;
    $avg_loss = $losses > 0 ? ($gross_loss / $losses) : 0;
    $avg_trade_size = $total_trades > 0 ? ($total_wagered / $total_trades) : 0;
    
    // Expectancy
    $loss_rate = 1 - $win_rate;
    $expectancy = ($win_rate * $avg_win) - ($loss_rate * $avg_loss);

    ksort($unique_assets); 

    // Prepare Chart Data
    $equity_labels = []; $equity_data = []; $cum_profit = 0;
    foreach ($filtered_data as $row) {
        $net = (float)$row['Income'] - (float)$row['Amount'];
        $cum_profit += $net;
        $equity_labels[] = date('M d, H:i', strtotime($row['Open time']));
        $equity_data[] = round($cum_profit, 2);
    }

    uasort($asset_stats, function($a, $b) { return $b['net'] <=> $a['net']; });
    $pair_labels = []; $pair_profits = []; $pair_colors = [];
    foreach ($asset_stats as $asset => $st) {
        $pair_labels[] = $asset;
        $pair_profits[] = round($st['net'], 2);
        $pair_colors[] = $st['net'] >= 0 ? 'rgba(34, 197, 94, 0.8)' : 'rgba(239, 68, 68, 0.8)';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pro Trade Journal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-slate-100 text-slate-800 p-4 md:p-8 min-h-screen">

    <div class="max-w-7xl mx-auto space-y-6">
        
        <div class="flex flex-col md:flex-row justify-between items-center bg-white p-5 rounded-xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-slate-900">📈 Pro Trade Journal</h1>
                <?php if (isset($_SESSION['current_file'])): ?>
                    <p class="text-sm text-slate-500 mt-1">Active File: <span class="font-semibold text-blue-600"><?= htmlspecialchars($_SESSION['display_name']) ?></span></p>
                <?php endif; ?>
            </div>
            
            <?php if (isset($_SESSION['current_file'])): ?>
                <div class="flex gap-3 mt-4 md:mt-0">
                    <a href="?export=1" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-5 rounded-lg text-sm transition text-center leading-loose">
                        ↓ Export CSV
                    </a>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="action" value="reset">
                        <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-semibold py-2 px-5 rounded-lg text-sm transition">
                            Close File
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($success_msg): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm"><p><?= $success_msg ?></p></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm"><p><?= $error_msg ?></p></div>
        <?php endif; ?>

        <?php if (!isset($_SESSION['current_file'])): ?>
            <div class="bg-white rounded-xl shadow-sm p-12 mt-10 max-w-2xl mx-auto text-center border border-slate-200">
                <svg class="w-16 h-16 text-blue-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                <h2 class="text-2xl font-bold mb-2 text-slate-800">Upload Trading CSV</h2>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col items-center mt-6">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="csv_file" accept=".csv" required class="block w-full text-sm text-slate-500 file:mr-4 file:py-3 file:px-6 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 mb-6 cursor-pointer border border-slate-200 rounded-full p-1"/>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-10 rounded-full shadow-md transition w-full md:w-auto">
                        Analyze Portfolio
                    </button>
                </form>
            </div>

        <?php else: ?>
            <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                <form method="POST" class="flex flex-col md:flex-row gap-4 items-end">
                    <input type="hidden" name="action" value="filter">
                    <div class="w-full md:w-1/5">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Start Date</label>
                        <input type="date" name="start_date" value="<?= $f_start ?>" class="w-full border-slate-300 rounded-md shadow-sm border p-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div class="w-full md:w-1/5">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">End Date</label>
                        <input type="date" name="end_date" value="<?= $f_end ?>" class="w-full border-slate-300 rounded-md shadow-sm border p-2 focus:ring-blue-500 text-sm">
                    </div>
                    <div class="w-full md:w-1/5">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Asset Pair</label>
                        <select name="asset" class="w-full border-slate-300 rounded-md shadow-sm border p-2 focus:ring-blue-500 text-sm">
                            <option value="All">All Assets</option>
                            <?php foreach (array_keys($unique_assets) as $ast): ?>
                                <option value="<?= $ast ?>" <?= $f_asset === $ast ? 'selected' : '' ?>><?= $ast ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="w-full md:w-1/5">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Market Type</label>
                        <select name="market" class="w-full border-slate-300 rounded-md shadow-sm border p-2 focus:ring-blue-500 text-sm">
                            <option value="All" <?= $f_market === 'All' ? 'selected' : '' ?>>All Markets</option>
                            <option value="Regular" <?= $f_market === 'Regular' ? 'selected' : '' ?>>Regular Only</option>
                            <option value="OTC" <?= $f_market === 'OTC' ? 'selected' : '' ?>>OTC Only</option>
                        </select>
                    </div>
                    <div class="w-full md:w-1/5 flex gap-2">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-md shadow-sm transition text-sm">Filter</button>
                        <button type="submit" onclick="this.form.action.value='clear_filters';" class="w-full bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold py-2 rounded-md shadow-sm transition text-sm">Clear</button>
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Total Trades</div>
                    <div class="text-3xl font-black text-slate-800 mt-1"><?= $total_trades ?></div>
                    <div class="text-xs text-slate-500 mt-1">W: <?= $wins ?> | L: <?= $losses ?> | T: <?= $ties ?></div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Win Rate</div>
                    <div class="text-3xl font-black text-blue-600 mt-1"><?= round($win_rate * 100, 2) ?>%</div>
                    <div class="text-xs text-slate-500 mt-1">Excludes ties/break-evens</div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Net Profit</div>
                    <div class="text-3xl font-black mt-1 <?= $net_profit >= 0 ? 'text-green-600' : 'text-red-600' ?>">₹<?= number_format($net_profit, 2) ?></div>
                    <div class="text-xs text-slate-500 mt-1">After deducting wagers</div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Profit Factor</div>
                    <div class="text-3xl font-black text-purple-600 mt-1"><?= $profit_factor ?></div>
                    <div class="text-xs text-slate-500 mt-1">Gross Profit / Gross Loss</div>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 text-center">
                    <span class="block text-xs font-bold text-slate-400 uppercase">Trade Expectancy</span>
                    <span class="block text-xl font-bold <?= $expectancy >= 0 ? 'text-green-600' : 'text-red-600' ?>">₹<?= number_format($expectancy, 2) ?></span>
                    <span class="block text-[10px] text-slate-400">Avg made per trade</span>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 text-center">
                    <span class="block text-xs font-bold text-slate-400 uppercase">Max Drawdown</span>
                    <span class="block text-xl font-bold text-red-600">-₹<?= number_format($max_drawdown, 2) ?></span>
                    <span class="block text-[10px] text-slate-400">Peak-to-Valley Drop</span>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 text-center">
                    <span class="block text-xs font-bold text-slate-400 uppercase">Max Streaks</span>
                    <span class="block text-xl font-bold text-slate-700"><span class="text-green-500"><?= $max_win_streak ?>W</span> / <span class="text-red-500"><?= $max_loss_streak ?>L</span></span>
                    <span class="block text-[10px] text-slate-400">Consecutive wins/losses</span>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 text-center">
                    <span class="block text-xs font-bold text-slate-400 uppercase">Avg Trade Size</span>
                    <span class="block text-xl font-bold text-blue-600">₹<?= number_format($avg_trade_size, 2) ?></span>
                    <span class="block text-[10px] text-slate-400">Average risked amount</span>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 text-center">
                    <span class="block text-xs font-bold text-slate-400 uppercase">Win / Loss Ratio</span>
                    <span class="block text-xl font-bold text-slate-700">₹<?= number_format($avg_win, 0) ?> / ₹<?= number_format($avg_loss, 0) ?></span>
                    <span class="block text-[10px] text-slate-400">Average Win vs Avg Loss</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 md:col-span-2">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">Equity Curve (Drawdown visualizer)</h3>
                    <div class="relative h-64 w-full">
                        <canvas id="equityChart"></canvas>
                    </div>
                </div>
                <div class="bg-white p-5 rounded-xl shadow-sm border border-slate-200 md:col-span-1">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">Performance by Expiry Time</h3>
                    <div class="overflow-y-auto max-h-64">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 sticky top-0">
                                <tr>
                                    <th class="py-2 text-left text-slate-600 font-semibold">Duration</th>
                                    <th class="py-2 text-left text-slate-600 font-semibold">Trades</th>
                                    <th class="py-2 text-left text-slate-600 font-semibold">Win Rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($duration_stats as $dur => $st): ?>
                                    <tr>
                                        <td class="py-2 font-medium text-slate-800"><?= $dur ?></td>
                                        <td class="py-2 text-slate-600"><?= $st['trades'] ?></td>
                                        <td class="py-2 font-bold <?= ($st['wins']/$st['trades']) >= 0.5 ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= round(($st['wins']/$st['trades'])*100, 1) ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_remarks">
                    <div class="flex flex-col md:flex-row justify-between items-center p-4 border-b border-slate-200 bg-slate-50 gap-4">
                        <h2 class="text-md font-bold text-slate-800 whitespace-nowrap">Trade Psychology Log</h2>
                        
                        <div class="relative w-full md:w-96">
                            <input type="text" id="tableSearch" onkeyup="filterTable()" placeholder="Search tags, assets, or remarks..." 
                                class="w-full border-slate-300 rounded-md shadow-sm border pl-10 p-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <svg class="w-5 h-5 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>

                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded shadow-sm transition text-sm whitespace-nowrap">
                            Save Remarks
                        </button>
                    </div>
                    
                    <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                        <table class="min-w-full divide-y divide-slate-200" id="tradeTable">
                            <thead class="bg-slate-50 sticky top-0 z-10 shadow-sm">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Time</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Asset</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Income</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">ROI</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase">Result</th>
                                    <th class="px-4 py-3 text-left text-xs font-bold text-slate-500 uppercase w-1/3">Remarks</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-200">
                                <?php 
                                // Reverse the data so newest trades appear at the top of the table
                                $display_data = array_reverse($filtered_data, true);
                                foreach ($display_data as $index => $row): ?>
                                    <?php 
                                        $open_date = isset($row['Open time']) ? date('M j, Y', strtotime($row['Open time'])) : 'N/A';
                                        $open_time_only = isset($row['Open time']) ? date('h:i:s A', strtotime($row['Open time'])) : 'N/A';
                                        $info = $row['Info'] ?? 'N/A';
                                        $tags = $row['Tags'] ?? [];
                                        
                                        $amount = isset($row['Amount']) ? (float)$row['Amount'] : 0;
                                        $income = isset($row['Income']) ? (float)$row['Income'] : 0;
                                        $remarks = $row['Remarks'] ?? '';
                                        
                                        $is_win = $income > $amount;
                                        $is_loss = $income < $amount;
                                        
                                        // ROI Calculation
                                        $roi_val = $amount > 0 ? (($income - $amount) / $amount) * 100 : 0;
                                        $roi_formatted = ($roi_val > 0 ? '+' : '') . round($roi_val, 2) . '%';
                                        $roi_badge_color = $roi_val > 0 ? 'text-emerald-700 bg-emerald-100' : ($roi_val < 0 ? 'text-rose-700 bg-rose-100' : 'text-slate-700 bg-slate-100');

                                        $result_text = $is_win ? 'Win' : ($is_loss ? 'Loss' : 'Tie');
                                        $result_color = $is_win ? 'text-green-700 bg-green-100 border-green-200' : ($is_loss ? 'text-red-700 bg-red-100 border-red-200' : 'text-slate-700 bg-slate-100 border-slate-200');
                                    ?>
                                    <tr class="hover:bg-slate-50 transition border-l-4 <?= $is_win ? 'border-l-green-500' : ($is_loss ? 'border-l-red-500' : 'border-l-transparent') ?>">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-700"><?= $open_date ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-700 font-medium"><?= $open_time_only ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            <div class="text-sm font-bold text-slate-800"><?= $info ?></div>
                                            <?php foreach($tags as $tag): 
                                                $tag_color = strpos($tag, 'MTG') !== false ? 'bg-orange-100 text-orange-800 border border-orange-200' : 'bg-purple-100 text-purple-800 border border-purple-200';
                                            ?>
                                                <span class="inline-block mt-1 px-1.5 py-0.5 text-[10px] font-bold rounded <?= $tag_color ?>"><?= $tag ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-600">₹<?= number_format($amount, 2) ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-600">₹<?= number_format($income, 2) ?></td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            <span class="px-2 py-0.5 inline-flex text-xs font-bold rounded-md <?= $roi_badge_color ?>">
                                                <?= $roi_formatted ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            <span class="px-2 py-0.5 inline-flex text-xs font-bold rounded-md border <?= $result_color ?>">
                                                <?= $result_text ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-500">
                                            <input type="text" name="remarks[<?= $index ?>]" value="<?= htmlspecialchars($remarks) ?>" 
                                                class="block w-full rounded border-slate-300 bg-slate-50 focus:bg-white focus:border-blue-500 focus:ring-blue-500 sm:text-sm border p-2 transition" 
                                                placeholder="What were you thinking?">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>

            <script>
                // JavaScript for Live Search Filter in Table (Now easily searches MTG/Compound tags too!)
                function filterTable() {
                    const input = document.getElementById("tableSearch");
                    const filter = input.value.toLowerCase();
                    const table = document.getElementById("tradeTable");
                    const tr = table.getElementsByTagName("tr");

                    for (let i = 1; i < tr.length; i++) {
                        let rowText = tr[i].innerText.toLowerCase();
                        let inputs = tr[i].getElementsByTagName("input");
                        if(inputs.length > 0) {
                            rowText += " " + inputs[0].value.toLowerCase();
                        }
                        
                        if (rowText.indexOf(filter) > -1) {
                            tr[i].style.display = "";
                        } else {
                            tr[i].style.display = "none";
                        }
                    }
                }

                // Equity Curve Chart rendering
                const ctxEquity = document.getElementById('equityChart').getContext('2d');
                new Chart(ctxEquity, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($equity_labels) ?>,
                        datasets: [{
                            label: 'Cumulative Net Profit (₹)',
                            data: <?= json_encode($equity_data) ?>,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.1,
                            pointRadius: 0, 
                            pointHoverRadius: 6
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { x: { ticks: { maxTicksLimit: 8 } } },
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                    }
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>