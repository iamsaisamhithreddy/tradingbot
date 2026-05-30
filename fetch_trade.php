<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php'; 

session_start();

// Only admins can access this page. 
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$result = null;
$error = null;
$chart_pair = null; // Variable to store the pair name for the bottom chart

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raw_trade_id = intval($_POST['raw_trade_id']);

    $sql = "SELECT * FROM prediction_trade_data WHERE raw_trade_id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("i", $raw_trade_id);
        if ($stmt->execute()) {
            $res = $stmt->store_result();
            $meta = $stmt->result_metadata();

            if ($meta->field_count > 0) {
                $fields = [];
                $row = [];

                while ($field = $meta->fetch_field()) {
                    $fields[] = &$row[$field->name];
                }

                call_user_func_array([$stmt, 'bind_result'], $fields);

                if ($stmt->fetch()) {
                    $result = $row;
                } else {
                    $error = "⚠️ No trade found with raw_trade_id = " . htmlspecialchars($raw_trade_id);
                }
            }
        } else {
            $error = "Execute failed: " . $stmt->error;
        }
    } else {
        $error = "Prepare failed: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fetch Trade</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 30px;
        }
        .container {
            max-width: 1000px; /* Widened for a better full-view chart */
            margin: auto;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        h2, h3 {
            color: #444;
            font-weight: 600;
        }
        h2 {
            margin-bottom: 15px;
        }
        form {
            margin-bottom: 20px;
        }
        input, button {
            padding: 10px 14px;
            font-size: 15px;
            border-radius: 6px;
            border: 1px solid #ddd;
            outline: none;
        }
        input {
            width: 200px;
        }
        button {
            background: #28a745;
            color: #fff;
            border: none;
            margin-left: 10px;
            cursor: pointer;
            transition: background 0.2s ease-in-out;
        }
        button:hover {
            background: #218838;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
            margin-top: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            border: 1px solid #e0e0e0;
        }
        th, td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #fafafa;
            color: #555;
            font-weight: 600;
            width: 30%;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .direction-up {
            color: #28a745;
            font-weight: bold;
        }
        .direction-down {
            color: #dc3545;
            font-weight: bold;
        }
        .null {
            color: #aaa;
            font-style: italic;
        }
        
        /* New styles for the full bottom chart */
        .full-chart-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px dashed #ccc;
        }
        .chart-container {
            margin-top: 15px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #ddd;
            background: #000; /* Match typical chart background */
        }
        .chart-link {
            display: inline-block;
            margin-top: 12px;
            font-size: 14px;
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
        }
        .chart-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>🔍 Fetch Trade by raw_trade_id</h2>
        <form method="POST">
            <input type="number" name="raw_trade_id" placeholder="Enter raw_trade_id" required>
            <button type="submit">Get Trade</button>
        </form>

        <?php if ($error): ?>
            <p class="error"><?= $error ?></p>
        <?php endif; ?>

        <?php if ($result): ?>
            <h3>📊 Trade Details</h3>
            <table>
                <tbody>
                <?php foreach ($result as $key => $value): ?>
                    <tr>
                        <th><?= htmlspecialchars($key) ?></th>
                        <td>
                            <?php 
                                if ($value === "" || $value === null) {
                                    echo "<span class='null'>NULL</span>";
                                } elseif ($key === "trade_direction") {
                                    $class = ($value === "UP") ? "direction-up" : "direction-down";
                                    echo "<span class='$class'>" . htmlspecialchars($value) . "</span>";
                                } elseif (in_array(strtolower($key), ['symbol', 'pair', 'pair_name', 'asset_name'])) {
                                    // Save the pair for the bottom chart, just show text here
                                    $chart_pair = $value;
                                    echo "<strong>" . htmlspecialchars($value) . "</strong>";
                                } else {
                                    echo htmlspecialchars($value);
                                }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($chart_pair): ?>
                <div class="full-chart-section">
                    <h3>📈 Live Chart: <?= htmlspecialchars($chart_pair) ?></h3>
                    <?php 
                        $url_pair = urlencode($chart_pair);
                        $chart_url = "https://saireddy.site/livechart/?symbol=" . $url_pair;
                    ?>
                    <div class="chart-container">
                        <iframe src="<?= $chart_url ?>" width="100%" height="550" frameborder="0"></iframe>
                    </div>
                    <a href="<?= $chart_url ?>" target="_blank" class="chart-link">Open chart in new tab ↗</a>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>