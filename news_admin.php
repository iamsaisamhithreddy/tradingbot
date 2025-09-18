<?php

// PROJECT BY : SAI SAMHITH REDDY , DATE : 1/8/2025
// linkedin : https://www.linkedin.com/in/saisamhithreddy
// LEETCODE : https://leetcode.com/u/iamsaisamhithreddy/
// GITHUB : https://github.com/iamsaisamhithreddy

require 'db.php'; // database connection

session_start();

// Only admins can access this page. 
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function escapeHTML($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// initial response variables
$inserted = 0;
$insertedEvents = [];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Clear database
    if (isset($_POST['clear_db'])) {
        $stmt = $conn->prepare("DELETE FROM economic_events");
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'cleared' => true]);
        exit;
    }

    // Insert events
    if (!empty($_POST['events'])) {
        $events = json_decode($_POST['events'], true);

        $stmt = $conn->prepare("INSERT INTO economic_events (event_name, impact, event_time, sent_status) VALUES (?, ?, ?, 0)");

        foreach ($events as $event) {
            $eventName = $event['event'] ?? $event['event_name'] ?? '';
            $impact = intval($event['impact'] ?? 1);
            $time = $event['time'] ?? date('H:i');

            // Use today's date for event_time
            $event_time = date('Y-m-d') . " $time:00";

            $stmt->bind_param("sis", $eventName, $impact, $event_time);
            if ($stmt->execute()) {
                $inserted++;
                $insertedEvents[] = [
                    'event_name' => $eventName,
                    'impact' => $impact,
                    'event_time' => $event_time
                ];
            }
        }
        $stmt->close();

        echo json_encode(['success' => true, 'inserted' => $inserted, 'insertedEvents' => $insertedEvents]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Economic Calendar Data Extractor</title>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #f4f4f4; padding: 20px; }
.container { max-width: 1200px; margin: 0 auto; }
header { text-align: center; padding: 20px; margin-bottom: 20px; background: #fff; border-radius: 8px; }
textarea { width: 100%; min-height: 200px; padding: 10px; font-family: monospace; font-size: 14px; }
button { padding: 10px 20px; margin: 5px; cursor: pointer; border: none; border-radius: 5px; }
.status { margin: 15px 0; font-weight: bold; }
table { width: 100%; border-collapse: collapse; margin-top: 15px; }
th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
.btn-clear { background: red; color: white; }
.btn-clear:hover { background: darkred; }
</style>
</head>
<body>
<div class="container">
    <header style="position: relative; text-align: center; padding: 20px; margin-bottom: 20px; background: #fff; border-radius: 8px;">
    <h1>Economic Calendar Data Extractor</h1>
    <p>Paste your economic calendar data below or select a file.</p>
    <form method="POST" style="position: absolute; top: 20px; right: 20px; margin:0;">
        <button type="submit" name="logout" formaction="logout.php" class="btn-clear">Logout</button>
    </form>
</header>

    <textarea id="dataInput" placeholder="Paste your economic calendar data here..."></textarea>
    <input type="file" id="fileInput" accept=".txt" />
    <div>
        <button onclick="processData()">Process Data</button>
        <button onclick="clearData()">Clear</button>
        <button class="btn-clear" onclick="clearDatabase()">🗑️ Clear Database</button>
    </div>

    <div class="status" id="status"></div>
    <div id="dataTable"></div>
</div>

<script>
function escapeHTML(str) {
    return str.replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

function parseEconomicData(text){
    const events = [];
    const lines = text.split('\n');
    let current = {};

    for (const line of lines) {
        const l = line.trim();
        if(!l || l.startsWith('===') || l.startsWith('---')) {
            if(current.time && current.event) {
                if(!current.impact) current.impact = 1;
                events.push(current);
                current = {};
            }
            continue;
        }

        const combined = l.match(/Time:\s*([\d:]+)\s*\|\s*Currency:\s*([A-Z]{3})\s*\|\s*Impact:\s*(\d+)/i);
        if(combined){
            current.time = combined[1];
            current.currency = combined[2];
            current.impact = parseInt(combined[3]);
            continue;
        }

        const timeMatch = l.match(/^Time:\s*([\d:]+)/i);
        if(timeMatch) current.time = timeMatch[1];

        const currencyMatch = l.match(/Currency:\s*([A-Z]{3})/i);
        if(currencyMatch) current.currency = currencyMatch[1];

        const impactMatch = l.match(/Impact:\s*(\d+)/i);
        if(impactMatch) current.impact = parseInt(impactMatch[1]);

        const eventMatch = l.match(/^Event:\s*(.+)/i);
        if(eventMatch) current.event = eventMatch[1];
    }

    if(current.time && current.event){
        if(!current.impact) current.impact = 1;
        events.push(current);
    }
    return events;
}

function displayData(events){
    if(!events.length){
        document.getElementById('dataTable').innerHTML = 'No events found.';
        return;
    }

    let html = '<h3>Inserted Events</h3><table><tr><th>Event Name</th><th>Impact</th><th>Event Time</th></tr>';
    events.forEach(ev => {
        html += `<tr>
            <td>${escapeHTML(ev.event_name || ev.event)}</td>
            <td>${escapeHTML(ev.impact || 1)}</td>
            <td>${escapeHTML(ev.event_time || ev.time)}</td>
        </tr>`;
    });
    html += '</table>';
    document.getElementById('dataTable').innerHTML = html;
}

function processData(){
    const text = document.getElementById('dataInput').value.trim();
    if(!text){ alert('Please paste some data first'); return; }

    const events = parseEconomicData(text);

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'events=' + encodeURIComponent(JSON.stringify(events))
    })
    .then(res => res.json())
    .then(data => {
        if(data.success){
            document.getElementById('status').innerText = `Inserted ${data.inserted} events into DB successfully.`;
            displayData(data.insertedEvents);
        } else {
            document.getElementById('status').innerText = 'Error inserting into DB.';
        }
    }).catch(err => {
        document.getElementById('status').innerText = 'Error: ' + err;
    });
}

function clearData(){
    document.getElementById('dataInput').value = '';
    document.getElementById('dataTable').innerHTML = '';
    document.getElementById('status').innerText = '';
}

function clearDatabase(){
    if(!confirm("⚠️ Are you sure you want to clear the entire database?")) return;

    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'clear_db=1'
    })
    .then(res => res.json())
    .then(data => {
        if(data.success && data.cleared){
            document.getElementById('status').innerText = "✅ Database chleared successfully!";
            document.getElementById('dataTable').innerHTML = '';
        } else {
            document.getElementById('status').innerText = "❌ Error clearing database.";
        }
    }).catch(err => {
        document.getElementById('status').innerText = 'Error: ' + err;
    });
}

document.getElementById('fileInput').addEventListener('change', function(e){
    const file = e.target.files[0];
    if(!file) return;
    if(file.type !== "text/plain"){ alert("Only .txt files allowed"); return; }
    if(file.size > 2*1024*1024){ alert("File too large (max 2MB)"); return; }

    const reader = new FileReader();
    reader.onload = function(e){
        document.getElementById('dataInput').value = e.target.result;
    };
    reader.readAsText(file);
});
</script>
</body>
</html>
