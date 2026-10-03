<?php
// Initialize Variables to prevent undefined errors
$data = []; $headers = []; $filtered_data = []; $unique_assets = []; $asset_stats = [];
$total_trades = 0; $wins = 0; $losses = 0; $ties = 0;
$gross_profit = 0; $gross_loss = 0; $net_profit = 0; $total_wagered = 0;
$peak_balance = 0; $max_drawdown = 0; $current_balance = 0;
$current_win_streak = 0; $max_win_streak = 0;
$current_loss_streak = 0; $max_loss_streak = 0;
$duration_stats = []; $hourly_stats = array_fill(0, 24, ['net'=>0]); 
$win_rate = 0; $profit_factor = 0; $avg_win = 0; $avg_loss = 0; $avg_trade_size = 0; $expectancy = 0;
$equity_labels = []; $equity_data = []; $pair_labels = []; $pair_profits = []; $pair_colors = [];
$hourly_labels = []; $hourly_data = []; $hourly_colors = [];

$f_start = $_SESSION['filters']['start_date'] ?? '';
$f_end = $_SESSION['filters']['end_date'] ?? '';
$f_asset = $_SESSION['filters']['asset'] ?? 'All';
$f_market = $_SESSION['filters']['market'] ?? 'All';

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

    // Handle CSV Export
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
        global $success_msg;
        $success_msg = "Journal remarks saved successfully!";
    }

    // Strategy Tagging Engine
    uasort($data, function($a, $b) {
        return strtotime($a['Open time']) <=> strtotime($b['Open time']);
    });

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

        if ($last_win_income > 0 && abs($amount - $last_win_income) <= 1.5) {
            $compound_step++;
            $row['Tags'][] = "Compound Step " . $compound_step;
        } else {
            $compound_step = 0;
        }

        $last_win_income = ($net > 0) ? $income : 0;

        if (!isset($last_trade_by_pair[$asset])) {
            $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => 0];
        } else {
            $prev = $last_trade_by_pair[$asset];
            $time_diff_mins = ($open_time - $prev['open_time']) / 60;
            
            if ($time_diff_mins == 0) {
                $last_trade_by_pair[$asset]['net'] += $net; 
            } else {
                if ($prev['net'] < 0 && $time_diff_mins <= 15) {
                    $step = $prev['mtg_step'] + 1;
                    $row['Tags'][] = "MTG Step " . $step;
                    $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => $step];
                } else {
                    $last_trade_by_pair[$asset] = ['open_time' => $open_time, 'net' => $net, 'mtg_step' => 0];
                }
            }
        }
    }
    unset($row); 

    // Apply Filters & Stats
    foreach ($data as $index => $row) {
        $asset = $row['Info'] ?? 'Unknown';
        $unique_assets[$asset] = true;
        
        $open_timestamp = isset($row['Open time']) ? strtotime($row['Open time']) : 0;
        $close_timestamp = isset($row['Close Time']) ? strtotime($row['Close Time']) : 0;
        $date_only = date('Y-m-d', $open_timestamp);
        $hour_only = (int)date('H', $open_timestamp);
        $is_otc = stripos($asset, 'OTC') !== false;
        
        if ($f_start && $date_only < $f_start) continue;
        if ($f_end && $date_only > $f_end) continue;
        if ($f_asset !== 'All' && $asset !== $f_asset) continue;
        if ($f_market === 'OTC' && !$is_otc) continue;
        if ($f_market === 'Regular' && $is_otc) continue;

        $filtered_data[$index] = $row; 

        $amount = isset($row['Amount']) ? (float)$row['Amount'] : 0;
        $income = isset($row['Income']) ? (float)$row['Income'] : 0;
        $net = $income - $amount;
        
        $net_profit += $net;
        $total_wagered += $amount;
        $total_trades++;
        $hourly_stats[$hour_only]['net'] += $net;

        if ($open_timestamp && $close_timestamp) {
            $duration_mins = round(($close_timestamp - $open_timestamp) / 60);
            if ($duration_mins == 4) $duration_mins = 5;
            $dur_key = $duration_mins . ' min';
            if (!isset($duration_stats[$dur_key])) $duration_stats[$dur_key] = ['trades'=>0, 'wins'=>0];
            $duration_stats[$dur_key]['trades']++;
            if ($net > 0) $duration_stats[$dur_key]['wins']++;
        }

        $current_balance += $net;
        if ($current_balance > $peak_balance) $peak_balance = $current_balance;
        $drawdown = $peak_balance - $current_balance;
        if ($drawdown > $max_drawdown) $max_drawdown = $drawdown;

        if (!isset($asset_stats[$asset])) $asset_stats[$asset] = ['trades'=>0, 'wins'=>0, 'losses'=>0, 'net'=>0];
        $asset_stats[$asset]['trades']++;
        $asset_stats[$asset]['net'] += $net;

        if ($net > 0) {
            $wins++; $gross_profit += $net; $asset_stats[$asset]['wins']++;
            $current_win_streak++; $current_loss_streak = 0;
            if ($current_win_streak > $max_win_streak) $max_win_streak = $current_win_streak;
        } elseif ($net < 0) {
            $losses++; $gross_loss += abs($net); $asset_stats[$asset]['losses']++;
            $current_loss_streak++; $current_win_streak = 0;
            if ($current_loss_streak > $max_loss_streak) $max_loss_streak = $current_loss_streak;
        } else {
            $ties++; $current_win_streak = 0; $current_loss_streak = 0;
        }
    }

    $win_rate = ($wins + $losses) > 0 ? ($wins / ($wins + $losses)) : 0;
    $profit_factor = $gross_loss > 0 ? round($gross_profit / $gross_loss, 2) : ($gross_profit > 0 ? '∞' : 0);
    $avg_win = $wins > 0 ? ($gross_profit / $wins) : 0;
    $avg_loss = $losses > 0 ? ($gross_loss / $losses) : 0;
    $avg_trade_size = $total_trades > 0 ? ($total_wagered / $total_trades) : 0;
    $loss_rate = 1 - $win_rate;
    $expectancy = ($win_rate * $avg_win) - ($loss_rate * $avg_loss);

    ksort($unique_assets); 

    foreach ($filtered_data as $row) {
        $net = (float)$row['Income'] - (float)$row['Amount'];
        $cum_profit += $net;
        $equity_labels[] = date('M d, H:i', strtotime($row['Open time']));
        $equity_data[] = round($cum_profit, 2);
    }

    uasort($asset_stats, function($a, $b) { return $b['net'] <=> $a['net']; });
    foreach ($asset_stats as $asset => $st) {
        $pair_labels[] = $asset;
        $pair_profits[] = round($st['net'], 2);
        $pair_colors[] = $st['net'] >= 0 ? 'rgba(34, 197, 94, 0.7)' : 'rgba(239, 68, 68, 0.7)';
    }

    for($i=0; $i<24; $i++) {
        $hourly_labels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
        $hourly_data[] = round($hourly_stats[$i]['net'], 2);
        $hourly_colors[] = $hourly_stats[$i]['net'] >= 0 ? 'rgba(59, 130, 246, 0.7)' : 'rgba(239, 68, 68, 0.7)';
    }
}