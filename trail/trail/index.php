<?php 
include 'db.php'; 

// 1. DATABASE UPDATE LOGIC (Handles updates from plot.php)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_result'])) {
    $trade_id = $_POST['trade_id'];
    $new_result = $_POST['update_result']; // 'win', 'loss', or 'setup_not_formed'
    
    $stmt = $conn->prepare("UPDATE prediction_trade_data SET trade_result = ? WHERE raw_trade_id = ?");
    $stmt->bind_param("si", $new_result, $trade_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trade Signal Logs</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; height: 100vh; overflow: hidden; }
        .dashboard { display: flex; gap: 20px; height: 90vh; }
        .container { flex: 1; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); overflow-y: auto; }
        .plot-panel { flex: 1.2; background: #fff; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); display: flex; flex-direction: column; overflow: hidden; }
        iframe { width: 100%; height: 100%; border: none; }
        .placeholder { display: flex; align-items: center; justify-content: center; height: 100%; color: #999; text-align: center; font-size: 1.2em; }

        h2 { border-bottom: 2px solid #007bff; padding-bottom: 10px; color: #333; margin-top: 0; }
        .search-box { margin-bottom: 10px; display: flex; gap: 10px; }
        .search-box input { padding: 8px; border: 1px solid #ccc; border-radius: 5px; width: 200px; font-size: 14px; }
        .search-box button { padding: 8px 15px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }

        .count-info {
            font-size: 13px;
            color: #444;
            margin-bottom: 15px;
        }

        table { width: 100%; border-collapse: collapse; }
        th { background: #007bff; color: white; padding: 10px; text-align: left; font-size: 13px; }
        td { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        tr:hover { background: #f9f9f9; }

        .badge { background: #eef5ff; color: #007bff; padding: 4px 8px; border-radius: 5px; font-weight: bold; }
        .btn-chart { background: #28a745; color: white; text-decoration: none; padding: 6px 12px; border-radius: 5px; font-size: 12px; font-weight: bold; }
        .sell-text { color: #dc3545; font-weight: bold; }
        .buy-text { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>

<div class="dashboard">
    <div class="container">
        <h2>📊 Trade Patterns</h2>

        <form class="search-box" method="GET" action="index.php">
            <input type="text" name="search" placeholder="ID or Pair..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <button type="submit">Search</button>
            <?php if(isset($_GET['search'])): ?>
                <a href="index.php" style="font-size: 12px; color: #666; align-self: center;">Clear</a>
            <?php endif; ?>
        </form>

        <?php
        // TOTAL PENDING COUNT (NEW — DOES NOT AFFECT MAIN QUERY)
        $countSql = "SELECT COUNT(*) AS total FROM prediction_trade_data WHERE trade_result = 'pending'";
        $countResult = $conn->query($countSql);
        $totalPending = 0;
        if ($countResult && $countResult->num_rows > 0) {
            $row = $countResult->fetch_assoc();
            $totalPending = $row['total'];
        }
        ?>

        <div class="count-info">
            Showing latest <strong>100</strong> pending trades out of 
            <strong><?php echo $totalPending; ?></strong>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Pair / Dir</th>
                    <th>Price</th>
                    <th>Visualize</th>
                </tr>
            </thead>
            <tbody>
                <?php
                date_default_timezone_set('Asia/Kolkata'); 
                $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
                
                $sql = "SELECT raw_trade_id, pair_name, price_target, trade_direction, updated_at 
                        FROM prediction_trade_data 
                        WHERE trade_result = 'pending'";
                
                if ($search != '') {
                    if (is_numeric($search)) {
                        $sql .= " AND raw_trade_id = '$search'";
                    } else {
                        $sql .= " AND pair_name = '$search'";
                    }
                }

                $sql .= " ORDER BY raw_trade_id ASC LIMIT 100";
                $result = $conn->query($sql);

                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        $displayTime = $row['updated_at'] ?: "N/A";
                        $ts = $row['updated_at'] ? strtotime($row['updated_at']) : time(); 
                        $direction = strtoupper($row['trade_direction']); 
                        $isSell = ($direction === 'DOWN');
                        $dirLabel = $isSell ? "SELL" : "BUY";
                        $price = number_format($row['price_target'], 5);
                        $filePath = "dataset/FX_" . $row['pair_name'] . ".csv"; 

                        $chartUrl = "plot.php?goto={$ts}&file=" . urlencode($filePath) .
                                    "&id=" . urlencode($row['raw_trade_id']) .
                                    "&pair=" . urlencode($row['pair_name']) .
                                    "&time=" . urlencode($displayTime) .
                                    "&price=" . urlencode($price) .
                                    "&dir=" . urlencode($dirLabel);

                        echo "<tr>
                                <td>
                                    <span class='badge'>{$row['pair_name']}</span><br>
                                    <small class='".($isSell ? 'sell-text' : 'buy-text')."'>{$dirLabel}</small>
                                </td>
                                <td><strong>{$price}</strong></td>
                                <td>
                                    <a href='{$chartUrl}' target='plot_frame' class='btn-chart'
                                       onclick=\"document.getElementById('ph').style.display='none'\">
                                       View →
                                    </a>
                                </td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='3' style='text-align:center; padding:20px;'>No pending trades found.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="plot-panel">
        <div id="ph" class="placeholder">
            <p>📈 Select a trade to visualize and update results</p>
        </div>
        <iframe name="plot_frame" id="plot_frame"></iframe>
    </div>
</div>

</body>
</html>
