<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

$sql = "SELECT * FROM prediction_trade_data ORDER BY raw_trade_id DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Trading Alert Data</title>
    <style>
        table {
            border-collapse: collapse;
            width: 95%;
            margin: 20px auto;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }
        th, td {
            border: 1px solid #444;
            padding: 6px 10px;
            text-align: center;
        }
        th {
            background-color: #222;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f7f7f7;
        }
        h2 {
            text-align: center;
            font-family: Arial, sans-serif;
            margin-top: 30px;
        }
    </style>
</head>
<body>

<h2>Trading Alert Data Table</h2>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Pair Name</th>
            <th>Price Target</th>
            <th>Direction</th>
            <th>Status</th>
            <th>Last Alert Time</th>
            <th>Updated At</th>
        </tr>
    </thead>
    <tbody>
    <?php
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['raw_trade_id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['pair_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['price_target']) . "</td>";
            echo "<td>" . htmlspecialchars($row['trade_direction']) . "</td>";
            echo "<td>" . ($row['sent_status'] == 1 ? '✅ Sent' : '⏳ Pending') . "</td>";
            echo "<td>" . htmlspecialchars($row['updated_at']) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='7'>No alert data found.</td></tr>";
    }
    $conn->close();
    ?>
    </tbody>
</table>

</body>
</html>
