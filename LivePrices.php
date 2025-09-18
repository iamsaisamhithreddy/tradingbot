<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

include 'db.php';

// Fetch latest prices from database
$prices = $conn->query("SELECT pair_name, current_price, updated_at FROM live_price_data ORDER BY pair_name ASC");
$priceData = [];
while($row = $prices->fetch_assoc()) {
    $priceData[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Live Price Dashboard</title>
<meta http-equiv="refresh" content="5"> <!-- Refresh page every 5 seconds -->
<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; max-width: 600px; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background-color: #f2f2f2; }
    .up { color: green; font-weight: bold; }
    .down { color: red; font-weight: bold; }
    #timer, #currentTime { font-weight: bold; margin-bottom: 10px; }
</style>
</head>
<body>

<h2>Live Price Dashboard</h2>
<div id="timer">Refreshing in: <span id="countdown">5</span>s</div>
<div id="currentTime">Current Time: <span id="time"></span></div>

<table>
    <thead>
        <tr>
            <th>Pair</th>
            <th>Price</th>
            <th>Last Updated</th>
        </tr>
    </thead>
    <tbody>
    <?php 
    $previousPrices = [];
    foreach($priceData as $row) {
        $currentPrice = (float)$row['current_price'];
        $class = '';
        if(isset($previousPrices[$row['pair_name']])) {
            if($currentPrice > $previousPrices[$row['pair_name']]) $class = 'up';
            elseif($currentPrice < $previousPrices[$row['pair_name']]) $class = 'down';
        }
        $previousPrices[$row['pair_name']] = $currentPrice;

        // Adjust time by subtracting 2 hours 30 minutes
        $adjustedTime = date('H:i:s', strtotime($row['updated_at'] . ' -2 hours -30 minutes'));

        echo "<tr>
                <td>{$row['pair_name']}</td>
                <td class='$class'>".number_format($currentPrice,5)."</td>
                <td>{$adjustedTime}</td>
              </tr>";
    }
    ?>
    </tbody>
</table>

<script>
// Countdown timer
let countdown = 5; // seconds
const countdownSpan = document.getElementById('countdown');
setInterval(() => {
    countdown--;
    if(countdown <= 0) countdown = 5; // reset when page refreshes
    countdownSpan.textContent = countdown;
}, 1000);

// Current time display
function updateTime() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2,'0');
    const minutes = String(now.getMinutes()).padStart(2,'0');
    const seconds = String(now.getSeconds()).padStart(2,'0');
    document.getElementById('time').textContent = `${hours}:${minutes}:${seconds}`;
}
setInterval(updateTime, 1000);
updateTime(); // initial call
</script>

</body>
</html>
