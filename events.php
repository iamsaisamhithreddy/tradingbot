<?php
require 'db.php';
ini_set('display_errors',1);
error_reporting(E_ALL);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_prompt'])) {
    
    // Fetch the currently active API from the database
    function getActiveAPI() {
        global $conn;
        $sql = "SELECT * FROM api_keys WHERE status='active' LIMIT 1";
        $res = mysqli_query($conn, $sql);
        if ($row = mysqli_fetch_assoc($res)) {
            return $row;
        }
        die("<div class='response-box'>❌ No active API key found in database</div>");
    }

    $api = getActiveAPI();
    $API_KEY  = $api['api_key'];
    $BASE_URL = $api['base_url'];
    $MODEL    = $api['model'];

    function callAI($messages) {
        global $API_KEY, $BASE_URL, $MODEL, $api;
        $provider = strtolower($api['provider']);

        // 🔹 GEMINI LOGIC
        if ($provider === 'gemini') {
            $text = "";
            foreach ($messages as $m) {
                $text .= strtoupper($m['role']) . ": " . $m['content'] . "\n";
            }

            $url = rtrim($BASE_URL, '/') . "/models/" . $MODEL . ":generateContent?key=" . $API_KEY;
            $payload = [
                "contents" => [
                    ["parts" => [["text" => $text]]]
                ]
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_TIMEOUT => 20
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) return "⚠️ Gemini Curl Error: " . curl_error($ch);
            curl_close($ch);

            $json = json_decode($response, true);
            if (!isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                return "⚠️ Gemini failed.\nResponse:\n" . substr($response,0,200);
            }

            return $json['candidates'][0]['content']['parts'][0]['text'];
        } 
        // 🔹 GROQ / OPENAI / XAI LOGIC
        else {
            $payload = [
                "model" => $MODEL,
                "messages" => $messages,
                "temperature" => 0.7,
                "max_tokens" => 2048
            ];

            $ch = curl_init(rtrim($BASE_URL, '/') . "/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer " . $API_KEY,
                    "Content-Type: application/json"
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) return "Curl Error: " . curl_error($ch);
            curl_close($ch);

            $json = json_decode($response, true);
            return $json['choices'][0]['message']['content'] ?? ("AI Error:\n" . $response);
        }
    }

    $prompt = trim($_POST['user_prompt']);
    
    // Structure the messages for the AI
    $messages = [
        ["role" => "system", "content" => "You are an expert financial market analyst. Provide concise, beginner-friendly trading insights."],
        ["role" => "user", "content" => $prompt]
    ];

    $reply = callAI($messages);

    // Return the response in the HTML format the JS expects
    echo "<div class='response-box'>" . htmlspecialchars($reply) . "</div>";
    exit; 
}


// Fetch events
$res = $conn->query("SELECT * FROM economic_events ORDER BY event_time ASC");
$events = [];
while($row = $res->fetch_assoc()){
    $events[] = $row;
}

// NEXT EVENT LOGIC 

$now = new DateTime("now", new DateTimeZone('Asia/Kolkata'));
$nextEvent = null;

foreach($events as $event){
    $eventTime = new DateTime($event['event_time'], new DateTimeZone('Asia/Kolkata'));

    if($eventTime >= $now){
        $nextEvent = $event;
        break;
    }
}

?>

<!DOCTYPE html>
<html>
<head>
<title>Economic Events</title>

<style>
body {
    font-family: Arial;
    background:#f8f9fa;
    margin:20px;
}

h1 { text-align:center; }

table {
    width:100%;
    border-collapse:collapse;
    background:#fff;
    box-shadow:0 2px 5px rgba(0,0,0,0.1);
}

th,td {
    padding:12px;
    text-align:center;
    border-bottom:1px solid #ddd;
}

th {
    background:#007bff;
    color:white;
}

tr:hover { background:#f1f1f1; }

.btn-ai {
    background:#28a745;
    color:white;
    padding:7px 12px;
    border:none;
    border-radius:6px;
    cursor:pointer;
}

.btn-ai:hover { background:#218838; }

.ai-response {
    background:#ffffff;
    padding:15px;
    border-radius:10px;
    border-left:5px solid #007bff;
    font-size:14px;
    line-height:1.6;
}

.ai-response h2 {
    color:#007bff;
    margin-top:15px;
}

.ai-response ul {
    margin-left:20px;
}

.ai-response b {
    color:#111;
}

.bullish { color:green; font-weight:bold; }
.bearish { color:red; font-weight:bold; }

.loader {
    font-size:13px;
    color:#555;
}


.next-event-box {
    max-width:600px;
    margin:20px auto;
    background:#fff3cd;
    padding:15px;
    border-radius:10px;
    text-align:center;
    border:1px solid #ffeeba;
}

#countdown {
    font-size:20px;
    color:#d9534f;
    font-weight:bold;
}

</style>

</head>
<body>

<h1>📅 Economic Events</h1>

<?php if($nextEvent): ?>
<div class="next-event-box">
    <h3>⏳ Next Event</h3>

    <b><?= htmlspecialchars($nextEvent['event_name']) ?></b><br>
    Impact: <?= $nextEvent['impact'] ?><br>
    Time: <?= $nextEvent['event_time'] ?><br><br>

    ⏱️ <span id="countdown"></span>
</div>
<?php endif; ?>
<table>
<tr>
    <th>ID</th>
    <th>Event</th>
    <th>Impact</th>
    <th>Time</th>
    <th>AI</th>
</tr>

<?php foreach($events as $event): 

$rowId = "row_" . $event['id'];

?>

<tr>
    <td><?= $event['id'] ?></td>
    <td><?= htmlspecialchars($event['event_name']) ?></td>
    <td><?= $event['impact'] ?></td>
    <td><?= $event['event_time'] ?></td>
    <td>
        <button class="btn-ai"
            onclick="askAI(
                '<?= addslashes($event['event_name']) ?>',
                '<?= $event['impact'] ?>',
                '<?= $rowId ?>'
            )">
            🤖 ASK AI
        </button>
    </td>
</tr>

<tr id="<?= $rowId ?>" style="display:none;">
    <td colspan="5">
        <div class="ai-response"></div>
    </td>
</tr>

<?php endforeach; ?>

</table>

<script>

// COUNTDOWN

<?php if($nextEvent): ?>
let eventTime = new Date("<?= $nextEvent['event_time'] ?>").getTime();

function updateCountdown(){

    let now = new Date().getTime();
    let diff = eventTime - now;

    if(diff <= 0){
        document.getElementById("countdown").innerHTML = "🚨 Live Now!";
        return;
    }

    let h = Math.floor(diff / (1000*60*60));
    let m = Math.floor((diff / (1000*60)) % 60);
    let s = Math.floor((diff / 1000) % 60);

    document.getElementById("countdown").innerHTML =
        ("0"+h).slice(-2) + ":" +
        ("0"+m).slice(-2) + ":" +
        ("0"+s).slice(-2);
}

setInterval(updateCountdown, 1000);
updateCountdown();
<?php endif; ?>

// FORMAT AI RESPONSE

function formatAI(text){

    text = text.replace(/\[.*?\]\((https?:\/\/[^\s]+)\)/g, '$1');
    text = text.replace(/(https?:\/\/[^\s\]]+)/g, '$1');

    text = text.replace(
        /(https?:\/\/[^\s]+)/g,
        '<a href="$1" target="_blank" style="color:#007bff;font-weight:bold;">🔗 Source</a>'
    );

    text = text
        .replace(/## (.*)/g, "<h2>$1</h2>")
        .replace(/\*\*(.*?)\*\*/g, "<b>$1</b>")
        .replace(/\* (.*)/g, "<li>$1</li>")
        .replace(/---/g, "<hr>")
        .replace(/\n/g, "<br>");

    text = text.replace(/(<li>.*<\/li>)/g, "<ul>$1</ul>");

    text = text
        .replace(/Bullish/gi, "<span class='bullish'>Bullish 📈</span>")
        .replace(/Bearish/gi, "<span class='bearish'>Bearish 📉</span>");

    return text;
}

// TOGGLE

function toggleAI(id){
    let full = document.getElementById("full_"+id);
    let preview = document.getElementById("preview_"+id);
    let btn = document.getElementById("btn_"+id);

    if(full.style.display === "none"){
        full.style.display = "block";
        preview.style.display = "none";
        btn.innerText = "🔽 Show Less";
    } else {
        full.style.display = "none";
        preview.style.display = "block";
        btn.innerText = "🔼 Show More";
    }
}

// CALL AI

function askAI(eventName, impact, rowId){

    let row = document.getElementById(rowId);
    let box = row.querySelector(".ai-response");

    row.style.display = "table-row";
    box.innerHTML = "<span class='loader'>Analyzing market impact using active API...</span>";

    let prompt = `Explain the market impact of ${eventName} with impact level ${impact}. Impact Level: 1 (lowest Impact) , 
    Impact Level: 3 (Highest Impact). Give trading insights with bullish or bearish bias. 
    Give in minimal length and beginner friendly. Provide official links if possible.`;

    fetch("", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "user_prompt=" + encodeURIComponent(prompt)
    })
    .then(res => res.text())
    .then(data => {

        let parser = new DOMParser();
        let doc = parser.parseFromString(data, "text/html");

        let response = doc.querySelector(".response-box");

        if(response){

            let formatted = formatAI(response.innerText);
            let shortText = formatted.substring(0, 300) + "...";

            box.innerHTML = `
                <div id="preview_${rowId}">${shortText}</div>

                <div id="full_${rowId}" style="display:none;">
                    ${formatted}
                </div>

                <br>
                <button id="btn_${rowId}" onclick="toggleAI('${rowId}')"
                    style="background:#007bff;color:white;border:none;padding:6px 10px;border-radius:5px;cursor:pointer;">
                    🔼 Show More
                </button>
            `;

        } else {
            box.innerHTML = "⚠️ Failed to fetch AI response.";
        }
    })
    .catch(err => {
        box.innerHTML = "❌ Error: " + err;
    });
}

</script>

</body>
</html>