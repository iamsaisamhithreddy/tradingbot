<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection

ini_set('display_errors',1);
error_reporting(E_ALL);

$nowIST = new DateTime("now", new DateTimeZone('Asia/Kolkata')); // converting to IST time zone 

if(isset($_POST['clear_db'])){
    $conn->query("DELETE FROM economic_events");
    echo "<p style='color:green; font-weight:bold; text-align:center;'>✅ Database cleared successfully!</p>";
}

// Fetch all events ordered by time
$res = $conn->query("SELECT * FROM economic_events ORDER BY event_time ASC");
$events = [];
while($row = $res->fetch_assoc()){
    $events[] = $row;
}

$nextEvent = null;
$futureEvents = [];

foreach($events as &$event){
    // Convert GMT-4 to IST
    $gmt4 = new DateTime($event['event_time'], new DateTimeZone('America/New_York'));
    $gmt4->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $event['event_time_ist'] = $gmt4;

    if($gmt4 >= $nowIST){
        $futureEvents[] = $event;
    }
}

// selecting event with highest impact and near to current time as of now..
if(!empty($futureEvents)){
    usort($futureEvents, function($a,$b){
        return $a['event_time_ist'] <=> $b['event_time_ist'];
    });

    $earliestTime = $futureEvents[0]['event_time_ist']->format("Y-m-d H:i:s");

    $sameTimeEvents = array_filter($futureEvents, function($ev) use($earliestTime){
        return $ev['event_time_ist']->format("Y-m-d H:i:s") === $earliestTime;
    });

    usort($sameTimeEvents, function($a,$b){
        return $b['impact'] <=> $a['impact'];
    });

    $nextEvent = array_values($sameTimeEvents)[0];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Economic Events</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 20px; }
        h1 { text-align: center; }
        table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0px 2px 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        tr:hover { background: #f1f1f1; }
        .impact-1 { color: gray; }
        .impact-2 { color: orange; font-weight: bold; }
        .impact-3 { color: red; font-weight: bold; }
        .next-event { margin: 20px auto; padding: 15px; max-width: 600px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; }
        #countdown { font-size: 1.2em; font-weight: bold; color: #d9534f; }
        .btn-clear { background: red; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-bottom: 20px; }
        .btn-clear:hover { background: darkred; }
    </style>
</head>
<body>

<h1>📅 Economic Events</h1>

<!-- Clear Database Button -->
<form method="POST" onsubmit="return confirm('⚠️ Are you sure you want to clear the entire database?');" style="text-align:center;">
    <button type="submit" name="clear_db" class="btn-clear">🗑️ Clear Database</button>
</form>

<?php if($nextEvent): ?>
<div class="next-event">
    <h2>⏳ Next Event</h2>
    <p><b>ID:</b> <?= $nextEvent['id'] ?></p>
    <p><b>Event:</b> <?= htmlspecialchars($nextEvent['event_name']) ?></p>
    <p><b>Impact:</b> <span class="impact-<?= $nextEvent['impact'] ?>"> <?= $nextEvent['impact'] ?> </span></p>
    <p><b>Time (IST):</b> <?= $nextEvent['event_time_ist']->format("Y-m-d H:i") ?></p>
    <p><b>Countdown:</b> <span id="countdown"></span></p>
</div>

<script>
// Countdown Timer
function startCountdown(targetTime) {
    function updateCountdown() {
        let now = new Date().getTime();
        let distance = targetTime - now;

        if(distance <= 0){
            document.getElementById("countdown").innerHTML = "🚨 Happening now!";
            clearInterval(timer);
            return;
        }

        let hours = Math.floor((distance / (1000*60*60)));
        let minutes = Math.floor((distance / (1000*60)) % 60);
        let seconds = Math.floor((distance / 1000) % 60);

        document.getElementById("countdown").innerHTML =
            ("0"+hours).slice(-2) + ":" +
            ("0"+minutes).slice(-2) + ":" +
            ("0"+seconds).slice(-2);
    }
    updateCountdown();
    let timer = setInterval(updateCountdown, 1000);
}

// Pass PHP IST time to JS
let eventTime = new Date("<?= $nextEvent['event_time_ist']->format('Y-m-d H:i:s') ?>").getTime();
startCountdown(eventTime);
</script>

<?php else: ?>
<p style="text-align:center;">✅ No upcoming events</p>
<?php endif; ?>

<table>
    <tr>
        <th>ID</th>
        <th>Event Name</th>
        <th>Impact</th>
        <th>Event Time (IST)</th>
        <th>Sent Status</th>
    </tr>

<!-- Showing events fecthed from database !-->
 
    <?php foreach($events as $event): ?>
    <tr>
        <td><?= $event['id'] ?></td>
        <td><?= htmlspecialchars($event['event_name']) ?></td>
        <td class="impact-<?= $event['impact'] ?>"><?= $event['impact'] ?></td>
        <td><?= $event['event_time_ist']->format("Y-m-d H:i") ?></td>
        <td><?= $event['sent_status'] == 1 ? "✅ Sent" : "❌ Not Sent" ?></td>
    </tr>
    <?php endforeach; ?>
</table>

</body>
</html>
