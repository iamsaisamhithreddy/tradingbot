<?php
include 'db.php';

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: text/plain');

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit("Invalid request");
}

$event_name  = trim($_POST['event_name'] ?? '');
$impact      = trim($_POST['impact'] ?? '');
$event_time  = trim($_POST['event_time'] ?? '');
$sent_status = (int)($_POST['sent_status'] ?? 0);

if ($event_name === '' || $event_time === '') {
    exit("⚠️ Missing required fields");
}


// Today IST
$todayIST = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

// Event IST date
try {
    $eventDateIST = (new DateTime($event_time, new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
} catch (Exception $e) {
    exit("❌ Invalid event_time format");
}

// Skip non-today events
if ($eventDateIST !== $todayIST) {
    exit("⏭️ Skipped (Not today): $event_name | $event_time");
}


$stmt = $conn->prepare("
    INSERT INTO economic_events (event_name, impact, event_time, sent_status)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        impact = VALUES(impact),
        event_time = VALUES(event_time),
        sent_status = VALUES(sent_status)
");

$stmt->bind_param("sssi", $event_name, $impact, $event_time, $sent_status);

if ($stmt->execute()) {
    echo "✅ Inserted/Updated (TODAY): $event_name | $event_time";
} else {
    echo "❌ DB Error: " . $stmt->error;
}
