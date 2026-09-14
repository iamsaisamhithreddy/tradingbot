<?php
session_start();
require 'db.php';

// Force error reporting to screen
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CHUNK SETTINGS
$chunk_size = 100;

if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    unset($_SESSION['comp_offset']);
    unset($_SESSION['variance_records']);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (!isset($_SESSION['comp_offset'])) {
    $_SESSION['comp_offset'] = 0;
    $_SESSION['variance_records'] = [];
}

$offset = (int)$_SESSION['comp_offset'];

// Fetch the total row counts for prediction data
$total_res = $conn->query("SELECT COUNT(*) as total FROM prediction_trade_data");
$total_rows = $total_res->fetch_assoc()['total'];
$is_finished = ($offset >= $total_rows);

if (!$is_finished) {
    // Read only a small slice of live data at a time to keep RAM footprint near zero
    $sql = "SELECT raw_trade_id, pair_name, trade_direction, price_target FROM prediction_trade_data ORDER BY raw_trade_id DESC LIMIT " . $chunk_size . " OFFSET " . $offset;
    $live_res = $conn->query($sql);
    
    if ($live_res && $live_res->num_rows > 0) {
        while ($p = $live_res->fetch_assoc()) {
            $rid = $p['raw_trade_id'];
            
            // Check matching entry individually inside backup table
            $b_sql = "SELECT price_target FROM prediction_trade_data_backup WHERE raw_trade_id = " . (int)$rid . " LIMIT 1";
            $back_res = $conn->query($b_sql);
            
            if ($back_res && $back_res->num_rows > 0) {
                $b = $back_res->fetch_assoc();
                $old_p = $b['price_target'];
                $new_p = $p['price_target'];
                
                // If there's a difference, record it to session array cache
                if ($old_p != $new_p || ($old_p === null && $new_p !== null) || ($old_p !== null && $new_p === null)) {
                    $_SESSION['variance_records'][] = [
                        'id' => $rid,
                        'pair' => $p['pair_name'],
                        'dir' => $p['trade_direction'],
                        'old' => $old_p,
                        'new' => $new_p
                    ];
                }
            }
        }
    }
    
    // Advance tracking offset and trigger loop reload
    $_SESSION['comp_offset'] += $chunk_size;
    header("Refresh: 1; url=" . strtok($_SERVER["REQUEST_URI"], '?'));
}

$display_records = $_SESSION['variance_records'];
?>
<!DOCTYPE html>
<html>
<head>
<title>Price Target Comparison Report</title>
<style>
body { font-family: Arial, sans-serif; margin:30px; background-color: #f4f7f6; }
.container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
h2 { text-align:center; color: #2c3e50; margin-bottom:10px; }
.stats { text-align: center; color: #7f8c8d; margin-bottom: 20px; font-size: 15px; }
table { border-collapse:collapse; width:100%; font-size:14px; margin-top: 10px; }
th, td { border:1px solid #e2e8f0; padding:12px 15px; text-align:center; }
th { background-color:#2d3748; color:white; font-weight: 600; }
tr:nth-child(even){ background-color:#f8fafc; }
.diff-cell { font-weight: bold; color: #e53e3e; }
.price-val { font-family: monospace; font-size: 15px; }
.reset-btn { display: inline-block; padding: 8px 15px; background: #d62728; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; margin-bottom: 20px; }
.no-change { padding: 20px; text-align: center; color: #2f855a; background: #f0fff4; border: 1px solid #c6f6d5; border-radius: 5px; font-size: 16px; font-weight: bold; }
</style>
</head>
<body>

<div class="container">
    <h2>Price Target Variance Report</h2>
    
    <div class="stats">
        <?php if (!$is_finished): ?>
            ⏳ Analyzed <strong><?php echo min($offset + $chunk_size, $total_rows); ?></strong> out of <strong><?php echo $total_rows; ?></strong> database records... (Streaming logs safely)<br>
            <span style="color: #3182ce; font-weight: bold;">Current Changes Found: <?php echo count($display_records); ?></span>
        <?php else: ?>
            ✅ <strong>Analysis complete!</strong> Scanned all <?php echo $total_rows; ?> records seamlessly.
        <?php endif; ?>
    </div>
    
    <div style="text-align: right;">
        <a href="?reset=1" class="reset-btn">Reset & Scan Again</a>
    </div>

    <?php if (count($display_records) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Raw Trade ID</th>
                <th>Pair Name</th>
                <th>Direction</th>
                <th>Old Price Target (Backup)</th>
                <th>New Price Target (Live)</th>
                <th>Price Shift Variance</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($display_records as $row): ?>
            <?php 
                $old_display = ($row['old'] !== null) ? number_format(floatval($row['old']), 5) : "N/A";
                $new_display = ($row['new'] !== null) ? number_format(floatval($row['new']), 5) : "N/A";
                
                if ($row['old'] !== null && $row['new'] !== null) {
                    $diff = floatval($row['new']) - floatval($row['old']);
                    $diff_display = ($diff > 0 ? "+" : "") . number_format($diff, 5);
                } else {
                    $diff_display = "Shifted State";
                }
            ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($row['id']); ?></strong></td>
                <td><?php echo htmlspecialchars($row['pair']); ?></td>
                <td><?php echo htmlspecialchars($row['dir']); ?></td>
                <td class="price-val"><?php echo $old_display; ?></td>
                <td class="price-val" style="color: #2b6cb0; font-weight: bold;"><?php echo $new_display; ?></td>
                <td class="diff-cell"><?php echo $diff_display; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php elseif($is_finished): ?>
        <div class="no-change">
            🎉 Process Complete! No discrepancies found between live targets and backup states.
        </div>
    <?php endif; ?>
</div>

</body>
</html>
<?php $conn->close(); ?>