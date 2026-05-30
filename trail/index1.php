<?php include 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trade Signal Logs</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; padding: 30px; }
        .container { max-width: 1100px; margin: auto; background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { border-bottom: 2px solid #007bff; padding-bottom: 10px; color: #333; display: flex; justify-content: space-between; align-items: center; }
        .search-box { margin-bottom: 20px; display: flex; gap: 10px; }
        .search-box input { padding: 10px; border: 1px solid #ccc; border-radius: 5px; width: 300px; font-size: 14px; }
        .search-box button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .search-box .clear-btn { background: #6c757d; text-decoration: none; display: flex; align-items: center; padding: 0 15px; border-radius: 5px; color: white; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #007bff; color: white; padding: 12px; text-align: left; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f9f9f9; }
        .badge { background: #eef5ff; color: #007bff; padding: 5px 10px; border-radius: 5px; font-weight: bold; }
        .btn-chart { background: #28a745; color: white; text-decoration: none; padding: 8px 15px; border-radius: 5px; font-size: 14px; transition: 0.3s; }
        .btn-chart:hover { background: #218838; }
        .sell-text { color: #dc3545; font-weight: bold; }
        .buy-text { color: #28a745; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <h2>📊 Detected Trade Patterns</h2>

    <form class="search-box" method="GET" action="index.php">
        <input type="text" name="search" placeholder="Enter Exact ID or Pair..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" required>
        <button type="submit">Search Exact</button>
        <?php if(isset($_GET['search'])): ?>
            <a href="index.php" class="clear-btn">Clear</a>
        <?php endif; ?>
    </form>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Pair</th>
                <th>Signal Time (IST)</th>
                <th>Entry Price</th>
                <th>Direction</th>
                <th>Visualize</th>
            </tr>
        </thead>
        <tbody>
            <?php
            date_default_timezone_set('Asia/Kolkata'); 
            $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
            
            // STRICT MATCHING SQL: Uses "=" instead of "LIKE"
            $sql = "SELECT raw_trade_id, pair_name, price_target, trade_direction, updated_at FROM prediction_trade_data";
            
            if ($search != '') {
                // Check if search is numeric (for ID) or string (for Pair)
                if (is_numeric($search)) {
                    $sql .= " WHERE raw_trade_id = '$search'";
                } else {
                    $sql .= " WHERE pair_name = '$search'";
                }
            }

            $sql .= " ORDER BY raw_trade_id ASC LIMIT 100";
            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    $displayTime = $row['updated_at'] ? $row['updated_at'] : "N/A";
                    $ts = $row['updated_at'] ? strtotime($row['updated_at']) : time(); 

                    $direction = strtoupper($row['trade_direction']); 
                    $isSell = ($direction === 'DOWN');
                    $dirLabel = $isSell ? "SELL" : "BUY";
                    $price = number_format($row['price_target'], 5);

                    $fileName = "FX_" . $row['pair_name'] . ".csv";
                    $filePath = "dataset/" . $fileName; 

                    $chartUrl = "plot.php?goto={$ts}&file=" . urlencode($filePath) . "&id=" . urlencode($row['raw_trade_id']) . "&pair=" . urlencode($row['pair_name']) . "&time=" . urlencode($displayTime) . "&price=" . urlencode($price) . "&dir=" . urlencode($dirLabel);

                    echo "<tr>
                            <td>#{$row['raw_trade_id']}</td>
                            <td><span class='badge'>{$row['pair_name']}</span></td>
                            <td>{$displayTime}</td>
                            <td><strong>{$price}</strong></td>
                            <td class='".($isSell ? 'sell-text' : 'buy-text')."'>{$dirLabel}</td>
                            <td><a href='{$chartUrl}' class='btn-chart'>View on Chart ➔</a></td>
                          </tr>";
                }
            } else {
                echo "<tr><td colspan='6' style='text-align:center; padding:20px;'>No exact match found for ID or Pair: <b>$search</b></td></tr>";
            }
            ?>
        </tbody>
    </table>
</div>

</body>
</html>