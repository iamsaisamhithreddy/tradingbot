<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

date_default_timezone_set("Asia/Kolkata");

function getSessions() {
    $now = new DateTime("now");
    $time = $now->format("H:i");

    $sessions = [
        "Sydney"   => ["02:30", "11:30"],
        "Tokyo"    => ["05:30", "14:30"],
        "London"   => ["12:30", "21:30"],
        "New York" => ["17:30", "02:30"], // crosses midnight
    ];

    $active = [];

    foreach ($sessions as $name => [$start, $end]) {
        if ($start < $end) {
            if ($time >= $start && $time <= $end) {
                $active[] = $name;
            }
        } else { 
            if ($time >= $start || $time <= $end) {
                $active[] = $name;
            }
        }
    }

    if (count($active) > 1) {
        return "Overlap: " . implode(" + ", $active);
    } elseif (count($active) == 1) {
        return $active[0];
    } else {
        return "No Active Session";
    }
}

$session = getSessions();

$confidence = "Normal";
$now = new DateTime("now");
$current_time = $now->format("H:i");
$dayOfWeek = $now->format("l");

// session wise priority.. 
if (strpos($session, "London") !== false && strpos($session, "New York") !== false) {
    $confidence = "Very High (London + NY Overlap)";
} elseif (strpos($session, "London") !== false && $current_time >= "13:30") {
    $confidence = "Boosted";
} elseif (strpos($session, "London") !== false || strpos($session, "New York") !== false) {
    $confidence = "High";
}
// 🔻 Drop after 21:30 even if NY active
if ($current_time >= "21:30" && strpos($session, "New York") !== false) {
    $confidence = "Low (Late NY)";
}

// Volatility notes
$note = "";
if ($dayOfWeek === "Monday") {
    $note = "⚠️ Markets can be volatile on Monday.";
} elseif ($dayOfWeek === "Friday" && $current_time >= "20:00") {
    $note = "⚠️ Friday late session: liquidity often drops.";
} else {
    $note = "ℹ️ Moderate volatility expected.";
}

echo "Current Time: " . $current_time . "<br>";
echo "Day: " . $dayOfWeek . "<br>";
echo "Active Session(s): " . $session . "<br>";
echo "Confidence Level: " . $confidence . "<br>";
echo "Note: " . $note . "<br>";
?>
