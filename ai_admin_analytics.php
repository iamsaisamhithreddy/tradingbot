<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

//  LOAD API CONFIG FROM DB 
function getActiveAPI() {
    global $conn;

    $sql = "SELECT * FROM api_keys WHERE status='active' LIMIT 1";
    $res = mysqli_query($conn, $sql);

    if ($row = mysqli_fetch_assoc($res)) {
        return $row;
    }

    die("❌ No active API key found in database");
}

$api = getActiveAPI();

$API_KEY  = $api['api_key'];
$BASE_URL = $api['base_url'];
$MODEL    = $api['model'];


// TRADING CONTEXT

$CTX_FILE = __DIR__ . "/trading_context.txt";
$TRADING_CONTEXT = file_exists($CTX_FILE)
    ? trim(file_get_contents($CTX_FILE))
    : "No trading context defined.";


// DUCKDUCKGO LITE SCRAPER (WITH LINKS)

function scrapeWebSearch($query) {
    $url = "https://lite.duckduckgo.com/lite/";
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['q' => $query]),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
        ],
        CURLOPT_TIMEOUT => 7
    ]);
    
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return "";

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);


    $titles = $xpath->query('//a[contains(@class, "result-url")]');
    $snippets = $xpath->query('//td[contains(@class, "result-snippet")]');
    
    $resultText = "";
    $count = 0;
    
    for ($i = 0; $i < $snippets->length; $i++) {
        $snippetNode = $snippets->item($i);
        $text = trim(preg_replace('/\s+/', ' ', $snippetNode->textContent)); 
        
        $sourceUrl = "Source not available";
        if ($i < $titles->length) {
            $titleNode = $titles->item($i);
            $rawHref = $titleNode->getAttribute('href');
            
            // DuckDuckGo Lite routes links through a redirect.
            if (strpos($rawHref, 'uddg=') !== false) {
                parse_str(parse_url($rawHref, PHP_URL_QUERY), $queryParams);
                if (isset($queryParams['uddg'])) {
                    $sourceUrl = $queryParams['uddg'];
                }
            } else {
                $sourceUrl = $rawHref;
            }
            
            if (strpos($sourceUrl, 'http') !== 0 && $sourceUrl !== "Source not available") {
                $sourceUrl = "https://" . ltrim($sourceUrl, '/');
            }
        }

        if (strlen($text) > 30) { 
            $resultText .= "- Info: " . $text . "\n  Link: " . $sourceUrl . "\n\n";
            $count++;
        }
        if ($count >= 4) break; 
    }

    if ($resultText !== "") {
        return "LATEST WEB SEARCH RESULTS FOR CONTEXT:\n" . $resultText . "\n";
    }
    
    return "LATEST WEB SEARCH RESULTS FOR CONTEXT:\n- [SYSTEM NOTE: The scraper failed to extract data. Answer based on your existing knowledge and mention you couldn't fetch live data.]\n";
}


//  AI CALL 

function callAI($messages) {
    global $API_KEY, $BASE_URL, $MODEL, $api;

    $provider = strtolower($api['provider']);

    // GOOGLE GEMINI  
    if ($provider === 'gemini') {
        $text = "";
        foreach ($messages as $m) {
            $text .= strtoupper($m['role']) . ": " . $m['content'] . "\n";
        }

        $url = rtrim($BASE_URL, '/') . "/models/" . $MODEL . ":generateContent?key=" . $API_KEY;

        $payload = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $text]
                    ]
                ]
            ],
            // Added generation config for Gemini to reduce hallucination
            "generationConfig" => [
                "temperature" => 0.2
            ]
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json"
            ],
            CURLOPT_TIMEOUT => 10 
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            return "⚠️ Gemini Curl Error: " . curl_error($ch) . "\n\n— 🤖 Model: GEMINI | " . $MODEL;
        }

        curl_close($ch);
        $json = json_decode($response, true);

        if (!isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            return "⚠️ Gemini failed.\nResponse:\n" . substr($response,0,200) . "\n\n— 🤖 Model: GEMINI | " . $MODEL;
        }

        $content = $json['candidates'][0]['content']['parts'][0]['text'];
        return $content . "\n\n— 🤖 Model: GEMINI | " . $MODEL;
    }

    //  GROQ / OPENAI / XAI
    else {

        $payload = [
            "model" => $MODEL,
            "messages" => $messages,
            //  CHANGED THIS FROM 0.7 TO 0.2 TO STOP HALLUCINATIONS 
            "temperature" => 0.2, 
            "max_tokens" => 2048
        ];

        $ch = curl_init($BASE_URL . "/chat/completions");

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

        if (curl_errno($ch)) {
            return "Curl Error: " . curl_error($ch);
        }

        curl_close($ch);
        $json = json_decode($response, true);

        $content = $json['choices'][0]['message']['content']
            ?? ("AI Error:\n" . $response);

        return $content . "\n\n— 🤖 Model: " . strtoupper($provider) . " | " . $MODEL;
    }
}


// SMART TRADING FILTER

function isTradingQuestion($text) {
    $keywords = [
        'trading','forex','stock','crypto','market','price',
        'binary','option','candles','chart','indicator',
        'support','resistance','trend','volume'
    ];
    $text = strtolower($text);
    foreach ($keywords as $word) {
        if (strpos($text, $word) !== false) return true;
    }
    return false;
}

// SYSTEM PROMPT 

function systemPrompt($scrapedData = "") {
    global $TRADING_CONTEXT;

    return [
        "role" => "system",
        "content" =>
            "You are a professional trading assistant.\n".
            "You MUST ONLY answer financial, market, banking-related questions.\n\n".

            ($scrapedData ? "=====================\n" . $scrapedData . "=====================\n\n" : "") .

            "IMPORTANT:\n".
            "- Be direct and concise. Do NOT hallucinate long historical tables or unrelated data.\n".
            "- If 'LATEST WEB SEARCH RESULTS FOR CONTEXT' are provided above, you MUST use them to answer the user's question.\n".
            "- If the user asks for sources or links, you MUST provide the specific 'Link' URLs extracted in the Web Search Results. Do not invent links.\n".
            "- NEVER say you don't have access to real-time data. Act as if you know the real-time data based on the provided search context.\n\n".

            "If unrelated, reply EXACTLY:\n".
            "\"I can answer only trading-related questions.\"\n\n".

            "Trading Rules:\n".
            "---------------------\n".
            $TRADING_CONTEXT
    ];
}


// MODE 1: API / TELEGRAM

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['source'])
    && $_POST['source'] !== 'web') {

    header('Content-Type: application/json');

    $question = trim($_POST['question'] ?? '');

    if ($question === '') {
        echo json_encode(["error"=>"Empty question"]);
        exit;
    }

    $scrapedData = scrapeWebSearch($question);

    $messages = [
        systemPrompt($scrapedData),
        ["role"=>"user","content"=>$question]
    ];

    $reply = callAI($messages);

    echo json_encode([
        "status" => "ok",
        "reply"  => $reply
    ]);
    exit;
}


// MODE 2: WEB CHAT UI

session_start();

$STORE = __DIR__ . "/chat_store";
if (!is_dir($STORE)) mkdir($STORE,0755,true); // GIVING FILE PERMISSON 755

$CHAT_FILE = "$STORE/web_chat.json";
if (!file_exists($CHAT_FILE)) {
    file_put_contents($CHAT_FILE, json_encode([]));
}

$chat = json_decode(file_get_contents($CHAT_FILE), true);

if (isset($_POST['send_web'])) {
    $q = trim($_POST['question']);

    if ($q !== "") {
        $scrapedData = scrapeWebSearch($q);

        $chat[] = ["role"=>"user","content"=>$q];

        $messages = [ systemPrompt($scrapedData) ];
        foreach ($chat as $m) $messages[] = $m;

        $reply = callAI($messages);
        $chat[] = ["role"=>"assistant","content"=>$reply];

        if (count($chat) > 10) {
            $chat = array_slice($chat, -10);
        }

        file_put_contents($CHAT_FILE, json_encode($chat,JSON_PRETTY_PRINT));
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}
?>


<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Trading AI</title>
<style>
body{font-family:Inter,Arial;background:#f5f7fa}
.box{max-width:850px;margin:auto;background:#fff;padding:20px;height:95vh;display:flex;flex-direction:column}
.chat{flex:1;overflow:auto}
.msg{padding:12px;border-radius:12px;margin:10px 0;max-width:75%}
.user{background:#2563eb;color:#fff;margin-left:auto}
.assistant{background:#e5e7eb}
textarea{width:100%;padding:10px;border-radius:10px}
button{padding:10px 15px;border-radius:10px;border:0;background:#2563eb;color:#fff}
</style>
</head>
<body>

<div class="box">
<h3>📈 Trading AI Assistant</h3>

<div class="chat" id="chat">
<?php foreach ($chat as $m): ?>
<div class="msg <?=$m['role']?>">
<?= nl2br(htmlspecialchars($m['content'])) ?>
</div>
<?php endforeach; ?>
</div>

<form method="post">
<textarea name="question" placeholder="Ask trading-related questions only..."></textarea>
<button name="send_web">Send</button>
</form>
</div>

<script>
let c=document.getElementById("chat");
c.scrollTop=c.scrollHeight;
</script>

</body>
</html>